<?php

declare(strict_types=1);

// Hata gösterimi
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Memory ve execution time (milyonlarca kayıt için)
ini_set('memory_limit', '2G'); // 1G -> 2G (ultra!)
ini_set('max_execution_time', '900'); // 600 -> 900 (15 dakika)
ini_set('max_input_time', '900');
set_time_limit(900);

// Türkiye Timezone
date_default_timezone_set('Europe/Istanbul');

use Slim\Factory\AppFactory;
use DI\ContainerBuilder;
use Slim\Factory\ServerRequestCreatorFactory;

require __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Build DI container
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');

if ($_ENV['APP_ENV'] === 'production') {
    $containerBuilder->enableCompilation(__DIR__ . '/../var/cache');
}

$container = $containerBuilder->build();

// Create Slim app
AppFactory::setContainer($container);
$app = AppFactory::create();

// Set base path for URLs (if behind index.php)
$basePath = '';
if (isset($_SERVER['SCRIPT_NAME'])) {
    $scriptName = $_SERVER['SCRIPT_NAME'];
    if (strpos($scriptName, '/index.php') !== false) {
        // Check if rewrite is working
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        if (strpos($requestUri, '/index.php') === 0) {
            $basePath = '/index.php';
        }
    }
}
$app->setBasePath($basePath);

// Get settings
$settings = $container->get('settings');

// Add Body Parsing Middleware (JSON support)
$app->addBodyParsingMiddleware();

// Add Domain Config Middleware (her request'te domain config'i Twig'e yükler)
$app->add(\App\Middlewares\DomainConfigMiddleware::class);

// Add Routing Middleware
$app->addRoutingMiddleware();

// Add Error Middleware
$displayErrorDetails = $settings['app']['debug'] ?? false;
$logErrors = true;
$logErrorDetails = true;

$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, $logErrors, $logErrorDetails);
$errorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorHandler->forceContentType('text/html');

// Register routes
(require __DIR__ . '/../config/routes.php')($app);

// Run app
$serverRequestCreator = ServerRequestCreatorFactory::create();
$request = $serverRequestCreator->createServerRequestFromGlobals();
$app->run($request);

