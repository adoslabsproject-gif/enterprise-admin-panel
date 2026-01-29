<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Enterprise HTTP Response Factory
 *
 * Provides static methods for creating common response types.
 * Works with any PSR-7 implementation.
 *
 * @version 1.0.0
 */
final class Response implements ResponseInterface
{
    private int $statusCode;
    private string $reasonPhrase;
    private array $headers;
    private ?StreamInterface $body;
    private string $bodyContent;
    private string $protocolVersion = '1.1';

    private function __construct(int $statusCode = 200, array $headers = [], string $body = '')
    {
        $this->statusCode = $statusCode;
        $this->reasonPhrase = $this->getReasonPhraseForStatus($statusCode);
        $this->headers = $headers;
        $this->bodyContent = $body;
        $this->body = null;
    }

    /**
     * Create JSON response
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers['Content-Type'] = ['application/json; charset=utf-8'];

        return new self($status, $headers, $body);
    }

    /**
     * Create HTML response
     */
    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        $headers['Content-Type'] = ['text/html; charset=utf-8'];

        return new self($status, $headers, $html);
    }

    /**
     * Create redirect response
     */
    public static function redirect(string $url, int $status = 302, array $headers = []): self
    {
        $headers['Location'] = [$url];

        return new self($status, $headers, '');
    }

    /**
     * Create empty response
     */
    public static function empty(int $status = 204, array $headers = []): self
    {
        return new self($status, $headers, '');
    }

    /**
     * Create file download response
     *
     * ENTERPRISE: RFC 5987 compliant Content-Disposition header.
     * Includes both ASCII fallback and UTF-8 encoded filename for
     * proper handling of international characters in filenames.
     */
    public static function download(string $content, string $filename, string $contentType = 'application/octet-stream'): self
    {
        // Sanitize filename: remove path separators and null bytes
        $filename = str_replace(['/', '\\', "\0"], '', $filename);

        // Create ASCII-safe fallback (replace non-ASCII with underscore)
        $asciiFilename = preg_replace('/[^\x20-\x7E]/', '_', $filename);
        $asciiFilename = str_replace('"', '\\"', $asciiFilename);

        // RFC 5987: UTF-8 encoded filename for modern browsers
        // Format: filename*=UTF-8''url_encoded_filename
        $utf8Filename = rawurlencode($filename);

        // Content-Disposition with both fallback and UTF-8 filename
        // Modern browsers use filename*, older ones fall back to filename
        $disposition = "attachment; filename=\"{$asciiFilename}\"; filename*=UTF-8''{$utf8Filename}";

        $headers = [
            'Content-Type' => [$contentType],
            'Content-Disposition' => [$disposition],
            'Content-Length' => [(string) strlen($content)],
        ];

        return new self(200, $headers, $content);
    }

    /**
     * Create error response
     */
    public static function error(string $message, int $status = 500, string $code = 'ERROR'): self
    {
        return self::json([
            'error' => $message,
            'code' => $code,
        ], $status);
    }

    /**
     * Create success response
     */
    public static function success(mixed $data = null, string $message = 'Success'): self
    {
        return self::json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    // PSR-7 ResponseInterface implementation

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->reasonPhrase = $reasonPhrase ?: $this->getReasonPhraseForStatus($code);

        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): ResponseInterface
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;

        return $clone;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]) || isset($this->headers[$name]);
    }

    public function getHeader(string $name): array
    {
        $lower = strtolower($name);

        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $lower) {
                return is_array($value) ? $value : [$value];
            }
        }

        return [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): ResponseInterface
    {
        $clone = clone $this;
        $clone->headers[$name] = is_array($value) ? $value : [$value];

        return $clone;
    }

    public function withAddedHeader(string $name, $value): ResponseInterface
    {
        $clone = clone $this;

        if (!isset($clone->headers[$name])) {
            $clone->headers[$name] = [];
        }

        if (is_array($value)) {
            $clone->headers[$name] = array_merge($clone->headers[$name], $value);
        } else {
            $clone->headers[$name][] = $value;
        }

        return $clone;
    }

    public function withoutHeader(string $name): ResponseInterface
    {
        $clone = clone $this;
        unset($clone->headers[$name]);

        return $clone;
    }

    public function getBody(): StreamInterface
    {
        if ($this->body === null) {
            $this->body = new StringStream($this->bodyContent);
        }

        return $this->body;
    }

    public function withBody(StreamInterface $body): ResponseInterface
    {
        $clone = clone $this;
        $clone->body = $body;
        $clone->bodyContent = (string) $body;

        return $clone;
    }

    /**
     * Add security headers to response
     *
     * Achieves A+ rating on securityheaders.com when served over HTTPS
     */
    public function withSecurityHeaders(bool $isHttps = false): self
    {
        $response = $this
            // Prevent caching - critical for security (no back button after logout)
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT')
            // Security headers
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-XSS-Protection', '0') // Disabled - can cause XSS in old browsers, CSP is better
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Resource-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Embedder-Policy', 'require-corp')
            ->withHeader('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self'",
                "img-src 'self' data:",
                "font-src 'self'",
                "connect-src 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "base-uri 'self'",
                "object-src 'none'",
                "upgrade-insecure-requests",
            ]));

        // HSTS - only add for HTTPS connections (required for A+)
        if ($isHttps) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        return $response;
    }

    /**
     * Send response to client (for non-framework usage)
     */
    public function send(): void
    {
        // Send status
        http_response_code($this->statusCode);

        // Send headers
        foreach ($this->headers as $name => $values) {
            foreach ((array) $values as $value) {
                header("{$name}: {$value}", false);
            }
        }

        // Send body
        echo $this->bodyContent;
    }

    /**
     * Get standard reason phrase for status code
     */
    private function getReasonPhraseForStatus(int $code): string
    {
        return match ($code) {
            100 => 'Continue',
            101 => 'Switching Protocols',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            307 => 'Temporary Redirect',
            308 => 'Permanent Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => '',
        };
    }
}
