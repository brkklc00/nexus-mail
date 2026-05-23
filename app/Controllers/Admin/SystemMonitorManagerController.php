<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Application\Services\AuditLoggerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment as TwigEnvironment;

class SystemMonitorManagerController
{
    private const CSRF_SESSION_KEY = 'system_monitor_csrf';
    private const MAX_TEXT_READ_BYTES = 1048576; // 1 MB
    private const MAX_TAIL_LINES = 500;
    private const MAX_FILE_UPLOAD_BYTES = 20971520; // 20 MB
    private const LOG_ALLOWED_EXT = ['log', 'txt'];

    public function __construct(
        private TwigEnvironment $twig,
        private EntityManagerInterface $em,
        private AuditLoggerService $auditLogger
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        $html = $this->twig->render('admin/system-monitor/index.twig', [
            'page_title' => 'Sunucu Yönetimi',
            'csrf_token' => $this->getOrCreateCsrfToken(),
            'maintenance_mode' => $this->isMaintenanceModeEnabled(),
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    public function stats(Request $request, Response $response): Response
    {
        return $this->metrics($request, $response);
    }

    public function metrics(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        $payload = [
            'success' => true,
            'timestamp' => date('d.m.Y H:i:s'),
            'maintenance_mode' => $this->isMaintenanceModeEnabled(),
            'server' => $this->getServerStats(),
            'database' => $this->getDatabaseStats(),
            'disk' => $this->getDiskStats(),
            'network' => $this->getNetworkStats(),
            'system' => $this->getSystemInfoGrid(),
        ];

        return $this->json($response, $payload);
    }

    public function logs(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        return $this->json($response, [
            'success' => true,
            'items' => $this->collectLogFiles(),
        ]);
    }

    public function logTail(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        $name = (string) ($args['name'] ?? '');
        $lines = (int) (($request->getQueryParams()['lines'] ?? 100));
        $lines = max(50, min(self::MAX_TAIL_LINES, $lines));

        $log = $this->resolveAllowedLogByName($name);
        if ($log === null) {
            return $this->json($response, ['success' => false, 'message' => 'Log bulunamadı'], 404);
        }

        $content = $this->tailFile($log['full_path'], $lines);

        return $this->json($response, [
            'success' => true,
            'name' => $log['name'],
            'lines' => $lines,
            'content' => $content,
        ]);
    }

    public function downloadLog(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        $name = (string) ($args['name'] ?? '');
        $log = $this->resolveAllowedLogByName($name);
        if ($log === null || !is_file($log['full_path'])) {
            return $this->json($response, ['success' => false, 'message' => 'Log bulunamadı'], 404);
        }

        $this->auditAction('system_monitor.log.download', ['log' => $log['name']]);
        $response->getBody()->write((string) file_get_contents($log['full_path']));

        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($log['full_path']) . '"');
    }

    public function clearLog(Request $request, Response $response, array $args): Response
    {
        if (!$this->validateAdminAndCsrf($request, $response)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz token'], 403);
        }

        $name = (string) ($args['name'] ?? '');
        $log = $this->resolveAllowedLogByName($name);
        if ($log === null || !is_file($log['full_path'])) {
            return $this->json($response, ['success' => false, 'message' => 'Log bulunamadı'], 404);
        }

        file_put_contents($log['full_path'], '');
        $this->auditAction('system_monitor.log.clear', ['log' => $log['name']]);

        return $this->json($response, ['success' => true, 'message' => 'Log temizlendi']);
    }

    public function clearAllLogsAction(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request, $response)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz token'], 403);
        }

        $logs = $this->collectLogFiles();
        foreach ($logs as $log) {
            if (!empty($log['full_path']) && is_file($log['full_path'])) {
                file_put_contents($log['full_path'], '');
            }
        }

        $this->auditAction('system_monitor.log.clear_all', ['count' => count($logs)]);
        return $this->json($response, ['success' => true, 'message' => 'Tüm loglar temizlendi']);
    }

    public function clearLogs(Request $request, Response $response): Response
    {
        $data = (array) ($request->getParsedBody() ?? []);
        $type = (string) ($data['type'] ?? 'all');
        $worker = (string) ($data['worker'] ?? '');

        if ($type === 'worker' && $worker !== '') {
            return $this->clearLogByResolvedWorker($response, $worker, $request);
        }
        if ($type === 'app') {
            return $this->clearAppLogs($request, $response);
        }
        if ($type === 'all') {
            return $this->clearAllLogsAction($request, $response);
        }

        return $this->json($response, ['success' => false, 'message' => 'Geçersiz log tipi'], 422);
    }

