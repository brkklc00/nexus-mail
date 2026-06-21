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
        this.lastHeartbeatAt = 0;
        this._shutdownPromise = null;
    }

    /**
     * Worker'ı başlat
     */
    async start() {
        const appTitle = process.env.SITE_TITLE || 'Nexus Panel';
        console.log('');
        Logger.divider('═');
        Logger.banner(`  ${appTitle}  ·  Email Worker`);
        Logger.divider('═');
        console.log('');

        Logger.kv('Worker ID',  config.worker.workerId);
        Logger.kv('API',        config.api.baseUrl);
        Logger.kv('Poll',       `${config.worker.pollInterval}ms`);
        Logger.kv('Eşzamanlı', `${config.worker.campaignConcurrency} kampanya`);
        Logger.kv('Claim TTL',  `${config.worker.claimTtlSeconds}s  │  Stuck: ${config.worker.stuckTtlHours}h`);
        Logger.kv('DB',         `${config.database.host}:${config.database.port} / ${config.database.database}`);

        try {
            await this.apiClient.refreshRuntimeSendingConfig();
            const wr = this.apiClient.workerRuntime;
            if (wr) {
                const r = wr.rate_per_second_effective != null ? Number(wr.rate_per_second_effective) : NaN;
                const rStr = Number.isFinite(r) ? `~${r.toFixed(2)} mail/sn` : '?';
                Logger.kv('Gönderim', `${rStr}  │  çekim ${wr.fetch_batch_size ?? '—'}  │  grup ${wr.send_batch_size ?? '—'}  │  lane ${wr.max_smtp_lanes ?? '—'}`);
            } else {
                Logger.warn('Panel gönderim ayarları alınamadı — kod içi varsayılanlar aktif');
            }
        } catch (_) {
            Logger.warn('Panel gönderim ayarları alınamadı — kod içi varsayılanlar aktif');
        }

        console.log('');
        Logger.info('Restart recovery: önceki kampanyalar kontrol ediliyor...');
        await this.apiClient.cleanupStuckEmailCampaigns(config.worker.workerId);
        Logger.divider();
        console.log('');

        this.isRunning = true;

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
     * Bekleyen kampanyaları çek ve işle (non-blocking dispatch)
     *
     * processCampaign AWAIT EDİLMEZ — fire-and-forget olarak başlatılır.
     * poll() her 10sn'de çalışır, boş slot sayısı kadar yeni kampanya claim eder.
     * Böylece "bekleyen" kampanyalar, işlenmekte olanlar bitmeden de başlar.
     */
    async poll() {
        try {
            await this.maybeCleanupStuckCampaigns();
            await this.apiClient.refreshRuntimeSendingConfig();

            const maxConcurrency = Math.max(1, config.worker.campaignConcurrency);
            const active = this.processor.processing.size;
            const freeSlots = maxConcurrency - active;

            if (freeSlots <= 0) {
                Logger.info(`Tüm ${maxConcurrency} slot dolu (aktif: ${active}) — yeni kampanya alınmıyor`);
                return;
            }

            // Sadece boş slot kadar kampanya çek; WORKER_SLOT >= 0 ise priority kontrolü dahil
            const workerSlot = config.worker.workerSlot ?? -1;
            const campaigns = await this.apiClient.getPendingEmailCampaigns(freeSlots, workerSlot);

            if (campaigns.length === 0) {
                // Heartbeat: her 5 dakikada bir "canlı" logu yaz
                const now = Date.now();
                if (now - this.lastHeartbeatAt >= 5 * 60 * 1000) {
                    this.lastHeartbeatAt = now;
                    Logger.info(`Beklemede — kampanya yok | aktif: ${active} | ${new Date().toLocaleString('tr-TR')}`);
                }
                return;
            }

            console.log('');
            Logger.info(`${campaigns.length} bekleyen kampanya bulundu (aktif: ${active}/${maxConcurrency} slot)`);

            let claimedCount = 0;
            let skippedCount = 0;

            for (const campaign of campaigns) {
                const claimResult = await this.apiClient.claimEmailCampaign(
                    campaign.id, config.worker.workerId, workerSlot
                );
                if (!claimResult.claimed) {
                    Logger.info(`Kampanya #${campaign.id} claim alınamadı, atlandı (${claimResult.reason || 'locked'})`);
                    skippedCount++;
                    continue;
                }

                // Priority slot bilgisini campaign objesine taşı
                if (claimResult.prioritySlotId) {
                    campaign.priority_slot_id = claimResult.prioritySlotId;
                    Logger.info(`[priority] Kampanya #${campaign.id} priority slot #${claimResult.prioritySlotId} ile başlatılıyor`);
                } else {
                    Logger.info(`Kampanya #${campaign.id} başlatıldı (arka planda)`);
                }

                // Fire-and-forget — poll() bloklama; kampanya arka planda işlenir
                this.processor.processCampaign(campaign).catch((err) => {
                    Logger.error(`Kampanya #${campaign.id} işleme hatası: ${err.message}`);
                });
                claimedCount++;
            }

            Logger.info(
                `Dispatch özeti | claim=${claimedCount} | skipped=${skippedCount} | aktif=${this.processor.processing.size}/${maxConcurrency}`
            );

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
        await this.apiClient.cleanupStuckEmailCampaigns(config.worker.workerId);
    }

    /**
     * Worker'ı durdur — aktif kampanyaların tamamlanması için kısa süre bekler.
     */
    async shutdown() {
        // Çift çağrı koruması
        if (this._shutdownPromise) return this._shutdownPromise;
        this._shutdownPromise = this._doShutdown();
        return this._shutdownPromise;
    }

    async _doShutdown() {
        console.log('');
        Logger.divider('═');
        Logger.banner('  Email Worker durduruluyor...');
        Logger.divider('═');

        this.isRunning = false;

        // Aktif kampanyalar varsa kısa süre bekle (graceful shutdown)
        const activeCount = this.processor.processing.size;
        if (activeCount > 0) {
            Logger.info(`${activeCount} aktif kampanya — tamamlanması bekleniyor (max 30sn)...`);
            const deadline = Date.now() + 30_000;
            while (this.processor.processing.size > 0 && Date.now() < deadline) {
                await this.sleep(500);
            }
            if (this.processor.processing.size > 0) {
                Logger.warn(`${this.processor.processing.size} kampanya zaman aşımı — iptal ediliyor (restart recovery ile devam eder)`);
                // cancelledCampaigns Set'ine ekle — send loop bunu görünce durur
                for (const id of this.processor.processing) {
                    this.processor.cancelledCampaigns.add(id);
                }
                await this.sleep(1000);
            } else {
                Logger.info('Tüm aktif kampanyalar tamamlandı');
            }
        }

        if (this.healthServer) {
            try { this.healthServer.close(); } catch (_) {}
        }
        await closeMysqlPool().catch(() => {});

        Logger.divider();
        Logger.info('Email Worker durduruldu');
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

