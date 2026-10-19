<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019155952 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remote-content preference: level + trusted hosts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preference ADD remote_content VARCHAR(16) DEFAULT NULL, ADD remote_content_hosts JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_preference DROP remote_content, DROP remote_content_hosts');
    }
}
