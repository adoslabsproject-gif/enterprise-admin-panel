<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;

/**
 * Access Log Middleware - PHP-FPM Style Request Logging
 *
 * Logs every HTTP request with detailed information:
 * - Client IP, method, URI, protocol
 * - Response status code and body size
 * - Request duration
 * - User agent, referer
 * - Authenticated user (if any)
 *
 * Performance: ~50μs overhead (memory-only logging)
 *
 * Log format similar to NGINX/Apache combined format but as structured JSON.
 *
 * @version 1.0.0
 */
final class AccessLogMiddleware implements MiddlewareInterface
{
    /**
     * Slow request threshold in milliseconds
     */
    private const SLOW_REQUEST_THRESHOLD_MS = 1000;

    /**
     * Paths to exclude from access logging
     */
    private array $excludedPaths;

    /**
     * Paths to exclude from detailed logging (health checks, etc.)
     */
    private array $minimalLogPaths;

    /**
     * Whether to log request body for POST/PUT/PATCH
     */
    private bool $logRequestBody;

    /**
     * Maximum request body size to log (bytes)
     */
    private int $maxBodyLogSize;

    /**
     * Create middleware instance
     *
     * @param array $excludedPaths Paths to completely exclude (e.g., ['/health', '/metrics'])
     * @param array $minimalLogPaths Paths for minimal logging (e.g., ['/admin/api/heartbeat'])
     * @param bool $logRequestBody Whether to log POST/PUT/PATCH body
     * @param int $maxBodyLogSize Max body size to log in bytes
     */
    public function __construct(
        array $excludedPaths = [],
        array $minimalLogPaths = ['/admin/api/heartbeat', '/admin/api/ping'],
        bool $logRequestBody = false,
        int $maxBodyLogSize = 1024
    ) {
        $this->excludedPaths = $excludedPaths;
        $this->minimalLogPaths = $minimalLogPaths;
        $this->logRequestBody = $logRequestBody;
        $this->maxBodyLogSize = $maxBodyLogSize;
    }

    /**
     * Process request and log access
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // Skip excluded paths entirely
        if ($this->isExcludedPath($path)) {
            return $handler->handle($request);
        }

        // Start timing
        $startTime = hrtime(true);
        $startMemory = memory_get_usage();

        // Process request
        $response = $handler->handle($request);

        // Calculate duration
        $duration = (hrtime(true) - $startTime) / 1_000_000; // Convert to ms
        $memoryUsed = memory_get_usage() - $startMemory;

        // Log the request
        $this->logRequest($request, $response, $duration, $memoryUsed);

        return $response;
    }

    /**
     * Log the HTTP request
     */
    private function logRequest(
        ServerRequestInterface $request,
        ResponseInterface $response,
        float $durationMs,
        int $memoryUsed
    ): void {
        $path = $request->getUri()->getPath();
        $statusCode = $response->getStatusCode();
        $method = $request->getMethod();

        // Minimal logging for high-frequency endpoints
        if ($this->isMinimalLogPath($path)) {
            // Only log errors for these paths
            if ($statusCode >= 400) {
                Logger::channel('default')->warning( "{$method} {$path} {$statusCode}", [
                    'status' => $statusCode,
                    'duration_ms' => round($durationMs, 2),
                ]);
            }
            return;
        }

        // Build context
        $context = $this->buildLogContext($request, $response, $durationMs, $memoryUsed);

        // Determine log level based on response
        $level = $this->determineLogLevel($statusCode, $durationMs);

        // Format message (similar to NGINX combined format)
        $ip = $context['ip'];
        $user = $context['user_id'] ? "user:{$context['user_id']}" : '-';
        $uri = $context['uri'];
        $protocol = $context['protocol'];
        $size = $context['response_size'];
        $referer = $context['referer'] ?: '-';
        $userAgent = $context['user_agent'] ? substr($context['user_agent'], 0, 50) : '-';

        $message = "{$ip} {$user} \"{$method} {$uri} {$protocol}\" {$statusCode} {$size} \"{$referer}\" \"{$userAgent}\"";

        // Log based on level
        switch ($level) {
            case 'error':
                Logger::channel('default')->error( $message, $context);
                break;
            case 'warning':
                Logger::channel('default')->warning( $message, $context);
                // Also log to performance channel if slow
                if ($durationMs >= self::SLOW_REQUEST_THRESHOLD_MS) {
                    Logger::channel('performance')->warning( 'Slow request detected', [
                        'method' => $method,
                        'uri' => $uri,
                        'duration_ms' => round($durationMs, 2),
                        'status' => $statusCode,
                    ]);
                }
                break;
            default:
                Logger::channel('default')->info( $message, $context);
        }
    }

