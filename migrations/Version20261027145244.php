<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027145244 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E2EE account keys, device registry and key packages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE e2ee_account (id INT AUTO_INCREMENT NOT NULL, public_key VARCHAR(44) NOT NULL, generation INT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_E2EE_ACCOUNT_USER (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE e2ee_device (id VARCHAR(36) NOT NULL, name VARCHAR(64) NOT NULL, public_key VARCHAR(44) NOT NULL, server VARCHAR(255) NOT NULL, generation INT NOT NULL, credential VARCHAR(88) NOT NULL, created_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, revocation JSON DEFAULT NULL, account_id INT NOT NULL, INDEX IDX_E2EE_DEVICE_ACCOUNT_ACTIVE (account_id, revoked_at), INDEX IDX_C58D3DD49B6B5FBA (account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE e2ee_key_package (id INT AUTO_INCREMENT NOT NULL, data LONGTEXT NOT NULL, expires_at DATETIME NOT NULL, last_resort TINYINT NOT NULL, created_at DATETIME NOT NULL, device_id VARCHAR(36) NOT NULL, INDEX IDX_E2EE_KEY_PACKAGE_DEVICE (device_id, last_resort, expires_at), INDEX IDX_49F6B4B794A4C7D4 (device_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE e2ee_account ADD CONSTRAINT FK_F6444CC5A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE e2ee_device ADD CONSTRAINT FK_C58D3DD49B6B5FBA FOREIGN KEY (account_id) REFERENCES e2ee_account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE e2ee_key_package ADD CONSTRAINT FK_49F6B4B794A4C7D4 FOREIGN KEY (device_id) REFERENCES e2ee_device (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE e2ee_account DROP FOREIGN KEY FK_F6444CC5A76ED395');
        $this->addSql('ALTER TABLE e2ee_device DROP FOREIGN KEY FK_C58D3DD49B6B5FBA');
        $this->addSql('ALTER TABLE e2ee_key_package DROP FOREIGN KEY FK_49F6B4B794A4C7D4');
        $this->addSql('DROP TABLE e2ee_account');
        $this->addSql('DROP TABLE e2ee_device');
        $this->addSql('DROP TABLE e2ee_key_package');
    }
}
