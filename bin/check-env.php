#!/usr/bin/env php
<?php

/**
 * .env dosyası kontrol script'i
 * .env dosyasının yüklenip yüklenmediğini ve değerleri kontrol eder
 */

require __DIR__ . '/../vendor/autoload.php';

echo "=== .ENV DOSYASI KONTROLÜ ===\n\n";

// .env dosyası var mı?
$envFile = __DIR__ . '/../.env';
if (!file_exists($envFile)) {
    echo "❌ .env dosyası bulunamadı: $envFile\n";
    exit(1);
}

echo "✅ .env dosyası bulundu: $envFile\n\n";

// .env dosyasını yükle
try {
    require_once __DIR__ . '/../config/load-env.php';
    nexus_ensure_env_loaded();
    echo "✅ .env dosyası yüklendi\n\n";
} catch (Exception $e) {
    echo "❌ .env dosyası yüklenemedi: " . $e->getMessage() . "\n";
    exit(1);
}

// Database ayarlarını kontrol et
echo "=== DATABASE AYARLARI (.env) ===\n";
echo "DB_HOST: " . (nexus_env('DB_HOST') ?? 'NULL') . "\n";
echo "DB_PORT: " . (nexus_env('DB_PORT') ?? 'NULL') . "\n";
echo "DB_NAME: " . (nexus_env('DB_NAME') ?? 'NULL') . "\n";
echo "DB_USER: " . (nexus_env('DB_USER') ?? 'NULL') . "\n";
$password = nexus_env('DB_PASSWORD');
if ($password === null || $password === '') {
    echo "DB_PASSWORD: ❌ BOŞ VEYA NULL!\n";
} else {
    echo "DB_PASSWORD: ✅ VAR (uzunluk: " . strlen($password) . " karakter)\n";
}
echo "\n";

// Settings.php'den database ayarlarını kontrol et
echo "=== SETTINGS.PHP DATABASE AYARLARI ===\n";
$settings = require __DIR__ . '/../config/settings.php';
$dbSettings = $settings['settings']['database'] ?? [];
echo "host: " . ($dbSettings['host'] ?? 'NULL') . "\n";
echo "port: " . ($dbSettings['port'] ?? 'NULL') . "\n";
echo "dbname: " . ($dbSettings['dbname'] ?? 'NULL') . "\n";
echo "user: " . ($dbSettings['user'] ?? 'NULL') . "\n";
$settingsPassword = $dbSettings['password'] ?? 'NULL';
if ($settingsPassword === 'NULL' || empty($settingsPassword)) {
    echo "password: ❌ BOŞ VEYA NULL!\n";
} else {
    echo "password: ✅ VAR (uzunluk: " . strlen($settingsPassword) . " karakter)\n";
}
echo "\n";

// Test bağlantısı
if (!empty($dbSettings['password']) && $dbSettings['password'] !== 'NULL') {
    echo "=== VERİTABANI BAĞLANTI TESTİ ===\n";
    try {
        $host = $dbSettings['host'] === 'localhost' ? '127.0.0.1' : $dbSettings['host'];
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $dbSettings['port'],
            $dbSettings['dbname']
        );
        $pdo = new PDO($dsn, $dbSettings['user'], $dbSettings['password']);
        echo "✅ Veritabanı bağlantısı başarılı!\n";
    } catch (PDOException $e) {
        echo "❌ Veritabanı bağlantı hatası: " . $e->getMessage() . "\n";
    }
} else {
    echo "⚠️  Şifre boş olduğu için veritabanı bağlantı testi yapılamadı\n";
}
