/**
 * Nexus — bildirim paneli: body portal + position fixed (viewport hizalı).
 * Parent overflow/transform kesilmez. loadNotifications global ile beslenir.
 */
(function () {
    'use strict';

    var PANEL_ID = 'nxNotiPanel';
    var BELL_ID = 'notificationBell';
    var PANEL_WIDTH = 360;
    var GAP_Y = 10;
    var EDGE = 12;

    function getPanel() {
        return document.getElementById(PANEL_ID);
    }

    function getBell() {
        return document.getElementById(BELL_ID);
    }

    function isOpen() {
        var p = getPanel();
        return !!(p && p.classList.contains('nx-noti-panel-portal--open'));
    }

    function mountPanel() {
        var panel = getPanel();
        if (panel && panel.parentNode !== document.body) {
            document.body.appendChild(panel);
        }
    }

    function positionPanel() {
        var panel = getPanel();
        var bell = getBell();
        if (!panel || !bell || !isOpen()) return;

        var rect = bell.getBoundingClientRect();
        var vw = window.innerWidth;
        var vh = window.innerHeight;

        var panelWidth = Math.min(PANEL_WIDTH, vw - 2 * EDGE);
        panel.style.width = panelWidth + 'px';

        var top = rect.bottom + GAP_Y;

        var left = Math.min(rect.right - panelWidth, vw - EDGE - panelWidth);
        left = Math.max(EDGE, left);

        panel.style.position = 'fixed';
        panel.style.top = top + 'px';
        panel.style.left = left + 'px';
        panel.style.right = 'auto';
        panel.style.bottom = 'auto';

        var ph = panel.offsetHeight || 0;
        if (ph > 0 && top + ph > vh - EDGE) {
            var aboveTop = rect.top - GAP_Y - ph;
            if (aboveTop >= EDGE) {
                panel.style.top = aboveTop + 'px';
            }
        }
    }

    function openPanel() {
        mountPanel();
        var panel = getPanel();
        var bell = getBell();
        if (!panel || !bell) return;

        panel.classList.add('nx-noti-panel-portal--open');
        panel.setAttribute('aria-hidden', 'false');
        panel.setAttribute('aria-modal', 'true');
        bell.setAttribute('aria-expanded', 'true');

        var ln = typeof window.loadNotifications === 'function' ? window.loadNotifications : null;
        if (ln) {
            try {
                ln();
            } catch (e) {
                console.warn(e);
            }
        }

        requestAnimationFrame(function () {
            positionPanel();
            requestAnimationFrame(positionPanel);
        });
    }

    function closePanel() {
        var panel = getPanel();
        var bell = getBell();
        if (panel) {
            panel.classList.remove('nx-noti-panel-portal--open');
            panel.setAttribute('aria-hidden', 'true');
            panel.setAttribute('aria-modal', 'false');
        }
        if (bell) {
            bell.setAttribute('aria-expanded', 'false');
        }
    }

    function togglePanel() {
        if (isOpen()) {
            closePanel();
        } else {
            openPanel();
        }
    }

    function onDocMouseDown(e) {
        if (!isOpen()) return;
        var panel = getPanel();
        var bell = getBell();
        if (panel && panel.contains(e.target)) return;
        if (bell && bell.contains(e.target)) return;
        closePanel();
    }

    function onKeyDown(e) {
        if (e.key === 'Escape' && isOpen()) {
            closePanel();
        }
    }

    window.nxNotiRefreshIfOpen = function () {
        if (isOpen()) {
            positionPanel();
        }
    };

    function wire() {
        mountPanel();
        var bell = getBell();
        var panel = getPanel();
        if (!bell || !panel) return;

        bell.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            togglePanel();
        });

        document.addEventListener('mousedown', onDocMouseDown);
        document.addEventListener('keydown', onKeyDown);

        window.addEventListener('resize', function () {
            if (isOpen()) positionPanel();
        });

        window.addEventListener(
            'scroll',
            function () {
                if (isOpen()) positionPanel();
            },
            true
        );
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }
})();
