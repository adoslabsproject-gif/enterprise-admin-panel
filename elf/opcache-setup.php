#!/usr/bin/env php
<?php
/**
 * Enterprise Admin Panel - OPcache Preload Setup
 *
 * Configures OPcache preloading for maximum performance.
 *
 * Usage:
 *   php elf/opcache-setup.php --check           # Check current OPcache status
 *   php elf/opcache-setup.php --generate        # Generate config file only
 *   php elf/opcache-setup.php --install         # Install to php.ini (requires sudo)
 *   php elf/opcache-setup.php --fpm-restart     # Restart PHP-FPM after install
 *
 * @package AdosLabs\AdminPanel
 */

declare(strict_types=1);

// Find paths
$packageRoot = dirname(__DIR__);
$projectRoot = null;

$searchDir = getcwd();
for ($i = 0; $i < 10; $i++) {
    if (file_exists($searchDir . '/composer.json')) {
        $composerJson = json_decode(file_get_contents($searchDir . '/composer.json'), true);
        if (($composerJson['name'] ?? '') !== 'ados-labs/enterprise-admin-panel') {
            $projectRoot = $searchDir;
            break;
        }
    }
    $parent = dirname($searchDir);
    if ($parent === $searchDir) break;
    $searchDir = $parent;
}

if ($projectRoot === null) {
    $projectRoot = $packageRoot;
}

// Parse arguments
$options = getopt('', ['check', 'generate', 'install', 'fpm-restart', 'user:', 'help', 'json']);

if (isset($options['help']) || empty($options)) {
    echo <<<HELP
Enterprise Admin Panel - OPcache Preload Setup
===============================================

This tool configures OPcache preloading for maximum PHP performance.
Preloading compiles and caches PHP classes at PHP-FPM startup.

Usage:
  php elf/opcache-setup.php [options]

Options:
  --check         Check current OPcache status and configuration
  --generate      Generate opcache.ini config file (does not install)
  --install       Install config to php.ini (may require sudo)
  --fpm-restart   Restart PHP-FPM after installation
  --user=USER     PHP-FPM user (default: auto-detect or www-data)
  --json          Output in JSON format
  --help          Show this help

Examples:
  # Check if OPcache is properly configured
  php elf/opcache-setup.php --check

  # Generate config file to review
  php elf/opcache-setup.php --generate

  # Install and restart PHP-FPM (Linux)
  sudo php elf/opcache-setup.php --install --fpm-restart

  # macOS with Homebrew PHP
  php elf/opcache-setup.php --install --user=_www

Performance Impact:
  - First request: ~50-100ms faster (no compilation)
  - Subsequent requests: ~10-30% faster (optimized opcodes)
  - Memory usage: Slightly higher (shared preloaded classes)

HELP;
    exit(0);
}

$jsonOutput = isset($options['json']);

function output(string $message, string $type = 'info'): void {
    global $jsonOutput;
    if ($jsonOutput) return;

    $prefix = match($type) {
        'ok' => "\033[32m[OK]\033[0m",
        'warn' => "\033[33m[WARN]\033[0m",
        'error' => "\033[31m[ERROR]\033[0m",
        'skip' => "\033[90m[SKIP]\033[0m",
        default => "\033[34m[INFO]\033[0m",
    };
    echo "$prefix $message\n";
}

function outputJson(array $data): void {
    global $jsonOutput;
    if ($jsonOutput) {
        echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    }
}

// ============================================================================
// CHECK - Analyze current OPcache configuration
// ============================================================================

