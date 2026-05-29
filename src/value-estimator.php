<?php

declare(strict_types=1);

namespace CarValue;

/**
 * Computes a market-value estimate from a set of comparable listings.
 *
 * Pipeline (see design-doc §6):
 *   1. drop bogus-low prices (< price_floor)
 *   2. remove price outliers via the IQR rule
 *   3. if mileage is given and there are enough mileage points with sufficient
 *      spread, fit price ~ mileage (OLS) and evaluate at the requested mileage,
 *      clamping a negative slope's prediction to the observed price range;
 *      otherwise fall back to the (outlier-resistant) median
 *   4. round to the nearest $100
 *   5. return up to `sample_limit` listings, nearest the requested mileage first
 */
final class ValueEstimator
{
    private int $priceFloor;
    private int $minFit;
    private int $minSpread;
    private int $sampleLimit;
    private int $roundTo;

    public function __construct(array $cfg = [])
    {
        $this->priceFloor  = (int) ($cfg['price_floor'] ?? 500);
        $this->minFit      = (int) ($cfg['min_fit'] ?? 8);
        $this->minSpread   = (int) ($cfg['min_mileage_spread'] ?? 5000);
        $this->sampleLimit = (int) ($cfg['sample_limit'] ?? 100);
        $this->roundTo     = (int) ($cfg['round_to'] ?? 100);
    }

    /**
     * @param array<int,array<string,mixed>> $comps comparable listing rows
     * @return array{estimate:?int,sampleCount:int,method:string,listings:array}
     */
    public function estimate(array $comps, ?int $mileage): array
    {
        $priced = array_values(array_filter(
            $comps,
            fn($r) => $r['price'] !== null && (int) $r['price'] >= $this->priceFloor
        ));

        if (!$priced) {
            return ['estimate' => null, 'sampleCount' => 0, 'method' => 'none', 'listings' => []];
        }

        $clean = $this->removeOutliers($priced);
        if (!$clean) {
            $clean = $priced;
        }

        [$value, $method] = $this->computeValue($clean, $mileage);
        $estimate = (int) (round($value / $this->roundTo) * $this->roundTo);

        return [
            'estimate'    => $estimate,
            'sampleCount' => count($clean),
            'method'      => $method,
            'listings'    => $this->sampleListings($clean, $mileage),
        ];
    }

    /** Remove price outliers using Tukey's 1.5×IQR fences. */
    private function removeOutliers(array $rows): array
    {
        $prices = array_map(fn($r) => (int) $r['price'], $rows);
        sort($prices);
        if (count($prices) < 4) {
            return $rows; // too few to define quartiles meaningfully
        }

        $q1 = $this->percentile($prices, 0.25);
        $q3 = $this->percentile($prices, 0.75);
        $iqr = $q3 - $q1;
        $lo = $q1 - 1.5 * $iqr;
        $hi = $q3 + 1.5 * $iqr;

        return array_values(array_filter(
            $rows,
            fn($r) => (int) $r['price'] >= $lo && (int) $r['price'] <= $hi
        ));
    }

    /** @return array{0:float,1:string} [value, method] */
    private function computeValue(array $clean, ?int $mileage): array
    {
        if ($mileage !== null) {
            $points = [];
            foreach ($clean as $r) {
                if ($r['mileage'] !== null && (int) $r['mileage'] > 0) {
                    $points[] = [(float) $r['mileage'], (float) $r['price']];
                }
            }

            if (count($points) >= $this->minFit) {
                $miles = array_column($points, 0);
                if ((max($miles) - min($miles)) >= $this->minSpread) {
                    [$intercept, $slope] = $this->ols($points);
                    if ($slope < 0) { // expected: value falls as mileage rises
                        $value = $intercept + $slope * $mileage;
                        $prices = array_map(fn($r) => (int) $r['price'], $clean);
                        $value = max(min($prices), min(max($prices), $value));
                        return [$value, 'regression'];
                    }
                }
            }
        }

        return [$this->median(array_map(fn($r) => (int) $r['price'], $clean)), 'median'];
    }

    /** Ordinary least squares. @return array{0:float,1:float} [intercept, slope] */
    private function ols(array $points): array
    {
        $n = count($points);
        $sx = $sy = $sxy = $sxx = 0.0;
        foreach ($points as [$x, $y]) {
            $sx += $x;
            $sy += $y;
            $sxy += $x * $y;
            $sxx += $x * $x;
        }
        $denom = $n * $sxx - $sx * $sx;
        if ($denom == 0.0) {
            return [$sy / $n, 0.0];
        }
        $slope = ($n * $sxy - $sx * $sy) / $denom;
        $intercept = ($sy - $slope * $sx) / $n;
        return [$intercept, $slope];
    }

    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return ($n % 2) ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2.0;
    }

    /** Linear-interpolated percentile of a pre-sorted array. */
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 1) {
            return (float) $sorted[0];
        }
        $rank = $p * ($n - 1);
        $lo = (int) floor($rank);
        $hi = (int) ceil($rank);
        if ($lo === $hi) {
            return (float) $sorted[$lo];
        }
        $frac = $rank - $lo;
        return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * $frac;
    }

    /** Up to sample_limit listings, nearest the requested mileage first. */
    private function sampleListings(array $clean, ?int $mileage): array
    {
        $rows = $clean;
        if ($mileage !== null) {
            usort($rows, function ($a, $b) use ($mileage) {
                $da = $a['mileage'] === null ? PHP_INT_MAX : abs((int) $a['mileage'] - $mileage);
                $db = $b['mileage'] === null ? PHP_INT_MAX : abs((int) $b['mileage'] - $mileage);
                return $da <=> $db;
            });
        } else {
            usort($rows, fn($a, $b) =>
                ((string) ($b['last_seen_date'] ?? '')) <=> ((string) ($a['last_seen_date'] ?? ''))
                ?: ((int) $b['price'] <=> (int) $a['price']));
        }

        $out = [];
        foreach (array_slice($rows, 0, $this->sampleLimit) as $r) {
            $vehicle = trim(implode(' ', array_filter([
                $r['year'], $r['make'], $r['model'], $r['trim'],
            ], fn($v) => $v !== null && $v !== '')));

            $location = trim((string) ($r['city'] ?? ''));
            if (!empty($r['state'])) {
                $location = $location === '' ? $r['state'] : $location . ', ' . $r['state'];
            }

            $out[] = [
                'vehicle'  => $vehicle,
                'price'    => (int) $r['price'],
                'mileage'  => $r['mileage'] === null ? null : (int) $r['mileage'],
                'location' => $location,
            ];
        }
        return $out;
    }
}
