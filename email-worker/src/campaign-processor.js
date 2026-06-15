import { Logger } from './logger.js';
import { ApiClient } from './api-client.js';
import { SmtpClient } from './smtp-client.js';
import { SmtpRateLimiter } from './smtp-rate-limiter.js';
import { config } from './config.js';
import { insertBatchMetric } from './services/campaign-metric-service.js';
import { EmailValidator } from './email-validator.js';
import { SmtpHealthMonitor } from './smtp-health-monitor.js';
/** İnsan okumalı süre (ms → sn / dk / saat) */
function formatDurationMs(ms) {
    if (ms == null || !Number.isFinite(ms) || ms < 0) {
        return '—';
    }
    if (ms < 1000) {
        return `${Math.round(ms)} ms`;
    }
    const sec = ms / 1000;
    if (sec < 60) {
        return `${sec.toFixed(1)} sn`;
    }
    const m = Math.floor(sec / 60);
    const s = Math.round(sec % 60);
    if (m < 60) {
        return `${m} dk ${s} sn`;
    }
    const h = Math.floor(m / 60);
    const mi = m % 60;
    return `${h} sa ${mi} dk ${s} sn`;
}

function mailsPerSecond(count, elapsedMs) {
    if (!elapsedMs || elapsedMs < 1 || count < 1) {
        return 0;
    }
    return count / (elapsedMs / 1000);
}

/** Sonuçların DB/API’ye yazılamaması — kampanya processing durumunda kalsın (çift gönderim önlemi) */
const RESULT_PERSIST_FAILED = 'RESULT_PERSIST_FAILED';

/**
 * PERFORMANS OPTİMİZE EDİLMİŞ CAMPAIGN PROCESSOR
 * - Streaming/Pagination ile milyonlarca email işler
 * - SMTP connection pooling
 * - Memory efficient processing
 * - Bulk updates
 * - Email validation (bounce prevention)
 * - SMTP health monitoring
 */
export class CampaignProcessor {
    constructor(apiClient = null, options = {}) {
        this.apiClient = apiClient || new ApiClient();
        // Telegram bildirimler kaldırıldı
        this.emailValidator = new EmailValidator();
        this.healthMonitor = new SmtpHealthMonitor();
        this.processing = new Set();
        this.smtpPool = new Map();
        this.throttleState = new Map();
        this.rateLimiter = new SmtpRateLimiter();
        this.cancelledCampaigns = new Set();
        this._poolCleanupTimer = null;
        /** @type {import('mysql2/promise').Pool | null} */
        this.pool = options.pool ?? null;
        this.resultMode = (options.resultMode ?? config.worker.resultMode ?? 'api').toLowerCase();
        this.flushService = options.flushService ?? null;
        this.workerId = options.workerId ?? config.worker.workerId;
        this._batchSeq = 0;
    }

    _batchGapMs() {
        return this.apiClient.getWorkerBatchGapMs();
    }

    _chunkGapMs() {
        return this.apiClient.getWorkerChunkGapMs();
    }

    _sendConcurrencyPerLane() {
        return this.apiClient.getWorkerSendConcurrency();
    }

