import mysql from 'mysql2/promise';
import { config } from '../config.js';

let poolSingleton = null;

export function getMysqlPool() {
    if (!poolSingleton) {
        poolSingleton = mysql.createPool({
            host: config.database.host,
            port: config.database.port,
            user: config.database.user,
            password: config.database.password,
            database: config.database.database,
            timezone: config.database.timezone || '+00:00',
            waitForConnections: true,
            connectionLimit: config.worker.dbPoolLimit || 16,
            queueLimit: 0,
            enableKeepAlive: true,
            keepAliveInitialDelay: 0,
        });
    }
    return poolSingleton;
}

export async function closeMysqlPool() {
    if (poolSingleton) {
        await poolSingleton.end();
        poolSingleton = null;
    }
}
