<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Routing;

use DirectoryIterator;

/**
 * Route Loader
 *
 * Loads route definitions from modular route files.
 * Each route file returns a callable that receives a RouteDefinition instance.
 *
 * Route files are loaded from:
 * - src/Routing/routes/*.php (core routes)
 * - Module-provided routes (via ModuleRegistry)
 *
 * @version 1.0.0
 */
final class RouteLoader
{
    /** @var array<string> Loaded route files */
    private array $loadedFiles = [];

    public function __construct(
        private Router $router,
        private string $basePath = ''
    ) {
    }

    /**
     * Load all route files from a directory
     */
    public function loadDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = [];

        foreach (new DirectoryIterator($directory) as $file) {
            if ($file->isDot() || !$file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        // Sort for consistent loading order
        sort($files);

        foreach ($files as $file) {
            $this->loadFile($file);
        }
    }

    /**
     * Load a single route file
     */
    public function loadFile(string $file): void
    {
        if (!file_exists($file)) {
            throw new \RuntimeException("Route file not found: {$file}");
        }

        // Prevent double loading
        $realPath = realpath($file);
        if (in_array($realPath, $this->loadedFiles, true)) {
            return;
        }

        $this->loadedFiles[] = $realPath;

        // Route files return a callable
        $routeDefinition = require $file;

        if (!is_callable($routeDefinition)) {
            throw new \RuntimeException(
                "Route file must return a callable: {$file}"
            );
        }

        // Create route definition builder
        $routes = new RouteDefinition($this->router, $this->basePath);

        // Execute the route definition
        $routeDefinition($routes);
    }

    /**
     * Load routes with a prefix (for module routes)
     */
    public function loadFileWithPrefix(string $file, string $prefix): void
    {
        if (!file_exists($file)) {
            throw new \RuntimeException("Route file not found: {$file}");
        }

        $realPath = realpath($file);
        if (in_array($realPath, $this->loadedFiles, true)) {
            return;
        }

        $this->loadedFiles[] = $realPath;

        $routeDefinition = require $file;

        if (!is_callable($routeDefinition)) {
            throw new \RuntimeException(
                "Route file must return a callable: {$file}"
            );
        }

        // Create route definition with prefix
        $routes = new RouteDefinition($this->router, $this->basePath . $prefix);

        $routeDefinition($routes);
    }

    /**
     * Get list of loaded route files
     */
    public function getLoadedFiles(): array
    {
        return $this->loadedFiles;
    }

    /**
     * Update the base path (for dynamic admin paths)
     */
    public function setBasePath(string $basePath): void
    {
        $this->basePath = $basePath;
    }
}
