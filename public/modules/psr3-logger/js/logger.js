/**
 * Enterprise PSR3 Logger - Admin Module JavaScript
 *
 * CSP Compliant: No inline scripts
 * All event handlers are attached via addEventListener
 *
 * @package senza1dio/enterprise-psr3-logger
 * @version 1.0.0
 */

(function() {
    'use strict';

    // Get admin base path from data attribute on body or default
    const adminBase = document.body.dataset.adminBasePath || '/admin';

    // Get CSRF token from meta tag
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    /**
     * Initialize logger dashboard functionality
     */
    function initDashboard() {
        initChannelToggles();
        initChannelLevelSelects();
        initTelegramForm();
        initFileManagement();
    }

    /**
     * Channel toggle handlers
     */
    function initChannelToggles() {
        document.querySelectorAll('.channel-toggle').forEach(toggle => {
            toggle.addEventListener('change', function() {
                const channel = this.dataset.channel;
                const enabled = this.checked ? '1' : '0';
                const levelSelect = document.querySelector(`.channel-level[data-channel="${channel}"]`);
                const level = levelSelect ? levelSelect.value : 'info';

                updateChannel(channel, enabled, level);
            });
        });
    }

    /**
     * Channel level select handlers
     */
    function initChannelLevelSelects() {
        document.querySelectorAll('.channel-level').forEach(select => {
            select.addEventListener('change', function() {
                const channel = this.dataset.channel;
                const level = this.value;
                const toggle = document.querySelector(`.channel-toggle[data-channel="${channel}"]`);
                const enabled = toggle && toggle.checked ? '1' : '0';

                updateChannel(channel, enabled, level);
            });
        });
    }

    /**
     * Update channel configuration via AJAX
     */
    function updateChannel(channel, enabled, level) {
        fetch(`${adminBase}/logger/channel/update`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: `channel=${encodeURIComponent(channel)}&enabled=${enabled}&level=${encodeURIComponent(level)}&_csrf_token=${encodeURIComponent(csrfToken)}`
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showNotification(data.error || 'Failed to update channel', 'error');
            } else {
                showNotification('Channel updated', 'success');
            }
        })
        .catch(err => {
            console.error('Error:', err);
            showNotification('Network error', 'error');
        });
    }

    /**
     * Telegram form handlers
     */
    function initTelegramForm() {
        const telegramForm = document.getElementById('telegram-form');
        const telegramEnabled = document.getElementById('telegram-enabled');
        const testTelegramBtn = document.getElementById('test-telegram');

        if (telegramForm) {
            telegramForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                if (telegramEnabled) {
                    formData.append('enabled', telegramEnabled.checked ? '1' : '0');
                }

                fetch(`${adminBase}/logger/telegram/update`, {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Telegram settings saved', 'success');
                    } else {
                        showNotification(data.error || 'Failed to save', 'error');
                    }
                })
                .catch(err => {
                    console.error('Error:', err);
                    showNotification('Network error', 'error');
                });
            });
        }

        if (testTelegramBtn && telegramForm) {
            testTelegramBtn.addEventListener('click', function() {
                const formData = new FormData(telegramForm);

                fetch(`${adminBase}/logger/telegram/test`, {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    showNotification(
                        data.success ? 'Test message sent!' : (data.error || 'Test failed'),
                        data.success ? 'success' : 'error'
                    );
                })
                .catch(err => {
                    console.error('Error:', err);
                    showNotification('Network error', 'error');
                });
            });
        }

        // Toggle label update
        if (telegramEnabled) {
            const label = telegramEnabled.closest('.eap-toggle-inline')?.querySelector('.eap-toggle-label');
            if (label) {
                telegramEnabled.addEventListener('change', function() {
                    label.textContent = this.checked ? 'Enabled' : 'Disabled';
                });
            }
        }
    }

    /**
     * File management handlers
     */
    function initFileManagement() {
        const selectAll = document.getElementById('select-all-files');
        const fileCheckboxes = document.querySelectorAll('.file-checkbox');
        const deleteBtn = document.getElementById('delete-selected');
        const filesForm = document.getElementById('files-form');

        // Select all checkbox
        if (selectAll) {
            selectAll.addEventListener('change', function() {
                fileCheckboxes.forEach(cb => cb.checked = this.checked);
                updateDeleteButton();
            });
        }

        // Individual file checkboxes
        fileCheckboxes.forEach(cb => {
            cb.addEventListener('change', updateDeleteButton);
        });

        function updateDeleteButton() {
            const checked = document.querySelectorAll('.file-checkbox:checked').length;
            if (deleteBtn) {
                deleteBtn.disabled = checked === 0;
                deleteBtn.textContent = checked > 0 ? `Delete Selected (${checked})` : 'Delete Selected';
            }
        }

        // Delete button
        if (deleteBtn && filesForm) {
            deleteBtn.addEventListener('click', function() {
                if (!confirm('Delete selected log files?')) return;

                const formData = new FormData(filesForm);

                fetch(`${adminBase}/logger/file/delete`, {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        showNotification(data.error || 'Failed to delete files', 'error');
                    }
                })
                .catch(err => {
                    console.error('Error:', err);
                    showNotification('Network error', 'error');
                });
            });
        }
    }

    /**
     * Initialize database logs page
     */
    function initDatabaseLogs() {
        // Toggle context visibility
        document.querySelectorAll('.toggle-context').forEach(btn => {
            btn.addEventListener('click', function() {
                const logId = this.dataset.logId;
                const context = document.getElementById('context-' + logId);
                if (context) {
                    const isHidden = context.style.display === 'none';
                    context.style.display = isHidden ? 'block' : 'none';
                    this.textContent = isHidden ? 'Hide Context' : 'Show Context';
                }
            });
        });

        // Clear logs modal
        const clearLogsBtn = document.getElementById('clear-logs-btn');
        const clearModal = document.getElementById('clear-modal');
        const modalBackdrop = clearModal?.querySelector('.eap-modal__backdrop');
        const modalClose = clearModal?.querySelector('.eap-modal__close');

        if (clearLogsBtn && clearModal) {
            clearLogsBtn.addEventListener('click', function() {
                clearModal.style.display = 'flex';
            });

            if (modalBackdrop) {
                modalBackdrop.addEventListener('click', closeModal);
            }
            if (modalClose) {
                modalClose.addEventListener('click', closeModal);
            }
        }

        function closeModal() {
            if (clearModal) {
                clearModal.style.display = 'none';
            }
        }

        // Make closeModal available globally for inline onclick (backwards compat)
        window.closeModal = closeModal;
    }

    /**
     * Initialize PHP errors page
     */
    function initPhpErrors() {
        const scrollBottomBtn = document.getElementById('scroll-bottom');
        const viewer = document.getElementById('errors-viewer');

        if (scrollBottomBtn && viewer) {
            scrollBottomBtn.addEventListener('click', function() {
                viewer.scrollTop = viewer.scrollHeight;
            });
        }

        // Auto-scroll to bottom on load
        if (viewer) {
            viewer.scrollTop = viewer.scrollHeight;
        }
    }

    /**
     * Initialize file viewer page
     */
    function initFileViewer() {
        const viewer = document.getElementById('log-viewer');
        const content = document.getElementById('log-content');
        const searchInput = document.getElementById('log-search');
        const searchResults = document.getElementById('search-results');
        const scrollTopBtn = document.getElementById('scroll-top');
        const scrollBottomBtn = document.getElementById('scroll-bottom');
        const toggleWrapBtn = document.getElementById('toggle-wrap');

        if (!viewer || !content) return;

        const originalContent = content.textContent;

        // Scroll buttons
        if (scrollTopBtn) {
            scrollTopBtn.addEventListener('click', () => {
                viewer.scrollTop = 0;
            });
        }

        if (scrollBottomBtn) {
            scrollBottomBtn.addEventListener('click', () => {
                viewer.scrollTop = viewer.scrollHeight;
            });
        }

        // Toggle wrap
        if (toggleWrapBtn) {
            toggleWrapBtn.addEventListener('click', () => {
                content.classList.toggle('wrap');
            });
        }

        // Search functionality
        if (searchInput && searchResults) {
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    const query = this.value.trim().toLowerCase();

                    if (!query) {
                        content.innerHTML = escapeHtml(originalContent);
                        searchResults.textContent = '';
                        return;
                    }

                    const lines = originalContent.split('\n');
                    let matchCount = 0;
                    const highlighted = lines.map(line => {
                        const lowerLine = line.toLowerCase();
                        if (lowerLine.includes(query)) {
                            matchCount++;
                            const escaped = escapeHtml(line);
                            const regex = new RegExp('(' + escapeRegex(escapeHtml(query)) + ')', 'gi');
                            return escaped.replace(regex, '<span class="highlight">$1</span>');
                        }
                        return escapeHtml(line);
                    });

                    content.innerHTML = highlighted.join('\n');
                    searchResults.textContent = matchCount > 0 ? `${matchCount} matches` : 'No matches';
                }, 200);
            });
        }

        // Auto-scroll to bottom on load
        viewer.scrollTop = viewer.scrollHeight;
    }

    /**
     * Initialize channels configuration page
     */
    function initChannelsPage() {
        const addChannelBtn = document.querySelector('[data-action="add-channel"]');
        const modal = document.getElementById('add-channel-modal');

        if (addChannelBtn && modal) {
            addChannelBtn.addEventListener('click', () => {
                resetChannelModal();
                modal.showModal();
            });
        }

        // Edit channel buttons
        document.querySelectorAll('[data-action="edit-channel"]').forEach(btn => {
            btn.addEventListener('click', function() {
                const channel = this.dataset.channel;
                const level = this.dataset.level;
                const enabled = this.dataset.enabled === 'true';
                const description = this.dataset.description || '';

                editChannel(channel, level, enabled, description);
            });
        });

        function editChannel(channel, level, enabled, description) {
            const modalTitle = document.getElementById('modal-title');
            const channelName = document.getElementById('channel-name');
            const channelLevel = document.getElementById('channel-level');
            const channelEnabled = document.getElementById('channel-enabled');
            const channelDesc = document.getElementById('channel-desc');

            if (modalTitle) modalTitle.textContent = 'Edit Channel';
            if (channelName) {
                channelName.value = channel;
                channelName.readOnly = true;
            }
            if (channelLevel) channelLevel.value = level;
            if (channelEnabled) channelEnabled.checked = enabled;
            if (channelDesc) channelDesc.value = description;

            if (modal) modal.showModal();
        }

        function resetChannelModal() {
            const modalTitle = document.getElementById('modal-title');
            const channelName = document.getElementById('channel-name');
            const channelLevel = document.getElementById('channel-level');
            const channelEnabled = document.getElementById('channel-enabled');
            const channelDesc = document.getElementById('channel-desc');

            if (modalTitle) modalTitle.textContent = 'Add Channel';
            if (channelName) {
                channelName.value = '';
                channelName.readOnly = false;
            }
            if (channelLevel) channelLevel.value = 'info';
            if (channelEnabled) channelEnabled.checked = true;
            if (channelDesc) channelDesc.value = '';
        }

        // Make editChannel available globally for backwards compat
        window.editChannel = editChannel;
    }

    /**
     * Show notification toast
     */
    function showNotification(message, type = 'info') {
        // Remove existing notifications
        document.querySelectorAll('.eap-toast').forEach(el => el.remove());

        const toast = document.createElement('div');
        toast.className = `eap-toast eap-toast--${type}`;
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            z-index: 9999;
            animation: slideIn 0.3s ease;
        `;

        if (type === 'success') {
            toast.style.background = 'var(--eap-neon-green)';
            toast.style.color = 'var(--eap-void)';
        } else if (type === 'error') {
            toast.style.background = '#ef4444';
            toast.style.color = 'white';
        } else {
            toast.style.background = 'var(--eap-surface)';
            toast.style.color = 'var(--eap-text)';
            toast.style.border = '1px solid var(--eap-border)';
        }

        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    /**
     * Helper: Escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Helper: Escape regex special characters
     */
    function escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * Apply dynamic channel colors from data attributes
     */
    function applyChannelColors() {
        document.querySelectorAll('.eap-channel-card[data-channel-bg]').forEach(card => {
            const bg = card.dataset.channelBg;
            const text = card.dataset.channelText;
            if (bg) card.style.setProperty('--channel-bg', bg);
            if (text) card.style.setProperty('--channel-text', text);
        });
    }

    /**
     * Auto-initialize based on page
     */
    function init() {
        // Apply dynamic channel colors
        applyChannelColors();
        // Dashboard page
        if (document.querySelector('.channel-toggle')) {
            initDashboard();
        }

        // Database logs page
        if (document.querySelector('.toggle-context') || document.getElementById('clear-logs-btn')) {
            initDatabaseLogs();
        }

        // PHP errors page
        if (document.getElementById('errors-viewer')) {
            initPhpErrors();
        }

        // File viewer page
        if (document.getElementById('log-viewer')) {
            initFileViewer();
        }

        // Channels configuration page
        if (document.getElementById('add-channel-modal')) {
            initChannelsPage();
        }
    }

    /**
     * Initialize modal close buttons and confirm dialogs
     */
    function initModalAndConfirm() {
        // Modal close buttons
        document.querySelectorAll('.eap-modal__close, .eap-modal__cancel').forEach(btn => {
            btn.addEventListener('click', function() {
                const modal = this.closest('.eap-modal');
                if (modal) {
                    if (modal.tagName === 'DIALOG') {
                        modal.close();
                    } else {
                        modal.hidden = true;
                    }
                }
            });
        });

        // Modal backdrop click to close
        document.querySelectorAll('.eap-modal__backdrop').forEach(backdrop => {
            backdrop.addEventListener('click', function() {
                const modal = this.closest('.eap-modal');
                if (modal) modal.hidden = true;
            });
        });

        // Confirm dialogs on buttons
        document.querySelectorAll('[data-confirm]').forEach(btn => {
            btn.addEventListener('click', function(e) {
                if (!confirm(this.dataset.confirm)) {
                    e.preventDefault();
                }
            });
        });

        // Open modal buttons
        document.querySelectorAll('[id$="-btn"]').forEach(btn => {
            const modalId = btn.id.replace('-btn', '-modal');
            const modal = document.getElementById(modalId);
            if (modal) {
                btn.addEventListener('click', () => {
                    if (modal.tagName === 'DIALOG') {
                        modal.showModal();
                    } else {
                        modal.hidden = false;
                    }
                });
            }
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            init();
            initModalAndConfirm();
        });
    } else {
        init();
        initModalAndConfirm();
    }

    // Add CSS animation keyframes
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);

})();
