<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026180829 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add notification.actor_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD actor_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA10DAF24A FOREIGN KEY (actor_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_BF5476CA10DAF24A ON notification (actor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP FOREIGN KEY FK_BF5476CA10DAF24A');
        $this->addSql('DROP INDEX IDX_BF5476CA10DAF24A ON notification');
        $this->addSql('ALTER TABLE notification DROP actor_id');
    }
}
