<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918190837 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the monitoring schema: users, monitor groups, monitors with subscribers, check results, hourly rollups and incidents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE check_result (status VARCHAR(16) NOT NULL, latency_ms INT DEFAULT NULL, http_status_code SMALLINT DEFAULT NULL, error_message VARCHAR(500) DEFAULT NULL, checked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, region VARCHAR(32) DEFAULT \'local\' NOT NULL, id UUID NOT NULL, monitor_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_669A38BC4CE1C902 ON check_result (monitor_id)');
        $this->addSql('CREATE INDEX idx_check_result_monitor_time ON check_result (monitor_id, checked_at)');
        $this->addSql('CREATE INDEX idx_check_result_checked_at ON check_result (checked_at)');
        $this->addSql('CREATE TABLE check_rollup (bucket_start TIMESTAMP(0) WITH TIME ZONE NOT NULL, region VARCHAR(32) DEFAULT \'local\' NOT NULL, up_count INT DEFAULT 0 NOT NULL, degraded_count INT DEFAULT 0 NOT NULL, down_count INT DEFAULT 0 NOT NULL, latency_min_ms INT DEFAULT NULL, latency_avg_ms INT DEFAULT NULL, latency_max_ms INT DEFAULT NULL, latency_p95_ms INT DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, monitor_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_ABCA922A4CE1C902 ON check_rollup (monitor_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_check_rollup_bucket ON check_rollup (monitor_id, bucket_start, region)');
        $this->addSql('CREATE TABLE incident (started_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, severity VARCHAR(16) NOT NULL, cause VARCHAR(500) DEFAULT NULL, notified_opened_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, notified_resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, monitor_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3D03A11A4CE1C902 ON incident (monitor_id)');
        $this->addSql('CREATE INDEX idx_incident_monitor_open ON incident (monitor_id, ended_at)');
        $this->addSql('CREATE INDEX idx_incident_started ON incident (started_at)');
        $this->addSql('CREATE TABLE monitor (name VARCHAR(120) NOT NULL, url VARCHAR(2048) NOT NULL, type VARCHAR(32) DEFAULT \'http\' NOT NULL, interval_seconds INT DEFAULT 60 NOT NULL, timeout_ms INT DEFAULT 8000 NOT NULL, expected_status_code SMALLINT DEFAULT NULL, enabled BOOLEAN DEFAULT true NOT NULL, region VARCHAR(32) DEFAULT \'local\' NOT NULL, next_check_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_checked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, last_status VARCHAR(16) DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, monitor_group_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_monitor_due ON monitor (enabled, next_check_at)');
        $this->addSql('CREATE INDEX idx_monitor_group ON monitor (monitor_group_id)');
        $this->addSql('CREATE TABLE monitor_subscriber (monitor_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (monitor_id, user_id))');
        $this->addSql('CREATE INDEX IDX_5C6EB32E4CE1C902 ON monitor_subscriber (monitor_id)');
        $this->addSql('CREATE INDEX IDX_5C6EB32EA76ED395 ON monitor_subscriber (user_id)');
        $this->addSql('CREATE TABLE monitor_group (name VARCHAR(80) NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_monitor_group_name ON monitor_group (name)');
        $this->addSql('CREATE TABLE "user" (username VARCHAR(64) NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649F85E0677 ON "user" (username)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_email ON "user" (email)');
        $this->addSql('ALTER TABLE check_result ADD CONSTRAINT FK_669A38BC4CE1C902 FOREIGN KEY (monitor_id) REFERENCES monitor (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE check_rollup ADD CONSTRAINT FK_ABCA922A4CE1C902 FOREIGN KEY (monitor_id) REFERENCES monitor (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE incident ADD CONSTRAINT FK_3D03A11A4CE1C902 FOREIGN KEY (monitor_id) REFERENCES monitor (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE monitor ADD CONSTRAINT FK_E115998524670DB3 FOREIGN KEY (monitor_group_id) REFERENCES monitor_group (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE monitor_subscriber ADD CONSTRAINT FK_5C6EB32E4CE1C902 FOREIGN KEY (monitor_id) REFERENCES monitor (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE monitor_subscriber ADD CONSTRAINT FK_5C6EB32EA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE check_result DROP CONSTRAINT FK_669A38BC4CE1C902');
        $this->addSql('ALTER TABLE check_rollup DROP CONSTRAINT FK_ABCA922A4CE1C902');
        $this->addSql('ALTER TABLE incident DROP CONSTRAINT FK_3D03A11A4CE1C902');
        $this->addSql('ALTER TABLE monitor DROP CONSTRAINT FK_E115998524670DB3');
        $this->addSql('ALTER TABLE monitor_subscriber DROP CONSTRAINT FK_5C6EB32E4CE1C902');
        $this->addSql('ALTER TABLE monitor_subscriber DROP CONSTRAINT FK_5C6EB32EA76ED395');
        $this->addSql('DROP TABLE check_result');
        $this->addSql('DROP TABLE check_rollup');
        $this->addSql('DROP TABLE incident');
        $this->addSql('DROP TABLE monitor');
        $this->addSql('DROP TABLE monitor_subscriber');
        $this->addSql('DROP TABLE monitor_group');
        $this->addSql('DROP TABLE "user"');
    }
}
