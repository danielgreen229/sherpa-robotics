<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Indexes for first image lookup, import history, recovery and rate limit cleanup';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX product_images_first_idx ON product_images(product_id, id)');
        $this->addSql('CREATE INDEX import_jobs_user_created_idx ON import_jobs(user_id, created_at DESC)');
        $this->addSql("CREATE INDEX import_jobs_pending_idx ON import_jobs(created_at) WHERE status IN ('queued', 'processing')");
        $this->addSql('CREATE INDEX rate_limits_expiry_idx ON rate_limits(expires_at)');
    }

    public function down(Schema $schema): void
    {
        foreach (['rate_limits_expiry_idx', 'import_jobs_pending_idx', 'import_jobs_user_created_idx', 'product_images_first_idx'] as $index) {
            $this->addSql('DROP INDEX ' . $index);
        }
    }
}
