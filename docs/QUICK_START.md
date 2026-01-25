# Enterprise Framework - Quick Start Guide

## From Zero to Running in 3 Minutes

This guide takes you from a fresh machine to a fully working Enterprise admin panel with logging.

---

## One-Command Installation (Recommended)

For the fastest setup, use our automated installer:

```bash
# Create project directory
mkdir my-enterprise-app && cd my-enterprise-app

# Initialize and install packages
composer init --name="mycompany/my-app" --type=project --require="php:^8.1" -n
composer config repositories.admin path ../enterprise-admin-panel
composer config repositories.bootstrap path ../enterprise-bootstrap
composer config repositories.logger path ../enterprise-psr3-logger
composer require adoslabs/enterprise-admin-panel:@dev

# Run the automated installer
./vendor/adoslabs/enterprise-admin-panel/setup/quick-install.sh

# Start the server
php -S localhost:8080 -t public
```

The installer will:
1. Start Docker containers (PostgreSQL, Redis, Mailhog)
2. Run all database migrations
3. Create the admin user
4. Display your secure admin URL and credentials

**Save the URL and credentials immediately - they are shown only once!**

---

## Manual Installation (Step by Step)

If you prefer manual control or the automated installer doesn't work for your environment:

## Prerequisites

Before starting, ensure you have:

- **PHP 8.1+** with extensions: pdo, pdo_pgsql, json, mbstring
- **Composer** 2.x
- **Docker Desktop** or **OrbStack** (recommended for Mac)

### Check PHP Version
```bash
php -v
# Should show PHP 8.1 or higher
```

### Check Composer
```bash
composer --version
# Should show Composer 2.x
```

### Install OrbStack (Mac) or Docker Desktop
```bash
# macOS with Homebrew
brew install orbstack

# Or download Docker Desktop from https://docker.com
```

---

## Step 1: Create Your Project

```bash
# Create project directory
mkdir my-enterprise-app
cd my-enterprise-app

# Initialize Composer project
composer init --name="mycompany/my-app" --type=project --require="php:^8.1" -n
```

---

## Step 2: Add Enterprise Packages

### Option A: From Local Development (Path Repositories)

If you have the packages locally:

```bash
# Add path repositories
composer config repositories.admin path ../enterprise-admin-panel
composer config repositories.bootstrap path ../enterprise-bootstrap
composer config repositories.logger path ../enterprise-psr3-logger

# Install packages (in order!)
composer require adoslabs/enterprise-admin-panel:@dev
composer require adoslabs/enterprise-bootstrap:@dev
composer require adoslabs/enterprise-psr3-logger:@dev
```

### Option B: From Packagist (Production)

```bash
composer require adoslabs/enterprise-admin-panel
composer require adoslabs/enterprise-bootstrap
composer require adoslabs/enterprise-psr3-logger
```

---

## Step 3: Start Database Services

### Create docker-compose.yml

```bash
cat > docker-compose.yml << 'EOF'
version: '3.8'

services:
  postgres:
    image: postgres:17-alpine
    container_name: enterprise-postgres
    environment:
      POSTGRES_DB: enterprise
      POSTGRES_USER: admin
      POSTGRES_PASSWORD: secret
    ports:
      - "5432:5432"
    volumes:
      - postgres_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U admin -d enterprise"]
      interval: 5s
      timeout: 5s
      retries: 5

  redis:
    image: redis:7-alpine
    container_name: enterprise-redis
    ports:
      - "6379:6379"
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 5s
      retries: 5

  mailhog:
    image: mailhog/mailhog:latest
    container_name: enterprise-mailhog
    ports:
      - "1025:1025"   # SMTP
      - "8025:8025"   # Web UI

volumes:
  postgres_data:
EOF
```

### Start Services

```bash
docker-compose up -d

# Wait for services to be ready
echo "Waiting for PostgreSQL..."
until docker exec enterprise-postgres pg_isready -U admin -d enterprise > /dev/null 2>&1; do
  sleep 1
done
echo "PostgreSQL is ready!"
```

