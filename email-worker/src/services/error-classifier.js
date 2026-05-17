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

const RECIPIENT_PATTERNS = [
    /559\s+invalid\s+rcptto/i,
    /invaddr\s+reject/i,
    /ANTISPAM_BAT/i,
    /invalid\s+recipient/i,
    /mailbox\s+not\s+found/i,
    /user\s+unknown/i,
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

    for (const p of RECIPIENT_PATTERNS) {
        if (p.test(m)) {
            return {
                category: Category.RECIPIENT_REJECTED,
                code: m.includes('559') ? '559' : 'RCPT_REJECT',
            };
        }
    }
    for (const p of CONNECTION_PATTERNS) {
        if (p.test(m)) {
            return { category: Category.CONNECTION, code: 'CONN' };
        }
    }
    for (const p of PROVIDER_PATTERNS) {
        if (p.test(m)) {
            return { category: Category.PROVIDER, code: 'PROVIDER' };
        }
    }

    return { category: Category.UNKNOWN, code: 'UNKNOWN' };
}
