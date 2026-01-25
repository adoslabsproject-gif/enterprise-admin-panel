<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Simple PSR-7 ServerRequest implementation
 *
 * Minimal implementation for admin panel routing.
 * In production, use a full PSR-7 library (nyholm/psr7, guzzle, etc.)
 *
 * @version 1.0.0
 */
final class Request implements ServerRequestInterface
{
    private string $method;
    private UriInterface $uri;
    private array $headers = [];
    private ?StreamInterface $body = null;
    private string $protocolVersion = '1.1';
    private array $serverParams;
    private array $cookieParams;
    private array $queryParams;
    private array $uploadedFiles = [];
    private array|object|null $parsedBody = null;
    private array $attributes = [];

    public function __construct(
        string $method,
        string $uri,
        array $serverParams = [],
        array $queryParams = [],
        array $parsedBody = [],
        array $cookieParams = [],
        array $headers = []
    ) {
        $this->method = strtoupper($method);
        $this->uri = new Uri($uri);
        $this->serverParams = $serverParams;
        $this->queryParams = $queryParams;
        $this->parsedBody = $parsedBody ?: null;
        $this->cookieParams = $cookieParams;

        // Extract headers from server params
        foreach ($serverParams as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $this->headers[strtolower($name)] = [$value];
            }
        }

        // Add explicit headers
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = is_array($value) ? $value : [$value];
        }

        if (isset($serverParams['CONTENT_TYPE'])) {
            $this->headers['content-type'] = [$serverParams['CONTENT_TYPE']];
        }
    }

    /**
     * Create from PHP globals
     */
    public static function fromGlobals(): self
    {
        $uri = ($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://'
            . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . ($_SERVER['REQUEST_URI'] ?? '/');

        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $uri,
            $_SERVER,
            $_GET,
            $_POST,
            $_COOKIE
        );
    }

    // ServerRequestInterface methods

    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->cookieParams = $cookies;
        return $clone;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function withQueryParams(array $query): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->queryParams = $query;
        return $clone;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->uploadedFiles = $uploadedFiles;
        return $clone;
    }

    public function getParsedBody(): array|object|null
    {
        return $this->parsedBody;
    }

    public function withParsedBody($data): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->parsedBody = $data;
        return $clone;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $clone = clone $this;
        unset($clone->attributes[$name]);
        return $clone;
    }

    // RequestInterface methods

    public function getRequestTarget(): string
    {
        $target = $this->uri->getPath();
        $query = $this->uri->getQuery();

        if ($query !== '') {
            $target .= '?' . $query;
        }

        return $target ?: '/';
    }

    public function withRequestTarget(string $requestTarget): static
    {
        $clone = clone $this;
        // Parse and update URI if needed
        return $clone;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): static
    {
        $clone = clone $this;
        $clone->method = strtoupper($method);
        return $clone;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $clone = clone $this;
        $clone->uri = $uri;
        return $clone;
    }

    // MessageInterface methods

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): static
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
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): static
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = is_array($value) ? $value : [$value];
        return $clone;
    }

    public function withAddedHeader(string $name, $value): static
    {
        $clone = clone $this;
        $lower = strtolower($name);

        if (!isset($clone->headers[$lower])) {
            $clone->headers[$lower] = [];
        }

        $clone->headers[$lower] = array_merge(
            $clone->headers[$lower],
            is_array($value) ? $value : [$value]
        );

        return $clone;
    }

    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        unset($clone->headers[strtolower($name)]);
        return $clone;
    }

    public function getBody(): StreamInterface
    {
        if ($this->body === null) {
            $this->body = new StringStream('');
        }
        return $this->body;
    }

    public function withBody(StreamInterface $body): static
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }
}
