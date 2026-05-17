import { Logger } from './logger.js';
import { ApiClient } from './api-client.js';
import { SmtpClient } from './smtp-client.js';
import { config } from './config.js';

/**
 * LEGACY VERSION - Küçük kampanyalar için
 * Milyonlarca email için campaign-processor.js (optimized) kullanın
 */
export class CampaignProcessorLegacy {
    constructor() {
        this.apiClient = new ApiClient();
        // Telegram bildirimler kaldırıldı
        this.processing = new Set();
    }

    // ... (eski kod buraya)
}
