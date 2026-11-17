<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117171454 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE article (id BLOB NOT NULL, status VARCHAR(16) NOT NULL, failure VARCHAR(64) DEFAULT NULL, title VARCHAR(512) DEFAULT NULL, site_name VARCHAR(255) DEFAULT NULL, author VARCHAR(255) DEFAULT NULL, excerpt CLOB DEFAULT NULL, image_url VARCHAR(2048) DEFAULT NULL, published_at DATETIME DEFAULT NULL, language VARCHAR(2) DEFAULT NULL, voice_name VARCHAR(64) DEFAULT NULL, voice_engine VARCHAR(32) NOT NULL, editor VARCHAR(128) DEFAULT NULL, summary CLOB DEFAULT NULL, word_count INTEGER NOT NULL, paragraph_count INTEGER NOT NULL, revision INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, ready_at DATETIME DEFAULT NULL, url VARCHAR(2048) NOT NULL, language_setting VARCHAR(4) NOT NULL, voice_gender VARCHAR(8) NOT NULL, source VARCHAR(16) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX article_created_at ON article (created_at)');
        $this->addSql('CREATE TABLE segment (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, paragraph INTEGER NOT NULL, kind VARCHAR(16) NOT NULL, text CLOB NOT NULL, spoken CLOB NOT NULL, edited BOOLEAN NOT NULL, pause_after_ms INTEGER NOT NULL, audio_key VARCHAR(64) DEFAULT NULL, duration_ms INTEGER DEFAULT NULL, position INTEGER NOT NULL, article_id BLOB NOT NULL, CONSTRAINT FK_1881F5657294869C FOREIGN KEY (article_id) REFERENCES article (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX segment_position ON segment (article_id, position)');
        $this->addSql('CREATE INDEX IDX_1881F5657294869C ON segment (article_id)');
        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE article');
        $this->addSql('DROP TABLE segment');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
