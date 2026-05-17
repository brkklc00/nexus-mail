<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Kullanıcı Bazlı Sayfa Erişim Kontrolü
 * 
 * Bu migration users tablosuna allowed_pages JSON kolonu ekler
 * Böylece her kullanıcı için hangi sayfalara erişebileceği ayarlanabilir
 */
final class Version20250201_AddUserPageAccessControl extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kullanıcı bazlı sayfa erişim kontrolü - allowed_pages kolonu ekle';
    }

    public function up(Schema $schema): void
    {
        // users tablosuna allowed_pages JSON kolonu ekle
        // MySQL 5.7+ için JSON tipi desteklenir
        // Kolon var mı kontrol et
        $connection = $this->connection;
        
        try {
            // Kolon var mı kontrol et
            $result = $connection->fetchOne("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'users' 
                AND COLUMN_NAME = 'allowed_pages'");
            
            if ((int)$result === 0) {
                // Kolon yok, ekle
                $this->addSql('ALTER TABLE users ADD COLUMN allowed_pages JSON NULL');
            }
        } catch (\Exception $e) {
            // Hata durumunda yine de eklemeyi dene (eğer zaten varsa hata verir ama zararsız)
            $this->addSql('ALTER TABLE users ADD COLUMN allowed_pages JSON NULL');
        }

        // Açıklama: 
        // NULL = Tüm sayfalara erişebilir (role bazlı kontrol - varsayılan)
        // [] = Hiçbir sayfaya erişemez
        // ["/orders", "/phone-books"] = Sadece listelenen sayfalara erişebilir
    }

    public function down(Schema $schema): void
    {
        // allowed_pages kolonunu kaldır
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS allowed_pages');
    }
}

