import winston from 'winston';
import { config } from './config.js';

const { combine, timestamp, printf, colorize } = winston.format;

// Renksiz log formatı
const plainFormat = printf(({ level, message, timestamp }) => {
    const cleanLevel = level.toUpperCase().padEnd(5);
    return `[${timestamp}] ${cleanLevel} | ${message}`;
});

// Transports - sadece console (file log kapalı yerelde)
const transports = [];

// Console her zaman açık (hata ayıklama için)
transports.push(
    new winston.transports.Console({
        level: config.logging.level,
        format: combine(
            timestamp({ format: 'YYYY-MM-DD HH:mm:ss' }),
            plainFormat
        )
    })
);

// File logging sadece production'da veya açıkça istenirse
if (config.logging.enableFile) {
    transports.push(
        new winston.transports.File({ 
            filename: 'logs/error.log', 
            level: 'error',
            maxsize: 5242880, // 5MB
            maxFiles: 2
        })
    );
}

export const logger = winston.createLogger({
    level: config.logging.level,
    format: combine(
        timestamp({ format: 'YYYY-MM-DD HH:mm:ss' }),
        plainFormat
    ),
    transports
});

export class Logger {
    static info(message) {
        logger.info(message);
    }

    static error(message) {
        logger.error(message);
    }

    static warn(message) {
        logger.warn(message);
    }

    static debug(message) {
        logger.debug(message);
    }
}

