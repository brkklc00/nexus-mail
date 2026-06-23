/**
 * ExternalApiClient — hub-nexus kampanyalarını mail sunucusundan işlemek için hybrid API client.
 *
 * Email verisi (batch getirme, sonuç yazma, slot tamamlama) → hub-nexus API
 * SMTP seçimi, kullanım kaydı, runtime config → yerel API (ApiClient'tan miras)
 */

import axios from 'axios';
import { ApiClient } from './api-client.js';
import { Logger } from './logger.js';

export class ExternalApiClient extends ApiClient {
    /**
     * @param {string} hubApiUrl      - Hub-nexus base URL (örn. https://hub-nexus.com)
     * @param {string} hubApiToken    - Hub-nexus API token (X-Api-Token)
     * @param {number} hubCampaignId  - Hub-nexus kampanya ID
     * @param {number} hubSlotId      - Hub-nexus priority slot ID
     * @param {number} localSlotId    - Yerel external_priority_slots.id
     */
    constructor(hubApiUrl, hubApiToken, hubCampaignId, hubSlotId, localSlotId) {
        super();  // yerel API client (SMTP, runtime config)
        this.hubCampaignId = hubCampaignId;
        this.hubSlotId     = hubSlotId;
        this.localSlotId   = localSlotId;

        this.hubClient = axios.create({
            baseURL: hubApiUrl.replace(/\/+$/, ''),
            timeout: this.batchTimeout,
            headers: {
                'Content-Type': 'application/json',
                'X-Api-Token': hubApiToken,
            },
        });
    }

    // ─── Hub'a yönlendirilen metodlar ─────────────────────────────────────────

    /**
     * Email batch'ini hub-nexus'tan getir (priority slot ID ile filtrelenmiş)
     */
    async getEmailCampaignEmailsBatch(campaignId, offset = 0, limit = 10000, lastPoolId = null) {
        try {
            const params = { offset, limit, priority_slot_id: this.hubSlotId };
            if (lastPoolId !== null) params.last_pool_id = lastPoolId;

            const response = await this.hubClient.get(
                `/api/email-campaigns/${this.hubCampaignId}/emails/batch`,
                { params, timeout: this.batchTimeout }
            );

            if (response.status !== 200 || !response.data.success) {
                Logger.error(`[external] getEmailCampaignEmailsBatch HTTP ${response.status}: ${JSON.stringify(response.data)}`);
                return null;
            }
            return response.data;
        } catch (error) {
            Logger.error(`[external] getEmailCampaignEmailsBatch error: ${error.message}`);
            return null;
        }
    }

    /**
     * Email sonuçlarını hub-nexus'a yaz
     */
    async updateEmailCampaignEmails(campaignId, emails) {
        if (!emails || emails.length === 0) return true;

        const maxAttempts = 5;
        for (let attempt = 1; attempt <= maxAttempts; attempt++) {
            try {
                const response = await this.hubClient.post(
                    `/api/email-campaigns/${this.hubCampaignId}/emails`,
                    { emails },
                    { timeout: this.batchTimeout }
                );
                if (response.status === 200) {
                    return true;
                }
                Logger.error(`[external] updateEmailCampaignEmails HTTP ${response.status}`);
            } catch (error) {
                Logger.error(`[external] updateEmailCampaignEmails attempt ${attempt}/${maxAttempts}: ${error.message}`);
                if (attempt < maxAttempts) {
                    await new Promise(r => setTimeout(r, Math.min(30000, 1000 * 2 ** (attempt - 1))));
                    continue;
                }
            }
            break;
        }
        return false;
    }

    /**
     * Kampanya durumunu güncelle — hub-nexus priority slot sistemi bunu kendi yönetiyor, atla.
     */
    async updateEmailCampaignStatus(campaignId, status, stats = {}) {
        return true;
    }

    /**
     * Geçersiz adres karalisteye → email verisi hub'da olduğu için hub'a gönder.
     */
    async suppressBouncedEmails(campaignId, emails, reason = 'auto:invalid_address') {
        if (!emails || emails.length === 0) return true;
        try {
            const response = await this.hubClient.post(
                `/api/email-campaigns/${this.hubCampaignId}/suppress`,
                { emails, reason },
                { timeout: this.batchTimeout }
            );
            return response.status === 200;
        } catch (error) {
            Logger.warn(`[external] suppressBouncedEmails error: ${error.message}`);
            return false;
        }
    }

    /**
     * Hub priority slot'unu tamamla + yerel external slot'u kapat
     */
    async completePrioritySlot(slotId, stats = {}) {
        const sentCount   = stats.sent ?? 0;
        const failedCount = stats.failed ?? 0;

        // 1) Hub-nexus'ta priority slot'u tamamla
        try {
            await this.hubClient.post(
                `/api/email-campaigns/priority-slots/${this.hubSlotId}/complete`,
                { sent_count: sentCount, failed_count: failedCount }
            );
            Logger.info(`[external] Hub slot #${this.hubSlotId} tamamlandı`);
        } catch (error) {
            Logger.error(`[external] Hub completePrioritySlot(${this.hubSlotId}) error: ${error.message}`);
        }

        // 2) Yerel external_priority_slots kaydını kapat
        try {
            await this.client.post(
                `/api/email-campaigns/external-priority/${this.localSlotId}/complete`,
                { sent_count: sentCount, failed_count: failedCount }
            );
            Logger.info(`[external] Yerel slot #${this.localSlotId} tamamlandı`);
        } catch (error) {
            Logger.error(`[external] Local completeExternalPrioritySlot(${this.localSlotId}) error: ${error.message}`);
        }

        return true;
    }

    /**
     * Karaliste hub-nexus'tan al
     */
    async getEmailBlacklist() {
        const now = Date.now();
        if (this._blacklistCache && now < this._blacklistCache.expiresAt) {
            return this._blacklistCache.data;
        }
        try {
            const response = await this.hubClient.get('/api/email-blacklist');
            if (response.status === 200 && response.data?.success) {
                const blacklistSet = new Set(response.data.blacklist.map(e => e.toLowerCase()));
                this._blacklistCache = { data: blacklistSet, expiresAt: now + this._blacklistTtlMs };
                Logger.info(`[external] Karaliste hub'dan yüklendi: ${blacklistSet.size} adres`);
                return blacklistSet;
            }
        } catch (error) {
            Logger.error(`[external] getEmailBlacklist error: ${error.message}`);
        }
        if (this._blacklistCache) return this._blacklistCache.data;
        return new Set();
    }

    // ─── Atlanacak metodlar ────────────────────────────────────────────────────

    async refundFailedEmails(campaignId) { return true; }
    async cleanupStuckEmailCampaigns(workerId = null) { return; }
}
