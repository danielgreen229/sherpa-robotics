<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Catalog, import receipts, users and persistent rate limits';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE products (id SERIAL PRIMARY KEY, external_code VARCHAR(255) NOT NULL UNIQUE, name VARCHAR(500) NOT NULL, description TEXT NOT NULL, price NUMERIC(14,2) NOT NULL CHECK (price >= 0), purchase_price NUMERIC(14,2) NOT NULL CHECK (purchase_price >= 0), discount NUMERIC(14,2) NOT NULL)');
        $this->addSql('CREATE INDEX products_price_idx ON products(price)');
        $this->addSql('CREATE INDEX products_name_idx ON products(lower(name))');
        $this->addSql('CREATE TABLE product_attributes (id SERIAL PRIMARY KEY, product_id INT NOT NULL REFERENCES products(id) ON DELETE CASCADE, "key" VARCHAR(255) NOT NULL, value TEXT NOT NULL)');
        $this->addSql('CREATE INDEX product_attributes_product_idx ON product_attributes(product_id)');
        $this->addSql('CREATE TABLE product_images (id SERIAL PRIMARY KEY, product_id INT NOT NULL REFERENCES products(id) ON DELETE CASCADE, url TEXT NOT NULL, path VARCHAR(255) NOT NULL)');
        $this->addSql('CREATE INDEX product_images_product_idx ON product_images(product_id)');
        $this->addSql('CREATE TABLE users (id SERIAL PRIMARY KEY, email VARCHAR(255) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL)');
        $this->addSql("CREATE TABLE import_jobs (id VARCHAR(32) PRIMARY KEY, user_id INT NOT NULL REFERENCES users(id), filename VARCHAR(255) NOT NULL, file_path TEXT NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'queued', total INT NOT NULL DEFAULT 0, failure TEXT, created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(), updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())");
        $this->addSql('CREATE TABLE import_rows (job_id VARCHAR(32) NOT NULL REFERENCES import_jobs(id) ON DELETE CASCADE, row_number INT NOT NULL, outcome VARCHAR(16) NOT NULL, errors JSONB NOT NULL DEFAULT \'[]\'::jsonb, PRIMARY KEY(job_id, row_number))');
        $this->addSql('CREATE TABLE rate_limits (bucket VARCHAR(255) PRIMARY KEY, hits INT NOT NULL, expires_at BIGINT NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        foreach (['rate_limits', 'import_rows', 'import_jobs', 'users', 'product_images', 'product_attributes', 'products'] as $table) {
            $this->addSql('DROP TABLE ' . $table);
        }
    }
}
