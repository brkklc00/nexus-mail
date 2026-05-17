<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment as TwigEnvironment;
use Doctrine\ORM\EntityManager;

class SystemMonitorController
{
    public function __construct(
        private TwigEnvironment $twig,
        private EntityManager $em
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $html = $this->twig->render('admin/system-monitor/index.twig', [
            'page_title' => 'Sunucu Yönetim Paneli'
        ]);
        
        $response->getBody()->write($html);
        return $response;
    }

    public function stats(Request $request, Response $response): Response
    {
        try {
            $stats = [
                'server' => $this->getServerStats(),
                'pm2' => $this->getPM2Stats(),
                'database' => $this->getDatabaseStats(),
                'disk' => $this->getDiskStats(),
                'network' => $this->getNetworkStats(),
                'logs' => $this->getLogStats(),
            ];

            $response->getBody()->write(json_encode($stats));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $error = [
                'error' => true,
                'message' => 'İstatistikler alınamadı: ' . $e->getMessage(),
                'server' => ['error' => $e->getMessage()],
                'pm2' => ['error' => 'Veri alınamadı'],
                'database' => ['error' => 'Veri alınamadı'],
                'disk' => ['error' => 'Veri alınamadı'],
                'network' => [],
                'logs' => ['error' => 'Veri alınamadı']
            ];
            
            $response->getBody()->write(json_encode($error));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    public function clearLogs(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $logType = $data['type'] ?? 'all';
        $workerName = $data['worker'] ?? null;

        try {
            if ($logType === 'worker' && $workerName) {
                // Belirli worker'ın loglarını temizle
                $this->clearWorkerLogs($workerName);
                $message = "{$workerName} logları temizlendi";
            } elseif ($logType === 'pm2') {
                // PM2 sistem loglarını temizle
                $this->clearPM2SystemLogs();
                $message = "PM2 sistem logları temizlendi";
            } elseif ($logType === 'app') {
                // Uygulama loglarını temizle
                $this->clearAppLogs();
                $message = "Uygulama logları temizlendi";
            } else {
                // Tüm logları temizle
                $this->clearAllLogs();
                $message = "Tüm loglar temizlendi";
            }

            $result = ['success' => true, 'message' => $message];
        } catch (\Exception $e) {
            $result = ['success' => false, 'message' => 'Hata: ' . $e->getMessage()];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function clearWorkerLogs(string $workerName): void
    {
        $projectPath = dirname(__DIR__, 3);
        $logPath = $projectPath . '/' . $workerName . '/logs';

        if (is_dir($logPath)) {
            $files = glob($logPath . '/*.log');
            foreach ($files as $file) {
                if (is_file($file)) {
                    file_put_contents($file, '');
                }
            }
        }
    }

    private function clearPM2SystemLogs(): void
    {
        // PM2 loglarını temizle
        exec('pm2 flush 2>/dev/null');
    }

    private function clearAppLogs(): void
    {
        $projectPath = dirname(__DIR__, 3);
        $logPaths = [
            $projectPath . '/storage/logs',
            $projectPath . '/var/cache'
        ];

        foreach ($logPaths as $logPath) {
            if (is_dir($logPath)) {
                $files = glob($logPath . '/*.log');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        file_put_contents($file, '');
                    }
                }
            }
        }
    }

    private function clearAllLogs(): void
    {
        // Worker logları
        $workers = ['email-worker'];
        
        foreach ($workers as $worker) {
            $this->clearWorkerLogs($worker);
        }

        // PM2 logları
        $this->clearPM2SystemLogs();

        // Uygulama logları
        $this->clearAppLogs();
    }

    private function getLogStats(): array
    {
        try {
            $projectPath = dirname(__DIR__, 3);
            $workers = ['email-worker'];
            
            $logInfo = [];
            $totalSize = 0;

            foreach ($workers as $worker) {
                $logPath = $projectPath . '/' . $worker . '/logs';
                $size = 0;

                // Log klasörü yoksa oluştur
                if (!is_dir($logPath)) {
                    @mkdir($logPath, 0755, true);
                }

                if (is_dir($logPath)) {
                    $files = @glob($logPath . '/*.log');
                    if ($files !== false) {
                        foreach ($files as $file) {
                            if (is_file($file)) {
                                $fileSize = @filesize($file);
                                if ($fileSize !== false) {
                                    $size += $fileSize;
                                }
                            }
                        }
                    }
                    
                    // Eğer log dosyası yoksa, placeholder oluştur
                    if (count($files) === 0) {
                        @touch($logPath . '/worker.log');
                    }
                }

                $logInfo[$worker] = [
                    'size' => $this->formatBytes($size),
                    'size_bytes' => $size
                ];
                $totalSize += $size;
            }

            // Uygulama logları
            $appLogPath = $projectPath . '/storage/logs';
            $appSize = 0;
            if (is_dir($appLogPath)) {
                $files = @glob($appLogPath . '/*.log');
                if ($files !== false) {
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            $fileSize = @filesize($file);
                            if ($fileSize !== false) {
                                $appSize += $fileSize;
                            }
                        }
                    }
                }
            }

            return [
                'workers' => $logInfo,
                'app' => [
                    'size' => $this->formatBytes($appSize),
                    'size_bytes' => $appSize
                ],
                'total' => $this->formatBytes($totalSize + $appSize),
                'total_bytes' => $totalSize + $appSize
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Log bilgileri alınamadı: ' . $e->getMessage(),
                'workers' => [],
                'app' => ['size' => '0 B', 'size_bytes' => 0],
                'total' => '0 B',
                'total_bytes' => 0
            ];
        }
    }

