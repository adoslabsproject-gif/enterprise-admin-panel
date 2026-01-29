# Security Documentation

## Overview

Enterprise Admin Panel implements comprehensive security measures to achieve **A+ security rating** across all attack vectors.

## Security Features Summary

| Feature | Status | Description |
|---------|--------|-------------|
| Cryptographic URLs | Active | 256-bit HMAC-SHA256 signatures |
| 2FA | Default | Email, Telegram, Discord, Slack, TOTP |
| CSRF Protection | All forms | Stateless tokens with 60-minute validity |
| Session Security | Hardened | IP binding, device tracking, auto-rotation |
| Password Security | Argon2id | With brute-force protection |
| Emergency Access | CLI only | One-time tokens bypassing all auth |
| XSS Prevention | All output | `esc()` helper with ENT_QUOTES |
| SQL Injection | Protected | Prepared statements only |
| Rate Limiting | API/Auth | Sliding window algorithm |
| HTTPS Enforcement | Middleware | Automatic redirect in production |

## Cryptographic URL Security

See [URL-SECURITY.md](URL-SECURITY.md) for full details.

### Key Points

- **256-bit entropy** using HMAC-SHA256
- **User-bound** URLs (each admin has unique URL)
- **Time-limited** with automatic rotation
- **Multiple patterns** to prevent fingerprinting

```
Traditional:  /admin
This system:  /x-d4e8f2a9c6b1d5f3e7a2b8c4d9f1e6a3b7c2d8e4f9a1b5c6d2e7f3a8b4c9d1e5f2/login
```

### URL Rotation Events

- Logout
- Password change
- Failed login threshold (5 attempts)
- Security events
- Manual CLI rotation

## Two-Factor Authentication (2FA)

### Supported Methods

| Method | Description |
|--------|-------------|
| Email | 6-digit code via SMTP |
| Telegram | Bot notification |
| Discord | Webhook notification |
| Slack | Webhook notification |
| TOTP | Time-based authenticator apps |

### Configuration

```php
// 2FA is enabled by default for all users
// Can be configured per-user in admin panel
```

### Recovery Codes

- Generated on 2FA setup
- 10 single-use codes
- Stored hashed (Argon2id)
- Regenerated on use

## CSRF Protection

### Implementation

```php
// Stateless CSRF using HMAC
$token = hash_hmac(
    'sha256',
    $userId . $timestamp . $formId,
    $appKey
);
```

### Token Validation

- 60-minute validity window
- User-bound (cannot reuse across users)
- Form-bound (cannot reuse across forms)
- Constant-time comparison

### Usage in Forms

```php
<input type="hidden" name="_csrf" value="<?= esc($csrfToken) ?>">
```

## Session Security

### Features

| Feature | Description |
|---------|-------------|
| IP Binding | Optional, validates IP on each request |
| Device Tracking | Fingerprints user agent |
| Auto-rotation | Session ID rotates on privilege change |
| Secure Cookie | HttpOnly, Secure, SameSite=Strict |
| Idle Timeout | Configurable (default 30 minutes) |
| Absolute Timeout | Maximum session lifetime |

### Session Storage

```sql
CREATE TABLE admin_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id BIGINT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    payload TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP NOT NULL,
    expires_at TIMESTAMP NOT NULL
);
```

## Password Security

### Hashing Algorithm

```php
// Argon2id with recommended parameters
password_hash($password, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,  // 64 MB
    'time_cost' => 4,        // 4 iterations
    'threads' => 3,          // 3 parallel threads
]);
```

### Brute-Force Protection

| Threshold | Action |
|-----------|--------|
| 5 failed attempts | Account locked 15 minutes |
| 10 failed attempts | Account locked 1 hour |
| 20 failed attempts | Account locked 24 hours |

### Password Requirements

- Minimum 12 characters
- At least 1 uppercase letter
- At least 1 lowercase letter
- At least 1 number
- At least 1 special character
- Not in common password list

## Emergency Access

For situations where normal login is impossible.

### Generate Emergency Token

```bash
php elf/token-emergency-create.php \
  --token=MASTER_CLI_TOKEN \
  --email=admin@example.com \
  --password=YOUR_PASSWORD
```

### Token Properties

- **One-time use**: Invalidated after first use
- **Time-limited**: 15-minute expiration
- **Bypasses**: Login and 2FA
- **Logged**: Full audit trail
- **CLI-only**: Cannot be generated via web

## XSS Prevention

### Helper Functions

