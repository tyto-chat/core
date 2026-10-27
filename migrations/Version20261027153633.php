<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027153633 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E2EE key packages: fingerprint per device and claimed tombstones';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM e2ee_key_package');
        $this->addSql('ALTER TABLE e2ee_key_package ADD fingerprint VARCHAR(64) NOT NULL, ADD claimed_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E2EE_KEY_PACKAGE_FINGERPRINT ON e2ee_key_package (device_id, fingerprint)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_E2EE_KEY_PACKAGE_FINGERPRINT ON e2ee_key_package');
        $this->addSql('ALTER TABLE e2ee_key_package DROP fingerprint, DROP claimed_at');
    }
}
