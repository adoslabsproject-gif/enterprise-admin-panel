<?php

/**
 * Enterprise Admin Panel - Entry Point
 *
 * SECURITY: Uses cryptographic dynamic URLs.
 * Static paths like /admin/* are BLOCKED and return 404.
 *
 * The admin panel is accessible ONLY via the dynamic URL stored in database:
 *   Example: /x-a7f3b2c8d9e4f1a6b2c8d9e4/login
 *
 * Usage:
 *   php -S localhost:8080 -t public
 */

declare(strict_types=1);

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ============================================================================
// AUTOLOAD DETECTION
// Supports: normal install, path repository (symlink), and standalone
// ============================================================================

$autoloaded = false;
$projectRoot = null;

// Method 1: Check if EAP_PROJECT_ROOT is defined by wrapper index.php
// This is the most reliable method when installed as a package
if (defined('EAP_PROJECT_ROOT')) {
    $projectRoot = EAP_PROJECT_ROOT;
    $autoloadPath = $projectRoot . '/vendor/autoload.php';
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloaded = true;
    }
}

// Method 2: Use getcwd() - works when running from project root
// This handles symlink case where __DIR__ resolves to original package location
if (!$autoloaded) {
    $cwd = getcwd();
    if ($cwd !== false) {
        // Check if we're in public/ or project root
        $checkPaths = [
            $cwd . '/vendor/autoload.php',           // Running from project root
            dirname($cwd) . '/vendor/autoload.php',  // Running from public/
        ];

        foreach ($checkPaths as $autoloadPath) {
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
                $autoloaded = true;
                $projectRoot = dirname($autoloadPath, 2);
                break;
            }
        }
    }
}

// Method 3: Walk up from __DIR__ (original method - works for normal installs)
if (!$autoloaded) {
    $autoloadPaths = [
        // Project vendor autoload (when installed as package via normal composer)
        dirname(__DIR__, 4) . '/vendor/autoload.php',
        // Package standalone vendor autoload
        __DIR__ . '/../vendor/autoload.php',
    ];

    foreach ($autoloadPaths as $autoloadPath) {
        if (file_exists($autoloadPath)) {
            require_once $autoloadPath;
            $autoloaded = true;
            $projectRoot = dirname($autoloadPath, 2);
            break;
        }
    }
}

if (!$autoloaded) {
    die('Could not find autoload.php. Run: composer install');
}

// ============================================================================
// EARLY TIMEZONE CONFIGURATION
// Set timezone BEFORE any logging or date operations to ensure correct timestamps
// Priority: APP_TIMEZONE env > php.ini date.timezone > Europe/Rome default
// ============================================================================

(function (): void {
    // Try environment variable first
    $timezone = $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: null;

    // Fall back to php.ini setting
    if ($timezone === null || $timezone === '' || $timezone === false) {
        $iniTimezone = ini_get('date.timezone');
        if ($iniTimezone !== '' && $iniTimezone !== false) {
            $timezone = $iniTimezone;
        }
    }

    // Default to Europe/Rome
    if ($timezone === null || $timezone === '' || $timezone === false) {
        $timezone = 'Europe/Rome';
    }

    // Apply timezone
    try {
        date_default_timezone_set($timezone);
    } catch (\Throwable $e) {
        date_default_timezone_set('Europe/Rome');
    }
})();

