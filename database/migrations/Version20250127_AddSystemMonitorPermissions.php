<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250127_AddSystemMonitorPermissions extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'system_monitor izni ve admin role_permissions';
    }

    public function up(Schema $schema): void
    {
        $connection = $this->connection;
        $dbName = $connection->getDatabase();

        // RBAC tabloları henüz yoksa atla (Version20260518_EnsureRbacTablesForLogin oluşturur)
        $permissionsExists = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$dbName, 'permissions']
        ) > 0;
        if (!$permissionsExists) {
            return;
        }

        // Kolon adlarını dinamik olarak belirle (camelCase vs snake_case)
        $columns = $connection->fetchAllAssociative(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = ? 
            AND TABLE_NAME = 'role_permissions' 
            AND COLUMN_NAME IN ('canRead', 'can_read', 'canCreate', 'can_create', 'canUpdate', 'can_update', 'canDelete', 'can_delete')
            ORDER BY COLUMN_NAME",
            [$dbName]
        );

        $columnMap = [];
        foreach ($columns as $col) {
            $name = strtolower($col['COLUMN_NAME']);
            $columnMap[$name] = $col['COLUMN_NAME'];
        }

        // Kolon adlarını belirle
        $readCol = $columnMap['canread'] ?? $columnMap['can_read'] ?? 'canRead';
        $createCol = $columnMap['cancreate'] ?? $columnMap['can_create'] ?? 'canCreate';
        $updateCol = $columnMap['canupdate'] ?? $columnMap['can_update'] ?? 'canUpdate';
        $deleteCol = $columnMap['candelete'] ?? $columnMap['can_delete'] ?? 'canDelete';

        // 1. Permission oluştur (eğer yoksa)
        $connection->executeStatement("
            INSERT IGNORE INTO permissions (permission_key, name) 
            VALUES ('system_monitor', 'Sunucu Yönetimi (Sistem İzleme, Dosya Yöneticisi, Yedekleme)')
        ");

        // 2. Admin role için TÜM izinleri ver (admin rolü yoksa satır ekleme — role_id NULL hatası önlenir)
        $connection->executeStatement("
            INSERT INTO role_permissions (role_id, permission_id, {$readCol}, {$createCol}, {$updateCol}, {$deleteCol})
            SELECT 
                (SELECT id FROM roles WHERE LOWER(name) = 'admin' LIMIT 1) as role_id,
                (SELECT id FROM permissions WHERE permission_key = 'system_monitor' LIMIT 1) as permission_id,
                1, 1, 1, 1
            FROM (SELECT 1) AS _m
            WHERE NOT EXISTS (
                SELECT 1 FROM role_permissions 
                WHERE role_id = (SELECT id FROM roles WHERE LOWER(name) = 'admin' LIMIT 1)
                AND permission_id = (SELECT id FROM permissions WHERE permission_key = 'system_monitor' LIMIT 1)
            )
            AND (SELECT id FROM roles WHERE LOWER(name) = 'admin' LIMIT 1) IS NOT NULL
            AND (SELECT id FROM permissions WHERE permission_key = 'system_monitor' LIMIT 1) IS NOT NULL
        ");

        // 3. Varsa güncelle
        $connection->executeStatement("
            UPDATE role_permissions 
            SET {$readCol} = 1, {$createCol} = 1, {$updateCol} = 1, {$deleteCol} = 1
            WHERE role_id = (SELECT id FROM roles WHERE LOWER(name) = 'admin' LIMIT 1)
            AND permission_id = (SELECT id FROM permissions WHERE permission_key = 'system_monitor' LIMIT 1)
            AND (SELECT id FROM roles WHERE LOWER(name) = 'admin' LIMIT 1) IS NOT NULL
        ");
    }

    public function down(Schema $schema): void
    {
        $connection = $this->connection;
        $connection->executeStatement("
            DELETE FROM role_permissions 
            WHERE permission_id = (SELECT id FROM permissions WHERE permission_key = 'system_monitor' LIMIT 1)
        ");

        $connection->executeStatement("
            DELETE FROM permissions WHERE permission_key = 'system_monitor'
        ");
    }
}