### Verify Services

```bash
# Check all containers are running
docker-compose ps

# Expected output:
# NAME                  STATUS
# enterprise-postgres   Up (healthy)
# enterprise-redis      Up (healthy)
# enterprise-mailhog    Up
```

---

## Step 4: Run Database Migrations

### Admin Panel Migrations (creates core tables)

```bash
# Run all admin panel migrations
for f in vendor/adoslabs/enterprise-admin-panel/src/Database/migrations/postgresql/*.sql; do
  echo "Running: $f"
  docker exec -i enterprise-postgres psql -U admin -d enterprise < "$f"
done
```

### PSR-3 Logger Migrations (creates logs table)

```bash
# If you installed enterprise-psr3-logger
php vendor/adoslabs/enterprise-psr3-logger/setup/install.php \
  --driver=pgsql \
  --host=localhost \
  --port=5432 \
  --database=enterprise \
  --username=admin \
  --password=secret
```

### Verify Tables Created

```bash
docker exec enterprise-postgres psql -U admin -d enterprise -c "\dt"

# Expected tables:
# admin_users, admin_sessions, admin_audit_log, admin_config,
# admin_modules, admin_url_whitelist, log_channels, log_telegram_config, logs
```

---

## Step 5: Create Admin User

```bash
# Generate password hash
ADMIN_PASSWORD="Admin123!"
HASH=$(php -r "echo password_hash('$ADMIN_PASSWORD', PASSWORD_ARGON2ID);")

# Create admin user
docker exec enterprise-postgres psql -U admin -d enterprise << EOF
INSERT INTO admin_users (email, password_hash, name, role, is_master, is_active)
VALUES ('admin@example.com', '$HASH', 'Administrator', 'super_admin', true, true);
EOF

echo "Admin user created!"
echo "  Email: admin@example.com"
echo "  Password: $ADMIN_PASSWORD"
```

---

## Step 6: Create Entry Point

### Create public/index.php

```bash
mkdir -p public

cat > public/index.php << 'EOF'
<?php
/**
 * Enterprise Admin Panel - Entry Point
 */

declare(strict_types=1);

// Load Composer autoloader
require dirname(__DIR__) . '/vendor/autoload.php';

use AdosLabs\AdminPanel\Core\Application;

// Database configuration
$config = [
    'db' => [
        'driver' => 'pgsql',
        'host' => 'localhost',
        'port' => 5432,
        'database' => 'enterprise',
        'username' => 'admin',
        'password' => 'secret',
    ],
    'app' => [
        'name' => 'My Enterprise App',
        'env' => 'development',
        'debug' => true,
    ],
    'security' => [
        'app_secret' => 'your-64-char-secret-key-here-change-in-production-1234567890ab',
    ],
];

// Create and run application
$app = new Application($config);
$app->run();
EOF
```

---

## Step 7: Generate Admin URL

The admin panel uses cryptographic URLs for security. Generate your unique URL:

```bash
# Create URL generator script
cat > get-admin-url.php << 'EOF'
<?php
require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO(
    'pgsql:host=localhost;port=5432;dbname=enterprise',
    'admin',
    'secret'
);

use AdosLabs\AdminPanel\Core\CryptographicAdminUrlGenerator;

// Get first admin user
$stmt = $pdo->query("SELECT id FROM admin_users LIMIT 1");
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("No admin user found. Create one first.\n");
}

$secret = 'your-64-char-secret-key-here-change-in-production-1234567890ab';
$url = CryptographicAdminUrlGenerator::generate($user['id'], $pdo, $secret);

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║              ENTERPRISE ADMIN PANEL                              ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "Admin Panel URL:\n";
echo "  http://localhost:8080{$url}/login\n";
echo "\n";
echo "Credentials:\n";
echo "  Email:    admin@example.com\n";
echo "  Password: Admin123!\n";
echo "\n";
echo "Start server with:\n";
echo "  php -S localhost:8080 -t public\n";
echo "\n";
EOF

# Run it
php get-admin-url.php
```

