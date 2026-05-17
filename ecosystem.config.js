/**
 * PM2 Ecosystem Configuration
 * Nexus Mail için yalnızca email-worker yönetimi
 * 
 * Kullanım:
 *   pm2 start ecosystem.config.js
 *   pm2 restart all
 *   pm2 logs
 */

module.exports = {
  apps: [
    {
      name: 'email-worker',
      // Proje kökünden tek script yolu: PM2 bazı kurulumlarda cwd+npm ile stdout/cluster sapıtıyor
      cwd: '.',
      script: './email-worker/src/index.js',
      interpreter: 'node',
      exec_mode: 'fork',
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '500M',
      env: {
        NODE_ENV: 'production',
        // Default config logging.level is error — without this, Logger.info never reaches PM2 stdout
        EMAIL_LOG_LEVEL: 'info',
        EMAIL_WORKER_ID: 'email-worker-0',
        EMAIL_CLAIM_TTL_SECONDS: '900',
        EMAIL_STUCK_TTL_HOURS: '6',
        EMAIL_CLEANUP_INTERVAL_MS: '120000',
        API_BATCH_TIMEOUT: '300000'
      },
      error_file: './email-worker/logs/error.log',
      out_file: './email-worker/logs/out.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss',
      merge_logs: true
    }
  ]
};

