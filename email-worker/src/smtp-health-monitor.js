import { Logger } from './logger.js';
import { ApiClient } from './api-client.js';

/**
 * SMTP Health Monitor
 * - Bounce rate tracking
 * - Auto-disable problematic SMTP accounts
 * - Warming up for new accounts
 */
export class SmtpHealthMonitor {
    constructor() {
        this.apiClient = new ApiClient();
        this.smtpStats = new Map(); // smtpId -> stats
        
        // Threshold'lar
        this.maxBounceRate = 0.05; // %5 bounce rate = kritik
        this.warmupThreshold = 100; // İlk 100 email'de yavaş git
        this.minEmailsForCheck = 50; // Min 50 email gönderdikten sonra kontrol et
    }

    /**
     * SMTP'nin bounce rate'ini kontrol et
     */
    async checkSmtpHealth(smtpId) {
        const stats = this.getSmtpStats(smtpId);
        
        // Henüz yeterince email gönderilmemiş
        if (stats.totalSent < this.minEmailsForCheck) {
            return { healthy: true, reason: 'Insufficient data' };
        }

        const bounceRate = stats.bounces / stats.totalSent;

        // Bounce rate çok yüksek
        if (bounceRate > this.maxBounceRate) {
            Logger.warn(`⚠️ SMTP #${smtpId} bounce rate yüksek: ${(bounceRate * 100).toFixed(2)}%`);
            
            // SMTP'yi devre dışı bırak
            await this.disableSmtp(smtpId, `High bounce rate: ${(bounceRate * 100).toFixed(2)}%`);
            
            return {
                healthy: false,
                reason: 'High bounce rate',
                bounceRate: bounceRate
            };
        }

        return {
            healthy: true,
            bounceRate: bounceRate
        };
    }

    /**
     * Email gönderimi kaydet
     */
    recordEmail(smtpId, success, isBounce = false) {
        const stats = this.getSmtpStats(smtpId);
        
        stats.totalSent++;
        
        if (success) {
            stats.successful++;
        } else {
            stats.failed++;
            
            if (isBounce) {
                stats.bounces++;
            }
        }

        stats.lastUsed = Date.now();
        
        this.smtpStats.set(smtpId, stats);
    }

    /**
     * SMTP stats'ı al veya oluştur
     */
    getSmtpStats(smtpId) {
        if (!this.smtpStats.has(smtpId)) {
            this.smtpStats.set(smtpId, {
                smtpId: smtpId,
                totalSent: 0,
                successful: 0,
                failed: 0,
                bounces: 0,
                lastUsed: Date.now(),
                createdAt: Date.now()
            });
        }
        
        return this.smtpStats.get(smtpId);
    }

    /**
     * SMTP'yi devre dışı bırak
     */
    async disableSmtp(smtpId, reason) {
        try {
            Logger.error(`🔴 SMTP #${smtpId} devre dışı bırakılıyor: ${reason}`);
            
            // API'ye bildir (SMTP'yi pasif yap)
            await this.apiClient.recordSmtpUsage(smtpId, {
                success_count: 0,
                failed_count: 0,
                disable: true,
                error_message: reason
            });
            
            // Stats'ı temizle
            this.smtpStats.delete(smtpId);
            
        } catch (error) {
            Logger.error(`SMTP disable error: ${error.message}`);
        }
    }

    /**
     * Warming up gerekiyor mu? (Database'deki totalSent'e bakar)
     */
    needsWarmup(smtpId, dbTotalSent = null) {
        // Database'den gelen değer varsa onu kullan (persistent)
        const totalSent = dbTotalSent ?? this.getSmtpStats(smtpId).totalSent;
        return totalSent < this.warmupThreshold;
    }

    /**
     * Warming up için delay hesapla (Database'deki totalSent'e bakar)
     */
    getWarmupDelay(smtpId, dbTotalSent = null) {
        // Database'den gelen değer varsa onu kullan (persistent)
        const totalSent = dbTotalSent ?? this.getSmtpStats(smtpId).totalSent;
        
        if (totalSent < 10) {
            return 5000; // İlk 10 email: 5 saniye bekle
        } else if (totalSent < 50) {
            return 2000; // 10-50: 2 saniye
        } else if (totalSent < 100) {
            return 1000; // 50-100: 1 saniye
        }
        
        return 500; // Normal: 500ms
    }

    /**
     * Alibaba için warmup aşamalarına göre güvenli hız üst limiti.
     * total_sent kalıcı DB sayacı olduğu için restart sonrası da korunur.
     */
    getAlibabaWarmupRateCap(dbTotalSent = 0, panelMaxCapPerSecond = null) {
        const totalSent = Number(dbTotalSent || 0);
        const maxCap =
            panelMaxCapPerSecond != null
            && Number.isFinite(Number(panelMaxCapPerSecond))
            && Number(panelMaxCapPerSecond) > 0
                ? Math.max(0.2, Number(panelMaxCapPerSecond))
                : 15;

        if (totalSent < 1000) return Math.min(maxCap, 4.0);       // yeni hesap (önceden 1/sn çok düşüktü)
        if (totalSent < 10000) return Math.min(maxCap, 8.0);       // erken ısınma
        if (totalSent < 50000) return Math.min(maxCap, 12.0);     // orta seviye
        if (totalSent < 100000) return Math.min(maxCap, 14.0);    // ileri seviye
        return maxCap;
    }

    /**
     * Rate limiting kontrolü
     */
    shouldRateLimit(smtpId) {
        const stats = this.getSmtpStats(smtpId);
        const now = Date.now();
        
        // Son 1 dakikada kaç email gönderildi?
        const oneMinuteAgo = now - 60000;
        
        // Bu bilgiyi tutmak için timestamp array'e ihtiyacımız var
        // Şimdilik basit bir kontrol: Son kullanımdan 100ms geçmişse OK
        const timeSinceLastUse = now - stats.lastUsed;
        
        return timeSinceLastUse < 100; // 100ms'den kısa sürede tekrar kullanılmış
    }

    /**
     * Tüm SMTP istatistiklerini al
     */
    getAllStats() {
        const stats = [];
        
        for (const [smtpId, data] of this.smtpStats.entries()) {
            const bounceRate = data.totalSent > 0 ? (data.bounces / data.totalSent) : 0;
            const successRate = data.totalSent > 0 ? (data.successful / data.totalSent) : 0;
            
            stats.push({
                smtpId: smtpId,
                totalSent: data.totalSent,
                successful: data.successful,
                failed: data.failed,
                bounces: data.bounces,
                successRate: (successRate * 100).toFixed(2) + '%',
                bounceRate: (bounceRate * 100).toFixed(2) + '%',
                status: bounceRate > this.maxBounceRate ? '🔴 Risky' : '🟢 Healthy'
            });
        }
        
        return stats;
    }

    /**
     * İstatistikleri logla
     */
    logStats() {
        const stats = this.getAllStats();
        
        if (stats.length === 0) {
            Logger.info('SMTP istatistikleri: Henüz email gönderilmedi');
            return;
        }

        Logger.info('═══════════════════════════════════════════════════════');
        Logger.info('SMTP Health Report:');
        
        stats.forEach(stat => {
            Logger.info(`SMTP #${stat.smtpId}: ${stat.totalSent} sent, Success: ${stat.successRate}, Bounce: ${stat.bounceRate} ${stat.status}`);
        });
        
        Logger.info('═══════════════════════════════════════════════════════');
    }

    /**
     * Cache'i temizle
     */
    reset() {
        this.smtpStats.clear();
        Logger.info('SMTP health monitor reset edildi');
    }
}