    /**
     * Build structured log context
     */
    private function buildLogContext(
        ServerRequestInterface $request,
        ResponseInterface $response,
        float $durationMs,
        int $memoryUsed
    ): array {
        $serverParams = $request->getServerParams();

        // Get authenticated user if available
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $userId = $user['id'] ?? null;

        // Get response body size
        $body = $response->getBody();
        $responseSize = $body->getSize() ?? 0;

        $context = [
            // Request info
            'method' => $request->getMethod(),
            'uri' => $request->getUri()->getPath(),
            'query' => $request->getUri()->getQuery() ?: null,
            'protocol' => 'HTTP/' . $request->getProtocolVersion(),

            // Client info
            'ip' => $this->getClientIp($request),
            'user_agent' => $request->getHeaderLine('User-Agent') ?: null,
            'referer' => $request->getHeaderLine('Referer') ?: null,

            // Response info
            'status' => $response->getStatusCode(),
            'response_size' => $responseSize,

            // Performance
            'duration_ms' => round($durationMs, 2),
            'memory_bytes' => $memoryUsed,

            // Auth
            'user_id' => $userId,

            // Request ID (if set by upstream)
            'request_id' => $request->getHeaderLine('X-Request-ID') ?: null,
        ];

        // Optionally log request body for mutations
        if ($this->logRequestBody && in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'])) {
            $requestBody = (string) $request->getBody();
            if (strlen($requestBody) <= $this->maxBodyLogSize) {
                // Sanitize sensitive fields
                $context['request_body'] = $this->sanitizeBody($requestBody);
            } else {
                $context['request_body_truncated'] = true;
                $context['request_body_size'] = strlen($requestBody);
            }
        }

        // Filter out null values
        return array_filter($context, fn($v) => $v !== null);
    }

    /**
     * Determine log level based on status code and duration
     */
    private function determineLogLevel(int $statusCode, float $durationMs): string
    {
        // Server errors → ERROR
        if ($statusCode >= 500) {
            return 'error';
        }

        // Client errors (except 404) → WARNING
        if ($statusCode >= 400 && $statusCode !== 404) {
            return 'warning';
        }

        // Slow requests → WARNING
        if ($durationMs >= self::SLOW_REQUEST_THRESHOLD_MS) {
            return 'warning';
        }

        // Everything else → INFO
        return 'info';
    }

    /**
     * Get real client IP (handles proxies)
     */
    private function getClientIp(ServerRequestInterface $request): string
    {
        // Check X-Forwarded-For (load balancer/proxy)
        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
        if (!empty($forwardedFor)) {
            $ips = array_map('trim', explode(',', $forwardedFor));
            return $ips[0]; // First IP is the client
        }

        // Check X-Real-IP (nginx)
        $realIp = $request->getHeaderLine('X-Real-IP');
        if (!empty($realIp)) {
            return $realIp;
        }

        // Check CF-Connecting-IP (Cloudflare)
        $cfIp = $request->getHeaderLine('CF-Connecting-IP');
        if (!empty($cfIp)) {
            return $cfIp;
        }

        // Fallback to REMOTE_ADDR
        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Check if path should be excluded
     */
    private function isExcludedPath(string $path): bool
    {
        foreach ($this->excludedPaths as $excluded) {
            if (str_starts_with($path, $excluded)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if path should have minimal logging
     */
    private function isMinimalLogPath(string $path): bool
    {
        foreach ($this->minimalLogPaths as $minimal) {
            if (str_starts_with($path, $minimal)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sanitize request body (remove sensitive fields)
     */
    private function sanitizeBody(string $body): string
    {
        // Try to parse as JSON
        $decoded = json_decode($body, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Mask sensitive fields
            $sensitiveFields = [
                'password', 'password_confirmation', 'current_password', 'new_password',
                'token', 'api_key', 'secret', 'credit_card', 'cvv', 'ssn',
                'two_factor_code', 'recovery_code', 'otp',
            ];

            array_walk_recursive($decoded, function (&$value, $key) use ($sensitiveFields) {
                if (in_array(strtolower($key), $sensitiveFields)) {
                    $value = '[REDACTED]';
                }
            });

            return json_encode($decoded);
        }

        // For form data, try to parse and sanitize
        parse_str($body, $parsed);
        if (!empty($parsed)) {
            foreach ($parsed as $key => &$value) {
                if (stripos($key, 'password') !== false || stripos($key, 'token') !== false) {
                    $value = '[REDACTED]';
                }
            }
            return http_build_query($parsed);
        }

        return $body;
    }

    /**
     * Get slow request threshold
     */
    public static function getSlowRequestThreshold(): int
    {
        return self::SLOW_REQUEST_THRESHOLD_MS;
    }
}
