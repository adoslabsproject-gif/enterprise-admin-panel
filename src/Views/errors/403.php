<?php
/**
 * 403 Forbidden - "Vault Door"
 *
 * Concept: A massive bank vault door that's locked tight,
 * with a retinal scan animation that fails
 */

$home_url = $home_url ?? '/';
$reason = $reason ?? 'Access denied';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 - Access Denied</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="stylesheet" href="/css/error-403.css">
</head>
<body class="eap-error-403">
    <div class="eap-error-403__grid-bg"></div>
    <div class="eap-error-403__scan-line"></div>

    <div class="eap-error-403__container">
        <div class="eap-error-403__vault-container">
            <div class="eap-error-403__vault-door">
                <div class="eap-error-403__vault-handle">
                    <div class="eap-error-403__handle-spoke"></div>
                    <div class="eap-error-403__handle-spoke"></div>
                    <div class="eap-error-403__handle-spoke"></div>
                    <div class="eap-error-403__handle-spoke"></div>
                    <div class="eap-error-403__handle-spoke"></div>
                    <div class="eap-error-403__handle-spoke"></div>
                    <div class="eap-error-403__handle-spoke"></div>
                    <div class="eap-error-403__handle-spoke"></div>
                    <div class="eap-error-403__handle-center"></div>
                </div>
            </div>
            <div class="eap-error-403__lock-indicator">
                <div class="eap-error-403__lock-dot"></div>
                <span class="eap-error-403__lock-text">Locked</span>
            </div>
        </div>

        <div class="eap-error-403__code">403</div>
        <h1 class="eap-error-403__title">Access Denied</h1>
        <p class="eap-error-403__message">
            This area requires special clearance.<br>
            Your credentials don't have the necessary permissions.
        </p>

        <div class="eap-error-403__reason-box">
            <span class="eap-error-403__reason-label">Reason:</span> <?= htmlspecialchars($reason) ?>
        </div>

        <a href="<?= htmlspecialchars($home_url) ?>" class="eap-error-403__back-btn">Go Back</a>

        <div class="eap-error-403__security-badge">
            <svg class="eap-error-403__security-icon" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>
            Protected by Enterprise Security
        </div>
    </div>
</body>
</html>
