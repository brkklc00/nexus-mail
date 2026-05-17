/**
 * Batch metrik satırı (CampaignMetricService) — campaign_batch_metrics tablosuna yazar.
 */

export async function insertBatchMetric(pool, row) {
    const {
        campaignId,
        workerId,
        batchNo,
        batchSize,
        successCount,
        failedCount,
        retryCount,
        recipientRejectedCount,
        connectionErrorCount,
        providerErrorCount,
        internalErrorCount,
        smtpDurationMs,
        dbFlushDurationMs,
        totalDurationMs,
        queueWaitMs,
        laneCount,
        smtpAccountCount,
        topErrorCode,
        topErrorMessage,
        startedAt,
        finishedAt,
    } = row;

    await pool.execute(
        `INSERT INTO campaign_batch_metrics (
            campaign_id, worker_id, batch_no, batch_size,
            success_count, failed_count, retry_count,
            recipient_rejected_count, connection_error_count, provider_error_count, internal_error_count,
            smtp_duration_ms, db_flush_duration_ms, total_duration_ms, queue_wait_ms,
            lane_count, smtp_account_count,
            top_error_code, top_error_message,
            started_at, finished_at
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
        [
            campaignId,
            workerId,
            batchNo,
            batchSize,
            successCount,
            failedCount,
            retryCount || 0,
            recipientRejectedCount,
            connectionErrorCount,
            providerErrorCount,
            internalErrorCount,
            smtpDurationMs,
            dbFlushDurationMs,
            totalDurationMs,
            queueWaitMs || 0,
            laneCount || 0,
            smtpAccountCount || 0,
            topErrorCode || null,
            topErrorMessage ? String(topErrorMessage).slice(0, 512) : null,
            startedAt || null,
            finishedAt || null,
        ]
    );
}
