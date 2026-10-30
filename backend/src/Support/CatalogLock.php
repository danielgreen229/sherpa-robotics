<?php

declare(strict_types=1);

namespace App\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/** Coordinate catalog writes and filesystem cleanup across API and worker processes. */
final class CatalogLock
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @template T
     *  @param callable(): T $operation
     *  @return T
     */
    public function read(callable $operation): mixed
    {
        $postgres = $this->db->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if ($postgres) {
            $this->db->executeQuery('SELECT pg_advisory_lock_shared(543212, 1)');
        }
        try {
            return $operation();
        } finally {
            if ($postgres) {
                $this->db->executeQuery('SELECT pg_advisory_unlock_shared(543212, 1)');
            }
        }
    }

    /** @param callable(): void $operation */
    public function job(string $id, callable $operation): void
    {
        $postgres = $this->db->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if ($postgres && !$this->db->fetchOne('SELECT pg_try_advisory_lock(hashtext(?))', ['import:' . $id])) {
            return;
        }
        try {
            $operation();
        } finally {
            if ($postgres) {
                $this->db->executeQuery('SELECT pg_advisory_unlock(hashtext(?))', ['import:' . $id]);
            }
        }
    }

    /** @template T
     *  @param callable(): T $operation
     *  @return T
     */
    public function exclusive(callable $operation): mixed
    {
        $postgres = $this->db->getDatabasePlatform() instanceof PostgreSQLPlatform;
        if ($postgres && !$this->db->fetchOne('SELECT pg_try_advisory_lock(543212, 1)')) {
            throw new ApiException('Каталог сейчас изменяется. Дождитесь завершения операции и повторите очистку.', 409);
        }
        try {
            return $operation();
        } finally {
            if ($postgres) {
                $this->db->executeQuery('SELECT pg_advisory_unlock(543212, 1)');
            }
        }
    }
}
