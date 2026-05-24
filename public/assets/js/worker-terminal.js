(function () {
    'use strict';

    const root = document.getElementById('workerTerminalPage');
    if (!root) return;

    const csrf = root.dataset.csrf || '';
    const terminal = document.getElementById('wtTerminal');
    const badge = document.getElementById('wtConnectionBadge');
    const statusBadge = document.getElementById('wtStatusBadge');
    const statusText = document.getElementById('wtStatusText');
    const restartCount = document.getElementById('wtRestartCount');
    const cpuRam = document.getElementById('wtCpuRam');
    const uptime = document.getElementById('wtUptime');
    const lastAction = document.getElementById('wtLastAction');
    const manualInput = document.getElementById('wtManualCommand');
    const runCommandBtn = document.getElementById('wtRunCommandBtn');
    const pauseBtn = document.getElementById('wtPauseBtn');
    const autoScrollBtn = document.getElementById('wtAutoScrollBtn');
    const refreshBtn = document.getElementById('wtRefreshBtn');

    let autoScroll = true;
    let paused = false;
    let eventSource = null;
    let currentLogType = 'combined';
    let reconnectTimer = null;
    let connectWatchdog = null;
    let pollTimer = null;
    let reconnectAttempts = 0;
    let isPolling = false;

    function toast(type, message) {
        if (window.iziToast && typeof window.iziToast[type] === 'function') {
            window.iziToast[type]({ title: type === 'error' ? 'Hata' : 'Bilgi', message, position: 'topRight' });
            return;
        }
        if (type === 'error') {
            console.error(message);
        }
    }

    function lineClass(line) {
        const lower = String(line).toLowerCase();
        if (lower.includes('error') || lower.includes('failed') || lower.includes('exception')) return 'nx-wt-log-line--error';
        if (lower.includes('success') || lower.includes('online') || lower.includes('completed')) return 'nx-wt-log-line--success';
        return 'nx-wt-log-line--normal';
    }

    function nowLabel() {
        const d = new Date();
        return d.toLocaleTimeString('tr-TR', { hour12: false });
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }

    function appendLine(text) {
        if (!terminal || paused) return;
        const row = document.createElement('div');
        row.className = 'nx-wt-log-line ' + lineClass(text);
        row.innerHTML = '[' + nowLabel() + '] ' + escapeHtml(String(text));
        terminal.appendChild(row);
        if (autoScroll) {
            terminal.scrollTop = terminal.scrollHeight;
        }
    }

    function clearTerminal() {
        if (terminal) {
            terminal.innerHTML = '';
        }
    }

    function setConnection(text, ok) {
        if (!badge) return;
        badge.textContent = text;
        badge.className = 'badge ' + (ok ? 'bg-success-subtle text-success-emphasis' : 'bg-warning-subtle text-warning-emphasis');
    }

    function applyStatus(data) {
        if (!data) return;
        if (!data.found) {
            statusBadge.className = 'badge bg-danger';
            statusBadge.textContent = 'Bulunamadi';
            statusText.textContent = 'email-worker PM2 icinde bulunamadi';
            restartCount.textContent = '-';
            cpuRam.textContent = '-';
            uptime.textContent = '-';
            return;
        }

        const st = String(data.status || 'stopped');
        if (st === 'online') {
            statusBadge.className = 'badge bg-success';
        } else if (st === 'errored') {
            statusBadge.className = 'badge bg-danger';
        } else {
            statusBadge.className = 'badge bg-secondary';
        }
        statusBadge.textContent = data.status_label || st;
        statusText.textContent = data.status_label || st;
        restartCount.textContent = String(data.restart_count ?? 0);
        cpuRam.textContent = String(data.cpu_percent ?? 0) + '% / ' + String(data.memory_human || '0 MB');
        uptime.textContent = data.uptime_human || '-';
    }

    function setButtonsBusy(isBusy) {
        document.querySelectorAll('.wt-action-btn, #wtRunCommandBtn').forEach((btn) => {
            btn.disabled = isBusy;
        });
    }

    async function requestJson(url, options) {
        const res = await fetch(url, options || {});
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error(data.message || 'Islem basarisiz');
        }
        return data;
    }

    async function loadStatus() {
        try {
            const data = await requestJson('/admin/worker-terminal/status');
            applyStatus(data.data || {});
        } catch (err) {
            toast('error', err.message);
        }
    }

    async function loadLogs(type, silent) {
        try {
            const data = await requestJson('/admin/worker-terminal/logs?type=' + encodeURIComponent(type) + '&lines=150');
            clearTerminal();
            const lines = String(data.output || '').split(/\r?\n/);
            lines.forEach((line) => {
                if (line.trim() !== '') appendLine(line);
            });
        } catch (err) {
            if (!silent) {
                toast('error', err.message);
            }
        }
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        isPolling = false;
    }

    function startPolling() {
        if (isPolling) return;
        stopPolling();
        isPolling = true;
        setConnection('Polling modu aktif', true);
        loadLogs(currentLogType, true);
        pollTimer = setInterval(() => {
            loadLogs(currentLogType, true);
        }, 3000);
    }

    function scheduleReconnect() {
        if (reconnectTimer) {
            clearTimeout(reconnectTimer);
        }
        reconnectAttempts += 1;
        if (reconnectAttempts >= 2) {
            startPolling();
        } else {
            setConnection('Yeniden baglaniyor...', false);
        }
        reconnectTimer = setTimeout(connectSse, 3000);
    }

    function connectSse() {
        if (reconnectTimer) {
            clearTimeout(reconnectTimer);
            reconnectTimer = null;
        }
        if (connectWatchdog) {
            clearTimeout(connectWatchdog);
            connectWatchdog = null;
        }
        if (eventSource) {
            eventSource.close();
        }
        setConnection('Baglaniyor...', false);
        eventSource = new EventSource('/admin/worker-terminal/stream?type=' + encodeURIComponent(currentLogType));
        connectWatchdog = setTimeout(() => {
            startPolling();
        }, 4000);

        eventSource.addEventListener('log', (event) => {
            try {
                const parsed = JSON.parse(event.data || '{}');
                if (parsed.line) appendLine(parsed.line);
            } catch (_err) {}
        });

        eventSource.addEventListener('done', () => {
            if (eventSource) {
                eventSource.close();
            }
            scheduleReconnect();
        });

        eventSource.onopen = function () {
            reconnectAttempts = 0;
            if (connectWatchdog) {
                clearTimeout(connectWatchdog);
                connectWatchdog = null;
            }
            stopPolling();
            setConnection('Canli baglanti aktif', true);
        };

        eventSource.onerror = function () {
            if (connectWatchdog) {
                clearTimeout(connectWatchdog);
                connectWatchdog = null;
            }
            if (eventSource) {
                eventSource.close();
            }
            scheduleReconnect();
        };
    }

    async function runAction(action) {
        setButtonsBusy(true);
        try {
            const data = await requestJson('/admin/worker-terminal/action', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({ action, _csrf: csrf })
            });

            if (data.output) appendLine(data.output);
            lastAction.textContent = action + ' -> ' + (data.message || 'tamamlandi');
            await loadStatus();
        } catch (err) {
            toast('error', err.message);
        } finally {
            setButtonsBusy(false);
        }
    }

    async function runManualCommand() {
        const command = (manualInput?.value || '').trim();
        if (!command) return;
        setButtonsBusy(true);
        try {
            const data = await requestJson('/admin/worker-terminal/command', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({ command, _csrf: csrf })
            });

            appendLine('$ ' + command);
            if (data.output) appendLine(data.output);
            lastAction.textContent = 'Komut calisti: ' + command;

            const key = 'worker_terminal_command_history';
            const prev = JSON.parse(localStorage.getItem(key) || '[]');
            prev.unshift(command);
            localStorage.setItem(key, JSON.stringify(prev.slice(0, 20)));
        } catch (err) {
            appendLine('Bu komut guvenlik nedeniyle engellendi.');
            toast('error', err.message);
        } finally {
            setButtonsBusy(false);
        }
    }

    function bindEvents() {
        refreshBtn?.addEventListener('click', () => {
            loadStatus();
            loadLogs(currentLogType, false);
        });

        pauseBtn?.addEventListener('click', () => {
            paused = !paused;
            pauseBtn.textContent = paused ? 'Devam et' : 'Durdur';
        });

        autoScrollBtn?.addEventListener('click', () => {
            autoScroll = !autoScroll;
            autoScrollBtn.textContent = autoScroll ? 'Auto-scroll Acik' : 'Auto-scroll Kapali';
        });

        document.getElementById('wtClearBtn')?.addEventListener('click', clearTerminal);

        document.getElementById('wtCopyBtn')?.addEventListener('click', async () => {
            const text = terminal?.innerText || '';
            if (!text) return;
            try {
                await navigator.clipboard.writeText(text);
                toast('success', 'Loglar panoya kopyalandi.');
            } catch (_err) {
                toast('error', 'Kopyalama basarisiz.');
            }
        });

        document.querySelectorAll('.wt-action-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const action = btn.getAttribute('data-action');
                if (action) runAction(action);
            });
        });

        document.querySelectorAll('.wt-log-tab').forEach((btn) => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.wt-log-tab').forEach((x) => x.classList.remove('active'));
                btn.classList.add('active');
                currentLogType = btn.getAttribute('data-type') || 'combined';
                loadLogs(currentLogType, false);
                connectSse();
            });
        });

        runCommandBtn?.addEventListener('click', runManualCommand);
        manualInput?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                runManualCommand();
            }
        });
    }

    function bootstrapInitialStatus() {
        const raw = document.getElementById('wtInitialStatusData')?.textContent || '{}';
        try {
            const parsed = JSON.parse(raw);
            applyStatus(parsed);
        } catch (_err) {}
    }

    bindEvents();
    bootstrapInitialStatus();
    loadLogs(currentLogType, false);
    connectSse();
    loadStatus();
    setInterval(loadStatus, 5000);
})();

