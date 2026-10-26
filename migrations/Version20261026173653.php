<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026173653 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_block table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_block (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, blocker_id INT NOT NULL, blocked_id INT NOT NULL, INDEX IDX_USER_BLOCK_BLOCKED (blocked_id), UNIQUE INDEX UNIQ_USER_BLOCK_PAIR (blocker_id, blocked_id), INDEX IDX_61D96C7A548D5975 (blocker_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_block ADD CONSTRAINT FK_61D96C7A548D5975 FOREIGN KEY (blocker_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_block ADD CONSTRAINT FK_61D96C7A21FF5136 FOREIGN KEY (blocked_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_block DROP FOREIGN KEY FK_61D96C7A548D5975');
        $this->addSql('ALTER TABLE user_block DROP FOREIGN KEY FK_61D96C7A21FF5136');
        $this->addSql('DROP TABLE user_block');
    }
}
