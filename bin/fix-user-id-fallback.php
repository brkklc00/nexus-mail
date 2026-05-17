#!/usr/bin/env php
<?php
/**
 * Tüm controller'larda getAttribute('user_id') kullanımlarına session fallback ekler
 */

$baseDir = __DIR__ . '/..';
$controllersDir = $baseDir . '/app/Controllers';

echo "🔍 Controller'larda user ID fallback kontrolü başlatılıyor...\n\n";

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($controllersDir),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}

$fixed = 0;
$total = 0;

foreach ($files as $filePath) {
    $content = file_get_contents($filePath);
    $originalContent = $content;
    
    // getAttribute('user_id') kullanımlarını bul (session fallback olmayanlar)
    $pattern = '/\$userId\s*=\s*\$request->getAttribute\(\'user_id\'\)\s*;/';
    $matches = preg_match_all($pattern, $content);
    
    if ($matches > 0) {
        $total += $matches;
        
        // Düzelt: session fallback ekle
        $content = preg_replace(
            '/\$userId\s*=\s*\$request->getAttribute\(\'user_id\'\)\s*;/',
            '$userId = $request->getAttribute(\'user_id\') ?? $_SESSION[\'user\'][\'id\'] ?? $_SESSION[\'user_id\'] ?? null;',
            $content
        );
        
        // currentUserId için de
        $content = preg_replace(
            '/\$currentUserId\s*=\s*\$request->getAttribute\(\'user_id\'\)\s*;/',
            '$currentUserId = $request->getAttribute(\'user_id\') ?? $_SESSION[\'user\'][\'id\'] ?? $_SESSION[\'user_id\'] ?? null;',
            $content
        );
        
        if ($content !== $originalContent) {
            file_put_contents($filePath, $content);
            $fixed += $matches;
            $relativePath = str_replace($baseDir . '/', '', $filePath);
            echo "✅ {$relativePath} - {$matches} düzeltme yapıldı\n";
        }
    }
}

echo "\n📊 Özet:\n";
echo "   Toplam dosya kontrol edildi: " . count($files) . "\n";
echo "   Toplam düzeltme: {$fixed}\n";
echo "   Toplam kullanım: {$total}\n";

if ($fixed > 0) {
    echo "\n✨ {$fixed} düzeltme başarıyla uygulandı!\n";
} else {
    echo "\n✨ Tüm controller'lar zaten düzeltilmiş!\n";
}
