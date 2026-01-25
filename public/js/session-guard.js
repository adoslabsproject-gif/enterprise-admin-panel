/**
 * Enterprise Admin Panel - Session Guard
 *
 * Client-side session monitoring with:
 * - Heartbeat every 30 seconds to keep session alive
 * - Warning dialog 5 minutes before expiry
 * - Auto-logout when session expires
 * - Activity tracking (mouse, keyboard, scroll)
 */
(function() {
    'use strict';

    var SessionGuard = {
        // Configuration
        heartbeatInterval: 30000,  // 30 seconds
        warningThreshold: 300,     // 5 minutes in seconds
        checkInterval: 10000,      // Check every 10 seconds

        // State
        expiresIn: null,
        lastActivity: Date.now(),
        warningShown: false,
        heartbeatTimer: null,
        checkTimer: null,
        initialized: false,

        // Bound event handlers (for cleanup)
        boundActivityHandler: null,
        boundVisibilityHandler: null,

        init: function() {
            // Prevent double initialization (memory leak protection)
            if (this.initialized) {
                console.warn('[SessionGuard] Already initialized, skipping');
                return;
            }
            this.initialized = true;

            // Get admin base path from meta tag or infer from current URL
            this.basePath = this.getBasePath();

            console.log('[SessionGuard] Initialized with basePath:', this.basePath);

            // Start heartbeat
            this.startHeartbeat();

            // Track user activity
            this.trackActivity();

            // Start expiry check
            this.startExpiryCheck();

            // Cleanup on page unload to prevent memory leaks
            var self = this;
            window.addEventListener('beforeunload', function() {
                self.destroy();
            }, { once: true });

            // Pause when tab is hidden (save resources)
            this.boundVisibilityHandler = function() {
                if (document.hidden) {
                    self.pauseTimers();
                } else {
                    self.resumeTimers();
                }
            };
            document.addEventListener('visibilitychange', this.boundVisibilityHandler);
        },

        getBasePath: function() {
            // Extract base path from current URL (e.g., /x-abc123)
            var path = window.location.pathname;
            var match = path.match(/^(\/x-[a-f0-9]+)/);
            return match ? match[1] : '';
        },

        startHeartbeat: function() {
            var self = this;

            // Initial heartbeat
            this.sendHeartbeat();

            // Schedule regular heartbeats
            this.heartbeatTimer = setInterval(function() {
                self.sendHeartbeat();
            }, this.heartbeatInterval);
        },

        sendHeartbeat: function() {
            var self = this;
            var xhr = new XMLHttpRequest();
            var url = this.basePath + '/api/session/heartbeat';

            console.log('[SessionGuard] Sending heartbeat to:', url);

            xhr.open('GET', url, true);
            xhr.setRequestHeader('Accept', 'application/json');

            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    console.log('[SessionGuard] Heartbeat response:', xhr.status);
                    if (xhr.status === 200) {
                        try {
                            var data = JSON.parse(xhr.responseText);
                            console.log('[SessionGuard] Session status:', data);
                            self.handleHeartbeatResponse(data);
                        } catch (e) {
                            console.error('[SessionGuard] Parse error:', e);
                        }
                    } else if (xhr.status === 401 || xhr.status === 403) {
                        console.warn('[SessionGuard] Session expired (HTTP ' + xhr.status + ')');
                        self.handleSessionExpired();
                    } else {
                        console.error('[SessionGuard] Heartbeat failed with status:', xhr.status);
                    }
                }
            };

            xhr.send();
        },

        handleHeartbeatResponse: function(data) {
            if (!data.active) {
                this.handleSessionExpired();
                return;
            }

            this.expiresIn = data.expires_in;

            if (data.should_warn && !this.warningShown) {
                this.showWarning(data.expires_in);
            }
        },

        startExpiryCheck: function() {
            var self = this;

            this.checkTimer = setInterval(function() {
                if (self.expiresIn !== null) {
                    // Decrement local counter
                    self.expiresIn = Math.max(0, self.expiresIn - (self.checkInterval / 1000));

                    if (self.expiresIn <= 0) {
                        self.handleSessionExpired();
                    } else if (self.expiresIn <= self.warningThreshold && !self.warningShown) {
                        self.showWarning(self.expiresIn);
                    }

                    // Update countdown if warning is shown
                    if (self.warningShown) {
                        self.updateCountdown(self.expiresIn);
                    }
                }
            }, this.checkInterval);
        },

        trackActivity: function() {
            var self = this;
            var events = ['mousedown', 'keydown', 'scroll', 'touchstart'];

            // Create a single bound handler for all events (memory efficient)
            // Debounced to prevent excessive updates
            var lastUpdate = 0;
            this.boundActivityHandler = function() {
                var now = Date.now();
                // Debounce: update at most once per second
                if (now - lastUpdate > 1000) {
                    self.lastActivity = now;
                    lastUpdate = now;
                }
            };

            // Store events for cleanup
            this.trackedEvents = events;

            events.forEach(function(event) {
                document.addEventListener(event, self.boundActivityHandler, { passive: true });
            });
        },

        showWarning: function(secondsRemaining) {
            this.warningShown = true;

            // Create warning dialog
            var dialog = document.createElement('div');
            dialog.id = 'session-warning-dialog';
            dialog.className = 'session-warning-overlay';
            dialog.innerHTML = this.getWarningHTML(secondsRemaining);

            document.body.appendChild(dialog);

            // Add event listeners
            var self = this;
            var extendBtn = document.getElementById('session-extend-btn');
            var logoutBtn = document.getElementById('session-logout-btn');

            if (extendBtn) {
                extendBtn.addEventListener('click', function() {
                    self.extendSession();
                });
            }

            if (logoutBtn) {
                logoutBtn.addEventListener('click', function() {
                    self.logout();
                });
            }

            // Add styles if not already present
            if (!document.getElementById('session-warning-styles')) {
                var style = document.createElement('style');
                style.id = 'session-warning-styles';
                style.textContent = this.getWarningStyles();
                document.head.appendChild(style);
            }
        },

        getWarningHTML: function(secondsRemaining) {
            var minutes = Math.floor(secondsRemaining / 60);
            var seconds = Math.floor(secondsRemaining % 60);

            return '<div class="session-warning-dialog">' +
                '<div class="session-warning-icon">&#9888;</div>' +
                '<h2 class="session-warning-title">Session Expiring</h2>' +
                '<p class="session-warning-text">Your session will expire in:</p>' +
                '<div class="session-warning-countdown" id="session-countdown">' +
                    this.formatTime(minutes, seconds) +
                '</div>' +
                '<p class="session-warning-subtext">Click "Stay Logged In" to extend your session.</p>' +
                '<div class="session-warning-buttons">' +
                    '<button id="session-logout-btn" class="session-btn session-btn-secondary">Log Out</button>' +
                    '<button id="session-extend-btn" class="session-btn session-btn-primary">Stay Logged In</button>' +
                '</div>' +
            '</div>';
        },

        formatTime: function(minutes, seconds) {
            return String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
        },

        updateCountdown: function(secondsRemaining) {
            var countdown = document.getElementById('session-countdown');
            if (countdown) {
                var minutes = Math.floor(secondsRemaining / 60);
                var seconds = Math.floor(secondsRemaining % 60);
                countdown.textContent = this.formatTime(minutes, seconds);
            }
        },

        getWarningStyles: function() {
            return '.session-warning-overlay {' +
                'position: fixed;' +
                'inset: 0;' +
                'background: rgba(0, 0, 0, 0.7);' +
                'display: flex;' +
                'align-items: center;' +
                'justify-content: center;' +
                'z-index: 10000;' +
                'font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;' +
            '}' +
            '.session-warning-dialog {' +
                'background: white;' +
                'border-radius: 12px;' +
                'padding: 2rem;' +
                'max-width: 400px;' +
                'text-align: center;' +
                'box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);' +
            '}' +
            '.session-warning-icon {' +
                'font-size: 3rem;' +
                'color: #f59e0b;' +
            '}' +
            '.session-warning-title {' +
                'margin: 1rem 0 0.5rem;' +
                'font-size: 1.5rem;' +
                'color: #1e293b;' +
            '}' +
            '.session-warning-text {' +
                'color: #64748b;' +
                'margin: 0;' +
            '}' +
            '.session-warning-countdown {' +
                'font-size: 3rem;' +
                'font-weight: bold;' +
                'color: #dc2626;' +
                'font-family: monospace;' +
                'margin: 1rem 0;' +
            '}' +
            '.session-warning-subtext {' +
                'color: #64748b;' +
                'font-size: 0.875rem;' +
                'margin-bottom: 1.5rem;' +
            '}' +
            '.session-warning-buttons {' +
                'display: flex;' +
                'gap: 0.75rem;' +
            '}' +
            '.session-btn {' +
                'flex: 1;' +
                'padding: 0.75rem 1rem;' +
                'border-radius: 0.5rem;' +
                'font-size: 1rem;' +
                'font-weight: 500;' +
                'cursor: pointer;' +
                'border: none;' +
            '}' +
            '.session-btn-primary {' +
                'background: #2563eb;' +
                'color: white;' +
            '}' +
            '.session-btn-primary:hover {' +
                'background: #1d4ed8;' +
            '}' +
            '.session-btn-secondary {' +
                'background: #f1f5f9;' +
                'color: #475569;' +
            '}' +
            '.session-btn-secondary:hover {' +
                'background: #e2e8f0;' +
            '}';
        },

        extendSession: function() {
            // Send heartbeat to extend session
            this.sendHeartbeat();

            // Hide warning
            this.hideWarning();
        },

        hideWarning: function() {
            var dialog = document.getElementById('session-warning-dialog');
            if (dialog) {
                dialog.remove();
            }
            this.warningShown = false;
        },

        handleSessionExpired: function() {
            // Stop timers
            clearInterval(this.heartbeatTimer);
            clearInterval(this.checkTimer);

            // Redirect to login
            window.location.href = this.basePath + '/login?expired=1';
        },

        logout: function() {
            // Create and submit logout form
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = this.basePath + '/logout';

            // Add CSRF token if available
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta) {
                var csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_csrf_token';
                csrfInput.value = csrfMeta.content;
                form.appendChild(csrfInput);
            }

            document.body.appendChild(form);
            form.submit();
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            SessionGuard.init();
        });
    } else {
        SessionGuard.init();
    }

        /**
         * Pause timers (when tab is hidden)
         */
        pauseTimers: function() {
            if (this.heartbeatTimer) {
                clearInterval(this.heartbeatTimer);
                this.heartbeatTimer = null;
            }
            if (this.checkTimer) {
                clearInterval(this.checkTimer);
                this.checkTimer = null;
            }
            console.log('[SessionGuard] Timers paused (tab hidden)');
        },

        /**
         * Resume timers (when tab becomes visible)
         */
        resumeTimers: function() {
            // Send immediate heartbeat to sync state
            this.sendHeartbeat();

            // Restart timers
            var self = this;
            if (!this.heartbeatTimer) {
                this.heartbeatTimer = setInterval(function() {
                    self.sendHeartbeat();
                }, this.heartbeatInterval);
            }
            if (!this.checkTimer) {
                this.startExpiryCheck();
            }
            console.log('[SessionGuard] Timers resumed (tab visible)');
        },

        /**
         * Cleanup all resources (prevents memory leaks)
         */
        destroy: function() {
            // Clear timers
            if (this.heartbeatTimer) {
                clearInterval(this.heartbeatTimer);
                this.heartbeatTimer = null;
            }
            if (this.checkTimer) {
                clearInterval(this.checkTimer);
                this.checkTimer = null;
            }

            // Remove event listeners
            var self = this;
            if (this.boundActivityHandler && this.trackedEvents) {
                this.trackedEvents.forEach(function(event) {
                    document.removeEventListener(event, self.boundActivityHandler, { passive: true });
                });
            }

            if (this.boundVisibilityHandler) {
                document.removeEventListener('visibilitychange', this.boundVisibilityHandler);
            }

            // Remove warning dialog if present
            this.hideWarning();

            this.initialized = false;
            console.log('[SessionGuard] Destroyed');
        }
    };

    // Expose globally for debugging
    window.SessionGuard = SessionGuard;
})();
