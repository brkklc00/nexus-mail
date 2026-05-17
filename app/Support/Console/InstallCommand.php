<?php

declare(strict_types=1);

namespace App\Support\Console;

use App\Domain\Entities\Role;
use App\Domain\Entities\Permission;
use App\Domain\Entities\RolePermission;
use App\Domain\Entities\User;
use App\Infrastructure\Security\PasswordHasher;
use Doctrine\ORM\EntityManager;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class InstallCommand extends Command
{
    protected static $defaultName = 'app:install';
    private ContainerInterface $container;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct();
        $this->container = $container;
    }

    protected function configure(): void
    {
        $this->setDescription('Install Nexus application (migrations, seeds)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Nexus Installation');

        /** @var EntityManager $em */
        $em = $this->container->get(EntityManager::class);
        $passwordHasher = $this->container->get(PasswordHasher::class);

        // 1. Drop and Create schema
        $io->section('Creating database schema...');
        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($em);
        $classes = $em->getMetadataFactory()->getAllMetadata();
        
        // Drop existing schema
        $io->text('Dropping existing tables...');
        $schemaTool->dropSchema($classes);
        
        // Create new schema
        $io->text('Creating new tables...');
        $schemaTool->createSchema($classes);
        $io->success('Database schema created');

        // 2. Create permissions
        $io->section('Creating permissions...');
        $permissionData = [
            // Email Sistemi
            'email_phonebook' => 'Email Rehber Yönetimi (Email rehberi ekle/düzenle/sil)',
            'email_order' => 'Email Sipariş Yönetimi (Email gönderebilme)',
            'email_blacklist' => 'Email Kara Liste Yönetimi',
            'email_transactions' => 'Email İşlem Geçmişi (Kendi işlemlerini görebilme)',
            'email_smtp' => 'Email SMTP Hesap Yönetimi (SMTP hesap ekle/düzenle/sil)',
            'email_template' => 'Email Şablon Yönetimi (Email şablonları yönetme)',
            'email_dashboard' => 'Email Dashboard (Email istatistikleri görüntüleme)',
            'email_data_pool' => 'Email Veri Havuzu Yönetimi (Email veri havuzunu görüntüleme ve yönetme)',
            'transactional_email' => 'İşlemsel Email (API ve Panel ile email gönderme)',
            
            // Genel
            'support_ticket' => 'Destek Talepleri (Talep oluşturma ve görüntüleme)',
            'notification' => 'Bildirim Yönetimi (Kullanıcılara bildirim gönderme)',
            'settings' => 'Sistem Ayarları (Genel ayarları düzenleme)',
            'system_monitor' => 'Sistem İzleme (Sunucu, PM2, CPU, RAM, Disk istatistikleri)',
            'url_shortener' => 'URL Kısaltma (Link kısaltma ve yönetme)',
            
            // Admin Yetkileri
            'user' => 'Kullanıcı Yönetimi (Kullanıcı ekle/düzenle/sil)',
            'role' => 'Rol Yönetimi (Rol ve yetki yönetimi)',
            'admin_email_orders' => 'Admin: Tüm Email Siparişlerini Görüntüleme',
            'admin_email_phonebooks' => 'Admin: Tüm Email Rehberlerini Görüntüleme',
            'admin_email_blacklists' => 'Admin: Tüm Email Kara Listelerini Görüntüleme',
            'admin_email_transactions' => 'Admin: Tüm Email İşlem Geçmişlerini Görüntüleme',
            'admin_email_templates' => 'Admin: Email Şablonlarını Görüntüleme ve Onaylama',
            'admin_email_data_pool' => 'Admin: Email Veri Havuzunu Görüntüleme ve Yönetme',
        ];
        $permissions = [];
        
        foreach ($permissionData as $key => $name) {
            $permission = new Permission();
            $permission->setKey($key);
            $permission->setName($name);
            $em->persist($permission);
            $permissions[$key] = $permission;
        }
        $em->flush();
        $io->success(count($permissionData) . ' permissions created');

        // 3. Create roles
        $io->section('Creating roles...');
        
        // Admin role - tüm yetkiler
        $adminRole = new Role();
        $adminRole->setName('admin');
        $adminRole->setIsReadonly(true);
        
        foreach ($permissions as $permission) {
            $rp = new RolePermission();
            $rp->setRole($adminRole);
            $rp->setPermission($permission);
            $rp->setCanRead(true);
            $rp->setCanCreate(true);
            $rp->setCanUpdate(true);
            $rp->setCanDelete(true);
            $adminRole->addPermission($rp);
        }
        
        $em->persist($adminRole);

        // Consumer role - sınırlı yetkiler (Normal kullanıcı)
        $consumerRole = new Role();
        $consumerRole->setName('consumer');
        $consumerRole->setIsReadonly(true);
        
        // Normal kullanıcı yetkileri (kendi verilerini yönetebilir)
        $consumerPermissions = [
            // Email Yetkileri
            'email_phonebook' => ['read' => true, 'create' => true, 'update' => true, 'delete' => true],
            'email_order' => ['read' => true, 'create' => true, 'update' => false, 'delete' => false],
            'email_blacklist' => ['read' => true, 'create' => true, 'update' => false, 'delete' => true],
            'email_transactions' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false],
            'email_template' => ['read' => true, 'create' => true, 'update' => true, 'delete' => true],
            'email_smtp' => ['read' => true, 'create' => true, 'update' => true, 'delete' => true],
            'email_dashboard' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false],
            'transactional_email' => ['read' => true, 'create' => true, 'update' => false, 'delete' => false],
            
            // Genel Yetkileri
            'support_ticket' => ['read' => true, 'create' => true, 'update' => true, 'delete' => false],
            'notification' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false],
            'settings' => ['read' => true, 'create' => false, 'update' => false, 'delete' => false],
            'url_shortener' => ['read' => true, 'create' => true, 'update' => false, 'delete' => true],
        ];
        
        foreach ($consumerPermissions as $key => $actions) {
            if (isset($permissions[$key])) {
                $rp = new RolePermission();
                $rp->setRole($consumerRole);
                $rp->setPermission($permissions[$key]);
                $rp->setCanRead($actions['read']);
                $rp->setCanCreate($actions['create']);
                $rp->setCanUpdate($actions['update']);
                $rp->setCanDelete($actions['delete']);
                $consumerRole->addPermission($rp);
            }
        }
        
        $em->persist($consumerRole);
        $em->flush();
        $io->success('Roles created: admin, consumer');

        // 4. Create admin user
        $io->section('Creating admin user...');
        
        try {
            $adminUser = new User();
            $adminUser->setName('Admin User');
            $adminUser->setUsername('admin');
            $adminUser->setEmail('admin@nexus.local');
            $adminUser->setPassword($passwordHasher->hash('Admin123!'));
            $adminUser->setIsActive(true);
            $adminUser->setEmailCredit(100000.00); // Email Campaign Credit
            $adminUser->setEmailTransactionalBalance(10000); // Transactional Email Balance
            
            // API Key oluştur
            $adminUser->setApiKey(bin2hex(random_bytes(32)));
            
            $adminUser->addRole($adminRole);
            $em->persist($adminUser);
            $em->flush();
            
            $io->success('Admin user created');
            $io->table(['Field', 'Value'], [
                ['Username', 'admin'],
                ['Password', 'Admin123!'],
                ['Email', 'admin@nexus.local'],
                ['Email Credit', '100,000'],
                ['Transactional Email Balance', '10,000'],
            ]);
        } catch (\Exception $e) {
            $io->error('Admin user creation failed: ' . $e->getMessage());
            $io->text('Stack trace: ' . $e->getTraceAsString());
            return Command::FAILURE;
        }

        // 5. Kurulum tamamlandı

        $io->success('Installation completed successfully!');
        $io->note('You can now login with username: admin and password: Admin123!');

        return Command::SUCCESS;
    }
}

