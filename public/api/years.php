<?php

declare(strict_types=1);

/** GET /api/years.php */
$app = require dirname(__DIR__, 2) . '/src/bootstrap.php';

$app['lookupController']
    ->years(CarValue\Request::fromGlobals())
    ->send();
