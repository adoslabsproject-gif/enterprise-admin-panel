<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Controllers;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use AdosLabs\AdminPanel\Core\ModuleRegistry;
use AdosLabs\AdminPanel\Http\Response;
use AdosLabs\AdminPanel\Middleware\AuthMiddleware;
use AdosLabs\AdminPanel\Middleware\CsrfMiddleware;
use AdosLabs\AdminPanel\Services\AuditService;
use AdosLabs\AdminPanel\Services\ConfigService;
use AdosLabs\AdminPanel\Services\SessionService;

/**
 * Base Controller
 *
 * Provides common functionality for all admin controllers:
 * - View rendering
 * - Response helpers
 * - User/session access
 * - Validation helpers
 * - Audit logging
 *
 * @version 1.0.0
 */
abstract class BaseController
{
    protected ?ServerRequestInterface $request = null;
    protected ?string $viewsPath = null;
    protected ?ModuleRegistry $moduleRegistry = null;
    protected ?ConfigService $configService = null;

    public function __construct(
        protected DatabasePool $db,
        protected SessionService $sessionService,
        protected AuditService $auditService,
        protected ?LoggerInterface $logger = null,
        ?ModuleRegistry $moduleRegistry = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->viewsPath = dirname(__DIR__) . '/Views';
        $this->moduleRegistry = $moduleRegistry;
    }

    /**
     * Set module registry (for view resolution across modules)
     */
    public function setModuleRegistry(ModuleRegistry $registry): void
    {
        $this->moduleRegistry = $registry;
    }

    /**
     * Set config service (for dynamic URL building)
     */
    public function setConfigService(ConfigService $configService): void
    {
        $this->configService = $configService;
    }

    /**
     * Set current request (called by router)
     */
    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    /**
     * Build admin URL with dynamic base path
     *
     * @param string $relativePath Relative path (e.g., "login", "dashboard")
     * @return string Full URL with cryptographic base (e.g., "/x-abc123/login")
     */
    protected function adminUrl(string $relativePath): string
    {
        if ($this->configService === null) {
            throw new \RuntimeException('ConfigService not set - cannot build admin URL');
        }
        return $this->configService->buildAdminUrl($relativePath);
    }

    /**
     * Get admin base path
     */
    protected function getAdminBasePath(): string
    {
        if ($this->configService === null) {
            throw new \RuntimeException('ConfigService not set');
        }
        return $this->configService->getAdminBasePath();
    }

    /**
     * Get current authenticated user
     */
    protected function getUser(): ?array
    {
        if ($this->request === null) {
            return null;
        }

        return AuthMiddleware::getUser($this->request);
    }

    /**
     * Get current session ID
     */
    protected function getSessionId(): ?string
    {
        if ($this->request === null) {
            return null;
        }

        return AuthMiddleware::getSessionId($this->request);
    }

    /**
     * Get current session data
     */
    protected function getSession(): ?array
    {
        if ($this->request === null) {
            return null;
        }

        return AuthMiddleware::getSession($this->request);
    }

    /**
     * Get CSRF token
     */
    protected function getCsrfToken(): ?string
    {
        if ($this->request === null) {
            return null;
        }

        return CsrfMiddleware::getToken($this->request);
    }

