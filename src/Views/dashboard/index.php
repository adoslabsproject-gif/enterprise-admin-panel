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
