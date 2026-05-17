<?php

declare(strict_types=1);

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

return [
    'driver' => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
    'host' => ($_ENV['DB_HOST'] ?? 'localhost') === 'localhost' ? '127.0.0.1' : ($_ENV['DB_HOST'] ?? 'localhost'),
    'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
    'dbname' => $_ENV['DB_NAME'] ?? 'nexus',
    'user' => $_ENV['DB_USER'] ?? 'root',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
    'charset' => 'utf8mb4',
];
