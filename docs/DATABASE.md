# Database Configuration Guide

> **Author:** Nicola Cucurachi
> **Package:** Enterprise Admin Panel

This guide covers database setup, access, and administration for Enterprise Admin Panel.

## Table of Contents

- [Quick Reference](#quick-reference)
- [Database Access](#database-access)
- [Environment Configuration](#environment-configuration)
- [OrbStack / Docker Setup](#orbstack--docker-setup)
- [Database GUI Tools](#database-gui-tools)
- [Redis Access](#redis-access)
- [Security Best Practices](#security-best-practices)

---

## Quick Reference

| Service | Host | Port | Database | Username | Password |
|---------|------|------|----------|----------|----------|
| PostgreSQL | localhost | 5432 | admin_panel | admin | (from .env) |
| Redis | localhost | 6379 | 0 | - | (from .env) |
| Mailpit SMTP | localhost | 1025 | - | - | - |
| Mailpit Web | localhost | 8025 | - | - | - |

---

## Database Access

### Command Line (psql)

```bash
# Connect to PostgreSQL
psql -h localhost -p 5432 -U admin -d admin_panel

# With password from environment
PGPASSWORD=$(grep DB_PASSWORD .env | cut -d'=' -f2) psql -h localhost -p 5432 -U admin -d admin_panel

# Quick commands
psql -h localhost -U admin -d admin_panel -c "SELECT * FROM admin_users;"
psql -h localhost -U admin -d admin_panel -c "\dt"  # List tables
```

### PHP (via DatabasePool)

```php
use AdosLabs\AdminPanel\Bootstrap;

Bootstrap::init('/path/to/project');

$db = db();  // Get DatabasePool instance

// Query
$users = $db->query('SELECT * FROM admin_users WHERE is_active = ?', [true]);

// Execute
$affected = $db->execute('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?', [$userId]);

// Transaction
$result = $db->transaction(function($pdo) {
    $pdo->exec("INSERT INTO audit_log (action) VALUES ('test')");
    return $pdo->lastInsertId();
});
```

---

## Environment Configuration

### Required Variables

Create a `.env` file in your project root:

```env
# Encryption key (generate with: php -r "echo bin2hex(random_bytes(32));")
APP_KEY=your-64-character-hex-key

# Database Configuration
DB_DRIVER=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=admin_panel
DB_USERNAME=admin
DB_PASSWORD=your-secure-database-password

# Redis Configuration
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=your-secure-redis-password
REDIS_DATABASE=0
REDIS_PREFIX=eap:

# SMTP (Mailpit for development)
SMTP_HOST=localhost
SMTP_PORT=1025
SMTP_FROM=admin@localhost

# Environment
APP_ENV=development
APP_DEBUG=true
```

### Generate Secure Passwords

```bash
# Generate random password (32 chars)
openssl rand -base64 24

# Generate APP_KEY (64 hex chars)
php -r "echo bin2hex(random_bytes(32));"
```

---

## OrbStack / Docker Setup

### docker-compose.yml

```yaml
version: '3.8'

services:
  postgres:
    image: postgres:17-alpine
    container_name: eap-postgres
    environment:
      POSTGRES_DB: admin_panel
      POSTGRES_USER: admin
      POSTGRES_PASSWORD: ${DB_PASSWORD:-changeme}
    ports:
      - "5432:5432"
    volumes:
      - postgres_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U admin -d admin_panel"]
      interval: 10s
      timeout: 5s
      retries: 5

  redis:
    image: redis:7-alpine
    container_name: eap-redis
    command: redis-server --requirepass ${REDIS_PASSWORD:-changeme}
    ports:
      - "6379:6379"
    volumes:
      - redis_data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "-a", "${REDIS_PASSWORD:-changeme}", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5

  mailpit:
    image: axllent/mailpit
    container_name: eap-mailpit
    ports:
      - "1025:1025"  # SMTP
      - "8025:8025"  # Web UI
    environment:
      MP_SMTP_AUTH_ACCEPT_ANY: 1
      MP_SMTP_AUTH_ALLOW_INSECURE: 1

volumes:
  postgres_data:
  redis_data:
```

### Start Services

```bash
# Start all services
docker-compose up -d

# Check status
docker-compose ps

# View logs
docker-compose logs -f postgres
docker-compose logs -f redis

# Stop all services
docker-compose down

# Stop and remove volumes (WARNING: deletes data)
docker-compose down -v
```

### OrbStack Quick Start

If using OrbStack on macOS:

```bash
# PostgreSQL is available at localhost:5432
# Redis is available at localhost:6379
# No additional configuration needed - OrbStack handles networking
```

---

## Database GUI Tools

### TablePlus (Recommended)

1. Download: https://tableplus.com/
2. Create new connection:
   - **Type**: PostgreSQL
   - **Host**: localhost
   - **Port**: 5432
   - **User**: admin
   - **Password**: (from .env)
   - **Database**: admin_panel

### pgAdmin 4

1. Download: https://www.pgadmin.org/
2. Add server:
   - **Host**: localhost
   - **Port**: 5432
   - **Username**: admin
   - **Password**: (from .env)

### DBeaver

1. Download: https://dbeaver.io/
2. Create PostgreSQL connection with same credentials

### VS Code Extensions

- **PostgreSQL** by Chris Kolkman
- **Database Client** by Weijan Chen

---

## Redis Access

### Command Line (redis-cli)

```bash
# Connect without password
redis-cli -h localhost -p 6379

# Connect with password
redis-cli -h localhost -p 6379 -a your-redis-password

# From environment
redis-cli -h localhost -p 6379 -a $(grep REDIS_PASSWORD .env | cut -d'=' -f2)

# Quick commands
redis-cli -a password KEYS "eap:*"           # List all EAP keys
redis-cli -a password HGETALL "eap:dbpool:circuit:admin_panel"  # Circuit breaker state
redis-cli -a password INFO memory            # Memory usage
```

### Redis GUI Tools

- **RedisInsight** (Official): https://redis.com/redis-enterprise/redis-insight/
- **Medis**: https://getmedis.com/ (macOS)
- **Another Redis Desktop Manager**: https://github.com/qishibo/AnotherRedisDesktopManager

### Key Patterns

| Pattern | Description |
|---------|-------------|
| `eap:dbpool:circuit:*` | Circuit breaker state |
| `eap:dbpool:metrics:*` | Database pool metrics |
| `eap:cache:*` | Application cache |
| `eap:session:*` | User sessions |

---

## Security Best Practices

### Password Requirements

- **Minimum 16 characters**
- Mix of uppercase, lowercase, numbers, symbols
- Different passwords for each service
- Store in `.env` (never commit to git)

### Network Security

```bash
# Production: Bind to localhost only
# PostgreSQL: postgresql.conf
listen_addresses = 'localhost'

# Redis: redis.conf
bind 127.0.0.1
protected-mode yes
requirepass your-strong-password
```

### File Permissions

```bash
# Protect .env file
chmod 600 .env

# Ensure .env is in .gitignore
echo ".env" >> .gitignore
```

### SSL/TLS (Production)

```env
# PostgreSQL SSL
DB_SSL=true
DB_SSL_CA=/path/to/ca.crt
DB_SSL_CERT=/path/to/client.crt
DB_SSL_KEY=/path/to/client.key

# Redis SSL
REDIS_SSL=true
REDIS_SSL_CA=/path/to/ca.crt
```

---

## Troubleshooting

### Connection Refused

```bash
# Check if services are running
docker-compose ps
pg_isready -h localhost -p 5432
redis-cli -h localhost -p 6379 ping

# Check ports
lsof -i :5432
lsof -i :6379
```

### Authentication Failed

```bash
# Verify password in .env matches docker-compose
grep DB_PASSWORD .env
grep POSTGRES_PASSWORD docker-compose.yml

# Reset PostgreSQL password
docker-compose exec postgres psql -U postgres -c "ALTER USER admin PASSWORD 'new-password';"
```

### Too Many Connections

```bash
# Check current connections
psql -c "SELECT count(*) FROM pg_stat_activity;"

# Check pool stats
php -r "
require 'vendor/autoload.php';
\$pool = db();
print_r(\$pool->getStats()['pool']);
"
```

---

## Migrations

### Run Migrations

```bash
# Using the install script
php vendor/ados-labs/enterprise-admin-panel/elf/install.php

# Manual migration
psql -h localhost -U admin -d admin_panel -f migrations/001_create_admin_users.sql
```

### Create New Migration

```bash
# Naming convention: XXX_description.sql
touch migrations/010_add_new_feature.sql
```
