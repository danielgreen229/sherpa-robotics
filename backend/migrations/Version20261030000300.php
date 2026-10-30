<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persistent import attempts and retry backoff';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_jobs ADD attempts INT NOT NULL DEFAULT 0 CHECK (attempts >= 0)');
        $this->addSql('ALTER TABLE import_jobs ADD next_attempt_at BIGINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE import_jobs ADD last_published_at BIGINT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_jobs DROP COLUMN last_published_at, DROP COLUMN next_attempt_at, DROP COLUMN attempts');
    }
}
