import axios from 'axios';
import { config } from './config.js';
import { Logger } from './logger.js';

/** DNS kesintisi, timeout vb. — kısa süre sonra tekrar denenebilir */
export function isTransientNetworkError(error) {
    const code = error?.code || '';
    if (['EAI_AGAIN', 'ECONNRESET', 'ETIMEDOUT', 'ENOTFOUND', 'ECONNREFUSED', 'EPIPE', 'ENETUNREACH', 'EHOSTUNREACH', 'ECONNABORTED'].includes(code)) {
        return true;
    }
    const msg = String(error?.message || '').toLowerCase();
    return msg.includes('eai_again')
        || msg.includes('getaddrinfo')
        || msg.includes('socket hang up')
        || msg.includes('network error')
        || msg.includes('failed to resolve ipv4')
        || msg.includes('failed to resolve')
        || (msg.includes('timeout') && !error?.response);
}

function sleepMs(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

export class ApiClient {
    constructor() {
        this.baseUrl = config.api.baseUrl;
        this.timeout = config.api.timeout;
        this.batchTimeout = Math.max(
            config.api.timeout || 30000,
            config.api.batchTimeout || 300000
        );
        this.apiToken = config.api.token;
        this.errorThrottleMs = Math.max(5000, parseInt(process.env.WORKER_ERROR_THROTTLE_MS || '60000', 10) || 60000);
        this.lastErrorLogAt = new Map();
        /** Panelden gelen worker ayarları (runtime-config veya select-best) */
        this.workerRuntime = null;
        /** Blacklist TTL cache: { data: Set<string>, expiresAt: number } | null */
        this._blacklistCache = null;
        this._blacklistTtlMs = parseInt(process.env.BLACKLIST_CACHE_TTL_MS || String(60 * 60 * 1000), 10);

        this.client = axios.create({
            baseURL: this.baseUrl,
            timeout: this.timeout,
            headers: {
                'Content-Type': 'application/json',
                'X-Api-Token': this.apiToken,
            }
        });
    }

    logThrottledError(key, message) {
        const now = Date.now();
        const lastAt = this.lastErrorLogAt.get(key) || 0;
        const elapsed = now - lastAt;
        if (elapsed >= this.errorThrottleMs) {
            this.lastErrorLogAt.set(key, now);
            Logger.error(message);
            return;
        }
        const remainingSec = Math.max(1, Math.ceil((this.errorThrottleMs - elapsed) / 1000));
        Logger.debug(`${message} (aynı hata için ${remainingSec}s throttle)`);
    }

    /**
     * Bekleyen email kampanyalarını getir
     */
    async getPendingEmailCampaigns(limit = null) {
        try {
            const params = {};
            if (Number.isFinite(limit) && limit > 0) {
                params.limit = Math.floor(limit);
            }
            const response = await this.client.get('/email-campaigns/pending', { params });
            
            if (response.status !== 200) {
                Logger.error(`API Error: ${response.status} - ${JSON.stringify(response.data)}`);
                return [];
            }

            return response.data.campaigns || [];
        } catch (error) {
            const detail = error.response?.data;
            const status = error.response?.status || 0;
            const hint = typeof detail === 'object' && detail?.hint ? String(detail.hint) : '';
            const reason = typeof detail === 'object' && detail?.message ? String(detail.message) : String(error.message || 'Bilinmeyen hata');
            const key = `${status}:${reason}:${hint}`;
            const line = [
                'getPendingEmailCampaigns failed',
                `HTTP=${status || 'N/A'}`,
                `Reason=${reason}`,
                hint ? `Fix=${hint}` : null,
            ].filter(Boolean).join(' | ');
            this.logThrottledError(key, line);
            return [];
        }
    }

    /**
     * Kampanya durumunu güncelle
     */
    async updateEmailCampaignStatus(campaignId, status, stats = {}) {
        try {
            const payload = {
                status: status,
                ...stats
            };
            
            const response = await this.client.post(`/email-campaigns/${campaignId}/status`, payload, {
                timeout: this.batchTimeout,
            });

            if (response.status !== 200) {
                Logger.error(`updateEmailCampaignStatus Error: ${response.status} - ${JSON.stringify(response.data)}`);
                return false;
            }

            Logger.info(`Kampanya #${campaignId} durumu güncellendi: ${status}`);
            return true;
        } catch (error) {
            Logger.error(`updateEmailCampaignStatus error for campaign ${campaignId}: ${error.message}`);
            if (error.response) {
                Logger.error(`Response status: ${error.response.status}`);
                Logger.error(`Response data: ${JSON.stringify(error.response.data)}`);
            }
            return false;
        }
    }

    /**
     * Email kampanyasının emaillerini batch olarak getir (PAGINATION)
     */
    async getEmailCampaignEmailsBatch(campaignId, offset = 0, limit = 10000, lastPoolId = null) {
        try {
            const response = await this.client.get(`/email-campaigns/${campaignId}/emails/batch`, {
                params: { offset, limit, last_pool_id: lastPoolId },
                timeout: this.batchTimeout,
            });

            if (response.status !== 200 || !response.data.success) {
                Logger.error(`getEmailCampaignEmailsBatch Error: ${JSON.stringify(response.data)}`);
                return null;
            }

            return response.data;
        } catch (error) {
            Logger.error(`getEmailCampaignEmailsBatch error: ${error.message}`);
            return null;
        }
    }

    /**
     * Email numaralarının durumunu güncelle
     */
    async updateEmailCampaignEmails(campaignId, emails) {
        if (!emails || emails.length === 0) {
            Logger.warn(`updateEmailCampaignEmails: Boş email listesi (Kampanya #${campaignId})`);
            return true;
        }

        const maxAttempts = Math.max(1, parseInt(process.env.EMAIL_API_MAX_RETRIES || '5', 10) || 5);
        let lastError = null;

        for (let attempt = 1; attempt <= maxAttempts; attempt++) {
            try {
                const response = await this.client.post(
                    `/email-campaigns/${campaignId}/emails`,
                    { emails },
                    { timeout: this.batchTimeout }
                );

                if (response.status !== 200) {
                    Logger.error(`updateEmailCampaignEmails Error: ${response.status} - ${JSON.stringify(response.data)}`);
                    Logger.error(`Kampanya #${campaignId}: ${emails.length} email sonucu kaydedilemedi`);
                    return false;
                }

                const successCount = emails.filter(e => e.status === 'delivered').length;
                const failedCount = emails.filter(e => e.status === 'failed').length;
                Logger.debug(`✅ API'ye kaydedildi (Kampanya #${campaignId}): ${successCount} başarılı, ${failedCount} başarısız, toplam ${emails.length}`);

                return true;
            } catch (error) {
                lastError = error;
                const transient = isTransientNetworkError(error);
                Logger.error(`updateEmailCampaignEmails error (Kampanya #${campaignId}, deneme ${attempt}/${maxAttempts}): ${error.message}`);
                if (error.response) {
                    Logger.error(`Response status: ${error.response.status}`);
                    Logger.error(`Response data: ${JSON.stringify(error.response.data)}`);
                }
                if (transient && attempt < maxAttempts) {
                    const backoff = Math.min(30000, 1000 * 2 ** (attempt - 1));
                    Logger.warn(`Geçici ağ/DNS hatası — ${backoff}ms sonra tekrar denenecek`);
                    await sleepMs(backoff);
                    continue;
                }
                break;
            }
        }

        Logger.error(`❌ ${emails.length} email sonucu kaydedilemedi - pending olarak kalacaklar`);
        if (lastError) {
            Logger.error(`Son hata: ${lastError.message}`);
        }
        return false;
    }

    /**
     * Paneldeki email-sending ayarlarını çek (batch gap, concurrency, pool…)
     */
    async refreshRuntimeSendingConfig() {
        const maxAttempts = 4;
        for (let attempt = 1; attempt <= maxAttempts; attempt++) {
            try {
                const response = await this.client.get('/email-sending/runtime-config');
                if (response.status === 200 && response.data?.success && response.data.worker_runtime) {
                    this.workerRuntime = response.data.worker_runtime;
                    return true;
                }
                const hint = response.data?.error || JSON.stringify(response.data ?? {}).slice(0, 280);
                Logger.warn(
                    `runtime-config beklenmeyen yanıt (${attempt}/${maxAttempts}): HTTP ${response.status} — ${hint}`
                );
                if (attempt === maxAttempts) {
                    return false;
                }
            } catch (error) {
                const status = error.response?.status;
                const data = error.response?.data;
                const bodyStr = data !== undefined ? JSON.stringify(data).slice(0, 280) : '';
                const line = status
                    ? `HTTP ${status} ${bodyStr}`
                    : `${error.code || 'NO_CODE'} ${error.message}`;
                Logger.warn(`runtime-config isteği başarısız (${attempt}/${maxAttempts}): ${line}`);
                if (status === 401) {
                    Logger.warn(
                        '401: email-worker API_TOKEN ile panel API anahtarı eşleşmiyor olabilir (X-Api-Token / .env).'
                    );
                    return false;
                }
                const transient = isTransientNetworkError(error) || (typeof status === 'number' && status >= 500);
                if (!transient) {
                    return false;
                }
                if (attempt === maxAttempts) {
                    return false;
                }
            }
            if (attempt < maxAttempts) {
                await sleepMs(Math.min(12000, 800 * 2 ** (attempt - 1)));
            }
        }
        return false;
    }

    getWorkerBatchGapMs() {
        const v = this.workerRuntime?.batch_gap_ms;
        return Number.isFinite(v) ? Math.max(0, v) : 100;
    }

    getWorkerChunkGapMs() {
        const v = this.workerRuntime?.chunk_gap_ms;
        return Number.isFinite(v) ? Math.max(0, v) : 50;
    }

    getWorkerSendConcurrency() {
        const v = this.workerRuntime?.send_concurrency_per_lane;
        if (Number.isFinite(v) && v >= 1) {
            return Math.min(50, v);
        }
        return 1;
    }

    getWorkerSmtpPoolMax() {
        const v = this.workerRuntime?.smtp_pool_max_connections;
        if (Number.isFinite(v) && v >= 0) {
            return Math.min(10000, v);
        }
        return 0;
    }

    /** Panel yoksa PHP varsayılanlarıyla aynı sabitler */
    getWorkerFetchBatchSize() {
        const v = this.workerRuntime?.fetch_batch_size;
        const raw = Number.isFinite(v) && v >= 100 ? v : 10000;
        // Panelde 500k seçilebilir; API limit=50k ve büyük JSON — tek istekte makul tavan
        return Math.min(30000, Math.max(100, raw));
    }

    getWorkerSendBatchSize() {
        const v = this.workerRuntime?.send_batch_size;
        if (Number.isFinite(v) && v >= 10) {
            return Math.min(5000, v);
        }
        return 500;
    }

    getWorkerMaxSmtpLanes() {
        const v = this.workerRuntime?.max_smtp_lanes;
        if (Number.isFinite(v) && v >= 1) {
            return Math.min(50, v);
        }
        return 10;
    }

    getWorkerThrottleStepUpPerSecond() {
        const v = this.workerRuntime?.throttle_step_up_per_second;
        if (Number.isFinite(v) && v >= 0.01) {
            return Math.min(10, v);
        }
        return 0.5;
    }

    getWorkerThrottleCooldownMs() {
        const v = this.workerRuntime?.throttle_cooldown_ms;
        if (Number.isFinite(v) && v >= 1000) {
            return Math.min(600000, v);
        }
        return 15000;
    }

    getWorkerSmtpPoolMaxMessages() {
        const v = this.workerRuntime?.smtp_pool_max_messages;
        if (Number.isFinite(v) && v >= 1) {
            return Math.min(50000, v);
        }
        return 100;
    }

    /** null = 15/sn tavan (eski davranış) */
    getWorkerAlibabaWarmupMaxRatePerSecond() {
        const v = this.workerRuntime?.alibaba_warmup_max_rate_per_second;
        if (v == null || v === '') {
            return null;
        }
        const n = Number(v);
        if (!Number.isFinite(n) || n <= 0) {
            return null;
        }
        return Math.max(0.2, Math.min(600, n));
    }

    /**
     * En uygun SMTP hesabını seç
     */
    async selectBestSmtp(excludeIds = []) {
        try {
            const params = {};
            if (Array.isArray(excludeIds) && excludeIds.length > 0) {
                params.exclude_ids = excludeIds.join(',');
            }

            const response = await this.client.get('/smtp/select-best', { params });

            if (response.data?.worker_runtime && typeof response.data.worker_runtime === 'object') {
                this.workerRuntime = response.data.worker_runtime;
            }

            if (response.status !== 200) {
                Logger.error(`selectBestSmtp HTTP ${response.status}: ${JSON.stringify(response.data)}`);
                return null;
            }

            if (!response.data.success || response.data.smtp == null) {
                Logger.debug(
                    `selectBestSmtp: smtp=null — ${response.data?.error || '—'}`
                );
                return null;
            }

            return response.data.smtp;
        } catch (error) {
            Logger.error(`selectBestSmtp error: ${error.message}`);
            return null;
        }
    }

    /**
     * Kullanıcının email karalistesini getir.
     * Sonuç 1 saat (BLACKLIST_CACHE_TTL_MS) boyunca önbelleklenir.
     * API hatası durumunda eski önbellek kullanılır.
     */
    async getEmailBlacklist() {
        const now = Date.now();

        if (this._blacklistCache && now < this._blacklistCache.expiresAt) {
            const remainMin = Math.round((this._blacklistCache.expiresAt - now) / 60_000);
            Logger.info(`Karaliste önbellekten alındı: ${this._blacklistCache.data.size.toLocaleString()} adres (${remainMin}dk geçerli)`);
            return this._blacklistCache.data;
        }

        try {
            const response = await this.client.get('/email-blacklist');

            if (response.status !== 200 || !response.data.success) {
                Logger.error(`getEmailBlacklist HTTP ${response.status}: ${JSON.stringify(response.data)}`);
                if (this._blacklistCache) {
                    Logger.warn('Karaliste API hatası — eski önbellek kullanılıyor');
                    return this._blacklistCache.data;
                }
                return new Set();
            }

            const blacklistSet = new Set(response.data.blacklist.map(email => email.toLowerCase()));
            this._blacklistCache = { data: blacklistSet, expiresAt: now + this._blacklistTtlMs };
            Logger.info(`Karaliste API'den yüklendi: ${blacklistSet.size.toLocaleString()} adres (${Math.round(this._blacklistTtlMs / 60_000)}dk önbellekte)`);
            return blacklistSet;
        } catch (error) {
            Logger.error(`getEmailBlacklist error: ${error.message}`);
            if (this._blacklistCache) {
                Logger.warn('Karaliste ağ hatası — eski önbellek kullanılıyor');
                return this._blacklistCache.data;
            }
            return new Set();
        }
    }

    /**
     * SMTP kullanımını kaydet (DATABASE'E KAYDEDER)
     */
    async recordSmtpUsage(smtpId, data) {
        try {
            const response = await this.client.post(`/smtp/${smtpId}/usage`, data);
            
            if (response.status === 200 && response.data) {
                const stats = response.data;
                Logger.debug(`SMTP #${smtpId}: Günlük ${stats.daily_sent}/${stats.daily_remaining + stats.daily_sent}, Saatlik ${stats.hourly_sent}/${stats.hourly_remaining + stats.hourly_sent}, Dakikalık ${stats.minute_sent}/${stats.minute_remaining + stats.minute_sent}, Toplam: ${stats.total_sent}`);
                return stats; // Response'u döndür ki pool config güncellenebilsin
            }
            return null;
        } catch (error) {
            Logger.error(`recordSmtpUsage error: ${error.message}`);
            return null;
        }
    }

    /**
     * Failed emailleri iade et
     */
    async refundFailedEmails(campaignId) {
        try {
            const response = await this.client.post(`/email-campaigns/${campaignId}/refund`);
            
            if (response.status !== 200) {
                Logger.error(`refundFailedEmails Error: ${response.status} - ${JSON.stringify(response.data)}`);
                return false;
            }

            Logger.info(`Kampanya #${campaignId} için iade işlendi`);
            return true;
        } catch (error) {
            Logger.error(`refundFailedEmails error for campaign ${campaignId}: ${error.message}`);
            return false;
        }
    }

    /**
     * Kampanyayı atomik claim et
     */
    async claimEmailCampaign(campaignId, workerId) {
        try {
            const response = await this.client.post(`/email-campaigns/${campaignId}/claim`, {
                worker_id: workerId
            });

            if (response.status !== 200 || !response.data.success) {
                return { claimed: false, reason: 'api_error' };
            }

            return {
                claimed: !!response.data.claimed,
                reason: response.data.reason || null
            };
        } catch (error) {
            Logger.error(`claimEmailCampaign error: ${error.message}`);
            return { claimed: false, reason: error.message };
        }
    }

    /**
     * Takılı kalan email kampanyalarını temizle
     */
    async cleanupStuckEmailCampaigns() {
        try {
            await this.client.post('/email-campaigns/cleanup-stuck', {}, { timeout: this.batchTimeout });
        } catch (error) {
            Logger.error(`cleanupStuckEmailCampaigns error: ${error.message}`);
        }
    }
}

