<?php
/**
 * Logger Dashboard
 *
 * @var array $channels Channel configurations
 * @var array $stats Global log statistics
 * @var array $log_files Available log files
 * @var array $telegram_config Telegram configuration
 * @var array $storage_info Storage statistics
 * @var string $admin_base_path Admin base path
 * @var string $csrf_token CSRF token
 */

// Calculate storage totals
$totalSize = 0;
$totalFiles = count($log_files);
foreach ($log_files as $file) {
    $totalSize += $file['size'] ?? 0;
}

// Format total size
if ($totalSize >= 1073741824) {
    $totalSizeFormatted = number_format($totalSize / 1073741824, 2) . ' GB';
} elseif ($totalSize >= 1048576) {
    $totalSizeFormatted = number_format($totalSize / 1048576, 2) . ' MB';
} elseif ($totalSize >= 1024) {
    $totalSizeFormatted = number_format($totalSize / 1024, 2) . ' KB';
} else {
    $totalSizeFormatted = $totalSize . ' B';
}
?>
<link rel="stylesheet" href="/css/logger-index.css">

<!-- Page Header -->
<div class="eap-page-header">
    <div class="eap-page-header__content">
        <h1 class="eap-page-title">Logger</h1>
        <p class="eap-page-subtitle">Monitor channels, view logs, configure notifications</p>
    </div>
</div>

<!-- Stats -->
<div class="eap-logger-stats">
    <div class="eap-logger-stat eap-logger-stat--info">
        <div class="eap-logger-stat__icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="22" height="22">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
        </div>
        <div class="eap-logger-stat__content">
            <div class="eap-logger-stat__value"><?= number_format($stats['today'] ?? 0) ?></div>
            <div class="eap-logger-stat__label">Logs Today</div>
        </div>
    </div>

    <div class="eap-logger-stat eap-logger-stat--danger">
        <div class="eap-logger-stat__icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="22" height="22">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>
        <div class="eap-logger-stat__content">
            <div class="eap-logger-stat__value"><?= number_format($stats['errors_today'] ?? 0) ?></div>
            <div class="eap-logger-stat__label">Errors Today</div>
        </div>
    </div>

    <div class="eap-logger-stat eap-logger-stat--success">
        <div class="eap-logger-stat__icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="22" height="22">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
            </svg>
        </div>
        <div class="eap-logger-stat__content">
            <div class="eap-logger-stat__value"><?= $totalFiles ?></div>
            <div class="eap-logger-stat__label">Log Files</div>
        </div>
    </div>

    <div class="eap-logger-stat eap-logger-stat--warning">
        <div class="eap-logger-stat__icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="22" height="22">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
            </svg>
        </div>
        <div class="eap-logger-stat__content">
            <div class="eap-logger-stat__value"><?= $totalSizeFormatted ?></div>
            <div class="eap-logger-stat__label">Total Size</div>
        </div>
    </div>
</div>

<!-- Channels Section -->
<div class="eap-logger-section">
    <div>
        <span class="eap-logger-section__title">Log Channels</span>
        <span class="eap-logger-section__subtitle"><?= count($channels) ?> configured</span>
    </div>
</div>

<?php if (empty($channels)): ?>
<div class="eap-card">
    <div class="eap-card__body eap-text-center">
        <p class="eap-text-muted">No channels configured. Add channels in the database.</p>
    </div>
