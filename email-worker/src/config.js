import dotenv from 'dotenv';
import os from 'os';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

// Ana dizindeki .env dosyasını yükle (merkezi config)
dotenv.config({ path: join(__dirname, '..', '..', '.env') });

/** mysql2 + localhost → çoğu sistemde ::1; MySQL yalnızca 127.0.0.1 dinliyorsa ECONNREFUSED */
function normalizeMysqlHost(host) {
    const h = (host || '').trim() || 'localhost';
    return h === 'localhost' ? '127.0.0.1' : h;
}

export const config = {
    api: {
        baseUrl: process.env.API_BASE_URL || 'http://localhost/api',
        token: process.env.API_TOKEN || 'nexus_secure_api_token_2025_ae05df466d9affa51d4651e9dedca1d1',
        /** Genel API çağrıları */
        timeout: parseInt(process.env.API_TIMEOUT) || 30000,
        /**
         * Havuzdan büyük email batch çekme / toplu sonuç yazma (40k satır JSON yavaş olabilir).
         * Varsayılan 5 dk; API_TIMEOUT’tan bağımsız.
         */
        batchTimeout: parseInt(process.env.API_BATCH_TIMEOUT) || 300000,
    },
    
    database: {
        host: normalizeMysqlHost(process.env.EMAIL_DB_HOST || process.env.DB_HOST || 'localhost'),
        port: parseInt(process.env.EMAIL_DB_PORT || process.env.DB_PORT) || 3306,
        user: process.env.EMAIL_DB_USER || process.env.DB_USER || 'root',
        password: process.env.EMAIL_DB_PASSWORD || process.env.DB_PASSWORD || 'root',
        database: process.env.EMAIL_DB_NAME || process.env.DB_NAME || 'nexus_db',
        timezone: '+03:00' // Türkiye saati
    },
    
    worker: {
        workerId: process.env.EMAIL_WORKER_ID || `email-worker-${process.env.NODE_APP_INSTANCE || os.hostname()}`,
        pollInterval: parseInt(process.env.EMAIL_POLL_INTERVAL || process.env.POLL_INTERVAL) || 10000,
        campaignConcurrency: parseInt(process.env.EMAIL_CAMPAIGN_CONCURRENCY || '5', 10) || 5,
        pendingFetchLimit: parseInt(process.env.EMAIL_PENDING_FETCH_LIMIT || '100', 10) || 100,
        maxRetries: parseInt(process.env.EMAIL_MAX_RETRIES || process.env.MAX_RETRIES) || 3,
        claimTtlSeconds: parseInt(process.env.EMAIL_CLAIM_TTL_SECONDS) || 900,
        stuckTtlHours: parseInt(process.env.EMAIL_STUCK_TTL_HOURS) || 6,
        cleanupIntervalMs: parseInt(process.env.EMAIL_CLEANUP_INTERVAL_MS) || 120000,
        /** api = eski panel POST; direct-db = sonuçları MySQL bulk (önerilen) */
        resultMode: (process.env.WORKER_RESULT_MODE || 'direct-db').toLowerCase(),
        dbPoolLimit: parseInt(process.env.WORKER_DB_POOL_LIMIT || '16', 10) || 16,
        resultFlushSize: parseInt(process.env.RESULT_FLUSH_SIZE || '1000', 10) || 1000,
        resultFlushIntervalMs: parseInt(process.env.RESULT_FLUSH_INTERVAL_MS || '5000', 10) || 5000,
        healthPort: parseInt(process.env.WORKER_HEALTH_PORT || '0', 10) || 0,
    },

    logging: {
        level: process.env.EMAIL_LOG_LEVEL || process.env.LOGGING_LEVEL || process.env.LOG_LEVEL || 'error',
        enableFile: process.env.EMAIL_LOGGING_ENABLE_FILE === 'true' || process.env.LOGGING_ENABLE_FILE === 'true',
        file: process.env.EMAIL_LOG_FILE || process.env.LOG_FILE || 'logs/email-worker.log',
    },
    
    reportChecker: {
        checkInterval: parseInt(process.env.EMAIL_REPORT_CHECK_INTERVAL || process.env.REPORT_CHECK_INTERVAL) || 30000,
        maxConcurrent: parseInt(process.env.EMAIL_REPORT_MAX_CONCURRENT || process.env.REPORT_MAX_CONCURRENT) || 3,
        timeout: parseInt(process.env.EMAIL_REPORT_TIMEOUT || process.env.REPORT_TIMEOUT) || 86400000,
        rateLimitDelay: parseInt(process.env.EMAIL_REPORT_RATE_LIMIT_DELAY || process.env.REPORT_RATE_LIMIT_DELAY) || 2000,
    },
};

