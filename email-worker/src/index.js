import { Logger } from './logger.js';
import { ApiClient } from './api-client.js';
import { CampaignProcessor } from './campaign-processor.js'; // PERFORMANS OPTİMİZE EDİLMİŞ VERSİYON
import { ReportChecker } from './report-checker.js';
import { config } from './config.js';
import { getMysqlPool, closeMysqlPool } from './db/mysql-pool.js';
import { createResultFlushService } from './services/result-flush-service.js';
import { startHealthServer } from './health-server.js';

class EmailWorker {
    constructor() {
        this.apiClient = new ApiClient();
        const mode = config.worker.resultMode;
        let pool = null;
        let flushService = null;
        if (mode === 'direct-db') {
            pool = getMysqlPool();
            flushService = createResultFlushService(pool, {
                chunkSize: config.worker.resultFlushSize,
            });
            Logger.info(`Sonuç yazımı    : direct-db (bulk MySQL · WORKER_RESULT_MODE)`);
        } else {
            Logger.info(`Sonuç yazımı    : api (panel POST · WORKER_RESULT_MODE=api)`);
        }
        this.pool = pool;
        this.healthServer = startHealthServer(config.worker.healthPort, () => ({
            result_mode: mode,
            worker_id: config.worker.workerId,
        }));
        this.processor = new CampaignProcessor(this.apiClient, {
            pool,
            flushService,
            resultMode: mode,
            workerId: config.worker.workerId,
        });
        this.reportChecker = new ReportChecker();
        this.isRunning = false;
        this.lastCleanupAt = 0;
    }

    /**
     * Worker'ı başlat
     */
    async start() {
        console.log('');
        Logger.info('═══════════════════════════════════════════════════════');
        const appTitle = process.env.SITE_TITLE || 'Nexus Panel';
        Logger.info(`${appTitle} - Email Worker başlatılıyor...`);
        Logger.info('═══════════════════════════════════════════════════════');
        console.log('');
        Logger.info('Yapılandırma:');
        Logger.info(`API URL          : ${config.api.baseUrl}`);
        Logger.info(`Poll Interval    : ${config.worker.pollInterval}ms`);
        Logger.info(`DB               : ${config.database.host}:${config.database.port} / ${config.database.database}`);
        try {
            await this.apiClient.refreshRuntimeSendingConfig();
            const wr = this.apiClient.workerRuntime;
            if (wr) {
                const r = wr.rate_per_second_effective != null ? Number(wr.rate_per_second_effective) : NaN;
                Logger.info(
                    `Panel gönderim   : ~${Number.isFinite(r) ? r.toFixed(2) : '?'} mail/sn · havuz çekim ${wr.fetch_batch_size ?? '—'} · gönderim grup ${wr.send_batch_size ?? '—'} · SMTP lane ${wr.max_smtp_lanes ?? '—'} · lane eşzamanlı ${wr.send_concurrency_per_lane ?? '—'} · SMTP pool ${(wr.smtp_pool_max_connections ?? 0) > 0 ? wr.smtp_pool_max_connections : 'kapalı'}`
                );
            } else {
                Logger.warn('Panel gönderim ayarları alınamadı; API yanıt verene kadar kod içi varsayılanlar kullanılır.');
            }
        } catch (_) {
            Logger.warn('Panel gönderim ayarları alınamadı; kod içi varsayılanlar kullanılır.');
        }
        console.log('');

        this.isRunning = true;

        // Report checker devre dışı (Email'de gerçek zamanlı tracking yok)
        // this.reportChecker.start();

        process.on('SIGINT', () => void this.shutdown());
        process.on('SIGTERM', () => void this.shutdown());

        // Ana loop
        await this.mainLoop();
    }

    /**
     * Ana polling loop
     */
    async mainLoop() {
        while (this.isRunning) {
            try {
                await this.poll();
            } catch (error) {
                Logger.error(`Main loop error: ${error.message}`);
            }

            // Poll interval kadar bekle
            await this.sleep(config.worker.pollInterval);
        }
    }

    /**
     * Bekleyen kampanyaları çek ve işle
     */
    async poll() {
        try {
            await this.maybeCleanupStuckCampaigns();
            await this.apiClient.refreshRuntimeSendingConfig();

            // Bekleyen kampanyaları getir
            const campaigns = await this.apiClient.getPendingEmailCampaigns();

            if (campaigns.length === 0) {
                // Kampanya yoksa sessiz kal (log yazma)
                return;
            }

            console.log('');
            Logger.info(`${campaigns.length} bekleyen kampanya bulundu`);

            // Her kampanyayı işle
            for (const campaign of campaigns) {
                try {
                    const claimResult = await this.apiClient.claimEmailCampaign(campaign.id, config.worker.workerId);
                    if (!claimResult.claimed) {
                        Logger.info(`Kampanya #${campaign.id} claim alınamadı, atlandı (${claimResult.reason || 'locked'})`);
                        continue;
                    }

                    // Kampanyayı işle
                    await this.processor.processCampaign(campaign);

                    // Not: Email'de report tracking yok (SMTP gerçek zamanlı delivery confirmation vermez)
                    // Report checker sadece timeout kontrolü için çalışır, şimdilik devre dışı

                } catch (error) {
                    Logger.error(`Kampanya #${campaign.id} işlenirken hata: ${error.message}`);
                }
            }

        } catch (error) {
            Logger.error(`Poll error: ${error.message}`);
        }
    }

    async maybeCleanupStuckCampaigns() {
        const now = Date.now();
        if (now - this.lastCleanupAt < config.worker.cleanupIntervalMs) {
            return;
        }

        this.lastCleanupAt = now;
        await this.apiClient.cleanupStuckEmailCampaigns();
    }

    /**
     * Worker'ı durdur
     */
    async shutdown() {
        console.log('');
        Logger.info('═══════════════════════════════════════════════════════');
        Logger.info('Email Worker durduruluyor...');
        Logger.info('═══════════════════════════════════════════════════════');

        this.isRunning = false;
        if (this.healthServer) {
            try {
                this.healthServer.close();
            } catch (_) {}
        }
        await closeMysqlPool().catch(() => {});

        Logger.info('Email Worker başarıyla durduruldu');
        process.exit(0);
    }

    /**
     * Sleep utility
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

// Worker'ı başlat
const worker = new EmailWorker();
worker.start().catch(error => {
    Logger.error(`Email Worker başlatılamadı: ${error.message}`);
    process.exit(1);
});

