<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Core;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Module Registry - Auto-Discovery & Registration System
 *
 * ENTERPRISE GALAXY: Modular admin panel architecture
 *
 * FEATURES:
 * =========
 * 1. Auto-discovery of modules from composer packages
 * 2. Tab registration (dynamic sidebar navigation)
 * 3. Route registration (automatic routing)
 * 4. Service provider pattern (dependency injection)
 * 5. Module enable/disable without uninstall
 * 6. Priority-based loading order
 * 7. Module dependencies (requires/conflicts)
 * 8. Configuration per module
 * 9. Database migration per module
 * 10. Asset registration (CSS/JS)
 *
 * MODULE DISCOVERY:
 * =================
 * Automatically discovers modules via:
 * - composer.json "extra.admin-panel.module" key
 * - AdminModuleInterface implementation
 * - Manual registration via registerModule()
 *
 * EXAMPLE composer.json for module:
 * ```json
 * {
 *     "name": "adoslabs/enterprise-security-shield",
 *     "extra": {
 *         "admin-panel": {
 *             "module": "AdosLabs\\SecurityShield\\AdminModule",
 *             "priority": 10,
 *             "requires": ["psr/log"]
 *         }
 *     }
 * }
 * ```
 *
 * USAGE:
 * ======
 * ```php
 * $registry = new ModuleRegistry($pdo, $logger);
 *
 * // Auto-discover modules from composer
 * $registry->discoverModules();
 *
 * // Get all enabled modules
 * $modules = $registry->getEnabledModules();
 *
 * // Get tabs for sidebar navigation
 * $tabs = $registry->getTabs();
 * ```
 *
 * @version 1.0.0
 * @since 2026-01-24
 */
final class ModuleRegistry
{
    /**
     * Registered modules
     *
     * @var array<string, array{
     *     name: string,
     *     class: string,
     *     instance: AdminModuleInterface|null,
     *     priority: int,
     *     enabled: bool,
     *     installed_at: string|null,
     *     config: array
     * }>
     */
    private array $modules = [];

    /**
     * Module discovery paths
     *
     * @var array<string>
     */
    private array $discoveryPaths = [];

