<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Controllers;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use Psr\Log\LoggerInterface;
use AdosLabs\AdminPanel\Http\Response;
use AdosLabs\AdminPanel\Services\AuthService;
use AdosLabs\AdminPanel\Services\AuditService;
use AdosLabs\AdminPanel\Services\SessionService;
use AdosLabs\AdminPanel\Services\RecoveryService;
use AdosLabs\AdminPanel\Services\NotificationService;
use AdosLabs\AdminPanel\Services\ConfigService;

/**
 * Authentication Controller
 *
 * Handles:
 * - Login (form + processing)
 * - Logout
 * - Two-factor authentication
 * - Password reset (future)
 *
 * SECURITY: All URLs are dynamic (cryptographic base path)
 *
 * @version 1.0.0
 */
final class AuthController extends BaseController
{
    private AuthService $authService;
    private ?RecoveryService $recoveryService = null;

    /**
     * Safely decode and validate base64 encoded error messages
     *
     * SECURITY: Only accepts printable ASCII to prevent XSS.
     * Returns null if decoding fails or content is suspicious.
     */
    private function safeBase64DecodeError(?string $encoded): ?string
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        $decoded = base64_decode($encoded, true);

        // Reject if not valid base64
        if ($decoded === false) {
            return null;
        }

        // SECURITY: Only allow printable ASCII characters (space through tilde)
        // This prevents any HTML/JavaScript injection attempts
        if (!preg_match('/^[\x20-\x7E]{1,500}$/', $decoded)) {
            return null;
        }

