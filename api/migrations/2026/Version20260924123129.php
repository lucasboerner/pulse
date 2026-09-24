<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924123129 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable down_interval_seconds column to monitor table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monitor ADD down_interval_seconds INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monitor DROP down_interval_seconds');
    }
}
