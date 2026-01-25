<?php
/**
 * 404 Not Found - "Lost in the Matrix"
 *
 * Concept: Digital rain effect like The Matrix, with the message
 * that the page has been "derezzed" (Tron reference)
 */

$home_url = $home_url ?? '/';
$requested_path = $requested_path ?? $_SERVER['REQUEST_URI'] ?? '/unknown';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Derezzed</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="stylesheet" href="/css/error-404.css">
</head>
<body class="eap-error-404">
    <canvas id="eap-matrix-rain" class="eap-error-404__matrix-rain"></canvas>

    <div class="eap-error-404__container">
        <div class="eap-error-404__code">404</div>
        <h1 class="eap-error-404__title">Page Derezzed</h1>
        <p class="eap-error-404__message">
            The page you're looking for has been deleted from the system,<br>
            or perhaps it never existed in this reality.
        </p>
        <div class="eap-error-404__path"><?= htmlspecialchars($requested_path) ?></div>
        <br>
        <a href="<?= htmlspecialchars($home_url) ?>" class="eap-error-404__back-btn">Return to Grid</a>
        <div class="eap-error-404__terminal-line">
            > system.locate("<?= htmlspecialchars(basename($requested_path)) ?>") <span class="eap-error-404__cursor">_</span><br>
            > ERROR: Resource not found in database
        </div>
    </div>

    <script>
        // Matrix rain effect
        const canvas = document.getElementById('eap-matrix-rain');
        const ctx = canvas.getContext('2d');

        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;

        const chars = 'アイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモヤユヨラリルレロワヲン0123456789ABCDEF';
        const charArray = chars.split('');

        const fontSize = 14;
        const columns = canvas.width / fontSize;

        const drops = [];
        for (let i = 0; i < columns; i++) {
            drops[i] = Math.random() * -100;
        }

        function draw() {
            ctx.fillStyle = 'rgba(10, 10, 10, 0.05)';
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            ctx.fillStyle = '#00ff41';
            ctx.font = fontSize + 'px monospace';

            for (let i = 0; i < drops.length; i++) {
                const char = charArray[Math.floor(Math.random() * charArray.length)];
                ctx.fillText(char, i * fontSize, drops[i] * fontSize);

                if (drops[i] * fontSize > canvas.height && Math.random() > 0.975) {
                    drops[i] = 0;
                }
                drops[i]++;
            }
        }

        setInterval(draw, 35);

        window.addEventListener('resize', () => {
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
        });
    </script>
</body>
</html>
