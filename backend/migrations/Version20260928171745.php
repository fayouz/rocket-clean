<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928171745 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rocket Clean: sites, cleaning tasks and checklist templates (places referenced by id).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cleaning_checklist_item (id UUID NOT NULL, place_id VARCHAR(36) NOT NULL, label VARCHAR(160) NOT NULL, position INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_cleaning_checklist_item_place ON cleaning_checklist_item (place_id)');
        $this->addSql('CREATE TABLE cleaning_task (id UUID NOT NULL, place_id VARCHAR(36) NOT NULL, place_name VARCHAR(160) NOT NULL, label VARCHAR(120) NOT NULL, scheduled_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, due_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, status VARCHAR(16) NOT NULL, external_ref VARCHAR(120) DEFAULT NULL, notes TEXT DEFAULT NULL, checklist JSON NOT NULL, photos JSON NOT NULL, stock_reports JSON NOT NULL, started_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, link_salt VARCHAR(32) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, assignee_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_cleaning_task_scheduled_at ON cleaning_task (scheduled_at)');
        $this->addSql('CREATE INDEX idx_cleaning_task_place ON cleaning_task (place_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_cleaning_task_place_external_ref ON cleaning_task (place_id, external_ref)');
        $this->addSql('CREATE INDEX IDX_D2B7317059EC7D60 ON cleaning_task (assignee_id)');
        $this->addSql('CREATE TABLE site (id VARCHAR(36) NOT NULL, name VARCHAR(160) NOT NULL, source VARCHAR(8) NOT NULL, cloud_folder_id VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE cleaning_task ADD CONSTRAINT FK_D2B7317059EC7D60 FOREIGN KEY (assignee_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cleaning_task DROP CONSTRAINT FK_D2B7317059EC7D60');
        $this->addSql('DROP TABLE cleaning_checklist_item');
        $this->addSql('DROP TABLE cleaning_task');
        $this->addSql('DROP TABLE site');
    }
}
