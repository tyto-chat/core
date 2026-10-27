<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027100500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill notification.actor_id on single-contributor channel activity rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE notification n
            JOIN user u ON u.id = CAST(JSON_VALUE(n.actor_ids, '$[0]') AS UNSIGNED)
            SET n.actor_id = u.id
            WHERE n.type = 'channel_activity'
              AND n.actor_id IS NULL
              AND JSON_LENGTH(n.actor_ids) = 1
            SQL);
    }

    public function down(Schema $schema): void
    {
    }
}
