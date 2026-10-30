<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class RateLimitRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function consume(string $key, int $limit, int $window): bool
    {
        $now = time();
        $bucket = hash('sha256', $key . ':' . (int) floor($now / $window));
        $this->db->executeStatement('DELETE FROM rate_limits WHERE expires_at < ?', [$now]);
        $hits = $this->db->fetchOne('INSERT INTO rate_limits (bucket, hits, expires_at) VALUES (?, 1, ?) ON CONFLICT (bucket) DO UPDATE SET hits = rate_limits.hits + 1 RETURNING hits', [$bucket, $now + $window]);
        return (int) $hits <= $limit;
    }
}
