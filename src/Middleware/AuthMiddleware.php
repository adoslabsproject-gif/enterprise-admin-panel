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
 * Enterprise Authentication Middleware
 *
 * Features:
 * - Session validation
 * - Activity tracking
 * - Permission checking
 * - Role-based access control
 *
 * PSR-15 compatible middleware
 *
 * @version 1.0.0
 */
final class AuthMiddleware implements MiddlewareInterface
{
    /**
     * Request attribute names
     */
    public const ATTR_USER = 'admin_user';
    public const ATTR_SESSION = 'admin_session';
    public const ATTR_SESSION_ID = 'admin_session_id';

    /**
     * Session cookie/header name
     */
    private const SESSION_COOKIE = 'admin_session';
    private const SESSION_HEADER = 'X-Admin-Session';

    public function __construct(
        private SessionService $sessionService,
        private string $loginUrl = '/admin/login',
        private array $excludedPaths = []
    ) {
    }

    /**
     * Process request through middleware
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // Check if path is excluded (login page, etc.)
        if ($this->isExcludedPath($path)) {
            return $handler->handle($request);
        }

        // Get session ID from cookie or header
        $sessionId = $this->extractSessionId($request);

        if ($sessionId === null) {
            return $this->redirectToLogin($request);
        }

        // Validate session
        $session = $this->sessionService->validate($sessionId);

        if ($session === null) {
            return $this->redirectToLogin($request);
        }

        // Add user and session to request attributes
        $request = $request
            ->withAttribute(self::ATTR_SESSION_ID, $sessionId)
            ->withAttribute(self::ATTR_SESSION, $session)
            ->withAttribute(self::ATTR_USER, [
                'id' => $session['user_id'],
                'email' => $session['email'],
                'name' => $session['name'],
                'role' => $session['role'],
                'permissions' => json_decode($session['permissions'] ?? '[]', true),
                'avatar_url' => $session['avatar_url'] ?? null,
            ]);

        return $handler->handle($request);
    }

    /**
     * Create middleware with required permission check
     *
     * @param string $permission Required permission
     * @return callable Middleware
     */
    public function withPermission(string $permission): callable
    {
        return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($permission): ResponseInterface {
            $user = $request->getAttribute(self::ATTR_USER);

            if ($user === null) {
                return $this->redirectToLogin($request);
            }

            if (!$this->hasPermission($user, $permission)) {
                // Strategic log: permission denied (access control violation attempt)
                Logger::channel('security')->warning( 'Permission denied for user', [
                    'user_id' => $user['id'] ?? null,
                    'email' => $user['email'] ?? null,
                    'role' => $user['role'] ?? null,
                    'required_permission' => $permission,
                    'uri' => (string) $request->getUri()->getPath(),
                    'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
                ]);

                return $this->accessDenied($request, $permission);
            }

            return $handler->handle($request);
        };
    }

    /**
     * Create middleware with required role check
     *
     * @param string|array $roles Required role(s)
     * @return callable Middleware
     */
    public function withRole(string|array $roles): callable
    {
        $roles = is_array($roles) ? $roles : [$roles];

        return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($roles): ResponseInterface {
            $user = $request->getAttribute(self::ATTR_USER);

            if ($user === null) {
                return $this->redirectToLogin($request);
            }

            if (!in_array($user['role'], $roles, true)) {
                // Strategic log: role-based access denial
                Logger::channel('security')->warning( 'Role-based access denied', [
                    'user_id' => $user['id'] ?? null,
                    'email' => $user['email'] ?? null,
                    'user_role' => $user['role'] ?? null,
                    'required_roles' => $roles,
                    'uri' => (string) $request->getUri()->getPath(),
                    'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
                ]);

                return $this->accessDenied($request, 'role:' . implode(',', $roles));
            }

            return $handler->handle($request);
        };
    }

    /**
     * Check if path is excluded from authentication
     */
    private function isExcludedPath(string $path): bool
    {
        // Always exclude login page
        if ($path === $this->loginUrl || $path === $this->loginUrl . '/') {
            return true;
        }

        // Check configured excluded paths
        foreach ($this->excludedPaths as $excluded) {
            if (str_starts_with($path, $excluded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract session ID from request
     */
    private function extractSessionId(ServerRequestInterface $request): ?string
    {
        // Try header first (for API requests)
        $header = $request->getHeaderLine(self::SESSION_HEADER);
        if (!empty($header)) {
            return $header;
        }

        // Try cookie
        $cookies = $request->getCookieParams();
        if (isset($cookies[self::SESSION_COOKIE])) {
            return $cookies[self::SESSION_COOKIE];
        }

        return null;
    }

    /**
     * Check if user has permission
     */
    private function hasPermission(array $user, string $permission): bool
    {
        // Super admin has all permissions
        if ($user['role'] === 'super_admin') {
            return true;
        }

        // Check explicit permissions
        $permissions = $user['permissions'] ?? [];

        if (in_array($permission, $permissions, true)) {
            return true;
        }

        // Check wildcard permissions
        foreach ($permissions as $perm) {
            if (str_ends_with($perm, '*')) {
                $prefix = substr($perm, 0, -1);
                if (str_starts_with($permission, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Redirect to login page
     */
    private function redirectToLogin(ServerRequestInterface $request): ResponseInterface
    {
        $isAjax = $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest' ||
                  str_contains($request->getHeaderLine('Accept'), 'application/json');

        if ($isAjax) {
            return Response::json([
                'error' => 'Authentication required',
                'code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $returnUrl = (string) $request->getUri();

        return Response::redirect($this->loginUrl . '?return=' . urlencode($returnUrl));
    }

    /**
     * Return access denied response
     */
    private function accessDenied(ServerRequestInterface $request, string $required): ResponseInterface
    {
        $isAjax = $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest' ||
                  str_contains($request->getHeaderLine('Accept'), 'application/json');

        if ($isAjax) {
            return Response::json([
                'error' => 'Access denied',
                'code' => 'FORBIDDEN',
                'required' => $required,
            ], 403);
        }

        return Response::html(
            '<h1>403 Forbidden</h1><p>You do not have permission to access this resource.</p>',
            403
        );
    }

    /**
     * Get user from request (helper for controllers)
     */
    public static function getUser(ServerRequestInterface $request): ?array
    {
        return $request->getAttribute(self::ATTR_USER);
    }

    /**
     * Get session from request (helper for controllers)
     */
    public static function getSession(ServerRequestInterface $request): ?array
    {
        return $request->getAttribute(self::ATTR_SESSION);
    }

    /**
     * Get session ID from request (helper for controllers)
     */
    public static function getSessionId(ServerRequestInterface $request): ?string
    {
        return $request->getAttribute(self::ATTR_SESSION_ID);
    }
}
