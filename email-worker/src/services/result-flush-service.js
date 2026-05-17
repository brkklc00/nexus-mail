/**
 * Gönderim sonuçlarını toplu olarak MySQL’e yazar (ResultFlushService).
 */

import { classifySmtpError, Category } from './error-classifier.js';
import { suppressRecipientIfNeeded } from './suppression-service.js';

function chunk(arr, size) {
    const out = [];
    for (let i = 0; i < arr.length; i += size) {
        out.push(arr.slice(i, i + size));
    }
    return out;
}

function normalizeStatus(s) {
    const v = String(s || '').toLowerCase();
    if (v === 'delivered' || v === 'sent') {
        return 'delivered';
    }
    if (v === 'skipped_blacklist') {
        return 'skipped_blacklist';
    }
    if (v === 'bounced') {
        return 'bounced';
    }
    return 'failed';
}

function isTerminal(prev) {
    const p = String(prev || '').toLowerCase();
    return ['delivered', 'failed', 'bounced', 'skipped_blacklist', 'suppressed'].includes(p);
}

export function createResultFlushService(pool, opts = {}) {
    const chunkSize = opts.chunkSize ?? parseInt(process.env.RECIPIENT_UPDATE_CHUNK_SIZE || '1000', 10);

    async function flushCampaignResults(orderId, userId, results) {
        if (!results?.length) {
            return { durationMs: 0, updated: 0 };
        }

        const t0 = Date.now();

        const enriched = results.map((r) => {
            const status = normalizeStatus(r.status);
            let category = null;
            let code = null;
            if (status === 'failed' || status === 'bounced') {
                const c = classifySmtpError(r.error);
                category = c.category;
                code = c.code;
            }
            return {
                ...r,
                status,
                category,
                code,
                emailLower: String(r.email || '').trim().toLowerCase(),
            };
        });

        let sentDelta = 0;
        let deliveredDelta = 0;
        let failedDelta = 0;
        let bouncedDelta = 0;

        const conn = await pool.getConnection();
        try {
            await conn.beginTransaction();

            const withId = enriched.filter((r) => Number.isFinite(Number(r.id)) && Number(r.id) > 0);
            const withoutId = enriched.filter((r) => !Number.isFinite(Number(r.id)) || Number(r.id) <= 0);

            async function flushIdRows(rows) {
                if (rows.length === 0) {
                    return;
                }

                const ids = [...new Set(rows.map((r) => Number(r.id)))];
                const placeholders = ids.map(() => '?').join(',');

                const [prevRows] = await conn.query(
                    `SELECT id, status FROM email_order_emails WHERE order_id = ? AND id IN (${placeholders})`,
                    [orderId, ...ids]
                );
                const prevMap = new Map(prevRows.map((pr) => [pr.id, String(pr.status || '')]));

                const eligible = [];
                for (const r of rows) {
                    const id = Number(r.id);
                    const prev = prevMap.get(id);
                    if (prev === undefined) {
                        continue;
                    }
                    if (isTerminal(prev)) {
                        continue;
                    }
                    const pn = prev.toLowerCase();
                    if (pn === r.status) {
                        continue;
                    }
                    eligible.push(r);

                    if (r.status === 'delivered') {
                        sentDelta += 1;
                        deliveredDelta += 1;
                    } else if (r.status === 'failed') {
                        failedDelta += 1;
                    } else if (r.status === 'bounced') {
                        bouncedDelta += 1;
                    }
                }

                if (eligible.length === 0) {
                    return;
                }

                for (const part of chunk(eligible, chunkSize)) {
                    const casePartsStatus = [];
                    const casePartsMid = [];
                    const casePartsErr = [];
                    const casePartsCat = [];
                    const casePartsCode = [];
                    const casePartsDel = [];
                    const casePartsFail = [];
                    const paramsStatus = [];
                    const paramsMid = [];
                    const paramsErr = [];
                    const paramsCat = [];
                    const paramsCode = [];
                    const paramsDel = [];
                    const paramsFail = [];
                    const partIds = [];

                    for (const r of part) {
                        const id = Number(r.id);
                        partIds.push(id);
                        casePartsStatus.push('WHEN ? THEN ?');
                        paramsStatus.push(id, r.status);
                        casePartsMid.push('WHEN ? THEN ?');
                        paramsMid.push(id, r.message_id ?? null);
                        casePartsErr.push('WHEN ? THEN ?');
                        paramsErr.push(id, r.error ?? null);
                        casePartsCat.push('WHEN ? THEN ?');
                        paramsCat.push(id, r.category ?? null);
                        casePartsCode.push('WHEN ? THEN ?');
                        paramsCode.push(id, r.code ?? null);

                        if (r.status === 'delivered') {
                            casePartsDel.push('WHEN ? THEN NOW()');
                            paramsDel.push(id);
                        }
                        if (r.status === 'failed' || r.status === 'bounced') {
                            casePartsFail.push('WHEN ? THEN NOW()');
                            paramsFail.push(id);
                        }
                    }

                    let delSql = 'delivered_at';
                    if (casePartsDel.length > 0) {
                        delSql = `CASE id ${casePartsDel.join(' ')} ELSE delivered_at END`;
                    }
                    let failSql = 'failed_at';
                    if (casePartsFail.length > 0) {
                        failSql = `CASE id ${casePartsFail.join(' ')} ELSE failed_at END`;
                    }

                    const sql = `
                        UPDATE email_order_emails SET
                            status = CASE id ${casePartsStatus.join(' ')} ELSE status END,
                            message_id = CASE id ${casePartsMid.join(' ')} ELSE message_id END,
                            error = CASE id ${casePartsErr.join(' ')} ELSE error END,
                            last_error_category = CASE id ${casePartsCat.join(' ')} ELSE last_error_category END,
                            last_error_code = CASE id ${casePartsCode.join(' ')} ELSE last_error_code END,
                            delivered_at = ${delSql},
                            failed_at = ${failSql},
                            locked_at = NULL,
                            locked_by = NULL
                        WHERE order_id = ? AND id IN (${partIds.map(() => '?').join(',')})
                    `;

                    await conn.execute(sql, [
                        ...paramsStatus,
                        ...paramsMid,
                        ...paramsErr,
                        ...paramsCat,
                        ...paramsCode,
                        ...paramsDel,
                        ...paramsFail,
                        orderId,
                        ...partIds,
                    ]);
                }
            }

            async function resolveWithoutIdRows(rows) {
                if (rows.length === 0) {
                    return [];
                }

                const resolved = [];

                for (const part of chunk(rows, chunkSize)) {
                    const uniqueEmails = [
                        ...new Set(
                            part
                                .map((r) => r.emailLower)
                                .filter((v) => typeof v === 'string' && v.length > 0)
                        ),
                    ];

                    if (uniqueEmails.length === 0) {
                        continue;
                    }

                    const placeholders = uniqueEmails.map(() => '?').join(',');
                    const [foundRows] = await conn.query(
                        `SELECT id, LOWER(email) AS email_lower
                         FROM email_order_emails
                         WHERE order_id = ?
                           AND LOWER(email) IN (${placeholders})
                           AND status NOT IN ('delivered', 'failed', 'bounced', 'skipped_blacklist', 'suppressed')
                         ORDER BY id ASC`,
                        [orderId, ...uniqueEmails]
                    );

                    const idBuckets = new Map();
                    for (const fr of foundRows) {
                        const key = String(fr.email_lower || '').trim().toLowerCase();
                        if (!key) {
                            continue;
                        }
                        if (!idBuckets.has(key)) {
                            idBuckets.set(key, []);
                        }
                        idBuckets.get(key).push(Number(fr.id));
                    }

                    for (const r of part) {
                        const key = String(r.emailLower || '').trim().toLowerCase();
                        if (!key) {
                            continue;
                        }
                        const ids = idBuckets.get(key);
                        if (!ids || ids.length === 0) {
                            continue;
                        }
                        const id = ids.shift();
                        if (!Number.isFinite(id) || id <= 0) {
                            continue;
                        }
                        resolved.push({ ...r, id });
                    }
                }

                return resolved;
            }

            const resolvedWithoutId = await resolveWithoutIdRows(withoutId);
            await flushIdRows([...withId, ...resolvedWithoutId]);

            if (sentDelta || deliveredDelta || failedDelta || bouncedDelta) {
                await conn.execute(
                    `UPDATE email_orders SET
                        sent = sent + ?,
                        delivered = delivered + ?,
                        failed = failed + ?,
                        bounced = bounced + ?,
                        updated_at = NOW()
                     WHERE id = ?`,
                    [sentDelta, deliveredDelta, failedDelta, bouncedDelta, orderId]
                );
            }

            await conn.commit();

            if (userId) {
                for (const r of enriched) {
                    if (r.category === Category.RECIPIENT_REJECTED && r.status === 'failed') {
                        await suppressRecipientIfNeeded(pool, userId, r.emailLower, 'recipient_rejected', r.error);
                    }
                }
            }
        } catch (e) {
            await conn.rollback();
            throw e;
        } finally {
            conn.release();
        }

        return { durationMs: Date.now() - t0, updated: enriched.length };
    }

    return { flushCampaignResults };
}
