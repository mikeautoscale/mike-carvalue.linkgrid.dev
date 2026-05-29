<?php
/**
 * Application configuration.
 *
 * Connection settings resolve in this order: real environment variable (if set,
 * even when empty) > config/.env file > built-in default. This lets the web app
 * (php-fpm) read credentials from config/.env, while CLI/test contexts can
 * override via real env vars.
 */

declare(strict_types=1);

/** Resolve a setting from the environment, then config/.env, then a default. */
if (!function_exists('carvalue_env')) {
function carvalue_env(string $key, ?string $default = null): ?string
{
    $real = getenv($key);
    if ($real !== false) {
        return $real;
    }

    static $dotenv = null;
    if ($dotenv === null) {
        $dotenv = [];
        $file = __DIR__ . '/.env';
        if (is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $dotenv[trim($k)] = trim($v, " \t\"'");
            }
        }
    }

    return $dotenv[$key] ?? $default;
}
}

return [
    'db' => [
        'host'   => carvalue_env('DB_HOST') ?: null,
        'port'   => carvalue_env('DB_PORT', '3306'),
        'socket' => carvalue_env('DB_SOCKET', '/var/lib/mysql/mysql.sock'),
        'name'   => carvalue_env('DB_NAME', 'mike-carvalue'),
        'user'   => carvalue_env('DB_USER', 'root'),
        'pass'   => carvalue_env('DB_PASS', ''),
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
        'min_make_listings'        => 1000, // makes shown in the dropdown
        'min_model_listings'       => 100,  // models shown in the dropdown
        'parser_min_make_listings' => 30,   // make recognition for the estimate parser
    ],
];