    /**
     * Kampanyayı işle (STREAM-BASED)
     */
    async processCampaign(campaign) {
        if (this.processing.has(campaign.id)) {
            Logger.debug(`Kampanya #${campaign.id} zaten işleniyor, atlandı`);
            return;
        }

        // Cancelled durumundaki kampanyaları işleme
        if (campaign.status === 'cancelled') {
            Logger.info(`Kampanya #${campaign.id} iptal edilmiş, atlandı`);
            return;
        }

        // Cancel a pending pool-cleanup so existing SMTP connections are reused
        if (this._poolCleanupTimer) {
            clearTimeout(this._poolCleanupTimer);
            this._poolCleanupTimer = null;
            Logger.debug('SMTP pool cleanup iptal edildi — yeni kampanya bağlantıları devralıyor');
        }
        this.cancelledCampaigns.delete(campaign.id);

        this.processing.add(campaign.id);
        const campaignStartedAt = Date.now();
        const subjectLine = String(campaign.subject || '')
            .replace(/\s+/g, ' ')
            .trim()
            .slice(0, 72);

        console.log('');
        Logger.divider('─');
        Logger.banner(`  ▶  Kampanya #${campaign.id}  ·  ${campaign.total_emails.toLocaleString()} mail`);
        if (subjectLine) {
            Logger.banner(`     ${subjectLine}${String(campaign.subject || '').length > 72 ? '…' : ''}`);
        }
        Logger.divider('─');

        try {
            const tStatus = Date.now();
            await this.apiClient.updateEmailCampaignStatus(campaign.id, 'processing');
            Logger.info(`Durum → processing  (${formatDurationMs(Date.now() - tStatus)})`);

            const tBl = Date.now();
            const blacklistSet = await this.apiClient.getEmailBlacklist();
            Logger.info(`Karaliste  ${blacklistSet.size.toLocaleString()} adres  (${formatDurationMs(Date.now() - tBl)})`);

            const campaignPercentage = campaign.delivery_percentage || 100;
            const userPercentage = campaign.email_delivery_percentage || 100;
            const finalPercentage = Math.round((campaignPercentage * userPercentage) / 100);

            const totalPending = campaign.pending_emails || campaign.total_emails;
            const realCount = Math.floor((totalPending * finalPercentage) / 100);
            const fakeCount = totalPending - realCount;

            Logger.info(`Kota  gerçek ${realCount.toLocaleString()}  ·  sahte ${fakeCount.toLocaleString()}  ·  oran %${finalPercentage}`);

            const wr = this.apiClient.workerRuntime;
            if (wr && wr.rate_per_second_effective != null) {
                const rp = Number(wr.rate_per_second_effective);
                if (Number.isFinite(rp) && rp > 0) {
                    const estSec = realCount / rp;
                    Logger.info(`Hedef hız  ~${rp.toFixed(2)} mail/sn  →  tahmini ${formatDurationMs(estSec * 1000)}`);
                }
            }

            // STREAMING PROCESSING: Batch batch işle
            let processedCount = 0;
            let successCount = 0;
            let failedCount = 0;
            let blacklistedCount = 0; // Karaliste skip sayısı
            let invalidEmailCount = 0; // Geçersiz email sayısı
            const fetchBatchSize = this.apiClient.getWorkerFetchBatchSize();
            let lastPoolCursor = campaign.last_pool_id || null;

            // Prefetch pipeline: fetch N+1 while sending N.
            // Pending list shrinks with each batch (offset=0 always), so we always
            // pass the latest cursor after updating it from the response.
            const startFetch = (cursor) =>
                this.apiClient.getEmailCampaignEmailsBatch(campaign.id, 0, fetchBatchSize, cursor);
            let prefetchPromise = startFetch(lastPoolCursor);

            while (true) {
                // Cancel check (set externally via this.cancelledCampaigns)
                if (this.cancelledCampaigns.has(campaign.id)) {
                    Logger.info(`Kampanya #${campaign.id} iptal sinyali alındı — loop durduruluyor`);
                    break;
                }

                const tFetch = Date.now();
                const batchData = await prefetchPromise;
                const fetchMs = Date.now() - tFetch;
                const prefetched = fetchMs < 20; // near-zero = prefetch finished while we were sending

                if (!batchData) {
                    Logger.error(`Batch alınamadı (Kampanya #${campaign.id}, işlenen=${processedCount}) — API hatası`);
                    throw new Error(`API batch failed: Kampanya #${campaign.id} için email batch alınamadı`);
                }
                if (batchData.emails.length === 0) {
                    const apiTotalPending = batchData.total_pending ?? 0;
                    if (apiTotalPending > 0) {
                        Logger.error(`API boş batch döndü ama total_pending=${apiTotalPending} (Kampanya #${campaign.id}) — backend sorunu`);
                        throw new Error(`Empty batch but ${apiTotalPending} pending: Kampanya #${campaign.id}`);
                    }
                    Logger.info('Tüm emailler işlendi — pending kalmadı');
                    break;
                }

                const emails = batchData.emails;
                if (batchData.next_cursor) {
                    lastPoolCursor = batchData.next_cursor;
                }

                // Start fetching the next batch immediately (overlaps with current batch processing)
                prefetchPromise = startFetch(lastPoolCursor);

                Logger.info(
                    `Batch  ${emails.length.toLocaleString()} adres  ·  çekim ${prefetched ? `${fetchMs}ms (önceden)` : formatDurationMs(fetchMs)}`
                );

                // EMAIL VALIDATION (BOUNCE ÖNLEMİ)
                const validationResult = await this.emailValidator.validateBatch(emails, {
                    checkMX: false,
                    autoCorrect: true,
                    skipDisposable: true
                });

                if (validationResult.invalid.length > 0) {
                    Logger.warn(`Geçersiz email: ${validationResult.invalid.length} adres atlandı`);
                    const invalidResults = validationResult.invalid.map(email => ({
                        email: email.email,
                        status: 'failed',
                        error: email.reason
                    }));
                    const invalidUpdateSuccess = await this.apiClient.updateEmailCampaignEmails(campaign.id, invalidResults);
                    if (!invalidUpdateSuccess) {
                        throw new Error(`${RESULT_PERSIST_FAILED}: ${invalidResults.length} geçersiz email sonucu kaydedilemedi`);
                    }
                    failedCount += invalidResults.length;
                    invalidEmailCount += invalidResults.length;
                }

                // BLACKLIST KONTROLÜ
                let validEmails = validationResult.valid;
                const blacklistedEmails = [];
                const cleanEmails = [];
                for (const emailObj of validEmails) {
                    if (blacklistSet.has(emailObj.email.toLowerCase())) {
                        blacklistedEmails.push(emailObj);
                    } else {
                        cleanEmails.push(emailObj);
                    }
                }
                if (blacklistedEmails.length > 0) {
                    Logger.info(`Karaliste: ${blacklistedEmails.length} adres atlandı`);
                    const blacklistResults = blacklistedEmails.map(email => ({
                        email: email.email,
                        status: 'skipped_blacklist',
                        error: 'Email karalistede'
                    }));
                    const blacklistUpdateSuccess = await this.apiClient.updateEmailCampaignEmails(campaign.id, blacklistResults);
                    if (!blacklistUpdateSuccess) {
                        throw new Error(`${RESULT_PERSIST_FAILED}: ${blacklistResults.length} blacklist email sonucu kaydedilemedi`);
                    }
                    blacklistedCount += blacklistedEmails.length;
                }
                validEmails = cleanEmails;

                if (validEmails.length === 0) {
                    Logger.warn('Batch\'de gönderilecek email kalmadı (blacklist/invalid) — sonraki batch\'e geçiliyor');
                    processedCount += emails.length;
                    continue;
                }

                const batchRealCount = Math.min(
                    Math.floor((validEmails.length * finalPercentage) / 100),
                    realCount - successCount
                );
                const { realEmails, fakeEmails } = this.splitEmailsForBatch(validEmails, batchRealCount);

                if (fakeEmails.length > 0) {
                    const fakeResults = fakeEmails.map(email => ({
                        email: email.email,
                        status: 'delivered',
                        message_id: `fake_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`
                    }));
                    const fakeUpdateSuccess = await this.apiClient.updateEmailCampaignEmails(campaign.id, fakeResults);
                    if (!fakeUpdateSuccess) {
                        throw new Error(`${RESULT_PERSIST_FAILED}: ${fakeResults.length} fake email sonucu kaydedilemedi`);
                    }
                }

                if (realEmails.length > 0) {
                    const results = await this.sendBatchEmails(campaign, realEmails);
                    successCount += results.filter(r => r.status === 'delivered').length;
                    failedCount += results.filter(r => r.status === 'failed').length;
                }

                processedCount += emails.length;

                const progress = ((processedCount / totalPending) * 100).toFixed(1);
                const elapsedCamp = Date.now() - campaignStartedAt;
                const overallMps = mailsPerSecond(processedCount, elapsedCamp);
                const remaining = Math.max(0, totalPending - processedCount);
                const etaStr = overallMps > 0.02 && remaining > 0
                    ? `~${formatDurationMs((remaining / overallMps) * 1000)}`
                    : '—';
                Logger.info(
                    `↑ ${processedCount.toLocaleString()}/${totalPending.toLocaleString()} (%${progress})  ·  ${overallMps.toFixed(2)} mail/sn  ·  kalan ${etaStr}`
                );

                if ((batchData.total_pending ?? 0) > emails.length) {
                    await this.sleep(this._batchGapMs());
                }
            }

            // Kampanya tamamlandı - ama önce gerçekten tüm pending'ler bitti mi kontrol et
            const finalCheck = await this.apiClient.getEmailCampaignEmailsBatch(campaign.id, 0, 1);
            const remainingPending = finalCheck ? finalCheck.total_pending : 0;
            
            if (remainingPending > 0) {
                Logger.warn(`Kampanya #${campaign.id} — ${remainingPending} pending email kaldı, "processing" olarak bırakıldı`);
                Logger.warn('Worker tekrar çalışınca kalan emailler gönderilecek');
                await this.apiClient.updateEmailCampaignStatus(campaign.id, 'processing', {
                    sent_count: successCount,
                    failed_count: failedCount,
                    delivered_count: successCount
                });
                return;
            }
            
            // Tüm email'ler işlendi, şimdi completed yapabiliriz
            await this.apiClient.updateEmailCampaignStatus(campaign.id, 'completed', {
                sent_count: successCount,
                failed_count: failedCount,
                delivered_count: successCount
            });

            const totalCampMs = Date.now() - campaignStartedAt;
            const avgMps = mailsPerSecond(processedCount, totalCampMs);

            console.log('');
            Logger.divider('─');
            Logger.banner(`  ✓  Kampanya #${campaign.id} tamamlandı  ·  ${formatDurationMs(totalCampMs)}`);
            Logger.divider('─');
            Logger.kv('Gönderildi',   `${successCount.toLocaleString()}  (${avgMps.toFixed(2)} mail/sn)`);
            Logger.kv('Başarısız',    failedCount.toLocaleString());
            Logger.kv('Karaliste',    blacklistedCount.toLocaleString());
            Logger.kv('Geçersiz',     invalidEmailCount.toLocaleString());
            Logger.kv('Toplam işlen', processedCount.toLocaleString());
            Logger.divider();
            
            // SMTP health report
            this.healthMonitor.logStats();

        } catch (error) {
            const keepProcessing =
                error.message.includes('No SMTP available')
                || error.message.startsWith(RESULT_PERSIST_FAILED);

            if (keepProcessing) {
                if (error.message.startsWith(RESULT_PERSIST_FAILED)) {
                    Logger.warn(`Kampanya #${campaign.id} duraklatıldı — sonuç kaydı başarısız (API/DB)`);
                    Logger.warn('Sorun giderilince pending kayıtlar tekrar işlenecek');
                } else {
                    Logger.warn(`Kampanya #${campaign.id} duraklatıldı — SMTP günlük limiti doldu`);
                    Logger.warn('Limitler resetlenince worker otomatik devam edecek');
                }
            } else {
                Logger.error(`Kampanya #${campaign.id} başarısız: ${error.message}`);
                await this.apiClient.updateEmailCampaignStatus(campaign.id, 'failed');
            }
        } finally {
            this.processing.delete(campaign.id);

            // Aynı anda birden fazla kampanya işlenirken global pool'u kapatmak,
            // diğer kampanyalarda "Connection pool was closed" hatası üretir.
            // Bu yüzden yalnızca aktif kampanya kalmadığında temizle.
            if (this.processing.size === 0) {
                this.cleanupSmtpPool();
            } else {
                Logger.debug(`SMTP pool korunuyor (aktif kampanya: ${this.processing.size})`);
            }
        }
    }

