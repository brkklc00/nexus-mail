/**
 * PM2 Ecosystem Configuration
 * Nexus Mail — email-worker (Node) + data-pool-worker (PHP kuyruk)
 *
 * Kullanım:
 *   cd /var/www/nexus-mail   # veya /var/www/nexus
 *   pm2 start ecosystem.config.js
 *   pm2 restart all
 *   pm2 save && pm2 startup
 */

const path = require('path');
const fs = require('fs');
const projectRoot = __dirname;

/** .env içinden DB_* anahtarlarını PM2 ortamına aktarır (boş sistem env override önlemi). */
function loadDbEnvFromFile(envPath) {
  const out = {};
  if (!fs.existsSync(envPath)) {
    return out;
  }

  for (const rawLine of fs.readFileSync(envPath, 'utf8').split(/\r?\n/)) {
    const line = rawLine.trim();
    if (!line || line.startsWith('#')) {
      continue;
    }
    const eq = line.indexOf('=');
    if (eq < 1) {
      continue;
    }
    const key = line.slice(0, eq).trim();
    if (!/^(DB_|EMAIL_DB_|DATABASE_URL)/.test(key)) {
      continue;
    }
    let value = line.slice(eq + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"'))
      || (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    out[key] = value;
  }

  return out;
}

const dbEnv = loadDbEnvFromFile(path.join(projectRoot, '.env'));

module.exports = {
  apps: [
    {
      name: 'email-worker',
      cwd: projectRoot,
      script: './email-worker/src/index.js',
      interpreter: 'node',
      exec_mode: 'fork',
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '500M',
      env: {
        NODE_ENV: 'production',
        FORCE_COLOR: '1',
        EMAIL_LOG_LEVEL: 'info',
        EMAIL_WORKER_ID: 'email-worker-0',
        EMAIL_CLAIM_TTL_SECONDS: '900',
        EMAIL_STUCK_TTL_HOURS: '6',
        EMAIL_CLEANUP_INTERVAL_MS: '120000',
        API_BATCH_TIMEOUT: '300000',
        ...dbEnv
      },
      error_file: './email-worker/logs/error.log',
      out_file: './email-worker/logs/out.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss',
      merge_logs: true
    },
    {
      name: 'data-pool-worker',
      cwd: projectRoot,
      script: 'bin/console',
      args: ['data-pool:jobs:work'],
      interpreter: 'php',
      exec_mode: 'fork',
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '1536M',
      env: {
        DATA_POOL_WORKER_ID: 'data-pool-worker-0',
        DATA_POOL_JOB_MAX_RUNTIME: '3600',
        DATA_POOL_JOB_BATCH_SIZE: '10000',
        DATA_POOL_JOB_SLEEP_MS: '100',
        DATA_POOL_HEARTBEAT_SECONDS: '10',
        ...dbEnv
      },
      error_file: './storage/logs/data-pool-worker-error.log',
      out_file: './storage/logs/data-pool-worker-out.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss',
      merge_logs: true
    }
  ]
};

