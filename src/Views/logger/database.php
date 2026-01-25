<?php
/**
 * Database Logs Viewer
 *
 * View and search logs stored in the database
 *
 * @var array $logs Log entries
 * @var array $channels Available channels for filter
 * @var array $log_levels Available log levels
 * @var array $filters Current filter values
 * @var int $total_count Total log count
 * @var int $page Current page
 * @var int $per_page Items per page
 * @var string $admin_base_path Admin base path
 * @var string $csrf_input CSRF hidden input
 */

$total_pages = ceil($total_count / $per_page);
?>
<link rel="stylesheet" href="/css/logger-database.css">

<!-- Page Header -->
<div class="eap-page-header">
    <div class="eap-page-header__content">
        <a href="<?= $admin_base_path ?>/logger" class="eap-btn eap-btn--ghost eap-btn--sm">
            &larr; Back to Logger
        </a>
        <h1 class="eap-page-title">Database Logs</h1>
        <p class="eap-page-subtitle">View and search logs stored in database</p>
    </div>
    <div class="eap-page-header__actions">
        <button type="button" class="eap-btn eap-btn--danger" onclick="showClearModal()">
            Clear Old Logs
        </button>
    </div>
</div>

<!-- Filters -->
<div class="eap-card">
    <div class="eap-card__body">
        <form method="GET" action="<?= $admin_base_path ?>/logger/database" class="eap-filters">
            <div class="eap-filters__row">
                <div class="eap-form__group eap-form__group--inline">
                    <label class="eap-form__label">Channel</label>
                    <select name="channel" class="eap-form__select">
                        <option value="">All Channels</option>
                        <?php foreach ($channels as $ch): ?>
                            <option value="<?= htmlspecialchars($ch['channel']) ?>"
                                    <?= ($filters['channel'] ?? '') === $ch['channel'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ch['channel']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="eap-form__group eap-form__group--inline">
                    <label class="eap-form__label">Min Level</label>
                    <select name="min_level" class="eap-form__select">
                        <option value="">All Levels</option>
                        <?php foreach ($log_levels as $level): ?>
                            <option value="<?= $level ?>"
                                    <?= ($filters['min_level'] ?? '') === $level ? 'selected' : '' ?>>
                                <?= ucfirst($level) ?>+
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="eap-form__group eap-form__group--inline">
                    <label class="eap-form__label">Search</label>
                    <input type="text" name="search" class="eap-form__input"
                           value="<?= htmlspecialchars($filters['search'] ?? '') ?>"
                           placeholder="Search message...">
                </div>

                <div class="eap-form__group eap-form__group--inline">
                    <label class="eap-form__label">From</label>
                    <input type="date" name="from" class="eap-form__input"
                           value="<?= htmlspecialchars($filters['from'] ?? '') ?>">
                </div>

                <div class="eap-form__group eap-form__group--inline">
                    <label class="eap-form__label">To</label>
                    <input type="date" name="to" class="eap-form__input"
                           value="<?= htmlspecialchars($filters['to'] ?? '') ?>">
                </div>

                <div class="eap-form__group eap-form__group--inline">
                    <button type="submit" class="eap-btn eap-btn--primary">Filter</button>
                    <a href="<?= $admin_base_path ?>/logger/database" class="eap-btn eap-btn--secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Results Info -->
<div class="eap-flex eap-justify-between eap-items-center eap-mb-4">
    <span class="eap-text-muted">
        Showing <?= number_format(count($logs)) ?> of <?= number_format($total_count) ?> logs
    </span>
    <div class="eap-pagination">
        <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>"
               class="eap-btn eap-btn--ghost eap-btn--sm">&larr; Previous</a>
        <?php endif; ?>

        <span class="eap-pagination__info">Page <?= $page ?> of <?= $total_pages ?></span>

        <?php if ($page < $total_pages): ?>
            <a href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>"
               class="eap-btn eap-btn--ghost eap-btn--sm">Next &rarr;</a>
        <?php endif; ?>
    </div>
</div>

<!-- Logs Table -->
<div class="eap-card">
    <div class="eap-card__body eap-card__body--no-padding">
        <table class="eap-table eap-table--logs">
            <thead>
                <tr>
                    <th width="160">Timestamp</th>
                    <th width="80">Level</th>
                    <th width="100">Channel</th>
                    <th>Message</th>
                    <th width="60">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" class="eap-table__empty">
                            No logs found matching your criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
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
                        $levelColor = $levelColors[$log['level']] ?? 'secondary';
                        ?>
                        <tr class="eap-log-row eap-log-row--<?= $levelColor ?>"
                            data-log-id="<?= $log['id'] ?>">
                            <td>
                                <span class="eap-text-mono eap-text-sm">
                                    <?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="eap-badge eap-badge--<?= $levelColor ?>">
                                    <?= strtoupper($log['level']) ?>
                                </span>
                            </td>
                            <td>
                                <code class="eap-code"><?= htmlspecialchars($log['channel']) ?></code>
                            </td>
                            <td class="eap-log-message">
                                <span class="eap-log-message__text">
                                    <?= htmlspecialchars(substr($log['message'], 0, 200)) ?>
                                    <?= strlen($log['message']) > 200 ? '...' : '' ?>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="eap-btn eap-btn--ghost eap-btn--sm"
                                        onclick="showLogDetail(<?= $log['id'] ?>)">
                                    View
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div class="eap-pagination eap-pagination--bottom">
    <?php if ($page > 1): ?>
        <a href="?<?= http_build_query(array_merge($filters, ['page' => 1])) ?>"
           class="eap-btn eap-btn--ghost eap-btn--sm">First</a>
        <a href="?<?= http_build_query(array_merge($filters, ['page' => $page - 1])) ?>"
           class="eap-btn eap-btn--ghost eap-btn--sm">&larr; Previous</a>
    <?php endif; ?>

    <span class="eap-pagination__info">Page <?= $page ?> of <?= $total_pages ?></span>

    <?php if ($page < $total_pages): ?>
        <a href="?<?= http_build_query(array_merge($filters, ['page' => $page + 1])) ?>"
           class="eap-btn eap-btn--ghost eap-btn--sm">Next &rarr;</a>
        <a href="?<?= http_build_query(array_merge($filters, ['page' => $total_pages])) ?>"
           class="eap-btn eap-btn--ghost eap-btn--sm">Last</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Log Detail Modal -->
<div id="log-detail-modal" class="eap-modal eap-hidden">
    <div class="eap-modal__backdrop" onclick="closeLogDetail()"></div>
    <div class="eap-modal__content eap-modal__content--lg">
        <div class="eap-modal__header">
            <h3 class="eap-modal__title">Log Details</h3>
            <button type="button" class="eap-modal__close" onclick="closeLogDetail()">&times;</button>
        </div>
        <div class="eap-modal__body" id="log-detail-content">
            Loading...
        </div>
    </div>
</div>

<!-- Clear Logs Modal -->
<div id="clear-modal" class="eap-modal eap-hidden">
    <div class="eap-modal__backdrop" onclick="closeClearModal()"></div>
    <div class="eap-modal__content eap-modal__content--sm">
        <div class="eap-modal__header">
            <h3 class="eap-modal__title">Clear Old Logs</h3>
            <button type="button" class="eap-modal__close" onclick="closeClearModal()">&times;</button>
        </div>
        <form method="POST" action="<?= $admin_base_path ?>/logger/database/clear">
            <?= $csrf_input ?>

            <div class="eap-modal__body">
                <div class="eap-form__group">
                    <label class="eap-form__label">Delete logs older than</label>
                    <select name="days" class="eap-form__select">
                        <option value="7">7 days</option>
                        <option value="14">14 days</option>
                        <option value="30" selected>30 days</option>
                        <option value="60">60 days</option>
                        <option value="90">90 days</option>
                    </select>
                </div>

                <div class="eap-notice eap-notice--warning">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <span>This action cannot be undone!</span>
                </div>
            </div>

            <div class="eap-modal__footer">
                <button type="button" class="eap-btn eap-btn--secondary" onclick="closeClearModal()">
                    Cancel
                </button>
                <button type="submit" class="eap-btn eap-btn--danger">
                    Delete Logs
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Store logs data for detail view
const logsData = <?= json_encode($logs) ?>;

function showLogDetail(logId) {
    const log = logsData.find(l => l.id == logId);
    if (!log) return;

    let contextHtml = '<p class="eap-text-muted">No context data</p>';
    if (log.context) {
        try {
            const context = typeof log.context === 'string' ? JSON.parse(log.context) : log.context;
            contextHtml = '<pre class="eap-pre">' + JSON.stringify(context, null, 2) + '</pre>';
        } catch (e) {
            contextHtml = '<pre class="eap-pre">' + log.context + '</pre>';
        }
    }

    let extraHtml = '';
    if (log.extra) {
        try {
            const extra = typeof log.extra === 'string' ? JSON.parse(log.extra) : log.extra;
            extraHtml = `
                <h4>Extra</h4>
                <pre class="eap-pre">${JSON.stringify(extra, null, 2)}</pre>
            `;
        } catch (e) {
            extraHtml = `<h4>Extra</h4><pre class="eap-pre">${log.extra}</pre>`;
        }
    }

    document.getElementById('log-detail-content').innerHTML = `
        <div class="eap-log-detail">
            <div class="eap-log-detail__meta">
                <div class="eap-log-detail__item">
                    <strong>Timestamp:</strong>
                    <span>${log.created_at}</span>
                </div>
                <div class="eap-log-detail__item">
                    <strong>Level:</strong>
                    <span class="eap-badge eap-badge--${getLevelColor(log.level)}">${log.level.toUpperCase()}</span>
                </div>
                <div class="eap-log-detail__item">
                    <strong>Channel:</strong>
                    <code class="eap-code">${escapeHtml(log.channel)}</code>
                </div>
            </div>

            <h4>Message</h4>
            <div class="eap-log-detail__message">${escapeHtml(log.message)}</div>

            <h4>Context</h4>
            ${contextHtml}

            ${extraHtml}
        </div>
    `;

    document.getElementById('log-detail-modal').classList.remove('eap-hidden');
}

function closeLogDetail() {
    document.getElementById('log-detail-modal').classList.add('eap-hidden');
}

function showClearModal() {
    document.getElementById('clear-modal').classList.remove('eap-hidden');
}

function closeClearModal() {
    document.getElementById('clear-modal').classList.add('eap-hidden');
}

function getLevelColor(level) {
    const colors = {
        debug: 'secondary',
        info: 'info',
        notice: 'info',
        warning: 'warning',
        error: 'danger',
        critical: 'danger',
        alert: 'danger',
        emergency: 'danger',
    };
    return colors[level] || 'secondary';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
