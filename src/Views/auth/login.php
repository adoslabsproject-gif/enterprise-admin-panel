<?php
/**
 * Login Page
 *
 * @var string|null $error Error message
 * @var string $form_action Form submission URL
 * @var string $return_url Return URL after login
 * @var string $admin_base_path Admin panel base path
 * @var string $csrf_input CSRF hidden input
 */

$error = $error ?? null;
$form_action = $form_action ?? '';
$return_url = $return_url ?? '';
$admin_base_path = $admin_base_path ?? '';
$csrf_input = $csrf_input ?? '';
$recovery_url = $admin_base_path . '/recovery';
$emergency_recovery_enabled = $emergency_recovery_enabled ?? true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <!-- Prevent caching - no back button access to login page -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Login - Enterprise Admin</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="stylesheet" href="/css/login.css">
</head>
<body class="eap-login">
    <div class="eap-login__container">
        <div class="eap-login__header">
            <h1 class="eap-login__title">Enterprise Admin</h1>
            <p class="eap-login__subtitle">Sign in to your account</p>
        </div>

        <?php if (!empty($error)) : ?>
            <div class="eap-login__alert eap-login__alert--danger">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form class="eap-login__form" action="<?= htmlspecialchars($form_action) ?>" method="POST">
            <?= $csrf_input ?>
            <input type="hidden" name="return_url" value="<?= htmlspecialchars($return_url) ?>">

            <div class="eap-login__field">
                <label class="eap-login__label" for="email">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="eap-login__input"
                    placeholder="admin@example.com"
                    required
                    autofocus
                    autocomplete="email"
                >
            </div>

            <div class="eap-login__field">
                <label class="eap-login__label" for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="eap-login__input"
                    placeholder="Enter your password"
                    required
                    minlength="6"
                    autocomplete="current-password"
                >
            </div>

            <button type="submit" class="eap-login__submit">Sign In</button>
        </form>

        <?php if ($emergency_recovery_enabled) : ?>
        <div class="eap-login__divider">
            <span class="eap-login__divider-text">Master Admin Only</span>
        </div>

        <div class="eap-login__recovery">
            <button type="button" class="eap-login__recovery-link" id="eap-open-recovery">
                <span class="eap-login__recovery-icon">🔓</span>
                Emergency Recovery (Bypass 2FA)
            </button>
        </div>
        <?php endif; ?>

        <div class="eap-login__footer">
            <svg class="eap-login__footer-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            Secured with Argon2id hashing &amp; CSRF protection
        </div>
    </div>

    <?php if ($emergency_recovery_enabled) : ?>
    <!-- Emergency Recovery Modal -->
    <div class="eap-modal" id="eap-recovery-modal">
        <div class="eap-modal__content">
            <div class="eap-modal__header">
                <h2 class="eap-modal__title">
                    <span class="eap-modal__title-icon">🔓</span>
                    Emergency Recovery
                </h2>
                <button type="button" class="eap-modal__close" id="eap-close-recovery">&times;</button>
            </div>

            <form action="<?= htmlspecialchars($recovery_url) ?>" method="POST">
                <?= $csrf_input ?>

                <div class="eap-modal__body">
                    <div class="eap-modal__warning">
                        <span class="eap-modal__warning-icon">⚠️</span>
                        <div>
                            <strong>Master Admin Only</strong><br>
                            This feature allows bypassing 2FA using a recovery token generated via CLI.
                            Contact your system administrator if you need a recovery token.
                        </div>
                    </div>

                    <div class="eap-modal__form">
                        <div class="eap-modal__field">
                            <label class="eap-modal__label" for="recovery-email">Master Admin Email</label>
                            <input
                                type="email"
                                id="recovery-email"
                                name="email"
                                class="eap-modal__input"
                                placeholder="admin@example.com"
                                required
                                autocomplete="email"
                            >
                        </div>

                        <div class="eap-modal__field">
                            <label class="eap-modal__label" for="recovery-token">Recovery Token</label>
                            <input
                                type="text"
                                id="recovery-token"
                                name="token"
                                class="eap-modal__input eap-modal__input--token"
                                placeholder="recovery-XXXXXXXX-XXXXXXXX-..."
                                required
                                minlength="32"
                                autocomplete="off"
                            >
                            <p class="eap-modal__hint">
                                Token is case-sensitive and expires after 24 hours
                            </p>
                        </div>
                    </div>

                    <div class="eap-modal__info">
                        <h3 class="eap-modal__info-title">How to get a recovery token</h3>
                        <ul class="eap-modal__info-list">
                            <li class="eap-modal__info-item">
                                <span class="eap-modal__info-icon">1️⃣</span>
                                <span>Contact your system administrator</span>
                            </li>
                            <li class="eap-modal__info-item">
                                <span class="eap-modal__info-icon">2️⃣</span>
                                <span>They will run a CLI command to generate the token</span>
                            </li>
                            <li class="eap-modal__info-item">
                                <span class="eap-modal__info-icon">3️⃣</span>
                                <span>Token will be sent to your registered email/Telegram/Discord</span>
                            </li>
                            <li class="eap-modal__info-item">
                                <span class="eap-modal__info-icon">4️⃣</span>
                                <span>Enter the token above - it can only be used once</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="eap-modal__footer">
                    <button type="button" class="eap-modal__btn eap-modal__btn--cancel" id="eap-cancel-recovery">
                        Cancel
                    </button>
                    <button type="submit" class="eap-modal__btn eap-modal__btn--submit">
                        Access Dashboard
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php endif; ?>

    <script src="/js/login.js"></script>
</body>
</html>
