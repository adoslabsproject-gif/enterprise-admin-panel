<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Console;

/**
 * Asset Publisher
 *
 * Publishes static assets (CSS, JS, images) from vendor packages
 * to the project's public directory.
 *
 * Similar to Laravel's vendor:publish command.
 *
 * Usage:
 *   php vendor/adoslabs/enterprise-admin-panel/bin/publish-assets
 *
 * Or via Composer script (automatic on install/update):
 *   composer run publish-assets
 *
 * @package adoslabs/enterprise-admin-panel
 */
final class AssetPublisher
{
    private string $projectRoot;
    private string $publicPath;
    private array $published = [];
    private array $errors = [];
    private bool $force = false;
    private bool $verbose = false;

    /**
     * Asset manifests from packages
     * Each package declares what assets to publish and where
     */
    private array $manifests = [];

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot ?? $this->detectProjectRoot();
        $this->publicPath = $this->projectRoot . '/public';
    }

    /**
     * Enable force mode (overwrite existing files)
     */
    public function force(bool $force = true): self
    {
        $this->force = $force;
        return $this;
    }

    /**
     * Enable verbose output
     */
    public function verbose(bool $verbose = true): self
    {
        $this->verbose = $verbose;
        return $this;
    }

    /**
     * Run the asset publishing process
     */
    public function publish(): bool
    {
        $this->log("Asset Publisher v1.0.0");
        $this->log("======================");
        $this->log("");

        // Discover all publishable assets
        $this->discoverAssets();

        if (empty($this->manifests)) {
            $this->log("No assets to publish.");
            return true;
        }

        // Ensure public directory exists
        if (!is_dir($this->publicPath)) {
            mkdir($this->publicPath, 0755, true);
            $this->log("Created public directory: {$this->publicPath}");
        }

        // Publish each package's assets
        foreach ($this->manifests as $package => $manifest) {
            $this->publishPackage($package, $manifest);
        }

        // Summary
        $this->log("");
        $this->log("=== Summary ===");
        $this->log("Published: " . count($this->published) . " files");

        if (!empty($this->errors)) {
            $this->log("Errors: " . count($this->errors));
            foreach ($this->errors as $error) {
                $this->log("  - {$error}", 'error');
            }
            return false;
        }

        $this->log("All assets published successfully!");
        return true;
    }

    /**
     * Discover assets from installed packages
     */
    private function discoverAssets(): void
    {
        $vendorPath = $this->projectRoot . '/vendor';

        // 1. Admin Panel's own assets
        $this->registerAdminPanelAssets();

        // 2. Discover from composer.lock (installed packages)
        $composerLock = $this->projectRoot . '/composer.lock';
        if (file_exists($composerLock)) {
            $lock = json_decode(file_get_contents($composerLock), true);
            $packages = array_merge(
                $lock['packages'] ?? [],
                $lock['packages-dev'] ?? []
            );

            foreach ($packages as $package) {
                $packageName = $package['name'] ?? '';
                $packagePath = $vendorPath . '/' . $packageName;

                // Check for asset manifest in package
                $this->discoverPackageAssets($packageName, $packagePath);
            }
        }

        // 3. Also scan common locations
        $this->scanCommonLocations($vendorPath);
    }

    /**
     * Register admin panel's own assets
     */
    private function registerAdminPanelAssets(): void
    {
        $adminPanelPublic = dirname(__DIR__, 2) . '/public';

        if (is_dir($adminPanelPublic)) {
            $this->manifests['adoslabs/enterprise-admin-panel'] = [
                'source' => $adminPanelPublic,
                'assets' => $this->scanDirectory($adminPanelPublic),
            ];
        }
    }

    /**
     * Discover assets from a specific package
     */
    private function discoverPackageAssets(string $packageName, string $packagePath): void
    {
        if (!is_dir($packagePath)) {
            return;
        }

        // Check composer.json for asset configuration
        $composerJson = $packagePath . '/composer.json';
        if (file_exists($composerJson)) {
            $composer = json_decode(file_get_contents($composerJson), true);

            // Check for admin-panel extra config
            $extra = $composer['extra']['admin-panel'] ?? [];

            if (!empty($extra['assets-path'])) {
                $assetsPath = $packagePath . '/' . ltrim($extra['assets-path'], '/');
                if (is_dir($assetsPath)) {
                    $this->manifests[$packageName] = [
                        'source' => $assetsPath,
                        'assets' => $this->scanDirectory($assetsPath),
                        'prefix' => $extra['assets-prefix'] ?? null,
                    ];
                    return;
                }
            }
        }

        // Fallback: check common asset locations
        $commonPaths = ['public', 'resources/assets', 'assets'];
        foreach ($commonPaths as $path) {
            $fullPath = $packagePath . '/' . $path;
            if (is_dir($fullPath) && $this->hasPublishableAssets($fullPath)) {
                $this->manifests[$packageName] = [
                    'source' => $fullPath,
                    'assets' => $this->scanDirectory($fullPath),
                ];
                break;
            }
        }
    }

    /**
     * Scan common vendor locations
     */
    private function scanCommonLocations(string $vendorPath): void
    {
        // Scan adoslabs packages
        $adoslabsPath = $vendorPath . '/adoslabs';
        if (is_dir($adoslabsPath)) {
            foreach (scandir($adoslabsPath) as $package) {
                if ($package === '.' || $package === '..') continue;

                $packageName = 'adoslabs/' . $package;
                if (isset($this->manifests[$packageName])) continue;

                $packagePath = $adoslabsPath . '/' . $package;
                $this->discoverPackageAssets($packageName, $packagePath);
            }
        }
    }

    /**
     * Check if directory has publishable assets
     */
    private function hasPublishableAssets(string $path): bool
    {
        $extensions = ['css', 'js', 'png', 'jpg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $extensions)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Scan directory for assets
     */
    private function scanDirectory(string $path): array
    {
        $assets = [];
        $extensions = ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'map'];

        if (!is_dir($path)) {
            return $assets;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $extensions)) {
                    $relativePath = str_replace($path . '/', '', $file->getPathname());
                    $assets[] = $relativePath;
                }
            }
        }

        return $assets;
    }

    /**
     * Publish a package's assets
     */
    private function publishPackage(string $package, array $manifest): void
    {
        $this->log("Publishing: {$package}");

        $source = $manifest['source'];

        // Determine destination prefix
        // Admin panel assets go directly to /css, /js
        // Module assets go to /modules/{module-slug}/css, /modules/{module-slug}/js
        $prefix = $manifest['prefix'] ?? null;

        if ($prefix === null && $package !== 'adoslabs/enterprise-admin-panel') {
            // Extract module slug from package name
            $slug = str_replace(['adoslabs/', 'enterprise-'], '', $package);
            $prefix = 'modules/' . $slug;
        }

        foreach ($manifest['assets'] as $asset) {
            $sourcePath = $source . '/' . $asset;
            $destPath = $this->publicPath . '/' . ($prefix ? $prefix . '/' : '') . $asset;

            $this->publishFile($sourcePath, $destPath);
        }
    }

    /**
     * Publish a single file
     */
    private function publishFile(string $source, string $dest): void
    {
        // Check if file exists and is not changed
        if (file_exists($dest) && !$this->force) {
            if (md5_file($source) === md5_file($dest)) {
                if ($this->verbose) {
                    $this->log("  [skip] " . basename($dest) . " (unchanged)");
                }
                return;
            }
        }

        // Create directory if needed
        $destDir = dirname($dest);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        // Copy file
        if (copy($source, $dest)) {
            $this->published[] = $dest;
            $relativeDest = str_replace($this->publicPath . '/', '', $dest);
            $this->log("  [+] {$relativeDest}");
        } else {
            $this->errors[] = "Failed to copy: {$source} -> {$dest}";
        }
    }

    /**
     * Detect project root
     *
     * Handles multiple scenarios:
     * 1. Running from vendor: my-project/vendor/adoslabs/enterprise-admin-panel/...
     * 2. Running standalone: enterprise-admin-panel/...
     * 3. Running via Composer script in project root
     */
    private function detectProjectRoot(): string
    {
        // Get the package root directory
        // __DIR__ = .../src/Console, dirname(__DIR__, 2) = package root
        $packageRoot = dirname(__DIR__, 2);

        // Strategy 1: Walk up from package looking for a REAL vendor directory
        // A real vendor dir has autoload.php and composer/ subfolder
        $dir = $packageRoot;

        while ($dir !== '/' && $dir !== '' && strlen($dir) > 1) {
            $parentDir = dirname($dir);
            $currentDirName = basename($dir);

            // Check if we're inside a vendor structure: vendor/namespace/package
            if ($currentDirName === 'vendor' || basename($parentDir) === 'vendor' || basename(dirname($parentDir)) === 'vendor') {
                // Find the actual vendor directory by looking for autoload.php
                $testDir = $dir;
                for ($i = 0; $i < 4; $i++) {
                    if (file_exists($testDir . '/autoload.php') && is_dir($testDir . '/composer')) {
                        // Found vendor! Project root is parent of vendor
                        $projectRoot = dirname($testDir);
                        if (file_exists($projectRoot . '/composer.json')) {
                            return $projectRoot;
                        }
                    }
                    $testDir = dirname($testDir);
                }
            }

            $dir = $parentDir;
        }

        // Strategy 2: Look for parent composer.json with vendor directory
        $dir = dirname($packageRoot);
        $ownComposer = realpath($packageRoot . '/composer.json');

        while ($dir !== '/' && $dir !== '' && strlen($dir) > 1) {
            $composerFile = $dir . '/composer.json';
            $vendorDir = $dir . '/vendor';

            // Must have composer.json, vendor directory with autoload.php
            // And must NOT be our own package
            if (file_exists($composerFile) &&
                realpath($composerFile) !== $ownComposer &&
                file_exists($vendorDir . '/autoload.php')) {
                return $dir;
            }

            $dir = dirname($dir);
        }

        // Strategy 3: Current working directory (for Composer scripts)
        $cwd = getcwd();
        if ($cwd &&
            file_exists($cwd . '/composer.json') &&
            file_exists($cwd . '/vendor/autoload.php')) {
            return $cwd;
        }

        // Last resort: package root (standalone mode)
        return $packageRoot;
    }

    /**
     * Log message
     */
    private function log(string $message, string $type = 'info'): void
    {
        $prefix = match ($type) {
            'error' => "\033[31m[ERROR]\033[0m ",
            'warning' => "\033[33m[WARN]\033[0m ",
            'success' => "\033[32m[OK]\033[0m ",
            default => '',
        };

        echo $prefix . $message . PHP_EOL;
    }

    /**
     * Get published files
     */
    public function getPublished(): array
    {
        return $this->published;
    }

    /**
     * Get errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Static method for Composer scripts
     */
    public static function postInstall(): void
    {
        $publisher = new self();
        $publisher->publish();
    }

    /**
     * Static method for Composer scripts (force mode)
     */
    public static function postUpdate(): void
    {
        $publisher = new self();
        $publisher->force(true)->publish();
    }
}
