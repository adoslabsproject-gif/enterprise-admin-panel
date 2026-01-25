<div class="eap-stats">
    <div class="eap-stat">
        <div class="eap-stat__label">Total Users</div>
        <div class="eap-stat__value"><?= number_format($stats['users']['total'] ?? 0) ?></div>
    </div>
    <div class="eap-stat eap-stat--success">
        <div class="eap-stat__label">Active Sessions</div>
        <div class="eap-stat__value"><?= number_format($stats['sessions']['active'] ?? 0) ?></div>
    </div>
    <div class="eap-stat eap-stat--info">
        <div class="eap-stat__label">Logins Today</div>
        <div class="eap-stat__value"><?= number_format($stats['audit']['logins_today'] ?? 0) ?></div>
    </div>
    <div class="eap-stat eap-stat--danger">
        <div class="eap-stat__label">Failed Logins</div>
        <div class="eap-stat__value"><?= number_format($stats['audit']['failed_logins'] ?? 0) ?></div>
    </div>
</div>

<div class="eap-grid eap-grid--2-1">
    <div class="eap-card">
        <div class="eap-card__header">
            <span class="eap-card__title">Recent Activity</span>
            <div class="eap-card__actions">
                <a href="<?= htmlspecialchars(($admin_base_path ?? '/admin') . '/audit') ?>" class="eap-btn eap-btn--secondary eap-btn--sm">View All</a>
            </div>
        </div>
        <div class="eap-card__body eap-card__body--flush">
            <table class="eap-table">
                <thead class="eap-table__head">
                    <tr>
                        <th class="eap-table__th">Action</th>
                        <th class="eap-table__th">User</th>
                        <th class="eap-table__th">IP Address</th>
                        <th class="eap-table__th">Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_activity)) : ?>
                        <tr>
                            <td colspan="4" class="eap-table__empty">
                                No recent activity
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($recent_activity as $activity) : ?>
                            <tr class="eap-table__row">
                                <td class="eap-table__td">
                                    <span class="eap-badge eap-badge--<?= in_array($activity['action'], ['login_failed', 'account_lock']) ? 'danger' : 'info' ?>">
                                        <?= htmlspecialchars($activity['action']) ?>
                                    </span>
                                </td>
                                <td class="eap-table__td"><?= htmlspecialchars($activity['user_email'] ?? 'System') ?></td>
                                <td class="eap-table__td eap-text--muted eap-text--mono"><?= htmlspecialchars($activity['ip_address'] ?? '-') ?></td>
                                <td class="eap-table__td eap-text--muted eap-text--sm"><?= htmlspecialchars($activity['created_at'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="eap-card">
        <div class="eap-card__header">
            <span class="eap-card__title">Active Modules</span>
        </div>
        <div class="eap-card__body">
            <?php if (empty($modules)) : ?>
                <p class="eap-text--muted">No modules installed</p>
            <?php else : ?>
                <ul class="eap-list">
                    <?php foreach ($modules as $module) : ?>
                        <li class="eap-list__item">
                            <div class="eap-text--medium"><?= htmlspecialchars($module['name']) ?></div>
                            <div class="eap-text--sm eap-text--muted">
                                v<?= htmlspecialchars($module['version']) ?> &bull;
                                <?= $module['tabs'] ?> tabs
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Database Pool & Redis Metrics -->
<div class="eap-grid eap-grid--2 eap-mt-6">
    <!-- Database Pool -->
    <div class="eap-card">
        <div class="eap-card__header">
            <span class="eap-card__title">Database Pool</span>
            <span class="eap-badge eap-badge--<?= ($stats['database']['circuit_state'] ?? 'closed') === 'closed' ? 'success' : (($stats['database']['circuit_state'] ?? '') === 'open' ? 'danger' : 'warning') ?>">
                <?= htmlspecialchars(ucfirst($stats['database']['circuit_state'] ?? 'unknown')) ?>
            </span>
        </div>
        <div class="eap-card__body">
            <div class="eap-grid eap-grid--3">
                <div class="eap-info-item">
                    <div class="eap-info-item__label">Driver</div>
                    <div class="eap-info-item__value eap-text--mono"><?= htmlspecialchars(strtoupper($stats['database']['driver'] ?? 'pgsql')) ?></div>
                </div>
                <div class="eap-info-item">
                    <div class="eap-info-item__label">Connections</div>
                    <div class="eap-info-item__value">
                        <span class="eap-text--success"><?= $stats['database']['idle'] ?? 0 ?></span>
                        /
                        <span class="eap-text--warning"><?= $stats['database']['in_use'] ?? 0 ?></span>
                        /
                        <?= $stats['database']['connections'] ?? 0 ?>
                    </div>
                </div>
                <div class="eap-info-item">
                    <div class="eap-info-item__label">Queries</div>
                    <div class="eap-info-item__value"><?= number_format($stats['database']['queries'] ?? 0) ?></div>
                </div>
            </div>
            <div class="eap-divider eap-my-4"></div>
            <div class="eap-grid eap-grid--2">
                <div class="eap-info-item">
                    <div class="eap-info-item__label">Avg Query Time</div>
                    <div class="eap-info-item__value"><?= $stats['database']['avg_query_ms'] ?? 0 ?>ms</div>
                </div>
                <div class="eap-info-item">
                    <div class="eap-info-item__label">Circuit Breaker</div>
                    <div class="eap-info-item__value">
                        <?php if (($stats['database']['circuit_state'] ?? 'closed') === 'open'): ?>
                            <span class="eap-text--danger">OPEN - Requests blocked</span>
                        <?php elseif (($stats['database']['circuit_state'] ?? 'closed') === 'half_open'): ?>
                            <span class="eap-text--warning">HALF-OPEN - Testing</span>
                        <?php else: ?>
                            <span class="eap-text--success">CLOSED - Healthy</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="eap-card__footer">
            <button type="button" class="eap-btn eap-btn--secondary eap-btn--sm" onclick="refreshDbPoolMetrics()">
                Refresh
            </button>
            <a href="<?= htmlspecialchars(($admin_base_path ?? '/admin') . '/api/dbpool') ?>" target="_blank" class="eap-btn eap-btn--ghost eap-btn--sm">
                View JSON
            </a>
        </div>
    </div>

    <!-- Redis -->
    <div class="eap-card">
        <div class="eap-card__header">
            <span class="eap-card__title">Redis</span>
            <?php if ($stats['redis']['enabled'] ?? false): ?>
                <span class="eap-badge eap-badge--<?= ($stats['redis']['connected'] ?? false) ? 'success' : 'danger' ?>">
                    <?= ($stats['redis']['connected'] ?? false) ? 'Connected' : 'Disconnected' ?>
                </span>
            <?php else: ?>
                <span class="eap-badge eap-badge--muted">Disabled</span>
            <?php endif; ?>
        </div>
        <div class="eap-card__body">
            <?php if ($stats['redis']['enabled'] ?? false): ?>
                <?php if ($stats['redis']['connected'] ?? false): ?>
                    <div class="eap-grid eap-grid--3">
                        <div class="eap-info-item">
                            <div class="eap-info-item__label">Active Workers</div>
                            <div class="eap-info-item__value"><?= $stats['redis']['workers'] ?? 0 ?></div>
                        </div>
                        <div class="eap-info-item">
                            <div class="eap-info-item__label">Status</div>
                            <div class="eap-info-item__value eap-text--success">Healthy</div>
                        </div>
                        <div class="eap-info-item">
                            <div class="eap-info-item__label">Mode</div>
                            <div class="eap-info-item__value">Distributed</div>
                        </div>
                    </div>
                    <div class="eap-divider eap-my-4"></div>
                    <div id="redis-details" class="eap-text--muted eap-text--sm">
                        Loading Redis details...
                    </div>
                <?php else: ?>
                    <div class="eap-alert eap-alert--danger">
                        <strong>Redis Disconnected</strong>
                        <p class="eap-mt-2">Using local fallback mode. Circuit breaker state is not shared across workers.</p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="eap-alert eap-alert--info">
                    <strong>Redis Disabled</strong>
                    <p class="eap-mt-2">Enable Redis for distributed circuit breaker and metrics.</p>
                    <p class="eap-mt-2 eap-text--mono eap-text--sm">Set REDIS_HOST and REDIS_PORT in .env</p>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($stats['redis']['enabled'] ?? false): ?>
            <div class="eap-card__footer">
                <button type="button" class="eap-btn eap-btn--secondary eap-btn--sm" onclick="refreshRedisMetrics()">
                    Refresh
                </button>
                <a href="<?= htmlspecialchars(($admin_base_path ?? '/admin') . '/api/redis') ?>" target="_blank" class="eap-btn eap-btn--ghost eap-btn--sm">
                    View JSON
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- System Information -->
<div class="eap-card eap-mt-6">
    <div class="eap-card__header">
        <span class="eap-card__title">System Information</span>
    </div>
    <div class="eap-card__body">
        <div class="eap-grid eap-grid--4">
            <div class="eap-info-item">
                <div class="eap-info-item__label">PHP Version</div>
                <div class="eap-info-item__value"><?= htmlspecialchars($stats['system']['php_version'] ?? PHP_VERSION) ?></div>
            </div>
            <div class="eap-info-item">
                <div class="eap-info-item__label">Memory Usage</div>
                <div class="eap-info-item__value"><?= htmlspecialchars($stats['system']['memory_usage'] ?? 'N/A') ?></div>
            </div>
            <div class="eap-info-item">
                <div class="eap-info-item__label">Peak Memory</div>
                <div class="eap-info-item__value"><?= htmlspecialchars($stats['system']['memory_peak'] ?? 'N/A') ?></div>
            </div>
            <div class="eap-info-item">
                <div class="eap-info-item__label">Enabled Modules</div>
                <div class="eap-info-item__value"><?= $stats['modules']['enabled'] ?? 0 ?></div>
            </div>
        </div>
    </div>
</div>

<?php
// Dashboard configuration for JavaScript (passed via data attribute on body)
$dashboardConfig = [
    'apiBase' => ($admin_base_path ?? '/admin') . '/api',
    'redisEnabled' => $stats['redis']['enabled'] ?? false,
    'redisConnected' => $stats['redis']['connected'] ?? false,
];
?>
<div id="dashboard-config"
     data-api-base="<?= htmlspecialchars($dashboardConfig['apiBase']) ?>"
     data-redis-enabled="<?= $dashboardConfig['redisEnabled'] ? 'true' : 'false' ?>"
     data-redis-connected="<?= $dashboardConfig['redisConnected'] ? 'true' : 'false' ?>"
     style="display:none;"></div>
