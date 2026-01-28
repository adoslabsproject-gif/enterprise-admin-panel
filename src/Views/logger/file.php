<?php
/**
 * Log File Viewer
 *
 * View individual log files from storage/logs
 *
 * @var string $file_name File name
 * @var string $content File content (last N lines)
 * @var int $lines Number of lines shown
 * @var string $file_size Formatted file size
 * @var string $file_modified Formatted modification date
 * @var string $admin_base_path Admin base path
 * @var string $csrf_input CSRF hidden input
 */
?>
<link rel="stylesheet" href="/css/logger-file.css">

<!-- Page Header -->
<div class="eap-page-header">
    <div class="eap-page-header__content">
        <a href="<?= $admin_base_path ?>/logger" class="eap-btn eap-btn--ghost eap-btn--sm">
            &larr; Back to Logger
        </a>
        <h1 class="eap-page-title"><?= htmlspecialchars($file_name) ?></h1>
        <p class="eap-page-subtitle">Log file viewer</p>
    </div>
    <div class="eap-page-header__actions">
        <a href="<?= $admin_base_path ?>/logger/file/download?name=<?= urlencode($file_name) ?>"
           class="eap-btn eap-btn--primary">
            Download File
        </a>
    </div>
</div>

<!-- File Info -->
<div class="eap-card eap-mb-4">
    <div class="eap-card__body">
        <div class="eap-log-file__info-grid">
            <div class="eap-log-file__info-item">
                <span class="eap-log-file__info-label">File Size</span>
                <div class="eap-log-file__info-value"><?= htmlspecialchars($file_size) ?></div>
            </div>
            <div class="eap-log-file__info-item">
                <span class="eap-log-file__info-label">Last Modified</span>
                <div class="eap-log-file__info-value"><?= htmlspecialchars($file_modified) ?></div>
            </div>
            <div class="eap-log-file__info-item">
                <span class="eap-log-file__info-label">Lines Shown</span>
                <div>
                    <form method="GET" action="<?= $admin_base_path ?>/logger/file" class="eap-log-file__lines-form">
                        <input type="hidden" name="name" value="<?= htmlspecialchars($file_name) ?>">
                        <select name="lines" class="eap-form__select eap-form__select--sm" onchange="this.form.submit()">
                            <option value="100" <?= $lines == 100 ? 'selected' : '' ?>>Last 100 lines</option>
                            <option value="200" <?= $lines == 200 ? 'selected' : '' ?>>Last 200 lines</option>
                            <option value="500" <?= $lines == 500 ? 'selected' : '' ?>>Last 500 lines</option>
                            <option value="1000" <?= $lines == 1000 ? 'selected' : '' ?>>Last 1000 lines</option>
                            <option value="2000" <?= $lines == 2000 ? 'selected' : '' ?>>Last 2000 lines</option>
                            <option value="5000" <?= $lines == 5000 ? 'selected' : '' ?>>Last 5000 lines</option>
                        </select>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Log Content -->
<div class="eap-card">
    <div class="eap-card__header">
        <span class="eap-card__title">Log Content</span>
        <div class="eap-card__actions">
            <button type="button" class="eap-btn eap-btn--ghost eap-btn--sm" id="scroll-bottom-btn">
                Scroll to Bottom
            </button>
            <button type="button" class="eap-btn eap-btn--ghost eap-btn--sm" id="scroll-top-btn">
                Scroll to Top
            </button>
        </div>
    </div>
    <div class="eap-card__body eap-card__body--no-padding">
        <?php if (empty($content)): ?>
            <div class="eap-log-file__empty">
                <svg class="eap-log-file__empty-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="48" height="48">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <p>Log file is empty.</p>
            </div>
        <?php else: ?>
            <pre class="eap-log-file__content" id="log-content"><?= htmlspecialchars($content) ?></pre>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var logContent = document.getElementById('log-content');
    var scrollBottomBtn = document.getElementById('scroll-bottom-btn');
    var scrollTopBtn = document.getElementById('scroll-top-btn');

    if (scrollBottomBtn && logContent) {
        scrollBottomBtn.addEventListener('click', function() {
            logContent.scrollTop = logContent.scrollHeight;
        });
    }

    if (scrollTopBtn && logContent) {
        scrollTopBtn.addEventListener('click', function() {
            logContent.scrollTop = 0;
        });
    }

    // Auto-scroll to bottom on load
    if (logContent) {
        logContent.scrollTop = logContent.scrollHeight;
    }
});
</script>