---

## Step 8: Start the Server

```bash
php -S localhost:8080 -t public
```

---

## Step 9: Access Admin Panel

1. Open the URL shown by `get-admin-url.php` (e.g., `http://localhost:8080/x-abc123def456/login`)
2. Login with:
   - Email: `admin@example.com`
   - Password: `Admin123!`

**Note:** Going to `/admin/login` will return 404. This is by design for security.

---

## Complete Project Structure

After completing all steps, your project should look like:

```
my-enterprise-app/
├── composer.json
├── composer.lock
├── docker-compose.yml
├── get-admin-url.php
├── public/
│   └── index.php
└── vendor/
    └── adoslabs/
        ├── enterprise-admin-panel/
        ├── enterprise-bootstrap/
        └── enterprise-psr3-logger/
```

---

## Troubleshooting

### "Connection refused" to PostgreSQL

```bash
# Check if container is running
docker ps | grep enterprise-postgres

# If not, restart
docker-compose up -d postgres
```

### "Permission denied" on migrations

```bash
# Check database exists
docker exec enterprise-postgres psql -U admin -l

# Create if missing
docker exec enterprise-postgres createdb -U admin enterprise
```

### 404 on /admin/login

This is expected! Use the cryptographic URL from `get-admin-url.php`.

### Login fails

```bash
# Reset password
HASH=$(php -r "echo password_hash('Admin123!', PASSWORD_ARGON2ID);")
docker exec enterprise-postgres psql -U admin -d enterprise \
  -c "UPDATE admin_users SET password_hash = '$HASH', failed_login_attempts = 0, locked_until = NULL WHERE email = 'admin@example.com';"
```

### Can't see Logger tab in admin

Make sure you installed `enterprise-psr3-logger`:

```bash
composer show | grep psr3-logger
```

---

## What's Next?

### Configure Logging Channels

1. Go to Logger → Channels in admin panel
2. Add channels: `security`, `api`, `database`, etc.
3. Set minimum levels for each channel

### Configure Telegram Notifications

1. Go to Logger → Telegram in admin panel
2. Enter your Telegram bot token and chat ID
3. Set minimum level for notifications (recommended: `error`)

### Add More Packages

```bash
# Security Shield (WAF, rate limiting)
composer require adoslabs/enterprise-security-shield

# Database Pool (connection pooling)
composer require adoslabs/database-pool
```

---

## Package Integration Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        YOUR APPLICATION                                  │
├─────────────────────────────────────────────────────────────────────────┤
│   ┌─────────────────────┐    ┌─────────────────────┐                   │
│   │ enterprise-admin-panel│◄───│ enterprise-psr3-logger│                │
│   │                     │    │                     │                   │
│   │ • UI Management     │    │ • PSR-3 Logging     │                   │
│   │ • LogConfigService  │    │ • Handlers          │                   │
│   │ • Channel Config UI │    │ • Calls should_log()│                   │
│   │ • Telegram Config   │    │ • Telegram Handler  │                   │
│   └─────────┬───────────┘    └──────────┬──────────┘                   │
│             │         INTEGRATION       │                               │
│             ▼                           ▼                               │
│   ┌─────────────────────────────────────────────────┐                   │
│   │            enterprise-bootstrap                  │                   │
│   │  • should_log() function (intelligent filter)   │                   │
│   │  • Multi-layer caching (~0.001μs per decision)  │                   │
│   │  • Cache helpers: cache(), db(), session()      │                   │
│   └─────────────────────────────────────────────────┘                   │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Support

For issues or questions:
- GitHub: https://github.com/adoslabs/enterprise-admin-panel/issues
- Email: support@adoslabs.com
