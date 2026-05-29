<?php

declare(strict_types=1);

/** GET /api/makes.php */
$app = require dirname(__DIR__, 2) . '/src/bootstrap.php';

$app['lookupController']
    ->makes(CarValue\Request::fromGlobals())
    ->send();
