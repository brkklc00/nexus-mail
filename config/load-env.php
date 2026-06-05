<?php

declare(strict_types=1);

/**
 * .env dosyasını yükler. Immutable dotenv bazı sunucularda DB_* okuyamaz;
 * mutable yükleme + manuel parse yedek olarak kullanılır.
 */
function nexus_parse_env_file(string $path): array
{
    $vars = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return $vars;
    }

    foreach ($lines as $line) {
        $line = trim(str_replace("\0", '', (string) $line));
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false || $eq === 0) {
            continue;
        }

        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $vars[$key] = $value;
    }

    return $vars;
}

function nexus_env(string $key, ?string $default = null): ?string
{
    if (array_key_exists($key, $_ENV)) {
        $value = $_ENV[$key];
        if ($value !== '' && $value !== null) {
            return (string) $value;
        }
    }

    if (array_key_exists($key, $_SERVER)) {
        $value = $_SERVER[$key];
        if ($value !== '' && $value !== null) {
            return (string) $value;
        }
    }

    $fromGetenv = getenv($key);
    if ($fromGetenv !== false && $fromGetenv !== '') {
        return (string) $fromGetenv;
    }

    return $default;
}

function nexus_ensure_env_loaded(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $root = dirname(__DIR__);
    $envFile = $root . '/.env';
    if (!is_readable($envFile)) {
        return;
    }

    if (file_exists($root . '/vendor/autoload.php')) {
        require_once $root . '/vendor/autoload.php';
    }

    if (class_exists(\Dotenv\Dotenv::class)) {
        try {
            \Dotenv\Dotenv::createMutable($root)->safeLoad();
        } catch (\Throwable) {
            // Manuel parse yedek
        }
    }

    foreach (nexus_parse_env_file($envFile) as $key => $value) {
        if (nexus_env($key) !== null) {
            continue;
        }
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        if (function_exists('putenv')) {
            @putenv($key . '=' . $value);
        }
    }
}
