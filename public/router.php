<?php
/**
 * Router for PHP Built-in Development Server
 *
 * This file ensures proper routing and correct HTTP status codes
 * when using `php -S localhost:8080 -t public public/router.php`
 *
 * The built-in server shows 404 in logs for virtual routes because
 * it checks for physical files first. This router intercepts all
 * requests and forwards them to index.php.
 */

// Serve static files directly if they exist
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$staticFile = __DIR__ . $uri;

if ($uri !== '/' && file_exists($staticFile) && is_file($staticFile)) {
    // Let the built-in server handle static files
    return false;
}

// Forward all other requests to index.php
require __DIR__ . '/index.php';
