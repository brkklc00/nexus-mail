(function (window) {
    'use strict';

    var TYPE_CONFIG = {
        success: { title: 'Başarılı', icon: 'mdi mdi-check-circle-outline' },
        error: { title: 'Hata', icon: 'mdi mdi-alert-circle-outline' },
        warning: { title: 'Uyarı', icon: 'mdi mdi-alert-outline' },
        info: { title: 'Bilgi', icon: 'mdi mdi-information-outline' }
    };

    function normalizeType(type) {
        if (!type || !TYPE_CONFIG[type]) {
            return 'info';
        }
        return type;
    }

    function fallbackAlert(message) {
        if (!message) {
            return;
        }
        window.alert(message);
    }

    function show(type, message, options) {
        var safeType = normalizeType(type);
        var cfg = TYPE_CONFIG[safeType];
        var opts = options || {};
        var toastMessage = String(message || '').trim();
        if (!toastMessage) {
            return;
        }

        if (!window.iziToast || typeof window.iziToast.show !== 'function') {
            fallbackAlert(toastMessage);
            return;
        }

        window.iziToast.show({
            class: 'nx-toast nx-toast--' + safeType,
            title: String(opts.title || cfg.title),
            message: toastMessage,
            icon: cfg.icon,
            iconColor: 'currentColor',
            position: opts.position || 'topRight',
            timeout: Number(opts.timeout || 5000),
            close: true,
            closeOnEscape: true,
            displayMode: 2,
            maxWidth: 460,
            layout: 1,
            transitionIn: 'fadeInDown',
            transitionOut: 'fadeOutUp',
            pauseOnHover: true
        });
    }

    window.NexusToast = {
        show: show,
        success: function (message, options) { show('success', message, options); },
        error: function (message, options) { show('error', message, options); },
        warning: function (message, options) { show('warning', message, options); },
        info: function (message, options) { show('info', message, options); }
    };
})(window);
