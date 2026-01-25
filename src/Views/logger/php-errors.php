<?php
/**
 * PHP Error Log Viewer
 *
 * View PHP error log file
 *
 * @var string $error_log_path Path to PHP error log
 * @var array $errors Parsed error log entries
 * @var bool $error_log_exists Whether the error log file exists
 * @var string $admin_base_path Admin base path
 * @var string $csrf_input CSRF hidden input
 */
?>
<link rel="stylesheet" href="/css/logger-php-errors.css">

<!-- Page Header -->
<div class="eap-page-header">
    <div class="eap-page-header__content">
        <a href="<?= $admin_base_path ?>/logger" class="eap-btn eap-btn--ghost eap-btn--sm">
            &larr; Back to Logger
        </a>
        <h1 class="eap-page-title">PHP Error Log</h1>
        <p class="eap-page-subtitle">View PHP errors and warnings</p>
    </div>
    <div class="eap-page-header__actions">
        <?php if ($error_log_exists): ?>
            <button type="button" class="eap-btn eap-btn--secondary" onclick="refreshErrors()">
                Refresh
            </button>
            <button type="button" class="eap-btn eap-btn--danger" onclick="showClearModal()">
                Clear Log
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Error Log Path Info -->
<div class="eap-notice eap-notice--info">
    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div>
        <strong>Log file:</strong> <code><?= htmlspecialchars($error_log_path) ?></code>
        <?php if (!$error_log_exists): ?>
            <br><span class="eap-text-warning">File does not exist or is not readable.</span>
        <?php endif; ?>
    </div>
</div>

<?php if ($error_log_exists): ?>
    <!-- Stats -->
    <div class="eap-grid eap-grid--cols-4 eap-mb-4">
        <?php
        $errorCount = 0;
        $warningCount = 0;
        $noticeCount = 0;
        $otherCount = 0;

        foreach ($errors as $error) {
            $type = strtolower($error['type'] ?? '');
            if (str_contains($type, 'error') || str_contains($type, 'fatal')) {
                $errorCount++;
            } elseif (str_contains($type, 'warning')) {
                $warningCount++;
            } elseif (str_contains($type, 'notice') || str_contains($type, 'deprecated')) {
                $noticeCount++;
            } else {
                $otherCount++;
            }
        }
        ?>
        <div class="eap-stat-card eap-stat-card--danger">
            <span class="eap-stat-card__value"><?= $errorCount ?></span>
            <span class="eap-stat-card__label">Errors</span>
        </div>
        <div class="eap-stat-card eap-stat-card--warning">
            <span class="eap-stat-card__value"><?= $warningCount ?></span>
            <span class="eap-stat-card__label">Warnings</span>
        </div>
        <div class="eap-stat-card eap-stat-card--info">
            <span class="eap-stat-card__value"><?= $noticeCount ?></span>
            <span class="eap-stat-card__label">Notices</span>
        </div>
        <div class="eap-stat-card eap-stat-card--secondary">
            <span class="eap-stat-card__value"><?= $otherCount ?></span>
            <span class="eap-stat-card__label">Other</span>
        </div>
    </div>

    <!-- Errors List -->
    <div class="eap-card">
        <div class="eap-card__header">
            <span class="eap-card__title">Recent Errors</span>
            <span class="eap-badge eap-badge--secondary"><?= count($errors) ?> entries</span>
        </div>
        <div class="eap-card__body eap-card__body--no-padding">
            <?php if (empty($errors)): ?>
                <div class="eap-empty-state">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="48" height="48">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p>No PHP errors in the log file.</p>
                </div>
            <?php else: ?>
                <div class="eap-error-list">
                    <?php foreach (array_reverse($errors) as $index => $error): ?>
                        <?php
                        $type = strtolower($error['type'] ?? 'unknown');
                        if (str_contains($type, 'error') || str_contains($type, 'fatal')) {
                            $typeClass = 'danger';
                        } elseif (str_contains($type, 'warning')) {
                            $typeClass = 'warning';
                        } elseif (str_contains($type, 'notice') || str_contains($type, 'deprecated')) {
                            $typeClass = 'info';
                        } else {
                            $typeClass = 'secondary';
                        }
                        ?>
                        <div class="eap-error-item eap-error-item--<?= $typeClass ?>">
                            <div class="eap-error-item__header">
                                <span class="eap-badge eap-badge--<?= $typeClass ?>">
                                    <?= htmlspecialchars($error['type'] ?? 'Unknown') ?>
                                </span>
                                <span class="eap-text-muted eap-text-sm">
                                    <?= htmlspecialchars($error['timestamp'] ?? '') ?>
                                </span>
                            </div>
                            <div class="eap-error-item__message">
                                <?= htmlspecialchars($error['message'] ?? '') ?>
                            </div>
                            <?php if (!empty($error['file'])): ?>
                                <div class="eap-error-item__location">
                                    <code><?= htmlspecialchars($error['file']) ?></code>
                                    <?php if (!empty($error['line'])): ?>
                                        <span>line <?= htmlspecialchars($error['line']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($error['stack_trace'])): ?>
                                <details class="eap-error-item__trace">
                                    <summary>Stack Trace</summary>
                                    <pre><?= htmlspecialchars($error['stack_trace']) ?></pre>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <!-- Error Log Not Found -->
    <div class="eap-card">
        <div class="eap-card__body">
            <div class="eap-empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="48" height="48">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M12 12h.01M12 12h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <h3>Error Log Not Found</h3>
                <p>The PHP error log file could not be found or read.</p>
                <p class="eap-text-muted">
                    Check that <code>error_log</code> is configured in your php.ini
                    and the file is readable by the web server.
                </p>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Clear Log Modal -->
<div id="clear-modal" class="eap-modal eap-hidden">
    <div class="eap-modal__backdrop" onclick="closeClearModal()"></div>
    <div class="eap-modal__content eap-modal__content--sm">
        <div class="eap-modal__header">
            <h3 class="eap-modal__title">Clear PHP Error Log</h3>
            <button type="button" class="eap-modal__close" onclick="closeClearModal()">&times;</button>
        </div>
        <form method="POST" action="<?= $admin_base_path ?>/logger/php-errors/clear">
            <?= $csrf_input ?>

            <div class="eap-modal__body">
                <div class="eap-notice eap-notice--warning">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <span>This will permanently delete all entries in the PHP error log!</span>
                </div>
            </div>

            <div class="eap-modal__footer">
                <button type="button" class="eap-btn eap-btn--secondary" onclick="closeClearModal()">
                    Cancel
                </button>
                <button type="submit" class="eap-btn eap-btn--danger">
                    Clear Log
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function refreshErrors() {
    window.location.reload();
}

function showClearModal() {
    document.getElementById('clear-modal').classList.remove('eap-hidden');
}

function closeClearModal() {
    document.getElementById('clear-modal').classList.add('eap-hidden');
}
</script>
