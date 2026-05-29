<?php
/**
 * Application bootstrap: wires config -> PDO -> repository -> services.
 * Returns the shared service container used by the API entry points.
 */

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

$config = require dirname(__DIR__) . '/config/config.php';
$pdo = CarValue\Database::connect($config['db']);

$repository = new CarValue\ListingRepository($pdo);
$lookup = $config['lookup'] ?? [];
$minMake = (int) ($lookup['min_make_listings'] ?? 1);
$minModel = (int) ($lookup['min_model_listings'] ?? 1);
// The parser stays permissive so a directly-typed make below the dropdown
// threshold can still be estimated.
$parserMinMake = (int) ($lookup['parser_min_make_listings'] ?? $minMake);

return [
    'config'     => $config,
    'pdo'        => $pdo,
    'repository' => $repository,
    'estimator'  => new CarValue\ValueEstimator($config['estimator'] ?? []),
    'estimateController' => new CarValue\EstimateController(
        $repository,
        new CarValue\ValueEstimator($config['estimator'] ?? []),
        $parserMinMake
    ),
    'lookupController' => new CarValue\LookupController($repository, $minMake, $minModel),
];
