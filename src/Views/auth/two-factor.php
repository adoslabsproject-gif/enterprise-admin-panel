<?php
/**
 * Two-Factor Authentication Page
 *
 * @var string|null $error Error message
 * @var string $form_action Form submission URL
 * @var string $return_url Return URL after verification
 * @var string $admin_base_path Admin panel base path
 * @var string $csrf_input CSRF hidden input
 * @var string $tfa_method 2FA method (email, telegram, discord, slack, totp)
 */

$error = $error ?? null;
$form_action = $form_action ?? '';
$return_url = $return_url ?? '';
$admin_base_path = $admin_base_path ?? '';
$csrf_input = $csrf_input ?? '';
$twoFaMethod = $tfa_method ?? 'email';

$methodLabel = match ($twoFaMethod) {
    'email' => 'We sent a verification code to your email',
    'telegram' => 'We sent a verification code to your Telegram',
    'discord' => 'We sent a verification code to your Discord',
    'slack' => 'We sent a verification code to your Slack',
    'totp' => 'Enter the code from your authenticator app',
    default => 'Enter your verification code',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Prevent caching - no back button access to 2FA page -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Two-Factor Authentication - Enterprise Admin</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="stylesheet" href="/css/two-factor.css">
</head>
<body class="eap-2fa">
    <div class="eap-2fa__container">
        <div class="eap-2fa__header">
            <div class="eap-2fa__icon">
                <svg class="eap-2fa__icon-svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
            </div>
            <h1 class="eap-2fa__title">Two-Factor Authentication</h1>
            <p class="eap-2fa__subtitle"><?= htmlspecialchars($methodLabel) ?></p>
        </div>

        <?php if (!empty($error)) : ?>
            <div class="eap-2fa__alert eap-2fa__alert--danger">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form action="<?= htmlspecialchars($form_action) ?>" method="POST">
            <?= $csrf_input ?>
            <input type="hidden" name="return_url" value="<?= htmlspecialchars($return_url) ?>">

            <div class="eap-2fa__form-group">
                <label class="eap-2fa__label" for="code">Authentication Code</label>
                <input
                    type="text"
                    id="code"
                    name="code"
                    class="eap-2fa__input"
                    placeholder="000000"
                    maxlength="17"
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    pattern="[0-9A-Za-z\-]*"
                    required
                    autofocus
                >
            </div>

            <button type="submit" class="eap-2fa__btn eap-2fa__btn--primary">Verify Code</button>
        </form>

        <div class="eap-2fa__help">
            <p>Lost access to your authenticator?</p>
            <p><button type="button" class="eap-2fa__help-link" id="eap-show-recovery-form">Use a recovery code</button></p>
        </div>

        <form id="eap-recovery-form" class="eap-2fa__recovery-form" action="<?= htmlspecialchars($form_action) ?>" method="POST">
            <?= $csrf_input ?>
            <input type="hidden" name="return_url" value="<?= htmlspecialchars($return_url) ?>">

            <div class="eap-2fa__form-group">
                <label class="eap-2fa__label" for="recovery-code">Recovery Code</label>
                <input
                    type="text"
                    id="recovery-code"
                    name="code"
                    class="eap-2fa__input eap-2fa__input--recovery"
                    placeholder="XXXX-XXXX"
                >
            </div>

            <button type="submit" class="eap-2fa__btn eap-2fa__btn--secondary">Use Recovery Code</button>
        </form>

        <div class="eap-2fa__back">
            <a href="<?= htmlspecialchars($admin_base_path . '/login') ?>" class="eap-2fa__back-link">Back to login</a>
        </div>
    </div>

    <script src="/js/two-factor.js"></script>
</body>
</html>