if (isset($options['check'])) {
    $result = [
        'opcache_enabled' => false,
        'preload_configured' => false,
        'preload_path' => null,
        'jit_enabled' => false,
        'recommendations' => [],
    ];

    echo "\n";
    echo "OPcache Status Check\n";
    echo "====================\n\n";

    // Check if OPcache extension is loaded
    if (!extension_loaded('Zend OPcache')) {
        output("OPcache extension not loaded", 'error');
        $result['recommendations'][] = 'Install and enable OPcache extension';
        outputJson($result);
        exit(1);
    }

    output("OPcache extension loaded", 'ok');
    $result['opcache_enabled'] = true;

    // Get OPcache configuration
    $config = opcache_get_configuration();
    $status = opcache_get_status(false);

    // Check if enabled
    if (!($config['directives']['opcache.enable'] ?? false)) {
        output("OPcache is disabled (opcache.enable=0)", 'warn');
        $result['recommendations'][] = 'Set opcache.enable=1 in php.ini';
    } else {
        output("OPcache is enabled", 'ok');
    }

    // Check CLI mode
    if (PHP_SAPI === 'cli' && !($config['directives']['opcache.enable_cli'] ?? false)) {
        output("OPcache CLI mode disabled (opcache.enable_cli=0)", 'warn');
        $result['recommendations'][] = 'Set opcache.enable_cli=1 for CLI performance';
    }

    // Check preloading
    $preloadPath = $config['directives']['opcache.preload'] ?? '';
    if (empty($preloadPath)) {
        output("Preloading not configured", 'warn');
        $result['recommendations'][] = 'Configure opcache.preload for faster startup';
    } else {
        $result['preload_configured'] = true;
        $result['preload_path'] = $preloadPath;

        if (str_contains($preloadPath, 'enterprise-admin-panel')) {
            output("Preloading configured for Enterprise Admin Panel", 'ok');
        } else {
            output("Preloading configured: $preloadPath", 'info');
        }

        // Check preload stats
        if (isset($status['preload_statistics'])) {
            $preloadStats = $status['preload_statistics'];
            $fileCount = $preloadStats['files'] ?? 0;
            $memoryUsed = $preloadStats['memory_consumption'] ?? 0;
            output("  Preloaded files: $fileCount", 'info');
            output("  Memory used: " . round($memoryUsed / 1024 / 1024, 2) . " MB", 'info');
        }
    }

    // Check JIT
    $jitEnabled = ($config['directives']['opcache.jit'] ?? 0) !== 0;
    $result['jit_enabled'] = $jitEnabled;

    if ($jitEnabled) {
        $jitBuffer = $config['directives']['opcache.jit_buffer_size'] ?? 0;
        output("JIT enabled (buffer: " . round($jitBuffer / 1024 / 1024) . " MB)", 'ok');
    } else {
        output("JIT disabled (PHP 8.0+ feature)", 'warn');
        $result['recommendations'][] = 'Enable JIT with opcache.jit=1255 for best performance';
    }

    // Memory stats
    if ($status) {
        $memoryUsage = $status['memory_usage'] ?? [];
        $usedMem = $memoryUsage['used_memory'] ?? 0;
        $freeMem = $memoryUsage['free_memory'] ?? 0;
        $totalMem = $usedMem + $freeMem;
        $usagePercent = $totalMem > 0 ? round($usedMem / $totalMem * 100, 1) : 0;

        echo "\n";
        output("Memory: $usagePercent% used (" . round($usedMem / 1024 / 1024, 1) . " / " . round($totalMem / 1024 / 1024) . " MB)", 'info');

        $cachedScripts = $status['opcache_statistics']['num_cached_scripts'] ?? 0;
        output("Cached scripts: $cachedScripts", 'info');
    }

    // Recommendations
    if (!empty($result['recommendations'])) {
        echo "\n";
        echo "Recommendations:\n";
        foreach ($result['recommendations'] as $rec) {
            echo "  - $rec\n";
        }
        echo "\nRun: php elf/opcache-setup.php --generate\n";
    } else {
        echo "\n";
        output("OPcache is optimally configured!", 'ok');
    }

    outputJson($result);
    exit(0);
}

// ============================================================================
// GENERATE - Create optimized opcache.ini configuration
// ============================================================================

if (isset($options['generate']) || isset($options['install'])) {
    $preloadPath = realpath($packageRoot . '/preload.php');
    $user = $options['user'] ?? null;

    // Auto-detect user
    if ($user === null) {
        if (PHP_OS_FAMILY === 'Darwin') {
            $user = '_www'; // macOS
        } elseif (file_exists('/etc/nginx/nginx.conf')) {
            // Try to detect from nginx config
            $nginxConf = file_get_contents('/etc/nginx/nginx.conf');
            if (preg_match('/^\s*user\s+(\w+)/m', $nginxConf, $matches)) {
                $user = $matches[1];
            }
        }
        $user = $user ?? 'www-data'; // Default for most Linux
    }

    $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

    $config = <<<INI
; ============================================================================
; Enterprise Admin Panel - OPcache Configuration
; ============================================================================
; Generated: %DATE%
; PHP Version: $phpVersion
; ============================================================================
; Add this to your php.ini or create a separate file in conf.d/
;
; Linux: /etc/php/$phpVersion/fpm/conf.d/99-enterprise-admin-panel.ini
; macOS (Homebrew): /opt/homebrew/etc/php/$phpVersion/conf.d/99-enterprise-admin-panel.ini
; ============================================================================

; Enable OPcache
opcache.enable=1
opcache.enable_cli=1

; Preload Enterprise Admin Panel classes at PHP-FPM startup
; This eliminates compilation overhead for every request
opcache.preload=$preloadPath
opcache.preload_user=$user

; Memory settings (adjust based on server resources)
; For small servers: 128MB, medium: 256MB, large: 512MB
opcache.memory_consumption=256

; Interned strings buffer (for class/function names)
opcache.interned_strings_buffer=16

; Maximum cached files (10000 is good for most apps)
opcache.max_accelerated_files=10000

; PRODUCTION: Disable timestamp validation for best performance
; Set to 1 during development to auto-detect file changes
opcache.validate_timestamps=0
opcache.revalidate_freq=0

; JIT Compilation (PHP 8.0+)
; 1255 = tracing JIT, best for web applications
; 1205 = function JIT, slightly faster cold start
opcache.jit=1255
opcache.jit_buffer_size=128M

; Optimization level (default is fine)
opcache.optimization_level=0x7FFEBFFF

; ============================================================================
; DEVELOPMENT OVERRIDES (uncomment for development)
; ============================================================================
; opcache.validate_timestamps=1
; opcache.revalidate_freq=2
; opcache.jit=0

INI;

    $config = str_replace('%DATE%', date('Y-m-d H:i:s'), $config);

    // Determine output path
    $outputPath = $projectRoot . '/opcache.ini';

    file_put_contents($outputPath, $config);

    output("Generated: $outputPath", 'ok');
    echo "\n";
    echo "Configuration file created!\n\n";

    if (!isset($options['install'])) {
        echo "To install, copy this file to your PHP conf.d directory:\n\n";

        if (PHP_OS_FAMILY === 'Darwin') {
            echo "  # macOS (Homebrew)\n";
            echo "  sudo cp $outputPath /opt/homebrew/etc/php/$phpVersion/conf.d/99-eap.ini\n";
            echo "  brew services restart php\n";
        } else {
            echo "  # Linux (Debian/Ubuntu)\n";
            echo "  sudo cp $outputPath /etc/php/$phpVersion/fpm/conf.d/99-eap.ini\n";
            echo "  sudo systemctl restart php$phpVersion-fpm\n";
        }

        echo "\nOr run: sudo php elf/opcache-setup.php --install --fpm-restart\n";
        exit(0);
    }
}

