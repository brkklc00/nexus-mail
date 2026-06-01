(function () {
    'use strict';

    const app = document.getElementById('smApp');
    if (!app) return;

    const csrfToken = app.getAttribute('data-csrf') || '';
    const state = {
        metrics: null,
        maintenance: false,
        logs: [],
        currentLogName: null,
        currentLogRaw: '',
        files: [],
        currentPath: '',
        filePage: 1,
        filePerPage: 60,
        fileTotalPages: 1,
        fileSearch: '',
        editingFilePath: null,
        createType: 'file',
        backups: { database: [], files: [] },
        maintenanceSummary: null,
        maintenanceCards: [],
        maintenancePreviewByOperation: {},
        maintenancePollingTimer: null,
        maintenanceJobIds: {},
    };

    const maintenanceOperationConfig = {
        cleanup_email_orders: {
            label: 'Email Siparişleri',
            desc: 'Order özetleri korunur; detaylar temizlenir veya arşivlenir.',
            targetTable: 'email_orders,email_order_emails',
            risk: 'medium'
        },
        cleanup_email_order_details: {
            label: 'Alıcı / Recipient Detayları',
            desc: 'Recipient detay satırları cursor-batch ile işlenir.',
            targetTable: 'email_order_emails',
            risk: 'low'
        },
        cleanup_email_send_results: {
            label: 'Gönderim Sonuçları',
            desc: 'Gönderim sonuçları (status/error/message) detayları temizlenir.',
            targetTable: 'email_order_emails',
            risk: 'low'
        },
        cleanup_worker_batch_results: {
            label: 'Worker Job Geçmişi',
            desc: 'Campaign batch metrik ve geçmiş verileri temizlenir.',
            targetTable: 'campaign_batch_metrics',
            risk: 'medium'
        },
        cleanup_system_logs: {
            label: 'Loglar',
            desc: 'Log/audit tablosu satırları retention ile temizlenir.',
            targetTable: 'email_order_approval_logs,email_template_test_logs',
            risk: 'medium'
        },
        cleanup_export_files: {
            label: 'Export / Temp Dosyalar',
            desc: 'Eski export dosyaları worker tarafında silinir.',
            targetTable: 'storage/exports',
            risk: 'low'
        },
        cleanup_temp_files: {
            label: 'Geçici Dosyalar',
            desc: 'Tmp/cache dosyaları güvenli eşik ile temizlenir.',
            targetTable: 'storage/tmp',
            risk: 'low'
        },
        database_optimize_tables: {
            label: 'Veritabanı Optimizasyonu',
            desc: 'OPTIMIZE TABLE işlemi worker ile kontrollü çalıştırılır.',
            targetTable: 'email_orders,email_order_emails,data_pool_jobs',
            risk: 'high'
        }
    };

    function api(url, options) {
        const opts = options || {};
        opts.headers = opts.headers || {};
        if (opts.method && opts.method !== 'GET') {
            opts.headers['X-CSRF-Token'] = csrfToken;
        }
        return fetch(url, opts).then(async (res) => {
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.success === false) {
                throw new Error(data.message || ('HTTP ' + res.status));
            }
            return data;
        });
    }

    function setBusy(button, busy, busyText) {
        if (!button) return;
        if (busy) {
            if (!button.dataset.originalHtml) {
                button.dataset.originalHtml = button.innerHTML;
            }
            button.disabled = true;
            button.classList.add('is-loading');
            button.setAttribute('aria-busy', 'true');
            const label = busyText || 'İşleniyor...';
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>' + escapeHtml(label);
            return;
        }
        if (button.dataset.originalHtml) {
            button.innerHTML = button.dataset.originalHtml;
            delete button.dataset.originalHtml;
        }
        button.disabled = false;
        button.classList.remove('is-loading');
        button.removeAttribute('aria-busy');
    }

    function toast(message, type) {
        const map = { success: 'success', error: 'danger', warning: 'warning', info: 'info' };
        const cls = map[type || 'info'] || 'info';
        const icon = cls === 'success' ? 'check-circle' : (cls === 'danger' ? 'alert-circle' : (cls === 'warning' ? 'alert-triangle' : 'info'));
        const html = [
            '<div class="alert alert-' + cls + ' alert-dismissible fade show" role="alert">',
            '<i data-feather="' + icon + '" class="icon-xs me-2"></i>',
            message,
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>',
            '</div>'
        ].join('');
        const area = document.getElementById('smAlertArea');
        area.insertAdjacentHTML('beforeend', html);
        if (typeof feather !== 'undefined') feather.replace();
    }

    function statusClass(percent) {
        if (percent >= 85) return { dot: 'bad', bar: 'bg-danger' };
        if (percent >= 65) return { dot: 'warn', bar: 'bg-warning' };
        return { dot: 'ok', bar: 'bg-success' };
    }

    function setDot(elId, percent) {
        const el = document.getElementById(elId);
        if (!el) return;
        const cls = statusClass(percent).dot;
        el.className = 'nx-sm-dot ' + cls;
    }

    function setProgress(id, percent) {
        const bar = document.getElementById(id);
        if (!bar) return;
        const cls = statusClass(percent).bar;
        bar.className = 'progress-bar ' + cls;
        bar.style.width = Math.max(0, Math.min(100, percent)) + '%';
    }

    function updateMetricsUI(payload) {
        state.metrics = payload;
        const server = payload.server || {};
        const disk = payload.disk || {};
        const db = payload.database || {};
        const grid = payload.system || [];

        const cpuUsage = Number(server.cpu && server.cpu.usage || 0);
        const ramUsage = Number(server.memory && server.memory.usage_percent || 0);
        const diskUsage = Number(disk.usage_percent || 0);

        document.getElementById('smCpuValue').textContent = cpuUsage.toFixed(1) + '%';
        document.getElementById('smCpuHint').textContent = ((server.cpu && server.cpu.cores) || 0) + ' çekirdek';
        setProgress('smCpuBar', cpuUsage);
        setDot('smCpuDot', cpuUsage);

        document.getElementById('smRamValue').textContent = ramUsage.toFixed(1) + '%';
        document.getElementById('smRamHint').textContent = (server.memory && server.memory.used || '-') + ' / ' + (server.memory && server.memory.total || '-');
        setProgress('smRamBar', ramUsage);
        setDot('smRamDot', ramUsage);

        document.getElementById('smDiskValue').textContent = diskUsage.toFixed(1) + '%';
        document.getElementById('smDiskHint').textContent = (disk.used || '-') + ' / ' + (disk.total || '-');
        setProgress('smDiskBar', diskUsage);
        setDot('smDiskDot', diskUsage);

        document.getElementById('smDbValue').textContent = db.size || '--';
        document.getElementById('smDbHint').textContent = (db.tables || 0) + ' tablo · ' + (db.connections || 0) + ' bağlantı';
        document.getElementById('smDbSizeInline').textContent = db.size || '-';

        let statusText = 'Stabil';
        let statusPercent = Math.max(cpuUsage, ramUsage, diskUsage);
        if (statusPercent >= 85) statusText = 'Kritik';
        else if (statusPercent >= 65) statusText = 'Uyarı';
        document.getElementById('smStatusValue').textContent = statusText;
        document.getElementById('smStatusHint').textContent = 'Son yenileme: ' + (payload.timestamp || '-');
        setDot('smStatusDot', statusPercent);
        setDot('smDbDot', Math.min(100, Math.max(0, Number(db.size_bytes || 0) / (1024 * 1024 * 1024) * 10)));

        const gridWrap = document.getElementById('smSystemGrid');
        gridWrap.innerHTML = '';
        grid.forEach(function (item) {
            const card = document.createElement('div');
            card.className = 'nx-sm-metric-item';
            card.innerHTML = '<span class="nx-sm-metric-label">' + escapeHtml(item.label || '-') + '</span><span class="nx-sm-metric-value">' + escapeHtml(item.value || '-') + '</span>';
            gridWrap.appendChild(card);
        });

        state.maintenance = !!payload.maintenance_mode;
        updateMaintenanceButton();
    }

    function updateMaintenanceButton() {
        const text = document.getElementById('smMaintenanceToggleText');
        const btn = document.getElementById('smMaintenanceToggleBtn');
        if (!text || !btn) return;
        if (state.maintenance) {
            text.textContent = 'Bakım Modunu Kapat';
            btn.classList.add('btn-warning');
            btn.classList.remove('btn-outline-light');
        } else {
            text.textContent = 'Bakım Modunu Aç';
            btn.classList.remove('btn-warning');
            btn.classList.add('btn-outline-light');
        }
    }

    function loadMetrics() {
        return api('/admin/system-monitor/metrics')
            .then(updateMetricsUI)
            .catch(function (err) { toast(err.message, 'error'); });
    }

    function renderLogs() {
        const tbody = document.getElementById('smLogsTableBody');
        if (!state.logs.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="nx-sm-empty">Log bulunamadı.</td></tr>';
            return;
        }
        tbody.innerHTML = state.logs.map(function (log) {
            return '<tr>' +
                '<td><strong>' + escapeHtml(log.label || log.name) + '</strong><div class="small text-muted">' + escapeHtml(log.group || '-') + '</div></td>' +
                '<td>' + escapeHtml(log.size || '-') + '</td>' +
                '<td>' + escapeHtml(log.modified || '-') + '</td>' +
                '<td class="text-end">' +
                '<div class="d-inline-flex gap-1">' +
                '<button class="btn btn-sm btn-outline-primary sm-log-view-btn" data-name="' + escapeHtmlAttr(log.name) + '"><i data-feather="eye" class="icon-xs me-1"></i>Görüntüle</button>' +
                '<button class="btn btn-sm btn-outline-info sm-log-download-btn" data-name="' + escapeHtmlAttr(log.name) + '"><i data-feather="download" class="icon-xs me-1"></i>İndir</button>' +
                '<button class="btn btn-sm btn-outline-danger sm-log-clear-btn" data-name="' + escapeHtmlAttr(log.name) + '"><i data-feather="trash-2" class="icon-xs me-1"></i>Temizle</button>' +
                '</div></td></tr>';
        }).join('');
        if (typeof feather !== 'undefined') feather.replace();
    }

    function loadLogs(filterGroup) {
        return api('/admin/system-monitor/logs')
            .then(function (data) {
                state.logs = (data.items || []).filter(function (i) {
                    if (!filterGroup) return true;
                    if (filterGroup === 'error') return (i.label || '').toLowerCase().includes('error') || (i.label || '').toLowerCase().includes('err');
                    if (filterGroup === 'app') return i.group === 'application';
                    if (filterGroup === 'worker') return i.group === 'worker';
                    return true;
                });
                renderLogs();
            })
            .catch(function (err) { toast(err.message, 'error'); });
    }

    function showConfirm(title, message, buttonClass, callback) {
        document.getElementById('smConfirmTitle').textContent = title;
        document.getElementById('smConfirmMessage').innerHTML = message;
        const btn = document.getElementById('smConfirmActionBtn');
        btn.className = 'btn ' + (buttonClass || 'btn-danger');
        const cloned = btn.cloneNode(true);
        btn.parentNode.replaceChild(cloned, btn);
        cloned.addEventListener('click', function () {
            const modal = bootstrap.Modal.getInstance(document.getElementById('smConfirmModal'));
            if (modal) modal.hide();
            callback();
        }, { once: true });
        new bootstrap.Modal(document.getElementById('smConfirmModal')).show();
    }

    function openLogModal(name) {
        state.currentLogName = name;
        document.getElementById('smLogModalTitle').textContent = 'Log Görüntüleyici: ' + name;
        new bootstrap.Modal(document.getElementById('smLogModal')).show();
        loadLogTail();
    }

    function loadLogTail() {
        if (!state.currentLogName) return;
        const lines = Number(document.getElementById('smLogLineSelect').value || '100');
        const url = '/admin/system-monitor/logs/' + encodeURIComponent(state.currentLogName) + '/tail?lines=' + lines;
        api(url).then(function (res) {
            state.currentLogRaw = res.content || '';
            applyLogSearchFilter();
            if (document.getElementById('smLogAutoScroll').checked) {
                const viewer = document.getElementById('smLogViewer');
                viewer.scrollTop = viewer.scrollHeight;
            }
        }).catch(function (err) {
            document.getElementById('smLogViewer').textContent = 'Hata: ' + err.message;
        });
    }

    function applyLogSearchFilter() {
        const q = (document.getElementById('smLogSearch').value || '').toLowerCase();
        if (!q) {
            document.getElementById('smLogViewer').textContent = state.currentLogRaw;
            return;
        }
        const lines = state.currentLogRaw.split('\n').filter(function (line) {
            return line.toLowerCase().includes(q);
        });
        document.getElementById('smLogViewer').textContent = lines.join('\n');
    }

    function loadFiles() {
        const url = '/admin/system-monitor/files?path=' + encodeURIComponent(state.currentPath) +
            '&page=' + state.filePage + '&per_page=' + state.filePerPage + '&q=' + encodeURIComponent(state.fileSearch);
        api(url).then(function (res) {
            state.files = res.items || [];
            state.fileTotalPages = res.pagination ? res.pagination.total_pages : 1;
            renderFiles(res);
        }).catch(function (err) {
            toast(err.message, 'error');
        });
    }

    function typeBadge(type) {
        const map = {
            folder: 'nx-sm-badge-folder',
            file: 'nx-sm-badge-file',
            config: 'nx-sm-badge-config',
            log: 'nx-sm-badge-log',
            migration: 'nx-sm-badge-migration',
            asset: 'nx-sm-badge-asset'
        };
        return map[type] || 'nx-sm-badge-file';
    }

    function renderFiles(res) {
        document.getElementById('smPathBreadcrumb').value = 'Ana Dizin' + (state.currentPath ? ' / ' + state.currentPath : '');
        const tbody = document.getElementById('smFilesTableBody');
        if (!state.files.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="nx-sm-empty">Dosya bulunamadı.</td></tr>';
        } else {
            tbody.innerHTML = state.files.map(function (f) {
                const safeBadge = f.is_sensitive ? '<span class="badge bg-danger-subtle text-danger-emphasis ms-1">Hassas</span>' : '';
                return '<tr>' +
                    '<td>' + (f.is_dir ? '<i data-feather="folder" class="icon-xs me-1 nx-sm-file-folder"></i>' : '<i data-feather="file-text" class="icon-xs me-1 text-primary"></i>') + '<span class="nx-sm-file-name">' + escapeHtml(f.name) + '</span>' + safeBadge + '</td>' +
                    '<td><span class="badge nx-sm-file-badge ' + typeBadge(f.type_badge) + '">' + escapeHtml(fileTypeLabel(f.type_badge)) + '</span></td>' +
                    '<td>' + escapeHtml(f.size_formatted || '-') + '</td>' +
                    '<td>' + escapeHtml(f.modified || '-') + '</td>' +
                    '<td class="text-end"><div class="d-inline-flex gap-1 flex-wrap justify-content-end">' +
                    (f.is_dir
                        ? '<button class="btn btn-sm btn-outline-primary sm-file-enter-btn" data-path="' + escapeHtmlAttr(f.path) + '">Gir</button>'
                        : '<button class="btn btn-sm btn-outline-primary sm-file-view-btn" data-path="' + escapeHtmlAttr(f.path) + '">Görüntüle</button>' +
                          '<button class="btn btn-sm btn-outline-secondary sm-file-edit-btn" data-path="' + escapeHtmlAttr(f.path) + '">Düzenle</button>' +
                          '<button class="btn btn-sm btn-outline-info sm-file-download-btn" data-path="' + escapeHtmlAttr(f.path) + '">İndir</button>') +
                    '<button class="btn btn-sm btn-outline-warning sm-file-rename-btn" data-path="' + escapeHtmlAttr(f.path) + '">Yeniden Adlandır</button>' +
                    '<button class="btn btn-sm btn-outline-danger sm-file-delete-btn" data-path="' + escapeHtmlAttr(f.path) + '" data-name="' + escapeHtmlAttr(f.name) + '">Sil</button>' +
                    '</div></td></tr>';
            }).join('');
        }
        const page = (res.pagination && res.pagination.page) || state.filePage;
        const totalPages = (res.pagination && res.pagination.total_pages) || 1;
        const total = (res.pagination && res.pagination.total) || state.files.length;
        document.getElementById('smFilesPageInfo').textContent = 'Sayfa ' + page + ' / ' + totalPages + ' · Toplam ' + total + ' kayıt';
        document.getElementById('smFilesPrevBtn').disabled = page <= 1;
        document.getElementById('smFilesNextBtn').disabled = page >= totalPages;
        if (typeof feather !== 'undefined') feather.replace();
    }

    function fileTypeLabel(type) {
        const map = { folder: 'Klasör', file: 'Dosya', config: 'Config', log: 'Log', migration: 'Migration', asset: 'Asset' };
        return map[type] || 'Dosya';
    }

    function openEditor(path) {
        api('/admin/system-monitor/file?path=' + encodeURIComponent(path)).then(function (res) {
            state.editingFilePath = path;
            document.getElementById('smEditorTitle').textContent = 'Dosya Düzenle: ' + path;
            document.getElementById('smEditorMeta').textContent = 'Boyut: ' + (res.size || '-') + ' · Son değişiklik: ' + (res.modified || '-');
            document.getElementById('smEditorTextarea').value = res.content || '';
            new bootstrap.Modal(document.getElementById('smEditorModal')).show();
        }).catch(function (err) { toast(err.message, 'error'); });
    }

    function saveEditor() {
        if (!state.editingFilePath) return;
        const form = new URLSearchParams();
        form.set('path', state.editingFilePath);
        form.set('content', document.getElementById('smEditorTextarea').value || '');
        form.set('backup_before_save', document.getElementById('smBackupBeforeSave').checked ? '1' : '0');
        api('/admin/system-monitor/file/save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: form.toString()
        }).then(function (res) {
            toast(res.message || 'Kaydedildi', 'success');
            const modal = bootstrap.Modal.getInstance(document.getElementById('smEditorModal'));
            if (modal) modal.hide();
            loadFiles();
        }).catch(function (err) { toast(err.message, 'error'); });
    }

    function showCreateModal(type) {
        state.createType = type;
        document.getElementById('smCreateTitle').textContent = type === 'dir' ? 'Yeni Klasör Oluştur' : 'Yeni Dosya Oluştur';
        document.getElementById('smCreateName').value = '';
        new bootstrap.Modal(document.getElementById('smCreateModal')).show();
    }

    function createItem() {
        const name = (document.getElementById('smCreateName').value || '').trim();
        if (!name) {
            toast('Ad alanı zorunlu', 'warning');
            return;
        }
        const form = new URLSearchParams();
        form.set('path', state.currentPath);
        form.set('name', name);
        form.set('type', state.createType);
        api('/admin/system-monitor/file/create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: form.toString()
        }).then(function (res) {
            toast(res.message || 'Oluşturuldu', 'success');
            const modal = bootstrap.Modal.getInstance(document.getElementById('smCreateModal'));
            if (modal) modal.hide();
            loadFiles();
        }).catch(function (err) { toast(err.message, 'error'); });
    }

    function uploadFile(file) {
        if (!file) return;
        const fd = new FormData();
        fd.append('path', state.currentPath);
        fd.append('upload', file);
        fd.append('_csrf', csrfToken);
        fetch('/admin/system-monitor/file/upload', { method: 'POST', body: fd })
            .then(async function (res) {
                const data = await res.json().catch(() => ({}));
                if (!res.ok || data.success === false) throw new Error(data.message || 'Yükleme başarısız');
                toast(data.message || 'Dosya yüklendi', 'success');
                loadFiles();
            })
            .catch(function (err) { toast(err.message, 'error'); });
    }

    function downloadFile(path) {
        window.location.href = '/admin/system-monitor/file/download?path=' + encodeURIComponent(path);
    }

    function loadBackups() {
        api('/admin/system-monitor/backups').then(function (res) {
            state.backups = res.backups || { database: [], files: [] };
            renderBackups('database', state.backups.database || []);
            renderBackups('files', state.backups.files || []);

            const dbLast = state.backups.database[0] ? state.backups.database[0].date : '-';
            const fileLast = state.backups.files[0] ? state.backups.files[0].date : '-';
            document.getElementById('smDbLastBackup').textContent = dbLast;
            document.getElementById('smFileLastBackup').textContent = fileLast;
            document.getElementById('smProjectSizeInline').textContent = state.metrics && state.metrics.disk ? state.metrics.disk.used : '-';
        }).catch(function (err) { toast(err.message, 'error'); });
    }

    function renderBackups(type, list) {
        const wrap = document.getElementById(type === 'database' ? 'smBackupDbTableWrap' : 'smBackupFilesTableWrap');
        if (!list.length) {
            wrap.innerHTML = '<div class="nx-sm-empty"><i data-feather="archive" class="icon-sm me-1"></i>Henüz yedek bulunmuyor.</div>';
            if (typeof feather !== 'undefined') feather.replace();
            return;
        }
        wrap.innerHTML = '<div class="table-responsive"><table class="table table-hover nx-sm-table mb-0">' +
            '<thead><tr><th>Dosya Adı</th><th>Boyut</th><th>Oluşturulma</th><th>Tür</th><th class="text-end">İşlemler</th></tr></thead><tbody>' +
            list.map(function (b) {
                return '<tr><td>' + escapeHtml(b.name) + '</td><td>' + escapeHtml(b.size) + '</td><td>' + escapeHtml(b.date) + '</td><td>' + (type === 'database' ? 'DB' : 'Dosya') + '</td>' +
                    '<td class="text-end"><div class="d-inline-flex gap-1">' +
                    '<button class="btn btn-sm btn-outline-primary sm-backup-download-btn" data-type="' + type + '" data-name="' + escapeHtmlAttr(b.name) + '">İndir</button>' +
                    (type === 'database' ? '<button class="btn btn-sm btn-outline-warning sm-backup-restore-btn" data-name="' + escapeHtmlAttr(b.name) + '">Geri Yükle</button>' : '') +
                    '<button class="btn btn-sm btn-outline-danger sm-backup-delete-btn" data-type="' + type + '" data-name="' + escapeHtmlAttr(b.name) + '">Sil</button>' +
                    '</div></td></tr>';
            }).join('') + '</tbody></table></div>';
    }

    function createBackup(kind) {
        const endpoint = kind === 'database' ? '/admin/system-monitor/backup/database' : '/admin/system-monitor/backup/files';
        const buttonId = kind === 'database' ? 'smBackupDbBtn' : 'smBackupFilesBtn';
        const button = document.getElementById(buttonId);
        setBusy(button, true, kind === 'database' ? 'Yedekleniyor...' : 'Arşivleniyor...');
        api(endpoint, { method: 'POST' }).then(function (res) {
            toast(res.message || 'Yedek oluşturuldu', 'success');
            loadBackups();
        }).catch(function (err) {
            toast(err.message, 'error');
        }).finally(function () {
            setBusy(button, false);
        });
    }

    function deleteBackup(type, name) {
        const form = new URLSearchParams();
        form.set('type', type);
        form.set('file', name);
        api('/admin/system-monitor/delete-backup', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: form.toString()
        }).then(function (res) {
            toast(res.message || 'Yedek silindi', 'success');
            loadBackups();
        }).catch(function (err) { toast(err.message, 'error'); });
    }

    function restoreBackup(name) {
        const form = new URLSearchParams();
        form.set('file', name);
        api('/admin/system-monitor/restore-database', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: form.toString()
        }).then(function (res) {
            toast(res.message || 'Geri yükleme tamamlandı', 'success');
        }).catch(function (err) { toast(err.message, 'error'); });
    }

    function runSystemCheck() {
        api('/admin/system-monitor/system-check')
            .then(function (res) {
                const label = res.status === 'critical' ? 'Kritik' : (res.status === 'warning' ? 'Uyarı' : 'Sağlıklı');
                toast('Sistem kontrolü tamamlandı: ' + label, res.status === 'critical' ? 'error' : (res.status === 'warning' ? 'warning' : 'success'));
            })
            .catch(function (err) { toast(err.message, 'error'); });
    }

    function toggleMaintenance() {
        api('/admin/system-monitor/maintenance/toggle', { method: 'POST' })
            .then(function (res) {
                state.maintenance = !!res.enabled;
                updateMaintenanceButton();
                toast(res.message || 'Bakım modu güncellendi', 'success');
            })
            .catch(function (err) { toast(err.message, 'error'); });
    }

    function getMaintenanceFilters() {
        const mode = document.getElementById('smMaintenanceMode').value || 'purge_details_keep_summary';
        const dateBefore = document.getElementById('smMaintenanceDateBefore').value || '';
        const batchSize = Number(document.getElementById('smMaintenanceBatchSize').value || '50000');
        const statusesRaw = document.getElementById('smMaintenanceStatuses').value || '';
        const statuses = statusesRaw.split(',').map(function (item) { return item.trim(); }).filter(Boolean);
        const hardDeleteConfirmed = !!document.getElementById('smMaintenanceHardDeleteConfirm').checked;
        return {
            mode: mode,
            date_before: dateBefore,
            batch_size: Math.max(1000, batchSize || 50000),
            statuses: statuses,
            hard_delete_confirmed: hardDeleteConfirmed ? '1' : '0'
        };
    }

    function numberFmt(val) {
        const n = Number(val || 0);
        if (!Number.isFinite(n)) return '-';
        return n.toLocaleString('tr-TR');
    }

    function relativeTimeText(value) {
        if (!value) return '-';
        const ts = Date.parse(String(value).replace(' ', 'T'));
        if (!Number.isFinite(ts)) return String(value);
        const diffSec = Math.max(0, Math.floor((Date.now() - ts) / 1000));
        if (diffSec < 60) return diffSec + ' sn önce';
        if (diffSec < 3600) return Math.floor(diffSec / 60) + ' dk önce';
        if (diffSec < 86400) return Math.floor(diffSec / 3600) + ' sa önce';
        return Math.floor(diffSec / 86400) + ' gün önce';
    }

    function riskBadgeClass(risk) {
        if (risk === 'high') return 'nx-sm-risk high';
        if (risk === 'medium') return 'nx-sm-risk medium';
        return 'nx-sm-risk low';
    }

    function riskLabel(risk) {
        if (risk === 'high') return 'Yüksek Risk';
        if (risk === 'medium') return 'Orta Risk';
        return 'Düşük Risk';
    }

    function updateMaintenanceSummaryUI(summary) {
        state.maintenanceSummary = summary || {};
        document.getElementById('smMaintDbSize').textContent = summary.database_size || '-';
        document.getElementById('smMaintEstimatedRows').textContent = numberFmt(summary.estimated_cleanable_rows);
        document.getElementById('smMaintEstimatedSize').textContent = summary.estimated_cleanable_size || '-';
        document.getElementById('smMaintActiveJob').textContent = summary.active_job_label || '-';
        document.getElementById('smMaintLastMaintenance').textContent = summary.last_maintenance_at || '-';
    }

    function renderMaintenanceCards() {
        const wrap = document.getElementById('smMaintenanceCards');
        const cards = state.maintenanceCards || [];
        if (!cards.length) {
            wrap.innerHTML = '<div class="nx-sm-empty">Bakım kartı verisi bulunamadı.</div>';
            return;
        }
        wrap.innerHTML = cards.map(function (card) {
            const conf = maintenanceOperationConfig[card.operation] || {};
            const risk = card.risk || conf.risk || 'low';
            const progress = card.running_job || null;
            const progressHtml = progress
                ? '<div class="nx-sm-maint-progress mt-2">' +
                    '<div><strong>Job #' + numberFmt(progress.job_id) + '</strong> · ' + escapeHtml(progress.status || '-') + ' · %' + numberFmt(progress.percent || 0) + '</div>' +
                    '<div>İşlenen: <strong>' + numberFmt(progress.processed || 0) + '</strong> / ' + numberFmt(progress.total || 0) + '</div>' +
                    '<div>Temizlenen: <strong>' + numberFmt(progress.cleaned_count || progress.success_count || 0) + '</strong> · Arşivlenen: <strong>' + numberFmt(progress.archived_count || 0) + '</strong> · Hatalı: <strong>' + numberFmt(progress.failed_count || 0) + '</strong></div>' +
                    '<div>Step: <strong>' + escapeHtml(progress.current_step || '-') + '</strong> · Worker: <strong>' + escapeHtml(progress.worker_id || '-') + '</strong> · Heartbeat: <strong>' + escapeHtml(progress.heartbeat_at || '-') + '</strong></div>' +
                    '<div class="mt-2 d-flex flex-wrap gap-1">' +
                        '<button class="btn btn-sm btn-outline-secondary sm-maint-refresh-job-btn" data-job-id="' + escapeHtmlAttr(progress.job_id) + '">Durumu Yenile</button>' +
                        '<button class="btn btn-sm btn-outline-warning sm-maint-pause-job-btn" data-job-id="' + escapeHtmlAttr(progress.job_id) + '">Pause</button>' +
                        '<button class="btn btn-sm btn-outline-success sm-maint-resume-job-btn" data-job-id="' + escapeHtmlAttr(progress.job_id) + '">Resume</button>' +
                        '<button class="btn btn-sm btn-outline-danger sm-maint-cancel-job-btn" data-job-id="' + escapeHtmlAttr(progress.job_id) + '">Cancel</button>' +
                        '<button class="btn btn-sm btn-outline-dark sm-maint-fail-job-btn" data-job-id="' + escapeHtmlAttr(progress.job_id) + '">Mark Failed</button>' +
                    '</div>' +
                  '</div>'
                : '<div class="nx-sm-maint-progress mt-2">Aktif job yok.</div>';
            return '<div class="nx-sm-maint-item">' +
                '<div class="d-flex justify-content-between align-items-start gap-2">' +
                    '<h6>' + escapeHtml(conf.label || card.label || card.operation) + '</h6>' +
                    '<span class="' + riskBadgeClass(risk) + '">' + riskLabel(risk) + '</span>' +
                '</div>' +
                '<p class="sub">' + escapeHtml(conf.desc || card.description || '') + '</p>' +
                '<div class="meta">' +
                    '<div>Tahmini kayıt: <strong>' + numberFmt(card.estimated_count) + '</strong></div>' +
                    '<div>Tahmini boyut: <strong>' + escapeHtml(card.estimated_size || '-') + '</strong></div>' +
                    '<div>Temizlenebilir: <strong>' + numberFmt(card.cleanable_count) + '</strong></div>' +
                    '<div>Son bakım: <strong>' + escapeHtml(card.last_maintenance_at || '-') + '</strong></div>' +
                '</div>' +
                '<div class="d-flex flex-wrap gap-1">' +
                    '<button class="btn btn-sm btn-outline-primary sm-maint-preview-btn" data-operation="' + escapeHtmlAttr(card.operation) + '">Önizle</button>' +
                    '<button class="btn btn-sm btn-primary sm-maint-start-btn" data-operation="' + escapeHtmlAttr(card.operation) + '">Worker ile Başlat</button>' +
                    '<button class="btn btn-sm btn-outline-secondary sm-maint-detail-btn" data-operation="' + escapeHtmlAttr(card.operation) + '">Detay</button>' +
                '</div>' +
                progressHtml +
                '</div>';
        }).join('');
    }

    function loadMaintenanceSummary() {
        return api('/admin/email-data-pool/maintenance/summary')
            .then(function (res) {
                const payload = res.data || {};
                state.maintenanceCards = payload.cards || [];
                updateMaintenanceSummaryUI(payload.summary || {});
                renderMaintenanceCards();
            })
            .catch(function (err) {
                toast('Bakım özeti yüklenemedi: ' + err.message, 'error');
            });
    }

    function maintenancePreview(operation) {
        const filters = getMaintenanceFilters();
        const form = new URLSearchParams();
        form.set('operation', operation);
        form.set('mode', filters.mode);
        form.set('date_before', filters.date_before);
        form.set('batch_size', String(filters.batch_size));
        form.set('statuses', filters.statuses.join(','));
        form.set('hard_delete_confirmed', filters.hard_delete_confirmed);
        return api('/admin/email-data-pool/maintenance/preview', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: form.toString()
        }).then(function (res) {
            const preview = res.data || {};
            state.maintenancePreviewByOperation[operation] = preview;
            const warnings = (preview.warnings || []).map(function (w) { return '- ' + w; }).join('\n');
            toast('Önizleme hazır: ' + operation + ' · Tahmini kayıt: ' + numberFmt(preview.estimatedRows || 0), 'info');
            if (warnings) {
                toast('<pre class="mb-0">' + escapeHtml(warnings) + '</pre>', 'warning');
            }
            return preview;
        });
    }

    function maintenanceStart(operation) {
        const filters = getMaintenanceFilters();
        if (filters.mode === 'hard_delete' && filters.hard_delete_confirmed !== '1') {
            toast('Hard delete için onay kutusunu işaretleyin.', 'warning');
            return Promise.resolve();
        }
        const cachedPreview = state.maintenancePreviewByOperation[operation];
        if (!cachedPreview || !cachedPreview.previewToken) {
            toast('Önce önizleme alınmalı.', 'warning');
            return Promise.resolve();
        }
        const form = new URLSearchParams();
        form.set('operation', operation);
        form.set('mode', filters.mode);
        form.set('date_before', filters.date_before);
        form.set('batch_size', String(filters.batch_size));
        form.set('statuses', filters.statuses.join(','));
        form.set('hard_delete_confirmed', filters.hard_delete_confirmed);
        form.set('preview_token', String(cachedPreview.previewToken || ''));
        return api('/admin/email-data-pool/maintenance/start', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: form.toString()
        }).then(function (res) {
            const data = res.data || {};
            if (data.jobId) {
                state.maintenanceJobIds[operation] = data.jobId;
                toast('Bakım job kuyruğa alındı (#' + data.jobId + ')', 'success');
            }
            startMaintenancePolling();
            return loadMaintenanceSummary();
        }).catch(function (err) {
            toast('Bakım job başlatılamadı: ' + err.message, 'error');
        });
    }

    function startMaintenancePolling() {
        if (state.maintenancePollingTimer) {
            return;
        }
        state.maintenancePollingTimer = window.setInterval(function () {
            loadMaintenanceSummary();
        }, 5000);
    }

    function stopMaintenancePolling() {
        if (!state.maintenancePollingTimer) return;
        clearInterval(state.maintenancePollingTimer);
        state.maintenancePollingTimer = null;
    }

    function maintenanceJobAction(jobId, action) {
        const endpointMap = {
            refresh: '/admin/email-data-pool/jobs/' + encodeURIComponent(jobId) + '/status',
            pause: '/admin/email-data-pool/jobs/' + encodeURIComponent(jobId) + '/pause',
            resume: '/admin/email-data-pool/jobs/' + encodeURIComponent(jobId) + '/resume',
            cancel: '/admin/email-data-pool/jobs/' + encodeURIComponent(jobId) + '/cancel',
            fail: '/admin/email-data-pool/jobs/' + encodeURIComponent(jobId) + '/mark-failed'
        };
        const method = action === 'refresh' ? 'GET' : 'POST';
        return api(endpointMap[action], { method: method })
            .then(function (res) {
                if (action === 'refresh') {
                    const d = res.data || res;
                    toast('Job #' + jobId + ' · ' + escapeHtml(d.status || '-') + ' · %' + numberFmt(d.percent || 0), 'info');
                } else {
                    toast('Job #' + jobId + ' için işlem uygulandı: ' + action, 'success');
                }
                return loadMaintenanceSummary();
            })
            .catch(function (err) {
                toast('Job aksiyonu başarısız: ' + err.message, 'error');
            });
    }

    function escapeHtml(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function escapeHtmlAttr(v) { return escapeHtml(v); }

    function bindEvents() {
        document.getElementById('smRefreshBtn').addEventListener('click', function () {
            const btn = this;
            setBusy(btn, true, 'Yenileniyor...');
            loadMetrics().finally(function () {
                setBusy(btn, false);
            });
        });
        document.getElementById('smSystemCheckBtn').addEventListener('click', runSystemCheck);
        document.getElementById('smMaintenanceToggleBtn').addEventListener('click', function () {
            showConfirm('Bakım Modu', 'Bakım modu durumu değiştirilecek. Devam edilsin mi?', 'btn-warning', toggleMaintenance);
        });

        document.getElementById('smLoadAppLogsBtn').addEventListener('click', function () { loadLogs('app'); });
        document.getElementById('smLoadWorkerLogsBtn').addEventListener('click', function () { loadLogs('worker'); });
        document.getElementById('smLoadErrorLogsBtn').addEventListener('click', function () { loadLogs('error'); });
        document.getElementById('smClearAllLogsBtn').addEventListener('click', function () {
            showConfirm('Logları Temizle', 'Tüm log dosyaları temizlenecek. Bu işlem geri alınamaz.', 'btn-danger', function () {
                api('/admin/system-monitor/logs/clear-all', { method: 'POST' }).then(function (res) {
                    toast(res.message || 'Temizlendi', 'success');
                    loadLogs();
                }).catch(function (err) { toast(err.message, 'error'); });
            });
        });

        document.getElementById('smLogsTableBody').addEventListener('click', function (e) {
            const viewBtn = e.target.closest('.sm-log-view-btn');
            const downloadBtn = e.target.closest('.sm-log-download-btn');
            const clearBtn = e.target.closest('.sm-log-clear-btn');
            if (viewBtn) openLogModal(viewBtn.dataset.name);
            if (downloadBtn) window.location.href = '/admin/system-monitor/logs/' + encodeURIComponent(downloadBtn.dataset.name) + '/download';
            if (clearBtn) {
                const name = clearBtn.dataset.name;
                showConfirm('Log Temizle', '"' + escapeHtml(name) + '" log dosyası temizlenecek. Emin misiniz?', 'btn-danger', function () {
                    api('/admin/system-monitor/logs/' + encodeURIComponent(name) + '/clear', { method: 'POST' })
                        .then(function (res) { toast(res.message || 'Log temizlendi', 'success'); loadLogs(); })
                        .catch(function (err) { toast(err.message, 'error'); });
                });
            }
        });

        document.getElementById('smLogReloadBtn').addEventListener('click', loadLogTail);
        document.getElementById('smLogLineSelect').addEventListener('change', loadLogTail);
        document.getElementById('smLogSearch').addEventListener('input', applyLogSearchFilter);
        document.getElementById('smLogDownloadBtn').addEventListener('click', function () {
            if (state.currentLogName) window.location.href = '/admin/system-monitor/logs/' + encodeURIComponent(state.currentLogName) + '/download';
        });

        document.getElementById('smFileSearch').addEventListener('input', function (e) {
            state.fileSearch = e.target.value || '';
            state.filePage = 1;
            loadFiles();
        });
        document.getElementById('smUpDirBtn').addEventListener('click', function () {
            if (!state.currentPath) return;
            const parts = state.currentPath.split('/').filter(Boolean);
            parts.pop();
            state.currentPath = parts.join('/');
            state.filePage = 1;
            loadFiles();
        });
        document.getElementById('smCreateFileBtn').addEventListener('click', function () { showCreateModal('file'); });
        document.getElementById('smCreateDirBtn').addEventListener('click', function () { showCreateModal('dir'); });
        document.getElementById('smUploadBtn').addEventListener('click', function () { document.getElementById('smUploadInput').click(); });
        document.getElementById('smUploadInput').addEventListener('change', function (e) { uploadFile(e.target.files[0]); e.target.value = ''; });
        document.getElementById('smFilesPrevBtn').addEventListener('click', function () {
            if (state.filePage <= 1) return;
            state.filePage -= 1;
            loadFiles();
        });
        document.getElementById('smFilesNextBtn').addEventListener('click', function () {
            if (state.filePage >= state.fileTotalPages) return;
            state.filePage += 1;
            loadFiles();
        });

        document.getElementById('smFilesTableBody').addEventListener('click', function (e) {
            const enterBtn = e.target.closest('.sm-file-enter-btn');
            const viewBtn = e.target.closest('.sm-file-view-btn');
            const editBtn = e.target.closest('.sm-file-edit-btn');
            const dlBtn = e.target.closest('.sm-file-download-btn');
            const deleteBtn = e.target.closest('.sm-file-delete-btn');
            const renameBtn = e.target.closest('.sm-file-rename-btn');

            if (enterBtn) {
                state.currentPath = enterBtn.dataset.path || '';
                state.filePage = 1;
                loadFiles();
            }
            if (viewBtn) openEditor(viewBtn.dataset.path || '');
            if (editBtn) openEditor(editBtn.dataset.path || '');
            if (dlBtn) downloadFile(dlBtn.dataset.path || '');
            if (renameBtn) {
                const target = renameBtn.dataset.path || '';
                const currentName = target.split('/').pop();
                const newName = window.prompt('Yeni ad:', currentName || '');
                if (!newName || newName === currentName) return;
                const form = new URLSearchParams();
                form.set('path', target);
                form.set('new_name', newName);
                api('/admin/system-monitor/file/rename', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: form.toString()
                }).then(function (res) {
                    toast(res.message || 'Yeniden adlandırıldı', 'success');
                    loadFiles();
                }).catch(function (err) { toast(err.message, 'error'); });
            }
            if (deleteBtn) {
                const target = deleteBtn.dataset.path || '';
                const name = deleteBtn.dataset.name || target;
                showConfirm('Silme Onayı', '"' + escapeHtml(name) + '" silinecek. Bu işlem geri alınamaz.', 'btn-danger', function () {
                    const form = new URLSearchParams();
                    form.set('path', target);
                    api('/admin/system-monitor/file/delete', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: form.toString()
                    }).then(function (res) {
                        toast(res.message || 'Silindi', 'success');
                        loadFiles();
                    }).catch(function (err) { toast(err.message, 'error'); });
                });
            }
        });

        document.getElementById('smCreateSaveBtn').addEventListener('click', createItem);
        document.getElementById('smEditorSaveBtn').addEventListener('click', saveEditor);

        document.getElementById('smBackupDbBtn').addEventListener('click', function () {
            showConfirm('Veritabanı Yedeği', 'Veritabanı yedeği oluşturulacak. İşlem birkaç dakika sürebilir.', 'btn-success', function () { createBackup('database'); });
        });
        document.getElementById('smBackupFilesBtn').addEventListener('click', function () {
            showConfirm('Dosya Yedeği', 'Dosya yedeği oluşturulacak. İşlem birkaç dakika sürebilir.', 'btn-primary', function () { createBackup('files'); });
        });
        document.getElementById('smLoadBackupsBtn').addEventListener('click', function () {
            const btn = this;
            setBusy(btn, true, 'Yükleniyor...');
            Promise.resolve(loadBackups()).finally(function () {
                setBusy(btn, false);
            });
        });
        document.getElementById('smDownloadLastDbBtn').addEventListener('click', function () {
            const last = state.backups.database[0];
            if (!last) return toast('İndirilecek veritabanı yedeği yok', 'warning');
            window.location.href = '/admin/system-monitor/download-backup?type=database&file=' + encodeURIComponent(last.name);
        });
        document.getElementById('smDownloadLastFilesBtn').addEventListener('click', function () {
            const last = state.backups.files[0];
            if (!last) return toast('İndirilecek dosya yedeği yok', 'warning');
            window.location.href = '/admin/system-monitor/download-backup?type=files&file=' + encodeURIComponent(last.name);
        });

        document.getElementById('smBackupDbTableWrap').addEventListener('click', handleBackupTableActions);
        document.getElementById('smBackupFilesTableWrap').addEventListener('click', handleBackupTableActions);

        document.getElementById('smMaintenanceSummaryRefreshBtn').addEventListener('click', function () {
            const btn = this;
            setBusy(btn, true, 'Yenileniyor...');
            loadMaintenanceSummary().finally(function () {
                setBusy(btn, false);
            });
        });
        document.getElementById('smMaintenanceCards').addEventListener('click', function (e) {
            const previewBtn = e.target.closest('.sm-maint-preview-btn');
            const startBtn = e.target.closest('.sm-maint-start-btn');
            const detailBtn = e.target.closest('.sm-maint-detail-btn');
            const refreshJobBtn = e.target.closest('.sm-maint-refresh-job-btn');
            const pauseJobBtn = e.target.closest('.sm-maint-pause-job-btn');
            const resumeJobBtn = e.target.closest('.sm-maint-resume-job-btn');
            const cancelJobBtn = e.target.closest('.sm-maint-cancel-job-btn');
            const failJobBtn = e.target.closest('.sm-maint-fail-job-btn');

            if (previewBtn) {
                const operation = previewBtn.dataset.operation || '';
                if (!operation) return;
                setBusy(previewBtn, true, 'Önizleniyor...');
                maintenancePreview(operation).finally(function () { setBusy(previewBtn, false); });
                return;
            }
            if (startBtn) {
                const operation = startBtn.dataset.operation || '';
                if (!operation) return;
                showConfirm(
                    'Bakım Job Başlat',
                    'Seçilen bakım operasyonu worker kuyruğuna alınacak. İşlem web request içinde çalışmayacak.',
                    'btn-primary',
                    function () {
                        setBusy(startBtn, true, 'Kuyruğa alınıyor...');
                        maintenanceStart(operation).finally(function () { setBusy(startBtn, false); });
                    }
                );
                return;
            }
            if (detailBtn) {
                const operation = detailBtn.dataset.operation || '';
                const preview = state.maintenancePreviewByOperation[operation];
                if (!preview) {
                    toast('Önce önizleme alın.', 'warning');
                    return;
                }
                const lines = [
                    'Operasyon: ' + (preview.operation || operation),
                    'Mod: ' + (preview.mode || '-'),
                    'Tarih filtresi: ' + (preview.dateBefore || '-'),
                    'Tahmini order: ' + numberFmt(preview.estimatedOrders || 0),
                    'Tahmini recipient: ' + numberFmt(preview.estimatedRecipients || 0),
                    'Tahmini kayıt: ' + numberFmt(preview.estimatedRows || 0),
                    'Tahmini boyut: ' + (preview.estimatedDbSize || '-'),
                    'Batch: ' + numberFmt(preview.batchSize || 0),
                    'Süre: ' + (preview.estimatedDuration || '-'),
                    'Önizleme zamanı: ' + relativeTimeText(preview.generatedAt || null)
                ];
                showConfirm('Bakım Önizleme Detayı', '<pre class="mb-0">' + escapeHtml(lines.join('\n')) + '</pre>', 'btn-secondary', function () {});
                return;
            }
            if (refreshJobBtn) return maintenanceJobAction(refreshJobBtn.dataset.jobId, 'refresh');
            if (pauseJobBtn) return maintenanceJobAction(pauseJobBtn.dataset.jobId, 'pause');
            if (resumeJobBtn) return maintenanceJobAction(resumeJobBtn.dataset.jobId, 'resume');
            if (cancelJobBtn) {
                return showConfirm('Job İptal', 'Job iptal edilsin mi?', 'btn-danger', function () {
                    maintenanceJobAction(cancelJobBtn.dataset.jobId, 'cancel');
                });
            }
            if (failJobBtn) {
                return showConfirm('Job Failed İşaretle', 'Job failed olarak işaretlensin mi?', 'btn-warning', function () {
                    maintenanceJobAction(failJobBtn.dataset.jobId, 'fail');
                });
            }
        });
    }

    function handleBackupTableActions(e) {
        const dl = e.target.closest('.sm-backup-download-btn');
        const del = e.target.closest('.sm-backup-delete-btn');
        const restore = e.target.closest('.sm-backup-restore-btn');
        if (dl) {
            window.location.href = '/admin/system-monitor/download-backup?type=' + encodeURIComponent(dl.dataset.type) + '&file=' + encodeURIComponent(dl.dataset.name);
        }
        if (del) {
            showConfirm('Yedek Sil', '"' + escapeHtml(del.dataset.name) + '" silinecek. Emin misiniz?', 'btn-danger', function () {
                deleteBackup(del.dataset.type, del.dataset.name);
            });
        }
        if (restore) {
            showConfirm('Veritabanı Geri Yükleme', '"' + escapeHtml(restore.dataset.name) + '" yedeği geri yüklenecek. Mevcut veriler değişebilir.', 'btn-warning', function () {
                restoreBackup(restore.dataset.name);
            });
        }
    }

    bindEvents();
    loadMetrics();
    loadMaintenanceSummary().then(startMaintenancePolling);
    loadLogs();
    loadFiles();
    window.addEventListener('beforeunload', stopMaintenancePolling);
    if (typeof feather !== 'undefined') feather.replace();
})();

