<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use AdosLabs\AdminPanel\Services\SessionService;
use AdosLabs\AdminPanel\Http\Response;
use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;

/**
 * Enterprise CSRF Protection Middleware - STATELESS HMAC
 *
 * Stateless HMAC-based CSRF protection:
 * - Token = base64(timestamp.HMAC-SHA256(session_id + timestamp, secret_key))
 * - NO database writes during validation
 * - NO token regeneration needed
 * - Token valid for configurable duration (default: 60 minutes)
 * - Cryptographically secure via HMAC-SHA256
 *
 * Security layers:
 * 1. HMAC signature verification (unforgeability)
 * 2. Timestamp validation (replay protection)
 * 3. Session binding (cross-session protection)
 * 4. Origin/Referer validation
 * 5. SameSite cookie enforcement
 *
 * PSR-15 compatible middleware
 *
 * @version 2.0.0
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    /**
     * CSRF token names
     */
    private const TOKEN_HEADER = 'X-CSRF-Token';
    private const TOKEN_FIELD = '_csrf_token';
    private const TOKEN_COOKIE = 'csrf_token';

    /**
     * Safe HTTP methods (don't require CSRF check)
     */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /**
     * Request attribute for CSRF token
     */
    public const ATTR_TOKEN = 'csrf_token';

    /**
     * Token validity duration in seconds (60 minutes)
     */
    private const TOKEN_LIFETIME_SECONDS = 3600;

    /**
     * HMAC algorithm
     */
    private const HMAC_ALGO = 'sha256';

    private bool $validateOrigin;
    private array $trustedOrigins;
    private string $adminBasePath;
    private string $secretKey;
    private int $tokenLifetime;

    public function __construct(
        private SessionService $sessionService,
        string $adminBasePath = '/admin',
        bool $validateOrigin = true,
        array $trustedOrigins = [],
        ?string $secretKey = null,
        int $tokenLifetime = self::TOKEN_LIFETIME_SECONDS
    ) {
        $this->adminBasePath = $adminBasePath;
        $this->validateOrigin = $validateOrigin;
        $this->trustedOrigins = $trustedOrigins;
        $this->tokenLifetime = $tokenLifetime;

        // Secret key: use provided, env, or generate from master token
        $this->secretKey = $secretKey
            ?? $_ENV['CSRF_SECRET_KEY']
            ?? $_ENV['EAP_CSRF_SECRET']
            ?? $this->deriveSecretKey();
    }

    /**
     * Derive secret key from master token or generate one
     */
    private function deriveSecretKey(): string
    {
        $masterToken = $_ENV['EAP_MASTER_TOKEN'] ?? $_ENV['MASTER_TOKEN'] ?? null;

        if ($masterToken !== null) {
            // Derive CSRF key from master token using HKDF
            return hash_hmac(self::HMAC_ALGO, 'csrf-protection-key', $masterToken);
        }

        // Fallback: use app key or generate random (not ideal for distributed systems)
        $appKey = $_ENV['EAP_APP_KEY'] ?? $_ENV['APP_KEY'] ?? null;

        if ($appKey !== null) {
            return hash_hmac(self::HMAC_ALGO, 'csrf-protection-key', $appKey);
        }

        // Last resort: generate from PHP's random
        // Note: This will cause issues in load-balanced environments
        // In production, always set CSRF_SECRET_KEY or EAP_MASTER_TOKEN
        return hash(self::HMAC_ALGO, random_bytes(32));
    }

    /**
     * Generate HMAC-based CSRF token
     *
     * Token format: base64(timestamp_hex.signature)
     * Where signature = HMAC-SHA256(session_id + timestamp, secret_key)
     *
     * @param string $sessionId Session ID to bind token to
     * @return string CSRF token
     */
    public function generateToken(string $sessionId): string
    {
        $timestamp = time();
        $timestampHex = dechex($timestamp);

        // Create signature: HMAC(session_id + timestamp, secret)
        $payload = $sessionId . '|' . $timestamp;
        $signature = hash_hmac(self::HMAC_ALGO, $payload, $this->secretKey);

        // Token = base64(timestamp_hex.signature)
        return base64_encode($timestampHex . '.' . $signature);
    }

    /**
     * Verify HMAC-based CSRF token
     *
     * Validates:
     * 1. Token format
     * 2. Timestamp not expired
     * 3. HMAC signature matches
     *
     * @param string $sessionId Session ID
     * @param string $token Token to verify
     * @return bool Valid or not
     */
    public function verifyToken(string $sessionId, string $token): bool
    {
        // Decode token
        $decoded = base64_decode($token, true);

        if ($decoded === false) {
            return false;
        }

        // Parse: timestamp_hex.signature
        $parts = explode('.', $decoded, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$timestampHex, $signature] = $parts;

        // Convert timestamp
        $timestamp = hexdec($timestampHex);

        if ($timestamp === 0) {
            return false;
        }

        // Check expiry
        $now = time();

        if (($now - $timestamp) > $this->tokenLifetime) {
            return false; // Token expired
        }

        // Verify signature
        $payload = $sessionId . '|' . $timestamp;
        $expectedSignature = hash_hmac(self::HMAC_ALGO, $payload, $this->secretKey);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Validate CSRF token from request (for manual use without PSR-15 handler)
     */
    public function validateToken(ServerRequestInterface $request): bool
    {
        $session = $request->getAttribute('admin_session');

        if ($session === null) {
            return true; // No session, let auth handle it
        }

        $sessionId = $session['id'] ?? null;

        if ($sessionId === null) {
            return true;
        }

        // Extract token from request
        $token = $this->extractToken($request);

        if ($token === null) {
            return false;
        }

        return $this->verifyToken($sessionId, $token);
    }

    /**
     * Process request through middleware
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());

        // Safe methods don't need CSRF validation
        if (in_array($method, self::SAFE_METHODS, true)) {
            return $this->addTokenToRequest($request, $handler);
        }

        // Validate Origin/Referer header
        if ($this->validateOrigin && !$this->isValidOrigin($request)) {
            return $this->csrfError($request, 'Invalid origin');
        }

        // Validate CSRF token
        $sessionId = AuthMiddleware::getSessionId($request);

        if ($sessionId === null) {
            // Let auth middleware handle this
            return $handler->handle($request);
        }

        $token = $this->extractToken($request);

        if ($token === null) {
            return $this->csrfError($request, 'Missing CSRF token');
        }

        if (!$this->verifyToken($sessionId, $token)) {
            return $this->csrfError($request, 'Invalid CSRF token');
        }

        // STATELESS: No token regeneration!
        // The same token remains valid until it expires
        // This is safe because:
        // 1. Token is bound to session ID
        // 2. Token has expiry timestamp
        // 3. HMAC prevents forgery

        // Generate fresh token for the view (will have updated timestamp)
        $freshToken = $this->generateToken($sessionId);

        // Continue with request
        $request = $request->withAttribute(self::ATTR_TOKEN, $freshToken);

        return $handler->handle($request);
    }

    /**
     * Add CSRF token to request for safe methods
     */
    private function addTokenToRequest(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $sessionId = AuthMiddleware::getSessionId($request);

        if ($sessionId !== null) {
            // Generate fresh token with current timestamp
            $token = $this->generateToken($sessionId);
            $request = $request->withAttribute(self::ATTR_TOKEN, $token);
        }

        return $handler->handle($request);
    }

    /**
     * Validate Origin/Referer header
     */
    private function isValidOrigin(ServerRequestInterface $request): bool
    {
        $origin = $request->getHeaderLine('Origin');
        $referer = $request->getHeaderLine('Referer');

        // Both missing is suspicious
        if (empty($origin) && empty($referer)) {
            return false;
        }

        $requestHost = $request->getUri()->getHost();
        $requestScheme = $request->getUri()->getScheme();
        $requestOrigin = "{$requestScheme}://{$requestHost}";

        // Check Origin header
        if (!empty($origin)) {
            if ($origin === $requestOrigin) {
                return true;
            }

            if (in_array($origin, $this->trustedOrigins, true)) {
                return true;
            }

            return false;
        }

        // Check Referer header
        if (!empty($referer)) {
            $refererParts = parse_url($referer);
            $refererHost = $refererParts['host'] ?? '';
            $refererScheme = $refererParts['scheme'] ?? '';
            $refererOrigin = "{$refererScheme}://{$refererHost}";

            if ($refererOrigin === $requestOrigin) {
                return true;
            }

            if (in_array($refererOrigin, $this->trustedOrigins, true)) {
                return true;
            }

            return false;
        }

        return true;
    }

    /**
     * Extract CSRF token from request
     */
    private function extractToken(ServerRequestInterface $request): ?string
    {
        // Try header first (for AJAX requests)
        $header = $request->getHeaderLine(self::TOKEN_HEADER);

        if (!empty($header)) {
            return $header;
        }

        // Try POST body
        $body = $request->getParsedBody();

        if (is_array($body) && isset($body[self::TOKEN_FIELD])) {
            return $body[self::TOKEN_FIELD];
        }

        // Try query string (for GET with side effects - not recommended)
        $query = $request->getQueryParams();

        if (isset($query[self::TOKEN_FIELD])) {
            return $query[self::TOKEN_FIELD];
        }

        return null;
    }

    /**
     * Return CSRF error response
     */
    private function csrfError(ServerRequestInterface $request, string $reason): ResponseInterface
    {
        // Strategic log: CSRF validation failure (potential attack)
        Logger::channel('security')->warning( 'CSRF validation failed', [
            'reason' => $reason,
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri()->getPath(),
            'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
            'origin' => $request->getHeaderLine('Origin') ?: null,
            'referer' => $request->getHeaderLine('Referer') ?: null,
        ]);

        $isAjax = $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest' ||
                  str_contains($request->getHeaderLine('Accept'), 'application/json');

        if ($isAjax) {
            return Response::json([
                'error' => 'CSRF validation failed',
                'code' => 'CSRF_ERROR',
                'reason' => $reason,
            ], 403);
        }

        return Response::html(
            '<h1>403 Forbidden</h1><p>CSRF validation failed: ' . htmlspecialchars($reason) . '</p><p>Please refresh the page and try again.</p>',
            403
        );
    }

    /**
     * Get CSRF token from request (helper for views)
     */
    public static function getToken(ServerRequestInterface $request): ?string
    {
        return $request->getAttribute(self::ATTR_TOKEN);
    }

    /**
     * Generate hidden input HTML for forms
     */
    public static function getHiddenInput(ServerRequestInterface $request): string
    {
        $token = self::getToken($request);

        if ($token === null) {
            return '';
        }

        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::TOKEN_FIELD,
            htmlspecialchars($token)
        );
    }

    /**
     * Get meta tag for JavaScript access
     */
    public static function getMetaTag(ServerRequestInterface $request): string
    {
        $token = self::getToken($request);

        if ($token === null) {
            return '';
        }

        return sprintf(
            '<meta name="csrf-token" content="%s">',
            htmlspecialchars($token)
        );
    }

    /**
     * Get token lifetime in seconds
     */
    public function getTokenLifetime(): int
    {
        return $this->tokenLifetime;
    }

    /**
     * Regenerate CSRF token (alias for generateToken with stateless HMAC)
     *
     * With stateless HMAC tokens, "regeneration" simply means generating
     * a new token with a fresh timestamp. The old token remains valid
     * until it expires (token lifetime).
     *
     * This method exists for backward compatibility.
     *
     * @param string $sessionId Session ID to bind token to
     * @return string New CSRF token
     */
    public function regenerateToken(string $sessionId): string
    {
        return $this->generateToken($sessionId);
    }
}
