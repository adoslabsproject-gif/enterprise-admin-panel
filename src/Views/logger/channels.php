<?php
/**
 * Log Channels Configuration
 *
 * Manage log channels and their minimum levels
 *
 * @var array $channels List of channels
 * @var array $log_levels Available log levels
 * @var string $admin_base_path Admin base path
 * @var string $csrf_input CSRF hidden input
 */
?>
<link rel="stylesheet" href="/css/logger-channels.css">

<!-- Page Header -->
<div class="eap-page-header">
    <div class="eap-page-header__content">
        <a href="<?= $admin_base_path ?>/logger" class="eap-btn eap-btn--ghost eap-btn--sm">
            &larr; Back to Logger
        </a>
        <h1 class="eap-page-title">Log Channels</h1>
        <p class="eap-page-subtitle">Configure minimum log level for each channel</p>
    </div>
    <div class="eap-page-header__actions">
        <button type="button" class="eap-btn eap-btn--primary" onclick="showAddChannelModal()">
            + Add Channel
        </button>
    </div>
</div>

<!-- Info Notice -->
<div class="eap-notice eap-notice--info">
    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div>
        <strong>How it works:</strong> Logs are only written if their level is >= the channel's minimum level.
        <br>Example: Channel "api" at level WARNING will log warning, error, critical, alert, and emergency.
    </div>
</div>

<!-- Channels Table -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">Configured Channels</span>
        <span class="eap-badge eap-badge--info"><?= count($channels) ?> channels</span>
    </div>
    <div class="eap-card__body eap-card__body--no-padding">
        <table class="eap-table">
            <thead>
                <tr>
                    <th>Channel</th>
                    <th>Min Level</th>
                    <th>Status</th>
                    <th>Description</th>
                    <th>Log Count</th>
                    <th>Last Log</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($channels)): ?>
                    <tr>
                        <td colspan="7" class="eap-table__empty">
                            No channels configured. Add one to get started.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($channels as $channel): ?>
                        <tr data-channel="<?= htmlspecialchars($channel['channel']) ?>">
                            <td>
                                <code class="eap-code"><?= htmlspecialchars($channel['channel']) ?></code>
                            </td>
                            <td>
                                <select class="eap-form__select eap-form__select--inline channel-level"
                                        data-channel="<?= htmlspecialchars($channel['channel']) ?>">
                                    <?php foreach ($log_levels as $level): ?>
                                        <option value="<?= $level ?>"
                                                <?= ($channel['min_level'] ?? 'debug') === $level ? 'selected' : '' ?>>
                                            <?= ucfirst($level) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <label class="eap-form__toggle">
                                    <input type="checkbox" class="channel-enabled"
                                           data-channel="<?= htmlspecialchars($channel['channel']) ?>"
                                           <?= ($channel['enabled'] ?? true) ? 'checked' : '' ?>>
                                    <span class="eap-form__toggle-slider"></span>
                                </label>
                            </td>
                            <td>
                                <span class="eap-text-muted">
                                    <?= htmlspecialchars($channel['description'] ?? '-') ?>
                                </span>
                            </td>
                            <td>
                                <span class="eap-badge eap-badge--secondary">
                                    <?= number_format($channel['log_count'] ?? 0) ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($channel['last_log_at'])): ?>
                                    <span class="eap-text-muted" title="<?= htmlspecialchars($channel['last_log_at']) ?>">
                                        <?= date('M j, H:i', strtotime($channel['last_log_at'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="eap-text-muted">Never</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="eap-btn-group">
                                    <button type="button" class="eap-btn eap-btn--ghost eap-btn--sm"
                                            onclick="editChannel('<?= htmlspecialchars($channel['channel']) ?>')">
                                        Edit
                                    </button>
                                    <?php if ($channel['channel'] !== 'default'): ?>
                                        <button type="button" class="eap-btn eap-btn--ghost eap-btn--sm eap-btn--danger"
                                                onclick="deleteChannel('<?= htmlspecialchars($channel['channel']) ?>')">
                                            Delete
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Log Levels Reference -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">Log Levels Reference</span>
    </div>
    <div class="eap-card__body">
        <div class="eap-grid eap-grid--cols-4">
            <?php
            $levelColors = [
                'debug' => 'secondary',
                'info' => 'info',
                'notice' => 'info',
                'warning' => 'warning',
                'error' => 'danger',
                'critical' => 'danger',
                'alert' => 'danger',
                'emergency' => 'danger',
            ];
            $levelDescriptions = [
                'debug' => 'Detailed debug information',
                'info' => 'Interesting events',
                'notice' => 'Normal but significant events',
                'warning' => 'Exceptional occurrences that are not errors',
                'error' => 'Runtime errors that do not require immediate action',
                'critical' => 'Critical conditions',
                'alert' => 'Action must be taken immediately',
                'emergency' => 'System is unusable',
            ];
            foreach ($log_levels as $level):
            ?>
                <div class="eap-level-card">
                    <span class="eap-badge eap-badge--<?= $levelColors[$level] ?? 'secondary' ?>">
                        <?= ucfirst($level) ?>
                    </span>
                    <p class="eap-text-muted eap-text-sm"><?= $levelDescriptions[$level] ?? '' ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Add/Edit Channel Modal -->
<div id="channel-modal" class="eap-modal eap-hidden">
    <div class="eap-modal__backdrop" onclick="closeChannelModal()"></div>
    <div class="eap-modal__content">
        <div class="eap-modal__header">
            <h3 class="eap-modal__title" id="modal-title">Add Channel</h3>
            <button type="button" class="eap-modal__close" onclick="closeChannelModal()">&times;</button>
        </div>
        <form id="channel-form" method="POST" action="<?= $admin_base_path ?>/logger/channels/update">
            <?= $csrf_input ?>
            <input type="hidden" name="original_channel" id="original-channel" value="">

            <div class="eap-modal__body">
                <div class="eap-form__group">
                    <label class="eap-form__label" for="channel-name">Channel Name</label>
                    <input type="text" name="channel" id="channel-name" class="eap-form__input"
                           required pattern="[a-z0-9_-]+" placeholder="my_channel">
                    <div class="eap-form__hint">
                        Lowercase letters, numbers, underscores, and hyphens only.
                    </div>
                </div>

                <div class="eap-form__group">
                    <label class="eap-form__label" for="channel-min-level">Minimum Level</label>
                    <select name="min_level" id="channel-min-level" class="eap-form__select">
                        <?php foreach ($log_levels as $level): ?>
                            <option value="<?= $level ?>"><?= ucfirst($level) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="eap-form__group">
                    <label class="eap-form__label" for="channel-description">Description</label>
                    <input type="text" name="description" id="channel-description" class="eap-form__input"
                           placeholder="Optional description">
                </div>

                <div class="eap-form__group">
                    <label class="eap-form__checkbox">
                        <input type="checkbox" name="enabled" id="channel-enabled" checked>
                        <span>Enabled</span>
                    </label>
                </div>
            </div>

            <div class="eap-modal__footer">
                <button type="button" class="eap-btn eap-btn--secondary" onclick="closeChannelModal()">
                    Cancel
                </button>
                <button type="submit" class="eap-btn eap-btn--primary">
                    Save Channel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="delete-modal" class="eap-modal eap-hidden">
    <div class="eap-modal__backdrop" onclick="closeDeleteModal()"></div>
    <div class="eap-modal__content eap-modal__content--sm">
        <div class="eap-modal__header">
            <h3 class="eap-modal__title">Delete Channel</h3>
            <button type="button" class="eap-modal__close" onclick="closeDeleteModal()">&times;</button>
        </div>
        <form method="POST" action="<?= $admin_base_path ?>/logger/channels/delete">
            <?= $csrf_input ?>
            <input type="hidden" name="channel" id="delete-channel" value="">

            <div class="eap-modal__body">
                <p>Are you sure you want to delete the channel <code id="delete-channel-name"></code>?</p>
                <p class="eap-text-muted">This will not delete existing logs, only the configuration.</p>
            </div>

            <div class="eap-modal__footer">
                <button type="button" class="eap-btn eap-btn--secondary" onclick="closeDeleteModal()">
                    Cancel
                </button>
                <button type="submit" class="eap-btn eap-btn--danger">
                    Delete
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-save on level change
    document.querySelectorAll('.channel-level').forEach(select => {
        select.addEventListener('change', function() {
            updateChannel(this.dataset.channel, { min_level: this.value });
        });
    });

    // Auto-save on enabled toggle
    document.querySelectorAll('.channel-enabled').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateChannel(this.dataset.channel, { enabled: this.checked });
        });
    });
});