    /**
     * Batch için email'leri real/fake olarak ayır (memory efficient)
     */
    splitEmailsForBatch(emails, realCount) {
        if (realCount >= emails.length) {
            return { realEmails: emails, fakeEmails: [] };
        }

        if (realCount <= 0) {
            return { realEmails: [], fakeEmails: emails };
        }

        // Shuffle ve split (küçük batch'ler için güvenli)
        const shuffled = [...emails].sort(() => Math.random() - 0.5);
        
        return {
            realEmails: shuffled.slice(0, realCount),
            fakeEmails: shuffled.slice(realCount)
        };
    }

    /**
     * Batch email'leri gönder (SMTP POOLING ile)
     */
    async sendBatchEmails(campaign, emails) {
        const results = [];
        const sendBatchSize = this.apiClient.getWorkerSendBatchSize();
        const batches = this.chunkArray(emails, sendBatchSize);

        for (let i = 0; i < batches.length; i++) {
            const batch = batches[i];
            
            try {
                const batchResults = await this.processSingleBatch(campaign, batch);
                results.push(...batchResults);
            } catch (error) {
                Logger.error(`Batch ${i + 1} hatası: ${error.message}`);
                
                // ✅ Eğer SMTP yoksa (günlük limit aşıldı), hatayı yukarı fırlat
                // Kampanya "processing" kalsın, worker dursun
                if (error.message.includes('No SMTP available')) {
                    Logger.error('⏸️  Tüm SMTP hesapları günlük limitlerini aştı');
                    Logger.error('🔄 Kampanya "processing" durumunda kalacak ve bir sonraki çalıştırmada devam edecek');
                    throw error; // Hatayı yukarı fırlat
                }
                
                // Sonuç yazımı hatası — kampanya processing kalsın
                if (error.message.startsWith(RESULT_PERSIST_FAILED)) {
                    Logger.error('❌ Sonuç kaydı başarısız — Kampanya processing durumunda kalacak');
                    throw error; // Hatayı yukarı fırlat
                }
                
                // Diğer hatalar için batch'i failed yap (SMTP hatası vs.)
                const failedResults = batch.map(email => ({
                    email: email.email,
                    status: 'failed',
                    error: error.message
                }));
                results.push(...failedResults);
            }

            // Batch'ler arası kısa bekleme (ana rate limiting processSingleBatch içinde)
            if (i < batches.length - 1) {
                await this.sleep(this._chunkGapMs());
            }
        }

        return results;
    }

