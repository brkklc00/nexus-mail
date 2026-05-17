<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Services\DomainConfigService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class PostmanController
{
    private DomainConfigService $domainConfigService;

    public function __construct(DomainConfigService $domainConfigService)
    {
        $this->domainConfigService = $domainConfigService;
    }

    /**
     * Domain'e göre dinamik Postman collection oluştur
     * Panel domain'ini kullanarak base URL oluşturur
     */
    private function getBaseUrl(): string
    {
        $host = null;
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
        } elseif (isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
            $host = $_SERVER['SERVER_NAME'];
        } else {
            $host = 'localhost';
        }
        
        // Port numarasını kaldır
        $host = preg_replace('/:\d+$/', '', $host);
        
        // Gerçek sunucu IP'sini kontrol et (localhost mu?)
        $serverAddr = $_SERVER['SERVER_ADDR'] ?? null;
        $isRealLocalhost = in_array($serverAddr, ['127.0.0.1', '::1', 'localhost']) 
            || $serverAddr === '127.0.0.1'
            || (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] === '127.0.0.1');
        
        // Localhost kontrolü - localhost, 127.0.0.1 veya local IP ise domain config'i atla
        $isLocalhost = in_array(strtolower($host), ['localhost', '127.0.0.1', '::1']) 
            || preg_match('/^192\.168\./', $host) 
            || preg_match('/^10\./', $host) 
            || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host)
            || $isRealLocalhost; // Gerçek sunucu localhost ise, host adı ne olursa olsun localhost kabul et
        
        // Localhost değilse domain config'den base_url kontrolü (eğer varsa)
        if (!$isLocalhost) {
            $config = $this->domainConfigService->getDomainConfig($host);
            if ($config && !empty($config['base_url'])) {
                return $config['base_url'];
            }
        } else {
            // Localhost ise, host adını localhost olarak kullan
            $host = 'localhost';
        }
        
        // HTTPS kontrolü
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        
        // Port numarasını ekle (eğer varsa)
        $port = isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : null;
        if ($port && $port !== 80 && $port !== 443) {
            // Standart olmayan port varsa ekle
            if (($protocol === 'http' && $port !== 80) || ($protocol === 'https' && $port !== 443)) {
                return $protocol . '://' . $host . ':' . $port;
            }
        }
        
        return $protocol . '://' . $host;
    }

    /**
     * Site başlığını al
     */
    private function getSiteTitle(): string
    {
        $host = null;
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
        } elseif (isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
            $host = $_SERVER['SERVER_NAME'];
        }
        
        if ($host) {
            $host = preg_replace('/:\d+$/', '', $host);
            $config = $this->domainConfigService->getDomainConfig($host);
            if ($config && !empty($config['site_title'])) {
                return $config['site_title'];
            }
        }
        
        return $_ENV['SITE_TITLE'] ?? 'Nexus Panel';
    }

    /**
     * Transactional Email Collection
     */
    public function transactionalEmailCollection(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $baseUrl = $this->getBaseUrl();
        $siteTitle = $this->getSiteTitle();
        
        $collection = [
            'info' => [
                'name' => $siteTitle . ' - Transactional Email API',
                'description' => 'İşlemsel Email gönderme ve geçmiş API koleksiyonu',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json'
            ],
            'item' => [
                [
                    'name' => 'Email Gönder',
                    'request' => [
                        'method' => 'POST',
                        'header' => [
                            [
                                'key' => 'X-API-Key',
                                'value' => '{{api_key}}',
                                'type' => 'text'
                            ],
                            [
                                'key' => 'Content-Type',
                                'value' => 'application/json',
                                'type' => 'text'
                            ]
                        ],
                        'body' => [
                            'mode' => 'raw',
                            'raw' => "{\n    \"to_email\": \"test@example.com\",\n    \"to_name\": \"Test User\",\n    \"subject\": \"Test Email\",\n    \"body\": \"<h1>Merhaba!<\\/h1><p>Bu bir test emailidir.<\\/p>\",\n    \"from_email\": \"sender@example.com\",\n    \"from_name\": \"Gönderici\"\n}"
                        ],
                        'url' => [
                            'raw' => $baseUrl . '/api/transactional-email/send',
                            'protocol' => parse_url($baseUrl, PHP_URL_SCHEME),
                            'host' => [parse_url($baseUrl, PHP_URL_HOST)],
                            'path' => ['api', 'transactional-email', 'send']
                        ],
                        'description' => 'İşlemsel email gönderir. HTML içerik destekler.'
                    ]
                ],
                [
                    'name' => 'Email Geçmişi',
                    'request' => [
                        'method' => 'GET',
                        'header' => [
                            [
                                'key' => 'X-API-Key',
                                'value' => '{{api_key}}',
                                'type' => 'text'
                            ]
                        ],
                        'url' => [
                            'raw' => $baseUrl . '/api/transactional-email/history?page=1&limit=20&status=all',
                            'protocol' => parse_url($baseUrl, PHP_URL_SCHEME),
                            'host' => [parse_url($baseUrl, PHP_URL_HOST)],
                            'path' => ['api', 'transactional-email', 'history'],
                            'query' => [
                                ['key' => 'page', 'value' => '1'],
                                ['key' => 'limit', 'value' => '20'],
                                ['key' => 'status', 'value' => 'all']
                            ]
                        ]
                    ]
                ]
            ],
            'variable' => [
                [
                    'key' => 'api_key',
                    'value' => '',
                    'type' => 'string'
                ]
            ]
        ];

        $response->getBody()->write(json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'attachment; filename="' . str_replace(' ', '_', $siteTitle) . '_Transactional_Email.postman_collection.json"');
    }

    /**
     * WordPress REST API benzeri endpoint - Tüm API endpoint'lerini listeler
     */
    public function restApiIndex(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $baseUrl = $this->getBaseUrl();
        
        $routes = [
            'name' => 'Nexus Mail REST API',
            'description' => 'Nexus Mail API endpoint listesi',
            'version' => '1.0.0',
            'namespace' => 'nexus/v1',
            'routes' => [
                'transactional_email' => [
                    'send' => [
                        'url' => $baseUrl . '/api/transactional-email/send',
                        'method' => 'POST',
                        'description' => 'İşlemsel email gönderir'
                    ],
                    'history' => [
                        'url' => $baseUrl . '/api/transactional-email/history',
                        'method' => 'GET',
                        'description' => 'İşlemsel email geçmişini getirir'
                    ]
                ],
                'postman_collections' => [
                    'transactional_email' => [
                        'url' => $baseUrl . '/postman/transactional-email',
                        'method' => 'GET',
                        'description' => 'Transactional Email Postman koleksiyonunu indirir'
                    ]
                ]
            ]
        ];

        $response->getBody()->write(json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response
            ->withHeader('Content-Type', 'application/json');
    }
}