function updateChannel(channel, data) {
    const formData = new FormData();
    formData.append('channel', channel);
    formData.append('_csrf_token', document.querySelector('input[name="_csrf_token"]').value);

    for (const [key, value] of Object.entries(data)) {
        formData.append(key, value);
    }

    fetch('<?= $admin_base_path ?>/logger/channels/update', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Channel updated', 'success');
        } else {
            showNotification(data.error || 'Failed to update channel', 'error');
        }
    })
    .catch(error => {
        showNotification('Network error: ' + error.message, 'error');
    });
}

function showAddChannelModal() {
    document.getElementById('modal-title').textContent = 'Add Channel';
    document.getElementById('original-channel').value = '';
    document.getElementById('channel-name').value = '';
    document.getElementById('channel-name').disabled = false;
    document.getElementById('channel-min-level').value = 'info';
    document.getElementById('channel-description').value = '';
    document.getElementById('channel-enabled').checked = true;
    document.getElementById('channel-modal').classList.remove('eap-hidden');
}

function editChannel(channel) {
    const row = document.querySelector(`tr[data-channel="${channel}"]`);
    if (!row) return;

    document.getElementById('modal-title').textContent = 'Edit Channel';
    document.getElementById('original-channel').value = channel;
    document.getElementById('channel-name').value = channel;
    document.getElementById('channel-name').disabled = true;
    document.getElementById('channel-min-level').value = row.querySelector('.channel-level').value;
    document.getElementById('channel-description').value = row.querySelector('.eap-text-muted')?.textContent?.trim() || '';
    document.getElementById('channel-enabled').checked = row.querySelector('.channel-enabled').checked;
    document.getElementById('channel-modal').classList.remove('eap-hidden');
}

function closeChannelModal() {
    document.getElementById('channel-modal').classList.add('eap-hidden');
}

function deleteChannel(channel) {
    document.getElementById('delete-channel').value = channel;
    document.getElementById('delete-channel-name').textContent = channel;
    document.getElementById('delete-modal').classList.remove('eap-hidden');
}

function closeDeleteModal() {
    document.getElementById('delete-modal').classList.add('eap-hidden');
}

function showNotification(message, type) {
    // Simple notification - can be enhanced with a proper notification system
    const notification = document.createElement('div');
    notification.className = `eap-notification eap-notification--${type}`;
    notification.textContent = message;
    document.body.appendChild(notification);

    setTimeout(() => {
        notification.classList.add('eap-notification--fade-out');
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}
</script>
