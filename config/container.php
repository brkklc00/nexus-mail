<?php

declare(strict_types=1);

use App\Application\Services\AuditLoggerService;
use App\Application\Services\CreditService;
use App\Application\Services\EmailSmtpService;
use App\Application\Services\EmailSmtpSelector;
use App\Application\Services\AlibabaDirectMailReportService;
use App\Application\Services\TransactionalEmailService;
use App\Application\Services\DomainConfigService;
use App\Infrastructure\Security\PasswordHasher;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\DBAL\DriverManager;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Predis\Client as RedisClient;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;
use Twig\Extension\DebugExtension;
use function DI\factory;
use function DI\get;

$settings = require __DIR__ . '/settings.php';

return array_merge($settings, [
    // Doctrine EntityManager
    EntityManager::class => factory(function (ContainerInterface $c) {
        $settings = $c->get('settings');
        $dbSettings = $settings['database'];
        
        $paths = [__DIR__ . '/../app/Domain/Entities'];
        $isDevMode = true; // Force dev mode for auto proxy generation
        
        $config = ORMSetup::createAttributeMetadataConfiguration(
            $paths,
            $isDevMode,
            __DIR__ . '/../var/cache/doctrine/proxies',
            null,
            false
        );
        
        // localhost kullanıldığında Unix socket sorunu yaşanabilir
        // Bu yüzden 127.0.0.1 kullanarak TCP/IP bağlantısı yapıyoruz
        $host = $dbSettings['host'];
        if ($host === 'localhost') {
            $host = '127.0.0.1';
        }
        
        $connection = DriverManager::getConnection([
            'driver' => $dbSettings['driver'],
            'host' => $host,
            'port' => $dbSettings['port'],
            'dbname' => $dbSettings['dbname'],
            'user' => $dbSettings['user'],
            'password' => $dbSettings['password'],
            'charset' => $dbSettings['charset'],
            'driverOptions' => [
                1002 => "SET SESSION sql_mode=''", // PDO::MYSQL_ATTR_INIT_COMMAND
            ],
        ], $config);
        
        // Register custom ENUM type mapping (Doctrine doesn't support ENUM by default)
        $platform = $connection->getDatabasePlatform();
        $platform->registerDoctrineTypeMapping('enum', 'string');
        
        return new EntityManager($connection, $config);
    }),

    // Logger
    LoggerInterface::class => factory(function (ContainerInterface $c) {
        $settings = $c->get('settings');
        $logger = new Logger('app');
        
        $handler = new StreamHandler(
            $settings['logging']['path'],
            $settings['logging']['level']
        );
        
        $logger->pushHandler($handler);
        
        return $logger;
    }),

    // Redis
    RedisClient::class => factory(function (ContainerInterface $c) {
        $settings = $c->get('settings');
        $redis = $settings['redis'];
        
        $params = [
            'scheme' => 'tcp',
            'host' => $redis['host'],
            'port' => $redis['port'],
            'database' => $redis['database'],
        ];
        
        if (!empty($redis['password'])) {
            $params['password'] = $redis['password'];
        }
        
        return new RedisClient($params);
    }),

    // Cache
    Psr16Cache::class => factory(function (ContainerInterface $c) {
        $redis = $c->get(RedisClient::class);
        $adapter = new RedisAdapter($redis);
        return new Psr16Cache($adapter);
    }),

    // Translator
    Translator::class => factory(function (ContainerInterface $c) {
        $settings = $c->get('settings');
        $translator = new Translator($settings['app']['locale']);
        
        $translator->addLoader('php', new PhpFileLoader());
        
        // Load translations
        $locales = ['tr', 'en'];
        foreach ($locales as $locale) {
            $transFile = __DIR__ . "/../resources/lang/{$locale}/messages.php";
            if (file_exists($transFile)) {
                $translator->addResource('php', $transFile, $locale);
            }
        }
        
        return $translator;
    }),

    // Twig
    TwigEnvironment::class => factory(function (ContainerInterface $c) {
        $settings = $c->get('settings');
        $twigSettings = $settings['twig'];
        
        $loader = new FilesystemLoader($twigSettings['paths']);
        $twig = new TwigEnvironment($loader, [
            'cache' => $twigSettings['cache'],
            'debug' => $twigSettings['debug'],
            'auto_reload' => $twigSettings['auto_reload'],
            'autoescape' => 'html',
        ]);
        
        if ($twigSettings['debug']) {
            $twig->addExtension(new DebugExtension());
        }
        
        // Add global variables
        $twig->addGlobal('app_name', $settings['app']['name']);
        $twig->addGlobal('app_url', $settings['app']['url']);
        
        // NOT: site_title, site_logo, site_favicon, site_default_avatar, site_description
        // artık DomainConfigMiddleware tarafından her request'te dinamik olarak set ediliyor
        
        // Add session as global variable
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $twig->addGlobal('_session', $_SESSION);
        
        return $twig;
    }),

    // Services
    PasswordHasher::class => factory(function () {
        return new PasswordHasher();
    }),

    AuditLoggerService::class => factory(function (ContainerInterface $c) {
        return new AuditLoggerService(
            $c->get(EntityManager::class)
        );
    }),

    CreditService::class => factory(function (ContainerInterface $c) {
        return new CreditService(
            $c->get(EntityManager::class),
            $c->get(AuditLoggerService::class)
        );
    }),

    // Controllers
    \App\Controllers\AuthController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\AuthController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class),
            $c->get(PasswordHasher::class),
            $c->get(AuditLoggerService::class),
            $c->get(DomainConfigService::class)
        );
    }),

    \App\Controllers\DashboardController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\DashboardController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class)
        );
    }),

    \App\Controllers\UserController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\UserController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class),
            $c->get(PasswordHasher::class),
            $c->get(AuditLoggerService::class)
        );
    }),

    \App\Controllers\RoleController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\RoleController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class),
            $c->get(AuditLoggerService::class)
        );
    }),

    \App\Controllers\SupportTicketController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\SupportTicketController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class)
        );
    }),

    \App\Controllers\SettingsController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\SettingsController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class),
            $c->get(PasswordHasher::class),
            $c->get(AuditLoggerService::class)
        );
    }),

    \App\Controllers\NotificationController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\NotificationController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class),
            $c->get(\App\Application\Services\NotificationService::class)
        );
    }),

    \App\Controllers\ApiController::class => factory(function (ContainerInterface $c) {
        $settings = $c->get('settings');
        return new \App\Controllers\ApiController(
            $c->get(EntityManager::class),
            $settings,
            $c->get(\App\Application\Services\EmailSmtpService::class),
            $c->get(\App\Application\Services\EmailSmtpSelector::class),
            $c->get(\App\Application\Services\EmailSendingConfigService::class)
        );
    }),

    // Services
    \App\Application\Services\NotificationService::class => factory(function (ContainerInterface $c) {
        return new \App\Application\Services\NotificationService(
            $c->get(EntityManager::class)
        );
    }),

    // Email Services
    EmailSmtpService::class => factory(function (ContainerInterface $c) {
        return new EmailSmtpService(
            $c->get(EntityManager::class)
        );
    }),

    \App\Application\Services\EmailSendingConfigService::class => factory(function (ContainerInterface $c) {
        return new \App\Application\Services\EmailSendingConfigService(
            $c->get(EntityManager::class)
        );
    }),

    AlibabaDirectMailReportService::class => factory(function (ContainerInterface $c) {
        return new AlibabaDirectMailReportService(
            $c->get(EntityManager::class)
        );
    }),

    EmailSmtpSelector::class => factory(function (ContainerInterface $c) {
        return new EmailSmtpSelector(
            $c->get(EntityManager::class),
            $c->get(\App\Application\Services\EmailSendingConfigService::class)
        );
    }),

    \App\Controllers\EmailSmtpController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\EmailSmtpController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class),
            $c->get(EmailSmtpService::class),
            $c->get(EmailSmtpSelector::class),
            $c->get(\App\Application\Services\EmailSendingConfigService::class),
            $c->get(AlibabaDirectMailReportService::class)
        );
    }),

    \App\Controllers\EmailSendingSettingsController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\EmailSendingSettingsController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class),
            $c->get(\App\Application\Services\EmailSendingConfigService::class),
            $c->get(EmailSmtpSelector::class)
        );
    }),

    \App\Controllers\EmailDashboardController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\EmailDashboardController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class)
        );
    }),

    \App\Controllers\EmailPhoneBookController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\EmailPhoneBookController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class)
        );
    }),

    \App\Controllers\EmailOrderController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\EmailOrderController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class)
        );
    }),

    \App\Controllers\EmailBlacklistController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\EmailBlacklistController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class)
        );
    }),

    \App\Controllers\EmailTransactionController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\EmailTransactionController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class)
        );
    }),

    // Transactional Email Service
    TransactionalEmailService::class => factory(function (ContainerInterface $c) {
        return new TransactionalEmailService(
            $c->get(EntityManager::class),
            $c->get(EmailSmtpSelector::class),
            $c->get(EmailSmtpService::class)
        );
    }),

    \App\Controllers\TransactionalEmailController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\TransactionalEmailController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class),
            $c->get(TransactionalEmailService::class)
        );
    }),

    // Domain Config Service
    DomainConfigService::class => factory(function (ContainerInterface $c) {
        return new DomainConfigService(
            $c->get(EntityManager::class)
        );
    }),

    \App\Controllers\PostmanController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\PostmanController(
            $c->get(DomainConfigService::class)
        );
    }),

    \App\Controllers\Admin\DomainSettingsController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\Admin\DomainSettingsController(
            $c->get(TwigEnvironment::class),
            $c->get(DomainConfigService::class)
        );
    }),

    \App\Controllers\EmailTemplateController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\EmailTemplateController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class)
        );
    }),
    
    \App\Controllers\UrlShortenerController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\UrlShortenerController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class),
            $c->get('settings')
        );
    }),

    \App\Controllers\EmailDataPoolController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\EmailDataPoolController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class)
        );
    }),
    
    // Admin Email Controllers
    \App\Controllers\Admin\EmailOrderController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\Admin\EmailOrderController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class)
        );
    }),

    \App\Controllers\Admin\EmailPhonebookController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\Admin\EmailPhonebookController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class)
        );
    }),

    \App\Controllers\Admin\EmailTransactionController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\Admin\EmailTransactionController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class)
        );
    }),

    \App\Controllers\Admin\EmailBlacklistController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\Admin\EmailBlacklistController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class)
        );
    }),

    \App\Controllers\Admin\EmailTemplateController::class => factory(function (ContainerInterface $c) {
        return new \App\Controllers\Admin\EmailTemplateController(
            $c->get(EntityManager::class),
            $c->get(TwigEnvironment::class)
        );
    }),

    // Middlewares
    \App\Middlewares\AuthMiddleware::class => factory(function (ContainerInterface $c) {
        return new \App\Middlewares\AuthMiddleware(
            $c->get(EntityManager::class)
        );
    }),
]);

