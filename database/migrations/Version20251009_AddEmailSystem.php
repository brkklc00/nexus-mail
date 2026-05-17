<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251009_AddEmailSystem extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email sistemi için tüm tablolar (phonebooks, orders, blacklist, transactions, otp, data_pool, templates)';
    }

    public function up(Schema $schema): void
    {
        $connection = $this->connection;
        $dbName = $connection->getDatabase();
        
        // Helper function: Tablo var mı kontrol et
        $tableExists = function($tableName) use ($connection, $dbName) {
            try {
                $result = $connection->fetchOne(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
                    [$dbName, $tableName]
                );
                return (int)$result > 0;
            } catch (\Exception $e) {
                return false;
            }
        };
        
        // 1. Email PhoneBooks (Rehberler)
        if (!$tableExists('email_phonebooks')) {
            $emailPhonebooks = $schema->createTable('email_phonebooks');
            $emailPhonebooks->addColumn('id', 'integer', ['autoincrement' => true]);
            $emailPhonebooks->addColumn('user_id', 'integer', ['notnull' => true]);
            $emailPhonebooks->addColumn('title', 'string', ['length' => 255, 'notnull' => true]);
            $emailPhonebooks->addColumn('description', 'text', ['notnull' => false]);
            $emailPhonebooks->addColumn('total_contacts', 'integer', ['default' => 0]);
            $emailPhonebooks->addColumn('created_at', 'datetime', ['notnull' => true]);
            $emailPhonebooks->addColumn('updated_at', 'datetime', ['notnull' => true]);
            $emailPhonebooks->setPrimaryKey(['id']);
            $emailPhonebooks->addIndex(['user_id'], 'idx_email_phonebook_user');
            $emailPhonebooks->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_email_phonebook_user');
        }

        // 2. Email Contacts (Email Adresleri)
        if (!$tableExists('email_contacts')) {
            $emailContacts = $schema->createTable('email_contacts');
            $emailContacts->addColumn('id', 'integer', ['autoincrement' => true]);
            $emailContacts->addColumn('phonebook_id', 'integer', ['notnull' => true]);
            $emailContacts->addColumn('email', 'string', ['length' => 255, 'notnull' => true]);
            $emailContacts->addColumn('name', 'string', ['length' => 255, 'notnull' => false]);
            $emailContacts->addColumn('created_at', 'datetime', ['notnull' => true]);
            $emailContacts->setPrimaryKey(['id']);
            $emailContacts->addIndex(['phonebook_id'], 'idx_email_contact_phonebook');
            $emailContacts->addIndex(['email'], 'idx_email_contact_email');
            $emailContacts->addForeignKeyConstraint('email_phonebooks', ['phonebook_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_email_contact_phonebook');
        }

        // 3. Email Orders (Email Siparişleri)
        if (!$tableExists('email_orders')) {
            $emailOrders = $schema->createTable('email_orders');
            $emailOrders->addColumn('id', 'integer', ['autoincrement' => true]);
            $emailOrders->addColumn('user_id', 'integer', ['notnull' => true]);
            $emailOrders->addColumn('smtp_account_id', 'integer', ['notnull' => false]);
            $emailOrders->addColumn('subject', 'string', ['length' => 500, 'notnull' => true]);
            $emailOrders->addColumn('body', 'text', ['notnull' => true]);
            $emailOrders->addColumn('status', 'string', ['length' => 50, 'notnull' => true, 'default' => 'pending']);
            $emailOrders->addColumn('total', 'integer', ['default' => 0]);
            $emailOrders->addColumn('sent', 'integer', ['default' => 0]);
            $emailOrders->addColumn('delivered', 'integer', ['default' => 0]);
            $emailOrders->addColumn('bounced', 'integer', ['default' => 0]);
            $emailOrders->addColumn('failed', 'integer', ['default' => 0]);
            $emailOrders->addColumn('cost', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => '0.00']);
            $emailOrders->addColumn('delivery_percentage', 'integer', ['default' => 100]);
            $emailOrders->addColumn('target_campaign_id', 'string', ['length' => 255, 'notnull' => false]);
            $emailOrders->addColumn('created_at', 'datetime', ['notnull' => true]);
            $emailOrders->addColumn('updated_at', 'datetime', ['notnull' => true]);
            $emailOrders->addColumn('completed_at', 'datetime', ['notnull' => false]);
            $emailOrders->setPrimaryKey(['id']);
            $emailOrders->addIndex(['user_id'], 'idx_email_order_user');
            $emailOrders->addIndex(['status'], 'idx_email_order_status');
            $emailOrders->addIndex(['smtp_account_id'], 'idx_email_order_smtp');
            $emailOrders->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_email_order_user');
            $emailOrders->addForeignKeyConstraint('email_smtp_accounts', ['smtp_account_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_email_order_smtp');
        }

        // 4. Email Order Emails (Sipariş Email Detayları)
        if (!$tableExists('email_order_emails')) {
            $emailOrderEmails = $schema->createTable('email_order_emails');
            $emailOrderEmails->addColumn('id', 'integer', ['autoincrement' => true]);
            $emailOrderEmails->addColumn('order_id', 'integer', ['notnull' => true]);
            $emailOrderEmails->addColumn('email', 'string', ['length' => 255, 'notnull' => true]);
            $emailOrderEmails->addColumn('name', 'string', ['length' => 255, 'notnull' => false]);
            $emailOrderEmails->addColumn('status', 'string', ['length' => 50, 'notnull' => true, 'default' => 'pending']);
            $emailOrderEmails->addColumn('message_id', 'string', ['length' => 255, 'notnull' => false]);
            $emailOrderEmails->addColumn('error', 'text', ['notnull' => false]);
            $emailOrderEmails->addColumn('sent_at', 'datetime', ['notnull' => false]);
            $emailOrderEmails->addColumn('delivered_at', 'datetime', ['notnull' => false]);
            $emailOrderEmails->addColumn('created_at', 'datetime', ['notnull' => true]);
            $emailOrderEmails->setPrimaryKey(['id']);
            $emailOrderEmails->addIndex(['order_id'], 'idx_email_order_email_order');
            $emailOrderEmails->addIndex(['status'], 'idx_email_order_email_status');
            $emailOrderEmails->addIndex(['email'], 'idx_email_order_email_email');
            $emailOrderEmails->addForeignKeyConstraint('email_orders', ['order_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_email_order_email_order');
        }

        // 5. Email Blacklist (Email Karaliste)
        if (!$tableExists('email_blacklist')) {
            $emailBlacklist = $schema->createTable('email_blacklist');
            $emailBlacklist->addColumn('id', 'integer', ['autoincrement' => true]);
            $emailBlacklist->addColumn('user_id', 'integer', ['notnull' => true]);
            $emailBlacklist->addColumn('email', 'string', ['length' => 255, 'notnull' => true]);
            $emailBlacklist->addColumn('reason', 'string', ['length' => 255, 'notnull' => false]);
            $emailBlacklist->addColumn('created_at', 'datetime', ['notnull' => true]);
            $emailBlacklist->setPrimaryKey(['id']);
            $emailBlacklist->addIndex(['user_id'], 'idx_email_blacklist_user');
            $emailBlacklist->addIndex(['email'], 'idx_email_blacklist_email');
            $emailBlacklist->addUniqueIndex(['user_id', 'email'], 'uniq_email_blacklist_user_email');
            $emailBlacklist->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_email_blacklist_user');
        }

        // 6. Email Transactions (Email İşlem Geçmişi)
        if (!$tableExists('email_transactions')) {
            $emailTransactions = $schema->createTable('email_transactions');
            $emailTransactions->addColumn('id', 'integer', ['autoincrement' => true]);
            $emailTransactions->addColumn('user_id', 'integer', ['notnull' => true]);
            $emailTransactions->addColumn('type', 'string', ['length' => 50, 'notnull' => true]);
            $emailTransactions->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2, 'notnull' => true]);
            $emailTransactions->addColumn('balance_before', 'decimal', ['precision' => 10, 'scale' => 2, 'notnull' => true]);
            $emailTransactions->addColumn('balance_after', 'decimal', ['precision' => 10, 'scale' => 2, 'notnull' => true]);
            $emailTransactions->addColumn('description', 'text', ['notnull' => false]);
            $emailTransactions->addColumn('reference_type', 'string', ['length' => 50, 'notnull' => false]);
            $emailTransactions->addColumn('reference_id', 'integer', ['notnull' => false]);
            $emailTransactions->addColumn('created_at', 'datetime', ['notnull' => true]);
            $emailTransactions->setPrimaryKey(['id']);
            $emailTransactions->addIndex(['user_id'], 'idx_email_transaction_user');
            $emailTransactions->addIndex(['type'], 'idx_email_transaction_type');
            $emailTransactions->addIndex(['created_at'], 'idx_email_transaction_date');
            $emailTransactions->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_email_transaction_user');
        }

        // 7. Email OTP History (Email OTP Geçmişi)
        if (!$tableExists('email_otp_history')) {
            $emailOtpHistory = $schema->createTable('email_otp_history');
            $emailOtpHistory->addColumn('id', 'integer', ['autoincrement' => true]);
            $emailOtpHistory->addColumn('user_id', 'integer', ['notnull' => true]);
            $emailOtpHistory->addColumn('email', 'string', ['length' => 255, 'notnull' => true]);
            $emailOtpHistory->addColumn('otp_code', 'string', ['length' => 20, 'notnull' => true]);
            $emailOtpHistory->addColumn('message_id', 'string', ['length' => 255, 'notnull' => false]);
            $emailOtpHistory->addColumn('verified', 'boolean', ['default' => false]);
            $emailOtpHistory->addColumn('verified_at', 'datetime', ['notnull' => false]);
            $emailOtpHistory->addColumn('expires_at', 'datetime', ['notnull' => true]);
            $emailOtpHistory->addColumn('created_at', 'datetime', ['notnull' => true]);
            $emailOtpHistory->setPrimaryKey(['id']);
            $emailOtpHistory->addIndex(['user_id'], 'idx_email_otp_user');
            $emailOtpHistory->addIndex(['email'], 'idx_email_otp_email');
            $emailOtpHistory->addIndex(['otp_code'], 'idx_email_otp_code');
            $emailOtpHistory->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_email_otp_user');
        }

        // 8. Email Data Pool (Admin Mail Havuzu)
        if (!$tableExists('email_data_pool')) {
            $emailDataPool = $schema->createTable('email_data_pool');
            $emailDataPool->addColumn('id', 'integer', ['autoincrement' => true]);
            $emailDataPool->addColumn('email', 'string', ['length' => 255, 'notnull' => true]);
            $emailDataPool->addColumn('name', 'string', ['length' => 255, 'notnull' => false]);
            $emailDataPool->addColumn('category', 'string', ['length' => 100, 'notnull' => false]);
            $emailDataPool->addColumn('tags', 'text', ['notnull' => false]);
            $emailDataPool->addColumn('is_active', 'boolean', ['default' => true]);
            $emailDataPool->addColumn('created_at', 'datetime', ['notnull' => true]);
            $emailDataPool->addColumn('updated_at', 'datetime', ['notnull' => true]);
            $emailDataPool->setPrimaryKey(['id']);
            $emailDataPool->addIndex(['email'], 'idx_email_data_pool_email');
            $emailDataPool->addIndex(['category'], 'idx_email_data_pool_category');
            $emailDataPool->addIndex(['is_active'], 'idx_email_data_pool_active');
        }

        // 9. Email Templates (Mail Şablonları)
        if (!$tableExists('email_templates')) {
            $emailTemplates = $schema->createTable('email_templates');
            $emailTemplates->addColumn('id', 'integer', ['autoincrement' => true]);
            $emailTemplates->addColumn('name', 'string', ['length' => 255, 'notnull' => true]);
            $emailTemplates->addColumn('subject', 'string', ['length' => 500, 'notnull' => true]);
            $emailTemplates->addColumn('body', 'text', ['notnull' => true]);
            $emailTemplates->addColumn('variables', 'text', ['notnull' => false]);
            $emailTemplates->addColumn('is_system', 'boolean', ['default' => false]);
            $emailTemplates->addColumn('created_at', 'datetime', ['notnull' => true]);
            $emailTemplates->addColumn('updated_at', 'datetime', ['notnull' => true]);
            $emailTemplates->setPrimaryKey(['id']);
            $emailTemplates->addIndex(['is_system'], 'idx_email_template_system');
        }

        // 10. Users tablosuna email kolonları — $schema->getTable('users') kullanılmaz:
        // DB'de ENUM vb. sütunlar varken DBAL introspection "Unknown database type enum" verir.
        $hasUserCol = static function ($connection, string $dbName, string $col): bool {
            $n = (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, 'users', $col],
            );

            return $n > 0;
        };

        if (!$hasUserCol($connection, $dbName, 'email_credit')) {
            $this->addSql('ALTER TABLE users ADD COLUMN email_credit DECIMAL(10,2) NOT NULL DEFAULT 0');
        }
        if (!$hasUserCol($connection, $dbName, 'email_delivery_percentage')) {
            $this->addSql('ALTER TABLE users ADD COLUMN email_delivery_percentage INT NOT NULL DEFAULT 100');
        }
        if (!$hasUserCol($connection, $dbName, 'email_refund_enabled')) {
            $this->addSql('ALTER TABLE users ADD COLUMN email_refund_enabled TINYINT(1) NOT NULL DEFAULT 1');
        }
    }

    public function down(Schema $schema): void
    {
        // Drop tables in reverse order
        $schema->dropTable('email_templates');
        $schema->dropTable('email_data_pool');
        $schema->dropTable('email_otp_history');
        $schema->dropTable('email_transactions');
        $schema->dropTable('email_blacklist');
        $schema->dropTable('email_order_emails');
        $schema->dropTable('email_orders');
        $schema->dropTable('email_contacts');
        $schema->dropTable('email_phonebooks');

        $connection = $this->connection;
        $dbName = $connection->getDatabase();
        $has = static function ($connection, string $dbName, string $col): bool {
            return (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, 'users', $col],
            ) > 0;
        };
        if ($has($connection, $dbName, 'email_credit')) {
            $this->addSql('ALTER TABLE users DROP COLUMN email_credit');
        }
        if ($has($connection, $dbName, 'email_delivery_percentage')) {
            $this->addSql('ALTER TABLE users DROP COLUMN email_delivery_percentage');
        }
        if ($has($connection, $dbName, 'email_refund_enabled')) {
            $this->addSql('ALTER TABLE users DROP COLUMN email_refund_enabled');
        }
    }
}

