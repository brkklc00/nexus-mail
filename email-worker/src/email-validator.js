import dns from 'dns';
import { promisify } from 'util';
import { Logger } from './logger.js';

const resolveMx = promisify(dns.resolveMx);

/**
 * Email Validation Service
 * SMTP bounce oranını düşürmek için email kontrolü
 */
export class EmailValidator {
    constructor() {
        // Disposable/temporary email provider'ları
        this.disposableDomains = new Set([
            'tempmail.com', 'guerrillamail.com', '10minutemail.com', 
            'mailinator.com', 'throwaway.email', 'temp-mail.org',
            'fakeinbox.com', 'trashmail.com', 'getnada.com',
            'maildrop.cc', 'yopmail.com', 'sharklasers.com',
            'getairmail.com', 'mohmal.com', 'putlook.com',
            'drotel.com', 'otmail.com', '5834gmail.com', 
            'haotmail.com', 'cartelera.org'
        ]);

        // Yaygın yazım hataları (typo)
        this.commonTypos = {
            'gmial.com': 'gmail.com',
            'gmai.com': 'gmail.com',
            'gmil.com': 'gmail.com',
            'gmaill.com': 'gmail.com',
            'yahooo.com': 'yahoo.com',
            'yaho.com': 'yahoo.com',
            'hotmial.com': 'hotmail.com',
            'hotmai.com': 'hotmail.com',
            'homail.com': 'hotmail.com',
            'htomail.com': 'hotmail.com',
            'hotmil.com': 'hotmail.com',
            'outloo.com': 'outlook.com',
            'outlok.com': 'outlook.com',
            'gmail.com.tr': 'gmail.com',
            'hotmail.om': 'hotmail.com'
        };

        this.mxCache = new Map(); // MX record cache (performans için)
        this.cacheTimeout = 3600000; // 1 saat
    }

    /**
     * Email'i tam olarak validate et
     */
    async validateEmail(email) {
        const result = {
            email: email,
            isValid: true,
            errors: [],
            warnings: [],
            correctedEmail: null
        };

        // 1. Syntax kontrolü
        if (!this.isValidSyntax(email)) {
            result.isValid = false;
            result.errors.push('Geçersiz email formatı');
            return result;
        }

        const [localPart, domain] = email.toLowerCase().split('@');

        // 2. Domain typo kontrolü
        if (this.commonTypos[domain]) {
            result.warnings.push(`Muhtemel yazım hatası: ${domain} → ${this.commonTypos[domain]}`);
            result.correctedEmail = `${localPart}@${this.commonTypos[domain]}`;
        }

        // 3. Disposable email kontrolü
        if (this.isDisposableEmail(domain)) {
            result.isValid = false;
            result.errors.push('Geçici/disposable email adresi');
            return result;
        }

        // 4. MX record kontrolü (DNS)
        const hasMX = await this.checkMXRecord(domain);
        if (!hasMX) {
            result.isValid = false;
            result.errors.push('Domain MX kaydı bulunamadı');
            return result;
        }

        return result;
    }

    /**
     * Email syntax kontrolü (RFC 5322)
     */
    isValidSyntax(email) {
        const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return regex.test(email);
    }

    /**
     * Disposable email kontrolü
     */
    isDisposableEmail(domain) {
        return this.disposableDomains.has(domain.toLowerCase());
    }

    /**
     * MX record kontrolü (DNS)
     */
    async checkMXRecord(domain) {
        try {
            // Cache'de var mı?
            const cached = this.mxCache.get(domain);
            if (cached && (Date.now() - cached.timestamp) < this.cacheTimeout) {
                return cached.hasMX;
            }

            // DNS MX record kontrolü
            const mxRecords = await resolveMx(domain);
            const hasMX = mxRecords && mxRecords.length > 0;

            // Cache'e kaydet
            this.mxCache.set(domain, {
                hasMX: hasMX,
                timestamp: Date.now()
            });

            return hasMX;
        } catch (error) {
            // DNS hatası = MX yok kabul et
            Logger.debug(`MX check error for ${domain}: ${error.message}`);
            
            // Hatalarda cache'e negative result kaydet (tekrar sorgulamayalım)
            this.mxCache.set(domain, {
                hasMX: false,
                timestamp: Date.now()
            });
            
            return false;
        }
    }

    /**
     * Toplu email validasyonu (async batch)
     */
    async validateBatch(emails, options = {}) {
        const {
            checkMX = true,
            autoCorrect = true,
            skipDisposable = true
        } = options;

        const results = {
            valid: [],
            invalid: [],
            corrected: [],
            total: emails.length
        };

        for (const email of emails) {
            // Basit syntax check
            if (!this.isValidSyntax(email.email)) {
                results.invalid.push({
                    ...email,
                    reason: 'Invalid syntax'
                });
                continue;
            }

            const domain = email.email.split('@')[1].toLowerCase();
            const localPart = email.email.split('@')[0];

            // Disposable check
            if (skipDisposable && this.isDisposableEmail(domain)) {
                results.invalid.push({
                    ...email,
                    reason: 'Disposable email'
                });
                continue;
            }

            // Typo check ve auto-correct
            if (autoCorrect && this.commonTypos[domain]) {
                results.corrected.push({
                    original: email.email,
                    corrected: `${localPart}@${this.commonTypos[domain]}`,
                    reason: 'Typo fixed'
                });
                
                // Düzeltilmiş emaili kullan
                email.email = `${localPart}@${this.commonTypos[domain]}`;
            }

            // MX check (opsiyonel, performans için)
            if (checkMX) {
                const hasMX = await this.checkMXRecord(domain);
                if (!hasMX) {
                    results.invalid.push({
                        ...email,
                        reason: 'No MX record'
                    });
                    continue;
                }
            }

            results.valid.push(email);
        }

        return results;
    }

    /**
     * Email'i temizle ve normalize et
     */
    normalizeEmail(email) {
        return email.trim().toLowerCase();
    }

    /**
     * Cache'i temizle
     */
    clearCache() {
        this.mxCache.clear();
        Logger.info('Email validator cache temizlendi');
    }

    /**
     * Cache istatistikleri
     */
    getCacheStats() {
        return {
            size: this.mxCache.size,
            domains: Array.from(this.mxCache.keys())
        };
    }
}

