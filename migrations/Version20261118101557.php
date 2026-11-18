<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118101557 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chunks the listener removed from the reading';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE segment ADD COLUMN removed BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE segment DROP COLUMN removed');
    }
}