    /**
     * Tek batch işle (SMTP CONNECTION POOL ile + HEALTH MONITORING)
     * Paralel lane kurulumu: önce pool'dan al, kalan slotlar için batch API çağrısı + Promise.all connect
     */
    async processSingleBatch(campaign, batch) {
        const maxLanes = this.apiClient.getWorkerMaxSmtpLanes();
        const desiredLaneCount = Math.max(1, Math.min(maxLanes, batch.length));
        const smtpLanes = [];
        const excludedIds = new Set();
        const SAFETY_BUFFER = maxLanes;

        // 1. Pool'dan mevcut bağlantıları al (ağ maliyeti yok)
        for (const [smtpId, connection] of this.smtpPool.entries()) {
            if (smtpLanes.length >= desiredLaneCount) break;
            if (!connection.isActive || !connection.client.isConnected) continue;

            const dailyRemaining  = (connection.config.daily_limit  || 999999) - (connection.config.daily_sent  || 0);
            const hourlyRemaining = (connection.config.hourly_limit || 999999) - (connection.config.hourly_sent || 0);
            const minuteRemaining = (connection.config.minute_limit || 999999) - (connection.config.minute_sent || 0);

            if (dailyRemaining <= SAFETY_BUFFER || hourlyRemaining <= SAFETY_BUFFER || minuteRemaining <= SAFETY_BUFFER) {
                const which = dailyRemaining <= SAFETY_BUFFER ? 'günlük' : hourlyRemaining <= SAFETY_BUFFER ? 'saatlik' : 'dakikalık';
                Logger.warn(`SMTP #${smtpId} ${which} limiti dolmak üzere — pool'dan çıkarılıyor`);
                this.smtpPool.delete(smtpId);
                try { connection.client.close(); } catch (_) {}
                continue;
            }

            excludedIds.add(Number(smtpId));
            smtpLanes.push(connection);
            Logger.debug(`SMTP #${smtpId} pool'dan alındı (günlük kalan ${dailyRemaining})`);
        }

        // 2. Eksik slotları tek API çağrısıyla al, tümünü eş zamanlı bağla
        const remainingSlots = desiredLaneCount - smtpLanes.length;
        if (remainingSlots > 0) {
            const excludeArray = Array.from(excludedIds);
            const newConfigs = await this.apiClient.selectBestSmtpBatch(remainingSlots, excludeArray);

            if (newConfigs.length > 0) {
                const tConnect = Date.now();
                const connectResults = await Promise.all(
                    newConfigs.map(async (smtpConfig) => {
                        const smtpClient = new SmtpClient(smtpConfig);
                        const connected = await smtpClient.connect();
                        if (!connected) {
                            Logger.warn(`SMTP #${smtpConfig.id} bağlantısı başarısız`);
                            return null;
                        }
                        const connection = {
                            config: smtpConfig,
                            client: smtpClient,
                            isActive: true,
                            createdAt: Date.now(),
                        };
                        this.smtpPool.set(smtpConfig.id, connection);
                        return connection;
                    })
                );
                const connectMs = Date.now() - tConnect;
                const connected = connectResults.filter(Boolean);
                if (connected.length > 0) {
                    Logger.info(`${connected.length} SMTP eş zamanlı bağlandı — ${formatDurationMs(connectMs)} (pool: ${this.smtpPool.size})`);
                    for (const conn of connected) {
                        smtpLanes.push(conn);
                        excludedIds.add(conn.config.id);
                    }
                }
            } else {
                // Fallback: batch endpoint henüz sunucuda yok — tekil seçime düş
                Logger.warn('selectBestSmtpBatch boş döndü — tekil SMTP seçimine düşülüyor (sunucu güncellemesi gerekiyor olabilir)');
                for (let i = 0; i < remainingSlots; i++) {
                    const lane = await this.getOrCreateSmtpConnection(Array.from(excludedIds));
                    if (!lane || !lane.client) break;
                    if (excludedIds.has(lane.config.id)) break;
                    excludedIds.add(lane.config.id);
                    smtpLanes.push(lane);
                }
            }
        }

        if (smtpLanes.length === 0) {
            Logger.error('❌ SMTP bağlantısı alınamadı (Günlük limitler aşılmış olabilir)');
            Logger.warn('⏸️  Kampanya #' + campaign.id + ' processing durumunda kalacak');
            throw new Error('No SMTP available - Daily limits may be exceeded. Waiting for next run...');
        }

        Logger.info(`SMTP lane sayisi  : ${smtpLanes.length}`);

        // Email'leri lane'lere round-robin dağıt
        const laneBatches = smtpLanes.map(() => []);
        for (let i = 0; i < batch.length; i++) {
            laneBatches[i % smtpLanes.length].push(batch[i]);
        }

        const tSmtp = Date.now();
        const lanePromises = smtpLanes.map((lane, index) => this.sendEmailsWithLane(campaign, lane, laneBatches[index]));
        const laneResults = await Promise.all(lanePromises);
        const results = laneResults.flat();
        const smtpMs = Date.now() - tSmtp;

        const tPersist = Date.now();
        let persistMs = 0;

        if (results.length > 0) {
            if (this.resultMode === 'direct-db' && this.flushService) {
                try {
                    const fr = await this.flushService.flushCampaignResults(
                        campaign.id,
                        campaign.user_id ?? null,
                        results
                    );
                    persistMs = fr.durationMs;
                } catch (e) {
                    Logger.error(`❌ DB flush başarısız (Kampanya #${campaign.id}): ${e.message}`);
                    throw new Error(`${RESULT_PERSIST_FAILED}: ${results.length} email sonucu DB’ye yazılamadı`);
                }

                if (this.pool) {
                    this._batchSeq += 1;
                    const deliveredN = results.filter((r) => r.status === 'delivered').length;
                    const failedN = results.filter((r) => r.status === 'failed').length;
                    const fails = results.filter((r) => r.status === 'failed');
                    const topErr = fails[0];
                    const rej = fails.filter((r) => String(r.error || '').includes('559') || String(r.error || '').includes('invaddr')).length;
                    const connErr = fails.filter((r) => String(r.error || '').includes('ECONNRESET')).length;

                    insertBatchMetric(this.pool, {
                        campaignId: campaign.id,
                        workerId: this.workerId,
                        batchNo: this._batchSeq,
                        batchSize: batch.length,
                        successCount: deliveredN,
                        failedCount: failedN,
                        retryCount: 0,
                        recipientRejectedCount: rej,
                        connectionErrorCount: connErr,
                        providerErrorCount: 0,
                        internalErrorCount: 0,
                        smtpDurationMs: smtpMs,
                        dbFlushDurationMs: persistMs,
                        totalDurationMs: smtpMs + persistMs,
                        queueWaitMs: 0,
                        laneCount: smtpLanes.length,
                        smtpAccountCount: new Set(results.map((r) => r.smtp_id).filter(Boolean)).size,
                        topErrorCode: topErr ? 'smtp_fail' : null,
                        topErrorMessage: topErr?.error || null,
                        startedAt: new Date(tSmtp),
                        finishedAt: new Date(),
                    }).catch(() => {});
                }
            } else {
                const updateSuccess = await this.apiClient.updateEmailCampaignEmails(campaign.id, results);

                if (!updateSuccess) {
                    Logger.error(`❌ API'ye email sonuçları kaydedilemedi (Kampanya #${campaign.id}, ${results.length} email)`);
                    Logger.error("Email'ler gönderildi ama veritabanına kaydedilemedi - pending olarak kalacaklar");
                    Logger.error("Bu durumda email'ler tekrar gönderilmeye çalışılacak - dikkatli olun!");
                    throw new Error(`${RESULT_PERSIST_FAILED}: ${results.length} email sonucu panel API ile kaydedilemedi`);
                }
                persistMs = Date.now() - tPersist;
            }
        } else {
            Logger.warn(`⚠️  Boş sonuç listesi (Kampanya #${campaign.id}) — persist atlandı`);
        }

        // SMTP kullanımını kaydet (İSTATİSTİKLER DATABASE'E YAZILIR)
        // Not: Eğer SMTP değiştiyse, her SMTP için ayrı ayrı kaydetmemiz gerekir
        // Şimdilik son kullanılan SMTP için kaydediyoruz (basit yaklaşım)
        const successCount = results.filter(r => r.status === 'delivered').length;
        const failedCount = results.filter(r => r.status === 'failed').length;
        
        // Her SMTP lane için kullanım istatistiğini ayrı kaydet
        for (const lane of smtpLanes) {
            const laneItems = results.filter(item => item.smtp_id === lane.config.id);
            if (laneItems.length === 0) {
                continue;
            }

            const laneSuccessCount = laneItems.filter(item => item.status === 'delivered').length;
            const laneFailedCount = laneItems.filter(item => item.status === 'failed').length;

            const usageStats = await this.apiClient.recordSmtpUsage(lane.config.id, {
                event_key: this.createUsageEventKey(campaign.id, lane.config.id, laneItems),
                success_count: laneSuccessCount,
                failed_count: laneFailedCount,
                error_message: laneFailedCount > 0 ? `${laneFailedCount} emails failed` : null
            });

            if (this.smtpPool.has(lane.config.id) && usageStats) {
                const poolConnection = this.smtpPool.get(lane.config.id);
                if (usageStats.smtp_daily_sent !== undefined) {
                    poolConnection.config.daily_sent = usageStats.smtp_daily_sent;
                }
                if (usageStats.smtp_hourly_sent !== undefined) {
                    poolConnection.config.hourly_sent = usageStats.smtp_hourly_sent;
                }
                if (usageStats.smtp_minute_sent !== undefined) {
                    poolConnection.config.minute_sent = usageStats.smtp_minute_sent;
                }
                if (usageStats.total_sent !== undefined) {
                    poolConnection.config.total_sent = usageStats.total_sent;
                }
            }
        }

        const persistLabel = this.resultMode === 'direct-db' && this.flushService ? 'db' : 'api';
        const delivered = results.filter((r) => r.status === 'delivered').length;
        const failed    = results.filter((r) => r.status === 'failed').length;
        const smtpMps   = smtpMs >= 1 && batch.length > 0 ? batch.length / (smtpMs / 1000) : 0;
        Logger.info(
            `Send  ${batch.length} mail  ·  SMTP ${formatDurationMs(smtpMs)}${smtpMps > 0 ? ` (${smtpMps.toFixed(1)}/sn)` : ''}  ·  ${persistLabel} ${formatDurationMs(persistMs)}  ·  ✓ ${delivered}  ✗ ${failed}`
        );

        return results;
    }

