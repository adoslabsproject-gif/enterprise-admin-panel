<?php
/**
 * Goodbye Page - Shown after logout
 *
 * Security features:
 * - Prevents back button navigation via Cache-Control headers
 * - Clears history state
 * - Auto-redirects to login after delay
 *
 * @var string $login_url URL to login page
 * @var string $user_name User's name (optional)
 */

$login_url = $login_url ?? '';
$user_name = $user_name ?? 'Master';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <!-- Prevent caching - no back button access -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Arrivederci - Enterprise Admin</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="stylesheet" href="/css/goodbye.css">
</head>
<body class="eap-goodbye">
    <!-- Animated stars background -->
    <div class="eap-goodbye__stars" id="eap-stars"></div>

    <div class="eap-goodbye__container">
        <div class="eap-goodbye__icon">
            <span class="eap-goodbye__wave">👋</span>
        </div>

        <div class="eap-goodbye__card">
            <h1 class="eap-goodbye__title">
                Arrivederci, <span class="eap-goodbye__name"><?= htmlspecialchars($user_name) ?></span>!
            </h1>

            <p class="eap-goodbye__message">
                La tua sessione è stata chiusa in modo sicuro.<br>
                L'URL di accesso è stato rigenerato per sicurezza.
            </p>

            <div class="eap-goodbye__status">
                <span class="eap-goodbye__status-icon">✓</span>
                <span class="eap-goodbye__status-text">Logout completato • URL rigenerato</span>
            </div>

            <div class="eap-goodbye__divider"></div>

            <div class="eap-goodbye__cli-notice">
                <div class="eap-goodbye__cli-icon">&#128274;</div>
                <p class="eap-goodbye__cli-title">Accesso protetto</p>
                <p class="eap-goodbye__cli-text">
                    Per motivi di sicurezza, l'URL di accesso è stato rigenerato.<br>
                    Contatta l'amministratore di sistema per il nuovo link.
                </p>
            </div>

            <div class="eap-goodbye__footer">
                Sessione terminata in modo sicuro
            </div>
        </div>
    </div>

    <script src="/js/goodbye.js"></script>
</body>
</html>
