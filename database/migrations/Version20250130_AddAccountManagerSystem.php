<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hesap Yöneticisi (Account Manager) Sistemi Migration
 * 
 * Bu migration users tablosuna parent_user_id kolonu ekler
 * Böylece kullanıcılar arasında parent-child ilişkisi kurulabilir
 */
final class Version20250130_AddAccountManagerSystem extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hesap Yöneticisi (Account Manager) sistemi - parent_user_id kolonu ekle';
    }

    public function up(Schema $schema): void
    {
        $connection = $this->connection;
        
        // parent_user_id kolonu var mı kontrol et
        $columnExists = $connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'users' 
            AND COLUMN_NAME = 'parent_user_id'"
        );
        
        if ((int)$columnExists === 0) {
            // users tablosuna parent_user_id kolonu ekle
            $this->addSql('ALTER TABLE users ADD COLUMN parent_user_id INT NULL');
        }
        
        // Index var mı kontrol et
        $indexExists = $connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'users' 
            AND INDEX_NAME = 'idx_parent_user_id'"
        );
        
        if ((int)$indexExists === 0) {
            // Index oluştur
            $this->addSql('CREATE INDEX idx_parent_user_id ON users (parent_user_id)');
        }
        
        // Foreign key constraint var mı kontrol et
        $fkExists = $connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'users' 
            AND CONSTRAINT_NAME = 'fk_users_parent_user'"
        );
        
        if ((int)$fkExists === 0 && (int)$columnExists === 0) {
            // Foreign key constraint ekle (sadece yeni kolon eklendiyse)
            $this->addSql('ALTER TABLE users 
                ADD CONSTRAINT fk_users_parent_user 
                FOREIGN KEY (parent_user_id) 
                REFERENCES users(id) 
                ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // Foreign key constraint'i kaldır
        $this->addSql('ALTER TABLE users DROP FOREIGN KEY fk_users_parent_user');
        
        // Index'i kaldır
        $this->addSql('DROP INDEX idx_parent_user_id ON users');
        
        // parent_user_id kolonunu kaldır
        $this->addSql('ALTER TABLE users DROP COLUMN parent_user_id');
    }
}
