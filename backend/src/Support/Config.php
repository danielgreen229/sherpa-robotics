<?php

declare(strict_types=1);

namespace App\Support;

final class Config
{
    public static function env(string $key, string $default = ''): string
    {
        return (string) ($_ENV[$key] ?? $_SERVER[$key] ?? (getenv($key) ?: $default));
    }

    public static function storage(): string
    {
        return self::env('STORAGE_PATH', dirname(__DIR__, 2) . '/var');
    }
}
