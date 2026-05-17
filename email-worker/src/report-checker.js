import { Logger } from './logger.js';
import { ApiClient } from './api-client.js';
import { config } from './config.js';

/**
 * Email Report Checker
 * 
 * Not: Email delivery raporlaması SMS'ten farklıdır.
 * Çoğu SMTP sağlayıcı gerçek zamanlı delivery confirmation vermez.
 * Bu checker, timeout kontrolü ve otomatik refund için kullanılır.
 */
export class ReportChecker {
    constructor() {
        this.apiClient = new ApiClient();
        this.tracking = new Map(); // campaignId -> trackingData
        this.isRunning = false;
        this.timeout = config.reportChecker.timeout;
        this.checkInterval = config.reportChecker.checkInterval;
    }

    /**
     * Report checker'ı başlat
     */
    start() {
        if (this.isRunning) {
            Logger.warn('Report checker zaten çalışıyor');
            return;
        }

        this.isRunning = true;
        Logger.info('Report Checker başlatıldı');
        Logger.info(`Timeout          : ${this.timeout / 1000 / 60 / 60} saat`);
        Logger.info(`Check Interval   : ${this.checkInterval / 1000} saniye`);
        
        this.checkLoop();
    }

    /**
     * Report checker'ı durdur
     */
    stop() {
        this.isRunning = false;
        Logger.info('Report Checker durduruldu');
    }

    /**
     * Kampanyayı takibe al
     */
    addToTracking(campaignId) {
        if (!this.tracking.has(campaignId)) {
            this.tracking.set(campaignId, {
                campaignId,
                startTime: Date.now(),
                lastCheck: Date.now(),
                checkCount: 0
            });
            
            Logger.info(`Kampanya #${campaignId} report takibine alındı`);
        }
    }

    /**
     * Ana check loop
     */
    async checkLoop() {
        while (this.isRunning) {
            try {
                if (this.tracking.size > 0) {
                    await this.checkAllReports();
                }
            } catch (error) {
                Logger.error(`Report check loop error: ${error.message}`);
            }

            await this.sleep(this.checkInterval);
        }
    }

    /**
     * Tüm kampanyaların raporlarını kontrol et
     */
    async checkAllReports() {
        const now = Date.now();
        const toRemove = [];

        for (const [campaignId, trackingData] of this.tracking.entries()) {
            try {
                // Timeout kontrolü
                if (now - trackingData.startTime > this.timeout) {
                    Logger.warn(`Kampanya #${campaignId} timeout (24 saat), takipten çıkarılıyor`);
                    await this.handleTimeout(campaignId);
                    toRemove.push(campaignId);
                    continue;
                }

                // Bu kampanya için artık kontrol gerekmiyorsa
                // (örneğin manuel olarak tamamlandı), takipten çıkar
                const shouldContinue = await this.checkCampaignStatus(campaignId);
                if (!shouldContinue) {
                    toRemove.push(campaignId);
                }

            } catch (error) {
                Logger.error(`Kampanya #${campaignId} report check error: ${error.message}`);
            }
        }

        // Takipten çıkar
        toRemove.forEach(id => {
            this.tracking.delete(id);
            Logger.info(`Kampanya #${id} report takibinden çıkarıldı`);
        });
    }

    /**
     * Kampanya durumunu kontrol et
     */
    async checkCampaignStatus(campaignId) {
        // Bu metod, kampanyanın hala "sent" durumunda olup olmadığını kontrol eder
        // Eğer "completed" veya "failed" ise, artık takip gerekmez
        
        // Şimdilik basit bir implementasyon:
        // Her kampanya için refund kontrolü yap ve tamamlandıysa takipten çıkar
        
        // Not: Gerçek bir email delivery tracking için webhook veya
        // SMTP provider API'si gerekir. Şimdilik timeout bazlı çalışıyoruz.
        
        return true; // Devam et
    }

    /**
     * Timeout olan kampanyayı işle
     */
    async handleTimeout(campaignId) {
        Logger.warn(`Kampanya #${campaignId} için timeout, failed olarak işaretlenecek ve refund yapılacak`);

        try {
            // Durumu completed yap (timeout ile tamamlandı)
            await this.apiClient.updateEmailCampaignStatus(campaignId, 'completed');

            // Failed emailleri iade et
            await this.processRefund(campaignId);

        } catch (error) {
            Logger.error(`Timeout handling error for campaign ${campaignId}: ${error.message}`);
        }
    }

    /**
     * Failed emailleri iade et
     */
    async processRefund(campaignId) {
        try {
            const refunded = await this.apiClient.refundFailedEmails(campaignId);
            
            if (refunded) {
                Logger.info(`Kampanya #${campaignId} için refund başarılı`);
            } else {
                Logger.warn(`Kampanya #${campaignId} için refund yapılamadı (kullanıcı refund kapalı olabilir)`);
            }
        } catch (error) {
            Logger.error(`Refund error for campaign ${campaignId}: ${error.message}`);
        }
    }

    /**
     * Sleep utility
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    /**
     * İstatistik bilgisi
     */
    getStats() {
        return {
            tracking_count: this.tracking.size,
            campaigns: Array.from(this.tracking.keys())
        };
    }
}

