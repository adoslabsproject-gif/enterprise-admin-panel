/**
 * Enterprise Admin Panel - Dashboard JavaScript
 *
 * Handles dashboard metrics refresh and display.
 *
 * @version 1.0.0
 */

'use strict';

(function() {
    // Read configuration from data attributes
    function getConfig() {
        var configEl = document.getElementById('dashboard-config');
        if (!configEl) {
            return { apiBase: '/admin/api', redisEnabled: false, redisConnected: false };
        }
        return {
            apiBase: configEl.getAttribute('data-api-base') || '/admin/api',
            redisEnabled: configEl.getAttribute('data-redis-enabled') === 'true',
            redisConnected: configEl.getAttribute('data-redis-connected') === 'true'
        };
    }

    /**
     * Refresh Database Pool metrics
     */
    function refreshDbPoolMetrics() {
        var config = getConfig();
        var apiUrl = config.apiBase + '/dbpool';

        fetch(apiUrl, { credentials: 'same-origin' })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                console.log('DB Pool metrics:', data);
                location.reload();
            })
            .catch(function(err) {
                console.error('Failed to refresh DB pool metrics:', err);
            });
    }

    /**
     * Refresh Redis metrics and display details
     */
    function refreshRedisMetrics() {
        var detailsEl = document.getElementById('redis-details');
        if (!detailsEl) return;

        var config = getConfig();
        var apiUrl = config.apiBase + '/redis';

        fetch(apiUrl, { credentials: 'same-origin' })
            .then(function(res) {
                if (!res.ok) {
                    console.error('Redis API error:', res.status, res.statusText);
                    detailsEl.textContent = 'API Error: ' + res.status;
                    throw new Error('API error');
                }
                return res.json();
            })
            .then(function(data) {
                console.log('Redis metrics:', data);

                if (data.error) {
                    detailsEl.textContent = 'Error: ' + data.error;
                    return;
                }

                if (data.server) {
                    detailsEl.innerHTML =
                        '<div class="eap-grid eap-grid--2">' +
                            '<div>Version: <strong>' + data.server.version + '</strong></div>' +
                            '<div>Uptime: <strong>' + data.server.uptime_days + ' days</strong></div>' +
                            '<div>Memory: <strong>' + data.server.used_memory + '</strong></div>' +
                            '<div>Clients: <strong>' + data.server.connected_clients + '</strong></div>' +
                            '<div>Commands: <strong>' + Number(data.server.total_commands).toLocaleString() + '</strong></div>' +
                            '<div>EAP Keys: <strong>' + data.eap_keys + '</strong></div>' +
                        '</div>';
                } else {
                    detailsEl.textContent = data.connected ? 'Connected (no server info)' : 'Redis not connected';
                }
            })
            .catch(function(err) {
                if (err.message !== 'API error') {
                    console.error('Failed to refresh Redis metrics:', err);
                    detailsEl.textContent = 'Fetch error: ' + err.message;
                }
            });
    }

    // Expose functions globally for button onclick handlers
    window.refreshDbPoolMetrics = refreshDbPoolMetrics;
    window.refreshRedisMetrics = refreshRedisMetrics;

    // Auto-load Redis details on page load if Redis is enabled and connected
    document.addEventListener('DOMContentLoaded', function() {
        var config = getConfig();
        if (config.redisEnabled && config.redisConnected) {
            refreshRedisMetrics();
        }
    });
})();
