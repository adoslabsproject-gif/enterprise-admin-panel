<?php

/**
 * Enterprise Admin Panel - OPcache Preload Script
 *
 * This file preloads frequently used classes into OPcache for faster execution.
 * Configure in php.ini: opcache.preload=/path/to/enterprise-admin-panel/preload.php
 *
 * REQUIREMENTS:
 * - PHP 8.1+
 * - OPcache enabled
 * - opcache.preload_user must be set (e.g., www-data)
 *
 * CONFIGURATION (php.ini or .htaccess):
 * ```
 * opcache.enable=1
 * opcache.enable_cli=1
 * opcache.preload=/path/to/vendor/ados-labs/enterprise-admin-panel/preload.php
 * opcache.preload_user=www-data
 * opcache.memory_consumption=256
 * opcache.interned_strings_buffer=16
 * opcache.max_accelerated_files=10000
 * opcache.validate_timestamps=0  ; Set to 1 in development
 * opcache.revalidate_freq=0
 * opcache.jit=1255
 * opcache.jit_buffer_size=128M
 * ```
 *
 * @package AdosLabs\AdminPanel
 * @version 1.0.0
 */

declare(strict_types=1);

// Detect package root
$packageRoot = __DIR__;

// Check if we're in vendor or standalone
if (file_exists($packageRoot . '/vendor/autoload.php')) {
    // Standalone installation
    require_once $packageRoot . '/vendor/autoload.php';
} elseif (file_exists(dirname($packageRoot, 3) . '/autoload.php')) {
    // Installed via Composer
    require_once dirname($packageRoot, 3) . '/autoload.php';
} else {
    // Fallback: manual class loading
    error_log('[Preload] Autoloader not found, using manual preload');
}

/**
 * Preload a PHP file if it exists
 */
function preload_file(string $path): void
{
    if (file_exists($path) && is_readable($path)) {
        opcache_compile_file($path);
    }
}

/**
 * Preload all PHP files in a directory
 */
function preload_directory(string $directory, bool $recursive = true): int
{
    $count = 0;

    if (!is_dir($directory)) {
        return 0;
    }

    $iterator = $recursive
        ? new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        )
        : new DirectoryIterator($directory);

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $path = $file->getPathname();

            // Skip test files and examples
            if (
                str_contains($path, '/tests/') ||
                str_contains($path, '/Tests/') ||
                str_contains($path, '/examples/') ||
                str_contains($path, '/vendor/')
            ) {
                continue;
            }

            try {
                opcache_compile_file($path);
                $count++;
            } catch (Throwable $e) {
                error_log("[Preload] Failed to compile: {$path} - {$e->getMessage()}");
            }
        }
    }

    return $count;
}

// ============================================================================
// PRELOAD CORE CLASSES (order matters for dependencies)
// ============================================================================

$srcDir = $packageRoot . '/src';

// 1. Core infrastructure (no dependencies)
preload_directory($srcDir . '/Core', true);
preload_directory($srcDir . '/Helpers', true);

// 2. Database Pool (critical for performance)
preload_directory($srcDir . '/Database/Pool', true);
preload_directory($srcDir . '/Database/Pool/Redis', true);
preload_directory($srcDir . '/Database/Pool/Exceptions', true);

// 3. Cache drivers
preload_directory($srcDir . '/Cache', true);

// 4. Services
preload_directory($srcDir . '/Services', true);

// 5. Middleware
preload_directory($srcDir . '/Middleware', true);

// 6. Controllers
preload_directory($srcDir . '/Controllers', true);

// 7. Modules
preload_directory($srcDir . '/Modules', true);

// Count preloaded files
$stats = opcache_get_status();
$preloadedCount = $stats['preload_statistics']['files'] ?? 0;

error_log("[Preload] Enterprise Admin Panel preloaded {$preloadedCount} files");
