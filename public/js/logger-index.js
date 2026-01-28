/**
 * Logger Index Page JavaScript
 * CSP compliant - no inline scripts
 */
(function() {
    'use strict';

    var csrfToken = '';
    var baseUrl = '';

    function init() {
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

        var baseUrlMeta = document.querySelector('meta[name="admin-base-url"]');
        baseUrl = baseUrlMeta ? baseUrlMeta.getAttribute('content') : '';

        initChannelToggles();
        initChannelLevels();
        initAutoResetToggles();
        initFileCheckboxes();
        initBulkActions();
        initDeleteButtons();
    }

    // Channel enable/disable toggle
    function initChannelToggles() {
        var toggles = document.querySelectorAll('.channel-toggle');
        for (var i = 0; i < toggles.length; i++) {
            toggles[i].addEventListener('change', function() {
                var channel = this.dataset.channel;
                var enabled = this.checked;
                updateChannel(channel, { enabled: enabled ? '1' : '0' });

                var card = this.closest('.eap-logger-channel');
                if (enabled) {
                    card.classList.remove('eap-logger-channel--disabled');
                } else {
                    card.classList.add('eap-logger-channel--disabled');
                }
            });
        }
    }

    // Channel level select
    function initChannelLevels() {
        var selects = document.querySelectorAll('.channel-level');
        for (var i = 0; i < selects.length; i++) {
            selects[i].addEventListener('change', function() {
                var channel = this.dataset.channel;
                var level = this.value;
                updateChannel(channel, { min_level: level });

                // Update level badge
                var card = this.closest('.eap-logger-channel');
                var badge = card.querySelector('.eap-logger-level');
                if (badge) {
                    badge.textContent = level.toUpperCase();
                    badge.className = 'eap-logger-level eap-logger-level--' + level;
                }
            });
        }
    }

    // Auto-reset toggle
    function initAutoResetToggles() {
        var toggles = document.querySelectorAll('.channel-auto-reset');
        for (var i = 0; i < toggles.length; i++) {
            toggles[i].addEventListener('change', function() {
                var channel = this.dataset.channel;
                var autoReset = this.checked;
                updateChannel(channel, { auto_reset_enabled: autoReset ? '1' : '0' });
            });
        }
    }

    // File checkboxes
    function initFileCheckboxes() {
        var selectAll = document.getElementById('select-all');
        var checkboxes = document.querySelectorAll('.file-checkbox');
        var bulkActions = document.getElementById('bulk-actions');
        var selectedCount = document.getElementById('selected-count');

        if (!selectAll) return;

        selectAll.addEventListener('change', function() {
            var checked = this.checked;
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = checked;
            }
            updateBulkUI();
        });

        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].addEventListener('change', updateBulkUI);
        }

        function updateBulkUI() {
            var count = 0;
            for (var i = 0; i < checkboxes.length; i++) {
                if (checkboxes[i].checked) count++;
            }

            if (selectedCount) selectedCount.textContent = count;

            if (bulkActions) {
                if (count > 0) {
                    bulkActions.classList.add('eap-logger-bulk--visible');
                } else {
                    bulkActions.classList.remove('eap-logger-bulk--visible');
                }
            }
        }
    }

    // Bulk actions
    function initBulkActions() {
        var bulkDownload = document.getElementById('bulk-download');
        var bulkDelete = document.getElementById('bulk-delete');

        if (bulkDownload) {
            bulkDownload.addEventListener('click', function() {
                var files = getSelectedFiles();
                if (files.length === 0) return;

                // Download each file
                for (var i = 0; i < files.length; i++) {
                    var link = document.createElement('a');
                    link.href = baseUrl + '/logger/file/download?name=' + encodeURIComponent(files[i]);
                    link.download = files[i];
                    link.click();
                }
            });
        }

        if (bulkDelete) {
            bulkDelete.addEventListener('click', function() {
                var files = getSelectedFiles();
                if (files.length === 0) return;

                if (!confirm('Delete ' + files.length + ' file(s)? This cannot be undone.')) {
                    return;
                }

                deleteFiles(files);
            });
        }
    }

    // Single delete buttons
    function initDeleteButtons() {
        var buttons = document.querySelectorAll('.delete-file');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function() {
                var file = this.dataset.file;
                if (!confirm('Delete ' + file + '? This cannot be undone.')) {
                    return;
                }
                deleteFiles([file]);
            });
        }
    }

    function getSelectedFiles() {
        var checkboxes = document.querySelectorAll('.file-checkbox:checked');
        var files = [];
        for (var i = 0; i < checkboxes.length; i++) {
            files.push(checkboxes[i].value);
        }
        return files;
    }

    function deleteFiles(files) {
        var formData = new FormData();
        formData.append('_csrf_token', csrfToken);
        for (var i = 0; i < files.length; i++) {
            formData.append('files[]', files[i]);
        }

        fetch(baseUrl + '/logger/file/delete', {
            method: 'POST',
            body: formData
        })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (result.success) {
                // Remove rows from table
                for (var i = 0; i < files.length; i++) {
                    var row = document.querySelector('tr[data-file="' + files[i] + '"]');
                    if (row) row.remove();
                }
                showToast(files.length + ' file(s) deleted', 'success');
            } else {
                showToast(result.error || 'Delete failed', 'error');
            }
        })
        .catch(function() {
            showToast('Network error', 'error');
        });
    }

    function updateChannel(channel, data) {
        var formData = new FormData();
        formData.append('channel', channel);
        formData.append('_csrf_token', csrfToken);

        for (var key in data) {
            if (data.hasOwnProperty(key)) {
                formData.append(key, data[key]);
            }
        }

        fetch(baseUrl + '/logger/channels/update', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (result.success) {
                showToast('Channel updated', 'success');
            } else {
                showToast(result.error || 'Update failed', 'error');
            }
        })
        .catch(function() {
            showToast('Network error', 'error');
        });
    }

    function showToast(message, type) {
        var toast = document.createElement('div');
        toast.className = 'eap-logger-toast eap-logger-toast--' + type;
        toast.textContent = message;
        document.body.appendChild(toast);

        setTimeout(function() {
            toast.classList.add('eap-logger-toast--visible');
        }, 10);

        setTimeout(function() {
            toast.classList.remove('eap-logger-toast--visible');
            setTimeout(function() {
                toast.remove();
            }, 300);
        }, 3000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
