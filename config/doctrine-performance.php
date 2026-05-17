<?php

declare(strict_types=1);

/**
 * Doctrine Performance Optimizations
 * 
 * Milyonlarca kayıt için optimizasyonlar:
 * 1. Batch Processing
 * 2. Lazy Loading
 * 3. Query Result Caching
 * 4. Second Level Cache
 */

return [
    // Batch Processing ayarları
    'batch_size' => 1000, // Her batch'te kaç kayıt işlenecek
    
    // Query Cache (Metadata Cache)
    'query_cache_lifetime' => 3600, // 1 saat
    
    // Result Cache
    'result_cache_lifetime' => 300, // 5 dakika
    
    // Lazy Loading (varsayılan: aktif)
    'lazy_loading' => true,
    
    // Eager Loading strategy
    'eager_loading_strategy' => [
        'User' => ['roles', 'credit'], // User çekerken bunları da çek
        'Order' => ['user'], // Order çekerken user'ı da çek
        'PhoneBook' => [], // PhoneBook çekerken contact'ları ÇEKME (milyonlarca olabilir)
    ],
    
    // Memory optimizations
    'clear_entity_manager_batch' => 100, // Her 100 kayıtta bir EntityManager'ı temizle
];

