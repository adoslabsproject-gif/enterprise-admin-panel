<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Modules;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use AdosLabs\AdminPanel\Core\AdminModuleInterface;

/**
 * Base Module - Abstract implementation for easier module development
 *
 * ENTERPRISE ARCHITECTURE:
 * ========================
 * This is the base class that external packages extend to integrate with admin-panel.
 *
 * EXAMPLE (in adoslabs/enterprise-security-shield package):
 * ```php
 * namespace AdosLabs\SecurityShield\Admin;
 *
 * use AdosLabs\AdminPanel\Modules\BaseModule;
 *
 * class SecurityShieldModule extends BaseModule
 * {
 *     public function getName(): string { return 'Security Shield'; }
 *     public function getTabs(): array { return [...]; }
 *     public function getRoutes(): array { return [...]; }
 *     // Views are in THIS package (security-shield), not in admin-panel
 * }
 * ```
 *
 * The module registers itself in composer.json:
 * ```json
 * {
 *     "extra": {
 *         "admin-panel": {
 *             "module": "AdosLabs\\SecurityShield\\Admin\\SecurityShieldModule"
 *         }
 *     }
 * }
 * ```
 *
 * @version 1.0.0
 */
abstract class BaseModule implements AdminModuleInterface
{
    protected LoggerInterface $logger;

    public function __construct(
        protected DatabasePool $db,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Get module name - MUST be overridden
     */
    abstract public function getName(): string;

    /**
     * Get module description
     */
    public function getDescription(): string
    {
        return '';
    }

    /**
     * Get module version
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * Get tabs for admin sidebar
     *
     * Override to add navigation tabs.
     *
     * @return array<array{label: string, url: string, icon: string, badge?: string|null, priority?: int}>
     */
    public function getTabs(): array
    {
        return [];
    }

    /**
     * Get routes for this module
     *
     * Override to register HTTP routes.
     *
     * @return array<array{method: string, path: string, handler: callable|array}>
     */
    public function getRoutes(): array
    {
        return [];
    }

    /**
     * Install module (run migrations, seed data)
     *
     * Default implementation runs migrations from module's migrations folder.
     * Override for custom installation logic.
     */
    public function install(): void
    {
        $this->logger->info('Installing module', ['module' => $this->getName()]);

        $migrationsPath = $this->getMigrationsPath();

        if ($migrationsPath !== null && is_dir($migrationsPath)) {
            $this->runMigrations($migrationsPath);
        }

        $this->logger->info('Module installed successfully', ['module' => $this->getName()]);
    }

    /**
     * Uninstall module
     *
     * Default: no automatic cleanup (too dangerous).
     * Override to implement specific cleanup logic.
     */
    public function uninstall(): void
    {
        $this->logger->warning('Uninstalling module', ['module' => $this->getName()]);
        // Default: no-op (too dangerous to auto-drop tables)
    }

    /**
     * Get module configuration schema
     *
     * @return array<array{key: string, label: string, type: string, default: mixed, description?: string}>
     */
    public function getConfigSchema(): array
    {
        return [];
    }

    /**
     * Get module dependencies (composer package names)
     *
     * @return array<string>
     */
    public function getDependencies(): array
    {
        return [];
    }

    /**
     * Get module permissions (for RBAC)
     *
     * @return array<string>
     */
    public function getPermissions(): array
    {
        return [];
    }

    /**
     * Get views path for this module
     *
     * Modules provide their own views. This returns the path to the views
     * directory within the module's package.
     *
     * @return string|null Path to views directory, or null if module has no views
     */
    public function getViewsPath(): ?string
    {
        $reflector = new \ReflectionClass($this);
        $moduleDir = dirname($reflector->getFileName());

        $viewsPath = $moduleDir . '/../Views';

        return is_dir($viewsPath) ? realpath($viewsPath) : null;
    }

    /**
     * Get assets path for this module (CSS, JS)
     *
     * @return string|null Path to assets directory, or null if none
     */
    public function getAssetsPath(): ?string
    {
        $reflector = new \ReflectionClass($this);
        $moduleDir = dirname($reflector->getFileName());

        $assetsPath = $moduleDir . '/../assets';

        return is_dir($assetsPath) ? realpath($assetsPath) : null;
    }

    /**
     * Get migrations path for this module
     *
     * Modules can provide database-specific migrations.
     *
     * @return string|null Path to migrations directory
     */
    protected function getMigrationsPath(): ?string
    {
        $reflector = new \ReflectionClass($this);
        $moduleDir = dirname($reflector->getFileName());

        // Check for database-specific migrations first
        $driver = $this->detectDatabaseDriver();
        $specificPath = $moduleDir . '/../Database/migrations/' . $driver;

        if (is_dir($specificPath)) {
            return realpath($specificPath);
        }

        // Fallback to generic migrations
        $genericPath = $moduleDir . '/../Database/migrations';

        return is_dir($genericPath) ? realpath($genericPath) : null;
    }

    /**
     * Run migrations from directory
     *
     * @param string $migrationsPath Path to migrations folder
     * @return int Number of migrations executed
     */
    protected function runMigrations(string $migrationsPath): int
    {
        $files = glob($migrationsPath . '/*.sql');

        if ($files === false || empty($files)) {
            return 0;
        }

        sort($files);
        $executed = 0;

        foreach ($files as $file) {
            $sql = file_get_contents($file);

            if ($sql === false) {
                $this->logger->error('Failed to read migration file', ['file' => $file]);
                continue;
            }

            try {
                $this->db->exec($sql);
                $this->logger->info('Migration executed', ['file' => basename($file)]);
                $executed++;
            } catch (\PDOException $e) {
                // Log but continue - migration may already be applied
                $this->logger->warning('Migration failed (may already exist)', [
                    'file' => basename($file),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $executed;
    }

    /**
     * Get module configuration from database
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        try {
            $rows = $this->db->query('SELECT config FROM admin_modules WHERE name = ?', [static::class]);

            if (empty($rows) || !$rows[0]['config']) {
                return $default;
            }

            $config = json_decode($rows[0]['config'], true);

            return $config[$key] ?? $default;
        } catch (\Exception $e) {
            return $default;
        }
    }

    /**
     * Set module configuration in database
     */
    protected function setConfig(string $key, mixed $value): bool
    {
        try {
            $rows = $this->db->query('SELECT config FROM admin_modules WHERE name = ?', [static::class]);

            $config = !empty($rows) && $rows[0]['config']
                ? json_decode($rows[0]['config'], true)
                : [];

            $config[$key] = $value;

            $this->db->execute('UPDATE admin_modules SET config = ? WHERE name = ?', [json_encode($config), static::class]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to set config', ['key' => $key, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Detect database driver
     */
    protected function detectDatabaseDriver(): string
    {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'pgsql' => 'postgresql',
            'mysql' => 'mysql',
            default => $driver,
        };
    }
}
