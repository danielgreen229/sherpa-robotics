<?php

declare(strict_types=1);

namespace App\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;

final class Database
{
    private Connection $connection;
    private ?\Doctrine\ORM\Configuration $ormConfig = null;

    public function __construct(?string $url = null)
    {
        $parser = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite']);
        $params = $parser->parse($url ?? Config::env('DATABASE_URL', 'postgresql://catalog:catalog@db:5432/catalog'));
        if (($params['driver'] ?? '') === 'pdo_pgsql') {
            $params['serverVersion'] = '16';
            $params['connect_timeout'] = 3;
        }
        $this->connection = DriverManager::getConnection($params);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function entityManager(): EntityManager
    {
        $this->ormConfig ??= ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__) . '/Entity'], true);
        // Share metadata, not a unit of work: each import row gets a fresh identity map.
        return new EntityManager($this->connection, $this->ormConfig);
    }
}
