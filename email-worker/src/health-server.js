import http from 'http';
import { Logger } from './logger.js';

/**
 * Basit sağlık endpoint’i (Express yok — Node http).
 */
export function startHealthServer(port, getSnapshot) {
    if (!port || port < 1) {
        return null;
    }

    const server = http.createServer((req, res) => {
        if (req.url === '/health' || req.url === '/healthz') {
            try {
                const body = JSON.stringify({
                    ok: true,
                    service: 'nexus-email-worker',
                    ts: new Date().toISOString(),
                    ...(typeof getSnapshot === 'function' ? getSnapshot() : {}),
                });
                res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
                res.end(body);
            } catch (e) {
                res.writeHead(500);
                res.end();
            }
            return;
        }
        res.writeHead(404);
        res.end();
    });

    server.listen(port, () => {
        Logger.info(`Health HTTP :${port} (/health)`);
    });

    return server;
}
