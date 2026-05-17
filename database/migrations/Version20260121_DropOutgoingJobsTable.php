<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baileys worker kaldırıldı; outgoing_jobs tablosu artık kullanılmıyor.
 */
final class Version20260121_DropOutgoingJobsTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'outgoing_jobs tablosunu kaldır (Green-API)';
    }

    public function up(Schema $schema): void
    {
        $connection = $this->connection;
        $dbName = $connection->getDatabase();

        $tableExists = $connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'outgoing_jobs'",
            [$dbName]
        );

        if ((int) $tableExists > 0) {
            $connection->executeStatement('DROP TABLE IF EXISTS `outgoing_jobs`');
        }
    }

    public function down(Schema $schema): void
    {
        // Bilinçli no-op: tablo şeması geri yüklenmez
    }
}