// ============================================================================
// MODULE ASSETS - Serve CSS/JS directly from vendor packages (BEFORE bootstrap)
// This avoids initializing DB/cache just to serve static files
// Path: /module-assets/{package}/{file} -> vendor/ados-labs/{package}/public/{file}
// ============================================================================

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (preg_match('#^/module-assets/([a-z0-9-]+)/(.+)$#', $requestPath, $matches)) {
    $package = $matches[1];
    $file = $matches[2];

    // Security: only allow specific extensions
    $allowedExtensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'woff', 'woff2', 'ttf', 'eot'];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExtensions, true)) {
        http_response_code(403);
        exit('Forbidden');
    }

    // Security: prevent directory traversal
    if (str_contains($file, '..') || str_contains($package, '..')) {
        http_response_code(403);
        exit('Forbidden');
    }

    // Build path to vendor package
    $assetPath = $projectRoot . '/vendor/ados-labs/' . $package . '/public/' . $file;

    if (!file_exists($assetPath) || !is_file($assetPath)) {
        http_response_code(404);
        exit('Not Found');
    }

    // Set content type
    $contentTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
    ];

    header('Content-Type: ' . ($contentTypes[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=31536000'); // 1 year cache
    header('X-Content-Type-Options: nosniff');

    readfile($assetPath);
    exit;
}

// ============================================================================
// BOOTSTRAP - Initialize framework (loads .env, db pool, cache, etc.)
// ============================================================================

use AdosLabs\AdminPanel\Bootstrap;

// Use project root (where .env is located), not package directory
Bootstrap::init($projectRoot);

// Now we have access to:
// - db() : DatabasePool
// - cache() : CacheManager
// - should_log() : bool
// - query(), execute(), transaction()

// ============================================================================
// Imports
// ============================================================================

use AdosLabs\AdminPanel\Controllers\AuthController;
use AdosLabs\AdminPanel\Controllers\DashboardController;
use AdosLabs\AdminPanel\Core\ModuleRegistry;
use AdosLabs\AdminPanel\Http\ErrorPages;
use AdosLabs\AdminPanel\Http\Request;
use AdosLabs\AdminPanel\Http\Response;
use AdosLabs\AdminPanel\Middleware\CsrfMiddleware;
use AdosLabs\AdminPanel\Services\AuditService;
use AdosLabs\AdminPanel\Services\AuthService;
use AdosLabs\AdminPanel\Services\ConfigService;
use AdosLabs\AdminPanel\Services\NotificationService;
use AdosLabs\AdminPanel\Services\RecoveryService;
use AdosLabs\AdminPanel\Services\SessionService;
use AdosLabs\AdminPanel\Services\TwoFactorService;
use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;

// ============================================================================
// Services - All use the database pool via db()
// ============================================================================

$dbPool = db();

$configService = new ConfigService($dbPool);
$sessionService = new SessionService($dbPool);
$auditService = new AuditService($dbPool);
$notificationService = new NotificationService($dbPool, $configService);
$twoFactorService = new TwoFactorService($dbPool, $notificationService, $auditService);
$authService = new AuthService($dbPool, $sessionService, $auditService);
$authService->setTwoFactorService($twoFactorService);
$recoveryService = new RecoveryService($dbPool, $notificationService, $auditService);
$moduleRegistry = new ModuleRegistry($dbPool);

// Discover modules from installed packages
// This automatically registers modules from composer packages like enterprise-psr3-logger
$moduleRegistry->discoverModules();

// ============================================================================
// Request Parsing
// ============================================================================

$method = $_SERVER['REQUEST_METHOD'];
$fullPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Create PSR-7 compliant request object
$request = Request::fromGlobals();

// ============================================================================
// Session Cookie Helper
// ============================================================================

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    || ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https';
$isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', 'localhost:8080', '127.0.0.1'], true);

$getSessionCookie = function () use ($isHttps, $isLocalhost): ?string {
    if ($isHttps && !$isLocalhost) {
        return $_COOKIE['__Secure-admin_session'] ?? $_COOKIE['admin_session'] ?? null;
    }

    return $_COOKIE['admin_session'] ?? null;
};

// ============================================================================
// CSRF Protection (for non-safe HTTP methods)
// ============================================================================

$safeMethods = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

// Auth routes that don't require CSRF validation
// These routes handle authentication where the user may not have a valid session
// when loading the form, but may have an old session cookie when submitting
$csrfExemptPaths = ['/login', '/2fa/verify', '/recovery'];

if (!in_array($method, $safeMethods, true)) {
    $csrfMiddleware = new CsrfMiddleware($sessionService, $configService->getAdminBasePath());

    $sessionId = $getSessionCookie();
    $twoFaSessionId = $_COOKIE['admin_2fa_session'] ?? null;

    $csrfSession = null;
    $activeSessionId = null;

    if ($sessionId !== null) {
        $session = $sessionService->get($sessionId);
        if ($session !== null) {
            $csrfSession = $session;
            $activeSessionId = $sessionId;
            $request = $request->withAttribute('admin_session', $session);
        }
    }

    if ($csrfSession === null && $twoFaSessionId !== null) {
        $twoFaSession = $sessionService->get($twoFaSessionId);
        if ($twoFaSession !== null) {
            $csrfSession = $twoFaSession;
            $activeSessionId = $twoFaSessionId;
        }
    }

    // Get relative path for CSRF exemption check
    $relativePath = $configService->getAdminRelativePath($fullPath);

    // Skip CSRF validation for auth routes (login, 2fa, recovery)
    // These routes need to work even when user has stale session cookie
    $isCsrfExempt = in_array($relativePath, $csrfExemptPaths, true);

    if ($csrfSession !== null && !$isCsrfExempt) {
        $payload = $csrfSession['payload'] ?? '';
        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?? [];
        }
        $expectedToken = $payload['csrf_token'] ?? '';
        $submittedToken = $_POST['_csrf_token'] ?? $request->getHeaderLine('X-CSRF-Token') ?? '';

        // Only validate CSRF if the session has a token configured
        // Sessions without CSRF token (legacy or corrupted) should not block the request
        // but we log it for monitoring
        if ($expectedToken !== '') {
            if (!hash_equals($expectedToken, $submittedToken)) {
                $auditService->log('csrf_validation_failed', null, [
                    'path' => $fullPath,
                    'method' => $method,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);

                ErrorPages::render403('/', 'Invalid CSRF token. Please refresh the page and try again.');
            }

            if ($sessionId !== null && $activeSessionId === $sessionId) {
                $newToken = $csrfMiddleware->regenerateToken($sessionId);
                $request = $request->withAttribute('csrf_token', $newToken);
            }
        }
    }
}

// ============================================================================
// EMERGENCY LOGIN - Browser access with emergency token
// ============================================================================

// GET /emergency-login shows the form (no token in URL = no log exposure)
if ($fullPath === '/emergency-login' && $method === 'GET') {
    // Render emergency login form
    $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Emergency Access</title>
            <style>
                * { box-sizing: border-box; }
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; min-height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; padding: 1rem; }
                .card { background: white; border-radius: 12px; padding: 2rem; max-width: 400px; width: 100%; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); }
                .icon { font-size: 3rem; text-align: center; margin-bottom: 1rem; }
                h1 { margin: 0 0 0.5rem; text-align: center; color: #0f172a; font-size: 1.5rem; }
                p { color: #64748b; text-align: center; margin: 0 0 1.5rem; font-size: 0.9rem; }
                label { display: block; font-weight: 500; color: #334155; margin-bottom: 0.5rem; }
                input { width: 100%; padding: 0.75rem 1rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 1rem; font-family: monospace; }
                input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
                button { width: 100%; padding: 0.875rem; background: #dc2626; color: white; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 1rem; }
                button:hover { background: #b91c1c; }
                .warning { background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 0.75rem; margin-bottom: 1rem; font-size: 0.85rem; color: #92400e; }
            </style>
        </head>
        <body>
            <div class="card">
                <div class="icon">&#128274;</div>
                <h1>Emergency Access</h1>
                <p>Enter your emergency access token to bypass normal authentication.</p>
                <div class="warning">
                    <strong>Warning:</strong> This token is single-use and will be invalidated after access.
                </div>
                <form method="POST" action="/emergency-login">
                    <label for="token">Emergency Token</label>
                    <input type="password" id="token" name="token" placeholder="Enter your emergency token" required autofocus>
                    <button type="submit">Access Dashboard</button>
                </form>
            </div>
        </body>
        </html>
        HTML;
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

// POST /emergency-login verifies the token (token in body = no log exposure)
if ($fullPath === '/emergency-login' && $method === 'POST') {
    $token = $_POST['token'] ?? '';

    if (empty($token)) {
        ErrorPages::render403('/', 'Emergency token required');
    }

    // Find and verify emergency token
    $stmt = $dbPool->query('
        SELECT et.id, et.user_id, et.token_hash, et.name, et.expires_at, et.is_used,
               u.email, u.name as user_name, u.is_master
        FROM admin_emergency_tokens et
        JOIN admin_users u ON et.user_id = u.id
        WHERE et.is_used = false
          AND (et.expires_at IS NULL OR et.expires_at > NOW())
    ');

    $matchedToken = null;
    foreach ($stmt as $row) {
        if (password_verify($token, $row['token_hash'])) {
            $matchedToken = $row;
            break;
        }
    }

    if ($matchedToken === null) {
        $auditService->log('emergency_token_invalid', null, [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
        ErrorPages::render403('/', 'Invalid, expired, or already used emergency token');
    }

    if (!$matchedToken['is_master']) {
        ErrorPages::render403('/', 'Emergency tokens are only valid for master admin accounts');
    }

    // Mark token as used (ONE-TIME USE)
    $dbPool->execute('
        UPDATE admin_emergency_tokens
        SET is_used = true, used_at = NOW(), used_from_ip = ?
        WHERE id = ?
    ', [$_SERVER['REMOTE_ADDR'] ?? 'unknown', $matchedToken['id']]);

    // Create session directly (bypasses password and 2FA)
    $sessionId = bin2hex(random_bytes(64));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour')); // Short session for emergency

    $csrfToken = bin2hex(random_bytes(32));
    $payload = json_encode([
        'csrf_token' => $csrfToken,
        'emergency_login' => true,
        'emergency_token_id' => $matchedToken['id'],
    ]);

    $dbPool->execute('
        INSERT INTO admin_sessions (id, user_id, ip_address, user_agent, payload, last_activity, expires_at)
        VALUES (?, ?, ?, ?, ?, NOW(), ?)
    ', [
        $sessionId,
        $matchedToken['user_id'],
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        $payload,
        $expiresAt,
    ]);

    // Audit log
    $auditService->log('emergency_login_success', $matchedToken['user_id'], [
        'token_id' => $matchedToken['id'],
        'token_name' => $matchedToken['name'],
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);

    // Set session cookie
    $cookieName = $isHttps && !$isLocalhost ? '__Secure-admin_session' : 'admin_session';
    setcookie($cookieName, $sessionId, [
        'expires' => strtotime($expiresAt),
        'path' => '/',
        'secure' => $isHttps && !$isLocalhost,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    // Redirect to dashboard
    $adminBasePath = $configService->getAdminBasePath();
    header('Location: ' . $adminBasePath . '/dashboard');
    exit;
}

// ============================================================================
// SECURITY: Block static /admin/* paths - return 404 (not 403 to avoid info leak)
// ============================================================================

if (preg_match('#^/admin(/|$)#', $fullPath)) {
    $auditService->log('blocked_static_admin_access', null, [
        'path' => $fullPath,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);

    ErrorPages::render404('/', $fullPath);
}

// ============================================================================
// Get Dynamic Admin Base Path
// ============================================================================

$adminBasePath = $configService->getAdminBasePath();

if (!$configService->isAdminPath($fullPath)) {
    ErrorPages::render404('/', $fullPath);
}

$relativePath = $configService->getAdminRelativePath($fullPath);

// ============================================================================
// Route Handling
// ============================================================================

$response = null;
$buildUrl = fn (string $path) => $configService->buildAdminUrl($path);

try {
    // ========================================================================
    // Auth routes (no authentication required)
    // ========================================================================

    if ($relativePath === '/login' && $method === 'GET') {
        $controller = new AuthController($dbPool, $sessionService, $auditService, $authService);
        $controller->setRequest($request);
        $controller->setConfigService($configService);
        $response = $controller->loginForm();
    } elseif ($relativePath === '/login' && $method === 'POST') {
        $controller = new AuthController($dbPool, $sessionService, $auditService, $authService);
        $controller->setRequest($request);
        $controller->setConfigService($configService);
        $response = $controller->login();
    } elseif ($relativePath === '/2fa' && $method === 'GET') {
        $controller = new AuthController($dbPool, $sessionService, $auditService, $authService);
        $controller->setRequest($request);
        $controller->setConfigService($configService);
        $response = $controller->twoFactorForm();
    } elseif ($relativePath === '/2fa/verify' && $method === 'POST') {
        $controller = new AuthController($dbPool, $sessionService, $auditService, $authService);
        $controller->setRequest($request);
        $controller->setConfigService($configService);
        $response = $controller->verifyTwoFactor();
    } elseif ($relativePath === '/recovery' && $method === 'GET') {
        $controller = new AuthController($dbPool, $sessionService, $auditService, $authService);
        $controller->setRequest($request);
        $controller->setConfigService($configService);
        $controller->setRecoveryService($recoveryService);
        $response = $controller->recoveryForm();
    } elseif ($relativePath === '/recovery' && $method === 'POST') {
        $controller = new AuthController($dbPool, $sessionService, $auditService, $authService);
        $controller->setRequest($request);
        $controller->setConfigService($configService);
        $controller->setRecoveryService($recoveryService);
        $response = $controller->verifyRecovery();
    } elseif ($relativePath === '/logout' && $method === 'GET') {
        $response = Response::redirect($buildUrl('login'));
    } elseif ($relativePath === '/logout' && $method === 'POST') {
        $sessionId = $getSessionCookie() ?? null;
        $session = $sessionId ? $sessionService->validate($sessionId) : null;

        if ($session !== null) {
            $request = $request
                ->withAttribute('admin_session_id', $sessionId)
                ->withAttribute('admin_session', $session)
                ->withAttribute('admin_user', [
                    'id' => $session['user_id'],
                    'email' => $session['email'],
                    'name' => $session['name'],
                    'role' => $session['role'],
                ]);
        }

        $controller = new AuthController($dbPool, $sessionService, $auditService, $authService);
        $controller->setRequest($request);
        $controller->setConfigService($configService);
        $response = $controller->logout();
    }

    // ========================================================================
    // Session Heartbeat API
    // ========================================================================
    elseif ($relativePath === '/api/session/heartbeat' && $method === 'GET') {
        $sessionId = $getSessionCookie() ?? null;

        if ($sessionId === null) {
            $response = Response::json([
                'active' => false,
                'expires_in' => 0,
                'should_warn' => false,
                'message' => 'No session',
            ]);
        } else {
            $session = $sessionService->get($sessionId);

            if ($session === null) {
                $response = Response::json([
                    'active' => false,
                    'expires_in' => 0,
                    'should_warn' => false,
                    'message' => 'Session expired',
                ]);
            } else {
                $sessionService->touch($sessionId);

                $expiresAt = new DateTimeImmutable($session['expires_at']);
                $now = new DateTimeImmutable();
                $expiresInSeconds = max(0, $expiresAt->getTimestamp() - $now->getTimestamp());
                $shouldWarn = $expiresInSeconds <= 3600 && $expiresInSeconds > 0; // TEST: 3600 for testing, change back to 300

                $response = Response::json([
                    'active' => true,
                    'expires_in' => $expiresInSeconds,
                    'should_warn' => $shouldWarn,
                    'extension_count' => $session['payload']['extension_count'] ?? 0,
                ]);
            }
        }
    }

    // ========================================================================
    // Session Extend API (explicitly extend session by 1 hour)
    // ========================================================================
    elseif ($relativePath === '/api/session/extend' && $method === 'POST') {
        $sessionId = $getSessionCookie() ?? null;

        if ($sessionId === null) {
            Logger::channel('security')->warning('Session extend failed - no session cookie');
            $response = Response::json([
                'success' => false,
                'message' => 'No session',
            ], 401);
        } else {
            try {
                $extended = $sessionService->extend($sessionId);

                if ($extended) {
                    // Get updated session info
                    $session = $sessionService->get($sessionId);
                    $expiresAt = new DateTimeImmutable($session['expires_at']);
                    $now = new DateTimeImmutable();
                    $expiresInSeconds = max(0, $expiresAt->getTimestamp() - $now->getTimestamp());

                    Logger::channel('security')->info('Session extended successfully', [
                        'session_id_prefix' => substr($sessionId, 0, 16),
                        'expires_in' => $expiresInSeconds,
                        'extension_count' => $session['payload']['extension_count'] ?? 0,
                    ]);

                    $response = Response::json([
                        'success' => true,
                        'active' => true,
                        'expires_in' => $expiresInSeconds,
                        'should_warn' => false,
                        'extension_count' => $session['payload']['extension_count'] ?? 0,
                        'message' => 'Session extended',
                    ]);
                } else {
                    Logger::channel('security')->warning('Session extend failed - session not found', [
                        'session_id_prefix' => substr($sessionId, 0, 16),
                    ]);
                    $response = Response::json([
                        'success' => false,
                        'message' => 'Session not found',
                    ], 401);
                }
            } catch (\Throwable $e) {
                Logger::channel('security')->error('Session extend exception', [
                    'error' => $e->getMessage(),
                    'session_id_prefix' => substr($sessionId, 0, 16),
                ]);
                $response = Response::json([
                    'success' => false,
                    'message' => 'Error extending session',
                ], 500);
            }
        }
    }

    // ========================================================================
    // Infrastructure Metrics API (requires authentication)
    // ========================================================================
    elseif ($relativePath === '/api/dbpool' && $method === 'GET') {
        $sessionId = $getSessionCookie() ?? null;
        $session = $sessionId ? $sessionService->validate($sessionId) : null;

        if ($session === null) {
            $response = Response::json(['error' => 'Unauthorized'], 401);
        } else {
            $controller = new DashboardController($dbPool, $sessionService, $auditService, $moduleRegistry);
            $controller->setRequest($request);
            $controller->setConfigService($configService);
            $response = $controller->dbPoolMetrics();
        }
    } elseif ($relativePath === '/api/redis' && $method === 'GET') {
        $sessionId = $getSessionCookie() ?? null;
        $session = $sessionId ? $sessionService->validate($sessionId) : null;

        if ($session === null) {
            $response = Response::json(['error' => 'Unauthorized'], 401);
        } else {
            $controller = new DashboardController($dbPool, $sessionService, $auditService, $moduleRegistry);
            $controller->setRequest($request);
            $controller->setConfigService($configService);
            $response = $controller->redisMetrics();
        }
    }

    // ========================================================================
    // Dashboard (requires authentication)
    // ========================================================================
    elseif ($relativePath === '/dashboard' || $relativePath === '/' || $relativePath === '') {
        $sessionId = $getSessionCookie() ?? null;
        $session = $sessionId ? $sessionService->validate($sessionId) : null;

        if ($session === null) {
            $response = Response::redirect($buildUrl('login'));
        } else {
            $payload = $session['payload'] ?? '';
            if (is_string($payload)) {
                $payload = json_decode($payload, true) ?? [];
            }
            $csrfToken = $payload['csrf_token'] ?? '';

            $request = $request
                ->withAttribute('admin_session_id', $sessionId)
                ->withAttribute('admin_session', $session)
                ->withAttribute('admin_user', [
                    'id' => $session['user_id'],
                    'email' => $session['email'],
                    'name' => $session['name'],
                    'role' => $session['role'],
                    'permissions' => json_decode($session['permissions'] ?? '[]', true),
                ])
                ->withAttribute('admin_base_path', $adminBasePath)
                ->withAttribute('csrf_token', $csrfToken);

            $controller = new DashboardController($dbPool, $sessionService, $auditService, $moduleRegistry);
            $controller->setRequest($request);
            $controller->setModuleRegistry($moduleRegistry);
            $controller->setConfigService($configService);
            $response = $controller->index();
        }
    }

    // ========================================================================
    // Module routes
    // ========================================================================
    else {
        // Module routes are registered without /admin prefix (e.g., /logger/channels/update)
        // so we match against $relativePath directly
        $routes = $moduleRegistry->getRoutes();

        foreach ($routes as $route) {
            if ($route['method'] === $method && $route['path'] === $relativePath) {
                $sessionId = $getSessionCookie() ?? null;
                $session = $sessionId ? $sessionService->validate($sessionId) : null;

                if ($session === null) {
                    $response = Response::redirect($buildUrl('login'));
                } else {
                    $payload = $session['payload'] ?? '';
                    if (is_string($payload)) {
                        $payload = json_decode($payload, true) ?? [];
                    }
                    $csrfToken = $payload['csrf_token'] ?? '';

                    [$controllerClass, $action] = $route['handler'];

                    if (class_exists($controllerClass)) {
                        $controller = new $controllerClass($dbPool, $sessionService, $auditService);

                        $request = $request
                            ->withAttribute('admin_session_id', $sessionId)
                            ->withAttribute('admin_session', $session)
                            ->withAttribute('admin_user', [
                                'id' => $session['user_id'],
                                'email' => $session['email'],
                                'name' => $session['name'],
                                'role' => $session['role'],
                            ])
                            ->withAttribute('admin_base_path', $adminBasePath)
                            ->withAttribute('csrf_token', $csrfToken);

                        $controller->setRequest($request);

                        if (method_exists($controller, 'setModuleRegistry')) {
                            $controller->setModuleRegistry($moduleRegistry);
                        }

                        if (method_exists($controller, 'setConfigService')) {
                            $controller->setConfigService($configService);
                        }

                        $response = $controller->$action();
                    }
                }
                break;
            }
        }
    }

    if ($response === null) {
        $response = ErrorPages::get404Response($buildUrl('login'), $relativePath);
    }
} catch (Throwable $e) {
    error_log('Admin Panel Error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());

    $isDev = env('APP_ENV') !== 'production';

    $response = ErrorPages::get500Response(
        $adminBasePath ?? '/',
        bin2hex(random_bytes(8)),
        $isDev,
        $isDev ? $e->getMessage() : null,
        $isDev ? $e->getTraceAsString() : null,
    );
}

// ============================================================================
// Send Response
// ============================================================================

$response = $response->withSecurityHeaders($isHttps);
header_remove('X-Powered-By');
$response->send();
