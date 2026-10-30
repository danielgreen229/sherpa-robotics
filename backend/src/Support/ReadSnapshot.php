<?php

declare(strict_types=1);

namespace App\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

final class ReadSnapshot
{
    /** @template T
     *  @param callable(): T $operation
     *  @return T
     */
    public static function run(Connection $db, callable $operation): mixed
    {
        if ($db->isTransactionActive()) {
            return $operation();
        }
        return $db->transactional(static function () use ($db, $operation): mixed {
            if ($db->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            return $operation();
        });
    }
}
