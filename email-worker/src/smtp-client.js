import nodemailer from 'nodemailer';
import { Logger } from './logger.js';
import { classifySmtpError, Category } from './services/error-classifier.js';

export class SmtpClient {
    constructor(smtpConfig) {
        this.config = smtpConfig;
        this.transporter = null;
        this.isConnected = false;
    }

    /**
     * SMTP bağlantısı oluştur
     */
    async connect() {
        try {
            // Encryption ayarlarını belirle
            const isSSL = this.config.encryption === 'ssl';
            const isTLS = this.config.encryption === 'tls';
            const port = parseInt(this.config.port);
            
            // Alibaba SMTP için özel kontrol
            const isAlibaba = this.config.host && (
                this.config.host.includes('aliyuncs.com') || 
                this.config.host.includes('alibaba')
            );
            
            const transportConfig = {
                host: this.config.host,
                port: port,
                secure: isSSL, // true for 465, false for other ports
                auth: {
                    user: this.config.username,
                    pass: this.config.password,
                },
                tls: {
                    rejectUnauthorized: false, // Self-signed certificates için
                    minVersion: 'TLSv1.2' // Minimum TLS version
                },
                connectionTimeout: isAlibaba ? 60000 : 30000, // Alibaba için daha uzun timeout (60s)
                greetingTimeout: isAlibaba ? 30000 : 15000, // Alibaba için daha uzun greeting timeout (30s)
                socketTimeout: isAlibaba ? 60000 : 30000 // Alibaba için daha uzun socket timeout (60s)
            };
            
            // Alibaba SMTP için özel ayarlar
            if (isAlibaba) {
                if (port === 465) {
                    // Port 465 için SSL
                    transportConfig.secure = true;
                    transportConfig.requireTLS = false;
                    transportConfig.tls = {
                        rejectUnauthorized: false,
                        minVersion: 'TLSv1.2',
                        ciphers: 'DEFAULT@SECLEVEL=1'
                    };
                } else if (port === 80) {
                    // Port 80 için özel ayarlar (Alibaba DirectMail)
                    transportConfig.secure = false;
                    transportConfig.requireTLS = false;
                    transportConfig.ignoreTLS = true; // TLS'yi tamamen atla
                } else if (port === 25 || port === 587) {
                    // Port 25/587 için STARTTLS
                    transportConfig.secure = false;
                    transportConfig.requireTLS = true;
                    transportConfig.tls = {
                        rejectUnauthorized: false,
                        minVersion: 'TLSv1.2'
                    };
                }
                let poolMax = 0;
                if (typeof this.config.pool_max_connections === 'number' && Number.isFinite(this.config.pool_max_connections)) {
                    poolMax = this.config.pool_max_connections;
                }
                let poolMaxMsg = 100;
                if (typeof this.config.pool_max_messages === 'number' && Number.isFinite(this.config.pool_max_messages)) {
                    poolMaxMsg = Math.max(1, Math.min(50000, this.config.pool_max_messages));
                }
                if (Number.isFinite(poolMax) && poolMax > 1) {
                    transportConfig.pool = true;
                    transportConfig.maxConnections = poolMax;
                    transportConfig.maxMessages = poolMaxMsg;
                } else {
                    transportConfig.pool = false;
                }
            }
            
            // Port 80 için özel ayarlar
            if (port === 80) {
                transportConfig.secure = false;
                transportConfig.requireTLS = false;
                transportConfig.tls = {
                    rejectUnauthorized: false,
                    minVersion: 'TLSv1'
                };
            }
            
            // Port 2526 için özel ayarlar (AUTH CRAM-MD5 kullanır, encryption yok)
            if (port === 2526) {
                transportConfig.secure = false; // Plain connection
                transportConfig.requireTLS = false; // STARTTLS yok
                transportConfig.authMethod = 'CRAM-MD5'; // CRAM-MD5 authentication
                transportConfig.tls = {
                    rejectUnauthorized: false
                };
            }
            
            // TLS için STARTTLS kullan (Port 25, 587)
            if (isTLS && port !== 465 && port !== 2526) {
                transportConfig.secure = false; // STARTTLS için secure=false olmalı
                transportConfig.requireTLS = true;
                transportConfig.tls = {
                    rejectUnauthorized: false,
                    ciphers: 'SSLv3'
                };
            }
            
            this.transporter = nodemailer.createTransport(transportConfig);

            // Bağlantıyı test et
            await this.transporter.verify();
            this.isConnected = true;
            Logger.debug(`SMTP bağlantısı başarılı: ${this.config.host}:${this.config.port}`);
            return true;
        } catch (error) {
            this.isConnected = false;
            Logger.error(`SMTP bağlantı hatası: ${error.message}`);
            return false;
        }
    }

