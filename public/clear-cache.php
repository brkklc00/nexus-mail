<?php
/**
 * HTTP üzerinden cache temizleme endpoint'i
 * Güvenlik: IP kontrolü veya token kontrolü ile korunmalı
 */

// Güvenlik kontrolü - sadece admin kullanıcıları
session_start();
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Yetkisiz erişim']));
}

$baseDir = __DIR__ . '/..';
$cleared = [];

// Twig cache
$twigCacheDir = $baseDir . '/var/cache/twig';
if (is_dir($twigCacheDir)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($twigCacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    $count = 0;
    foreach ($files as $fileinfo) {
        if ($fileinfo->isFile()) {
            unlink($fileinfo->getRealPath());
            $count++;
        } elseif ($fileinfo->isDir()) {
            rmdir($fileinfo->getRealPath());
        }
    }
    $cleared['twig'] = $count;
}

// Doctrine cache
$doctrineCacheDir = $baseDir . '/var/cache/doctrine';
if (is_dir($doctrineCacheDir)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($doctrineCacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    $count = 0;
    foreach ($files as $fileinfo) {
        if ($fileinfo->isFile()) {
            unlink($fileinfo->getRealPath());
            $count++;
        } elseif ($fileinfo->isDir()) {
            rmdir($fileinfo->getRealPath());
        }
    }
    $cleared['doctrine'] = $count;
}

// Container cache
$containerCacheFile = $baseDir . '/var/cache/container.php';
if (file_exists($containerCacheFile)) {
    unlink($containerCacheFile);
    $cleared['container'] = true;
}

// OPcache temizle
if (function_exists('opcache_reset')) {
    opcache_reset();
    $cleared['opcache'] = true;
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'Cache başarıyla temizlendi',
    'cleared' => $cleared,
    'timestamp' => date('Y-m-d H:i:s')
]);
