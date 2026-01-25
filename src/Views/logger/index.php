<?php
/**
 * Logger Dashboard
 *
 * Overview of logging system: channels, stats, recent logs
 *
 * @var array $channels Channel configurations
 * @var array $stats Log statistics
 * @var array $recent_logs Recent log entries
 * @var array $log_files Available log files
 * @var array $telegram_config Telegram configuration
 * @var array $log_levels Available log levels
 * @var string $admin_base_path Admin base path
 * @var string $csrf_input CSRF hidden input
 */
?>
<link rel="stylesheet" href="/css/logger-index.css">
<?php
$levelColors = [
    'debug' => 'neutral',
    'info' => 'info',
    'notice' => 'info',
    'warning' => 'warning',
    'error' => 'danger',
    'critical' => 'danger',
    'alert' => 'danger',
    'emergency' => 'danger',
];
?>

<!-- Page Header -->
<div class="eap-page-header">
    <h1 class="eap-page-title">PSR-3 Logger</h1>
    <p class="eap-page-subtitle">Manage logging channels, view logs, and configure notifications</p>
</div>

<!-- Stats Row -->
<div class="eap-stats-row">
    <div class="eap-stat-card">
        <div class="eap-stat-card__icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
        </div>
        <div class="eap-stat-card__content">
            <div class="eap-stat-card__value"><?= number_format($stats['today'] ?? 0) ?></div>
            <div class="eap-stat-card__label">Logs Today</div>
        </div>
    </div>

    <div class="eap-stat-card eap-stat-card--danger">
        <div class="eap-stat-card__icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>
        <div class="eap-stat-card__content">
            <div class="eap-stat-card__value"><?= number_format($stats['errors_today'] ?? 0) ?></div>
            <div class="eap-stat-card__label">Errors Today</div>
        </div>
    </div>

    <div class="eap-stat-card">
        <div class="eap-stat-card__icon eap-stat-card__icon--info">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
            </svg>
        </div>
        <div class="eap-stat-card__content">
            <div class="eap-stat-card__value"><?= count($channels) ?></div>
            <div class="eap-stat-card__label">Active Channels</div>
        </div>
    </div>

    <div class="eap-stat-card">
        <div class="eap-stat-card__icon eap-stat-card__icon--success">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
            </svg>
        </div>
        <div class="eap-stat-card__content">
            <div class="eap-stat-card__value"><?= number_format($stats['total'] ?? 0) ?></div>
            <div class="eap-stat-card__label">Total Logs</div>
        </div>
    </div>
</div>

<!-- Quick Links -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">Quick Actions</span>
    </div>
    <div class="eap-card__body">
        <div class="eap-button-group">
            <a href="<?= $admin_base_path ?>/logger/channels" class="eap-btn eap-btn--primary">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                </svg>
                Configure Channels
            </a>
            <a href="<?= $admin_base_path ?>/logger/database" class="eap-btn eap-btn--secondary">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
                </svg>
                Database Logs
            </a>
            <a href="<?= $admin_base_path ?>/logger/telegram" class="eap-btn eap-btn--secondary">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                </svg>
                Telegram Alerts
                <?php if ($telegram_config['enabled'] ?? false): ?>
                    <span class="eap-badge eap-badge--success">ON</span>
                <?php endif; ?>
            </a>
            <a href="<?= $admin_base_path ?>/logger/php-errors" class="eap-btn eap-btn--ghost">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                </svg>
                PHP Error Log
            </a>
        </div>
    </div>
</div>

