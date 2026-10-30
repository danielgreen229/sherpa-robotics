<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CatalogResetRepository;
use App\Support\CatalogLock;
use App\Support\Config;

final class CatalogResetService
{
    public function __construct(private readonly CatalogResetRepository $repository, private readonly CatalogLock $lock)
    {
    }

    /** @return array{deleted: int, deleted_products: int, deleted_images: int, cleanup_failed: int} */
    public function clear(int $user): array
    {
        return $this->lock->exclusive(function () use ($user): array {
            $result = $this->repository->reset($user);
            // DB commit precedes filesystem cleanup. The exclusive catalog lock remains held.
            $failed = $this->removeManagedFiles($result['upload_paths'], 'uploads', '/^[a-f0-9]{32}\.xlsx$/D');
            $failed += $this->removeManagedFiles($result['media_paths'], 'media', '/^[a-f0-9]{64}\.(?:jpg|png|gif|webp)$/D', true);
            return ['deleted' => $result['deleted'], 'deleted_products' => $result['deleted_products'], 'deleted_images' => $result['deleted_images'], 'cleanup_failed' => $failed];
        });
    }

    /** @param list<string> $paths */
    private function removeManagedFiles(array $paths, string $folder, string $pattern, bool $relative = false): int
    {
        $directory = realpath(Config::storage() . '/' . $folder);
        $failed = 0;
        foreach (array_unique($paths) as $path) {
            $file = realpath($relative ? Config::storage() . $path : $path);
            if ($directory !== false && $file !== false && dirname($file) === $directory && preg_match($pattern, basename($file))) {
                if (!@unlink($file)) {
                    ++$failed;
                    error_log('Cannot remove cleared catalog file: ' . basename($file));
                }
            }
        }
        return $failed;
    }

}
