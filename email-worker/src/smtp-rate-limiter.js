/**
 * Per-SMTP per-minute token bucket rate limiter.
 *
 * JS is single-threaded, so all Map accesses are implicitly atomic —
 * no mutex needed. Multiple lanes calling consume() for DIFFERENT smtp IDs
 * are fully independent; multiple calls for the SAME smtp ID queue correctly
 * because each await yields control, and the next microtask resumes after the
 * previous consume() has already deducted its tokens.
 */
export class SmtpRateLimiter {
    constructor() {
        // smtpId (number|string) -> { tokens: number, windowStart: number }
        this._windows = new Map();
    }

    /**
     * Block until `count` tokens are available for `smtpId` within the
     * current 60-second window.  Resets window automatically when it expires.
     *
     * @param {number|string} smtpId
     * @param {number}        count          emails to consume
     * @param {number}        limitPerMinute 0 or negative = unlimited (returns immediately)
     */
    async consume(smtpId, count, limitPerMinute) {
        if (!limitPerMinute || limitPerMinute <= 0 || count <= 0) return;

        const key = String(smtpId);

        while (true) {
            const now = Date.now();
            let w = this._windows.get(key);

            if (!w || now - w.windowStart >= 60_000) {
                w = { tokens: limitPerMinute, windowStart: now };
                this._windows.set(key, w);
            }

            if (w.tokens >= count) {
                w.tokens -= count;
                return;
            }

            const msUntilReset = 60_000 - (now - w.windowStart);
            const waitMs = Math.max(50, msUntilReset + 10);
            await new Promise(resolve => setTimeout(resolve, waitMs));
        }
    }

    /** Force-reset the window for a specific SMTP (e.g. after API reports minute reset). */
    reset(smtpId) {
        this._windows.delete(String(smtpId));
    }

    resetAll() {
        this._windows.clear();
    }
}
