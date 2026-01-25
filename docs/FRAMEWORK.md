# Enterprise Lightning Framework

## Architecture

The Enterprise Lightning Framework (ELF) is a modular PHP framework. Each package works standalone but integrates automatically when installed together.

```
┌─────────────────────────────────────────────────────────────────┐
│                      YOUR APPLICATION                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌──────────────────┐  ┌──────────────────┐  ┌───────────────┐ │
│  │  Admin Panel     │  │  Security Shield │  │  PSR3 Logger  │ │
│  │  (Package 0)     │  │  (Package 2)     │  │  (Package 3)  │ │
│  │                  │  │                  │  │               │ │
│  │ • Secure URLs    │  │ • WAF            │  │ • DB Handler  │ │
│  │ • 2FA            │  │ • Rate Limiting  │  │ • File Handler│ │
│  │ • Module System  │  │ • Bot Protection │  │ • Telegram    │ │
│  └────────┬─────────┘  └────────┬─────────┘  └───────┬───────┘ │
│           │                     │                     │         │
│           └─────────────────────┼─────────────────────┘         │
│                                 │                               │
│                    ┌────────────┴────────────┐                  │
│                    │     Bootstrap           │                  │
│                    │     (Package 1)         │                  │
│                    │                         │                  │
│                    │ • DI Container          │                  │
│                    │ • Configuration         │                  │
│                    │ • Caching               │                  │
│                    │ • should_log()          │                  │
│                    └────────────┬────────────┘                  │
│                                 │                               │
│                    ┌────────────┴────────────┐                  │
│                    │    Database Pool        │                  │
│                    │    (Package 4)          │                  │
│                    │                         │                  │
│                    │ • Connection Pooling    │                  │
│                    │ • Circuit Breaker       │                  │
│                    │ • Query Monitoring      │                  │
│                    └─────────────────────────┘                  │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

## Packages

### Package 0: enterprise-admin-panel (this package)

Admin interface with:
- Cryptographic dynamic URLs (256-bit HMAC-SHA256)
- Multi-channel 2FA (Email, Telegram, Discord, Slack, TOTP)
- Module auto-discovery
- Session management with heartbeat

### Package 1: enterprise-bootstrap

Application foundation:
- Dependency Injection container
- Configuration management
- Multi-layer caching (memory → Redis → database)
- `should_log()` function for log filtering
- Environment handling

### Package 2: enterprise-security-shield

Security layer:
- Web Application Firewall (WAF)
- Rate limiting (per IP, per user, per endpoint)
- Bot detection and verification
- Honeypot traps
- IP banning
- Threat scoring

### Package 3: enterprise-psr3-logger

PSR-3 compliant logging:
- Database handler
- File handler
- Telegram notifications
- Channel-based log levels
- Log viewer in admin panel

### Package 4: database-pool

Database management:
- Connection pooling
- Circuit breaker pattern
- Query monitoring
- Slow query detection
- Connection health checks

## Integration

### Auto-Discovery

When you install a package, it registers itself via `composer.json`:

```json
{
    "extra": {
        "admin-panel": {
            "module": "AdosLabs\\SecurityShield\\AdminModule",
            "priority": 10
        }
    }
}
```

The admin panel scans installed packages and auto-registers modules.

### should_log() Integration

The `enterprise-bootstrap` package provides `should_log()`:

```php
// In enterprise-psr3-logger
public function log($level, $message, array $context = []): void
{
    // Check if we should log
    if (function_exists('should_log') && !should_log($this->channel, $level)) {
        return;
    }

    // ... write log
}
```

The admin panel provides UI to configure log levels per channel. Bootstrap caches these decisions for performance.

### Database Pool Integration

All packages can use the database pool when available:

```php
// Without pool
$pdo = new PDO($dsn, $user, $pass);

