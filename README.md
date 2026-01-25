# Enterprise Admin Panel

**Enterprise Lightning Framework - Package 0**

Admin panel with cryptographic dynamic URLs. No predictable `/admin` endpoints.

---

## What This Does

Traditional admin panels use `/admin`. Attackers know this. They scan for it. They brute-force it.

This panel generates URLs like:
```
/x-d4e8f2a9c6b1d5f3e7a2b8c4d9f1e6a3b7c2d8e4f9a1b5c6d2e7f3a8b4c9d1e5f2/login
```

- 256-bit entropy (HMAC-SHA256)
- Per-user URL binding
- Rotates on logout
- `/admin` returns 404

---

## Requirements

- PHP 8.1+
- PostgreSQL 14+ or MySQL 8.0+
- Redis (optional, for sessions)
- Docker/OrbStack (for local development)

---

## Installation

### Quick Start (CLI - copy/paste one line at a time)

```bash
mkdir my-project && cd my-project
```

```bash
echo '{"require":{"ados-labs/enterprise-admin-panel":"dev-main"},"repositories":[{"type":"vcs","url":"git@github.com:adoslabsproject-gif/enterprise-admin-panel.git"}],"minimum-stability":"dev","prefer-stable":true}' > composer.json
```

```bash
composer install
```

```bash
cd vendor/ados-labs/enterprise-admin-panel/elf && docker compose up -d
```

```bash
cd .. && composer install
```

```bash
cd elf && php install.php --email=admin@example.com
```

```bash
cd .. && php -S localhost:8080 -t public
```

### Detailed Steps (for scripts)

<details>
<summary>Click to expand formatted version</summary>

#### Step 1: Create project and composer.json

```bash
mkdir my-project && cd my-project

cat > composer.json << 'EOF'
{
    "require": {
        "ados-labs/enterprise-admin-panel": "dev-main"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:adoslabsproject-gif/enterprise-admin-panel.git"
        }
    ],
    "minimum-stability": "dev",
    "prefer-stable": true
}
EOF
```

#### Step 2: Install package

```bash
composer install
```

#### Step 3: Start Docker Services

```bash
cd vendor/ados-labs/enterprise-admin-panel/elf
docker compose up -d
```

Services started:
- PostgreSQL: `localhost:5432` (admin/secret)
- Redis: `localhost:6379`
- Mailpit: `localhost:8025` (email testing UI)

#### Step 4: Install Package Dependencies

```bash
cd ..
composer install
```

#### Step 5: Run Installation

```bash
cd elf
php install.php --email=admin@example.com
```

This command:
1. Runs database migrations
2. Creates admin user with secure password
3. Generates master CLI token (save it!)
4. Shows the secure admin URL (shown once!)

**SAVE THE OUTPUT! It contains:**
- Admin URL (secret, never shown again)
- Admin Password (secure, with special characters)
- Master CLI Token (required for all CLI operations)

#### Step 6: Start PHP Server

```bash
cd ..
php -S localhost:8080 -t public
```

#### Step 7: Access Admin Panel

Open the URL from Step 5 in your browser.

</details>

---

## CLI Commands

All commands are in the `elf/` directory.

**Important:** All CLI commands (except `token-emergency-use.php`) require three authentication factors:
- `--token=` Master CLI token
- `--email=` Admin email
- `--password=` Admin password

This triple authentication prevents unauthorized access even if one factor is compromised.

### Change Password

```bash
php elf/password-change.php --token=MASTER_TOKEN --email=admin@example.com --password=CURRENT --new-password=NEW
```

