<?php

declare(strict_types=1);

namespace App\Service;

interface ImageFetcher
{
    /** @return array{url: string, path: string} */
    public function fetch(string $url): array;
}
