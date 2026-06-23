/**
 * Alibaba / SMTP / ağ hatalarını kategorize eder (ErrorClassifierService).
 */

export const Category = {
    RECIPIENT_REJECTED: 'recipient_rejected',
    CONNECTION: 'connection',
    PROVIDER: 'provider',
    INTERNAL: 'internal',
    UNKNOWN: 'unknown',
};

// Kalıcı geçersiz adres → otomatik karalisteye alınmalı (suppression).
const INVALID_ADDRESS_PATTERNS = [
    /559\s+invalid\s+rcptto/i,
    /invaddr/i,
    /invalid\s+recipient/i,
    /mailbox\s+(not\s+found|unavailable|does\s+not\s+exist)/i,
    /user\s+unknown/i,
    /no\s+such\s+(user|mailbox)/i,
    /recipient\s+address\s+rejected/i,
    /550[\s-].*(user|mailbox|recipient|no\s+such|does\s+not\s+exist)/i,
];

// Geçici itibar/antispam reddi → adres geçerli olabilir, suppression YAPMA.
const SPAM_BLOCK_PATTERNS = [
    /ANTISPAM_BAT/i,
    /spam/i,
    /\bblocked\b/i,
    /reputation/i,
    /blacklist/i,
    /greylist/i,
];

const CONNECTION_PATTERNS = [
    /ECONNRESET/i,
    /ETIMEDOUT/i,
    /socket\s+(closed|hang)/i,
    /connection\s+(lost|reset|refused)/i,
    /ENOTFOUND/i,
    /EPIPE/i,
];

const PROVIDER_PATTERNS = [
    /quota/i,
    /rate\s*limit/i,
    /authentication\s+failed/i,
    /535/i,
    /account\s+disabled/i,
    /daily\s+limit/i,
];

export function classifySmtpError(message) {
    const m = String(message || '');

    // Kalıcı geçersiz adres — önce kontrol et; suppression'a uygun (permanent: true).
    for (const p of INVALID_ADDRESS_PATTERNS) {
        if (p.test(m)) {
            return {
                category: Category.RECIPIENT_REJECTED,
                code: m.includes('559') ? '559' : 'INVALID_ADDR',
                permanent: true,
            };
        }
    }
    // Geçici itibar/antispam reddi — adres geçerli olabilir, suppress etme.
    for (const p of SPAM_BLOCK_PATTERNS) {
        if (p.test(m)) {
            return {
                category: Category.RECIPIENT_REJECTED,
                code: 'SPAM_BLOCK',
                permanent: false,
            };
        }
    }
    for (const p of CONNECTION_PATTERNS) {
        if (p.test(m)) {
            return { category: Category.CONNECTION, code: 'CONN', permanent: false };
        }
    }
    for (const p of PROVIDER_PATTERNS) {
        if (p.test(m)) {
            return { category: Category.PROVIDER, code: 'PROVIDER', permanent: false };
        }
    }

    return { category: Category.UNKNOWN, code: 'UNKNOWN', permanent: false };
}
