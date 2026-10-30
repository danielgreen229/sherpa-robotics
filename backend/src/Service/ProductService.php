<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductRepository;

final class ProductService
{
    public function __construct(private readonly ProductRepository $products, private readonly ImageFetcher $images)
    {
    }

    /** @return array{product: array<string, mixed>, created: bool, warnings: list<array<string, string>>} */
    public function save(ProductData $data, ?int $id = null, bool $createOnly = false): array
    {
        return $this->products->withCatalogWrite(function () use ($data, $id, $createOnly): array {
            $prepared = $this->prepare($data);
            return $this->products->save($data, $prepared->images, $id, $createOnly, $prepared->failedUrls) + ['warnings' => $prepared->warnings];
        });
    }

    public function prepare(ProductData $data): PreparedImages
    {
        $images = [];
        $warnings = [];
        $failed = [];
        foreach ($data->imageUrls as $url) {
            try {
                $images[] = $this->images->fetch($url);
            } catch (\RuntimeException $e) {
                $failed[] = $url;
                $warnings[] = ['severity' => 'warning', 'field' => 'images', 'url' => $url, 'message' => $e->getMessage()];
            }
        }
        return new PreparedImages($images, $warnings, $failed);
    }
}