    async sendEmailsWithLane(campaign, smtp, emails) {
        if (!emails || emails.length === 0) {
            return [];
        }

        const results = [];
        const isAlibaba = smtp.config.host && (
            smtp.config.host.includes('aliyuncs.com') ||
            smtp.config.host.includes('alibaba') ||
            smtp.config.host.includes('dm.aliyun')
        );

        const fromEmail = smtp.config.from_email;
        const appTitle = process.env.SITE_TITLE || 'Nexus Panel';
        const fromNameBase = smtp.config.from_name || campaign.from_name || appTitle;

        const concurrency = Math.min(
            this._sendConcurrencyPerLane(),
            emails.length
        );
        const minuteLimit = smtp.config.minute_limit || 0;
        const smtpTag = `SMTP #${smtp.config.id}`;

        const sendOnly = async (emailData) => {
            const result = await smtp.client.sendEmail(
                emailData.email,
                campaign.subject,
                campaign.body,
                fromNameBase,
                fromEmail
            );
            return { emailData, result };
        };

        for (let i = 0; i < emails.length; i += concurrency) {
            // Cancel check per chunk
            if (this.cancelledCampaigns.has(campaign.id)) {
                Logger.info(`Kampanya #${campaign.id} iptal — ${smtpTag} lane durduruluyor`);
                break;
            }

            const chunk = emails.slice(i, i + concurrency);

            // Token bucket: dakikalık limiti aşmadan önce blokla
            if (minuteLimit > 0) {
                const tWait = Date.now();
                await this.rateLimiter.consume(smtp.config.id, chunk.length, minuteLimit);
                const waitedMs = Date.now() - tWait;
                if (waitedMs > 100) {
                    Logger.info(`${smtpTag} dakikalık limit beklemesi: ${formatDurationMs(waitedMs)} (limit=${minuteLimit}/dk)`);
                }
            }

            const ratePerSecond = this.getEffectiveRatePerSecond(
                smtp.config.id,
                smtp.config.rate_per_second || 1,
                isAlibaba,
                smtp.config.total_sent || 0
            );

            const settled = await Promise.all(chunk.map((emailData) => sendOnly(emailData)));

            for (const { emailData, result } of settled) {
                if (!result.success) {
                    Logger.warn(`${smtpTag} gönderilemedi (${emailData.email}): ${result.error || 'Bilinmeyen hata'}`);
                }

                if (!result.success && result.error && result.error.includes('throttling')) {
                    this.markThrottleFailure(smtp.config.id);
                    Logger.warn(`${smtpTag} throttle hatası — 5sn beklenecek, hız düşürülüyor`);
                    await this.sleep(5000);
                } else if (result.success) {
                    this.markThrottleSuccess(smtp.config.id);
                }

                const isBounce = result.error && result.error.includes('bounce');
                this.healthMonitor.recordEmail(smtp.config.id, result.success, isBounce);

                results.push({
                    id: emailData.id != null ? Number(emailData.id) : null,
                    email: emailData.email,
                    status: result.success ? 'delivered' : 'failed',
                    message_id: result.messageId || null,
                    error: result.error || null,
                    smtp_id: smtp.config.id,
                });
            }

            if (i + chunk.length < emails.length) {
                const pauseMs = ratePerSecond > 0
                    ? Math.max(0, Math.ceil((1000 * chunk.length) / ratePerSecond))
                    : 0;
                if (pauseMs > 0) {
                    await this.sleep(pauseMs);
                }
            }
        }

        const sent = results.filter(r => r.status === 'delivered').length;
        const failed = results.length - sent;
        Logger.debug(`${smtpTag} lane özeti: ✓ ${sent} · ✗ ${failed} · toplam ${results.length}`);
        return results;
    }