// ============================================================================
// INSTALL - Copy configuration to php.ini directory
// ============================================================================

if (isset($options['install'])) {
    $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $sourcePath = $projectRoot . '/opcache.ini';

    // Find conf.d directory
    $confDirs = [
        // macOS Homebrew
        "/opt/homebrew/etc/php/$phpVersion/conf.d",
        "/usr/local/etc/php/$phpVersion/conf.d",
        // Linux
        "/etc/php/$phpVersion/fpm/conf.d",
        "/etc/php/$phpVersion/cli/conf.d",
        "/etc/php.d",
    ];

    $targetDir = null;
    foreach ($confDirs as $dir) {
        if (is_dir($dir) && is_writable($dir)) {
            $targetDir = $dir;
            break;
        }
    }

    if ($targetDir === null) {
        output("Could not find writable PHP conf.d directory", 'error');
        output("Try running with sudo", 'info');

        echo "\nSearched directories:\n";
        foreach ($confDirs as $dir) {
            $status = is_dir($dir) ? (is_writable($dir) ? 'writable' : 'not writable') : 'not found';
            echo "  - $dir ($status)\n";
        }
        exit(1);
    }

    $targetPath = $targetDir . '/99-enterprise-admin-panel.ini';

    if (!copy($sourcePath, $targetPath)) {
        output("Failed to copy configuration file", 'error');
        output("Try: sudo cp $sourcePath $targetPath", 'info');
        exit(1);
    }

    output("Installed: $targetPath", 'ok');

    // Clean up generated file
    unlink($sourcePath);
}

// ============================================================================
// FPM-RESTART - Restart PHP-FPM service
// ============================================================================

if (isset($options['fpm-restart'])) {
    $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

    echo "\n";
    output("Restarting PHP-FPM...", 'info');

    if (PHP_OS_FAMILY === 'Darwin') {
        // macOS with Homebrew
        $result = shell_exec('brew services restart php 2>&1');
        if (str_contains($result ?? '', 'Successfully')) {
            output("PHP-FPM restarted (Homebrew)", 'ok');
        } else {
            output("Failed to restart: $result", 'error');
            exit(1);
        }
    } else {
        // Linux systemd
        $services = [
            "php$phpVersion-fpm",
            "php-fpm",
        ];

        $restarted = false;
        foreach ($services as $service) {
            $check = shell_exec("systemctl is-active $service 2>&1");
            if (trim($check ?? '') === 'active') {
                $result = shell_exec("systemctl restart $service 2>&1");
                if (empty($result)) {
                    output("Restarted: $service", 'ok');
                    $restarted = true;
                    break;
                }
            }
        }

        if (!$restarted) {
            output("Could not find running PHP-FPM service", 'error');
            output("Manually restart your PHP-FPM service", 'info');
            exit(1);
        }
    }

    echo "\n";
    output("OPcache preloading will take effect on next PHP-FPM worker spawn", 'info');
    output("Run: php elf/opcache-setup.php --check", 'info');
}

echo "\nDone!\n";
