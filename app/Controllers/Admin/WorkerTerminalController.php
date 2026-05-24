<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Application\Services\AuditLoggerService;
use App\Service\WorkerTerminalService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment as TwigEnvironment;

class WorkerTerminalController
{
    private const CSRF_SESSION_KEY = 'worker_terminal_csrf';

    public function __construct(
        private TwigEnvironment $twig,
        private WorkerTerminalService $workerTerminalService,
        private AuditLoggerService $auditLogger
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        $status = $this->workerTerminalService->getWorkerStatus();
        $html = $this->twig->render('admin/worker-terminal/index.twig', [
            'page_title' => 'Worker Terminali',
            'csrf_token' => $this->getOrCreateCsrfToken(),
            'initial_status' => $status,
        ]);
        $response->getBody()->write($html);
        return $response;
    }

    public function status(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        return $this->json($response, [
            'success' => true,
            'data' => $this->workerTerminalService->getWorkerStatus(),
        ]);
    }

    public function logs(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        $params = $request->getQueryParams();
        $type = (string) ($params['type'] ?? 'combined');
        $lines = (int) ($params['lines'] ?? 150);

        $resolvedType = match ($type) {
            'out' => 'out',
            'error' => 'error',
            default => 'combined',
        };

        $result = $this->workerTerminalService->getLogs($resolvedType, $lines);
        return $this->json($response, $result, $result['success'] ? 200 : 500);
    }

    public function stream(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            $response->getBody()->write('Yetkisiz');
            return $response->withStatus(403);
        }

        $params = $request->getQueryParams();
        $type = (string) ($params['type'] ?? 'combined');
        $resolvedType = match ($type) {
            'out' => 'out',
            'error' => 'error',
            default => 'combined',
        };

        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');
        @set_time_limit(0);

        $response = $response
            ->withHeader('Content-Type', 'text/event-stream')
            ->withHeader('Cache-Control', 'no-cache, no-transform')
            ->withHeader('Connection', 'keep-alive')
            ->withHeader('X-Accel-Buffering', 'no');

        $body = $response->getBody();
        $body->write("retry: 3000\n\n");

        $initial = $this->workerTerminalService->getLogs($resolvedType, 80);
        if (!empty($initial['output'])) {
            $lines = preg_split('/\r\n|\r|\n/', (string) $initial['output']) ?: [];
            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                $this->writeSse($body, 'log', ['line' => $line]);
            }
        }

        try {
            $process = $this->workerTerminalService->createStreamProcess($resolvedType);
            $process->start();
        } catch (\Throwable $e) {
            $this->writeSse($body, 'log', ['line' => 'Stream baslatilamadi: ' . $e->getMessage()]);
            $this->writeSse($body, 'done', ['message' => 'stream_closed']);
            return $response;
        }

        $startedAt = time();
        while ($process->isRunning()) {
            if (connection_aborted()) {
                $process->stop(1);
                break;
            }
            if ((time() - $startedAt) > 55) {
                $process->stop(1);
                break;
            }

            $chunks = [
                $process->getIncrementalOutput(),
                $process->getIncrementalErrorOutput(),
            ];
            foreach ($chunks as $chunk) {
                if ($chunk === '') {
                    continue;
                }
                $masked = $this->workerTerminalService->maskSecrets($chunk);
                $lines = preg_split('/\r\n|\r|\n/', $masked) ?: [];
                foreach ($lines as $line) {
                    if ($line === '') {
                        continue;
                    }
                    $this->writeSse($body, 'log', ['line' => $line]);
                }
            }

            usleep(200000);
        }

        $this->writeSse($body, 'done', ['message' => 'stream_closed']);
        return $response;
    }

    public function action(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya gecersiz token'], 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $action = (string) ($body['action'] ?? '');
        $result = $this->workerTerminalService->runAction($action);
        $this->audit('worker_terminal.action', ['action' => $action, 'success' => $result['success'] ?? false]);

        return $this->json($response, $result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function command(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya gecersiz token'], 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $command = (string) ($body['command'] ?? '');
        $result = $this->workerTerminalService->runSafeCommand($command);
        $this->audit('worker_terminal.command', ['command' => $command, 'success' => $result['success'] ?? false]);

        return $this->json($response, $result, ($result['blocked'] ?? false) ? 422 : (($result['success'] ?? false) ? 200 : 500));
    }

    private function writeSse($body, string $event, array $payload): void
    {
        $body->write('event: ' . $event . "\n");
        $body->write('data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n");
        @ob_flush();
        @flush();
    }

    private function validateAdminAndCsrf(Request $request): bool
    {
        if (!$this->isAdmin()) {
            return false;
        }
        $sessionToken = $_SESSION[self::CSRF_SESSION_KEY] ?? null;
        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $candidate = (string) ($body['_csrf'] ?? $request->getHeaderLine('X-CSRF-Token'));
        if ($candidate === '') {
            return false;
        }
        return hash_equals($sessionToken, $candidate);
    }

    private function isAdmin(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return (bool) ($_SESSION['is_admin'] ?? false);
    }

    private function getOrCreateCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = $_SESSION[self::CSRF_SESSION_KEY] ?? '';
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(24));
            $_SESSION[self::CSRF_SESSION_KEY] = $token;
        }
        return $token;
    }

    private function audit(string $event, array $newValues = []): void
    {
        try {
            $userId = (int) ($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
            $this->auditLogger->log($userId > 0 ? $userId : null, $event, 'WorkerTerminal', null, null, $newValues);
        } catch (\Throwable) {
            // audit hatasi ana islemi bozmasin
        }
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}