    /**
     * SMTP connection pool'dan al veya yeni oluştur
     */
    async getOrCreateSmtpConnection(excludedSmtpIds = []) {
        // Pool'da active connection var mı kontrol et
        const SAFETY_BUFFER = this.apiClient.getWorkerMaxSmtpLanes();

        for (const [smtpId, connection] of this.smtpPool.entries()) {
            if (excludedSmtpIds.includes(Number(smtpId))) {
                continue;
            }

            if (connection.isActive && connection.client.isConnected) {
                // ✅ LİMİT KONTROLÜ - Günlük, saatlik, dakikalık limit kontrolü + BUFFER
                const dailyLimit = connection.config.daily_limit || 999999;
                const dailySent = connection.config.daily_sent || 0;
                const hourlyLimit = connection.config.hourly_limit || 999999;
                const hourlySent = connection.config.hourly_sent || 0;
                const minuteLimit = connection.config.minute_limit || 999999;
                const minuteSent = connection.config.minute_sent || 0;

                const dailyRemaining = dailyLimit - dailySent;
                const hourlyRemaining = hourlyLimit - hourlySent;
                const minuteRemaining = minuteLimit - minuteSent;

                // Buffer dahil limit kontrolü (concurrent gönderim için)
                if (dailyRemaining <= SAFETY_BUFFER) {
                    Logger.warn(`⚠️  SMTP #${smtpId} günlük limiti dolmak üzere (${dailySent}/${dailyLimit}, kalan: ${dailyRemaining}), pool'dan çıkarılıyor`);
                    this.smtpPool.delete(smtpId);
                    connection.client.close();
                    continue;
                }

                if (hourlyRemaining <= SAFETY_BUFFER) {
                    Logger.warn(`⚠️  SMTP #${smtpId} saatlik limiti dolmak üzere (${hourlySent}/${hourlyLimit}, kalan: ${hourlyRemaining}), pool'dan çıkarılıyor`);
                    this.smtpPool.delete(smtpId);
                    connection.client.close();
                    continue;
                }

                if (minuteRemaining <= SAFETY_BUFFER) {
                    Logger.warn(`⚠️  SMTP #${smtpId} dakikalık limiti dolmak üzere (${minuteSent}/${minuteLimit}, kalan: ${minuteRemaining}), pool'dan çıkarılıyor`);
                    this.smtpPool.delete(smtpId);
                    connection.client.close();
                    continue;
                }

                Logger.debug(`SMTP #${smtpId} pool'dan alındı | günlük ${dailyRemaining}/${dailyLimit} | saatlik ${hourlyRemaining}/${hourlyLimit} | dakikalık ${minuteRemaining}/${minuteLimit}`);
                return connection;
            }
        }

        // Yeni SMTP al
        const excluded = Array.from(new Set(excludedSmtpIds.map(id => Number(id))))
            .filter(id => Number.isFinite(id) && id > 0);
        const smtpConfig = await this.apiClient.selectBestSmtp(excluded);

        if (!smtpConfig) {
            if (excluded.length > 0) {
                Logger.info(
                    `Ek SMTP yok: panelde yeterli farklı aktif hesap yok (şu an ${excluded.length} hesap bu batch için kullanılıyor). Bu normal; daha az lane ile devam edilir.`
                );
            } else {
                Logger.error('Uygun SMTP bulunamadı (aktif hesap yok veya günlük/saatlik/dakikalık plan limiti dolmuş olabilir)');
            }
            return null;
        }

        Logger.info(`SMTP #${smtpConfig.id} seçildi: ${smtpConfig.host}:${smtpConfig.port} (${smtpConfig.username})`);

        const smtpClient = new SmtpClient(smtpConfig);
        const connected = await smtpClient.connect();

        if (!connected) {
            await this.apiClient.recordSmtpUsage(smtpConfig.id, false, 'Connection failed');
            Logger.warn(`SMTP #${smtpConfig.id} bağlantısı başarısız`);
            return null;
        }

        const connection = {
            config: smtpConfig,
            client: smtpClient,
            isActive: true,
            createdAt: Date.now()
        };
        this.smtpPool.set(smtpConfig.id, connection);
        Logger.info(`SMTP #${smtpConfig.id} pool'a eklendi (toplam: ${this.smtpPool.size})`);

        return connection;
    }

