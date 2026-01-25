<?php
/**
 * Enterprise Admin Panel - Service Container
 *
 * Lightweight dependency injection container.
 * Optimized for speed with lazy loading.
 *
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Core;

use Closure;
use InvalidArgumentException;

final class Container
{
    /**
     * Registered bindings (factories)
     * @var array<string, Closure>
     */
    private static array $bindings = [];

    /**
     * Resolved singleton instances
     * @var array<string, object>
     */
    private static array $instances = [];

    /**
     * Singleton flags
     * @var array<string, bool>
     */
    private static array $singletons = [];

    /**
     * Register a binding
     *
     * @param string $abstract Service name
     * @param Closure|string|null $concrete Factory or class name
     * @param bool $singleton Whether to cache instance
     */
    public static function bind(string $abstract, Closure|string|null $concrete = null, bool $singleton = false): void
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }

        if (is_string($concrete)) {
            $className = $concrete;
            $concrete = fn() => new $className();
        }

        self::$bindings[$abstract] = $concrete;
        self::$singletons[$abstract] = $singleton;

        // Clear cached instance if re-binding
        unset(self::$instances[$abstract]);
    }

    /**
     * Register a singleton binding
     *
     * @param string $abstract Service name
     * @param Closure|string|null $concrete Factory or class name
     */
    public static function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        self::bind($abstract, $concrete, true);
    }

    /**
     * Register an existing instance
     *
     * @param string $abstract Service name
     * @param object $instance Instance to register
     */
    public static function instance(string $abstract, object $instance): void
    {
        self::$instances[$abstract] = $instance;
        self::$singletons[$abstract] = true;
    }

    /**
     * Resolve a service
     *
     * @param string $abstract Service name
     * @return mixed
     * @throws InvalidArgumentException If service not found
     */
    public static function get(string $abstract): mixed
    {
        // Return cached singleton
        if (isset(self::$instances[$abstract])) {
            return self::$instances[$abstract];
        }

        // Check if binding exists
        if (!isset(self::$bindings[$abstract])) {
            // Try auto-resolution for classes
            if (class_exists($abstract)) {
                self::bind($abstract, $abstract, false);
            } else {
                throw new InvalidArgumentException("Service not found: {$abstract}");
            }
        }

        // Resolve
        $concrete = self::$bindings[$abstract];
        $instance = $concrete(self::class);

        // Cache if singleton
        if (self::$singletons[$abstract] ?? false) {
            self::$instances[$abstract] = $instance;
        }

        return $instance;
    }

    /**
     * Check if service is registered
     *
     * @param string $abstract Service name
     * @return bool
     */
    public static function has(string $abstract): bool
    {
        return isset(self::$bindings[$abstract]) || isset(self::$instances[$abstract]);
    }

    /**
     * Remove a service
     *
     * @param string $abstract Service name
     */
    public static function forget(string $abstract): void
    {
        unset(
            self::$bindings[$abstract],
            self::$instances[$abstract],
            self::$singletons[$abstract]
        );
    }

    /**
     * Clear all bindings and instances
     */
    public static function flush(): void
    {
        self::$bindings = [];
        self::$instances = [];
        self::$singletons = [];
    }

    /**
     * Get all registered service names
     *
     * @return array<string>
     */
    public static function getBindings(): array
    {
        return array_keys(self::$bindings);
    }
}
