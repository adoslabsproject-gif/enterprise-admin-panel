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

// Autoload
require_once __DIR__ . '/../vendor/autoload.php';

// ============================================================================
// BOOTSTRAP - Initialize framework (loads .env, db pool, cache, etc.)
// ============================================================================

use AdosLabs\AdminPanel\Bootstrap;

Bootstrap::init(dirname(__DIR__));

// Now we have access to:
// - db() : DatabasePool
// - cache() : CacheManager
// - should_log() : bool
// - query(), execute(), transaction()

// ============================================================================
// Imports
// ============================================================================

use AdosLabs\AdminPanel\Core\Container;
use AdosLabs\AdminPanel\Core\ModuleRegistry;
use AdosLabs\AdminPanel\Controllers\AuthController;
use AdosLabs\AdminPanel\Controllers\DashboardController;
use AdosLabs\AdminPanel\Services\AuthService;
use AdosLabs\AdminPanel\Services\SessionService;
use AdosLabs\AdminPanel\Services\AuditService;
use AdosLabs\AdminPanel\Services\ConfigService;
use AdosLabs\AdminPanel\Services\NotificationService;
use AdosLabs\AdminPanel\Services\TwoFactorService;
use AdosLabs\AdminPanel\Services\RecoveryService;
use AdosLabs\AdminPanel\Middleware\CsrfMiddleware;
use AdosLabs\AdminPanel\Http\Response;
use AdosLabs\AdminPanel\Http\Request;
use AdosLabs\AdminPanel\Http\ErrorPages;

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

    if ($csrfSession !== null) {
        $payload = $csrfSession['payload'] ?? '';
        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?? [];
        }
        $expectedToken = $payload['csrf_token'] ?? '';
        $submittedToken = $_POST['_csrf_token'] ?? $request->getHeaderLine('X-CSRF-Token') ?? '';

        if ($expectedToken === '' || !hash_equals($expectedToken, $submittedToken)) {
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

// ============================================================================
// EMERGENCY LOGIN - Browser access with emergency token
// ============================================================================

if ($fullPath === '/emergency-login' && $method === 'GET') {
    $token = $_GET['token'] ?? '';

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
$buildUrl = fn(string $path) => $configService->buildAdminUrl($path);

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
                $shouldWarn = $expiresInSeconds <= 300 && $expiresInSeconds > 0;

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
        $moduleRoutePath = '/admin' . $relativePath;
        $routes = $moduleRegistry->getRoutes();

        foreach ($routes as $route) {
            if ($route['method'] === $method && $route['path'] === $moduleRoutePath) {
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
        $isDev ? $e->getTraceAsString() : null
    );
}

// ============================================================================
// Send Response
// ============================================================================

$response = $response->withSecurityHeaders($isHttps);
header_remove('X-Powered-By');
$response->send();
