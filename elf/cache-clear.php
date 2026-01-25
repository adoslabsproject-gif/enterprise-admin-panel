#!/usr/bin/env php
<?php
/**
 * Enterprise Admin Panel - Cache Clear Utility
 *
 * Clears OPcache, APCu, and Redis caches.
 * Run after code changes or deployments.
 *
 * Usage:
 *   php elf/cache-clear.php --all           # Clear all caches
 *   php elf/cache-clear.php --opcache       # Clear OPcache only
 *   php elf/cache-clear.php --apcu          # Clear APCu only
 *   php elf/cache-clear.php --redis         # Clear Redis (EAP keys only)
 *   php elf/cache-clear.php --redis-all     # Clear entire Redis database
 */

declare(strict_types=1);

// Find project root
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
$options = getopt('', ['opcache', 'apcu', 'redis', 'redis-all', 'all', 'help', 'quiet']);

$quiet = isset($options['quiet']);

function output(string $message): void {
    global $quiet;
    if (!$quiet) {
        echo $message . "\n";
    }
}

if (isset($options['help']) || empty($options)) {
    echo <<<HELP
Enterprise Admin Panel - Cache Clear Utility
=============================================

Usage:
  php elf/cache-clear.php [options]

Options:
  --opcache     Clear OPcache (compiled PHP bytecode)
  --apcu        Clear APCu (local shared memory cache)
  --redis       Clear Redis EAP keys only (safe)
  --redis-all   Clear entire Redis database (caution!)
  --all         Clear all caches (opcache + apcu + redis)
  --quiet       Suppress output
  --help        Show this help

Examples:
  # After code deployment
  php elf/cache-clear.php --opcache

  # Reset circuit breaker and metrics
  php elf/cache-clear.php --redis

  # Full cache clear
  php elf/cache-clear.php --all

HELP;
    exit(0);
}

$all = isset($options['all']);

output("Enterprise Admin Panel - Cache Clear");
output("====================================\n");

// Load .env for Redis configuration
$envFile = $projectRoot . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

$cleared = [];
$errors = [];

// ============================================================================
// OPcache
// ============================================================================
if ($all || isset($options['opcache'])) {
    if (function_exists('opcache_reset')) {
        if (opcache_reset()) {
            $cleared[] = 'OPcache';
            output("[OK] OPcache cleared");

            // Show stats
            if (function_exists('opcache_get_status')) {
                $status = opcache_get_status(false);
                if ($status && isset($status['opcache_statistics'])) {
                    $stats = $status['opcache_statistics'];
                    output("     - Cached scripts reset: " . ($stats['num_cached_scripts'] ?? 'N/A'));
                }
            }
        } else {
            $errors[] = 'OPcache reset failed';
            output("[FAIL] OPcache reset failed");
        }
    } else {
        output("[SKIP] OPcache not available (extension not loaded)");
    }
}

// ============================================================================
// APCu
// ============================================================================
if ($all || isset($options['apcu'])) {
    if (function_exists('apcu_clear_cache')) {
        if (apcu_clear_cache()) {
            $cleared[] = 'APCu';
            output("[OK] APCu cleared");

            // Show stats
            if (function_exists('apcu_cache_info')) {
                $info = @apcu_cache_info(true);
                if ($info) {
                    output("     - Memory freed: " . round(($info['mem_size'] ?? 0) / 1024 / 1024, 2) . " MB");
                }
            }
        } else {
            $errors[] = 'APCu clear failed';
            output("[FAIL] APCu clear failed");
        }
    } else {
        output("[SKIP] APCu not available (extension not loaded)");
    }
}

// ============================================================================
// Redis
// ============================================================================
if ($all || isset($options['redis']) || isset($options['redis-all'])) {
    $redisHost = getenv('REDIS_HOST') ?: 'localhost';
    $redisPort = (int)(getenv('REDIS_PORT') ?: 6379);
    $redisPassword = getenv('REDIS_PASSWORD') ?: null;
    $redisDatabase = (int)(getenv('REDIS_DATABASE') ?: 0);
    $redisPrefix = getenv('REDIS_PREFIX') ?: 'eap:';

    if (!class_exists('Redis')) {
        output("[SKIP] Redis extension not loaded");
    } else {
        try {
            $redis = new Redis();
            $connected = $redis->connect($redisHost, $redisPort, 2.5);

            if (!$connected) {
                throw new Exception("Could not connect to {$redisHost}:{$redisPort}");
            }

            if ($redisPassword !== null && $redisPassword !== '') {
                $redis->auth($redisPassword);
            }

            $redis->select($redisDatabase);

            if (isset($options['redis-all'])) {
                // Clear entire database (CAUTION!)
                $redis->flushDb();
                $cleared[] = 'Redis (entire database)';
                output("[OK] Redis database {$redisDatabase} flushed completely");
            } else {
                // Clear only EAP keys (safe)
                $patterns = [
                    $redisPrefix . 'dbpool:*',
                    $redisPrefix . 'cache:*',
                    $redisPrefix . 'session:*',
                    $redisPrefix . 'log:*',
                ];

                $totalKeys = 0;
                foreach ($patterns as $pattern) {
                    $keys = $redis->keys($pattern);
                    if (!empty($keys)) {
                        // Remove prefix that Redis adds back
                        $keysToDelete = array_map(function($key) use ($redisPrefix) {
                            return str_replace($redisPrefix, '', $key);
                        }, $keys);

                        foreach ($keys as $key) {
                            $redis->del($key);
                            $totalKeys++;
                        }
                    }
                }

                $cleared[] = "Redis ({$totalKeys} keys)";
                output("[OK] Redis: {$totalKeys} EAP keys cleared");

                // Show what was cleared
                output("     - Pattern: {$redisPrefix}*");
                output("     - Database: {$redisDatabase}");
            }

            $redis->close();

        } catch (Exception $e) {
            $errors[] = 'Redis: ' . $e->getMessage();
            output("[ERROR] Redis: " . $e->getMessage());
        }
    }
}

// ============================================================================
// Summary
// ============================================================================
output("\n------------------------------------");

if (!empty($cleared)) {
    output("Cleared: " . implode(', ', $cleared));
}

if (!empty($errors)) {
    output("Errors: " . implode(', ', $errors));
    exit(1);
}

output("\nCache clear complete!");
exit(0);