</div>
<?php else: ?>
<div class="eap-logger-channels">
    <?php foreach ($channels as $channel):
        $channelName = $channel['channel'] ?? '';
        $minLevel = $channel['min_level'] ?? 'debug';
        $isEnabled = (bool) ($channel['enabled'] ?? true);
        $autoResetEnabled = (bool) ($channel['auto_reset_enabled'] ?? false);
        $logCount = $channel['log_count'] ?? 0;
        $hasAutoReset = !empty($channel['auto_reset_at']);
        $autoResetTime = $hasAutoReset ? strtotime($channel['auto_reset_at']) : null;
        $timeRemaining = $autoResetTime ? max(0, $autoResetTime - time()) : 0;
        $hoursRemaining = $timeRemaining > 0 ? ceil($timeRemaining / 3600) : 0;

        // Determine channel type for color coding
        $channelType = 'default';
        $channelLower = strtolower($channelName);
        if (str_contains($channelLower, 'security') || str_contains($channelLower, 'audit') || str_contains($channelLower, 'auth')) {
            $channelType = 'security';
        } elseif (str_contains($channelLower, 'api') || str_contains($channelLower, 'request') || str_contains($channelLower, 'http')) {
            $channelType = 'api';
        } elseif (str_contains($channelLower, 'perf') || str_contains($channelLower, 'metric') || str_contains($channelLower, 'slow')) {
            $channelType = 'performance';
        } elseif (str_contains($channelLower, 'db') || str_contains($channelLower, 'database') || str_contains($channelLower, 'query')) {
            $channelType = 'database';
        } elseif (str_contains($channelLower, 'mail') || str_contains($channelLower, 'email') || str_contains($channelLower, 'notification')) {
            $channelType = 'notification';
        } elseif (str_contains($channelLower, 'payment') || str_contains($channelLower, 'billing') || str_contains($channelLower, 'transaction')) {
            $channelType = 'payment';
        }
    ?>
    <div class="eap-logger-channel eap-logger-channel--<?= $channelType ?> <?= !$isEnabled ? 'eap-logger-channel--disabled' : '' ?>"
         data-channel="<?= htmlspecialchars($channelName) ?>">
        <div class="eap-logger-channel__header">
            <span class="eap-logger-channel__name eap-logger-channel__name--<?= $channelType ?>"><?= htmlspecialchars($channelName) ?></span>
            <label class="eap-logger-toggle">
                <input type="checkbox"
                       class="eap-logger-toggle__input channel-toggle"
                       data-channel="<?= htmlspecialchars($channelName) ?>"
                       <?= $isEnabled ? 'checked' : '' ?>>
                <span class="eap-logger-toggle__slider"></span>
            </label>
        </div>

        <div class="eap-logger-channel__body">
            <!-- Logger usage example -->
            <div class="eap-logger-channel__usage">
                <div class="eap-logger-channel__usage-title">Usage:</div>
                <code class="eap-logger-channel__code">$logger-><?= htmlspecialchars($channelName) ?>('message', ['context' => $data]);</code>
                <div class="eap-logger-channel__usage-alt">
                    <span class="eap-logger-channel__usage-label">or check:</span>
                    <code class="eap-logger-channel__code-inline">if (should_log('<?= htmlspecialchars($channelName) ?>', 'error')) { ... }</code>
                </div>
            </div>

            <div class="eap-logger-channel__row">
                <span class="eap-logger-channel__label">Min Level</span>
                <select class="eap-logger-level-select channel-level"
                        data-channel="<?= htmlspecialchars($channelName) ?>">
                    <?php foreach (['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'] as $level): ?>
                    <option value="<?= $level ?>" <?= $minLevel === $level ? 'selected' : '' ?>>
                        <?= ucfirst($level) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="eap-logger-channel__row">
                <span class="eap-logger-channel__label">Auto-reset (8h)</span>
                <label class="eap-logger-toggle">
                    <input type="checkbox"
                           class="eap-logger-toggle__input channel-auto-reset"
                           data-channel="<?= htmlspecialchars($channelName) ?>"
                           <?= $autoResetEnabled ? 'checked' : '' ?>>
                    <span class="eap-logger-toggle__slider"></span>
                </label>
            </div>

            <?php if ($hasAutoReset && $hoursRemaining > 0): ?>
            <div class="eap-logger-channel__row">
                <span class="eap-logger-timer">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Resets in <?= $hoursRemaining ?>h
                </span>
            </div>
            <?php endif; ?>
        </div>

        <div class="eap-logger-channel__footer">
            <span class="eap-logger-level eap-logger-level--<?= $minLevel ?>">
                <?= strtoupper($minLevel) ?>
            </span>
            <div class="eap-logger-channel__stats">
                <span class="eap-logger-channel__stat"><?= number_format($logCount) ?> logs</span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Log Files Section -->
<?php if (!empty($log_files)): ?>
<div class="eap-logger-section">
    <div>
        <span class="eap-logger-section__title">Log Files</span>
        <span class="eap-logger-section__subtitle"><?= $totalFiles ?> files, <?= $totalSizeFormatted ?></span>
    </div>
