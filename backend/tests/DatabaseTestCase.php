<?php

declare(strict_types=1);

namespace Tests;

use App\Entity\Product;
use App\Entity\ProductAttribute;
use App\Entity\ProductImage;
use App\Repository\ImportRepository;
use App\Repository\ProductRepository;
use App\Support\Database;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected Database $database;
    protected ProductRepository $products;
    protected ImportRepository $jobs;
    protected string $temp;

    protected function setUp(): void
    {
        $this->temp = sys_get_temp_dir() . '/catalog-test-' . bin2hex(random_bytes(6));
        mkdir($this->temp . '/media', 0775, true);
        $_ENV['STORAGE_PATH'] = $this->temp;
        $_ENV['JWT_SECRET'] = str_repeat('test-secret-', 5);
        $this->database = new Database('sqlite:///:memory:');
        $db = $this->database->connection();
        $db->getNativeConnection()->sqliteCreateFunction('lower', static fn (string $value): string => mb_strtolower($value));
        $db->executeStatement('PRAGMA foreign_keys=ON');
        $em = $this->database->entityManager();
        $tool = new SchemaTool($em);
        $tool->createSchema(array_map(fn (string $c) => $em->getClassMetadata($c), [Product::class, ProductAttribute::class, ProductImage::class]));
        $db->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email VARCHAR(255) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL)');
        $db->executeStatement("CREATE TABLE import_jobs (id VARCHAR(32) PRIMARY KEY, user_id INT NOT NULL REFERENCES users(id), filename VARCHAR(255), file_path TEXT, status VARCHAR(32) DEFAULT 'queued', total INT DEFAULT 0, attempts INT NOT NULL DEFAULT 0, next_attempt_at BIGINT NOT NULL DEFAULT 0, last_published_at BIGINT NOT NULL DEFAULT 0, failure TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $db->executeStatement("CREATE TABLE import_rows (job_id VARCHAR(32) NOT NULL REFERENCES import_jobs(id), row_number INT NOT NULL, outcome VARCHAR(16), errors TEXT DEFAULT '[]', PRIMARY KEY(job_id, row_number))");
        $db->executeStatement('CREATE TABLE rate_limits (bucket VARCHAR(255) PRIMARY KEY, hits INT NOT NULL, expires_at BIGINT NOT NULL)');
        $db->insert('users', ['email' => 'test@example.com', 'password_hash' => password_hash('CatalogTest2026!', PASSWORD_DEFAULT)]);
        $this->products = new ProductRepository($this->database);
        $this->jobs = new ImportRepository($db);
    }

    protected function tearDown(): void
    {
        $this->database->connection()->close();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->temp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->temp);
        unset($_ENV['STORAGE_PATH'], $_ENV['JWT_SECRET']);
    }

    protected function example(): string
    {
        foreach ([dirname(__DIR__, 2) . '/examples/products.xlsx', '/examples/products.xlsx'] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        throw new \RuntimeException('Example file is missing');
    }
}
