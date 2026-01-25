<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Routing;

use Psr\Http\Message\ServerRequestInterface;
use AdosLabs\AdminPanel\Http\Response;

/**
 * Enterprise Router
 *
 * Modern, modular routing system with:
 * - Route groups with prefixes
 * - Middleware support
 * - Named routes
 * - Pattern matching
 * - Route parameters
 *
 * @version 1.0.0
 */
final class Router
{
    /** @var array<string, Route> Named routes */
    private array $namedRoutes = [];

    /** @var array<Route> All routes */
    private array $routes = [];

    /** @var array<callable> Global middleware */
    private array $middleware = [];

    /** @var string Current group prefix */
    private string $groupPrefix = '';

    /** @var array<callable> Current group middleware */
    private array $groupMiddleware = [];

    /**
     * Register a GET route
     */
    public function get(string $path, callable|array $handler): Route
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route
     */
    public function post(string $path, callable|array $handler): Route
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register a PUT route
     */
    public function put(string $path, callable|array $handler): Route
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Register a DELETE route
     */
    public function delete(string $path, callable|array $handler): Route
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Register a PATCH route
     */
    public function patch(string $path, callable|array $handler): Route
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Register route for multiple methods
     */
    public function match(array $methods, string $path, callable|array $handler): Route
    {
        $route = null;
        foreach ($methods as $method) {
            $route = $this->addRoute(strtoupper($method), $path, $handler);
        }
        return $route;
    }

    /**
     * Register route for all methods
     */
    public function any(string $path, callable|array $handler): Route
    {
        return $this->match(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], $path, $handler);
    }

    /**
     * Create a route group
     */
    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix = $previousPrefix . $prefix;
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    /**
     * Add global middleware
     */
    public function use(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Match request to route and dispatch
     */
    public function dispatch(ServerRequestInterface $request): ?Response
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        foreach ($this->routes as $route) {
            if ($route->matches($method, $path)) {
                $params = $route->extractParams($path);

                // Add route params to request
                foreach ($params as $name => $value) {
                    $request = $request->withAttribute($name, $value);
                }

                return $this->executeRoute($route, $request);
            }
        }

        return null; // No route matched
    }

    /**
     * Get route by name
     */
    public function route(string $name, array $params = []): ?string
    {
        if (!isset($this->namedRoutes[$name])) {
            return null;
        }

        return $this->namedRoutes[$name]->generateUrl($params);
    }

    /**
     * Get all routes (for debugging/module registration)
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Add a route
     */
    private function addRoute(string $method, string $path, callable|array $handler): Route
    {
        $fullPath = $this->groupPrefix . $path;
        $route = new Route($method, $fullPath, $handler, $this->groupMiddleware);

        $this->routes[] = $route;

        return $route;
    }

    /**
     * Execute route with middleware chain
     */
    private function executeRoute(Route $route, ServerRequestInterface $request): Response
    {
        // Build middleware chain: global -> group -> route
        $middlewareChain = array_merge(
            $this->middleware,
            $route->getMiddleware()
        );

        // Create the final handler
        $handler = fn(ServerRequestInterface $req) => $route->execute($req);

        // Wrap with middleware (reverse order)
        foreach (array_reverse($middlewareChain) as $middleware) {
            $next = $handler;
            $handler = fn(ServerRequestInterface $req) => $middleware($req, $next);
        }

        return $handler($request);
    }

    /**
     * Register named route
     */
    public function registerNamedRoute(string $name, Route $route): void
    {
        $this->namedRoutes[$name] = $route;
    }
}
