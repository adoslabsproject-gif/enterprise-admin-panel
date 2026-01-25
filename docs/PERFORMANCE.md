# Performance Configuration Guide

This guide covers all performance optimizations available in Enterprise Admin Panel.

## Table of Contents

- [Quick Setup](#quick-setup)
- [OPcache Configuration](#opcache-configuration)
- [Redis Configuration](#redis-configuration)
- [Database Pool Configuration](#database-pool-configuration)
- [Cache Management](#cache-management)
- [Clearing Caches](#clearing-caches)

---

## Quick Setup

Use the built-in setup tool for automatic OPcache configuration:

```bash
# Check current OPcache status
php vendor/ados-labs/enterprise-admin-panel/elf/opcache-setup.php --check

# Generate optimized configuration file
php vendor/ados-labs/enterprise-admin-panel/elf/opcache-setup.php --generate

# Install and restart PHP-FPM (production - requires sudo)
sudo php vendor/ados-labs/enterprise-admin-panel/elf/opcache-setup.php --install --fpm-restart
```

---

## OPcache Configuration

OPcache significantly improves PHP performance by caching compiled bytecode.

### Enable OPcache with Preloading

Use the automated setup tool (recommended):

```bash
php elf/opcache-setup.php --generate
```

Or manually add to your `php.ini`:

```ini
; Enable OPcache
opcache.enable=1
opcache.enable_cli=1

; Preload enterprise-admin-panel classes
opcache.preload=/path/to/vendor/ados-labs/enterprise-admin-panel/preload.php
opcache.preload_user=www-data

; Memory settings (adjust based on your server)
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000

; Validation (set to 0 in production, 1 in development)
opcache.validate_timestamps=0
opcache.revalidate_freq=0

; JIT compilation (PHP 8.0+)
opcache.jit=1255
opcache.jit_buffer_size=128M
```

### Development Settings

For development, enable timestamp validation:

```ini
opcache.validate_timestamps=1
opcache.revalidate_freq=2
```

---

## Redis Configuration

Redis provides distributed state for:
- Circuit breaker state sharing across processes
- Distributed metrics aggregation
- Session storage (optional)
- Cache layer

### Default Configuration

Redis is **ENABLED by default**. Configure via `.env`:

```env
# Redis Configuration
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=your-secure-password
REDIS_DATABASE=0
REDIS_PREFIX=eap:
```

### Database Pool Redis Settings

Configure in your application:

```php
use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use AdosLabs\AdminPanel\Database\Pool\PoolConfig;

$config = (new PoolConfig())
    ->driver('pgsql')
    ->host('localhost')
    ->database('admin_panel')
    ->credentials('admin', 'secret')
    ->redis(
        enabled: true,           // Enable Redis (default: true)
        host: 'localhost',       // Redis host
        port: 6379,              // Redis port
        password: 'secret',      // Redis password (null if none)
        database: 0              // Redis database number
    );

$pool = new DatabasePool($config);
```

### Disable Redis (Local-Only Mode)

If you don't have Redis, disable it:

```php
$config = (new PoolConfig())
    ->driver('pgsql')
    ->database('admin_panel')
    ->credentials('admin', 'secret')
    ->disableRedis();  // Use local-only mode
```

Or via `.env`:

```env
REDIS_ENABLED=false
```

---

## Database Pool Configuration

### PostgreSQL

```env
DB_DRIVER=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=admin_panel
DB_USERNAME=admin
DB_PASSWORD=your-secure-password
```

### MySQL

```env
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=admin_panel
DB_USERNAME=admin
DB_PASSWORD=your-secure-password
```

### Pool Size Settings

```php
$config = (new PoolConfig())
    ->driver('pgsql')
    ->database('admin_panel')
    ->credentials('admin', 'secret')
    ->poolSize(
        min: 2,    // Minimum connections (warm pool)
        max: 20    // Maximum connections
    )
    ->timeouts(
        idle: 300,        // Close idle connections after 5 minutes
        maxLifetime: 3600, // Max connection age: 1 hour
        wait: 5           // Wait timeout for connection
    );
```

### Circuit Breaker Settings

```php
$config = (new PoolConfig())
    ->circuitBreaker(
        threshold: 5,      // Open after 5 failures
        recoveryTime: 30   // Retry after 30 seconds
    );
```

---

## Cache Management

### Multi-Layer Cache Architecture

1. **Static (in-process)**: Zero latency, per-request
2. **APCu**: Sub-millisecond, shared between requests
3. **Redis**: Millisecond, shared between servers
4. **Database**: Fallback, persistent storage

### Cache Configuration

```env
# Cache driver priority
CACHE_DRIVER=redis
CACHE_PREFIX=eap_cache:
CACHE_TTL=3600

# APCu (local cache)
APCU_ENABLED=true
APCU_TTL=300
```

---

## Clearing Caches

### Clear All Caches (Recommended after deployment)

```bash
# Create a cache clear script
php -r "
// Clear OPcache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo \"OPcache cleared\\n\";
}

// Clear APCu
if (function_exists('apcu_clear_cache')) {
    apcu_clear_cache();
    echo \"APCu cleared\\n\";
}

// Clear Redis
\$redis = new Redis();
\$redis->connect('localhost', 6379);
\$redis->auth('your-password');  // If password set
\$redis->select(0);

// Clear only EAP keys (safe)
\$keys = \$redis->keys('eap:*');
if (!empty(\$keys)) {
    \$redis->del(\$keys);
    echo 'Redis: ' . count(\$keys) . \" keys cleared\\n\";
}

echo \"All caches cleared!\\n\";
"
```

### Clear OPcache Only

```bash
# Via PHP
php -r "opcache_reset(); echo 'OPcache cleared';"

# Or restart PHP-FPM
sudo systemctl restart php8.3-fpm
```

### Clear Redis Only

```bash
# Connect to Redis CLI
redis-cli

# Authenticate if password set
AUTH your-password

# Select database
SELECT 0

# Clear only enterprise-admin-panel keys (SAFE)
EVAL "local keys = redis.call('KEYS', 'eap:*'); for i,k in ipairs(keys) do redis.call('DEL', k) end; return #keys" 0

# OR flush entire database (CAUTION: deletes ALL data in database 0)
FLUSHDB
```

### Clear Database Pool State in Redis

```bash
redis-cli
AUTH your-password
SELECT 0

# Clear circuit breaker state
DEL eap:dbpool:circuit:admin_panel

# Clear metrics
KEYS eap:dbpool:metrics:*
DEL eap:dbpool:metrics:global
DEL eap:dbpool:metrics:worker:*
```

### Clear APCu

```bash
php -r "apcu_clear_cache(); echo 'APCu cleared';"
```

---

## CLI Helper Script

Create `elf/cache-clear.php`:

```php
#!/usr/bin/env php
<?php
/**
 * Clear all caches
 * Usage: php elf/cache-clear.php [--opcache] [--apcu] [--redis] [--all]
 */

$options = getopt('', ['opcache', 'apcu', 'redis', 'all', 'help']);

if (isset($options['help']) || empty($options)) {
    echo "Usage: php elf/cache-clear.php [options]\n";
    echo "Options:\n";
    echo "  --opcache  Clear OPcache\n";
    echo "  --apcu     Clear APCu cache\n";
    echo "  --redis    Clear Redis cache (EAP keys only)\n";
    echo "  --all      Clear all caches\n";
    exit(0);
}

$all = isset($options['all']);

// OPcache
if ($all || isset($options['opcache'])) {
    if (function_exists('opcache_reset')) {
        opcache_reset();
        echo "[OK] OPcache cleared\n";
    } else {
        echo "[SKIP] OPcache not available\n";
    }
}

// APCu
if ($all || isset($options['apcu'])) {
    if (function_exists('apcu_clear_cache')) {
        apcu_clear_cache();
        echo "[OK] APCu cleared\n";
    } else {
        echo "[SKIP] APCu not available\n";
    }
}

// Redis
if ($all || isset($options['redis'])) {
    // Load .env
    $envFile = dirname(__DIR__) . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (str_starts_with($line, '#')) continue;
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                putenv(trim($key) . '=' . trim($value));
            }
        }
    }

    try {
        $redis = new Redis();
        $redis->connect(
            getenv('REDIS_HOST') ?: 'localhost',
            (int)(getenv('REDIS_PORT') ?: 6379)
        );

        if ($pass = getenv('REDIS_PASSWORD')) {
            $redis->auth($pass);
        }

        $redis->select((int)(getenv('REDIS_DATABASE') ?: 0));

        // Only clear EAP keys
        $prefix = getenv('REDIS_PREFIX') ?: 'eap:';
        $keys = $redis->keys($prefix . '*');

        if (!empty($keys)) {
            $redis->del($keys);
            echo "[OK] Redis: " . count($keys) . " keys cleared\n";
        } else {
            echo "[OK] Redis: no keys to clear\n";
        }
    } catch (Exception $e) {
        echo "[ERROR] Redis: " . $e->getMessage() . "\n";
    }
}

echo "\nCache clear complete!\n";
```

Make executable:

```bash
chmod +x elf/cache-clear.php
```

Usage:

```bash
# Clear all caches
php elf/cache-clear.php --all

# Clear only OPcache (after code changes)
php elf/cache-clear.php --opcache

# Clear only Redis (reset circuit breaker, metrics)
php elf/cache-clear.php --redis
```

---

## Production Checklist

- [ ] OPcache enabled with preloading
- [ ] `opcache.validate_timestamps=0`
- [ ] JIT enabled (`opcache.jit=1255`)
- [ ] Redis password set
- [ ] Database pool configured with appropriate min/max connections
- [ ] Circuit breaker thresholds tuned
- [ ] Monitoring in place for Redis and database pool metrics
- [ ] Cache clear script ready for deployments

---

## Monitoring

### Get Pool Statistics

```php
$pool = Container::get('db.pool');
$stats = $pool->getStats();

// Stats include:
// - config: driver, host, database, min/max connections, redis_enabled
// - pool: total, idle, in_use, available
// - metrics: local and distributed (from Redis)
// - circuit_breaker: state, failure_count, trip_count
// - redis: enabled, connected, worker_count
```

### Health Check Endpoint

```php
// In your health check route
$pool = Container::get('db.pool');
$health = $pool->getHealthSummary();

return json_encode([
    'status' => $health['healthy'] ? 'ok' : 'degraded',
    'circuit_breaker' => $health['circuit_breaker_state'],
    'pool_utilization' => $health['pool_utilization'] . '%',
    'error_rate' => $health['error_rate'] . '%',
    'avg_query_time_ms' => $health['avg_query_time_ms'],
]);
```
