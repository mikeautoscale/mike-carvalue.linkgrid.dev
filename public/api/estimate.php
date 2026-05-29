<?php

declare(strict_types=1);

/** GET /api/estimate.php?ymm=2015+Toyota+Camry[&mileage=150000] */
$app = require dirname(__DIR__, 2) . '/src/bootstrap.php';

$app['estimateController']
    ->handle(CarValue\Request::fromGlobals())
    ->send();