Requirements for new password:
- Minimum 12 characters
- At least 1 number
- At least 1 special character (!@#$%^&*-_=+)

After password change:
- All active sessions are invalidated
- Master CLI token remains unchanged
- Admin URL remains unchanged

### Regenerate Master Token

```bash
php elf/token-master-regenerate.php --token=CURRENT_TOKEN --email=admin@example.com --password=PASSWORD
```

Generates a new master CLI token. The old token is immediately invalidated.

### Create Emergency Token (Bypass 2FA)

```bash
php elf/token-emergency-create.php --token=MASTER_TOKEN --email=admin@example.com --password=PASSWORD
```

Creates a one-time emergency token to bypass login (including 2FA) if you lose access.
Store this token offline (printed, in a safe).

### Use Emergency Token

```bash
php elf/token-emergency-use.php --token=EMERGENCY_TOKEN
```

Uses the emergency token to reveal the admin URL. The token is invalidated after use.
Or use it via the web interface: click "Emergency Recovery" on the login page.

---

## Features

### Cryptographic URL Security

| Feature | Traditional | This Panel |
|---------|-------------|------------|
| URL Pattern | `/admin` | Random 64-char hash |
| Entropy | 0 bits | 256 bits |
| User Binding | No | Yes |
| Rotation | Never | On logout |
| Brute Force | Easy | 2^256 combinations |

### Multi-Channel 2FA

Supported channels:
- Email (default, uses Mailpit in development)
- Telegram
- Discord
- Slack
- TOTP (Google Authenticator, Authy)

2FA is enabled by default. To disable for testing:
```sql
UPDATE admin_users SET two_factor_enabled = false WHERE email = 'admin@example.com';
```

### Session Management

- 60-minute session lifetime
- Heartbeat every 30 seconds
- Warning dialog 5 minutes before expiry
- Auto-logout on expiry
- URL rotation on logout

### Modular Architecture

Install additional packages to add tabs automatically:

```bash
composer require ados-labs/enterprise-security-shield
# Adds: Security, WAF, Honeypot, Banned IPs tabs

composer require ados-labs/enterprise-psr3-logger
# Adds: Logs, Channels, Telegram Alerts tabs

composer require ados-labs/database-pool
# Adds: Database Pool, Connections, Metrics tabs
```

Modules are auto-discovered from `composer.json`:
```json
{
    "extra": {
        "admin-panel": {
            "module": "YourNamespace\\YourModule"
        }
    }
}
```

---

## Configuration

### Environment Variables

Copy `.env.example` to `.env`:

```bash
cp .env.example .env
```

Required variables:
```bash
# Database
DB_DRIVER=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=admin_panel
DB_USERNAME=admin
DB_PASSWORD=secret

# Security (generate with: php -r "echo bin2hex(random_bytes(32));")
APP_KEY=your-64-char-hex-key
RECOVERY_MASTER_KEY=another-64-char-hex-key

# Email (Mailpit for development)
SMTP_HOST=localhost
SMTP_PORT=1025
```

### Database Support

PostgreSQL (recommended):
```bash
php elf/install.php --driver=pgsql
```

MySQL:
```bash
php elf/install.php --driver=mysql --port=3306
```

---

## Security

### Password Requirements

- Minimum 12 characters
- At least 1 number
- At least 1 special character
- Argon2id hashing
- Account lockout after 5 failed attempts

### Master Token Security

The master CLI token is:
- Generated once during installation (shown only once!)
- Hashed with Argon2id in the database
- Required for all CLI operations
- Independent from your password (changing password doesn't affect token)

### Recovery Process

If locked out:
1. Create emergency token: `php elf/token-emergency-create.php --token=MASTER_TOKEN --email=EMAIL --password=PASSWORD`
2. Use it via CLI: `php elf/token-emergency-use.php --token=EMERGENCY_TOKEN`
3. Or use it in browser: `/emergency-login?token=EMERGENCY_TOKEN`
4. Token is single-use and expires in 30 days by default

### URL Rotation

URLs are rotated:
- On every logout
- On password change
- On security events
- Manually via CLI

After rotation, old URLs return 404 immediately.

---

## Project Structure

```
enterprise-admin-panel/
├── elf/                    # CLI tools and Docker
│   ├── docker-compose.yml  # PostgreSQL, MySQL, Redis, Mailpit
│   ├── install.php         # First-time installation
│   ├── password-change.php # Change admin password
│   ├── token-master-regenerate.php # Regenerate master token
│   ├── token-emergency-create.php  # Create emergency token
│   └── token-emergency-use.php     # Use emergency token
├── public/                 # Web root
│   ├── index.php           # Entry point
│   ├── css/                # Stylesheets (CSP compliant)
│   └── js/                 # JavaScript
├── src/
│   ├── Controllers/        # Request handlers
│   ├── Services/           # Business logic
│   ├── Middleware/         # Auth, CSRF, HTTPS
│   ├── Modules/            # Module system
│   ├── Database/           # Migrations
│   └── Views/              # PHP templates
└── docs/                   # Additional documentation
```

---

## Enterprise Lightning Framework

This is Package 0 of the Enterprise Lightning Framework. The framework is modular - install only what you need:

| Package | Purpose |
|---------|---------|
| **enterprise-admin-panel** (this) | Admin interface with secure URLs |
| enterprise-bootstrap | Application foundation, DI, caching |
| enterprise-security-shield | WAF, rate limiting, bot protection |
| enterprise-psr3-logger | Logging with database/file handlers |
| database-pool | Connection pooling, circuit breaker |

Each package works standalone. When installed together, they integrate automatically.

See [docs/FRAMEWORK.md](docs/FRAMEWORK.md) for framework architecture.

---

## Documentation

- [CLI Commands](docs/CLI-COMMANDS.md) - Full CLI reference with examples
- [URL Security](docs/URL-SECURITY.md) - How cryptographic URLs work
- [2FA Setup](docs/2FA-SETUP.md) - Configure multi-channel authentication
- [Module Development](docs/MODULES.md) - Create custom admin modules
- [Framework Architecture](docs/FRAMEWORK.md) - How packages integrate

---

## Troubleshooting

### "404 Not Found" on /admin

Expected behavior. The admin URL is secret and was shown only during installation.
If you lost it, use an emergency token:
```bash
php elf/token-emergency-use.php --token=YOUR_EMERGENCY_TOKEN
```

### "Invalid credentials"

Reset failed attempts:
```sql
UPDATE admin_users SET failed_login_attempts = 0, locked_until = NULL
WHERE email = 'admin@example.com';
```

### 2FA codes not arriving

1. Check Mailpit: http://localhost:8025
2. Verify SMTP settings in `.env`

### Lost master token

If you lost your master token, you need to reset it manually in the database:
```sql
-- First, generate a new token hash manually:
-- Run: php -r "echo password_hash('your-new-token', PASSWORD_ARGON2ID);"
-- Then update the database:
UPDATE admin_users SET cli_token_hash = 'PASTE_HASH_HERE' WHERE email = 'admin@example.com';
```

Or reinstall by dropping all tables and running `php elf/install.php` again.

---

## License

MIT License - see [LICENSE](LICENSE)

---

## Contributing

Issues and pull requests welcome at:
https://github.com/adoslabsproject-gif/enterprise-admin-panel
