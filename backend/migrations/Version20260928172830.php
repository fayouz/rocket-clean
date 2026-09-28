<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928172830 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Types, origin, cost and conflict of cleanings; recurrences, occupied periods, default costs, checklist per type.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cleaning_cost (id UUID NOT NULL, place_id VARCHAR(36) NOT NULL, type VARCHAR(16) NOT NULL, cost INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_cleaning_cost_place_type ON cleaning_cost (place_id, type)');
        $this->addSql('CREATE TABLE cleaning_recurrence (id UUID NOT NULL, place_id VARCHAR(36) NOT NULL, place_name VARCHAR(160) NOT NULL, type VARCHAR(16) NOT NULL, label VARCHAR(120) NOT NULL, frequency VARCHAR(8) NOT NULL, weekdays JSON NOT NULL, month_day INT DEFAULT NULL, nth INT DEFAULT NULL, nth_weekday INT DEFAULT NULL, time VARCHAR(5) NOT NULL, duration_minutes INT NOT NULL, checklist JSON NOT NULL, cost INT DEFAULT NULL, active BOOLEAN NOT NULL, starts_on DATE NOT NULL, ends_on DATE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, assignee_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_cleaning_recurrence_place ON cleaning_recurrence (place_id)');
        $this->addSql('CREATE INDEX IDX_CBE00E1759EC7D60 ON cleaning_recurrence (assignee_id)');
        $this->addSql('CREATE TABLE occupied_period (id UUID NOT NULL, place_id VARCHAR(36) NOT NULL, starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, external_ref VARCHAR(120) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_occupied_period_place ON occupied_period (place_id)');
        $this->addSql('ALTER TABLE cleaning_recurrence ADD CONSTRAINT FK_CBE00E1759EC7D60 FOREIGN KEY (assignee_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cleaning_checklist_item ADD type VARCHAR(16) DEFAULT \'rental\' NOT NULL');
        $this->addSql('ALTER TABLE cleaning_task ADD type VARCHAR(16) DEFAULT \'personal\' NOT NULL');
        $this->addSql('ALTER TABLE cleaning_task ADD origin VARCHAR(16) DEFAULT \'clean\' NOT NULL');
        $this->addSql('ALTER TABLE cleaning_task ADD origin_app VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE cleaning_task ADD cost INT DEFAULT NULL');
        $this->addSql('ALTER TABLE cleaning_task ADD conflict BOOLEAN DEFAULT false NOT NULL');
        $this->addSql("UPDATE cleaning_task SET type = 'rental' WHERE external_ref LIKE 'booking:%'");
        $this->addSql("UPDATE cleaning_task SET origin = 'pms' WHERE external_ref IS NOT NULL AND external_ref NOT LIKE 'demo-%'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cleaning_recurrence DROP CONSTRAINT FK_CBE00E1759EC7D60');
        $this->addSql('DROP TABLE cleaning_cost');
        $this->addSql('DROP TABLE cleaning_recurrence');
        $this->addSql('DROP TABLE occupied_period');
        $this->addSql('ALTER TABLE cleaning_checklist_item DROP type');
        $this->addSql('ALTER TABLE cleaning_task DROP type');
        $this->addSql('ALTER TABLE cleaning_task DROP origin');
        $this->addSql('ALTER TABLE cleaning_task DROP origin_app');
        $this->addSql('ALTER TABLE cleaning_task DROP cost');
        $this->addSql('ALTER TABLE cleaning_task DROP conflict');
    }
}
