<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Routing;

/**
 * Route Definition Builder
 *
 * Fluent interface for defining routes in modular route files.
 * This class wraps the Router to provide a clean DSL for route definition.
 *
 * Usage in route files:
 *   return function (RouteDefinition $routes): void {
 *       $routes->get('/login', 'AuthController@loginForm')
 *           ->name('auth.login')
 *           ->middleware('guest');
 *   };
 *
 * @version 1.0.0
 */
final class RouteDefinition
{
    /** @var Route|null Last registered route for chaining */
    private ?Route $lastRoute = null;

    /** @var string Current group prefix */
    private string $groupPrefix = '';

    /** @var array<string> Current group middleware */
    private array $groupMiddleware = [];

    /** @var string Controller namespace prefix */
    private string $controllerNamespace = 'AdosLabs\\AdminPanel\\Controllers\\';

    public function __construct(
        private Router $router,
        private string $basePath = ''
    ) {
    }

    /**
     * Register a GET route
     */
    public function get(string $path, string|callable|array $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route
     */
    public function post(string $path, string|callable|array $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register a PUT route
     */
    public function put(string $path, string|callable|array $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Register a DELETE route
     */
    public function delete(string $path, string|callable|array $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Register a PATCH route
     */
    public function patch(string $path, string|callable|array $handler): self
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Set route name (for URL generation)
     */
    public function name(string $name): self
    {
        if ($this->lastRoute !== null) {
            $this->lastRoute->name($name);
            $this->router->registerNamedRoute($name, $this->lastRoute);
        }
        return $this;
    }

    /**
     * Add middleware to current route
     */
    public function middleware(string|callable ...$middleware): self
    {
        if ($this->lastRoute !== null) {
            // Convert string middleware names to callables
            $callables = array_map(
                fn($m) => is_string($m) ? $this->resolveMiddleware($m) : $m,
                $middleware
            );
            $this->lastRoute->middleware(...$callables);
        }
        return $this;
    }

    /**
     * Create a route group with shared prefix and/or middleware
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
     * Register route for multiple HTTP methods
     */
    public function match(array $methods, string $path, string|callable|array $handler): self
    {
        foreach ($methods as $method) {
            $this->addRoute(strtoupper($method), $path, $handler);
        }
        return $this;
    }

    /**
     * Register route for all common HTTP methods
     */
    public function any(string $path, string|callable|array $handler): self
    {
        return $this->match(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], $path, $handler);
    }

    /**
     * Set the controller namespace for string handlers
     */
    public function setControllerNamespace(string $namespace): self
    {
        $this->controllerNamespace = $namespace;
        return $this;
    }

    /**
     * Add a route to the router
     */
    private function addRoute(string $method, string $path, string|callable|array $handler): self
    {
        $fullPath = $this->basePath . $this->groupPrefix . $path;
        $resolvedHandler = $this->resolveHandler($handler);

        // Build middleware list: group middleware will be added
        $middleware = array_map(
            fn($m) => is_string($m) ? $this->resolveMiddleware($m) : $m,
            $this->groupMiddleware
        );

        $this->lastRoute = new Route($method, $fullPath, $resolvedHandler, $middleware);

        // Register with the underlying router
        $routerRoute = match ($method) {
            'GET' => $this->router->get($fullPath, $resolvedHandler),
            'POST' => $this->router->post($fullPath, $resolvedHandler),
            'PUT' => $this->router->put($fullPath, $resolvedHandler),
            'DELETE' => $this->router->delete($fullPath, $resolvedHandler),
            'PATCH' => $this->router->patch($fullPath, $resolvedHandler),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

        // Apply group middleware
        if (!empty($middleware)) {
            $routerRoute->middleware(...$middleware);
        }

        $this->lastRoute = $routerRoute;

        return $this;
    }

    /**
     * Resolve handler from string notation
     *
     * Supports:
     * - 'Controller@method'
     * - 'Namespace\Controller@method'
     * - [Controller::class, 'method']
     * - callable
     */
    private function resolveHandler(string|callable|array $handler): array|callable
    {
        if (is_callable($handler)) {
            return $handler;
        }

        if (is_array($handler)) {
            return $handler;
        }

        // Parse 'Controller@method' notation
        if (is_string($handler) && str_contains($handler, '@')) {
            [$controller, $method] = explode('@', $handler, 2);

            // Add namespace if not fully qualified
            if (!str_contains($controller, '\\')) {
                $controller = $this->controllerNamespace . $controller;
            }

            return [$controller, $method];
        }

        throw new \InvalidArgumentException("Invalid handler format: " . print_r($handler, true));
    }

    /**
     * Resolve middleware name to callable
     */
    private function resolveMiddleware(string $name): callable
    {
        // Map middleware names to classes
        $middlewareMap = [
            'auth' => \AdosLabs\AdminPanel\Middleware\AuthMiddleware::class,
            'csrf' => \AdosLabs\AdminPanel\Middleware\CsrfMiddleware::class,
            'guest' => \AdosLabs\AdminPanel\Middleware\GuestMiddleware::class,
        ];

        $class = $middlewareMap[$name] ?? null;

        if ($class === null) {
            throw new \InvalidArgumentException("Unknown middleware: {$name}");
        }

        // Return a callable that creates middleware instance
        // Actual instantiation happens at dispatch time with DI
        return static fn($request, $next) => (new $class())->handle($request, $next);
    }
}
