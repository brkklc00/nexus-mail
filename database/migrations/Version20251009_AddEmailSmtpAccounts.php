<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251009_AddEmailSmtpAccounts extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email SMTP hesapları yönetim tablosu';
    }

    public function up(Schema $schema): void
    {
        // Email SMTP Accounts tablosu (kontrol ederek)
        $connection = $this->connection;
        $dbName = $connection->getDatabase();
        
        try {
            $tableCheck = $connection->fetchOne(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = ? 
                AND TABLE_NAME = 'email_smtp_accounts'",
                [$dbName]
            );
            
            if ((int)$tableCheck > 0) {
                // Tablo zaten var, hiçbir şey yapma
                return;
            }
        } catch (\Exception $e) {
            // Hata olursa devam et
        }
        
        // Email SMTP Accounts tablosu
        $table = $schema->createTable('email_smtp_accounts');
        
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('name', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('host', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('port', 'integer', ['notnull' => true]);
        $table->addColumn('username', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('password', 'text', ['notnull' => true]); // Encrypted
        $table->addColumn('encryption', 'string', ['length' => 10, 'notnull' => false]); // tls, ssl
        $table->addColumn('from_email', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('from_name', 'string', ['length' => 255, 'notnull' => false]);
        
        // Limit ve kullanım
        $table->addColumn('daily_limit', 'integer', ['default' => 1000]);
        $table->addColumn('daily_sent', 'integer', ['default' => 0]);
        $table->addColumn('last_reset_date', 'date', ['notnull' => false]);
        
        // Durum ve öncelik
        $table->addColumn('is_active', 'boolean', ['default' => true]);
        $table->addColumn('priority', 'integer', ['default' => 1]); // 1=yüksek, 10=düşük
        
        // İstatistikler
        $table->addColumn('total_sent', 'integer', ['default' => 0]);
        $table->addColumn('total_failed', 'integer', ['default' => 0]);
        $table->addColumn('success_rate', 'decimal', ['precision' => 5, 'scale' => 2, 'default' => '100.00']);
        
        // Log
        $table->addColumn('last_used_at', 'datetime', ['notnull' => false]);
        $table->addColumn('last_error', 'text', ['notnull' => false]);
        
        // Timestamps
        $table->addColumn('created_at', 'datetime', ['notnull' => true]);
        $table->addColumn('updated_at', 'datetime', ['notnull' => true]);
        
        $table->setPrimaryKey(['id']);
        $table->addIndex(['is_active'], 'idx_smtp_active');
        $table->addIndex(['priority'], 'idx_smtp_priority');
        $table->addIndex(['last_reset_date'], 'idx_smtp_reset_date');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('email_smtp_accounts');
    }
}