    private function getServerStats(): array
    {
        try {
            // CPU Kullanımı
            $cpuLoad = sys_getloadavg();
            $cpuCount = $this->getCPUCount();
            $cpuUsage = $cpuCount > 0 ? ($cpuLoad[0] / $cpuCount) * 100 : 0;

            // RAM Kullanımı
            $memInfo = $this->getMemoryInfo();

            // Uptime
            $uptime = $this->getUptime();

            return [
                'cpu' => [
                    'usage' => round($cpuUsage, 2),
                    'cores' => $cpuCount,
                    'load' => [
                        '1min' => round($cpuLoad[0], 2),
                        '5min' => round($cpuLoad[1], 2),
                        '15min' => round($cpuLoad[2], 2),
                    ]
                ],
                'memory' => [
                    'total' => $memInfo['total'] ?? '0 B',
                    'used' => $memInfo['used'] ?? '0 B',
                    'free' => $memInfo['free'] ?? '0 B',
                    'usage_percent' => $memInfo['usage_percent'] ?? 0
                ],
                'uptime' => $uptime
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Sunucu bilgileri alınamadı: ' . $e->getMessage(),
                'cpu' => ['usage' => 0, 'cores' => 0, 'load' => ['1min' => 0, '5min' => 0, '15min' => 0]],
                'memory' => ['total' => '0 B', 'used' => '0 B', 'free' => '0 B', 'usage_percent' => 0],
                'uptime' => ['seconds' => 0, 'formatted' => 'Bilinmiyor']
            ];
        }
    }

    private function getPM2Stats(): array
    {
        try {
            // Önce which pm2 ile bulmayı dene
            $whichOutput = [];
            exec("which pm2 2>/dev/null", $whichOutput, $whichCode);
            
            $pm2Paths = [];
            
            // which pm2 sonucu varsa önce onu dene
            if ($whichCode === 0 && !empty($whichOutput[0])) {
                $pm2Paths[] = trim($whichOutput[0]);
            }
            
            // Diğer olası path'ler
            $pm2Paths = array_merge($pm2Paths, [
                'pm2',
                '/usr/bin/pm2',
                '/usr/local/bin/pm2',
                '/usr/local/lib/node_modules/pm2/bin/pm2',
                '/home/ubuntu/.nvm/versions/node/v18.20.5/bin/pm2',
                '/root/.nvm/versions/node/v18.20.5/bin/pm2',
            ]);
            
            // NVM path'lerini kontrol et
            if (is_dir('/home/ubuntu/.nvm/versions/node/')) {
                $nvmDirs = glob('/home/ubuntu/.nvm/versions/node/v*/bin/pm2');
                if (!empty($nvmDirs)) {
                    $pm2Paths = array_merge($pm2Paths, $nvmDirs);
                }
            }
            
            $output = [];
            $returnCode = 1;
            $pm2Found = false;
            
            foreach ($pm2Paths as $pm2Path) {
                if (empty($pm2Path)) continue;
                
                // PATH'e node bin dizinini ekle
                $nodeDir = dirname($pm2Path);
                
                // Önce sudo ile dene (root PM2 için)
                $commands = [
                    "sudo $pm2Path jlist 2>&1",
                    "export PATH=\$PATH:$nodeDir && $pm2Path jlist 2>&1",
                    "$pm2Path jlist 2>&1"
                ];
                
                foreach ($commands as $command) {
                    $output = [];
                    exec($command, $output, $returnCode);
                    
                    if ($returnCode === 0 && !empty($output)) {
                        $pm2Found = true;
                        break 2; // Her iki döngüden de çık
                    }
                }
            }
            
            if (!$pm2Found || empty($output)) {
                return [
                    'error' => 'PM2 bulunamadı',
                    'workers' => [],
                    'total' => 0,
                    'online' => 0,
                    'stopped' => 0,
                    'errored' => 0
                ];
            }

            $jsonOutput = implode('', $output);
            $processes = json_decode($jsonOutput, true);
            
            if (!is_array($processes)) {
                return [
                    'error' => 'PM2 çıktısı okunamadı',
                    'workers' => [],
                    'total' => 0,
                    'online' => 0,
                    'stopped' => 0,
                    'errored' => 0
                ];
            }
            
            $workers = [];

            foreach ($processes as $process) {
                $workers[] = [
                    'name' => $process['name'] ?? 'Unknown',
                    'status' => $process['pm2_env']['status'] ?? 'unknown',
                    'uptime' => isset($process['pm2_env']['pm_uptime']) 
                        ? time() - floor($process['pm2_env']['pm_uptime'] / 1000)
                        : 0,
                    'restarts' => $process['pm2_env']['restart_time'] ?? 0,
                    'memory' => isset($process['monit']['memory']) 
                        ? $this->formatBytes($process['monit']['memory']) 
                        : '0 MB',
                    'cpu' => $process['monit']['cpu'] ?? 0,
                    'pid' => $process['pid'] ?? 0
                ];
            }

            return [
                'workers' => $workers,
                'total' => count($workers),
                'online' => count(array_filter($workers, fn($w) => $w['status'] === 'online')),
                'stopped' => count(array_filter($workers, fn($w) => $w['status'] === 'stopped')),
                'errored' => count(array_filter($workers, fn($w) => $w['status'] === 'errored'))
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Hata: ' . $e->getMessage(),
                'workers' => [],
                'total' => 0,
                'online' => 0,
                'stopped' => 0,
                'errored' => 0
            ];
        }
    }

