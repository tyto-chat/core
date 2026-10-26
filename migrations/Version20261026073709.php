<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026073709 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Attachment animated flag, filled for GIFs at upload and by tyto:media:generate-thumbnails.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_object ADD animated TINYINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_object DROP animated');
    }
}
