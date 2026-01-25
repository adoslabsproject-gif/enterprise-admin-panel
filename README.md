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

### Step 1: Install via Composer

```bash
composer require ados-labs/enterprise-admin-panel
```

### Step 2: Start Docker Services

```bash
cd vendor/ados-labs/enterprise-admin-panel/elf
docker compose up -d
```

Services started:
- PostgreSQL: `localhost:5432` (admin/secret)
- Redis: `localhost:6379`
- Mailpit: `localhost:8025` (email testing UI)

### Step 3: Install Package Dependencies

```bash
cd vendor/ados-labs/enterprise-admin-panel
composer install
```

### Step 4: Run Installation

```bash
cd elf
php install.php
```

This command:
1. Runs database migrations
2. Creates admin user
3. Generates master token (save it!)
4. Shows the secure admin URL (shown once!)

Output example:
```
╔══════════════════════════════════════════════════════════════╗
║                    INSTALLATION COMPLETE                      ║
╠══════════════════════════════════════════════════════════════╣
║  Admin Email:     admin@example.com                          ║
║  Admin Password:  Jk8mP2xL9nQ4wR7v                           ║
║  Master Token:    a1b2c3d4e5f6...                            ║
║                                                              ║
║  ADMIN URL (SAVE THIS - SHOWN ONLY ONCE):                    ║
║  http://localhost:8080/x-7f3e8a2d9c1b6f4e.../login          ║
╚══════════════════════════════════════════════════════════════╝
```

### Step 5: Start PHP Server

```bash
php -S localhost:8080 -t public
```

(You should already be in `vendor/ados-labs/enterprise-admin-panel` from Step 3)

### Step 6: Access Admin Panel

Open the URL from Step 4 in your browser.

---

## CLI Commands

All commands are in the `elf/` directory.

### Generate Master Token

```bash
php elf/token-master-generate.php
```

Use this token to:
- Get the current admin URL
- Create emergency recovery tokens
- Change admin password

### Create Emergency Token (Bypass 2FA)

```bash
php elf/token-emergency-create.php --master-token=YOUR_MASTER_TOKEN
```

Creates a one-time token to bypass 2FA if you lose access.

### Use Emergency Token

```bash
php elf/token-emergency-use.php --token=EMERGENCY_TOKEN
```

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
- Argon2id hashing
- Account lockout after 5 failed attempts

### Recovery Process

If locked out:
1. Generate emergency token: `php elf/token-emergency-create.php --master-token=...`
2. Use it on the login page or via CLI
3. Token is single-use and expires in 24 hours

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
│   ├── token-master-generate.php
│   ├── token-emergency-create.php
│   └── token-emergency-use.php
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

- [URL Security](docs/URL-SECURITY.md) - How cryptographic URLs work
- [2FA Setup](docs/2FA-SETUP.md) - Configure multi-channel authentication
- [Module Development](docs/MODULES.md) - Create custom admin modules
- [Framework Architecture](docs/FRAMEWORK.md) - How packages integrate

---

## Troubleshooting

### "404 Not Found" on /admin

Expected behavior. Use the URL from installation or run:
```bash
php elf/token-master-generate.php
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

You need database access to generate a new one:
```bash
php elf/token-master-generate.php --force-new
```

---

## License

MIT License - see [LICENSE](LICENSE)

---

## Contributing

Issues and pull requests welcome at:
https://github.com/adoslabsproject-gif/enterprise-admin-panel
