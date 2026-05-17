/**
 * Kalıcı alıcı reddi → email_blacklist (SuppressionService).
 */

export async function suppressRecipientIfNeeded(pool, userId, email, category, reason) {
    if (!userId || !email) {
        return;
    }
    if (category !== 'recipient_rejected') {
        return;
    }
    const addr = String(email).trim().toLowerCase();
    if (!addr.includes('@')) {
        return;
    }
    try {
        await pool.execute(
            `INSERT IGNORE INTO email_blacklist (user_id, email, reason, created_at)
             VALUES (?, ?, ?, NOW())`,
            [userId, addr, reason ? String(reason).slice(0, 250) : 'recipient_rejected']
        );
    } catch (_) {
        /* ignore duplicate / FK */
    }
}
