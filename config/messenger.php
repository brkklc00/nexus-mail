<?php

declare(strict_types=1);

return [
    'transports' => [
        'async' => [
            'dsn' => $_ENV['MESSENGER_TRANSPORT_DSN'] ?? 'redis://127.0.0.1:6379/messages',
            'options' => [
                'stream' => 'messages',
                'group' => 'nexus',
                'consumer' => 'consumer-1',
            ],
        ],
    ],
    'routing' => [],
    'handlers' => [],
];