    /**
     * Get client IP address
     */
    protected function getClientIp(): string
    {
        if ($this->request === null) {
            return '0.0.0.0';
        }

        // Check forwarded headers
        $forwardedFor = $this->request->getHeaderLine('X-Forwarded-For');
        if (!empty($forwardedFor)) {
            $ips = explode(',', $forwardedFor);
            return trim($ips[0]);
        }

        $realIp = $this->request->getHeaderLine('X-Real-IP');
        if (!empty($realIp)) {
            return $realIp;
        }

        $serverParams = $this->request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Get client user agent
     */
    protected function getUserAgent(): ?string
    {
        if ($this->request === null) {
            return null;
        }

        return $this->request->getHeaderLine('User-Agent') ?: null;
    }

    /**
     * Get request body (parsed)
     */
    protected function getBody(): array
    {
        if ($this->request === null) {
            return [];
        }

        $body = $this->request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /**
     * Get query parameters
     */
    protected function getQuery(): array
    {
        if ($this->request === null) {
            return [];
        }

        return $this->request->getQueryParams();
    }

    /**
     * Get single input value (body or query)
     */
    protected function input(string $key, mixed $default = null): mixed
    {
        $body = $this->getBody();
        if (isset($body[$key])) {
            return $body[$key];
        }

        $query = $this->getQuery();
        if (isset($query[$key])) {
            return $query[$key];
        }

        return $default;
    }

    /**
     * Render a view template
     *
     * Views are searched in order:
     * 1. Core admin views (this->viewsPath)
     * 2. Module views (via ModuleRegistry)
     *
     * @param string $view View path (e.g., 'dashboard/index' or 'security/dashboard')
     * @param array $data Data to pass to view
     * @param string|null $layout Layout to use (null for default, false for no layout)
     * @return Response
     */
    protected function view(string $view, array $data = [], ?string $layout = 'admin'): Response
    {
        // Add common data (don't overwrite if already set)
        $data['user'] = $data['user'] ?? $this->getUser();
        $data['csrf_token'] = $data['csrf_token'] ?? $this->getCsrfToken();
        $data['csrf_input'] = $data['csrf_input'] ?? ($this->request ? CsrfMiddleware::getHiddenInput($this->request) : '');
        $data['csrf_meta'] = $data['csrf_meta'] ?? ($this->request ? CsrfMiddleware::getMetaTag($this->request) : '');
        $data['admin_base_path'] = $data['admin_base_path'] ?? ($this->configService?->getAdminBasePath() ?? '/admin');

        // Add tabs from modules for sidebar
        if ($this->moduleRegistry !== null) {
            $data['tabs'] = $data['tabs'] ?? $this->moduleRegistry->getTabs();
        }

        // Find view file
        $viewPath = $this->resolveViewPath($view);

        if ($viewPath === null) {
            return Response::error("View not found: {$view}", 500);
        }

        // Render view content using closure to isolate scope and avoid extract() vulnerability
        $content = (static function(string $_viewPath, array $_data): string {
            // Make variables available to view without extract() which can overwrite critical vars
            foreach ($_data as $_key => $_value) {
                // Skip reserved variable names that could compromise security
                if (in_array($_key, ['_viewPath', '_data', '_key', '_value', 'this', 'GLOBALS'], true)) {
                    continue;
                }
                $$_key = $_value;
            }

            ob_start();
            include $_viewPath;
            return ob_get_clean();
        })($viewPath, $data);

        // Wrap in layout if specified
        if ($layout !== null && $layout !== false) {
            $layoutPath = $this->viewsPath . '/layouts/' . $layout . '.php';

            if (file_exists($layoutPath)) {
                $data['content'] = $content;
                // Render layout using closure to isolate scope and avoid extract() vulnerability
                $content = (static function(string $_layoutPath, array $_data): string {
                    // Make variables available to layout without extract()
                    foreach ($_data as $_key => $_value) {
                        // Skip reserved variable names
                        if (in_array($_key, ['_layoutPath', '_data', '_key', '_value', 'this', 'GLOBALS'], true)) {
                            continue;
                        }
                        $$_key = $_value;
                    }

                    ob_start();
                    include $_layoutPath;
                    return ob_get_clean();
                })($layoutPath, $data);
            }
        }

        return Response::html($content);
    }

    /**
     * Resolve view path - search core views first, then modules
     */
    protected function resolveViewPath(string $view): ?string
    {
        // 1. Check core admin views
        $corePath = $this->viewsPath . '/' . $view . '.php';

        if (file_exists($corePath)) {
            return $corePath;
        }

        // 2. Check module views via registry
        if ($this->moduleRegistry !== null) {
            $modulePath = $this->moduleRegistry->findView($view);

            if ($modulePath !== null) {
                return $modulePath;
            }
        }

        return null;
    }

    /**
     * Return JSON response
     */
    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    /**
     * Return success JSON response
     */
    protected function success(mixed $data = null, string $message = 'Success'): Response
    {
        return Response::success($data, $message);
    }

    /**
     * Return error JSON response
     */
    protected function error(string $message, int $status = 400, string $code = 'ERROR'): Response
    {
        return Response::error($message, $status, $code);
    }

    /**
     * Return redirect response
     */
    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    /**
     * Redirect back (using referer)
     */
    protected function back(): Response
    {
        $referer = $this->request?->getHeaderLine('Referer') ?? '/admin/dashboard';

        return Response::redirect($referer);
    }

    /**
     * Set flash message and redirect
     */
    protected function withFlash(string $key, mixed $value, string $redirectTo): Response
    {
        $sessionId = $this->getSessionId();

        if ($sessionId !== null) {
            $this->sessionService->flash($sessionId, $key, $value);
        }

        return $this->redirect($redirectTo);
    }

    /**
     * Get flash message
     */
    protected function getFlash(string $key): mixed
    {
        $sessionId = $this->getSessionId();

        if ($sessionId === null) {
            return null;
        }

        return $this->sessionService->getFlash($sessionId, $key);
    }

    /**
     * Log audit event
     */
    protected function audit(string $action, array $metadata = []): void
    {
        $user = $this->getUser();

        $this->auditService->log(
            $action,
            $user['id'] ?? null,
            $metadata,
            $this->getClientIp(),
            $this->getUserAgent()
        );
    }

    /**
     * Log entity change
     */
    protected function auditEntityChange(
        string $action,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        $user = $this->getUser();

        $this->auditService->logEntityChange(
            $action,
            $user['id'] ?? null,
            $entityType,
            $entityId,
            $oldValues,
            $newValues,
            $this->getClientIp(),
            $this->getUserAgent()
        );
    }

    /**
     * Simple validation
     *
     * @param array $rules Validation rules (field => rules string)
     * @return array{valid: bool, errors: array<string, string>}
     */
    protected function validate(array $rules): array
    {
        $errors = [];
        $body = $this->getBody();

        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $value = $body[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $params = [];

                if (str_contains($rule, ':')) {
                    [$rule, $paramString] = explode(':', $rule, 2);
                    $params = explode(',', $paramString);
                }

                $error = $this->validateRule($field, $value, $rule, $params);

                if ($error !== null) {
                    $errors[$field] = $error;
                    break; // Stop at first error for field
                }
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate single rule
     */
    private function validateRule(string $field, mixed $value, string $rule, array $params): ?string
    {
        return match ($rule) {
            'required' => empty($value) && $value !== '0' && $value !== 0
                ? "The {$field} field is required."
                : null,

            'email' => $value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)
                ? "The {$field} must be a valid email address."
                : null,

            'min' => $value !== null && strlen((string) $value) < (int) ($params[0] ?? 0)
                ? "The {$field} must be at least {$params[0]} characters."
                : null,

            'max' => $value !== null && strlen((string) $value) > (int) ($params[0] ?? PHP_INT_MAX)
                ? "The {$field} may not be greater than {$params[0]} characters."
                : null,

            'numeric' => $value !== null && !is_numeric($value)
                ? "The {$field} must be a number."
                : null,

            'integer' => $value !== null && !filter_var($value, FILTER_VALIDATE_INT)
                ? "The {$field} must be an integer."
                : null,

            'ip' => $value !== null && !filter_var($value, FILTER_VALIDATE_IP)
                ? "The {$field} must be a valid IP address."
                : null,

            'url' => $value !== null && !filter_var($value, FILTER_VALIDATE_URL)
                ? "The {$field} must be a valid URL."
                : null,

            'in' => $value !== null && !in_array($value, $params, true)
                ? "The selected {$field} is invalid."
                : null,

            'confirmed' => $value !== ($this->getBody()[$field . '_confirmation'] ?? null)
                ? "The {$field} confirmation does not match."
                : null,

            default => null,
        };
    }
}
