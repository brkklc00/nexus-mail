#!/usr/bin/env php
<?php

/**
 * Cache temizleme script'i
 * Twig cache ve diğer cache'leri temizler
 */

echo "=== CACHE TEMİZLEME ===\n\n";

$baseDir = __DIR__ . '/..';

// Twig cache
$twigCacheDir = $baseDir . '/var/cache/twig';
if (is_dir($twigCacheDir)) {
    echo "🗑️  Twig cache temizleniyor: $twigCacheDir\n";
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
    echo "   ✅ $count dosya silindi\n";
} else {
    echo "ℹ️  Twig cache dizini bulunamadı: $twigCacheDir\n";
}

// Doctrine cache
$doctrineCacheDir = $baseDir . '/var/cache/doctrine';
if (is_dir($doctrineCacheDir)) {
    echo "🗑️  Doctrine cache temizleniyor: $doctrineCacheDir\n";
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
    echo "   ✅ $count dosya silindi\n";
} else {
    echo "ℹ️  Doctrine cache dizini bulunamadı: $doctrineCacheDir\n";
}

// Container cache (production mode)
$containerCacheFile = $baseDir . '/var/cache/container.php';
if (file_exists($containerCacheFile)) {
    echo "🗑️  Container cache temizleniyor: $containerCacheFile\n";
    unlink($containerCacheFile);
    echo "   ✅ Container cache silindi\n";
}

// OPcache temizle (eğer aktifse)
if (function_exists('opcache_reset')) {
    echo "🗑️  OPcache temizleniyor...\n";
    if (opcache_reset()) {
        echo "   ✅ OPcache temizlendi\n";
    } else {
        echo "   ⚠️  OPcache temizlenemedi (belki aktif değil)\n";
    }
} else {
    echo "ℹ️  OPcache aktif değil, atlanıyor\n";
}

echo "\n✅ Cache temizleme tamamlandı!\n";
echo "   Şimdi sayfayı yenileyin ve domain config'in çalıştığını kontrol edin.\n";
