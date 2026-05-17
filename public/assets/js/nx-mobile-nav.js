/**
 * Nexus — mobil sidebar drawer (Dastone enlarge-menu yerine).
 * lg (992px) altında: fixed drawer + overlay + body scroll kilidi.
 */
(function () {
    'use strict';

    var BODY_OPEN = 'nx-mobile-sidebar-open';
    var BODY_DESKTOP_COLLAPSED = 'nx-desktop-sidebar-collapsed';
    var STORAGE_DESKTOP_COLLAPSED = 'nxDesktopSidebarCollapsed';
    var MQ = '(max-width: 991.98px)';
    var MQ_DESKTOP = '(min-width: 992px)';
    var mobileMenuOpen = false;

    function mqMobile() {
        return typeof window.matchMedia === 'function' && window.matchMedia(MQ).matches;
    }

    function mqDesktop() {
        return typeof window.matchMedia === 'function' && window.matchMedia(MQ_DESKTOP).matches;
    }

    function stripLegacyEnlargeClasses() {
        if (window.jQuery) {
            window.jQuery('body').removeClass('enlarge-menu enlarge-menu-all');
        } else if (document.body) {
            document.body.classList.remove('enlarge-menu', 'enlarge-menu-all');
        }
    }

    function getStoredDesktopCollapsed() {
        try {
            return window.localStorage.getItem(STORAGE_DESKTOP_COLLAPSED) === '1';
        } catch (e) {
            return false;
        }
    }

    function setStoredDesktopCollapsed(collapsed) {
        try {
            window.localStorage.setItem(STORAGE_DESKTOP_COLLAPSED, collapsed ? '1' : '0');
        } catch (e) {
            /* ignore storage errors */
        }
    }

    function setDesktopSidebarCollapsed(collapsed) {
        if (!document.body) {
            return;
        }
        stripLegacyEnlargeClasses();
        document.body.classList.toggle(BODY_DESKTOP_COLLAPSED, !!collapsed);
        setStoredDesktopCollapsed(!!collapsed);
    }

    function setMenuButtonsExpanded(expanded) {
        var value = expanded ? 'true' : 'false';
        document.querySelectorAll('.button-menu-mobile').forEach(function (btn) {
            btn.setAttribute('aria-expanded', value);
        });
    }

    function setScrollLock(on) {
        if (on) {
            document.documentElement.style.overflow = 'hidden';
            document.body.style.overflow = 'hidden';
            document.body.style.overscrollBehavior = 'none';
        } else {
            document.documentElement.style.overflow = '';
            document.body.style.overflow = '';
            document.body.style.overscrollBehavior = '';
        }
    }

    function openDrawer() {
        if (!mqMobile()) {
            return;
        }
        document.body.classList.add(BODY_OPEN);
        mobileMenuOpen = true;
        setScrollLock(true);
        var ov = document.getElementById('nxMobileNavOverlay');
        if (ov) {
            ov.setAttribute('aria-hidden', 'false');
        }
        setMenuButtonsExpanded(true);
    }

    function closeDrawer() {
        if (window.NxSidebarNav && typeof window.NxSidebarNav.closeAllRoot === 'function') {
            window.NxSidebarNav.closeAllRoot();
        }
        document.body.classList.remove(BODY_OPEN);
        mobileMenuOpen = false;
        setScrollLock(false);
        var ov = document.getElementById('nxMobileNavOverlay');
        if (ov) {
            ov.setAttribute('aria-hidden', 'true');
        }
        setMenuButtonsExpanded(false);
    }

    function toggleDrawer() {
        if (!mqMobile()) {
            return;
        }
        if (mobileMenuOpen || document.body.classList.contains(BODY_OPEN)) {
            closeDrawer();
        } else {
            openDrawer();
        }
    }

    /** Submenu başlığı (javascript:) veya # — kapatma yok */
    function isRouteLink(a) {
        if (!a || a.hasAttribute('data-bs-toggle')) {
            return false;
        }
        var href = (a.getAttribute('href') || '').trim();
        if (!href || href === '#') {
            return false;
        }
        if (href.indexOf('javascript:') === 0) {
            return false;
        }
        return true;
    }

    function stripEnlargeOnMobile() {
        if (!window.jQuery) {
            return;
        }
        if (mqMobile()) {
            window.jQuery('body').removeClass('enlarge-menu enlarge-menu-all');
        }
    }

    function onResize() {
        if (mqMobile()) {
            stripEnlargeOnMobile();
            mobileMenuOpen = document.body.classList.contains(BODY_OPEN);
        } else {
            closeDrawer();
            setDesktopSidebarCollapsed(getStoredDesktopCollapsed());
        }
    }

    function bindNav() {
        if (!window.jQuery) {
            return;
        }
        var $ = window.jQuery;
        /* Dastone app.js initLeftMenuCollapse da .button-menu-mobile dinler; ikisi üst üste
           binecek olursa enlarge-menu iki kez toggle olur ve PC'de menü hiç değişmez. */
        $('.button-menu-mobile')
            .off('click')
            .on('click.nxMobileNav', function (e) {
                e.preventDefault();
                if (mqMobile()) {
                    toggleDrawer();
                } else {
                    var collapsed = !document.body.classList.contains(BODY_DESKTOP_COLLAPSED);
                    setDesktopSidebarCollapsed(collapsed);
                }
            });

        var overlay = document.getElementById('nxMobileNavOverlay');
        if (overlay) {
            overlay.addEventListener('click', function () {
                if (mqMobile()) {
                    closeDrawer();
                }
            });
        }

        var closeBtn = document.getElementById('nxMobileSidebarClose');
        if (closeBtn) {
            closeBtn.addEventListener('click', function (e) {
                e.preventDefault();
                closeDrawer();
            });
        }

        var sidebar = document.getElementById('nxAppSidebar');
        if (sidebar) {
            sidebar.addEventListener('click', function (e) {
                if (!mqMobile() || !document.body.classList.contains(BODY_OPEN)) {
                    return;
                }
                var a = e.target.closest('a');
                if (!isRouteLink(a)) {
                    return;
                }
                closeDrawer();
            });
        }

        window.addEventListener('popstate', function () {
            if (mqMobile() && mobileMenuOpen) {
                closeDrawer();
            }
        });

        window.addEventListener('hashchange', function () {
            if (mqMobile() && mobileMenuOpen) {
                closeDrawer();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.body.classList.contains(BODY_OPEN)) {
                closeDrawer();
            }
        });

        var resizeT;
        window.addEventListener('resize', function () {
            clearTimeout(resizeT);
            resizeT = setTimeout(onResize, 80);
        });

        if (typeof window.matchMedia === 'function') {
            window.matchMedia(MQ).addEventListener('change', onResize);
        }

        window.addEventListener('pageshow', function () {
            if (mqMobile()) {
                closeDrawer();
            }
        });

        stripEnlargeOnMobile();
        if (mqDesktop()) {
            setDesktopSidebarCollapsed(getStoredDesktopCollapsed());
        }
    }

    function nxFeather() {
        if (typeof feather !== 'undefined') {
            try {
                feather.replace();
            } catch (err) { /* ignore */ }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            bindNav();
            nxFeather();
        });
    } else {
        bindNav();
        nxFeather();
    }
})();

