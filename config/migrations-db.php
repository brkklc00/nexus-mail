<?php

declare(strict_types=1);

/**
 * doctrine-migrations --db-configuration
 *
 * MySQL/MariaDB ENUM sütunları: Schema API ($schema->getTable) introspection
 * "Unknown database type enum" hatasını önlemek için enum → string eşlemesi.
 */

use Doctrine\DBAL\DriverManager;

require_once __DIR__ . '/../vendor/autoload.php';

$params = require __DIR__ . '/db-config.php';

$connection = DriverManager::getConnection($params);

try {
    $platform = $connection->getDatabasePlatform();
    $platform->registerDoctrineTypeMapping('enum', 'string');
    $platform->registerDoctrineTypeMapping('set', 'string');
} catch (\Throwable) {
    // Platform henüz hazır değilse veya çift kayıt
}

return $connection;
