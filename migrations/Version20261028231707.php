<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028231707 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MLS delivery service: group epochs, welcomes and leave requests.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE e2ee_group_epoch (id INT AUTO_INCREMENT NOT NULL, epoch INT NOT NULL, generation INT NOT NULL, commit_id VARCHAR(36) NOT NULL, device_id VARCHAR(36) NOT NULL, commit MEDIUMTEXT NOT NULL, group_info MEDIUMTEXT NOT NULL, leave_request_id VARCHAR(36) DEFAULT NULL, member_generations JSON NOT NULL, created_at DATETIME NOT NULL, conversation_id INT NOT NULL, UNIQUE INDEX UNIQ_E2EE_GROUP_EPOCH (conversation_id, epoch), UNIQUE INDEX UNIQ_E2EE_GROUP_EPOCH_COMMIT (commit_id), INDEX IDX_17992ED49AC0396 (conversation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE e2ee_leave_request (id INT AUTO_INCREMENT NOT NULL, leave_request_id VARCHAR(36) NOT NULL, created_at DATETIME NOT NULL, fulfilled_at DATETIME DEFAULT NULL, device_id VARCHAR(36) NOT NULL, conversation_id INT NOT NULL, INDEX IDX_E2EE_LEAVE_REQUEST_PENDING (conversation_id, fulfilled_at), UNIQUE INDEX UNIQ_E2EE_LEAVE_REQUEST_CONVERSATION (leave_request_id, conversation_id), INDEX IDX_B86638D294A4C7D4 (device_id), INDEX IDX_B86638D29AC0396 (conversation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE e2ee_welcome (id VARCHAR(36) NOT NULL, data MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, device_id VARCHAR(36) NOT NULL, group_epoch_id INT NOT NULL, INDEX IDX_E2EE_WELCOME_DEVICE (device_id, delivered_at), INDEX IDX_BCB97BD294A4C7D4 (device_id), INDEX IDX_BCB97BD258D39E3E (group_epoch_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE e2ee_group_epoch ADD CONSTRAINT FK_17992ED49AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE e2ee_leave_request ADD CONSTRAINT FK_B86638D294A4C7D4 FOREIGN KEY (device_id) REFERENCES e2ee_device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE e2ee_leave_request ADD CONSTRAINT FK_B86638D29AC0396 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE e2ee_welcome ADD CONSTRAINT FK_BCB97BD294A4C7D4 FOREIGN KEY (device_id) REFERENCES e2ee_device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE e2ee_welcome ADD CONSTRAINT FK_BCB97BD258D39E3E FOREIGN KEY (group_epoch_id) REFERENCES e2ee_group_epoch (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE e2ee_group_epoch DROP FOREIGN KEY FK_17992ED49AC0396');
        $this->addSql('ALTER TABLE e2ee_leave_request DROP FOREIGN KEY FK_B86638D294A4C7D4');
        $this->addSql('ALTER TABLE e2ee_leave_request DROP FOREIGN KEY FK_B86638D29AC0396');
        $this->addSql('ALTER TABLE e2ee_welcome DROP FOREIGN KEY FK_BCB97BD294A4C7D4');
        $this->addSql('ALTER TABLE e2ee_welcome DROP FOREIGN KEY FK_BCB97BD258D39E3E');
        $this->addSql('DROP TABLE e2ee_group_epoch');
        $this->addSql('DROP TABLE e2ee_leave_request');
        $this->addSql('DROP TABLE e2ee_welcome');
    }
}
