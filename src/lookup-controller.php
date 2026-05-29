<?php

declare(strict_types=1);

namespace CarValue;

/** Handles the make/model autocomplete endpoints. */
final class LookupController
{
    public function __construct(
        private ListingRepository $repo,
        private int $minMakeListings = 1,
        private int $minModelListings = 1,
    ) {
    }

    /** GET /api/years.php */
    public function years(Request $request): Response
    {
        return Response::json(['years' => $this->repo->years()]);
    }

    /** GET /api/makes.php */
    public function makes(Request $request): Response
    {
        return Response::json(['makes' => $this->repo->makes($this->minMakeListings)]);
    }

    /** GET /api/models.php?make=Toyota[&year=2015] */
    public function models(Request $request): Response
    {
        $make = trim((string) $request->query('make', ''));
        if ($make === '') {
            return Response::json(['error' => 'Missing required parameter: make'], 400);
        }

        $yearRaw = $request->query('year');
        $year = null;
        if ($yearRaw !== null && trim((string) $yearRaw) !== '') {
            if (!ctype_digit((string) $yearRaw)) {
                return Response::json(['error' => 'Invalid year'], 400);
            }
            $year = (int) $yearRaw;
        }

        return Response::json([
            'models' => $this->repo->models(mb_strtoupper($make), $year, $this->minModelListings),
        ]);
    }
}
