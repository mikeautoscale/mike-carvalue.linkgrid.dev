<?php

declare(strict_types=1);

namespace CarValue;

/** Handles GET /api/estimate.php */
final class EstimateController
{
    public function __construct(
        private ListingRepository $repo,
        private ValueEstimator $estimator,
        private int $minMakeListings = 1,
    ) {
    }

    public function handle(Request $request): Response
    {
        $ymm = trim((string) $request->query('ymm', ''));
        if ($ymm === '') {
            return Response::json(['error' => 'Missing required parameter: ymm'], 400);
        }

        $mileage = $this->parseMileage($request->query('mileage'));
        if ($mileage === false) {
            return Response::json(['error' => 'Invalid mileage; expected a non-negative number'], 400);
        }

        $parsed = (new YmmParser($this->repo->makeKeys($this->minMakeListings)))->parse($ymm);
        if ($parsed === null) {
            return Response::json([
                'error' => 'Could not parse a year + make + model from: ' . $ymm,
            ], 400);
        }

        $comps = $this->repo->comparables($parsed['year'], $parsed['makeKey'], $parsed['modelKey']);
        $result = $this->estimator->estimate($comps, $mileage);

        return Response::json([
            'query' => [
                'year'    => $parsed['year'],
                'make'    => $parsed['make'],
                'model'   => $parsed['model'],
                'mileage' => $mileage,
            ],
            'estimate'    => $result['estimate'],
            'sampleCount' => $result['sampleCount'],
            'method'      => $result['method'],
            'listings'    => $result['listings'],
        ], 200);
    }

    /**
     * @return int|null|false int mileage, null when absent, false when invalid.
     */
    private function parseMileage(mixed $raw): int|null|false
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        // Accept "150000", "150,000", "150,000 miles".
        $digits = preg_replace('/[^0-9]/', '', (string) $raw);
        if ($digits === '') {
            return false;
        }
        return (int) $digits;
    }
}
