<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Support\AppErrorLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Slim\Psr7\Response;
use Twig\Environment;

final class PanelErrorHandler
{
    public static function handle(
        ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetails,
        Environment $twig,
        string $logPath
    ): ResponseInterface {
        $status = 500;
        if ($exception instanceof HttpException) {
            $status = $exception->getCode() >= 400 && $exception->getCode() < 600
                ? $exception->getCode()
                : 500;
        }

        $errorId = AppErrorLogger::log($exception, $logPath, [
            'route' => (string) $request->getUri()->getPath(),
        ]);

        $accept = strtolower($request->getHeaderLine('Accept'));
        $wantsJson = str_contains($accept, 'application/json')
            || str_contains(strtolower($request->getHeaderLine('X-Requested-With')), 'xmlhttprequest');

        $response = new Response();
        if ($wantsJson) {
            $payload = [
                'success' => false,
                'error_id' => $errorId,
                'message' => $displayErrorDetails
                    ? $exception->getMessage()
                    : 'Bir hata oluştu. Destek için hata kodunu iletin: ' . $errorId,
            ];
            if ($displayErrorDetails) {
                $payload['exception'] = $exception::class;
                $payload['file'] = $exception->getFile();
                $payload['line'] = $exception->getLine();
            }
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus($status);
        }

        $html = self::renderHtml($twig, $exception, $errorId, $status, $displayErrorDetails, (string) $request->getUri()->getPath());
        $response->getBody()->write($html);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withStatus($status);
    }

    private static function renderHtml(
        Environment $twig,
        \Throwable $exception,
        string $errorId,
        int $status,
        bool $showDetails,
        string $path
    ): string {
        $vars = [
            'error_id' => $errorId,
            'status_code' => $status,
            'path' => $path,
            'show_details' => $showDetails,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'exception_trace' => $showDetails ? $exception->getTraceAsString() : '',
            'log_hint' => 'storage/logs/app-' . date('Y-m-d') . '.log',
        ];

        try {
            if ($status === 404) {
                return $twig->render('errors/404.twig', $vars);
            }
            return $twig->render('errors/500.twig', $vars);
        } catch (\Throwable) {
            return self::fallbackHtml($vars);
        }
    }

    private static function fallbackHtml(array $v): string
    {
        $id = htmlspecialchars((string) ($v['error_id'] ?? ''), ENT_QUOTES, 'UTF-8');
        $msg = htmlspecialchars((string) ($v['exception_message'] ?? 'Bilinmeyen hata'), ENT_QUOTES, 'UTF-8');
        $details = !empty($v['show_details'])
            ? '<pre style="text-align:left;max-height:320px;overflow:auto;font-size:12px;">'
                . htmlspecialchars((string) ($v['exception_trace'] ?? ''), ENT_QUOTES, 'UTF-8')
                . '</pre>'
            : '<p>Detay için sunucuda: <code>tail -100 storage/logs/app-' . date('Y-m-d') . '.log</code></p>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Hata ' . (int) ($v['status_code'] ?? 500) . '</title></head><body style="font-family:sans-serif;padding:40px;text-align:center;">'
            . '<h1>Uygulama hatası</h1><p><strong>Kod:</strong> ' . $id . '</p><p>' . $msg . '</p>' . $details
            . '<p><a href="/">Ana sayfa</a></p></body></html>';
    }
}
