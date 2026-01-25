<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use AdosLabs\AdminPanel\Http\Response;

/**
 * HTTPS Enforcement Middleware
 *
 * Forces HTTPS in production environments and adds security headers:
 * - Redirects HTTP to HTTPS (configurable)
 * - Adds HSTS header (HTTP Strict Transport Security)
 * - Adds security headers for XSS, clickjacking, MIME sniffing protection
 *
 * HSTS tells browsers to ONLY connect via HTTPS for a specified duration.
 * Once set, browsers will refuse HTTP connections even if user types http://
 *
 * @version 1.0.0
 */
final class HttpsMiddleware implements MiddlewareInterface
{
    /**
     * Force redirect HTTP to HTTPS
     */
    private bool $forceHttps;

    /**
     * Enable HSTS header
     */
    private bool $enableHsts;

    /**
     * HSTS max-age in seconds (default: 1 year)
     */
    private int $hstsMaxAge;

    /**
     * Include subdomains in HSTS
     */
    private bool $hstsIncludeSubdomains;

    /**
     * Add to HSTS preload list (requires max-age >= 1 year + includeSubDomains)
     */
    private bool $hstsPreload;

    /**
     * Hosts to exclude from HTTPS enforcement (e.g., localhost)
     */
    private array $excludedHosts;

    public function __construct(
        bool $forceHttps = true,
        bool $enableHsts = true,
        int $hstsMaxAge = 31536000, // 1 year
        bool $hstsIncludeSubdomains = true,
        bool $hstsPreload = false,
        array $excludedHosts = ['localhost', '127.0.0.1', '::1']
    ) {
        $this->forceHttps = $forceHttps;
        $this->enableHsts = $enableHsts;
        $this->hstsMaxAge = $hstsMaxAge;
        $this->hstsIncludeSubdomains = $hstsIncludeSubdomains;
        $this->hstsPreload = $hstsPreload;
        $this->excludedHosts = $excludedHosts;
    }

    /**
     * Create from environment variables
     *
     * Reads configuration from:
     * - FORCE_HTTPS=true|false (default: true in production)
     * - HSTS_ENABLED=true|false (default: true)
     * - HSTS_MAX_AGE=seconds (default: 31536000)
     * - HSTS_INCLUDE_SUBDOMAINS=true|false (default: true)
     * - HSTS_PRELOAD=true|false (default: false)
     */
    public static function fromEnv(): self
    {
        $env = getenv('APP_ENV') ?: 'production';
        $isProduction = $env === 'production';

        return new self(
            forceHttps: filter_var(getenv('FORCE_HTTPS') ?: ($isProduction ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN),
            enableHsts: filter_var(getenv('HSTS_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
            hstsMaxAge: (int) (getenv('HSTS_MAX_AGE') ?: 31536000),
            hstsIncludeSubdomains: filter_var(getenv('HSTS_INCLUDE_SUBDOMAINS') ?: 'true', FILTER_VALIDATE_BOOLEAN),
            hstsPreload: filter_var(getenv('HSTS_PRELOAD') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $uri = $request->getUri();
        $host = $uri->getHost();

        // Check if host is excluded (localhost, etc.)
        $isExcluded = $this->isHostExcluded($host);

        // Check if request is HTTPS
        $isHttps = $this->isHttps($request);

        // Redirect HTTP to HTTPS if enabled and not excluded
        if ($this->forceHttps && !$isHttps && !$isExcluded) {
            $httpsUrl = (string) $uri->withScheme('https')->withPort(443);

            return Response::redirect($httpsUrl, 301)
                ->withAddedHeader('Cache-Control', 'no-cache');
        }

        // Process the request
        $response = $handler->handle($request);

        // Add security headers
        $response = $this->addSecurityHeaders($response, $isHttps, $isExcluded);

        return $response;
    }

    /**
     * Check if current request is HTTPS
     */
    private function isHttps(ServerRequestInterface $request): bool
    {
        $serverParams = $request->getServerParams();

        // Direct HTTPS
        if (!empty($serverParams['HTTPS']) && $serverParams['HTTPS'] !== 'off') {
            return true;
        }

        // Behind reverse proxy (Nginx, Cloudflare, AWS ALB, etc.)
        $forwardedProto = $request->getHeaderLine('X-Forwarded-Proto');
        if (strtolower($forwardedProto) === 'https') {
            return true;
        }

        // Cloudflare specific
        $cfVisitor = $request->getHeaderLine('CF-Visitor');
        if ($cfVisitor && str_contains($cfVisitor, '"scheme":"https"')) {
            return true;
        }

        // Request scheme
        if (($serverParams['REQUEST_SCHEME'] ?? '') === 'https') {
            return true;
        }

        // Server port
        if (($serverParams['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        return false;
    }

    /**
     * Check if host is excluded from HTTPS enforcement
     */
    private function isHostExcluded(string $host): bool
    {
        // Remove port from host
        $host = preg_replace('/:\d+$/', '', $host);

        foreach ($this->excludedHosts as $excluded) {
            if (strcasecmp($host, $excluded) === 0) {
                return true;
            }

            // Support wildcard patterns like *.localhost
            if (str_starts_with($excluded, '*.')) {
                $pattern = substr($excluded, 2);
                if (str_ends_with($host, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Add security headers to response
     */
    private function addSecurityHeaders(ResponseInterface $response, bool $isHttps, bool $isExcluded): ResponseInterface
    {
        // HSTS - Only on HTTPS connections and not excluded hosts
        if ($this->enableHsts && $isHttps && !$isExcluded) {
            $hsts = 'max-age=' . $this->hstsMaxAge;

            if ($this->hstsIncludeSubdomains) {
                $hsts .= '; includeSubDomains';
            }

            if ($this->hstsPreload) {
                $hsts .= '; preload';
            }

            $response = $response->withAddedHeader('Strict-Transport-Security', $hsts);
        }

        // X-Content-Type-Options - Prevent MIME type sniffing
        $response = $response->withAddedHeader('X-Content-Type-Options', 'nosniff');

        // X-Frame-Options - Prevent clickjacking
        // Note: CSP frame-ancestors is more flexible, but this provides fallback
        if (!$response->hasHeader('X-Frame-Options')) {
            $response = $response->withAddedHeader('X-Frame-Options', 'SAMEORIGIN');
        }

        // X-XSS-Protection - Legacy XSS protection (modern browsers use CSP)
        $response = $response->withAddedHeader('X-XSS-Protection', '1; mode=block');

        // Referrer-Policy - Control referrer information
        $response = $response->withAddedHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions-Policy - Restrict browser features
        $response = $response->withAddedHeader(
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()'
        );

        return $response;
    }
}
