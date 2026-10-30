<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\ApiException;
use Doctrine\DBAL\Connection;

/** One transaction for a shared catalog reset and the initiating user's history. */
final class CatalogResetRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{deleted: int, deleted_products: int, deleted_images: int, upload_paths: list<string>, media_paths: list<string>} */
    public function reset(int $user): array
    {
        return $this->db->transactional(function () use ($user): array {
            // The catalog is shared: another owner's active import must also block a reset.
            if ($this->db->fetchOne("SELECT 1 FROM import_jobs WHERE status IN ('queued', 'processing') LIMIT 1") !== false) {
                throw new ApiException('Дождитесь завершения всех активных импортов перед очисткой каталога.', 409);
            }
            $uploads = $this->db->fetchFirstColumn('SELECT file_path FROM import_jobs WHERE user_id = ?', [$user]);
            $media = $this->db->fetchFirstColumn('SELECT DISTINCT path FROM product_images');
            $products = (int) $this->db->fetchOne('SELECT COUNT(*) FROM products');
            $images = (int) $this->db->fetchOne('SELECT COUNT(*) FROM product_images');
            $this->db->executeStatement('DELETE FROM import_rows WHERE job_id IN (SELECT id FROM import_jobs WHERE user_id = ?)', [$user]);
            $this->db->delete('import_jobs', ['user_id' => $user]);
            $this->db->executeStatement('DELETE FROM product_attributes');
            $this->db->executeStatement('DELETE FROM product_images');
            $this->db->executeStatement('DELETE FROM products');
            return ['deleted' => count($uploads), 'deleted_products' => $products, 'deleted_images' => $images, 'upload_paths' => $uploads, 'media_paths' => $media];
        });
    }

}
