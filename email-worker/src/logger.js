import winston from 'winston';
import { config } from './config.js';

// ─── ANSI renk paleti ────────────────────────────────────────────────────────
const A = {
    reset:  '\x1b[0m',
    bold:   '\x1b[1m',
    dim:    '\x1b[2m',
    // levels
    cyan:         '\x1b[36m',
    yellow:       '\x1b[33m',
    red:          '\x1b[31m',
    gray:         '\x1b[90m',
    brightCyan:   '\x1b[96m',
    brightYellow: '\x1b[93m',
    brightRed:    '\x1b[91m',
    brightGreen:  '\x1b[92m',
    brightWhite:  '\x1b[97m',
};

// Renk etkin mi? — PM2'de FORCE_COLOR=1 ile aktif edilir
const USE_COLOR =
    process.env.FORCE_COLOR === '1' ||
    process.env.WORKER_COLOR_LOGS === 'true' ||
    (process.stdout.isTTY === true && process.env.NO_COLOR == null);

// ─── Level konfigürasyonu ─────────────────────────────────────────────────────
const LEVELS = {
    error: { icon: '✗', label: 'ERR', accent: A.brightRed + A.bold,   msg: A.brightRed },
    warn:  { icon: '⚠', label: 'WRN', accent: A.brightYellow,          msg: A.brightYellow },
    info:  { icon: '●', label: 'INF', accent: A.brightCyan,            msg: A.reset },
    debug: { icon: '·', label: 'DBG', accent: A.gray + A.dim,          msg: A.gray + A.dim },
};

// ─── Format fonksiyonları ─────────────────────────────────────────────────────

/** Konsol: renkli, sadece saat (PM2 zaten tarih ekliyor) */
const consoleFormat = winston.format.printf(({ level, message, timestamp }) => {
    const lv = LEVELS[level] ?? LEVELS.info;
    const time = String(timestamp ?? '').slice(11, 19); // HH:mm:ss

    if (USE_COLOR) {
        return (
            `${A.gray}${time}${A.reset}  ` +
            `${lv.accent}${lv.icon} ${lv.label}${A.reset}  ` +
            `${lv.msg}${message}${A.reset}`
        );
    }
    return `[${timestamp}]  ${lv.icon} ${lv.label}  ${message}`;
});

/** Dosya: düz metin, tam timestamp — grep dostu */
const fileFormat = winston.format.printf(({ level, message, timestamp }) => {
    const lv = LEVELS[level] ?? LEVELS.info;
    return `[${timestamp}]  ${lv.label}  ${message}`;
});

// ─── Transports ───────────────────────────────────────────────────────────────
const transports = [];

transports.push(
    new winston.transports.Console({
        level: config.logging.level,
        format: winston.format.combine(
            winston.format.timestamp({ format: 'YYYY-MM-DD HH:mm:ss' }),
            consoleFormat,
        ),
    })
);

if (config.logging.enableFile) {
    const logFile = config.logging.file || 'logs/email-worker.log';

    // Genel log (info+)
    transports.push(
        new winston.transports.File({
            filename: logFile,
            level: 'info',
            maxsize: 10 * 1024 * 1024,  // 10 MB
            maxFiles: 3,
            format: winston.format.combine(
                winston.format.timestamp({ format: 'YYYY-MM-DD HH:mm:ss' }),
                fileFormat,
            ),
        })
    );

    // Hata logu (error only)
    transports.push(
        new winston.transports.File({
            filename: 'logs/error.log',
            level: 'error',
            maxsize: 5 * 1024 * 1024,
            maxFiles: 2,
            format: winston.format.combine(
                winston.format.timestamp({ format: 'YYYY-MM-DD HH:mm:ss' }),
                fileFormat,
            ),
        })
    );
}

export const logger = winston.createLogger({
    level: config.logging.level,
    transports,
    exitOnError: false,
});

// ─── Public Logger API ────────────────────────────────────────────────────────
export class Logger {
    static info(message)  { logger.info(String(message)); }
    static warn(message)  { logger.warn(String(message)); }
    static error(message) { logger.error(String(message)); }
    static debug(message) { logger.debug(String(message)); }

    /**
     * Göze çarpan ayraç — kampanya başlangıç/bitiş, startup banner için.
     * Renk aktifse mavi renkte çizer.
     */
    static divider(char = '─', length = 62) {
        const line = char.repeat(length);
        if (USE_COLOR) {
            process.stdout.write(`${A.gray}${line}${A.reset}\n`);
        } else {
            process.stdout.write(`${line}\n`);
        }
    }

    /**
     * Renkli başlık satırı (banner içinde).
     * @param {string} text
     */
    static banner(text) {
        if (USE_COLOR) {
            process.stdout.write(`${A.brightCyan}${A.bold}${text}${A.reset}\n`);
        } else {
            process.stdout.write(`${text}\n`);
        }
    }

    /** Sağ-hizalı key: value çifti için düzgün girinti. */
    static kv(key, value, width = 16) {
        const padded = String(key).padEnd(width);
        if (USE_COLOR) {
            Logger.info(`${A.gray}${padded}${A.reset}  ${value}`);
        } else {
            Logger.info(`${padded}  ${value}`);
        }
    }
}
