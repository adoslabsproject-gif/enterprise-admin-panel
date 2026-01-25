<?php
/**
 * Emergency Recovery Form
 *
 * Allows master admin to bypass 2FA using a one-time recovery token.
 * Token must be generated via CLI: php elf/token-generate.php
 *
 * @var string|null $error Error message
 * @var string $form_action Form submission URL
 * @var string $admin_base_path Admin panel base path
 * @var string $login_url Login page URL
 */

$error = $error ?? null;
$form_action = $form_action ?? '';
$admin_base_path = $admin_base_path ?? '';
$login_url = $login_url ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Emergency Recovery - Admin Panel</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="stylesheet" href="/css/recovery.css">
</head>
<body class="eap-recovery">
    <div class="eap-recovery__container">
        <div class="eap-recovery__warning">
            <span class="eap-recovery__warning-icon">⚠️</span>
            <span class="eap-recovery__warning-text">
                Emergency Recovery Mode - Master Admin Only
            </span>
        </div>

        <div class="eap-recovery__card">
            <div class="eap-recovery__header">
                <div class="eap-recovery__logo">
                    <span class="eap-recovery__logo-icon">🔓</span>
                </div>
                <h1 class="eap-recovery__title">Emergency Recovery</h1>
                <p class="eap-recovery__subtitle">
                    Enter the recovery token sent to your registered notification channel.
                    This will bypass 2FA authentication.
                </p>
            </div>

            <?php if ($error) : ?>
                <div class="eap-recovery__error">
                    <span>⚠️</span>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form class="eap-recovery__form" method="POST" action="<?= htmlspecialchars($form_action) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                <div class="eap-recovery__field">
                    <label class="eap-recovery__label" for="email">Master Admin Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="eap-recovery__input"
                        placeholder="admin@example.com"
                        required
                        autocomplete="email"
                    >
                </div>

                <div class="eap-recovery__field">
                    <label class="eap-recovery__label" for="token">Recovery Token</label>
                    <input
                        type="text"
                        id="token"
                        name="token"
                        class="eap-recovery__input eap-recovery__input--token"
                        placeholder="Enter your recovery token"
                        required
                        autocomplete="off"
                        minlength="32"
                        maxlength="64"
                    >
                    <p class="eap-recovery__hint">
                        Token is case-sensitive and expires after 24 hours
                    </p>
                </div>

                <button type="submit" class="eap-recovery__submit">
                    Access Dashboard
                </button>
            </form>

            <div class="eap-recovery__footer">
                <a href="<?= htmlspecialchars($login_url) ?>" class="eap-recovery__link">
                    ← Back to Login
                </a>
            </div>

            <div class="eap-recovery__info">
                <h2 class="eap-recovery__info-title">How Recovery Works</h2>
                <ul class="eap-recovery__info-list">
                    <li class="eap-recovery__info-item">
                        <span class="eap-recovery__info-icon">🔑</span>
                        <span>Token generated via CLI command by system administrator</span>
                    </li>
                    <li class="eap-recovery__info-item">
                        <span class="eap-recovery__info-icon">📧</span>
                        <span>Sent to your registered email, Telegram, Discord, or Slack</span>
                    </li>
                    <li class="eap-recovery__info-item">
                        <span class="eap-recovery__info-icon">⏱️</span>
                        <span>Token is one-time use and expires after 24 hours</span>
                    </li>
                    <li class="eap-recovery__info-item">
                        <span class="eap-recovery__info-icon">🔒</span>
                        <span>Only master admin can use emergency recovery</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <script>
        // Auto-focus on email field
        document.getElementById('email').focus();
    </script>
</body>
</html>
