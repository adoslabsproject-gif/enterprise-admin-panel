<?php
/**
 * 500 Internal Server Error - "System Meltdown"
 *
 * Concept: A nuclear reactor core going critical with
 * warning lights, alarms, and a containment breach effect
 */

$home_url = $home_url ?? '/';
$error_id = $error_id ?? bin2hex(random_bytes(8));
$show_details = $show_details ?? false;
$error_message = $error_message ?? null;
$error_trace = $error_trace ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 - System Meltdown</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="stylesheet" href="/css/error-500.css">
</head>
<body class="eap-error-500">
    <div class="eap-error-500__warning-stripes"></div>
    <div class="eap-error-500__warning-stripes eap-error-500__warning-stripes--bottom"></div>
    <div class="eap-error-500__red-alert"></div>

    <div class="eap-error-500__smoke-container">
        <div class="eap-error-500__smoke"></div>
        <div class="eap-error-500__smoke"></div>
        <div class="eap-error-500__smoke"></div>
        <div class="eap-error-500__smoke"></div>
        <div class="eap-error-500__smoke"></div>
    </div>

    <div class="eap-error-500__container">
        <div class="eap-error-500__reactor-core">
            <div class="eap-error-500__core-outer">
                <div class="eap-error-500__core-center"></div>
            </div>
        </div>

        <div class="eap-error-500__warning-lights">
            <div class="eap-error-500__warning-light"></div>
            <div class="eap-error-500__warning-light"></div>
            <div class="eap-error-500__warning-light"></div>
            <div class="eap-error-500__warning-light"></div>
            <div class="eap-error-500__warning-light"></div>
        </div>

        <div class="eap-error-500__code">500</div>
        <h1 class="eap-error-500__title">System Meltdown</h1>
        <p class="eap-error-500__message">
            Critical system failure detected. Our engineers<br>
            have been automatically notified and are working on it.
        </p>

        <div class="eap-error-500__error-id">
            <span class="eap-error-500__error-id-label">Error ID:</span> <?= htmlspecialchars($error_id) ?>
        </div>

        <br>
        <a href="<?= htmlspecialchars($home_url) ?>" class="eap-error-500__back-btn">Return to Safety</a>

        <div class="eap-error-500__status-text">
            <span class="eap-error-500__status-label">STATUS:</span> CONTAINMENT BREACH<br>
            <span class="eap-error-500__status-label">PRIORITY:</span> CRITICAL<br>
            <span class="eap-error-500__status-label">TIMESTAMP:</span> <?= date('Y-m-d H:i:s T') ?>
        </div>

        <?php if ($show_details && ($error_message || $error_trace)) : ?>
        <details class="eap-error-500__debug-details">
            <summary class="eap-error-500__debug-summary">Technical Details (Development Mode)</summary>
            <?php if ($error_message) : ?>
            <span class="eap-error-500__debug-label">Error:</span>
            <pre class="eap-error-500__debug-content"><?= htmlspecialchars($error_message) ?></pre>
            <?php endif; ?>
            <?php if ($error_trace) : ?>
            <span class="eap-error-500__debug-label">Stack Trace:</span>
            <pre class="eap-error-500__debug-content"><?= htmlspecialchars($error_trace) ?></pre>
            <?php endif; ?>
        </details>
        <?php endif; ?>
    </div>

    <script>
        // Add random flicker to reactor core
        setInterval(() => {
            const core = document.querySelector('.eap-error-500__core-center');
            if (Math.random() > 0.9) {
                core.style.opacity = '0.5';
                setTimeout(() => core.style.opacity = '1', 50);
            }
        }, 100);
    </script>
</body>
</html>
