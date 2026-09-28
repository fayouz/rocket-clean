<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928183925 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Linen module: types, kits, needs per place, counts, movements, in-house washes, laundries and batches (linen_* tables).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE linen_batch (id UUID NOT NULL, place_id VARCHAR(36) NOT NULL, place_name VARCHAR(160) NOT NULL, usage VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL, sent_lines JSON NOT NULL, weight_grams INT DEFAULT NULL, sent_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expected_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, returned_lines JSON NOT NULL, damaged_lines JSON NOT NULL, discrepancies JSON NOT NULL, returned_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, cost INT DEFAULT NULL, note VARCHAR(1000) DEFAULT NULL, email_sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, laundry_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_linen_batch_status ON linen_batch (status)');
        $this->addSql('CREATE INDEX IDX_8694613C330BCF4 ON linen_batch (laundry_id)');
        $this->addSql('CREATE TABLE linen_count (id UUID NOT NULL, place_id VARCHAR(36) NOT NULL, location VARCHAR(16) NOT NULL, state VARCHAR(16) NOT NULL, qty INT NOT NULL, item_type_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_linen_count ON linen_count (place_id, location, item_type_id, state)');
        $this->addSql('CREATE INDEX IDX_75BB50A5CE11AAC7 ON linen_count (item_type_id)');
        $this->addSql('CREATE TABLE linen_item_type (id UUID NOT NULL, name VARCHAR(80) NOT NULL, stock_item_id VARCHAR(36) DEFAULT NULL, weight_grams INT DEFAULT NULL, position INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE linen_kit (id UUID NOT NULL, name VARCHAR(80) NOT NULL, lines JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE linen_laundry (id UUID NOT NULL, name VARCHAR(120) NOT NULL, order_email VARCHAR(180) DEFAULT NULL, pricing VARCHAR(8) NOT NULL, price_per_kg INT DEFAULT NULL, piece_prices JSON NOT NULL, turnaround_days INT NOT NULL, active BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE linen_movement (id UUID NOT NULL, place_id VARCHAR(36) NOT NULL, from_state VARCHAR(16) DEFAULT NULL, from_location VARCHAR(16) DEFAULT NULL, to_state VARCHAR(16) DEFAULT NULL, to_location VARCHAR(16) DEFAULT NULL, qty INT NOT NULL, reason VARCHAR(160) NOT NULL, external_ref VARCHAR(160) DEFAULT NULL, origin VARCHAR(16) NOT NULL, usage VARCHAR(16) NOT NULL, created_by VARCHAR(180) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, item_type_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_linen_movement_place ON linen_movement (place_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_linen_movement_ref ON linen_movement (external_ref)');
        $this->addSql('CREATE INDEX IDX_ED2FC2E3CE11AAC7 ON linen_movement (item_type_id)');
        $this->addSql('CREATE TABLE linen_par (id UUID NOT NULL, place_id VARCHAR(36) NOT NULL, units INT NOT NULL, kits_per_unit INT NOT NULL, kit_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_linen_par_place_kit ON linen_par (place_id, kit_id)');
        $this->addSql('CREATE INDEX IDX_333A80A13A8E60EF ON linen_par (kit_id)');
        $this->addSql('CREATE TABLE linen_wash_task (id UUID NOT NULL, place_id VARCHAR(36) NOT NULL, place_name VARCHAR(160) NOT NULL, label VARCHAR(120) NOT NULL, scheduled_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, status VARCHAR(16) NOT NULL, steps JSON NOT NULL, lines JSON NOT NULL, usage VARCHAR(16) NOT NULL, completed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, assignee_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_linen_wash_task_scheduled ON linen_wash_task (scheduled_at)');
        $this->addSql('CREATE INDEX IDX_8417271F59EC7D60 ON linen_wash_task (assignee_id)');
        $this->addSql('ALTER TABLE linen_batch ADD CONSTRAINT FK_8694613C330BCF4 FOREIGN KEY (laundry_id) REFERENCES linen_laundry (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE linen_count ADD CONSTRAINT FK_75BB50A5CE11AAC7 FOREIGN KEY (item_type_id) REFERENCES linen_item_type (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE linen_movement ADD CONSTRAINT FK_ED2FC2E3CE11AAC7 FOREIGN KEY (item_type_id) REFERENCES linen_item_type (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE linen_par ADD CONSTRAINT FK_333A80A13A8E60EF FOREIGN KEY (kit_id) REFERENCES linen_kit (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE linen_wash_task ADD CONSTRAINT FK_8417271F59EC7D60 FOREIGN KEY (assignee_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE linen_batch DROP CONSTRAINT FK_8694613C330BCF4');
        $this->addSql('ALTER TABLE linen_count DROP CONSTRAINT FK_75BB50A5CE11AAC7');
        $this->addSql('ALTER TABLE linen_movement DROP CONSTRAINT FK_ED2FC2E3CE11AAC7');
        $this->addSql('ALTER TABLE linen_par DROP CONSTRAINT FK_333A80A13A8E60EF');
        $this->addSql('ALTER TABLE linen_wash_task DROP CONSTRAINT FK_8417271F59EC7D60');
        $this->addSql('DROP TABLE linen_batch');
        $this->addSql('DROP TABLE linen_count');
        $this->addSql('DROP TABLE linen_item_type');
        $this->addSql('DROP TABLE linen_kit');
        $this->addSql('DROP TABLE linen_laundry');
        $this->addSql('DROP TABLE linen_movement');
        $this->addSql('DROP TABLE linen_par');
        $this->addSql('DROP TABLE linen_wash_task');
    }
}
