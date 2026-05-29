<?php
/**
 * Application configuration.
 *
 * Connection settings come from the environment (matching bin/import-data.php)
 * so the same code runs locally and in production. Local defaults target the
 * MariaDB unix socket as root.
 */

declare(strict_types=1);

return [
    'db' => [
        'host'   => getenv('DB_HOST') ?: null,
        'port'   => getenv('DB_PORT') ?: '3306',
        'socket' => getenv('DB_SOCKET') ?: '/var/lib/mysql/mysql.sock',
        'name'   => getenv('DB_NAME') ?: 'mike-carvalue',
        'user'   => getenv('DB_USER') ?: 'root',
        'pass'   => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
    ],

    'estimator' => [
        'price_floor'        => 500,   // ignore obviously-bogus low prices
        'min_fit'            => 8,     // min mileage points to attempt regression
        'min_mileage_spread' => 5000,  // min (max-min) mileage spread to regress
        'sample_limit'       => 100,   // max listings returned
        'round_to'           => 100,   // round estimate to nearest $100
    ],

    // Minimum listing counts for lookups/parsing — suppresses the long tail of
    // dirty dealer-entered make/model strings in the raw data.
    'lookup' => [
        'min_make_listings'  => 30,
        'min_model_listings' => 20,
    ],
];