    private function getDatabaseStats(): array
    {
        try {
            $conn = $this->em->getConnection();
            $params = $conn->getParams();
            
            // Veritabanı boyutu
            $dbName = $params['dbname'] ?? '';
            $stmt = $conn->prepare("
                SELECT 
                    SUM(data_length + index_length) as size,
                    SUM(data_length) as data_size,
                    SUM(index_length) as index_size,
                    COUNT(*) as table_count
                FROM information_schema.TABLES 
                WHERE table_schema = ?
            ");
            $result = $stmt->executeQuery([$dbName]);
            $dbStats = $result->fetchAssociative();

            // Bağlantı sayısı
            $stmt = $conn->prepare("SHOW STATUS LIKE 'Threads_connected'");
            $result = $stmt->executeQuery();
            $connections = $result->fetchAssociative();

            return [
                'size' => $this->formatBytes((int)($dbStats['size'] ?? 0)),
                'size_bytes' => (int)($dbStats['size'] ?? 0),
                'data_size' => $this->formatBytes((int)($dbStats['data_size'] ?? 0)),
                'index_size' => $this->formatBytes((int)($dbStats['index_size'] ?? 0)),
                'tables' => (int)($dbStats['table_count'] ?? 0),
                'connections' => (int)($connections['Value'] ?? 0)
            ];
        } catch (\Exception $e) {
            return ['error' => 'Veritabanı bilgisi alınamadı: ' . $e->getMessage()];
        }
    }

    private function getDiskStats(): array
    {
        try {
            $projectPath = dirname(__DIR__, 3);
            
            $totalSpace = @disk_total_space($projectPath);
            $freeSpace = @disk_free_space($projectPath);
            
            if ($totalSpace === false || $freeSpace === false) {
                throw new \Exception('Disk bilgileri alınamadı');
            }
            
            $usedSpace = $totalSpace - $freeSpace;
            $usagePercent = ($usedSpace / $totalSpace) * 100;

            // Disk I/O (Linux only)
            $diskIO = $this->getDiskIO();

            return [
                'total' => $this->formatBytes($totalSpace),
                'used' => $this->formatBytes($usedSpace),
                'free' => $this->formatBytes($freeSpace),
                'usage_percent' => round($usagePercent, 2),
                'io' => $diskIO
            ];
        } catch (\Exception $e) {
            return [
                'error' => 'Disk bilgileri alınamadı: ' . $e->getMessage(),
                'total' => '0 B',
                'used' => '0 B',
                'free' => '0 B',
                'usage_percent' => 0,
                'io' => ['reads' => 0, 'writes' => 0]
            ];
        }
    }

    private function getNetworkStats(): array
    {
        // Network interface stats (Linux)
        $networkData = [];
        
        if (file_exists('/proc/net/dev')) {
            $content = file_get_contents('/proc/net/dev');
            $lines = explode("\n", $content);
            
            foreach ($lines as $line) {
                if (preg_match('/^\s*(\w+):\s*(\d+)\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $matches)) {
                    $interface = $matches[1];
                    $rxBytes = (int)$matches[2];
                    $txBytes = (int)$matches[3];
                    
                    // Sadece loopback değil, veri trafiği olan tüm interface'leri göster
                    // lo'yu en sona ekleyeceğiz
                    if ($interface !== 'lo') {
                        $networkData[$interface] = [
                            'rx_bytes' => $this->formatBytes($rxBytes),
                            'tx_bytes' => $this->formatBytes($txBytes),
                            'rx_bytes_raw' => $rxBytes,
                            'tx_bytes_raw' => $txBytes,
                        ];
                    }
                }
            }
        }

        return $networkData;
    }

    private function getDiskIO(): array
    {
        if (!file_exists('/proc/diskstats')) {
            return ['reads' => 0, 'writes' => 0];
        }

        $content = file_get_contents('/proc/diskstats');
        $lines = explode("\n", $content);
        $totalReads = 0;
        $totalWrites = 0;

        foreach ($lines as $line) {
            if (preg_match('/^\s*\d+\s+\d+\s+(\w+)\s+(\d+)\s+\d+\s+\d+\s+\d+\s+(\d+)/', $line, $matches)) {
                if (!preg_match('/^loop|^ram/', $matches[1])) {
                    $totalReads += (int)$matches[2];
                    $totalWrites += (int)$matches[3];
                }
            }
        }

        return [
            'reads' => $totalReads,
            'writes' => $totalWrites
        ];
    }

    private function getCPUCount(): int
    {
        if (file_exists('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            preg_match_all('/^processor/m', $cpuinfo, $matches);
            return count($matches[0]);
        }
        return 1;
    }

    private function getMemoryInfo(): array
    {
        $memInfo = [];
        
        // Linux
        if (file_exists('/proc/meminfo')) {
            $content = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $content, $total);
            preg_match('/MemAvailable:\s+(\d+)/', $content, $available);
            
            $totalBytes = ((int)$total[1]) * 1024;
            $availableBytes = ((int)$available[1]) * 1024;
            $usedBytes = $totalBytes - $availableBytes;
            
            $memInfo = [
                'total' => $this->formatBytes($totalBytes),
                'used' => $this->formatBytes($usedBytes),
                'free' => $this->formatBytes($availableBytes),
                'usage_percent' => round(($usedBytes / $totalBytes) * 100, 2)
            ];
        }
        // macOS
        elseif (PHP_OS_FAMILY === 'Darwin') {
            exec('sysctl hw.memsize', $output);
            if (!empty($output[0])) {
                preg_match('/:\s*(\d+)/', $output[0], $matches);
                $totalBytes = (int)($matches[1] ?? 0);
                
                exec('vm_stat', $vmstat);
                $pageSize = 4096; // Default page size
                $free = $wired = $active = $inactive = 0;
                
                foreach ($vmstat as $line) {
                    if (preg_match('/Pages free:\s+(\d+)/', $line, $m)) $free = (int)$m[1];
                    if (preg_match('/Pages wired down:\s+(\d+)/', $line, $m)) $wired = (int)$m[1];
                    if (preg_match('/Pages active:\s+(\d+)/', $line, $m)) $active = (int)$m[1];
                    if (preg_match('/Pages inactive:\s+(\d+)/', $line, $m)) $inactive = (int)$m[1];
                }
                
                $freeBytes = $free * $pageSize;
                $usedBytes = ($wired + $active) * $pageSize;
                
                $memInfo = [
                    'total' => $this->formatBytes($totalBytes),
                    'used' => $this->formatBytes($usedBytes),
                    'free' => $this->formatBytes($freeBytes),
                    'usage_percent' => $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 2) : 0
                ];
            }
        }

        return $memInfo;
    }

    private function getUptime(): array
    {
        if (file_exists('/proc/uptime')) {
            $uptime = (int)file_get_contents('/proc/uptime');
            $days = floor($uptime / 86400);
            $hours = floor(($uptime % 86400) / 3600);
            $minutes = floor(($uptime % 3600) / 60);
            
            return [
                'seconds' => $uptime,
                'formatted' => sprintf('%d gün, %d saat, %d dakika', $days, $hours, $minutes)
            ];
        }

        return ['seconds' => 0, 'formatted' => 'Bilinmiyor'];
    }

    private function formatBytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = (float) $bytes;
        $i = 0;
        
        while ($bytes > 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    public function viewWorkerLog(Request $request, Response $response): Response
    {
        $workerName = $request->getAttribute('worker');
        $projectPath = dirname(__DIR__, 3);
        $logPath = $projectPath . '/' . $workerName . '/logs';

        try {
            $logs = [];
            if (is_dir($logPath)) {
                $files = glob($logPath . '/*.log');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $content = @file_get_contents($file);
                        if ($content !== false) {
                            // Son 1000 satırı al
                            $lines = explode("\n", $content);
                            $lines = array_slice($lines, -1000);
                            $logs[basename($file)] = implode("\n", $lines);
                        }
                    }
                }
            }

            $result = [
                'success' => true,
                'worker' => $workerName,
                'logs' => $logs
            ];
        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'message' => 'Log okunamadı: ' . $e->getMessage()
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function downloadWorkerLog(Request $request, Response $response): Response
    {
        $workerName = $request->getAttribute('worker');
        $projectPath = dirname(__DIR__, 3);
        $logPath = $projectPath . '/' . $workerName . '/logs';

        try {
            $zipFile = $projectPath . '/storage/logs/' . $workerName . '-logs-' . date('Y-m-d-His') . '.zip';
            
            if (!is_dir($logPath)) {
                throw new \Exception('Log dizini bulunamadı');
            }

            $zip = new \ZipArchive();
            if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \Exception('Zip dosyası oluşturulamadı');
            }

            $files = glob($logPath . '/*.log');
            foreach ($files as $file) {
                if (is_file($file)) {
                    $zip->addFile($file, basename($file));
                }
            }

            $zip->close();

            if (!file_exists($zipFile)) {
                throw new \Exception('Zip dosyası oluşturulamadı');
            }

            $response = $response
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', 'attachment; filename="' . basename($zipFile) . '"')
                ->withHeader('Content-Length', (string)filesize($zipFile));

            $response->getBody()->write(file_get_contents($zipFile));
            
            // Geçici dosyayı sil
            @unlink($zipFile);

            return $response;
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    public function createDatabaseBackup(Request $request, Response $response): Response
    {
        try {
            // Session kontrolü
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Kullanıcı kontrolü
            if (!isset($_SESSION['user'])) {
                throw new \Exception('Oturum süreniz dolmuş. Lütfen tekrar giriş yapın.');
            }
            
            // Admin veya system_monitor yetkisi kontrolü
            $isAdmin = $_SESSION['is_admin'] ?? false;
            $hasPermission = $isAdmin || (isset($_SESSION['user_permissions']['system_monitor']['read']) && $_SESSION['user_permissions']['system_monitor']['read']);
            
            if (!$hasPermission) {
                throw new \Exception('Bu işlem için yetkiniz yok. Lütfen yöneticinizle iletişime geçin.');
            }
            
            $projectPath = dirname(__DIR__, 3);
            $backupDir = $projectPath . '/storage/backups/database';
            
            // Klasör yoksa oluştur
            if (!is_dir($backupDir)) {
                if (!@mkdir($backupDir, 0755, true)) {
                    throw new \Exception('Backup dizini oluşturulamadı. Klasör izinlerini kontrol edin: ' . $backupDir);
                }
            }
            
            // Yazma izni kontrolü
            if (!is_writable($backupDir)) {
                throw new \Exception('Backup dizinine yazma izni yok: ' . $backupDir . '. Lütfen klasör izinlerini kontrol edin (chmod 755)');
            }

            $conn = $this->em->getConnection();
            $params = $conn->getParams();
            
            $dbName = $params['dbname'] ?? '';
            $dbHost = $params['host'] ?? 'localhost';
            $dbUser = $params['user'] ?? '';
            $dbPass = $params['password'] ?? '';

            $fileName = 'db-backup-' . date('Y-m-d-His') . '.sql.gz';
            $filePath = $backupDir . '/' . $fileName;

            // mysqldump kontrol et
            $mysqldumpPath = exec('which mysqldump 2>/dev/null');
            if (empty($mysqldumpPath)) {
                // Alternatif yollar dene (MAMP, sistem)
                $possiblePaths = [
                    '/Applications/MAMP/Library/bin/mysql80/bin/mysqldump',
                    '/Applications/MAMP/Library/bin/mysql57/bin/mysqldump',
                    '/usr/bin/mysqldump', 
                    '/usr/local/bin/mysqldump', 
                    '/Applications/MAMP/Library/bin/mysqldump'
                ];
                foreach ($possiblePaths as $path) {
                    if (file_exists($path) && is_executable($path)) {
                        $mysqldumpPath = $path;
                        break;
                    }
                }
                
                if (empty($mysqldumpPath)) {
                    throw new \Exception('mysqldump bulunamadı. Kontrol edilen yollar: ' . implode(', ', $possiblePaths));
                }
            }

            // mysqldump ile yedek al ve gzip ile sıkıştır
            $command = sprintf(
                '%s -h%s -u%s -p%s %s | gzip > %s 2>&1',
                $mysqldumpPath,
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName),
                escapeshellarg($filePath)
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                $errorMsg = implode("\n", $output);
                // Şifreyi gizle
                $errorMsg = preg_replace('/-p[^\s]+/', '-p****', $errorMsg);
                throw new \Exception('Yedekleme başarısız (kod: ' . $returnCode . '): ' . $errorMsg);
            }
            
            if (!file_exists($filePath)) {
                throw new \Exception('Yedek dosyası oluşturulamadı: ' . $filePath);
            }
            
            if (filesize($filePath) === 0) {
                @unlink($filePath);
                throw new \Exception('Yedek dosyası boş oluşturuldu. Veritabanı erişim izinlerini kontrol edin.');
            }

            $result = [
                'success' => true,
                'message' => 'Veritabanı başarıyla yedeklendi',
                'file' => $fileName,
                'size' => $this->formatBytes(filesize($filePath)),
                'path' => $filePath
            ];
        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function createFileBackup(Request $request, Response $response): Response
    {
        try {
            // Session kontrolü
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Kullanıcı kontrolü
            if (!isset($_SESSION['user'])) {
                throw new \Exception('Oturum süreniz dolmuş. Lütfen tekrar giriş yapın.');
            }
            
            // Admin veya system_monitor yetkisi kontrolü
            $isAdmin = $_SESSION['is_admin'] ?? false;
            $hasPermission = $isAdmin || (isset($_SESSION['user_permissions']['system_monitor']['read']) && $_SESSION['user_permissions']['system_monitor']['read']);
            
            if (!$hasPermission) {
                throw new \Exception('Bu işlem için yetkiniz yok. Lütfen yöneticinizle iletişime geçin.');
            }
            
            $projectPath = dirname(__DIR__, 3);
            $backupDir = $projectPath . '/storage/backups/files';
            
            // Klasör yoksa oluştur
            if (!is_dir($backupDir)) {
                if (!@mkdir($backupDir, 0755, true)) {
                    throw new \Exception('Backup dizini oluşturulamadı. Klasör izinlerini kontrol edin: ' . $backupDir);
                }
            }
            
            // Yazma izni kontrolü
            if (!is_writable($backupDir)) {
                throw new \Exception('Backup dizinine yazma izni yok: ' . $backupDir . '. Lütfen klasör izinlerini kontrol edin (chmod 755)');
            }

            $fileName = 'files-backup-' . date('Y-m-d-His') . '.tar.gz';
            $filePath = $backupDir . '/' . $fileName;

            // Yedeklenecek dizinler
            $dirsToBackup = [
                'storage/uploads',
            ];

            $excludeDirs = [
                'storage/logs',
                'storage/backups',
                'var/cache',
                'node_modules',
                'vendor',
            ];

            $excludeArgs = '';
            foreach ($excludeDirs as $dir) {
                $excludeArgs .= ' --exclude=' . escapeshellarg($dir);
            }

            $includeArgs = '';
            $foundDirs = 0;
            foreach ($dirsToBackup as $dir) {
                if (is_dir($projectPath . '/' . $dir)) {
                    $includeArgs .= ' ' . escapeshellarg($dir);
                    $foundDirs++;
                }
            }
            
            if ($foundDirs === 0) {
                throw new \Exception('Yedeklenecek dizin bulunamadı. Kontrol edilen dizinler: ' . implode(', ', $dirsToBackup));
            }

            // tar kontrolü
            $tarPath = exec('which tar 2>/dev/null') ?: 'tar';

            // tar ile yedek al
            $command = sprintf(
                'cd %s && %s -czf %s %s %s 2>&1',
                escapeshellarg($projectPath),
                $tarPath,
                escapeshellarg($filePath),
                $excludeArgs,
                $includeArgs
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \Exception('Dosya yedekleme başarısız (kod: ' . $returnCode . '): ' . implode("\n", $output));
            }
            
            if (!file_exists($filePath)) {
                throw new \Exception('Yedek dosyası oluşturulamadı: ' . $filePath);
            }
            
            if (filesize($filePath) === 0) {
                @unlink($filePath);
                throw new \Exception('Yedek dosyası boş oluşturuldu.');
            }

            $result = [
                'success' => true,
                'message' => 'Dosyalar başarıyla yedeklendi (' . $foundDirs . ' dizin)',
                'file' => $fileName,
                'size' => $this->formatBytes(filesize($filePath)),
                'path' => $filePath
            ];
        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function listBackups(Request $request, Response $response): Response
    {
        try {
            $projectPath = dirname(__DIR__, 3);
            $backupDirs = [
                'database' => $projectPath . '/storage/backups/database',
                'files' => $projectPath . '/storage/backups/files',
            ];

            $backups = [
                'database' => [],
                'files' => [],
            ];

            foreach ($backupDirs as $type => $dir) {
                if (is_dir($dir)) {
                    $files = glob($dir . '/*');
                    usort($files, function($a, $b) {
                        return filemtime($b) - filemtime($a);
                    });

                    foreach ($files as $file) {
                        if (is_file($file)) {
                            $backups[$type][] = [
                                'name' => basename($file),
                                'size' => $this->formatBytes(filesize($file)),
                                'size_bytes' => filesize($file),
                                'date' => date('d.m.Y H:i:s', filemtime($file)),
                                'timestamp' => filemtime($file),
                            ];
                        }
                    }
                }
            }

            $result = [
                'success' => true,
                'backups' => $backups
            ];
        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage()
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function downloadBackup(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $type = $params['type'] ?? '';
        $file = $params['file'] ?? '';

        try {
            $projectPath = dirname(__DIR__, 3);
            $backupDir = $projectPath . '/storage/backups/' . $type;
            $filePath = $backupDir . '/' . $file;

            if (!file_exists($filePath) || !is_file($filePath)) {
                throw new \Exception('Yedek dosyası bulunamadı');
            }

            // Güvenlik: Sadece backup dizini altındaki dosyalara izin ver
            $realPath = realpath($filePath);
            $realBackupDir = realpath($backupDir);
            
            if (strpos($realPath, $realBackupDir) !== 0) {
                throw new \Exception('Geçersiz dosya yolu');
            }

            $response = $response
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withHeader('Content-Disposition', 'attachment; filename="' . basename($file) . '"')
                ->withHeader('Content-Length', (string)filesize($filePath));

            $response->getBody()->write(file_get_contents($filePath));

            return $response;
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    public function restoreDatabaseBackup(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $file = $data['file'] ?? '';

        try {
            // Session kontrolü
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Admin kontrolü (restore sadece admin yapabilir)
            $isAdmin = $_SESSION['is_admin'] ?? false;
            if (!$isAdmin) {
                throw new \Exception('Veritabanı geri yükleme sadece admin kullanıcılar tarafından yapılabilir');
            }
            
            $projectPath = dirname(__DIR__, 3);
            $backupDir = $projectPath . '/storage/backups/database';
            $filePath = $backupDir . '/' . $file;

            if (!file_exists($filePath) || !is_file($filePath)) {
                throw new \Exception('Yedek dosyası bulunamadı');
            }

            // Güvenlik kontrolü
            $realPath = realpath($filePath);
            $realBackupDir = realpath($backupDir);
            
            if (strpos($realPath, $realBackupDir) !== 0) {
                throw new \Exception('Geçersiz dosya yolu');
            }

            $conn = $this->em->getConnection();
            $params = $conn->getParams();
            
            $dbName = $params['dbname'] ?? '';
            $dbHost = $params['host'] ?? 'localhost';
            $dbUser = $params['user'] ?? '';
            $dbPass = $params['password'] ?? '';

            // mysql kontrol et
            $mysqlPath = exec('which mysql 2>/dev/null');
            if (empty($mysqlPath)) {
                // Alternatif yollar dene (MAMP, sistem)
                $possiblePaths = [
                    '/Applications/MAMP/Library/bin/mysql80/bin/mysql',
                    '/Applications/MAMP/Library/bin/mysql57/bin/mysql',
                    '/usr/bin/mysql', 
                    '/usr/local/bin/mysql', 
                    '/Applications/MAMP/Library/bin/mysql'
                ];
                foreach ($possiblePaths as $path) {
                    if (file_exists($path) && is_executable($path)) {
                        $mysqlPath = $path;
                        break;
                    }
                }
                
                if (empty($mysqlPath)) {
                    throw new \Exception('mysql komutu bulunamadı. Kontrol edilen yollar: ' . implode(', ', $possiblePaths));
                }
            }

            // Dosya gzip'li mi kontrol et
            $isGzipped = pathinfo($file, PATHINFO_EXTENSION) === 'gz';
            
            if ($isGzipped) {
                // gzip'li dosyayı açıp restore et
                $command = sprintf(
                    'gunzip < %s | %s -h%s -u%s -p%s %s 2>&1',
                    escapeshellarg($filePath),
                    $mysqlPath,
                    escapeshellarg($dbHost),
                    escapeshellarg($dbUser),
                    escapeshellarg($dbPass),
                    escapeshellarg($dbName)
                );
            } else {
                // Normal SQL dosyası
                $command = sprintf(
                    '%s -h%s -u%s -p%s %s < %s 2>&1',
                    $mysqlPath,
                    escapeshellarg($dbHost),
                    escapeshellarg($dbUser),
                    escapeshellarg($dbPass),
                    escapeshellarg($dbName),
                    escapeshellarg($filePath)
                );
            }

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                $errorMsg = implode("\n", $output);
                $errorMsg = preg_replace('/-p[^\s]+/', '-p****', $errorMsg);
                throw new \Exception('Geri yükleme başarısız (kod: ' . $returnCode . '): ' . $errorMsg);
            }

            $result = [
                'success' => true,
                'message' => 'Veritabanı başarıyla geri yüklendi'
            ];
        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function deleteBackup(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $type = $data['type'] ?? '';
        $file = $data['file'] ?? '';

        try {
            $projectPath = dirname(__DIR__, 3);
            $backupDir = $projectPath . '/storage/backups/' . $type;
            $filePath = $backupDir . '/' . $file;

            if (!file_exists($filePath) || !is_file($filePath)) {
                throw new \Exception('Yedek dosyası bulunamadı');
            }

            // Güvenlik kontrolü
            $realPath = realpath($filePath);
            $realBackupDir = realpath($backupDir);
            
            if (strpos($realPath, $realBackupDir) !== 0) {
                throw new \Exception('Geçersiz dosya yolu');
            }

            if (!unlink($filePath)) {
                throw new \Exception('Dosya silinemedi');
            }

            $result = [
                'success' => true,
                'message' => 'Yedek dosyası silindi'
            ];
        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage()
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function listFiles(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $path = $params['path'] ?? '';

        try {
            $projectPath = dirname(__DIR__, 3);
            $fullPath = $projectPath . '/' . ltrim($path, '/');

            // Güvenlik: Sadece proje dizini içinde gezinmeye izin ver
            $realFullPath = realpath($fullPath) ?: $fullPath;
            $realProjectPath = realpath($projectPath);

            if ($realFullPath !== $realProjectPath && strpos($realFullPath, $realProjectPath) !== 0) {
                throw new \Exception('Geçersiz dizin');
            }

            if (!is_dir($fullPath)) {
                throw new \Exception('Dizin bulunamadı');
            }

            $items = [];
            $files = scandir($fullPath);

            foreach ($files as $file) {
                if ($file === '.') continue;
                
                $itemPath = $fullPath . '/' . $file;
                $relativePath = str_replace($projectPath . '/', '', $itemPath);

                $isDir = is_dir($itemPath);
                $size = $isDir ? 0 : @filesize($itemPath);
                $modified = @filemtime($itemPath);

                $items[] = [
                    'name' => $file,
                    'path' => $relativePath,
                    'is_dir' => $isDir,
                    'size' => $size,
                    'size_formatted' => $this->formatBytes($size),
                    'modified' => $modified ? date('d.m.Y H:i:s', $modified) : '-',
                    'extension' => $isDir ? '' : pathinfo($file, PATHINFO_EXTENSION),
                ];
            }

            // Önce dizinler, sonra dosyalar
            usort($items, function($a, $b) {
                if ($a['is_dir'] !== $b['is_dir']) {
                    return $b['is_dir'] - $a['is_dir'];
                }
                return strcasecmp($a['name'], $b['name']);
            });

            $result = [
                'success' => true,
                'current_path' => $path,
                'items' => $items
            ];
        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage()
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function readFile(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $path = $params['path'] ?? '';

        try {
            $projectPath = dirname(__DIR__, 3);
            $fullPath = $projectPath . '/' . ltrim($path, '/');

            // Güvenlik kontrolü
            $realFullPath = realpath($fullPath);
            $realProjectPath = realpath($projectPath);

            if (!$realFullPath || strpos($realFullPath, $realProjectPath) !== 0) {
                throw new \Exception('Geçersiz dosya yolu');
            }

            if (!is_file($fullPath)) {
                throw new \Exception('Dosya bulunamadı');
            }

            // Dosya boyutu kontrolü (max 5MB)
            $size = filesize($fullPath);
            if ($size > 5242880) {
                throw new \Exception('Dosya çok büyük (max 5MB)');
            }

            $content = file_get_contents($fullPath);
            
            if ($content === false) {
                throw new \Exception('Dosya okunamadı');
            }

            $result = [
                'success' => true,
                'path' => $path,
                'content' => $content,
                'size' => $this->formatBytes($size),
                'modified' => date('d.m.Y H:i:s', filemtime($fullPath))
            ];
        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage()
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function saveFile(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $path = $data['path'] ?? '';
        $content = $data['content'] ?? '';

        try {
            // Session kontrolü
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            // Kullanıcı kontrolü
            if (!isset($_SESSION['user'])) {
                throw new \Exception('Oturum süreniz dolmuş. Lütfen tekrar giriş yapın.');
            }
            
            // Admin veya system_monitor yetkisi kontrolü
            $isAdmin = $_SESSION['is_admin'] ?? false;
            $hasPermission = $isAdmin || (isset($_SESSION['user_permissions']['system_monitor']['read']) && $_SESSION['user_permissions']['system_monitor']['read']);
            
            if (!$hasPermission) {
                throw new \Exception('Bu işlem için yetkiniz yok. Lütfen yöneticinizle iletişime geçin.');
            }
            
            $projectPath = dirname(__DIR__, 3);
            $fullPath = $projectPath . '/' . ltrim($path, '/');

            // Güvenlik kontrolü
            $realFullPath = realpath(dirname($fullPath));
            $realProjectPath = realpath($projectPath);

            if (!$realFullPath || strpos($realFullPath, $realProjectPath) !== 0) {
                throw new \Exception('Geçersiz dosya yolu');
            }

            // Yedek al (hata oluşursa devam et)
            if (file_exists($fullPath)) {
                $backupPath = $fullPath . '.backup.' . date('YmdHis');
                if (!@copy($fullPath, $backupPath)) {
                    // Yedekleme başarısız ama devam et
                    error_log("Yedekleme başarısız: $backupPath");
                }
            }

            // Dizinin yazılabilir olup olmadığını kontrol et
            $dir = dirname($fullPath);
            if (!is_writable($dir)) {
                throw new \Exception('Dizin yazılabilir değil. Sunucu izinlerini kontrol edin: ' . $dir);
            }

            $written = @file_put_contents($fullPath, $content);

            if ($written === false) {
                throw new \Exception('Dosya kaydedilemedi. İzin hatası olabilir. Sunucu izinlerini kontrol edin.');
            }

            $result = [
                'success' => true,
                'message' => 'Dosya kaydedildi',
                'size' => $this->formatBytes($written)
            ];
        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage()
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function createFile(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $path = $data['path'] ?? '';
        $name = $data['name'] ?? '';
        $type = $data['type'] ?? 'file'; // file or dir

        try {
            $projectPath = dirname(__DIR__, 3);
            $fullPath = $projectPath . '/' . ltrim($path, '/') . '/' . $name;

            // Güvenlik kontrolü
            $realBasePath = realpath(dirname($fullPath));
            $realProjectPath = realpath($projectPath);

            if (!$realBasePath || strpos($realBasePath, $realProjectPath) !== 0) {
                throw new \Exception('Geçersiz dizin');
            }

            if (file_exists($fullPath)) {
                throw new \Exception('Dosya/dizin zaten mevcut');
            }

            if ($type === 'dir') {
                if (!mkdir($fullPath, 0755, true)) {
                    throw new \Exception('Dizin oluşturulamadı');
                }
                $message = 'Dizin oluşturuldu';
            } else {
                if (file_put_contents($fullPath, '') === false) {
                    throw new \Exception('Dosya oluşturulamadı');
                }
                $message = 'Dosya oluşturuldu';
            }

            $result = [
                'success' => true,
                'message' => $message
            ];
        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage()
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function deleteFile(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $path = $data['path'] ?? '';

        try {
            $projectPath = dirname(__DIR__, 3);
            $fullPath = $projectPath . '/' . ltrim($path, '/');

            // Güvenlik kontrolü
            $realFullPath = realpath($fullPath);
            $realProjectPath = realpath($projectPath);

            if (!$realFullPath || strpos($realFullPath, $realProjectPath) !== 0) {
                throw new \Exception('Geçersiz dosya yolu');
            }

            if (!file_exists($fullPath)) {
                throw new \Exception('Dosya/dizin bulunamadı');
            }

            if (is_dir($fullPath)) {
                if (!$this->deleteDirectory($fullPath)) {
                    throw new \Exception('Dizin silinemedi');
                }
                $message = 'Dizin silindi';
            } else {
                if (!unlink($fullPath)) {
                    throw new \Exception('Dosya silinemedi');
                }
                $message = 'Dosya silindi';
            }

            $result = [
                'success' => true,
                'message' => $message
            ];
        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage()
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function renameFile(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $path = $data['path'] ?? '';
        $newName = $data['new_name'] ?? '';

        try {
            $projectPath = dirname(__DIR__, 3);
            $fullPath = $projectPath . '/' . ltrim($path, '/');
            $newPath = dirname($fullPath) . '/' . $newName;

            // Güvenlik kontrolü
            $realFullPath = realpath($fullPath);
            $realProjectPath = realpath($projectPath);

            if (!$realFullPath || strpos($realFullPath, $realProjectPath) !== 0) {
                throw new \Exception('Geçersiz dosya yolu');
            }

            if (!file_exists($fullPath)) {
                throw new \Exception('Dosya/dizin bulunamadı');
            }

            if (file_exists($newPath)) {
                throw new \Exception('Hedef dosya/dizin zaten mevcut');
            }

            if (!rename($fullPath, $newPath)) {
                throw new \Exception('Yeniden adlandırma başarısız');
            }

            $result = [
                'success' => true,
                'message' => 'Yeniden adlandırıldı'
            ];
        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage()
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        return rmdir($dir);
    }
}