    /**
     * SMTP pool'u temizle — 5 saniyelik grace period ile.
     * Yeni bir kampanya başlarsa timer iptal edilir ve bağlantılar korunur.
     */
    cleanupSmtpPool(graceMs = 5000) {
        if (this._poolCleanupTimer) {
            clearTimeout(this._poolCleanupTimer);
        }
        const count = this.smtpPool.size;
        if (count === 0) return;

        Logger.debug(`SMTP pool temizleme planlandı: ${count} bağlantı ${graceMs}ms içinde kapatılacak`);

        this._poolCleanupTimer = setTimeout(() => {
            this._poolCleanupTimer = null;
            if (this.processing.size > 0) {
                // A new campaign started during grace period — keep connections
                Logger.debug('SMTP pool temizleme atlandı — aktif kampanya var');
                return;
            }
            let closed = 0;
            for (const [smtpId, connection] of this.smtpPool.entries()) {
                try {
                    if (connection.client) connection.client.close();
                    closed++;
                } catch (err) {
                    Logger.warn(`SMTP #${smtpId} kapatılırken hata: ${err.message}`);
                }
                this.smtpPool.delete(smtpId);
            }
            this.rateLimiter.resetAll();
            Logger.info(`SMTP pool temizlendi: ${closed} bağlantı kapatıldı`);
        }, graceMs);
    }