<!-- Channels Overview -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">Channels Overview</span>
        <a href="<?= $admin_base_path ?>/logger/channels" class="eap-btn eap-btn--ghost eap-btn--sm">
            Manage All
        </a>
    </div>
    <div class="eap-card__body eap-card__body--flush">
        <?php if (empty($channels)): ?>
            <div class="eap-table__empty">
                <p>No channels configured</p>
                <a href="<?= $admin_base_path ?>/logger/channels" class="eap-btn eap-btn--primary eap-btn--sm">
                    Add Channel
                </a>
            </div>
        <?php else: ?>
            <table class="eap-table">
                <thead class="eap-table__head">
                    <tr>
                        <th class="eap-table__th">Channel</th>
                        <th class="eap-table__th">Min Level</th>
                        <th class="eap-table__th">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($channels as $name => $config): ?>
                        <tr class="eap-table__row">
                            <td class="eap-table__td">
                                <code class="eap-code"><?= htmlspecialchars($name) ?></code>
                                <?php if (!empty($config['description'])): ?>
                                    <div class="eap-text--sm eap-text--muted"><?= htmlspecialchars($config['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="eap-table__td">
                                <span class="eap-level-badge eap-level-badge--<?= $levelColors[$config['min_level']] ?? 'neutral' ?>">
                                    <?= ucfirst($config['min_level']) ?>
                                </span>
                            </td>
                            <td class="eap-table__td">
                                <?php if ($config['enabled'] ?? true): ?>
                                    <span class="eap-badge eap-badge--success">Active</span>
                                <?php else: ?>
                                    <span class="eap-badge eap-badge--neutral">Disabled</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Logs -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">Recent Logs</span>
        <a href="<?= $admin_base_path ?>/logger/database" class="eap-btn eap-btn--ghost eap-btn--sm">
            View All
        </a>
    </div>
    <div class="eap-card__body eap-card__body--flush">
        <?php if (empty($recent_logs)): ?>
            <div class="eap-table__empty">
                <p>No logs recorded yet</p>
            </div>
        <?php else: ?>
            <table class="eap-table eap-table--logs">
                <thead class="eap-table__head">
                    <tr>
                        <th class="eap-table__th">Time</th>
                        <th class="eap-table__th">Level</th>
                        <th class="eap-table__th">Channel</th>
                        <th class="eap-table__th">Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_logs as $log): ?>
                        <tr class="eap-table__row eap-log-row eap-log-row--<?= strtolower($log['level']) ?>">
                            <td class="eap-table__td eap-text--mono eap-text--sm">
                                <?= date('H:i:s', strtotime($log['created_at'])) ?>
                            </td>
                            <td class="eap-table__td">
                                <span class="eap-level-badge eap-level-badge--<?= $levelColors[$log['level']] ?? 'neutral' ?>">
                                    <?= strtoupper($log['level']) ?>
                                </span>
                            </td>
                            <td class="eap-table__td">
                                <code class="eap-code"><?= htmlspecialchars($log['channel']) ?></code>
                            </td>
                            <td class="eap-table__td eap-log-message">
                                <?= htmlspecialchars(mb_substr($log['message'], 0, 100)) ?>
                                <?= mb_strlen($log['message']) > 100 ? '...' : '' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Log Files -->
<?php if (!empty($log_files)): ?>
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">Log Files</span>
    </div>
    <div class="eap-card__body eap-card__body--flush">
        <table class="eap-table">
            <thead class="eap-table__head">
                <tr>
                    <th class="eap-table__th">File</th>
                    <th class="eap-table__th">Size</th>
                    <th class="eap-table__th">Modified</th>
                    <th class="eap-table__th">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($log_files as $file): ?>
                    <tr class="eap-table__row">
                        <td class="eap-table__td">
                            <code class="eap-code"><?= htmlspecialchars($file['name']) ?></code>
                        </td>
                        <td class="eap-table__td eap-text--mono">
                            <?= number_format($file['size'] / 1024, 1) ?> KB
                        </td>
                        <td class="eap-table__td eap-text--muted">
                            <?= date('Y-m-d H:i', $file['modified']) ?>
                        </td>
                        <td class="eap-table__td">
                            <a href="<?= $admin_base_path ?>/logger/file?file=<?= urlencode($file['name']) ?>"
                               class="eap-btn eap-btn--ghost eap-btn--xs">View</a>
                            <a href="<?= $admin_base_path ?>/logger/file/download?file=<?= urlencode($file['name']) ?>"
                               class="eap-btn eap-btn--ghost eap-btn--xs">Download</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Integration Status -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">Integration Status</span>
    </div>
    <div class="eap-card__body">
        <div class="eap-integration-status">
            <div class="eap-integration-item">
                <div class="eap-integration-item__icon eap-integration-item__icon--success">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <div class="eap-integration-item__content">
                    <strong>enterprise-admin-panel</strong>
                    <span class="eap-text--muted">UI configuration enabled</span>
                </div>
            </div>

            <div class="eap-integration-item">
                <?php if (function_exists('should_log')): ?>
                    <div class="eap-integration-item__icon eap-integration-item__icon--success">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <div class="eap-integration-item__content">
                        <strong>should_log()</strong>
                        <span class="eap-text--muted">Intelligent filtering active</span>
                    </div>
                <?php else: ?>
                    <div class="eap-integration-item__icon eap-integration-item__icon--warning">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                    </div>
                    <div class="eap-integration-item__content">
                        <strong>should_log()</strong>
                        <span class="eap-text--warning">Not found - install enterprise-bootstrap</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="eap-integration-item">
                <?php if ($telegram_config['enabled'] ?? false): ?>
                    <div class="eap-integration-item__icon eap-integration-item__icon--success">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <div class="eap-integration-item__content">
                        <strong>Telegram Notifications</strong>
                        <span class="eap-text--muted">Enabled for <?= $telegram_config['min_level'] ?>+</span>
                    </div>
                <?php else: ?>
                    <div class="eap-integration-item__icon eap-integration-item__icon--neutral">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/>
                        </svg>
                    </div>
                    <div class="eap-integration-item__content">
                        <strong>Telegram Notifications</strong>
                        <span class="eap-text--muted">Not configured</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
