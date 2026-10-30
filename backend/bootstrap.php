<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

if (is_file(dirname(__DIR__) . '/.env')) {
    (new Symfony\Component\Dotenv\Dotenv())->load(dirname(__DIR__) . '/.env');
}

date_default_timezone_set('UTC');

return new App\Runtime();