    /**
     * Array'i chunk'lara böl
     */
    chunkArray(array, size) {
        const chunks = [];
        for (let i = 0; i < array.length; i += size) {
            chunks.push(array.slice(i, i + size));
        }
        return chunks;
    }

    getEffectiveRatePerSecond(smtpId, planRate, isAlibaba, dbTotalSent = 0) {
        const providerRate = isAlibaba
            ? this.healthMonitor.getAlibabaWarmupRateCap(
                dbTotalSent,
                this.apiClient.getWorkerAlibabaWarmupMaxRatePerSecond()
            )
            : planRate;
        const cap = Math.min(planRate, providerRate);
        const minRate = Math.max(0.2, Math.min(planRate * 0.08, cap * 0.35));

        const prev = this.throttleState.get(smtpId);
        let state = prev;
        if (!state || state.rateCap !== planRate) {
            // Başlangıcı tavana yakın tut; yavaş kademeli rampa yerine hızlı çıkış
            const start = prev?.currentRate != null
                ? Math.min(cap, Math.max(minRate, prev.currentRate))
                : Math.min(cap, Math.max(minRate, cap * 0.92));
            state = {
                currentRate: start,
                cooldownUntil: prev?.cooldownUntil ?? 0,
                rateCap: planRate,
            };
        }

        const now = Date.now();
        if (state.cooldownUntil > now) {
            const cooldownRate = Math.max(minRate, state.currentRate / 2);
            this.throttleState.set(smtpId, { ...state, currentRate: cooldownRate });
            return cooldownRate;
        }

        const effectiveRate = Math.max(minRate, Math.min(cap, state.currentRate));
        this.throttleState.set(smtpId, { ...state, currentRate: effectiveRate });
        return effectiveRate;
    }

    markThrottleFailure(smtpId) {
        const prev = this.throttleState.get(smtpId) || { currentRate: 0.4, cooldownUntil: 0, rateCap: 1 };
        const minRate = Math.max(0.05, (prev.rateCap || 1) * 0.05);
        const cooldownMs = this.apiClient.getWorkerThrottleCooldownMs();
        this.throttleState.set(smtpId, {
            currentRate: Math.max(minRate, prev.currentRate / 2),
            cooldownUntil: Date.now() + cooldownMs,
            rateCap: prev.rateCap || 1,
        });
    }

    markThrottleSuccess(smtpId) {
        const prev = this.throttleState.get(smtpId) || { currentRate: 0.4, cooldownUntil: 0, rateCap: 1 };
        const maxRate = prev.rateCap || 1;
        const baseStep = this.apiClient.getWorkerThrottleStepUpPerSecond();
        // Yüksek plan hızında tek tek +0.05 ile tavana çıkmak günler sürer; plana göre büyük adım
        const scaledStep = Math.max(baseStep, Math.min(maxRate * 0.08, 3));
        const stepUp = scaledStep;
        if (prev.cooldownUntil > Date.now()) {
            return;
        }
        this.throttleState.set(smtpId, {
            currentRate: Math.min(maxRate, prev.currentRate + stepUp),
            cooldownUntil: 0,
            rateCap: maxRate,
        });
    }

    createUsageEventKey(campaignId, smtpId, results) {
        const seed = results
            .map(item => `${item.email}:${item.status}`)
            .sort()
            .join('|');

        let hash = 0;
        for (let i = 0; i < seed.length; i++) {
            hash = ((hash << 5) - hash) + seed.charCodeAt(i);
            hash |= 0;
        }

        return `usage-${campaignId}-${smtpId}-${Math.abs(hash)}`;
    }

    /**
     * Sleep utility
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

