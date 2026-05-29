<?php

declare(strict_types=1);

use CarValue\ValueEstimator;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic tests of the estimation engine with crafted comparable sets.
 * Covers design-doc test matrix items T2 (mileage), T5 (price exclusion),
 * T6 (cap), T8 (outliers), T9 (rounding).
 */
final class ValueEstimatorTest extends TestCase
{
    private ValueEstimator $estimator;

    protected function setUp(): void
    {
        $this->estimator = new ValueEstimator(carvalue_config()['estimator']);
    }

    /** @return array<string,mixed> a single comparable row */
    private function comp(?int $price, ?int $mileage, array $over = []): array
    {
        return array_merge([
            'price'          => $price,
            'mileage'        => $mileage,
            'year'           => 2015,
            'make'           => 'Toyota',
            'model'          => 'Camry',
            'trim'           => 'LE',
            'certified'      => 0,
            'used'           => 1,
            'last_seen_date' => '2022-08-17',
            'city'           => 'Seattle',
            'state'          => 'WA',
        ], $over);
    }

    public function testEmptySetReturnsNull(): void
    {
        $r = $this->estimator->estimate([], 100000);
        $this->assertNull($r['estimate']);
        $this->assertSame(0, $r['sampleCount']);
        $this->assertSame([], $r['listings']);
    }

    public function testMedianWhenNoMileage(): void
    {
        $comps = [
            $this->comp(10000, null),
            $this->comp(20000, null),
            $this->comp(30000, null),
        ];
        $r = $this->estimator->estimate($comps, null);
        $this->assertSame('median', $r['method']);
        $this->assertSame(20000, $r['estimate']);
        $this->assertSame(3, $r['sampleCount']);
    }

    /** T9: estimate is always a multiple of 100. */
    public function testRoundsToNearestHundred(): void
    {
        $comps = [
            $this->comp(10133, null),
            $this->comp(20077, null),
            $this->comp(30049, null),
        ];
        $r = $this->estimator->estimate($comps, null);
        $this->assertSame(0, $r['estimate'] % 100);
    }

    /** T2: higher mileage yields a lower (or equal) estimate. */
    public function testRegressionDecreasesWithMileage(): void
    {
        $comps = [];
        for ($i = 0; $i < 12; $i++) {
            $mileage = 10000 + $i * 10000;          // 10k .. 120k
            $price = (int) (30000 - 0.10 * $mileage); // clear negative slope
            $comps[] = $this->comp($price, $mileage);
        }

        $low  = $this->estimator->estimate($comps, 20000);
        $high = $this->estimator->estimate($comps, 110000);

        $this->assertSame('regression', $low['method']);
        $this->assertSame('regression', $high['method']);
        $this->assertLessThan($low['estimate'], $high['estimate']);
    }

    /** T8: an extreme price outlier is excluded via the IQR rule. */
    public function testOutlierExcluded(): void
    {
        $comps = [];
        for ($i = 0; $i < 12; $i++) {
            $comps[] = $this->comp(10000, 50000 + $i * 1000);
        }
        $comps[] = $this->comp(500000, 60000); // outlier

        $r = $this->estimator->estimate($comps, null);
        $this->assertSame(12, $r['sampleCount'], 'outlier should be dropped from the comp set');
        $this->assertSame(10000, $r['estimate']);
    }

    /** T5-style: prices below the floor are not counted or returned. */
    public function testPriceFloorExcludesJunk(): void
    {
        $comps = [
            $this->comp(200, null),   // below floor
            $this->comp(15000, null),
            $this->comp(15000, null),
        ];
        $r = $this->estimator->estimate($comps, null);
        $this->assertSame(2, $r['sampleCount']);
        foreach ($r['listings'] as $l) {
            $this->assertGreaterThanOrEqual(500, $l['price']);
        }
    }

    /** T6: at most sample_limit (100) listings are returned. */
    public function testSampleListingsCappedAt100(): void
    {
        $comps = [];
        for ($i = 0; $i < 150; $i++) {
            $comps[] = $this->comp(10000 + $i * 10, null); // tight range -> no outliers
        }
        $r = $this->estimator->estimate($comps, null);
        $this->assertSame(150, $r['sampleCount']);
        $this->assertCount(100, $r['listings']);
    }

    public function testListingShapeAndLocation(): void
    {
        $r = $this->estimator->estimate([$this->comp(15000, 80000)], 80000);
        $listing = $r['listings'][0];
        $this->assertSame(['vehicle', 'price', 'mileage', 'location'], array_keys($listing));
        $this->assertSame('2015 Toyota Camry LE', $listing['vehicle']);
        $this->assertSame('Seattle, WA', $listing['location']);
        $this->assertSame(15000, $listing['price']);
        $this->assertSame(80000, $listing['mileage']);
    }

    public function testListingsOrderedByMileageProximity(): void
    {
        $comps = [
            $this->comp(12000, 200000),
            $this->comp(18000, 50000),
            $this->comp(15000, 100000),
        ];
        $r = $this->estimator->estimate($comps, 95000);
        // Nearest to 95k mileage (100k) should come first.
        $this->assertSame(100000, $r['listings'][0]['mileage']);
    }
}