    public function viewWorkerLog(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        $worker = (string) ($args['worker'] ?? '');
        $workerLogs = array_values(array_filter($this->collectLogFiles(), function (array $item) use ($worker): bool {
            return str_starts_with($item['name'], $worker . '-');
        }));

        $logs = [];
        foreach ($workerLogs as $item) {
            $logs[$item['name']] = $this->tailFile($item['full_path'], 200);
        }

        return $this->json($response, [
            'success' => true,
            'worker' => $worker,
            'logs' => $logs,
        ]);
    }

    public function downloadWorkerLog(Request $request, Response $response, array $args): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        $worker = (string) ($args['worker'] ?? '');
        $candidate = null;
        foreach ($this->collectLogFiles() as $item) {
            if (str_starts_with($item['name'], $worker . '-')) {
                $candidate = $item;
                break;
            }
        }
        if ($candidate === null) {
            return $this->json($response, ['success' => false, 'message' => 'Worker logu bulunamadı'], 404);
        }

        $response->getBody()->write((string) file_get_contents($candidate['full_path']));
        return $response
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($candidate['full_path']) . '"');
    }

    public function clearAppLogs(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request, $response)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz token'], 403);
        }

        foreach ($this->collectLogFiles() as $log) {
            if (($log['group'] ?? '') === 'application' && is_file($log['full_path'])) {
                file_put_contents($log['full_path'], '');
            }
        }

        $this->auditAction('system_monitor.log.clear_app');
        return $this->json($response, ['success' => true, 'message' => 'Uygulama logları temizlendi']);
    }

    public function systemCheck(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        $disk = $this->getDiskStats();
        $db = $this->getDatabaseStats();
        $server = $this->getServerStats();

        $status = 'ok';
        if (($disk['usage_percent'] ?? 0) >= 90 || ($server['memory']['usage_percent'] ?? 0) >= 90) {
            $status = 'critical';
        } elseif (($disk['usage_percent'] ?? 0) >= 75 || ($server['memory']['usage_percent'] ?? 0) >= 75) {
            $status = 'warning';
        }

        return $this->json($response, [
            'success' => true,
            'status' => $status,
            'checked_at' => date('d.m.Y H:i:s'),
            'disk_usage_percent' => $disk['usage_percent'] ?? 0,
            'memory_usage_percent' => $server['memory']['usage_percent'] ?? 0,
            'db_size' => $db['size'] ?? '0 B',
        ]);
    }

    public function maintenanceStatus(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        return $this->json($response, [
            'success' => true,
            'enabled' => $this->isMaintenanceModeEnabled(),
        ]);
    }

    public function toggleMaintenance(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request, $response)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz token'], 403);
        }

        $enabled = !$this->isMaintenanceModeEnabled();
        $flagPath = $this->projectRoot() . '/storage/maintenance.flag';

        if ($enabled) {
            file_put_contents($flagPath, 'enabled:' . date('c'));
        } else {
            @unlink($flagPath);
        }

        $this->auditAction('system_monitor.maintenance.toggle', ['enabled' => $enabled]);

        return $this->json($response, [
            'success' => true,
            'enabled' => $enabled,
            'message' => $enabled ? 'Bakım modu açıldı' : 'Bakım modu kapatıldı',
        ]);
    }

    public function createDatabaseBackup(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request, $response)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz token'], 403);
        }

        try {
            $backupDir = $this->projectRoot() . '/storage/backups/database';
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $conn = $this->em->getConnection();
            $params = $conn->getParams();
            $dbName = (string) ($params['dbname'] ?? '');
            $dbHost = (string) ($params['host'] ?? 'localhost');
            $dbUser = (string) ($params['user'] ?? '');
            $dbPass = (string) ($params['password'] ?? '');
            $fileName = 'db-backup-' . date('Y-m-d-His') . '.sql.gz';
            $filePath = $backupDir . '/' . $fileName;

            $mysqldump = trim((string) shell_exec('which mysqldump 2>/dev/null'));
            if ($mysqldump === '') {
                throw new \RuntimeException('mysqldump bulunamadı');
            }

            $cmd = sprintf(
                '%s -h%s -u%s -p%s %s | gzip > %s 2>&1',
                escapeshellcmd($mysqldump),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName),
                escapeshellarg($filePath)
            );
            exec($cmd, $out, $code);
            if ($code !== 0 || !is_file($filePath) || filesize($filePath) === 0) {
                throw new \RuntimeException('Veritabanı yedekleme başarısız');
            }

            $this->auditAction('system_monitor.backup.database.create', ['file' => $fileName]);

            return $this->json($response, [
                'success' => true,
                'message' => 'Veritabanı yedeği oluşturuldu',
                'file' => $fileName,
                'size' => $this->formatBytes((int) filesize($filePath)),
            ]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function createFileBackup(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request, $response)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz token'], 403);
        }

        try {
            $backupDir = $this->projectRoot() . '/storage/backups/files';
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $fileName = 'files-backup-' . date('Y-m-d-His') . '.tar.gz';
            $filePath = $backupDir . '/' . $fileName;
            $root = $this->projectRoot();
            $cmd = sprintf(
                'cd %s && tar -czf %s --exclude=%s --exclude=%s --exclude=%s %s 2>&1',
                escapeshellarg($root),
                escapeshellarg($filePath),
                escapeshellarg('storage/logs'),
                escapeshellarg('storage/backups'),
                escapeshellarg('node_modules'),
                escapeshellarg('storage/uploads')
            );

            exec($cmd, $out, $code);
            if ($code !== 0 || !is_file($filePath) || filesize($filePath) === 0) {
                throw new \RuntimeException('Dosya yedekleme başarısız');
            }

            $this->auditAction('system_monitor.backup.files.create', ['file' => $fileName]);

            return $this->json($response, [
                'success' => true,
                'message' => 'Dosya yedeği oluşturuldu',
                'file' => $fileName,
                'size' => $this->formatBytes((int) filesize($filePath)),
            ]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function listBackups(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        $root = $this->projectRoot() . '/storage/backups';
        $db = $this->scanBackupDir($root . '/database', 'database');
        $files = $this->scanBackupDir($root . '/files', 'files');

        return $this->json($response, [
            'success' => true,
            'backups' => [
                'database' => $db,
                'files' => $files,
            ],
        ]);
    }

    public function downloadBackup(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        $params = $request->getQueryParams();
        $type = (string) ($params['type'] ?? '');
        $file = basename((string) ($params['file'] ?? ''));
        $full = $this->projectRoot() . '/storage/backups/' . $type . '/' . $file;
        if (!is_file($full)) {
            return $this->json($response, ['success' => false, 'message' => 'Yedek dosyası bulunamadı'], 404);
        }

        $this->auditAction('system_monitor.backup.download', ['file' => $file, 'type' => $type]);
        $response->getBody()->write((string) file_get_contents($full));
        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file . '"');
    }

    public function restoreDatabaseBackup(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request, $response)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz token'], 403);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        $file = basename((string) ($data['file'] ?? ''));
        $path = $this->projectRoot() . '/storage/backups/database/' . $file;
        if (!is_file($path)) {
            return $this->json($response, ['success' => false, 'message' => 'Yedek bulunamadı'], 404);
        }

        try {
            $conn = $this->em->getConnection();
            $params = $conn->getParams();
            $dbName = (string) ($params['dbname'] ?? '');
            $dbHost = (string) ($params['host'] ?? 'localhost');
            $dbUser = (string) ($params['user'] ?? '');
            $dbPass = (string) ($params['password'] ?? '');
            $mysql = trim((string) shell_exec('which mysql 2>/dev/null'));
            if ($mysql === '') {
                throw new \RuntimeException('mysql komutu bulunamadı');
            }

            $cmd = sprintf(
                'gunzip < %s | %s -h%s -u%s -p%s %s 2>&1',
                escapeshellarg($path),
                escapeshellcmd($mysql),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName)
            );
            exec($cmd, $out, $code);
            if ($code !== 0) {
                throw new \RuntimeException('Geri yükleme başarısız');
            }

            $this->auditAction('system_monitor.backup.database.restore', ['file' => $file]);
            return $this->json($response, ['success' => true, 'message' => 'Veritabanı geri yüklendi']);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function deleteBackup(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request, $response)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz token'], 403);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        $type = (string) ($data['type'] ?? '');
        $file = basename((string) ($data['file'] ?? ''));
        $path = $this->projectRoot() . '/storage/backups/' . $type . '/' . $file;
        if (!is_file($path)) {
            return $this->json($response, ['success' => false, 'message' => 'Yedek dosyası bulunamadı'], 404);
        }

        unlink($path);
        $this->auditAction('system_monitor.backup.delete', ['file' => $file, 'type' => $type]);
        return $this->json($response, ['success' => true, 'message' => 'Yedek silindi']);
    }

    public function listFiles(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        $params = $request->getQueryParams();
        $path = (string) ($params['path'] ?? '');
        $query = mb_strtolower(trim((string) ($params['q'] ?? '')));
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(10, min(200, (int) ($params['per_page'] ?? 60)));

        $dirPath = $this->resolveProjectPath($path, true);
        if ($dirPath === null || !is_dir($dirPath)) {
            return $this->json($response, ['success' => false, 'message' => 'Geçersiz dizin'], 422);
        }

        $items = [];
        $files = @scandir($dirPath) ?: [];
        foreach ($files as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $dirPath . DIRECTORY_SEPARATOR . $name;
            $isDir = is_dir($full);
            $ext = $isDir ? '' : strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
            $relative = ltrim(str_replace($this->projectRoot(), '', $full), DIRECTORY_SEPARATOR);

            if ($query !== '' && !str_contains(mb_strtolower($name), $query)) {
                continue;
            }

            $typeBadge = $isDir ? 'folder' : $this->detectFileTypeBadge($relative, $ext);
            $items[] = [
                'name' => $name,
                'path' => $relative,
                'is_dir' => $isDir,
                'size' => $isDir ? 0 : (int) (@filesize($full) ?: 0),
                'size_formatted' => $isDir ? '-' : $this->formatBytes((int) (@filesize($full) ?: 0)),
                'modified' => date('d.m.Y H:i', (int) (@filemtime($full) ?: time())),
                'extension' => $ext,
                'type_badge' => $typeBadge,
                'is_sensitive' => $this->isSensitiveFile($relative),
            ];
        }

        usort($items, static function (array $a, array $b): int {
            if ($a['is_dir'] !== $b['is_dir']) {
                return $a['is_dir'] ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $paged = array_slice($items, $offset, $perPage);

        return $this->json($response, [
            'success' => true,
            'current_path' => trim($path, '/'),
            'items' => $paged,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) max(1, ceil($total / $perPage)),
            ],
        ]);
    }

    public function readFile(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        $path = (string) (($request->getQueryParams()['path'] ?? ''));
        $full = $this->resolveProjectPath($path, false);
        if ($full === null || !is_file($full)) {
            return $this->json($response, ['success' => false, 'message' => 'Dosya bulunamadı'], 404);
        }

        $size = (int) (@filesize($full) ?: 0);
        if ($size > self::MAX_TEXT_READ_BYTES) {
            return $this->json($response, ['success' => false, 'message' => 'Dosya çok büyük. Maksimum 1 MB görüntülenebilir.'], 422);
        }

        $content = (string) file_get_contents($full);
        if ($this->looksBinary($content)) {
            return $this->json($response, ['success' => false, 'message' => 'Binary dosyalar düzenlenemez.'], 422);
        }

        if ($this->isSensitiveFile($path)) {
            $content = $this->maskSensitiveContent($path, $content);
        }

        return $this->json($response, [
            'success' => true,
            'path' => trim($path, '/'),
            'content' => $content,
            'size' => $this->formatBytes($size),
            'modified' => date('d.m.Y H:i', (int) (@filemtime($full) ?: time())),
            'is_sensitive' => $this->isSensitiveFile($path),
        ]);
    }

    public function downloadFile(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz'], 403);
        }

        $path = (string) (($request->getQueryParams()['path'] ?? ''));
        $full = $this->resolveProjectPath($path, false);
        if ($full === null || !is_file($full)) {
            return $this->json($response, ['success' => false, 'message' => 'Dosya bulunamadı'], 404);
        }
        if ($this->isSensitiveFile($path)) {
            return $this->json($response, ['success' => false, 'message' => 'Hassas dosya indirilemez'], 403);
        }

        $this->auditAction('system_monitor.file.download', ['path' => trim($path, '/')]);
        $response->getBody()->write((string) file_get_contents($full));
        return $response
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($full) . '"');
    }

    public function saveFile(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request, $response)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz token'], 403);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        $path = (string) ($data['path'] ?? '');
        $content = (string) ($data['content'] ?? '');
        $backupBeforeSave = (string) ($data['backup_before_save'] ?? '1') === '1';

        $full = $this->resolveProjectPath($path, false);
        if ($full === null || !is_file($full)) {
            return $this->json($response, ['success' => false, 'message' => 'Dosya bulunamadı'], 404);
        }
        if ($this->looksBinary((string) file_get_contents($full))) {
            return $this->json($response, ['success' => false, 'message' => 'Binary dosya düzenlenemez'], 422);
        }

        if ($backupBeforeSave) {
            $backupFile = $full . '.backup.' . date('YmdHis');
            @copy($full, $backupFile);
        }

        file_put_contents($full, $content);
        $this->auditAction('system_monitor.file.save', ['path' => trim($path, '/')]);
        return $this->json($response, ['success' => true, 'message' => 'Dosya kaydedildi']);
    }

    public function createFile(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request, $response)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz token'], 403);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        $basePath = (string) ($data['path'] ?? '');
        $name = trim((string) ($data['name'] ?? ''));
        $type = (string) ($data['type'] ?? 'file');
        if ($name === '' || preg_match('/[\/\\\\]/', $name)) {
            return $this->json($response, ['success' => false, 'message' => 'Geçersiz ad'], 422);
        }

        $base = $this->resolveProjectPath($basePath, true);
        if ($base === null || !is_dir($base)) {
            return $this->json($response, ['success' => false, 'message' => 'Geçersiz dizin'], 422);
        }

        $target = $base . DIRECTORY_SEPARATOR . $name;
        if (file_exists($target)) {
            return $this->json($response, ['success' => false, 'message' => 'Dosya/klasör zaten var'], 409);
        }

        if ($type === 'dir') {
            mkdir($target, 0755, true);
        } else {
            file_put_contents($target, '');
        }

        $this->auditAction('system_monitor.file.create', ['path' => trim($basePath . '/' . $name, '/'), 'type' => $type]);
        return $this->json($response, ['success' => true, 'message' => 'Oluşturuldu']);
    }

    public function uploadFile(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request, $response)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz token'], 403);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        $path = (string) ($data['path'] ?? '');
        $base = $this->resolveProjectPath($path, true);
        if ($base === null || !is_dir($base)) {
            return $this->json($response, ['success' => false, 'message' => 'Geçersiz dizin'], 422);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['upload'] ?? null;
        if ($file === null) {
            return $this->json($response, ['success' => false, 'message' => 'Dosya bulunamadı'], 422);
        }

        $size = $file->getSize() ?? 0;
        if ($size > self::MAX_FILE_UPLOAD_BYTES) {
            return $this->json($response, ['success' => false, 'message' => 'Dosya çok büyük (max 20 MB)'], 422);
        }

        $filename = basename((string) $file->getClientFilename());
        if ($filename === '') {
            return $this->json($response, ['success' => false, 'message' => 'Geçersiz dosya adı'], 422);
        }

        $target = $base . DIRECTORY_SEPARATOR . $filename;
        $file->moveTo($target);

        $this->auditAction('system_monitor.file.upload', ['path' => trim($path . '/' . $filename, '/'), 'size' => $size]);
        return $this->json($response, ['success' => true, 'message' => 'Dosya yüklendi']);
    }

    public function deleteFile(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request, $response)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz token'], 403);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        $path = (string) ($data['path'] ?? '');
        $full = $this->resolveProjectPath($path, false);
        if ($full === null || !file_exists($full)) {
            return $this->json($response, ['success' => false, 'message' => 'Dosya/klasör bulunamadı'], 404);
        }

        if (is_dir($full)) {
            $this->deleteDirectory($full);
        } else {
            @unlink($full);
        }

        $this->auditAction('system_monitor.file.delete', ['path' => trim($path, '/')]);
        return $this->json($response, ['success' => true, 'message' => 'Silindi']);
    }

    public function renameFile(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request, $response)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz token'], 403);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        $path = (string) ($data['path'] ?? '');
        $newName = trim((string) ($data['new_name'] ?? ''));
        if ($newName === '' || preg_match('/[\/\\\\]/', $newName)) {
            return $this->json($response, ['success' => false, 'message' => 'Geçersiz yeni ad'], 422);
        }

        $full = $this->resolveProjectPath($path, false);
        if ($full === null || !file_exists($full)) {
            return $this->json($response, ['success' => false, 'message' => 'Kaynak bulunamadı'], 404);
        }

        $target = dirname($full) . DIRECTORY_SEPARATOR . $newName;
        if (file_exists($target)) {
            return $this->json($response, ['success' => false, 'message' => 'Hedef ad zaten mevcut'], 409);
        }

        rename($full, $target);
        $this->auditAction('system_monitor.file.rename', ['from' => trim($path, '/'), 'to' => trim(dirname($path) . '/' . $newName, '/')]);
        return $this->json($response, ['success' => true, 'message' => 'Yeniden adlandırıldı']);
    }

    private function clearLogByResolvedWorker(Response $response, string $worker, Request $request): Response
    {
        if (!$this->validateAdminAndCsrf($request, $response)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz token'], 403);
        }

        $found = false;
        foreach ($this->collectLogFiles() as $item) {
            if (str_starts_with($item['name'], $worker . '-') && is_file($item['full_path'])) {
                file_put_contents($item['full_path'], '');
                $found = true;
            }
        }

        if (!$found) {
            return $this->json($response, ['success' => false, 'message' => 'Worker logu bulunamadı'], 404);
        }

        $this->auditAction('system_monitor.log.clear_worker', ['worker' => $worker]);
        return $this->json($response, ['success' => true, 'message' => $worker . ' logları temizlendi']);
    }

    private function collectLogFiles(): array
    {
        $root = $this->projectRoot();
        $sources = [
            'app' => $root . '/storage/logs',
            'worker' => $root . '/email-worker/logs',
        ];

        $items = [];
        foreach ($sources as $group => $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = glob($dir . '/*.*') ?: [];
            foreach ($files as $full) {
                if (!is_file($full)) {
                    continue;
                }
                $ext = strtolower((string) pathinfo($full, PATHINFO_EXTENSION));
                if (!in_array($ext, self::LOG_ALLOWED_EXT, true)) {
                    continue;
                }
                $name = basename($full);
                $safeName = $group . '-' . $name;
                $items[] = [
                    'name' => $safeName,
                    'label' => $name,
                    'group' => $group === 'app' ? 'application' : 'worker',
                    'size' => $this->formatBytes((int) filesize($full)),
                    'size_bytes' => (int) filesize($full),
                    'modified' => date('d.m.Y H:i', (int) filemtime($full)),
                    'full_path' => $full,
                ];
            }
        }

        usort($items, static fn(array $a, array $b): int => $b['size_bytes'] <=> $a['size_bytes']);
        return $items;
    }

    private function resolveAllowedLogByName(string $name): ?array
    {
        foreach ($this->collectLogFiles() as $item) {
            if ($item['name'] === $name) {
                return $item;
            }
        }
        return null;
    }

    private function getServerStats(): array
    {
        $cpuLoad = sys_getloadavg() ?: [0, 0, 0];
        $cores = $this->getCPUCount();
        $cpuUsage = $cores > 0 ? min(100, max(0, ($cpuLoad[0] / $cores) * 100)) : 0;
        $memory = $this->getMemoryInfo();
        $uptime = $this->getUptime();

        return [
            'cpu' => [
                'usage' => round($cpuUsage, 2),
                'cores' => $cores,
                'load' => [
                    '1min' => round((float) $cpuLoad[0], 2),
                    '5min' => round((float) $cpuLoad[1], 2),
                    '15min' => round((float) $cpuLoad[2], 2),
                ],
            ],
            'memory' => $memory,
            'uptime' => $uptime,
        ];
    }

    private function getDatabaseStats(): array
    {
        try {
            $conn = $this->em->getConnection();
            $params = $conn->getParams();
            $dbName = (string) ($params['dbname'] ?? '');

            $stmt = $conn->prepare('SELECT SUM(data_length + index_length) as size, COUNT(*) as table_count FROM information_schema.TABLES WHERE table_schema = ?');
            $row = $stmt->executeQuery([$dbName])->fetchAssociative() ?: ['size' => 0, 'table_count' => 0];

            $connStmt = $conn->prepare("SHOW STATUS LIKE 'Threads_connected'");
            $connRow = $connStmt->executeQuery()->fetchAssociative() ?: ['Value' => 0];

            return [
                'size' => $this->formatBytes((int) ($row['size'] ?? 0)),
                'size_bytes' => (int) ($row['size'] ?? 0),
                'tables' => (int) ($row['table_count'] ?? 0),
                'connections' => (int) ($connRow['Value'] ?? 0),
            ];
        } catch (\Throwable) {
            return ['size' => '0 B', 'size_bytes' => 0, 'tables' => 0, 'connections' => 0];
        }
    }

    private function getDiskStats(): array
    {
        $total = (float) @disk_total_space($this->projectRoot());
        $free = (float) @disk_free_space($this->projectRoot());
        if ($total <= 0) {
            return [
                'total' => '0 B',
                'used' => '0 B',
                'free' => '0 B',
                'usage_percent' => 0,
                'io' => ['reads' => 0, 'writes' => 0],
            ];
        }
        $used = $total - $free;
        $percent = $total > 0 ? ($used / $total) * 100 : 0;

        return [
            'total' => $this->formatBytes((int) $total),
            'used' => $this->formatBytes((int) $used),
            'free' => $this->formatBytes((int) $free),
            'usage_percent' => round($percent, 2),
            'io' => $this->getDiskIO(),
        ];
    }

    private function getNetworkStats(): array
    {
        $result = [];
        if (!file_exists('/proc/net/dev')) {
            return $result;
        }

        $lines = explode("\n", (string) file_get_contents('/proc/net/dev'));
        foreach ($lines as $line) {
            if (!preg_match('/^\s*([\w\.\-]+):\s*(\d+).+\s(\d+)\s*$/', $line, $m)) {
                continue;
            }
            $name = $m[1];
            if ($name === 'lo') {
                continue;
            }
            $rx = (int) $m[2];
            $tx = (int) $m[3];
            $result[] = [
                'interface' => $name,
                'rx_bytes' => $rx,
                'tx_bytes' => $tx,
                'rx_human' => $this->formatBytes($rx),
                'tx_human' => $this->formatBytes($tx),
                'updated_at' => date('d.m.Y H:i:s'),
            ];
        }
        return $result;
    }

    private function getSystemInfoGrid(): array
    {
        $phpVersion = PHP_VERSION;
        $nodeVersion = trim((string) shell_exec('node -v 2>/dev/null')) ?: 'N/A';
        $uptime = $this->getUptime()['formatted'] ?? 'N/A';
        $load = sys_getloadavg() ?: [0, 0, 0];

        return [
            ['label' => 'Sunucu Uptime', 'value' => $uptime],
            ['label' => 'Load Average', 'value' => round((float) $load[0], 2) . ' / ' . round((float) $load[1], 2) . ' / ' . round((float) $load[2], 2)],
            ['label' => 'PHP Versiyonu', 'value' => $phpVersion],
            ['label' => 'Node Versiyonu', 'value' => $nodeVersion],
            ['label' => 'DB Bağlantı', 'value' => (string) ($this->getDatabaseStats()['connections'] ?? 0)],
            ['label' => 'Disk Read / Write', 'value' => ($this->getDiskIO()['reads'] ?? 0) . ' / ' . ($this->getDiskIO()['writes'] ?? 0)],
            ['label' => 'Worker', 'value' => $this->getWorkerStatusLabel()],
            ['label' => 'Son Yenileme', 'value' => date('d.m.Y H:i:s')],
        ];
    }

    private function getWorkerStatusLabel(): string
    {
        $output = trim((string) shell_exec('pm2 jlist 2>/dev/null'));
        if ($output === '') {
            return 'N/A';
        }
        $arr = json_decode($output, true);
        if (!is_array($arr) || $arr === []) {
            return 'N/A';
        }
        $online = 0;
        foreach ($arr as $proc) {
            if (($proc['pm2_env']['status'] ?? '') === 'online') {
                ++$online;
            }
        }
        return $online . '/' . count($arr) . ' online';
    }

    private function getDiskIO(): array
    {
        if (!file_exists('/proc/diskstats')) {
            return ['reads' => 0, 'writes' => 0];
        }
        $reads = 0;
        $writes = 0;
        $lines = explode("\n", (string) file_get_contents('/proc/diskstats'));
        foreach ($lines as $line) {
            if (preg_match('/^\s*\d+\s+\d+\s+(\w+)\s+(\d+)\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $m)) {
                if (str_starts_with($m[1], 'loop') || str_starts_with($m[1], 'ram')) {
                    continue;
                }
                $reads += (int) $m[2];
                $writes += (int) $m[3];
            }
        }
        return ['reads' => $reads, 'writes' => $writes];
    }

    private function getCPUCount(): int
    {
        if (!file_exists('/proc/cpuinfo')) {
            return 1;
        }
        preg_match_all('/^processor/m', (string) file_get_contents('/proc/cpuinfo'), $m);
        return max(1, count($m[0]));
    }

    private function getMemoryInfo(): array
    {
        if (file_exists('/proc/meminfo')) {
            $content = (string) file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $content, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $content, $available);
            $totalBytes = ((int) ($total[1] ?? 0)) * 1024;
            $availableBytes = ((int) ($available[1] ?? 0)) * 1024;
            $usedBytes = max(0, $totalBytes - $availableBytes);

            return [
                'total' => $this->formatBytes($totalBytes),
                'used' => $this->formatBytes($usedBytes),
                'free' => $this->formatBytes($availableBytes),
                'usage_percent' => $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 2) : 0,
            ];
        }
        return ['total' => '0 B', 'used' => '0 B', 'free' => '0 B', 'usage_percent' => 0];
    }

    private function getUptime(): array
    {
        if (!file_exists('/proc/uptime')) {
            return ['seconds' => 0, 'formatted' => 'N/A'];
        }
        $sec = (int) ((float) file_get_contents('/proc/uptime'));
        $d = (int) floor($sec / 86400);
        $h = (int) floor(($sec % 86400) / 3600);
        $m = (int) floor(($sec % 3600) / 60);

        return [
            'seconds' => $sec,
            'formatted' => sprintf('%d gün %d saat %d dk', $d, $h, $m),
        ];
    }

    private function scanBackupDir(string $dir, string $type): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*') ?: [];
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $items = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $items[] = [
                'name' => basename($file),
                'size' => $this->formatBytes((int) filesize($file)),
                'size_bytes' => (int) filesize($file),
                'date' => date('d.m.Y H:i:s', (int) filemtime($file)),
                'timestamp' => (int) filemtime($file),
                'type' => $type,
            ];
        }
        return $items;
    }

    private function resolveProjectPath(string $relativePath, bool $mustBeDir): ?string
    {
        $root = realpath($this->projectRoot());
        if ($root === false) {
            return null;
        }

        $trimmed = trim($relativePath);
        $trimmed = str_replace('\\', '/', $trimmed);
        if (str_contains($trimmed, '../')) {
            return null;
        }

        $candidate = $root . DIRECTORY_SEPARATOR . ltrim($trimmed, '/');
        $real = realpath($candidate);

        if ($real === false) {
            if ($mustBeDir) {
                return null;
            }
            $parent = realpath(dirname($candidate));
            if ($parent === false || !str_starts_with($parent, $root)) {
                return null;
            }
            return $candidate;
        }

        if (!str_starts_with($real, $root)) {
            return null;
        }

        return $real;
    }

    private function tailFile(string $path, int $lines): string
    {
        $content = (string) @file_get_contents($path);
        if ($content === '') {
            return '';
        }
        $rows = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $rows = array_slice($rows, -$lines);
        return implode("\n", $rows);
    }

    private function detectFileTypeBadge(string $relativePath, string $ext): string
    {
        $path = strtolower($relativePath);
        if (str_contains($path, '/migrations/')) {
            return 'migration';
        }
        if (str_contains($path, '/assets/') || str_contains($path, '/public/')) {
            return 'asset';
        }
        if ($ext === 'log') {
            return 'log';
        }
        if (in_array($ext, ['env', 'ini', 'yaml', 'yml', 'json', 'xml', 'conf', 'config'], true)) {
            return 'config';
        }
        return 'file';
    }

    private function looksBinary(string $content): bool
    {
        return str_contains($content, "\0");
    }

    private function isSensitiveFile(string $path): bool
    {
        $normalized = strtolower(trim($path, '/'));
        if ($normalized === '') {
            return false;
        }
        $sensitiveNames = [
            '.env',
            '.env.local',
            'id_rsa',
            'id_dsa',
            'id_ecdsa',
            'id_ed25519',
            'docker-compose.yml',
            'docker-compose.yaml',
            'credentials.json',
        ];
        foreach ($sensitiveNames as $name) {
            if (str_ends_with($normalized, strtolower($name))) {
                return true;
            }
        }
        return false;
    }

    private function maskSensitiveContent(string $path, string $content): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $masked = array_map(static function (string $line): string {
            if (!str_contains($line, '=')) {
                return $line;
            }
            [$k] = explode('=', $line, 2);
            return $k . '=********';
        }, $lines);
        return implode("\n", $masked);
    }

    private function deleteDirectory(string $dir): void
    {
        $items = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function validateAdminAndCsrf(Request $request, Response $response): bool
    {
        if (!$this->isAdmin()) {
            return false;
        }
        return $this->isValidCsrf($request);
    }

    private function isValidCsrf(Request $request): bool
    {
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

    private function isAdmin(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return (bool) ($_SESSION['is_admin'] ?? false);
    }

    private function auditAction(string $event, ?array $newValues = null): void
    {
        try {
            $userId = (int) ($_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? 0);
            $this->auditLogger->log($userId > 0 ? $userId : null, $event, 'SystemMonitor', null, null, $newValues);
        } catch (\Throwable) {
            // audit kayıt hatası ana işlemi bozmasın
        }
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function isMaintenanceModeEnabled(): bool
    {
        return is_file($this->projectRoot() . '/storage/maintenance.flag');
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function formatBytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $v = (float) $bytes;
        $i = 0;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            ++$i;
        }
        return round($v, $precision) . ' ' . $units[$i];
    }
}