// With pool (if installed)
$pdo = db(); // Returns pooled connection
```

## Installation Order

Install packages in any order. They detect each other and integrate automatically.

Recommended order for full stack:
```bash
# 1. Admin Panel (creates base tables)
composer require ados-labs/enterprise-admin-panel
php vendor/ados-labs/enterprise-admin-panel/elf/install.php

# 2. Bootstrap (provides helpers)
composer require ados-labs/enterprise-bootstrap

# 3. Logger (uses should_log from bootstrap)
composer require ados-labs/enterprise-psr3-logger

# 4. Security Shield (uses logger)
composer require ados-labs/enterprise-security-shield

# 5. Database Pool (used by all)
composer require ados-labs/database-pool
```

## Standalone Usage

Each package works independently:

### Admin Panel Only
```bash
composer require ados-labs/enterprise-admin-panel
```
- Full admin interface
- Secure URLs
- 2FA
- No dependencies on other packages

### Logger Only
```bash
composer require ados-labs/enterprise-psr3-logger
```
- PSR-3 compliant
- File and database handlers
- Works without admin panel (no UI config)

### Security Shield Only
```bash
composer require ados-labs/enterprise-security-shield
```
- WAF and rate limiting
- Works without admin panel (config via code)

## Configuration

### With Admin Panel

All configuration through web UI:
- Log levels per channel
- WAF rules
- Rate limits
- Module settings

### Without Admin Panel

Configure via code or environment:

```php
// Logger
$logger = new Logger('app');
$logger->pushHandler(new DatabaseHandler($pdo, 'warning'));

// Security Shield
$shield = new SecurityShield([
    'rate_limit' => 100,
    'block_duration' => 3600,
]);
```

## Events

Packages communicate via events:

```php
// Security Shield emits
$events->emit('security.threat_detected', [
    'ip' => $ip,
    'score' => $score,
]);

// Logger listens
$events->on('security.threat_detected', function ($data) {
    $this->logger->warning('Threat detected', $data);
});

// Admin Panel listens
$events->on('security.ip_banned', function ($data) {
    // Update UI, send notification
});
```

## Database Schema

Each package creates its own tables with `elf_` prefix:

| Package | Tables |
|---------|--------|
| Admin Panel | `admin_users`, `admin_sessions`, `admin_url_whitelist`, `admin_modules` |
| Logger | `logs`, `log_channels` |
| Security Shield | `security_events`, `ip_bans`, `rate_limits`, `waf_rules` |
| Database Pool | (no tables, uses Redis) |

## Performance

### Caching Strategy

```
Request
   │
   ▼
┌─────────────┐
│   Memory    │ ← First check (0.001ms)
└──────┬──────┘
       │ miss
       ▼
┌─────────────┐
│    Redis    │ ← Second check (0.1ms)
└──────┬──────┘
       │ miss
       ▼
┌─────────────┐
│  Database   │ ← Final check (1-5ms)
└─────────────┘
       │
       ▼
   Cache result
```

### Connection Pooling

Without pool:
- New connection per request
- ~10ms overhead

With pool:
- Reuse existing connections
- ~0.1ms overhead
- Automatic retry on failure
- Circuit breaker for failing databases

## Extending

### Custom Module

Create your own admin module:

```php
class MyModule extends BaseModule
{
    public function getName(): string { return 'my-module'; }
    public function getTabs(): array { return [...]; }
    public function getRoutes(): array { return [...]; }
}
```

### Custom Log Handler

Add handlers to the logger:

```php
class SlackHandler extends AbstractHandler
{
    public function write(array $record): void
    {
        // Send to Slack
    }
}

$logger->pushHandler(new SlackHandler($webhookUrl));
```

### Custom Security Rule

Add WAF rules:

```php
$shield->addRule(new CustomRule([
    'pattern' => '/evil-pattern/',
    'action' => 'block',
    'score' => 100,
]));
```

## Requirements

All packages require:
- PHP 8.1+
- ext-json
- ext-pdo
- ext-mbstring

Optional:
- ext-redis (for caching)
- ext-openssl (for encryption)
- PostgreSQL 14+ or MySQL 8.0+