    /**
     * Email gönder
     */
    async sendEmail(to, subject, body, fromName = null, fromEmail = null) {
        const toAddr = String(to ?? '').trim();
        if (!toAddr || !toAddr.includes('@')) {
            const msg = 'Geçersiz veya boş alıcı adresi';
            Logger.error(`Email gönderim hatası (${to}): ${msg}`);
            return {
                success: false,
                error: msg,
                error_type: 'invalid_recipient',
                retryable: false,
            };
        }

        if (!this.transporter) {
            const connected = await this.connect();
            if (!connected) {
                throw new Error('SMTP connection failed');
            }
        }

        try {
            // FROM email SMTP hesabından gelmelidir (authentication uyumu için)
            const senderEmail = fromEmail || this.config.from_email;
            const appTitle = process.env.SITE_TITLE || 'Nexus Panel';
            const senderName = fromName || this.config.from_name || appTitle;
            
            // Body zaten HTML formatında geldiği için direkt gönder
            // Text versiyonunu HTML tag'lerinden temizle (email client'lar için)
            const textVersion = body.replace(/<[^>]*>/g, '')
                .replace(/&nbsp;/g, ' ')
                .replace(/&amp;/g, '&')
                .replace(/&lt;/g, '<')
                .replace(/&gt;/g, '>')
                .replace(/&quot;/g, '"')
                .trim();
            
            const mailOptions = {
                from: `"${senderName}" <${senderEmail}>`,
                to: toAddr,
                subject: subject,
                html: body, // HTML içeriği direkt gönder
                text: textVersion || subject // Text versiyonu (email client'lar için)
            };

            const info = await this.transporter.sendMail(mailOptions);
            
            return {
                success: true,
                messageId: info.messageId,
                response: info.response
            };
        } catch (error) {
            // Nodemailer pool bazen dışarıdan kapatıldığında bu hata döner.
            // Tek seferlik reconnect+retry ile transient kırılmaları toparla.
            const errMsg = String(error?.message || '').toLowerCase();
            if (errMsg.includes('connection pool was closed')) {
                Logger.warn(`SMTP pool kapalı bulundu (${this.config.host}:${this.config.port}), yeniden bağlanıp tekrar denenecek`);
                try {
                    this.transporter = null;
                    this.isConnected = false;
                    const reconnected = await this.connect();
                    if (reconnected && this.transporter) {
                        const senderEmail = fromEmail || this.config.from_email;
                        const appTitle = process.env.SITE_TITLE || 'Nexus Panel';
                        const senderName = fromName || this.config.from_name || appTitle;
                        const textVersion = body.replace(/<[^>]*>/g, '')
                            .replace(/&nbsp;/g, ' ')
                            .replace(/&amp;/g, '&')
                            .replace(/&lt;/g, '<')
                            .replace(/&gt;/g, '>')
                            .replace(/&quot;/g, '"')
                            .trim();
                        const retryMailOptions = {
                            from: `"${senderName}" <${senderEmail}>`,
                            to: toAddr,
                            subject: subject,
                            html: body,
                            text: textVersion || subject
                        };
                        const retryInfo = await this.transporter.sendMail(retryMailOptions);
                        return {
                            success: true,
                            messageId: retryInfo.messageId,
                            response: retryInfo.response
                        };
                    }
                } catch (retryError) {
                    Logger.error(`Reconnect retry başarısız (${toAddr}): ${retryError.message}`);
                }
            }

            Logger.error(`Email gönderim hatası (${toAddr}): ${error.message}`);
            const classification = this.classifySendError(error);
            return {
                success: false,
                error: error.message,
                error_type: classification.type,
                retryable: classification.retryable,
            };
        }
    }

    /**
     * Toplu email gönder
     */
    async sendBulkEmails(emails, subject, body, fromName = null, fromEmail = null) {
        const results = [];
        
        for (const email of emails) {
            const result = await this.sendEmail(email, subject, body, fromName, fromEmail);
            results.push({
                email,
                ...result
            });
            
            // Rate limiting (SMTP flood önleme)
            await this.sleep(100);
        }
        
        return results;
    }

    /**
     * Bağlantıyı kapat
     */
    close() {
        if (this.transporter) {
            this.transporter.close();
            this.isConnected = false;
            Logger.info('SMTP bağlantısı kapatıldı');
        }
    }

    /**
     * Utility: Sleep
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    classifySendError(error) {
        const message = String(error?.message || '');
        const lower = message.toLowerCase();
        const code = String(error?.code || '').toLowerCase();

        const smtpClass = classifySmtpError(message);
        if (smtpClass.category === Category.RECIPIENT_REJECTED) {
            return { type: 'recipient_rejected', retryable: false };
        }
        if (smtpClass.category === Category.CONNECTION) {
            return { type: 'timeout', retryable: true };
        }
        if (smtpClass.category === Category.PROVIDER) {
            if (lower.includes('throttl') || lower.includes('rate limit') || lower.includes('quota')) {
                return { type: 'throttling', retryable: true };
            }
            if (lower.includes('auth') || lower.includes('535')) {
                return { type: 'auth', retryable: false };
            }
            return { type: 'provider', retryable: true };
        }

        if (lower.includes('throttl') || lower.includes('rate limit')) {
            return { type: 'throttling', retryable: true };
        }
        if (lower.includes('timeout') || code.includes('etimedout') || code.includes('econnreset')) {
            return { type: 'timeout', retryable: true };
        }
        if (lower.includes('auth') || lower.includes('535')) {
            return { type: 'auth', retryable: false };
        }

        return { type: 'unknown', retryable: false };
    }
}

