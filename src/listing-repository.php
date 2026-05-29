<?php

declare(strict_types=1);

namespace CarValue;

use PDO;

/** Data access for listings and the make/model lookups. */
final class ListingRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Comparable priced listings for a year + make + model.
     * Uses the idx_ymm (make_key, model_key, year) index.
     *
     * @return array<int,array<string,mixed>>
     */
    public function comparables(int $year, string $makeKey, string $modelKey): array
    {
        $sql = 'SELECT l.listing_price AS price, l.listing_mileage AS mileage, '
             . 'l.`year` AS year, l.make AS make, l.model AS model, l.`trim` AS trim, '
             . 'l.certified AS certified, l.used AS used, l.last_seen_date AS last_seen_date, '
             . 'd.city AS city, d.state AS state '
             . 'FROM listings l LEFT JOIN dealers d ON d.id = l.dealer_id '
             . 'WHERE l.make_key = ? AND l.model_key = ? AND l.`year` = ? '
             . 'AND l.listing_price IS NOT NULL';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$makeKey, $modelKey, $year]);
        return $stmt->fetchAll();
    }

    /**
     * Distinct make keys (uppercase) for makes with at least $minListings rows —
     * used to seed the YMM parser. Served from the materialized vehicle_summary
     * (built by the importer), which is already alpha-filtered and keeps this
     * fast over the 4.7M-row data set.
     *
     * @return string[]
     */
    public function makeKeys(int $minListings = 1): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT make_key FROM vehicle_summary '
            . 'GROUP BY make_key HAVING SUM(listings) >= ?'
        );
        $stmt->execute([$minListings]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Distinct makes for display/autocomplete, alphabetical.
     *
     * @return string[]
     */
    public function makes(int $minListings = 1): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT MIN(make) AS make FROM vehicle_summary '
            . 'GROUP BY make_key HAVING SUM(listings) >= ? ORDER BY make'
        );
        $stmt->execute([$minListings]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Distinct models for a make (optionally a year), alphabetical.
     *
     * @return string[]
     */
    public function models(string $makeKey, ?int $year = null, int $minListings = 1): array
    {
        $sql = 'SELECT MIN(model) AS model FROM vehicle_summary WHERE make_key = ?';
        $params = [$makeKey];
        if ($year !== null) {
            $sql .= ' AND `year` = ?';
            $params[] = $year;
        }
        $sql .= ' GROUP BY model_key HAVING SUM(listings) >= ? ORDER BY model';
        $params[] = $minListings;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
