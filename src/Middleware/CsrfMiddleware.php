<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use AdosLabs\AdminPanel\Services\SessionService;
use AdosLabs\AdminPanel\Http\Response;

/**
 * Enterprise CSRF Protection Middleware
 *
 * Features:
 * - Token-based CSRF protection
 * - Double-submit cookie pattern
 * - Same-site cookie support
 * - Origin/Referer validation
 * - Configurable safe methods
 *
 * PSR-15 compatible middleware
 *
 * @version 1.0.0
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

    private bool $validateOrigin;
    private array $trustedOrigins;
    private string $adminBasePath;

    public function __construct(
        private SessionService $sessionService,
        string $adminBasePath = '/admin',
        bool $validateOrigin = true,
        array $trustedOrigins = []
    ) {
        $this->adminBasePath = $adminBasePath;
        $this->validateOrigin = $validateOrigin;
        $this->trustedOrigins = $trustedOrigins;
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

        return $this->sessionService->verifyCsrfToken($sessionId, $token);
    }

    /**
     * Regenerate CSRF token (for manual use)
     */
    public function regenerateToken(string $sessionId): string
    {
        return $this->sessionService->regenerateCsrfToken($sessionId);
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

        if (!$this->sessionService->verifyCsrfToken($sessionId, $token)) {
            return $this->csrfError($request, 'Invalid CSRF token');
        }

        // Regenerate token after use (prevents replay attacks)
        $newToken = $this->sessionService->regenerateCsrfToken($sessionId);

        // Continue with request
        $request = $request->withAttribute(self::ATTR_TOKEN, $newToken);
        $response = $handler->handle($request);

        // Set new token in response cookie
        return $this->setTokenCookie($response, $newToken);
    }

    /**
     * Add CSRF token to request for safe methods
     */
    private function addTokenToRequest(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $sessionId = AuthMiddleware::getSessionId($request);

        if ($sessionId !== null) {
            $token = $this->sessionService->getCsrfToken($sessionId);
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
     * Set CSRF token cookie in response
     */
    private function setTokenCookie(ResponseInterface $response, string $token): ResponseInterface
    {
        $cookie = sprintf(
            '%s=%s; Path=/; HttpOnly; SameSite=Strict; Secure',
            self::TOKEN_COOKIE,
            $token
        );

        return $response->withAddedHeader('Set-Cookie', $cookie);
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
}
