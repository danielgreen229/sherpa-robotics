<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Indexes for substring search and cached image URL lookup';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE INDEX products_name_trgm_idx ON products USING GIN (lower(name) gin_trgm_ops)');
        $this->addSql('DROP INDEX products_name_idx');
        // Avoid the B-tree key size limit for long URLs.
        $this->addSql('CREATE INDEX product_images_url_idx ON product_images USING HASH (url)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX product_images_url_idx');
        $this->addSql('DROP INDEX products_name_trgm_idx');
        $this->addSql('CREATE INDEX products_name_idx ON products(lower(name))');
        // Other applications may depend on the extension, so keep it installed.
    }
}
