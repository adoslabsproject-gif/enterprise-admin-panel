# Enterprise Admin Panel

**Enterprise Lightning Framework - Package 0**

Admin panel with cryptographic dynamic URLs. No predictable `/admin` endpoints.

> **Documentation**: See the [`docs/`](docs/) folder for complete guides:
> - [Quick Start](docs/QUICK_START.md) - Get running in 5 minutes
> - [CLI Commands](docs/CLI-COMMANDS.md) - All installation options (PostgreSQL/MySQL, etc.)
> - [Performance](docs/PERFORMANCE.md) - OPcache, Redis, cache clearing
> - [Complete Guide](docs/COMPLETE_GUIDE.md) - Full configuration reference
> - [Architecture](docs/ARCHITECTURE.md) - System design and components

---

## What This Does

Traditional admin panels use `/admin`. Attackers know this. They scan for it. They brute-force it.

This panel generates URLs like:
```
/x-d4e8f2a9c6b1d5f3e7a2b8c4d9f1e6a3/login
```

- 128-bit entropy per URL
- 2FA enabled by default
- Emergency access token (bypasses login + 2FA)
- `/admin` returns 404

---

## Requirements

- PHP 8.1+
- PostgreSQL 14+ or MySQL 8.0+
- Docker/OrbStack (for local development)

---

## Installation

### Quick Start (copy/paste one line at a time)

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
cd vendor/ados-labs/enterprise-admin-panel/elf && docker compose up -d && cd ../../../..
```

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/install.php --email=admin@example.com
```

```bash
php -S localhost:8080 -t public
```

### What Gets Created

```
my-project/
├── .env                 ← Configuration (APP_KEY, database, SMTP)
├── composer.json
├── public/              ← Web root
│   ├── index.php        ← Entry point
│   ├── css/             ← Stylesheets
│   ├── js/              ← JavaScript
│   └── favicon.ico      ← Favicons
└── vendor/              ← Dependencies
```

### Services Started by Docker

| Service    | URL                    | Credentials     |
|------------|------------------------|-----------------|
| PostgreSQL | localhost:5432         | admin / secret  |
| Redis      | localhost:6379         | -               |
| Mailpit    | http://localhost:8025  | (2FA emails)    |

---

## After Installation

The install script shows credentials **ONCE**. Save them!

- **Admin URL** - Secret URL like `http://localhost:8080/x-abc123.../login`
- **Password** - Generated secure password with special characters
- **Master CLI Token** - Required for all CLI commands

### Default Security Settings

- **2FA is ENABLED** by default (codes sent via email to Mailpit)
- **Emergency access** can be created with CLI command

To disable 2FA:
```sql
UPDATE admin_users SET two_factor_enabled = false WHERE email = 'admin@example.com';
```

---

## CLI Commands

All commands are in `vendor/ados-labs/enterprise-admin-panel/elf/`.

**All commands require triple authentication:**
- `--token=` Master CLI token
- `--email=` Admin email
- `--password=` Admin password

### Change Password

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/password-change.php --token=MASTER_TOKEN --email=admin@example.com --password=CURRENT --new-password=NEW
```

Requirements: 12+ chars, 1 number, 1 special character (!@#$%^&*-_=+)

### Regenerate Master Token

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/token-master-regenerate.php --token=CURRENT_TOKEN --email=admin@example.com --password=PASSWORD
```

Old token is invalidated immediately.

### Create Emergency Access Token

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/token-emergency-create.php --token=MASTER_TOKEN --email=admin@example.com --password=PASSWORD
```

Creates a one-time token that **bypasses login and 2FA**, going directly to dashboard.
Store offline (printed, in a safe). Valid for 30 days.

### Use Emergency Access Token

Via CLI:
```bash
php vendor/ados-labs/enterprise-admin-panel/elf/token-emergency-use.php --token=EMERGENCY_TOKEN
```

Via browser:
```
http://localhost:8080/emergency-login?token=EMERGENCY_TOKEN
```

Token is **single-use** - invalidated after access.

---

## Development Tools

| Tool    | URL                   | Purpose              |
|---------|-----------------------|----------------------|
| Mailpit | http://localhost:8025 | View 2FA email codes |

---

## Security Features

### 2FA (Two-Factor Authentication)

- **Enabled by default** for all new users
- Codes sent via email (Mailpit in development)
- Channels: Email, Telegram, Discord, Slack, TOTP

### Emergency Access

- Created on-demand via CLI (not during install)
- Bypasses login form and 2FA
- Goes directly to dashboard
- Single-use, expires in 30 days

### Password Security

- Minimum 12 characters
- At least 1 number + 1 special character
- Argon2id hashing
- Account lockout after 5 failed attempts

### URL Security

| Feature      | Traditional | This Panel          |
|--------------|-------------|---------------------|
| URL Pattern  | `/admin`    | `/x-{random 32 hex}`|
| Entropy      | 0 bits      | 128 bits            |
| Brute Force  | Easy        | 2^128 combinations  |

---

## Troubleshooting

### "404 Not Found" on /admin

Expected. The admin URL is secret. If lost:
1. Create emergency token (requires master token + email + password)
2. Use it to access dashboard

### 2FA codes not arriving

Check Mailpit: http://localhost:8025

### Lost master token

Reset manually:
```bash
php -r "echo password_hash('your-new-token', PASSWORD_ARGON2ID);"
```
```sql
UPDATE admin_users SET cli_token_hash = 'PASTE_HASH' WHERE email = 'admin@example.com';
```

### Lost everything

Drop all tables and reinstall:
```bash
php vendor/ados-labs/enterprise-admin-panel/elf/install.php --email=admin@example.com
```

---

## License

MIT License - see [LICENSE](LICENSE)