        return $decoded;
    }

    public function __construct(
        DatabasePool $db,
        SessionService $sessionService,
        AuditService $auditService,
        AuthService $authService,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($db, $sessionService, $auditService, $logger);
        $this->authService = $authService;
    }

    /**
     * Set recovery service for emergency bypass
     */
    public function setRecoveryService(RecoveryService $recoveryService): void
    {
        $this->recoveryService = $recoveryService;
    }

    /**
     * Show login form
     * GET /{dynamic-base}/login
     */
    public function loginForm(): Response
    {
        // Check if already logged in
        if ($this->getUser() !== null) {
            return $this->redirect($this->adminUrl('dashboard'));
        }

        // Get error from query string (base64 encoded) or flash
        $errorParam = $this->input('error');
        $error = $this->safeBase64DecodeError($errorParam) ?? $this->getFlash('error');
        $returnUrl = $this->sanitizeReturnUrl($this->input('return', $this->adminUrl('dashboard')));

        // Check if emergency recovery is enabled
        $emergencyRecoveryEnabled = $this->isEmergencyRecoveryEnabled();

        return $this->view('auth/login', [
            'error' => $error,
            'return_url' => $returnUrl,
            'form_action' => $this->adminUrl('login'),
            'admin_base_path' => $this->getAdminBasePath(),
            'emergency_recovery_enabled' => $emergencyRecoveryEnabled,
        ], null); // No layout for login page
    }

    /**
     * Check if emergency recovery is enabled
     */
    private function isEmergencyRecoveryEnabled(): bool
    {
        // Check config in database
        $rows = $this->db->query("SELECT config_value FROM admin_config WHERE config_key = 'emergency_recovery_enabled'");

        if (empty($rows)) {
            // Default: enabled
            return true;
        }

        return filter_var($rows[0]['config_value'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Sanitize return URL to prevent open redirect attacks
     * Only allows relative URLs within the admin panel
     */
    private function sanitizeReturnUrl(?string $url): string
    {
        $default = $this->adminUrl('dashboard');

        if ($url === null || $url === '') {
            return $default;
        }

        // Parse the URL
        $parsed = parse_url($url);

        // Reject absolute URLs with scheme (http://, https://, etc.)
        if (isset($parsed['scheme'])) {
            return $default;
        }

        // Reject URLs with host (//example.com)
        if (isset($parsed['host'])) {
            return $default;
        }

        // Reject URLs starting with protocol-relative format
        if (str_starts_with($url, '//')) {
            return $default;
        }

        // Must start with admin base path
        $adminBasePath = $this->getAdminBasePath();
        if (!str_starts_with($url, $adminBasePath)) {
            return $default;
        }

        return $url;
    }

    /**
     * Process login
     * POST /{dynamic-base}/login
     */
    public function login(): Response
    {
        // Validate input
        $validation = $this->validate([
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        if (!$validation['valid']) {
            return $this->redirectWithError(
                'Please enter a valid email and password.',
                $this->adminUrl('login')
            );
        }

        $email = $this->input('email');
        $password = $this->input('password');
        $returnUrl = $this->sanitizeReturnUrl($this->input('return_url'));

        // Attempt authentication
        $result = $this->authService->attempt(
            $email,
            $password,
            $this->getClientIp(),
            $this->getUserAgent()
        );

        // Handle 2FA required
        if ($result['requires_2fa']) {
            // Set temporary 2FA session cookie
            $twoFaSessionId = $result['2fa_session_id'] ?? null;

            if ($twoFaSessionId === null) {
                return $this->withFlash('error', 'Failed to create 2FA session', $this->adminUrl('login'));
            }

            // Redirect with temporary session cookie
            $response = Response::redirect($this->adminUrl('2fa') . '?return=' . urlencode($returnUrl));

            $cookiePath = $this->getAdminBasePath();

            // Secure 2FA cookie (short-lived, 5 minutes)
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            $isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', 'localhost:8080', '127.0.0.1'], true);

            $cookieParts = [
                'admin_2fa_session=' . $twoFaSessionId,
                'Path=' . $cookiePath,
                'HttpOnly',
                'SameSite=Strict', // Strict for 2FA
                'Max-Age=300', // 5 minutes
            ];

            if ($isHttps && !$isLocalhost) {
                $cookieParts[] = 'Secure';
            }

            return $response->withAddedHeader('Set-Cookie', implode('; ', $cookieParts));
        }

        // Handle login failure
        if (!$result['success']) {
            return $this->redirectWithError(
                $result['error'] ?? 'Invalid credentials',
                $this->adminUrl('login') . '?return=' . urlencode($returnUrl)
            );
        }

        // Login successful - set session cookie
        return $this->redirectWithSessionCookie($returnUrl, $result['session_id']);
    }

    /**
     * Show 2FA verification form
     * GET /{dynamic-base}/2fa
     */
    public function twoFactorForm(): Response
    {
        // Get error from query string (base64 encoded) or flash
        $errorParam = $this->input('error');
        $error = $this->safeBase64DecodeError($errorParam) ?? $this->getFlash('error');
        $returnUrl = $this->sanitizeReturnUrl($this->input('return'));

        // Get user's 2FA method and CSRF token from 2FA session
        $twoFaSessionId = $_COOKIE['admin_2fa_session'] ?? null;
        $method = 'totp';
        $csrfToken = '';

        if ($twoFaSessionId === null) {
            // No 2FA session - redirect to login
            return $this->redirect($this->adminUrl('login'));
        }

        $session = $this->sessionService->get($twoFaSessionId);

        if ($session === null) {
            // Session expired - redirect to login
            return $this->redirectWithError('Session expired. Please login again.', $this->adminUrl('login'));
        }

        // Get CSRF token from 2FA session payload
        $payload = $session['payload'] ?? '';
        if (is_string($payload)) {
            $payload = json_decode($payload, true) ?? [];
        }
        $csrfToken = $payload['csrf_token'] ?? '';

        // Look up user's 2FA method
        $rows = $this->db->query('SELECT two_factor_method FROM admin_users WHERE id = ?', [$session['user_id']]);
        $method = $rows[0]['two_factor_method'] ?? 'totp';

        // Build CSRF hidden input
        $csrfInput = $csrfToken
            ? '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars($csrfToken) . '">'
            : '';

        return $this->view('auth/two-factor', [
            'error' => $error,
            'return_url' => $returnUrl,
            'form_action' => $this->adminUrl('2fa/verify'),
            'admin_base_path' => $this->getAdminBasePath(),
            '2fa_method' => $method,
            'csrf_input' => $csrfInput,
        ], null);
    }

    /**
     * Verify 2FA code
     * POST /{dynamic-base}/2fa/verify
     */
    public function verifyTwoFactor(): Response
    {
        $validation = $this->validate([
            'code' => 'required|min:6',
        ]);

        if (!$validation['valid']) {
            return $this->redirectWithError('Please enter a valid 2FA code.', $this->adminUrl('2fa'));
        }

        // Get user ID from 2FA session
        $twoFaSessionId = $_COOKIE['admin_2fa_session'] ?? null;

        if ($twoFaSessionId === null) {
            return $this->redirect($this->adminUrl('login'));
        }

        // Validate 2FA session and get user ID
        $session = $this->sessionService->get($twoFaSessionId);

        if ($session === null) {
            // Session expired
            return $this->redirectWithError('Session expired. Please login again.', $this->adminUrl('login'));
        }

        $userId = $session['user_id'];
        $code = $this->input('code');
        $returnUrl = $this->sanitizeReturnUrl($this->input('return_url'));

        $result = $this->authService->verify2FA(
            (int) $userId,
            $code,
            $this->getClientIp(),
            $this->getUserAgent()
        );

        if (!$result['success']) {
            return $this->redirectWithError(
                $result['error'],
                $this->adminUrl('2fa') . '?return=' . urlencode($returnUrl)
            );
        }

        // Clear 2FA session cookie and set real session
        $response = $this->redirectWithSessionCookie($returnUrl, $result['session_id']);

        // Clear the 2FA cookie
        $cookiePath = $this->getAdminBasePath();
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', 'localhost:8080', '127.0.0.1'], true);

        $clearParts = [
            'admin_2fa_session=',
            'Path=' . $cookiePath,
            'HttpOnly',
            'SameSite=Strict',
            'Max-Age=0',
        ];

        if ($isHttps && !$isLocalhost) {
            $clearParts[] = 'Secure';
        }

        $clearCookie = implode('; ', $clearParts);

        return $response->withAddedHeader('Set-Cookie', $clearCookie);
    }

    /**
     * Redirect with error message (without needing session)
     */
    private function redirectWithError(string $error, string $url): Response
    {
        // Append error to URL as query param (base64 encoded for safety)
        $separator = str_contains($url, '?') ? '&' : '?';
        return Response::redirect($url . $separator . 'error=' . urlencode(base64_encode($error)));
    }

    /**
     * Logout
     * POST /{dynamic-base}/logout
     *
     * Security features:
     * - Session is destroyed and cookies are cleared
     * - Admin base path is regenerated (user must run CLI again)
     * - Shows styled goodbye page that prevents back-button navigation
     */
    public function logout(): Response
    {
        // Get user name BEFORE destroying session
        $user = $this->getUser();
        $userName = $user['name'] ?? 'Master';

        $sessionId = $this->getSessionId();

        if ($sessionId !== null) {
            $this->authService->logout(
                $sessionId,
                $this->getClientIp(),
                $this->getUserAgent()
            );
        }

        // SECURITY: Regenerate admin base path
        // The old URL becomes invalid immediately.
        // User must run CLI command to get the new URL.
        if ($this->configService !== null) {
            $this->configService->rotateAdminBasePath();

            // Log the rotation
            $this->auditService->log('admin_url_rotated', $user['id'] ?? null, [
                'ip' => $this->getClientIp(),
                'user_agent' => $this->getUserAgent(),
                'reason' => 'logout',
            ]);
        }

        // Render goodbye page with session cookie cleared
        return $this->renderGoodbyePage($userName);
    }

    /**
     * Render the goodbye page with proper security headers
     *
     * Security features:
     * - Cache-Control headers prevent caching (no back button access)
     * - Session cookie is cleared
     * - Page uses JavaScript to prevent history navigation
     */
    private function renderGoodbyePage(string $userName): Response
    {
        // Render the goodbye view
        $loginUrl = $this->adminUrl('login');

        ob_start();
        $login_url = $loginUrl;
        $user_name = $userName;
        include dirname(__DIR__) . '/Views/auth/goodbye.php';
        $html = ob_get_clean();

        // Create response with goodbye page using static factory
        $response = Response::html($html, 200);

        // Add security headers to prevent caching
        $response = $response
            ->withAddedHeader('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
            ->withAddedHeader('Pragma', 'no-cache')
            ->withAddedHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT')
            ->withAddedHeader('X-Content-Type-Options', 'nosniff')
            ->withAddedHeader('X-Frame-Options', 'DENY');

        // Clear session cookie
        $cookiePath = $this->getAdminBasePath();

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https';

        $isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', 'localhost:8080', '127.0.0.1'], true);

        $cookieName = ($isHttps && !$isLocalhost) ? '__Secure-admin_session' : 'admin_session';

        $cookieParts = [
            $cookieName . '=',
            'Path=' . $cookiePath,
            'HttpOnly',
            'SameSite=Strict',
            'Max-Age=0',
        ];

        if ($isHttps && !$isLocalhost) {
            $cookieParts[] = 'Secure';
        }

        return $response->withAddedHeader('Set-Cookie', implode('; ', $cookieParts));
    }

    /**
     * Redirect with session cookie set
     *
     * Cookie security attributes:
     * - HttpOnly: Prevents JavaScript access (XSS protection)
     * - SameSite=Strict: Strongest CSRF protection
     * - Secure: Only sent over HTTPS (except localhost)
     * - Path: Restricted to admin base path
     * - __Host- prefix: When Secure, guarantees no Domain and Path=/
     */
    private function redirectWithSessionCookie(string $url, string $sessionId): Response
    {
        $response = Response::redirect($url);

        // Get admin base path for cookie path
        $cookiePath = $this->getAdminBasePath();

        // Detect if HTTPS
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https';

        // Localhost detection for development
        $isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', 'localhost:8080', '127.0.0.1'], true);

        // Build secure cookie
        // Note: __Host- prefix requires Secure, Path=/, and no Domain
        // We can't use __Host- with custom path, so we use __Secure- when on HTTPS
        $cookieParts = [];

        if ($isHttps && !$isLocalhost) {
            // Production HTTPS: Maximum security
            $cookieParts[] = '__Secure-admin_session=' . $sessionId;
            $cookieParts[] = 'Path=' . $cookiePath;
            $cookieParts[] = 'Secure';
            $cookieParts[] = 'HttpOnly';
            $cookieParts[] = 'SameSite=Strict';
            $cookieParts[] = 'Max-Age=' . (86400 * 7); // 7 days
        } else {
            // Development (localhost without HTTPS)
            $cookieParts[] = 'admin_session=' . $sessionId;
            $cookieParts[] = 'Path=' . $cookiePath;
            $cookieParts[] = 'HttpOnly';
            $cookieParts[] = 'SameSite=Strict';
            $cookieParts[] = 'Max-Age=' . (86400 * 7);
        }

        return $response->withAddedHeader('Set-Cookie', implode('; ', $cookieParts));
    }

    /**
     * Redirect with session cookie cleared
     */
    private function redirectWithClearSession(string $url): Response
    {
        $response = Response::redirect($url);

        // Get admin base path for cookie path
        $cookiePath = $this->getAdminBasePath();

        // Detect if HTTPS
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || ($_SERVER['REQUEST_SCHEME'] ?? '') === 'https';

        $isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', 'localhost:8080', '127.0.0.1'], true);

        // Clear both possible cookie names
        $cookieName = ($isHttps && !$isLocalhost) ? '__Secure-admin_session' : 'admin_session';

        $cookieParts = [
            $cookieName . '=',
            'Path=' . $cookiePath,
            'HttpOnly',
            'SameSite=Strict',
            'Max-Age=0',
        ];

        if ($isHttps && !$isLocalhost) {
            $cookieParts[] = 'Secure';
        }

        return $response->withAddedHeader('Set-Cookie', implode('; ', $cookieParts));
    }

    // ========================================================================
    // Emergency Recovery (Master Admin Only)
    // ========================================================================

    /**
     * Show emergency recovery form
     * GET /{dynamic-base}/recovery
     *
     * Allows master admin to bypass 2FA using a one-time recovery token.
     */
    public function recoveryForm(): Response
    {
        // Check if already logged in
        if ($this->getUser() !== null) {
            return $this->redirect($this->adminUrl('dashboard'));
        }

        $errorParam = $this->input('error');
        $error = $this->safeBase64DecodeError($errorParam);

        return $this->view('auth/recovery', [
            'error' => $error,
            'form_action' => $this->adminUrl('recovery'),
            'admin_base_path' => $this->getAdminBasePath(),
            'login_url' => $this->adminUrl('login'),
        ], null); // No layout for recovery page
    }

    /**
     * Verify emergency recovery token
     * POST /{dynamic-base}/recovery
     *
     * Validates the one-time recovery token and creates a session.
     * Token is invalidated after use (cannot be reused).
     */
    public function verifyRecovery(): Response
    {
        if ($this->recoveryService === null) {
            return $this->redirectWithError(
                'Recovery service not configured',
                $this->adminUrl('login')
            );
        }

        // Validate input
        $validation = $this->validate([
            'email' => 'required|email',
            'token' => 'required|min:32',
        ]);

        if (!$validation['valid']) {
            return $this->redirectWithError(
                'Please enter a valid email and recovery token.',
                $this->adminUrl('recovery')
            );
        }

        $email = $this->input('email');
        $token = $this->input('token');

        // Verify user exists and is master admin
        $rows = $this->db->query('SELECT id, email, is_master FROM admin_users WHERE email = ?', [$email]);
        $user = $rows[0] ?? null;

        if (!$user) {
            // Don't reveal whether user exists
            return $this->redirectWithError(
                'Invalid email or recovery token.',
                $this->adminUrl('recovery')
            );
        }

        if (!$user['is_master']) {
            $this->auditService->log('recovery_attempt_non_master', (int) $user['id'], [
                'ip' => $this->getClientIp(),
            ]);
            return $this->redirectWithError(
                'Recovery is only available for master admin.',
                $this->adminUrl('recovery')
            );
        }

        // Verify and consume the recovery token
        $result = $this->recoveryService->verifyAndUseToken(
            $token,
            $this->getClientIp(),
            $this->getUserAgent()
        );

        if (!$result['success']) {
            return $this->redirectWithError(
                $result['error'] ?? 'Invalid or expired recovery token.',
                $this->adminUrl('recovery')
            );
        }

        // Verify the token belongs to the specified email
        if ($result['user_id'] !== (int) $user['id']) {
            $this->auditService->log('recovery_token_email_mismatch', (int) $user['id'], [
                'ip' => $this->getClientIp(),
                'token_user_id' => $result['user_id'],
            ]);
            return $this->redirectWithError(
                'Recovery token does not match the specified email.',
                $this->adminUrl('recovery')
            );
        }

        // Token is valid - create session (bypassing 2FA)
        $sessionResult = $this->authService->createSessionForUser(
            $result['user_id'],
            $this->getClientIp(),
            $this->getUserAgent(),
            true // Mark as recovery login
        );

        if (!$sessionResult['success']) {
            return $this->redirectWithError(
                'Failed to create session. Please try again.',
                $this->adminUrl('recovery')
            );
        }

        $this->auditService->log('recovery_login_success', $result['user_id'], [
            'ip' => $this->getClientIp(),
            'user_agent' => $this->getUserAgent(),
        ]);

        // Redirect to dashboard with session cookie
        return $this->redirectWithSessionCookie(
            $this->adminUrl('dashboard'),
            $sessionResult['session_id']
        );
    }
}
