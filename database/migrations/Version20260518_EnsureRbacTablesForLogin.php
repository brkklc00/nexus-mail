<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518_EnsureRbacTablesForLogin extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Eksik RBAC tablolarini (roles, permissions, user_roles) tamamlar ve admin izinlerini garanti eder';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;
        $dbName = (string) $conn->getDatabase();

        $tableExists = static function (string $table) use ($conn, $dbName): bool {
            return (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$dbName, $table]
            ) > 0;
        };

        $columnExists = static function (string $table, string $column) use ($conn, $dbName): bool {
            return (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, $table, $column]
            ) > 0;
        };

        if (!$tableExists('roles')) {
            $this->addSql("CREATE TABLE roles (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(50) NOT NULL,
                isReadonly TINYINT(1) NOT NULL DEFAULT 0,
                createdAt DATETIME NOT NULL,
                updatedAt DATETIME NOT NULL,
                UNIQUE INDEX UNIQ_roles_name (name),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        }

        if (!$tableExists('permissions')) {
            $this->addSql("CREATE TABLE permissions (
                id INT AUTO_INCREMENT NOT NULL,
                permission_key VARCHAR(50) NOT NULL,
                name VARCHAR(100) NOT NULL,
                UNIQUE INDEX UNIQ_permissions_key (permission_key),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        }

        if (!$tableExists('user_roles')) {
            $this->addSql("CREATE TABLE user_roles (
                user_id INT NOT NULL,
                role_id INT NOT NULL,
                INDEX IDX_user_roles_user_id (user_id),
                INDEX IDX_user_roles_role_id (role_id),
                PRIMARY KEY(user_id, role_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        }

        if (!$tableExists('role_permissions')) {
            $this->addSql("CREATE TABLE role_permissions (
                id INT AUTO_INCREMENT NOT NULL,
                role_id INT NOT NULL,
                permission_id INT NOT NULL,
                canRead TINYINT(1) NOT NULL DEFAULT 0,
                canCreate TINYINT(1) NOT NULL DEFAULT 0,
                canUpdate TINYINT(1) NOT NULL DEFAULT 0,
                canDelete TINYINT(1) NOT NULL DEFAULT 0,
                INDEX IDX_role_permissions_role (role_id),
                INDEX IDX_role_permissions_permission (permission_id),
                UNIQUE INDEX uniq_role_permission (role_id, permission_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        }

        $this->addSql("INSERT IGNORE INTO roles (name, isReadonly, createdAt, updatedAt) VALUES
            ('admin', 1, NOW(), NOW()),
            ('consumer', 1, NOW(), NOW())");

        $permissions = [
            'email_phonebook' => 'Email Rehber Yönetimi',
            'email_order' => 'Email Sipariş Yönetimi',
            'email_blacklist' => 'Email Kara Liste Yönetimi',
            'email_transactions' => 'Email İşlem Geçmişi',
            'email_smtp' => 'Email SMTP Yönetimi',
            'email_template' => 'Email Şablon Yönetimi',
            'email_dashboard' => 'Email Dashboard',
            'email_data_pool' => 'Email Veri Havuzu Yönetimi',
            'transactional_email' => 'İşlemsel Email',
            'support_ticket' => 'Destek Talepleri',
            'notification' => 'Bildirim Yönetimi',
            'settings' => 'Sistem Ayarları',
            'system_monitor' => 'Sistem İzleme',
            'url_shortener' => 'URL Kısaltma',
            'user' => 'Kullanıcı Yönetimi',
            'role' => 'Rol Yönetimi',
            'admin_email_orders' => 'Admin Email Siparişleri',
            'admin_email_phonebooks' => 'Admin Email Rehberleri',
            'admin_email_blacklists' => 'Admin Email Kara Listeleri',
            'admin_email_transactions' => 'Admin Email İşlemleri',
            'admin_email_templates' => 'Admin Email Şablonları',
            'admin_email_data_pool' => 'Admin Email Veri Havuzu',
        ];

        foreach ($permissions as $key => $name) {
            $this->addSql(
                'INSERT IGNORE INTO permissions (permission_key, name) VALUES (?, ?)',
                [$key, $name]
            );
        }

        // Eski şemada snake_case, yeni şemada camelCase olabilir.
        // Tablo yeni oluşturulduysa camelCase kolonları varsayılır.
        $readCol = $columnExists('role_permissions', 'can_read') ? 'can_read' : 'canRead';
        $createCol = $columnExists('role_permissions', 'can_create') ? 'can_create' : 'canCreate';
        $updateCol = $columnExists('role_permissions', 'can_update') ? 'can_update' : 'canUpdate';
        $deleteCol = $columnExists('role_permissions', 'can_delete') ? 'can_delete' : 'canDelete';

        $this->addSql("
            INSERT INTO role_permissions (role_id, permission_id, {$readCol}, {$createCol}, {$updateCol}, {$deleteCol})
            SELECT r.id, p.id, 1, 1, 1, 1
            FROM roles r
            JOIN permissions p
            WHERE LOWER(r.name) = 'admin'
            AND NOT EXISTS (
                SELECT 1 FROM role_permissions rp
                WHERE rp.role_id = r.id AND rp.permission_id = p.id
            )
        ");

        $this->addSql("
            UPDATE role_permissions rp
            JOIN roles r ON r.id = rp.role_id
            SET rp.{$readCol} = 1, rp.{$createCol} = 1, rp.{$updateCol} = 1, rp.{$deleteCol} = 1
            WHERE LOWER(r.name) = 'admin'
        ");

        if ($tableExists('users')) {
            $this->addSql("
                INSERT IGNORE INTO user_roles (user_id, role_id)
                SELECT u.id, r.id
                FROM users u
                JOIN roles r ON LOWER(r.name) = 'admin'
                WHERE LOWER(u.username) = 'admin'
            ");

            $this->addSql("
                INSERT IGNORE INTO user_roles (user_id, role_id)
                SELECT u.id, r.id
                FROM users u
                JOIN roles r ON LOWER(r.name) = 'admin'
                WHERE NOT EXISTS (
                    SELECT 1 FROM user_roles ur WHERE ur.user_id = u.id
                )
            ");
        }

        // FK'ler yoksa ekle
        $fkUserRolesUserExists = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$dbName, 'user_roles', 'FK_user_roles_user', 'FOREIGN KEY']
        ) > 0;
        if (!$fkUserRolesUserExists) {
            $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        }

        $fkUserRolesRoleExists = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$dbName, 'user_roles', 'FK_user_roles_role', 'FOREIGN KEY']
        ) > 0;
        if (!$fkUserRolesRoleExists) {
            $this->addSql('ALTER TABLE user_roles ADD CONSTRAINT FK_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        // Güvenlik için no-op: mevcut yetki verilerini geri düşürmeyelim.
    }
}

