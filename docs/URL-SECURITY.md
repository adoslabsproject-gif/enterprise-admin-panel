# Cryptographic URL Security

## How It Works

Traditional admin panels use predictable URLs like `/admin`. This panel generates cryptographically secure URLs that are:

1. **Unpredictable**: 256-bit entropy using HMAC-SHA256
2. **User-bound**: Each admin user has a unique URL
3. **Time-limited**: URLs rotate on logout and security events
4. **Verified**: Server validates URL signature on every request

## URL Structure

```
/x-{64-character-hex-signature}/login
```

Example:
```
/x-d4e8f2a9c6b1d5f3e7a2b8c4d9f1e6a3b7c2d8e4f9a1b5c6d2e7f3a8b4c9d1e5f2/login
```

## Generation Process

```php
$signature = hash_hmac(
    'sha256',
    $userId . $timestamp . $nonce,
    $appSecret
);

$url = '/x-' . $signature . '/login';
```

Components:
- `userId`: Database ID of the admin user
- `timestamp`: Unix timestamp of generation
- `nonce`: 16 bytes of cryptographically secure random data
- `appSecret`: APP_KEY from environment (64 hex characters)

## URL Patterns

The system uses multiple URL patterns to prevent fingerprinting:

| Pattern | Example |
|---------|---------|
| `/x-{hash}` | `/x-d4e8f2a9...` |
| `/admin-{hash}` | `/admin-7f3e8a2d...` |
| `/cp-{hash}` | `/cp-c3f8e2a7...` |
| `/_a/{hash}` | `/_a/9b1f6e4a...` |
| `/_sys/{hash}` | `/_sys/2d5c8b3f...` |
| `/_panel/{hash}` | `/_panel/e1a6d7c2...` |

Pattern is selected randomly during URL generation.

## Validation

Every request to admin URLs is validated:

```php
public function validateUrl(string $url, int $userId): bool
{
    // 1. Extract signature from URL
    $signature = $this->extractSignature($url);

    // 2. Look up in whitelist
    $entry = $this->findWhitelistEntry($signature, $userId);

    if (!$entry) {
        return false; // Unknown URL
    }

    // 3. Check expiration
    if ($entry['expires_at'] < time()) {
        return false; // Expired
    }

    // 4. Check IP binding (if enabled)
    if ($entry['bound_ip'] && $entry['bound_ip'] !== $_SERVER['REMOTE_ADDR']) {
        return false; // Wrong IP
    }

    return true;
}
```

## URL Rotation

URLs are automatically rotated on:

- **Logout**: Every logout generates a new URL
- **Password change**: Security precaution
- **Failed login threshold**: After 5 failed attempts
- **Manual rotation**: Via CLI command
- **Security events**: Suspicious activity detected

After rotation:
- Old URL immediately returns 404
- New URL must be retrieved via CLI
- All active sessions on old URL are invalidated

## IP Binding

Optional feature for maximum security:

```bash
php elf/install.php --bind-ip
```

When enabled:
- URL only works from the IP that generated it
- VPN users may have issues (IP changes)
- Recommended for static IP environments

## Database Storage

URLs are stored in `admin_url_whitelist`:

```sql
CREATE TABLE admin_url_whitelist (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES admin_users(id),
    url_hash VARCHAR(64) NOT NULL UNIQUE,
    pattern VARCHAR(20) NOT NULL,
    bound_ip VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    last_used_at TIMESTAMP,
    use_count INTEGER DEFAULT 0,
    revoked_at TIMESTAMP,
    revoked_reason VARCHAR(255)
);
```

## Security Comparison

| Aspect | Traditional `/admin` | This System |
|--------|---------------------|-------------|
| Discoverability | Trivial | Impossible |
| Brute force | Feasible | 2^256 attempts |
| URL sharing | Works | Per-user binding |
| Session replay | Possible | URL rotation |
| Fingerprinting | Easy | Multiple patterns |

## Attack Resistance

**Brute Force**:
- 2^256 possible combinations
- At 1 billion attempts/second: 3.7 × 10^60 years

**Rainbow Tables**:
- HMAC uses secret key, tables useless

**Timing Attacks**:
- Constant-time comparison used

**URL Guessing**:
- No pattern to exploit
- Multiple URL formats

## CLI Commands

Get current URL:
```bash
php elf/token-master-generate.php
# Shows URL after master token validation
```

Force URL rotation:
```bash
php elf/token-master-generate.php --rotate
# Invalidates current URL, generates new one
```

## Best Practices

1. **Store URL securely**: Use a password manager
2. **Don't bookmark**: URL may rotate
3. **Don't share**: URLs are per-user
4. **Use HTTPS**: Always in production
5. **Enable IP binding**: If you have static IP
6. **Regular rotation**: Consider scheduled rotation
