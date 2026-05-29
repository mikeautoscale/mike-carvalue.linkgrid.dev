<?php

declare(strict_types=1);

/** GET /api/models.php?make=Toyota[&year=2015] */
$app = require dirname(__DIR__, 2) . '/src/bootstrap.php';

$app['lookupController']
    ->models(CarValue\Request::fromGlobals())
    ->send();
