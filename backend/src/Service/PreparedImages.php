<?php

declare(strict_types=1);

namespace App\Service;

final readonly class PreparedImages
{
    /** @param list<array{url: string, path: string}> $images
     *  @param list<array<string, string>> $warnings
     *  @param list<string> $failedUrls
     */
    public function __construct(public array $images, public array $warnings, public array $failedUrls)
    {
    }
}
