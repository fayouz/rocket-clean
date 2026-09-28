<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260928182638 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Voice assistant: checklist synonyms, photo flag and area; cleaning incidents and report';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE cleaning_checklist_item ADD synonyms JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE cleaning_checklist_item ALTER synonyms DROP DEFAULT');
        $this->addSql('ALTER TABLE cleaning_checklist_item ADD photo BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE cleaning_checklist_item ADD area VARCHAR(80) DEFAULT NULL');
        $this->addSql("ALTER TABLE cleaning_task ADD incidents JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE cleaning_task ALTER incidents DROP DEFAULT');
        $this->addSql('ALTER TABLE cleaning_task ADD report JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cleaning_checklist_item DROP synonyms');
        $this->addSql('ALTER TABLE cleaning_checklist_item DROP photo');
        $this->addSql('ALTER TABLE cleaning_checklist_item DROP area');
        $this->addSql('ALTER TABLE cleaning_task DROP incidents');
        $this->addSql('ALTER TABLE cleaning_task DROP report');
    }
}
