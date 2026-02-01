/**
 * Enterprise Admin Panel - Core JavaScript
 *
 * Features:
 * - CSRF token handling for AJAX
 * - Auto-refresh stats
 * - Utility functions
 */
(function() {
    'use strict';

    // Get CSRF token from meta tag
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.content : '';

    /**
     * Fetch wrapper with CSRF token
     */
    window.eapFetch = function(url, options) {
        options = options || {};
        options.headers = options.headers || {};
        options.headers['X-CSRF-Token'] = csrfToken;
        options.headers['X-Requested-With'] = 'XMLHttpRequest';

        return fetch(url, options);
    };

    /**
     * Auto-refresh stats containers
     */
    function refreshStats() {
        var containers = document.querySelectorAll('[data-stats-url]');

        containers.forEach(function(container) {
            var url = container.dataset.statsUrl;

            fetch(url)
                .then(function(response) {
                    return response.json();
                })
                .then(function(data) {
                    // Update stats display - can be customized per container
                    if (container.dataset.statsCallback && window[container.dataset.statsCallback]) {
                        window[container.dataset.statsCallback](container, data);
                    }
                })
                .catch(function(e) {
                    console.error('Failed to refresh stats:', e);
                });
        });
    }

    // Refresh stats every 30 seconds if there are stats containers
    if (document.querySelectorAll('[data-stats-url]').length > 0) {
        setInterval(refreshStats, 30000);
    }

    /**
     * Flash message auto-dismiss
     * Uses CSS classes instead of inline styles for CSP compliance
     */
    function initFlashMessages() {
        var alerts = document.querySelectorAll('.eap-alert[data-auto-dismiss]');

        alerts.forEach(function(alert) {
            var delay = parseInt(alert.dataset.autoDismiss, 10) || 5000;

            setTimeout(function() {
                alert.classList.add('eap-alert--dismissing');

                setTimeout(function() {
                    alert.remove();
                }, 300);
            }, delay);
        });
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFlashMessages);
    } else {
        initFlashMessages();
    }

    // Expose CSRF token globally
    window.eapCsrfToken = csrfToken;
})();
