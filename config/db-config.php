<?php

declare(strict_types=1);

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

require_once __DIR__ . '/load-env.php';
nexus_ensure_env_loaded();
require_once __DIR__ . '/database-env.php';

return nexus_database_env();
