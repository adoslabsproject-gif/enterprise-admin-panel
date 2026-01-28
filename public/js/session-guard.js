/**
 * Enterprise Admin Panel - Session Guard
 *
 * Client-side session monitoring with:
 * - Heartbeat every 30 seconds to keep session alive
 * - Warning dialog 5 minutes before expiry
 * - Real-time countdown (every second)
 * - Auto-logout when session expires
 * - Activity tracking (mouse, keyboard, scroll)
 *
 * @version 2.1.0 - Fixed button event handling
 */
(function() {
    'use strict';

    var SessionGuard = {
        // Configuration
        heartbeatInterval: 30000,  // 30 seconds
        warningThreshold: 300,     // 5 minutes in seconds

        // State
        expiresAt: null,           // Absolute timestamp when session expires
        lastActivity: Date.now(),
        warningShown: false,
        heartbeatTimer: null,
        countdownTimer: null,      // setInterval for countdown
        initialized: false,

        // Bound event handlers (for cleanup)
        boundActivityHandler: null,
        boundVisibilityHandler: null,
        boundDialogClickHandler: null,

        init: function() {
            // Prevent double initialization
            if (this.initialized) {
                console.warn('[SessionGuard] Already initialized, skipping');
                return;
            }
            this.initialized = true;

            // Get admin base path from current URL
            this.basePath = this.getBasePath();
            console.log('[SessionGuard] Initialized with basePath:', this.basePath);

            // Start heartbeat
            this.startHeartbeat();

            // Track user activity
            this.trackActivity();

            // Cleanup on page unload
            var self = this;
            window.addEventListener('beforeunload', function() {
                self.destroy();
            }, { once: true });

            // Pause when tab is hidden
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
            var path = window.location.pathname;
            var match = path.match(/^(\/x-[a-f0-9]+)/);
            return match ? match[1] : '';
        },

        startHeartbeat: function() {
            var self = this;
            this.sendHeartbeat();
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
                        self.handleSessionExpired();
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

            // Convert expires_in to absolute timestamp
            this.expiresAt = Date.now() + (data.expires_in * 1000);

            if (data.should_warn && !this.warningShown) {
                this.showWarning();
            }
        },

        getRemainingSeconds: function() {
            if (this.expiresAt === null) return null;
            return Math.max(0, Math.floor((this.expiresAt - Date.now()) / 1000));
        },

        trackActivity: function() {
            var self = this;
            var events = ['mousedown', 'keydown', 'scroll', 'touchstart'];
            var lastUpdate = 0;

            this.boundActivityHandler = function() {
                var now = Date.now();
                if (now - lastUpdate > 1000) {
                    self.lastActivity = now;
                    lastUpdate = now;
                }
            };

            this.trackedEvents = events;
            events.forEach(function(event) {
                document.addEventListener(event, self.boundActivityHandler, { passive: true });
            });
        },

        showWarning: function() {
            var self = this;
            this.warningShown = true;

            // Remove existing dialog if any
            var existing = document.getElementById('session-warning-dialog');
            if (existing) existing.remove();

            // Create dialog
            var dialog = document.createElement('div');
            dialog.id = 'session-warning-dialog';
            dialog.className = 'session-warning-overlay';
            dialog.innerHTML = this.getWarningHTML();
            document.body.appendChild(dialog);

            // Use event delegation on the dialog container
            this.boundDialogClickHandler = function(e) {
                var target = e.target;

                // Check if clicked element or parent is a button
                if (target.id === 'session-extend-btn' || target.closest('#session-extend-btn')) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('[SessionGuard] EXTEND button clicked!');
                    self.extendSession();
                    return;
                }

                if (target.id === 'session-logout-btn' || target.closest('#session-logout-btn')) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('[SessionGuard] LOGOUT button clicked!');
                    self.logout();
                    return;
                }
            };

            dialog.addEventListener('click', this.boundDialogClickHandler, true);

            // Start countdown timer (every second)
            this.startCountdown();

            console.log('[SessionGuard] Warning dialog shown, buttons attached');
        },

        startCountdown: function() {
            var self = this;

            // Clear existing timer
            if (this.countdownTimer) {
                clearInterval(this.countdownTimer);
            }

            // Update immediately
            this.updateCountdown();

            // Then every second
            this.countdownTimer = setInterval(function() {
                var remaining = self.getRemainingSeconds();

                if (remaining === null) return;

                if (remaining <= 0) {
                    self.handleSessionExpired();
                    return;
                }

                self.updateCountdown();
            }, 1000);
        },

        stopCountdown: function() {
            if (this.countdownTimer) {
                clearInterval(this.countdownTimer);
                this.countdownTimer = null;
            }
        },

        getWarningHTML: function() {
            var remaining = this.getRemainingSeconds() || 0;
            var minutes = Math.floor(remaining / 60);
            var seconds = remaining % 60;

            return '<div class="session-warning-dialog">' +
                '<div class="session-warning-icon">&#9888;</div>' +
                '<h2 class="session-warning-title">Session Expiring</h2>' +
                '<p class="session-warning-text">Your session will expire in:</p>' +
                '<div class="session-warning-countdown" id="session-countdown">' +
                    this.formatTime(minutes, seconds) +
                '</div>' +
                '<p class="session-warning-subtext">Click "Stay Logged In" to extend your session.</p>' +
                '<div class="session-warning-buttons">' +
                    '<button type="button" id="session-logout-btn" class="session-btn session-btn-secondary">Log Out</button>' +
                    '<button type="button" id="session-extend-btn" class="session-btn session-btn-primary">Stay Logged In</button>' +
                '</div>' +
            '</div>';
        },

        formatTime: function(minutes, seconds) {
            return String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
        },

        updateCountdown: function() {
            var remaining = this.getRemainingSeconds();
            if (remaining === null) return;

            var countdown = document.getElementById('session-countdown');
            if (countdown) {
                var minutes = Math.floor(remaining / 60);
                var seconds = remaining % 60;
                countdown.textContent = this.formatTime(minutes, seconds);

                // Add urgency class when under 1 minute
                if (remaining < 60) {
                    countdown.classList.add('session-warning-countdown--urgent');
                }
            }
        },

        extendSession: function() {
            var self = this;
            var url = this.basePath + '/api/session/extend';

            console.log('[SessionGuard] Extending session via:', url);

            // Disable button
            var extendBtn = document.getElementById('session-extend-btn');
            if (extendBtn) {
                extendBtn.disabled = true;
                extendBtn.textContent = 'Extending...';
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', url, true);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

            // Add CSRF token
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfMeta) {
                xhr.setRequestHeader('X-CSRF-Token', csrfMeta.content);
            }

            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    console.log('[SessionGuard] Extend response:', xhr.status, xhr.responseText);

                    if (xhr.status === 200) {
                        try {
                            var data = JSON.parse(xhr.responseText);
                            if (data.success) {
                                console.log('[SessionGuard] Session extended successfully!');
                                self.expiresAt = Date.now() + (data.expires_in * 1000);
                                self.hideWarning();
                            } else {
                                console.error('[SessionGuard] Extend failed:', data.message);
                                self.resetExtendButton();
                            }
                        } catch (e) {
                            console.error('[SessionGuard] Parse error:', e);
                            self.resetExtendButton();
                        }
                    } else if (xhr.status === 401 || xhr.status === 403) {
                        self.handleSessionExpired();
                    } else {
                        console.error('[SessionGuard] Extend failed with status:', xhr.status);
                        self.resetExtendButton();
                    }
                }
            };

            xhr.onerror = function() {
                console.error('[SessionGuard] Network error');
                self.resetExtendButton();
            };

            xhr.send('');
        },

        resetExtendButton: function() {
            var extendBtn = document.getElementById('session-extend-btn');
            if (extendBtn) {
                extendBtn.disabled = false;
                extendBtn.textContent = 'Stay Logged In';
            }
        },

        hideWarning: function() {
            this.stopCountdown();

            var dialog = document.getElementById('session-warning-dialog');
            if (dialog) {
                if (this.boundDialogClickHandler) {
                    dialog.removeEventListener('click', this.boundDialogClickHandler, true);
                }
                dialog.remove();
            }
            this.warningShown = false;
            console.log('[SessionGuard] Warning hidden');
        },

        handleSessionExpired: function() {
            this.stopCountdown();
            if (this.heartbeatTimer) {
                clearInterval(this.heartbeatTimer);
                this.heartbeatTimer = null;
            }
            window.location.href = this.basePath + '/login?expired=1';
        },

        logout: function() {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = this.basePath + '/logout';

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
        },

        pauseTimers: function() {
            if (this.heartbeatTimer) {
                clearInterval(this.heartbeatTimer);
                this.heartbeatTimer = null;
            }
            this.stopCountdown();
            console.log('[SessionGuard] Timers paused');
        },

        resumeTimers: function() {
            var self = this;
            this.sendHeartbeat();

            if (!this.heartbeatTimer) {
                this.heartbeatTimer = setInterval(function() {
                    self.sendHeartbeat();
                }, this.heartbeatInterval);
            }

            if (this.warningShown && !this.countdownTimer) {
                this.startCountdown();
            }
            console.log('[SessionGuard] Timers resumed');
        },

        destroy: function() {
            if (this.heartbeatTimer) {
                clearInterval(this.heartbeatTimer);
                this.heartbeatTimer = null;
            }
            this.stopCountdown();

            var self = this;
            if (this.boundActivityHandler && this.trackedEvents) {
                this.trackedEvents.forEach(function(event) {
                    document.removeEventListener(event, self.boundActivityHandler, { passive: true });
                });
            }

            if (this.boundVisibilityHandler) {
                document.removeEventListener('visibilitychange', this.boundVisibilityHandler);
            }

            this.hideWarning();
            this.initialized = false;
            console.log('[SessionGuard] Destroyed');
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

    window.SessionGuard = SessionGuard;
})();
