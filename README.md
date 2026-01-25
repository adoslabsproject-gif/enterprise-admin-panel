# Enterprise Admin Panel

> **Author:** Nicola Cucurachi
> **Enterprise Lightning Framework - Package 0**

Admin panel with cryptographic dynamic URLs. No predictable `/admin` endpoints.

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

- PHP 8.2+
- PostgreSQL 14+ or MySQL 8.0+
- Redis 7+ (optional, for distributed circuit breaker)
- Docker/OrbStack (for local development)

---

## Installation

### Step 1: Create Project

```bash
mkdir myproject && cd myproject
```

### Step 2: Create composer.json

```bash
cat > composer.json << 'EOF'
{
    "name": "mycompany/myproject",
    "type": "project",
    "require": {
        "php": ">=8.2",
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

### Step 3: Install Dependencies

```bash
composer install
```

### Step 4: Start Database Services

**Option A: Use the included docker-compose.yml**

```bash
cat > docker-compose.yml << 'EOF'
version: '3.8'

services:
  postgres:
    image: postgres:17-alpine
    container_name: elf-postgres
    environment:
      POSTGRES_DB: admin_panel
      POSTGRES_USER: admin
      POSTGRES_PASSWORD: ${DB_PASSWORD:-your_secure_password}
    ports:
      - "5432:5432"
    volumes:
      - postgres_data:/var/lib/postgresql/data

  redis:
    image: redis:7-alpine
    container_name: elf-redis
    command: redis-server --requirepass ${REDIS_PASSWORD:-your_redis_password}
    ports:
      - "6379:6379"
    volumes:
      - redis_data:/data

  mailpit:
    image: axllent/mailpit:latest
    container_name: elf-mailpit
    ports:
      - "1025:1025"
      - "8025:8025"

volumes:
  postgres_data:
  redis_data:
EOF

docker-compose up -d
```

**Option B: Use existing database**

Skip docker-compose and configure your existing database in Step 5.

### Step 5: Create .env File (REQUIRED)

**Important: Create this BEFORE running the installer!**

```bash
cat > .env << 'EOF'
# Database - REQUIRED
DB_DRIVER=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=admin_panel
DB_USERNAME=admin
DB_PASSWORD=your_secure_password

# Redis (optional - enables distributed circuit breaker)
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=your_redis_password

# SMTP (Mailpit for development)
SMTP_HOST=localhost
SMTP_PORT=1025
SMTP_FROM=admin@localhost
EOF
```

### Step 6: Run Installer

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/install.php --email=admin@example.com
```

**IMPORTANT:** Save the output! You will see:
- **Admin URL** - Secret URL (shown only once!)
- **Password** - Generated secure password
- **Master CLI Token** - Required for all CLI commands

### Step 7: Start Web Server

```bash
php -S localhost:8080 -t public
```

### Step 8: Access Admin Panel

Open the URL shown in Step 6 (NOT `/admin`!)

---

## Project Structure

After installation:

```
myproject/
├── .env                 ← Configuration (APP_KEY, database, SMTP)
├── .gitignore           ← Ignores .env, vendor, etc.
├── composer.json
├── docker-compose.yml   ← (if you created it)
├── public/              ← Web root
│   ├── index.php        ← Entry point
│   ├── css/             ← Stylesheets
│   ├── js/              ← JavaScript
│   └── favicon.ico
└── vendor/              ← Dependencies
```

---

## Services

| Service    | URL                    | Purpose            |
|------------|------------------------|--------------------|
| PostgreSQL | localhost:5432         | Database           |
| Redis      | localhost:6379         | Circuit breaker    |
| Mailpit    | http://localhost:8025  | View 2FA emails    |
| Admin Panel| (secret URL)           | Your admin panel   |

---

## Dashboard Features

The admin dashboard includes real-time metrics:

- **Database Pool** - Connections, queries, circuit breaker state
- **Redis** - Workers, memory, commands
- **Audit Log** - Recent activity
- **System Info** - PHP version, memory usage

---

## CLI Commands

All commands are in `vendor/ados-labs/enterprise-admin-panel/elf/`.

**All commands require triple authentication:**
- `--token=` Master CLI token
- `--email=` Admin email
- `--password=` Admin password

### Get Admin URL

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/url-get.php \
  --token=MASTER_TOKEN \
  --email=admin@example.com \
  --password=YOUR_PASSWORD
```

### Change Password

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/password-change.php \
  --token=MASTER_TOKEN \
  --email=admin@example.com \
  --password=CURRENT \
  --new-password=NEW
```

### Create Emergency Access Token

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/token-emergency-create.php \
  --token=MASTER_TOKEN \
  --email=admin@example.com \
  --password=PASSWORD
```

Creates a one-time token that bypasses login and 2FA.

---

## Security Features

### 2FA (Two-Factor Authentication)

- **Enabled by default** for all users
- Codes sent via email (check Mailpit at http://localhost:8025)
- Supports: Email, Telegram, Discord, Slack, TOTP

### URL Security

| Feature      | Traditional | This Panel           |
|--------------|-------------|----------------------|
| URL Pattern  | `/admin`    | `/x-{random 32 hex}` |
| Entropy      | 0 bits      | 128 bits             |
| Brute Force  | Easy        | 2^128 combinations   |

### Database Pool

- Connection pooling with LIFO reuse
- Circuit breaker (trips on failures, auto-recovers)
- Distributed state via Redis
- Metrics and monitoring

---

## Documentation

See the [`docs/`](docs/) folder:

- [Quick Start](docs/QUICK_START.md) - Get running fast
- [CLI Commands](docs/CLI-COMMANDS.md) - All installation options
- [Performance](docs/PERFORMANCE.md) - OPcache, Redis, caching
- [Database](docs/DATABASE.md) - Database access and configuration
- [Architecture](docs/ARCHITECTURE.md) - System design

---

## Troubleshooting

### "DB_PASSWORD is required"

Create `.env` with `DB_PASSWORD` before running the installer.

### "404 Not Found" on /admin

Expected. Use the secret URL from installation.

### Lost the admin URL?

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/url-get.php \
  --token=MASTER_TOKEN --email=EMAIL --password=PASSWORD
```

### 2FA codes not arriving

Check Mailpit: http://localhost:8025

### Connection refused

Make sure Docker services are running:
```bash
docker-compose up -d
docker-compose ps
```

---

## License

MIT License - see [LICENSE](LICENSE)