</div>

<!-- Bulk Actions Bar -->
<div class="eap-logger-bulk" id="bulk-actions">
    <span class="eap-logger-bulk__count"><span id="selected-count">0</span> selected</span>
    <div class="eap-logger-bulk__actions">
        <button type="button" class="eap-btn eap-btn--ghost eap-btn--sm" id="bulk-download">
            Download Selected
        </button>
        <button type="button" class="eap-btn eap-btn--danger eap-btn--sm" id="bulk-delete">
            Delete Selected
        </button>
    </div>
</div>

<div class="eap-card">
    <div class="eap-card__body eap-card__body--no-padding">
        <table class="eap-logger-files__table">
            <thead>
                <tr>
                    <th class="eap-logger-files__th-checkbox">
                        <input type="checkbox" class="eap-logger-files__checkbox" id="select-all">
                    </th>
                    <th>File</th>
                    <th>Size</th>
                    <th>Modified</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($log_files as $file):
                    $fileName = $file['name'] ?? '';
                    $fileSize = $file['size'] ?? 0;
                    $fileModified = $file['modified'] ?? 0;

                    if ($fileSize >= 1048576) {
                        $sizeFormatted = number_format($fileSize / 1048576, 2) . ' MB';
                    } elseif ($fileSize >= 1024) {
                        $sizeFormatted = number_format($fileSize / 1024, 2) . ' KB';
                    } else {
                        $sizeFormatted = $fileSize . ' B';
                    }
                    $modifiedFormatted = $fileModified ? date('Y-m-d H:i', $fileModified) : '-';
                ?>
                <tr data-file="<?= htmlspecialchars($fileName) ?>">
                    <td>
                        <input type="checkbox" class="eap-logger-files__checkbox file-checkbox"
                               value="<?= htmlspecialchars($fileName) ?>">
                    </td>
                    <td>
                        <span class="eap-logger-files__name"><?= htmlspecialchars($fileName) ?></span>
                    </td>
                    <td>
                        <span class="eap-logger-files__size"><?= $sizeFormatted ?></span>
                    </td>
                    <td>
                        <span class="eap-logger-files__date"><?= $modifiedFormatted ?></span>
                    </td>
                    <td>
                        <div class="eap-logger-files__row-actions">
                            <a href="<?= $admin_base_path ?>/logger/file?name=<?= urlencode($fileName) ?>"
                               class="eap-btn eap-btn--ghost eap-btn--xs">View</a>
                            <a href="<?= $admin_base_path ?>/logger/file/download?name=<?= urlencode($fileName) ?>"
                               class="eap-btn eap-btn--ghost eap-btn--xs">Download</a>
                            <button type="button" class="eap-btn eap-btn--ghost eap-btn--xs eap-btn--danger delete-file"
                                    data-file="<?= htmlspecialchars($fileName) ?>">Delete</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Telegram Section -->
<div class="eap-logger-section">
    <span class="eap-logger-section__title">Telegram Notifications</span>
    <a href="<?= $admin_base_path ?>/logger/telegram" class="eap-btn eap-btn--ghost eap-btn--sm">Configure</a>
</div>

<div class="eap-card">
    <div class="eap-logger-telegram">
        <?php if ($telegram_config['enabled'] ?? false): ?>
        <div class="eap-logger-telegram__icon eap-logger-telegram__icon--active">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
        </div>
        <div class="eap-logger-telegram__content">
            <div class="eap-logger-telegram__status">Active</div>
            <div class="eap-logger-telegram__detail">
                Min level: <?= ucfirst($telegram_config['min_level'] ?? 'error') ?> |
                Rate: <?= $telegram_config['rate_limit_per_minute'] ?? 10 ?>/min
            </div>
        </div>
        <?php else: ?>
        <div class="eap-logger-telegram__icon eap-logger-telegram__icon--inactive">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/>
            </svg>
        </div>
        <div class="eap-logger-telegram__content">
            <div class="eap-logger-telegram__status">Disabled</div>
            <div class="eap-logger-telegram__detail">Configure to receive alerts in Telegram</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="/js/logger-index.js"></script>