/**
 * Nexus sidebar v2 — accordion (nx-sidebar-nav ile aynı; tek dosyada sunucu 404 önlenir).
 */
(function () {
    'use strict';

    var SID = '#nxAppSidebar';
    var MQ_DESKTOP = '(min-width: 992px)';
    var openMenu = null;

    function sb() {
        return document.querySelector(SID);
    }

    function $all(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    function closest(el, sel) {
        return el && el.closest ? el.closest(sel) : null;
    }

    function refreshOpenMenuState() {
        var root = sb();
        if (!root) {
            openMenu = null;
            return;
        }
        var openTier0 = root.querySelector('.nx-sb-group--tier0.nx-sb-group--open');
        if (!openTier0) {
            openMenu = null;
            return;
        }
        openMenu = openTier0.getAttribute('data-nx-sb') || null;
    }

    function closeSiblingGroups(group) {
        var par = group.parentElement;
        if (!par) {
            return;
        }
        $all(':scope > li.nx-sb-group', par).forEach(function (sib) {
            if (sib === group) {
                return;
            }
            setGroupOpen(sib, false, true);
        });
    }

    function setPanelA11y(panel, open) {
        if (!panel) {
            return;
        }
        if (open) {
            panel.removeAttribute('inert');
            panel.setAttribute('aria-hidden', 'false');
        } else {
            panel.setAttribute('inert', '');
            panel.setAttribute('aria-hidden', 'true');
        }
    }

    function setGroupOpen(group, open, skipSiblings) {
        var trigger = group.querySelector(':scope > .nx-sb-group__trigger');
        var panel = group.querySelector(':scope > .nx-sb-group__panel');

        if (open && !skipSiblings) {
            closeSiblingGroups(group);
        }

        if (open) {
            group.classList.add('nx-sb-group--open');
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'true');
            }
            setPanelA11y(panel, true);
        } else {
            group.classList.remove('nx-sb-group--open');
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'false');
            }
            setPanelA11y(panel, false);
            $all(':scope .nx-sb-group--open', group).forEach(function (child) {
                setGroupOpen(child, false, true);
            });
        }
        refreshOpenMenuState();
    }

    function closeAllRoot() {
        var root = sb();
        if (!root) {
            return;
        }
        $all('.nx-sb-group--tier0.nx-sb-group--open', root).forEach(function (g) {
            setGroupOpen(g, false, true);
        });
        openMenu = null;
    }

    function syncOpenFromActive() {
        var root = sb();
        if (!root) {
            return;
        }
        $all('.nx-sb-group--open', root).forEach(function (g) {
            setGroupOpen(g, false, true);
        });
        var active = root.querySelector('a.active[href]');
        if (!active) {
            refreshOpenMenuState();
            return;
        }
        var chain = [];
        var el = closest(active, '.nx-sb-group');
        while (el && root.contains(el)) {
            chain.push(el);
            el = closest(el.parentElement, '.nx-sb-group');
        }
        chain.reverse().forEach(function (g) {
            setGroupOpen(g, true, true);
        });
        refreshOpenMenuState();
    }

    function closeAllOpenGroups() {
        var root = sb();
        if (!root) {
            return;
        }
        $all('.nx-sb-group--open', root).forEach(function (g) {
            setGroupOpen(g, false, true);
        });
        openMenu = null;
    }

    function bind() {
        var root = sb();
        if (!root) {
            return;
        }

        root.addEventListener('click', function (e) {
            var btn = closest(e.target, '.nx-sb-group__trigger');
            if (!btn || !root.contains(btn)) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            var group = closest(btn, '.nx-sb-group');
            if (!group) {
                return;
            }
            var isOpen = group.classList.contains('nx-sb-group--open');
            setGroupOpen(group, !isOpen, false);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') {
                return;
            }
            closeAllOpenGroups();
        });

        if (typeof window.matchMedia === 'function') {
            window.matchMedia(MQ_DESKTOP).addEventListener('change', function () {
                closeAllRoot();
                setTimeout(syncOpenFromActive, 0);
            });
        }
    }

    function nxFeatherSidebar() {
        if (typeof feather === 'undefined') {
            return;
        }
        try {
            feather.replace();
        } catch (err) {
            /* ignore */
        }
    }

    function init() {
        bind();
        setTimeout(function () {
            syncOpenFromActive();
            nxFeatherSidebar();
        }, 0);
    }

    var api = {
        closeAllRoot: closeAllRoot,
        syncFromActive: syncOpenFromActive,
    };
    Object.defineProperty(api, 'openMenu', {
        get: function () {
            return openMenu;
        },
        enumerable: true,
    });
    window.NxSidebarNav = api;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
