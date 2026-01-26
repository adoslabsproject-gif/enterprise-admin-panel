<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?= htmlspecialchars($page_title ?? 'Admin Panel') ?> - Enterprise Admin</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="stylesheet" href="/css/admin.css">
    <?php if (!empty($extra_styles)) : ?>
        <?php foreach ($extra_styles as $style) : ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($style) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <?= $csrf_meta ?? '' ?>
</head>
<body>
    <div class="eap-layout">
        <aside class="eap-sidebar">
            <div class="eap-sidebar__header">
                <div class="eap-sidebar__logo">E</div>
                <span class="eap-sidebar__title">admin_panel</span>
            </div>
            <nav class="eap-sidebar__nav">
                <a href="<?= htmlspecialchars(($admin_base_path ?? '/admin') . '/dashboard') ?>" class="eap-sidebar__link <?= ($page_title ?? '') === 'Dashboard' ? 'eap-sidebar__link--active' : '' ?>">
                    <svg class="eap-sidebar__link-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    <span class="eap-sidebar__link-text">dashboard</span>
                </a>

                <?php if (!empty($tabs)) : ?>
                    <?php
                    $currentModule = null;
                    foreach ($tabs as $tab) :
                        $module = $tab['module'] ?? 'default';
                        if ($module !== $currentModule) :
                            $currentModule = $module;
                            ?>
                        <div class="eap-sidebar__section"># <?= htmlspecialchars(strtolower(str_replace(['adoslabs/', '-'], ['', '_'], $module))) ?></div>
                        <?php endif; ?>
                    <?php
                        // Transform module URLs from /admin/... to dynamic base path
                        $tabUrl = $tab['url'];
                        if (str_starts_with($tabUrl, '/admin/')) {
                            $tabUrl = $admin_base_path . '/' . substr($tabUrl, 7);
                        } elseif ($tabUrl === '/admin') {
                            $tabUrl = $admin_base_path;
                        }
                    ?>
                    <a href="<?= htmlspecialchars($tabUrl) ?>" class="eap-sidebar__link">
                        <svg class="eap-sidebar__link-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <?php
                            $iconPaths = [
                                'shield' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
                                'database' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>',
                                'file-text' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
                                'activity' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>',
                                'default' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>',
                            ];
                            echo $iconPaths[$tab['icon'] ?? 'default'] ?? $iconPaths['default'];
                            ?>
                        </svg>
                        <span class="eap-sidebar__link-text"><?= htmlspecialchars(strtolower($tab['label'])) ?></span>
                        <?php if (!empty($tab['badge'])) : ?>
                            <span class="eap-sidebar__link-badge"><?= htmlspecialchars($tab['badge']) ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </nav>
            <div class="eap-sidebar__footer">
                <div class="eap-sidebar__footer-line">
                    <span class="eap-sidebar__status-dot"></span>
                    <span>system: online</span>
                </div>
            </div>
        </aside>

        <main class="eap-main">
            <header class="eap-topbar">
                <div class="eap-topbar__left">
                    <span class="eap-topbar__path">
                        ~<span class="eap-topbar__path-separator">/</span>admin<span class="eap-topbar__path-separator">/</span>
                    </span>
                    <span class="eap-topbar__title"><?= htmlspecialchars(strtolower($page_title ?? 'dashboard')) ?></span>
                </div>
                <div class="eap-topbar__right">
                    <?php if (!empty($user)) : ?>
                        <div class="eap-user">
                            <div class="eap-user__info">
                                <div class="eap-user__name"><?= htmlspecialchars($user['name'] ?? $user['email']) ?></div>
                                <div class="eap-user__role"><?= htmlspecialchars($user['role'] ?? 'admin') ?></div>
                            </div>
                            <div class="eap-user__avatar">
                                <?= strtoupper(substr($user['name'] ?? $user['email'], 0, 1)) ?>
                            </div>
                        </div>
                        <form action="<?= htmlspecialchars(($admin_base_path ?? '/admin') . '/logout') ?>" method="POST" class="eap-form--inline">
                            <?= $csrf_input ?? '' ?>
                            <button type="submit" class="eap-btn eap-btn--secondary eap-btn--sm">exit</button>
                        </form>
                    <?php endif; ?>
                </div>
            </header>

            <div class="eap-content">
                <?= $content ?? '' ?>
            </div>
        </main>
    </div>

    <script src="/js/admin.js"></script>
    <script src="/js/session-guard.js"></script>
    <?php if (!empty($extra_scripts)) : ?>
        <?php foreach ($extra_scripts as $script) : ?>
    <script src="<?= htmlspecialchars($script) ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
