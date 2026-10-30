<?php

declare(strict_types=1);

/** @var App\Runtime $runtime */
$runtime = require dirname(__DIR__) . '/bootstrap.php';
App\Application::create($runtime)->run();
