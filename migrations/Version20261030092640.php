<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030092640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recovery backups: identity backup on the account and per-device history records.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE e2ee_history_record (id INT AUTO_INCREMENT NOT NULL, counter INT NOT NULL, data LONGTEXT NOT NULL, created_at DATETIME NOT NULL, account_id INT NOT NULL, device_id VARCHAR(36) NOT NULL, INDEX IDX_E2EE_HISTORY_ACCOUNT_ORDER (account_id, device_id, counter), UNIQUE INDEX UNIQ_E2EE_HISTORY_DEVICE_COUNTER (device_id, counter), INDEX IDX_7E7D375B9B6B5FBA (account_id), INDEX IDX_7E7D375B94A4C7D4 (device_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE e2ee_history_record ADD CONSTRAINT FK_7E7D375B9B6B5FBA FOREIGN KEY (account_id) REFERENCES e2ee_account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE e2ee_history_record ADD CONSTRAINT FK_7E7D375B94A4C7D4 FOREIGN KEY (device_id) REFERENCES e2ee_device (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE e2ee_account ADD identity_backup LONGTEXT DEFAULT NULL, ADD identity_backup_generation INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE e2ee_history_record DROP FOREIGN KEY FK_7E7D375B9B6B5FBA');
        $this->addSql('ALTER TABLE e2ee_history_record DROP FOREIGN KEY FK_7E7D375B94A4C7D4');
        $this->addSql('DROP TABLE e2ee_history_record');
        $this->addSql('ALTER TABLE e2ee_account DROP identity_backup, DROP identity_backup_generation');
    }
}