    /**
     * @param DatabasePool $db Database pool
     * @param LoggerInterface|null $logger PSR-3 logger
     */
    public function __construct(
        private DatabasePool $db,
        private ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Discover modules from composer packages
     *
     * ALGORITHM:
     * 1. Read composer.lock for installed packages
     * 2. Check each package for "extra.admin-panel.module"
     * 3. Verify class implements AdminModuleInterface
     * 4. Register module with priority
     *
     * @return int Number of modules discovered
     */
    public function discoverModules(): int
    {
        $discovered = 0;

        // Get composer.lock path (relative to vendor/)
        $composerLockPath = $this->getComposerLockPath();

        if (!file_exists($composerLockPath)) {
            $this->logger->warning('composer.lock not found', [
                'path' => $composerLockPath,
            ]);
            return 0;
        }

        try {
            $composerLock = json_decode(file_get_contents($composerLockPath), true);

            if (!isset($composerLock['packages'])) {
                $this->logger->error('Invalid composer.lock format');
                return 0;
            }

            // Check each package for admin-panel module definition
            foreach ($composerLock['packages'] as $package) {
                if (!isset($package['extra']['admin-panel']['module'])) {
                    continue;
                }

                $moduleClass = $package['extra']['admin-panel']['module'];
                $priority = $package['extra']['admin-panel']['priority'] ?? 50;
                $requires = $package['extra']['admin-panel']['requires'] ?? [];

                // Verify class exists and implements interface
                if (!class_exists($moduleClass)) {
                    $this->logger->warning('Module class not found', [
                        'package' => $package['name'],
                        'class' => $moduleClass,
                    ]);
                    continue;
                }

                if (!in_array(AdminModuleInterface::class, class_implements($moduleClass) ?: [])) {
                    $this->logger->warning('Module class does not implement AdminModuleInterface', [
                        'package' => $package['name'],
                        'class' => $moduleClass,
                    ]);
                    continue;
                }

                // Check dependencies
                if (!$this->checkDependencies($requires)) {
                    $this->logger->warning('Module dependencies not met', [
                        'package' => $package['name'],
                        'requires' => $requires,
                    ]);
                    continue;
                }

                // Register module
                $this->registerModule(
                    $package['name'],
                    $moduleClass,
                    $priority
                );

                $discovered++;

                $this->logger->info('Module discovered', [
                    'package' => $package['name'],
                    'class' => $moduleClass,
                    'priority' => $priority,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Module discovery failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $discovered;
    }

    /**
     * Register module manually
     *
     * @param string $name Module name (unique identifier)
     * @param string $class Module class (must implement AdminModuleInterface)
     * @param int $priority Loading priority (lower = earlier, default: 50)
     * @return bool True if registered successfully
     */
    public function registerModule(string $name, string $class, int $priority = 50): bool
    {
        // Check if already registered
        if (isset($this->modules[$name])) {
            $this->logger->warning('Module already registered', [
                'name' => $name,
            ]);
            return false;
        }

        // Verify class implements interface
        if (!in_array(AdminModuleInterface::class, class_implements($class) ?: [])) {
            $this->logger->error('Module class does not implement AdminModuleInterface', [
                'name' => $name,
                'class' => $class,
            ]);
            return false;
        }

        // Check if module is enabled in database
        $enabled = $this->isModuleEnabled($name);

        $this->modules[$name] = [
            'name' => $name,
            'class' => $class,
            'instance' => null,
            'priority' => $priority,
            'enabled' => $enabled,
            'installed_at' => $this->getModuleInstalledAt($name),
            'config' => $this->getModuleConfig($name),
        ];

        // Sort by priority
        uasort($this->modules, fn($a, $b) => $a['priority'] <=> $b['priority']);

        return true;
    }

    /**
     * Get all enabled modules
     *
     * @return array<AdminModuleInterface>
     */
    public function getEnabledModules(): array
    {
        $enabled = [];

        foreach ($this->modules as $name => $module) {
            if (!$module['enabled']) {
                continue;
            }

            // Lazy instantiation
            if ($module['instance'] === null) {
                try {
                    $this->modules[$name]['instance'] = new $module['class']($this->db, $this->logger);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to instantiate module', [
                        'name' => $name,
                        'class' => $module['class'],
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            $enabled[$name] = $this->modules[$name]['instance'];
        }

        return $enabled;
    }

    /**
     * Get tabs for admin sidebar navigation
     *
     * @return array<array{label: string, url: string, icon: string, badge: string|null, priority: int}>
     */
    public function getTabs(): array
    {
        $tabs = [];

        foreach ($this->getEnabledModules() as $name => $module) {
            $moduleTabs = $module->getTabs();

            foreach ($moduleTabs as $tab) {
                $tabs[] = array_merge($tab, [
                    'module' => $name,
                ]);
            }
        }

        // Sort by priority
        usort($tabs, fn($a, $b) => ($a['priority'] ?? 50) <=> ($b['priority'] ?? 50));

        return $tabs;
    }

    /**
     * Get routes from all modules
     *
     * @return array<array{method: string, path: string, handler: callable}>
     */
    public function getRoutes(): array
    {
        $routes = [];

        foreach ($this->getEnabledModules() as $name => $module) {
            $moduleRoutes = $module->getRoutes();

            foreach ($moduleRoutes as $route) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /**
     * Install module (run migrations, seed data)
     *
     * @param string $name Module name
     * @return bool True if installed successfully
     */
    public function installModule(string $name): bool
    {
        if (!isset($this->modules[$name])) {
            $this->logger->error('Module not registered', ['name' => $name]);
            return false;
        }

        $module = $this->modules[$name];

        // Instantiate if needed
        if ($module['instance'] === null) {
            try {
                $this->modules[$name]['instance'] = new $module['class']($this->db, $this->logger);
            } catch (\Exception $e) {
                $this->logger->error('Failed to instantiate module for installation', [
                    'name' => $name,
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        }

        // Run installation
        try {
            $this->modules[$name]['instance']->install();

            // Mark as installed in database
            $this->db->execute('
                INSERT INTO admin_modules (name, enabled, installed_at, config)
                VALUES (?, true, NOW(), ?)
                ON CONFLICT (name) DO UPDATE SET
                    enabled = true,
                    installed_at = NOW()
            ', [$name, json_encode([])]);

            $this->modules[$name]['enabled'] = true;
            $this->modules[$name]['installed_at'] = date('Y-m-d H:i:s');

            $this->logger->info('Module installed', ['name' => $name]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Module installation failed', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Enable module
     *
     * @param string $name Module name
     * @return bool
     */
    public function enableModule(string $name): bool
    {
        try {
            $this->db->execute('
                UPDATE admin_modules SET enabled = true WHERE name = ?
            ', [$name]);

            if (isset($this->modules[$name])) {
                $this->modules[$name]['enabled'] = true;
            }

            $this->logger->info('Module enabled', ['name' => $name]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to enable module', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Disable module
     *
     * @param string $name Module name
     * @return bool
     */
    public function disableModule(string $name): bool
    {
        try {
            $this->db->execute('
                UPDATE admin_modules SET enabled = false WHERE name = ?
            ', [$name]);

            if (isset($this->modules[$name])) {
                $this->modules[$name]['enabled'] = false;
            }

            $this->logger->info('Module disabled', ['name' => $name]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to disable module', [
                'name' => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if module is enabled in database
     *
     * ARCHITECTURE: Auto-discovered modules are ENABLED by default.
     * If a module is found via discoverModules() but not in admin_modules table,
     * we return TRUE to enable it. Admin can disable manually if needed.
     *
     * This follows the "convention over configuration" principle:
     * - You install a package → it works
     * - You don't need manual "enable" step
     *
     * @param string $name Module name
     * @return bool
     */
    private function isModuleEnabled(string $name): bool
    {
        try {
            $results = $this->db->query('
                SELECT enabled FROM admin_modules WHERE name = ?
            ', [$name]);

            // IMPORTANT: If module not in DB, it's enabled by default (auto-discovered)
            // Only return false if explicitly disabled in database
            return empty($results) ? true : (bool) $results[0]['enabled'];
        } catch (\Exception $e) {
            // On error, assume enabled to not break functionality
            return true;
        }
    }

    /**
     * Get module installed timestamp
     *
     * @param string $name Module name
     * @return string|null
     */
    private function getModuleInstalledAt(string $name): ?string
    {
        try {
            $results = $this->db->query('
                SELECT installed_at FROM admin_modules WHERE name = ?
            ', [$name]);

            return $results[0]['installed_at'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get module configuration
     *
     * @param string $name Module name
     * @return array
     */
    private function getModuleConfig(string $name): array
    {
        try {
            $results = $this->db->query('
                SELECT config FROM admin_modules WHERE name = ?
            ', [$name]);

            if (!empty($results) && $results[0]['config']) {
                return json_decode($results[0]['config'], true) ?? [];
            }

            return [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Check if dependencies are met
     *
     * @param array<string> $requires Package names required
     * @return bool
     */
    private function checkDependencies(array $requires): bool
    {
        foreach ($requires as $package) {
            // Simple check: class_exists for package namespace
            // More sophisticated: check composer.lock
            if (!class_exists($package, false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get composer.lock path
     *
     * Searches for composer.lock in multiple possible locations:
     * 1. Configured path (via setComposerLockPath)
     * 2. Project root (when this package is a dependency)
     * 3. Current working directory
     *
     * @return string
     */
    private function getComposerLockPath(): string
    {
        // If explicitly set
        if (isset($this->composerLockPath)) {
            return $this->composerLockPath;
        }

        // Try to find composer.lock
        $possiblePaths = [
            getcwd() . '/composer.lock',
            dirname(__DIR__, 5) . '/composer.lock', // vendor/adoslabs/admin-panel/src/Core -> root
            dirname(__DIR__, 6) . '/composer.lock',
            $_SERVER['DOCUMENT_ROOT'] . '/../composer.lock',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // Fallback
        return getcwd() . '/composer.lock';
    }

    /**
     * Set composer.lock path explicitly
     */
    public function setComposerLockPath(string $path): void
    {
        $this->composerLockPath = $path;
    }

    /**
     * Discover module from a specific path (workspace/local package)
     *
     * ENTERPRISE FIX: Support local/workspace packages that aren't in composer.lock
     * This enables development workflows where packages are symlinked.
     *
     * @param string $path Path to the package directory (must contain composer.json)
     * @return bool True if a module was discovered
     */
    public function discoverFromPath(string $path): bool
    {
        $composerJsonPath = rtrim($path, '/') . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            $this->logger->warning('composer.json not found in path', [
                'path' => $path,
            ]);
            return false;
        }

        try {
            $composerJson = json_decode(file_get_contents($composerJsonPath), true);

            if (!isset($composerJson['extra']['admin-panel']['module'])) {
                $this->logger->debug('No admin-panel module definition in package', [
                    'path' => $path,
                    'name' => $composerJson['name'] ?? 'unknown',
                ]);
                return false;
            }

            $packageName = $composerJson['name'] ?? basename($path);
            $moduleClass = $composerJson['extra']['admin-panel']['module'];
            $priority = $composerJson['extra']['admin-panel']['priority'] ?? 50;

            // Verify class exists
            if (!class_exists($moduleClass)) {
                $this->logger->warning('Module class not found (ensure autoload is configured)', [
                    'package' => $packageName,
                    'class' => $moduleClass,
                ]);
                return false;
            }

            // Verify interface implementation
            if (!in_array(AdminModuleInterface::class, class_implements($moduleClass) ?: [])) {
                $this->logger->warning('Module class does not implement AdminModuleInterface', [
                    'package' => $packageName,
                    'class' => $moduleClass,
                ]);
                return false;
            }

            // Register the module
            $this->registerModule($packageName, $moduleClass, $priority);

            $this->logger->info('Module discovered from path', [
                'package' => $packageName,
                'class' => $moduleClass,
                'path' => $path,
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to discover module from path', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Discover modules from multiple workspace paths
     *
     * @param array<string> $paths Paths to package directories
     * @return int Number of modules discovered
     */
    public function discoverFromPaths(array $paths): int
    {
        $discovered = 0;

        foreach ($paths as $path) {
            if ($this->discoverFromPath($path)) {
                $discovered++;
            }
        }

        return $discovered;
    }

    /**
     * Get all views paths from enabled modules
     *
     * @return array<string, string> Module name => views path
     */
    public function getViewsPaths(): array
    {
        $paths = [];

        foreach ($this->getEnabledModules() as $name => $module) {
            $viewsPath = $module->getViewsPath();

            if ($viewsPath !== null) {
                $paths[$name] = $viewsPath;
            }
        }

        return $paths;
    }

    /**
     * Get all assets paths from enabled modules
     *
     * @return array<string, string> Module name => assets path
     */
    public function getAssetsPaths(): array
    {
        $paths = [];

        foreach ($this->getEnabledModules() as $name => $module) {
            $assetsPath = $module->getAssetsPath();

            if ($assetsPath !== null) {
                $paths[$name] = $assetsPath;
            }
        }

        return $paths;
    }

    /**
     * Find view file across all modules
     *
     * @param string $view View name (e.g., "security/dashboard")
     * @return string|null Full path to view file, or null if not found
     */
    public function findView(string $view): ?string
    {
        $viewFile = $view . '.php';

        foreach ($this->getViewsPaths() as $moduleName => $viewsPath) {
            $fullPath = $viewsPath . '/' . $viewFile;

            if (file_exists($fullPath)) {
                return $fullPath;
            }
        }

        return null;
    }
}