```php
// General HTML escaping
echo esc($userInput);
// Uses: htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')

// Attribute escaping
echo '<input value="' . esc_attr($value) . '">';

// URL escaping (validates scheme)
echo '<a href="' . esc_url($url) . '">Link</a>';
// Only allows: http, https, mailto
```

### Content Security Policy

Recommended headers (set in web server or middleware):

```
Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
```

## SQL Injection Protection

### Prepared Statements

All database queries use PDO prepared statements:

```php
$stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
```

### Table Name Validation

```php
// Blocked patterns in table names
'pg_*'              // PostgreSQL system
'mysql.*'           // MySQL system
'information_schema' // Standard system
'sys.*'             // System tables
'sqlite_*'          // SQLite system

// Validation rules
- Must start with letter or underscore
- Only alphanumeric and underscore allowed
- Maximum 63 characters
```

## Rate Limiting

### Sliding Window Algorithm

```php
$limiter = new RateLimiter();

// Check + increment in one call
$result = $limiter->attempt('login:' . $ip, 'auth');
// Returns: [allowed, remaining, reset_at, retry_after]
```

### Default Limits

| Category | Limit | Window |
|----------|-------|--------|
| Default | 60 req | 1 minute |
| Auth | 5 req | 15 minutes |
| Sensitive | 10 req | 1 minute |
| Test | 5 req | 5 minutes |

### Storage Backends

- **Redis**: Primary (if available) - distributed
- **Memory**: Fallback - per-process

## Middleware Stack

Recommended order:

```php
$app->pipe(new HttpsMiddleware());       // Force HTTPS
$app->pipe(new AccessLogMiddleware());   // Log requests
$app->pipe(new SessionGuard());          // Session security
$app->pipe(new CsrfMiddleware());        // CSRF protection
$app->pipe(new AuthMiddleware());        // Authentication
```

## Security Headers

### HttpsMiddleware

Automatically redirects HTTP to HTTPS in production:

```php
// Detects production via APP_ENV
if ($isProduction && !$isHttps) {
    return redirect('https://' . $host . $uri);
}
```

### Recommended Headers

Configure in web server (Nginx/Apache) or application:

```nginx
# Nginx example
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Content-Security-Policy "default-src 'self'" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

## Database Pool Security

### Circuit Breaker

Protects against cascading failures:

```php
$breaker = new CircuitBreaker();

// States: CLOSED -> OPEN -> HALF_OPEN -> CLOSED
// Trips after 5 consecutive failures
// Auto-recovers after 30 seconds
```

### Connection Security

- Connections validated before use (ping)
- Automatic cleanup of stale connections
- Maximum connection lifetime (1 hour)
- Pool exhaustion protection

## Audit Trail

All security events are logged:

| Event | Level | Channel |
|-------|-------|---------|
| Login success | INFO | security |
| Login failure | WARNING | auth |
| Account locked | ERROR | security |
| 2FA failure | WARNING | security |
| Password change | INFO | security |
| Session invalidation | WARNING | security |
| Emergency access | WARNING | security |

### Audit Log Table

```sql
CREATE TABLE audit_log (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100),
    entity_id BIGINT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    details JSONB,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Environment Variables

| Variable | Required | Description |
|----------|----------|-------------|
| `APP_KEY` | Yes | 64-char hex encryption key |
| `APP_ENV` | Yes | Environment (production, staging, local) |
| `APP_DEBUG` | No | Debug mode (false in production) |
| `DB_PASSWORD` | Yes | Database password |
| `REDIS_PASSWORD` | No | Redis password |
| `SMTP_PASSWORD` | No | SMTP password |

### Security Notes

- **Never commit `.env`** - Add to `.gitignore`
- **Use strong APP_KEY** - Generate with `openssl rand -hex 32`
- **Different keys per environment** - Production != staging
- **Rotate keys periodically** - Invalidates sessions

## Reporting Vulnerabilities

Please report security vulnerabilities to: security@adoslabs.com

**DO NOT** open public issues for security vulnerabilities.

## Security Checklist

### Before Production

- [ ] APP_ENV set to "production"
- [ ] APP_DEBUG set to "false"
- [ ] Strong APP_KEY generated (64 hex chars)
- [ ] HTTPS enforced
- [ ] 2FA enabled for all admins
- [ ] Emergency token generated and secured
- [ ] Audit logging enabled
- [ ] Rate limiting active
- [ ] Session timeouts configured
- [ ] Password policy enforced
- [ ] Database credentials secured
- [ ] Redis password set (if used)
- [ ] CSP headers configured
- [ ] Regular security updates applied
