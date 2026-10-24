<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024220153 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Message current revision pointer + revision count, backfilled from message_body.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD revision_count INT DEFAULT 0 NOT NULL, ADD current_revision_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307FA32ED756 FOREIGN KEY (current_revision_id) REFERENCES message_body (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_B6BD307FA32ED756 ON message (current_revision_id)');
        $this->addSql('UPDATE message m JOIN (SELECT message_id, COUNT(*) AS revision_count, MAX(id) AS current_revision_id FROM message_body GROUP BY message_id) b ON b.message_id = m.id SET m.revision_count = b.revision_count, m.current_revision_id = b.current_revision_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307FA32ED756');
        $this->addSql('DROP INDEX IDX_B6BD307FA32ED756 ON message');
        $this->addSql('ALTER TABLE message DROP revision_count, DROP current_revision_id');
    }
}
