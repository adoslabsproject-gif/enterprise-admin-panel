<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Routing;

use Psr\Http\Message\ServerRequestInterface;
use AdosLabs\AdminPanel\Http\Response;

/**
 * Route Definition
 *
 * Represents a single route with:
 * - HTTP method
 * - Path pattern (supports {param} placeholders)
 * - Handler (callable or [Controller, method])
 * - Middleware
 * - Name (for URL generation)
 *
 * @version 1.0.0
 */
final class Route
{
    private ?string $name = null;
    private string $pattern;
    private array $paramNames = [];

    /**
     * @param string $method HTTP method
     * @param string $path URL path (can contain {param} placeholders)
     * @param callable|array $handler Route handler
     * @param array<callable> $middleware Route-specific middleware
     */
    public function __construct(
        private string $method,
        private string $path,
        private mixed $handler,
        private array $middleware = []
    ) {
        $this->compilePattern();
    }

    /**
     * Set route name (for URL generation)
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Add middleware to this route
     */
    public function middleware(callable ...$middleware): self
    {
        $this->middleware = array_merge($this->middleware, $middleware);
        return $this;
    }

    /**
     * Get route name
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Get route middleware
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Check if route matches request
     */
    public function matches(string $method, string $path): bool
    {
        if ($this->method !== $method) {
            return false;
        }

        return (bool) preg_match($this->pattern, $path);
    }

    /**
     * Extract parameters from path
     */
    public function extractParams(string $path): array
    {
        $params = [];

        if (preg_match($this->pattern, $path, $matches)) {
            foreach ($this->paramNames as $name) {
                if (isset($matches[$name])) {
                    $params[$name] = $matches[$name];
                }
            }
        }

        return $params;
    }

    /**
     * Generate URL from route with parameters
     */
    public function generateUrl(array $params = []): string
    {
        $url = $this->path;

        foreach ($params as $name => $value) {
            $url = str_replace("{{$name}}", (string) $value, $url);
        }

        return $url;
    }

    /**
     * Execute the route handler
     */
    public function execute(ServerRequestInterface $request): Response
    {
        $handler = $this->handler;

        // Array handler: [ControllerClass, method]
        if (is_array($handler) && count($handler) === 2) {
            [$controllerClass, $method] = $handler;

            // Controller will be instantiated by the caller with DI
            // Here we just return the handler info for now
            if (is_string($controllerClass)) {
                throw new \RuntimeException(
                    "Controller instantiation should be handled by the router dispatcher. " .
                    "Use a factory or container."
                );
            }

            // If controller instance is passed
            if (is_object($controllerClass)) {
                return $controllerClass->$method($request);
            }
        }

        // Callable handler
        if (is_callable($handler)) {
            $result = $handler($request);

            if ($result instanceof Response) {
                return $result;
            }

            // Convert other return types to Response
            if (is_string($result)) {
                return Response::html($result);
            }

            if (is_array($result)) {
                return Response::json($result);
            }

            throw new \RuntimeException('Route handler must return Response, string, or array');
        }

        throw new \RuntimeException('Invalid route handler');
    }

    /**
     * Compile path to regex pattern
     */
    private function compilePattern(): void
    {
        // Escape regex special chars except { }
        $pattern = preg_quote($this->path, '#');

        // Replace {param} with named capture groups
        $pattern = preg_replace_callback(
            '/\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\}/',
            function ($matches) {
                $name = $matches[1];
                $this->paramNames[] = $name;
                return "(?P<{$name}>[^/]+)";
            },
            $pattern
        );

        // Optional trailing slash
        $this->pattern = '#^' . $pattern . '/?$#';
    }

    /**
     * Get raw path
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get HTTP method
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get handler
     */
    public function getHandler(): mixed
    {
        return $this->handler;
    }
}
