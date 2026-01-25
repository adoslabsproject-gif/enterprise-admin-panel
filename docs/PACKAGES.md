# Enterprise Packages Architecture

## Overview

The Enterprise ecosystem is a modular system where `enterprise-admin-panel` serves as the central hub. All other packages are optional extensions that integrate seamlessly when installed.

```
┌─────────────────────────────────────────────────────────────────┐
│                    YOUR APPLICATION                              │
│                      (my-new-site)                               │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │              enterprise-admin-panel                      │    │
│  │                   (CORE HUB)                             │    │
│  │                                                          │    │
│  │  - Dynamic cryptographic URLs (/x-abc123/...)            │    │
│  │  - User authentication (Argon2id + 2FA)                  │    │
│  │  - Session management                                    │    │
│  │  - Audit logging                                         │    │
│  │  - Module auto-discovery                                 │    │
│  │  - CSRF protection                                       │    │
│  │                                                          │    │
│  └──────────────────────┬───────────────────────────────────┘    │
│                         │                                        │
│         ┌───────────────┼───────────────┐                        │
│         │               │               │                        │
│         ▼               ▼               ▼                        │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐                │
│  │  security-  │ │   psr3-     │ │  database-  │                │
│  │   shield    │ │   logger    │ │    pool     │                │
│  └─────────────┘ └─────────────┘ └─────────────┘                │
│                                                                  │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐                │
│  │  frontend   │ │   payment   │ │    api      │                │
│  │  (planned)  │ │  (planned)  │ │  (planned)  │                │
│  └─────────────┘ └─────────────┘ └─────────────┘                │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Core Package

### enterprise-admin-panel
**Status:** Production Ready
**Composer:** `adoslabs/enterprise-admin-panel`

The central administration panel that serves as the foundation for all enterprise packages.

#### Features
- **Dynamic Security URLs**: Admin panel accessible only via cryptographic URLs (e.g., `/x-a7f3b2c8d9e4/login`)
- **Authentication**: Argon2id password hashing, optional 2FA via email
- **Session Management**: Secure sessions with automatic expiration
- **Audit Logging**: Complete audit trail of all admin actions
- **Module System**: Auto-discovery of installed packages via `composer.json`
- **CSRF Protection**: Automatic token validation for all POST requests
- **Matrix Theme**: Unique cyberpunk/terminal aesthetic

#### Key Files
```
enterprise-admin-panel/
├── public/
│   └── index.php           # Entry point
├── src/
│   ├── Controllers/        # Auth, Dashboard controllers
│   ├── Core/
│   │   ├── ModuleRegistry.php      # Module auto-discovery
│   │   └── AdminModuleInterface.php # Module contract
│   ├── Services/           # Auth, Session, Audit services
│   ├── Views/
│   │   ├── layouts/admin.php       # Main layout (Matrix theme)
│   │   ├── auth/                   # Login, 2FA views
│   │   └── dashboard/              # Dashboard view
│   └── Database/
│       └── migrations/     # PostgreSQL migrations
└── setup/
    └── docker-compose.yml  # PostgreSQL, Redis, Mailhog
```

#### Installation
```bash
composer require adoslabs/enterprise-admin-panel
```

---

## Extension Packages

### enterprise-psr3-logger
**Status:** Production Ready
**Composer:** `adoslabs/enterprise-psr3-logger`

PSR-3 compliant logging with database storage, channel-based filtering, and admin panel integration.

#### Features
- **PSR-3 Compliant**: Works with any PSR-3 compatible code
- **Database Storage**: Logs stored in PostgreSQL for querying
- **Channel System**: Separate log channels (app, security, api, etc.)
- **Level Filtering**: Configure minimum level per channel
- **Admin Integration**: Auto-registers in admin panel sidebar
- **Log Viewer**: Browse, filter, and search logs in admin
- **Channel Management**: Enable/disable channels, set levels via UI

#### Admin Panel Integration
When installed alongside `enterprise-admin-panel`, automatically adds:
- **Logs** menu in sidebar
  - Log Viewer: Browse all logs with filters
  - Channels: Configure log channels and levels

#### Key Files
```
enterprise-psr3-logger/
├── src/
│   ├── Logger.php                  # Main PSR-3 logger
│   ├── Handlers/
│   │   └── DatabaseHandler.php     # PostgreSQL handler
│   └── AdminIntegration/
│       ├── LoggerAdminModule.php   # Admin module definition
│       ├── Controllers/
│       │   └── LoggerController.php
│       └── Views/
│           └── logger/
│               ├── index.php       # Log viewer
│               └── channels.php    # Channel config
└── database/
    └── schema-postgresql.sql       # Logs table schema
```

#### composer.json (auto-discovery)
```json
{
    "extra": {
        "admin-panel": {
            "module": "AdosLabs\\EnterprisePSR3Logger\\AdminIntegration\\LoggerAdminModule",
            "priority": 50
        }
    }
}
```

---

### enterprise-security-shield
**Status:** In Development
**Composer:** `adoslabs/enterprise-security-shield`

Comprehensive security layer with WAF, rate limiting, honeypots, and threat detection.

#### Features
- **Web Application Firewall (WAF)**: Block malicious requests
- **Rate Limiting**: Protect against brute force attacks
- **IP Banning**: Automatic and manual IP blocking
- **Honeypot Fields**: Detect bot submissions
- **Threat Scoring**: Track suspicious behavior per IP
- **Bot Verification**: Challenge suspected bots
- **Security Events**: Log all security-related events

#### Admin Panel Integration (Planned)
- **Security** menu in sidebar
  - Dashboard: Overview of threats and blocks
  - WAF Rules: Manage firewall rules
  - Banned IPs: View and manage banned IPs
  - Honeypot: Configure honeypot settings
  - Audit: Security event log

---

### database-pool
**Status:** Production Ready
**Composer:** `adoslabs/database-pool`

Connection pooling and management for PostgreSQL and MySQL.

#### Features
- **Connection Pooling**: Reuse database connections
- **Circuit Breaker**: Prevent cascade failures
- **Health Checks**: Monitor connection health
- **Metrics**: Track connection usage statistics
- **Multi-Database**: Support PostgreSQL and MySQL

#### Admin Panel Integration (Planned)
- **Database** menu in sidebar
  - Pool Status: Active connections, pool size
  - Metrics: Connection usage graphs
  - Configuration: Pool settings

---

### enterprise-bootstrap
**Status:** Production Ready
**Composer:** `adoslabs/enterprise-bootstrap`

Foundation package with common helpers and utilities.

#### Features
- **Helper Functions**: `db()`, `cache()`, `session()`, `config()`
- **Service Container**: Simple dependency injection
- **Environment Loading**: `.env` file support
- **should_log() Stub**: Placeholder for log filtering

#### Note
This package provides utilities used by other packages. It does NOT add admin panel UI.

---

## Planned Packages

### enterprise-frontend (Planned)
**Status:** Planned
**Purpose:** Public-facing pages for end users

#### Planned Features
- Homepage template
- User registration (separate from admin users)
- User login (separate from admin login)
- User dashboard (post-login)
- Profile management
- Password reset flow

#### Architecture
```
enterprise-frontend/
├── src/
│   ├── Controllers/
│   │   ├── HomeController.php
│   │   ├── AuthController.php      # User auth (not admin)
│   │   └── ProfileController.php
│   ├── Views/
│   │   ├── layouts/public.php
│   │   ├── home/
│   │   ├── auth/
│   │   └── profile/
│   └── AdminIntegration/
│       └── FrontendAdminModule.php # Manage frontend settings
└── database/
    └── migrations/
        └── 001_create_users.sql    # End-user table
```

---

## Module Integration Protocol

### How Modules Register with Admin Panel

1. **Define Module Class** implementing `AdminModuleInterface`:
```php
namespace Vendor\Package\AdminIntegration;

use AdosLabs\AdminPanel\Core\AdminModuleInterface;

class MyAdminModule implements AdminModuleInterface
{
    public function getName(): string { return 'My Module'; }
    public function getDescription(): string { return 'Description'; }
    public function getVersion(): string { return '1.0.0'; }

    public function getTabs(): array
    {
        return [
            [
                'id' => 'my-module',
                'label' => 'My Module',
                'url' => '/admin/my-module',  // Will be transformed to dynamic URL
                'icon' => 'settings',
                'priority' => 60,
            ],
        ];
    }

    public function getRoutes(): array
    {
        return [
            ['method' => 'GET', 'path' => '/admin/my-module', 'handler' => [MyController::class, 'index']],
        ];
    }

    public function install(): void { /* Run migrations */ }
    public function uninstall(): void { /* Cleanup */ }
    public function getViewsPath(): ?string { return __DIR__ . '/Views'; }
    public function getAssetsPath(): ?string { return null; }
    public function getConfigSchema(): array { return []; }
    public function getDependencies(): array { return []; }
    public function getPermissions(): array { return []; }
}
```

2. **Register in composer.json**:
```json
{
    "extra": {
        "admin-panel": {
            "module": "Vendor\\Package\\AdminIntegration\\MyAdminModule",
            "priority": 60
        }
    }
}
```

3. **Auto-Discovery**: When installed, the admin panel automatically:
   - Discovers the module via `composer.lock`
   - Instantiates the module class
   - Adds tabs to sidebar
   - Registers routes
   - Enables module by default

### Controller Requirements

Module controllers should extend `BaseController`:

```php
namespace Vendor\Package\AdminIntegration\Controllers;

use AdosLabs\AdminPanel\Controllers\BaseController;
use AdosLabs\AdminPanel\Http\Response;

class MyController extends BaseController
{
    public function index(): Response
    {
        return $this->view('my-module/index', [
            'data' => $this->getData(),
            'page_title' => 'My Module',
        ]);
    }
}
```

### URL Transformation

Modules define routes with `/admin/...` paths. The admin panel automatically transforms these to the dynamic cryptographic base path:

- Module defines: `/admin/logger`
- User sees: `/x-abc123def456/logger`

---

## Database Schema

Each package manages its own tables. Core tables:

### Admin Panel Tables
- `admin_users` - Admin user accounts
- `admin_sessions` - Active sessions
- `admin_audit_log` - Audit trail
- `admin_config` - System configuration
- `admin_modules` - Installed modules state

### PSR3 Logger Tables
- `logs` - Log entries
- `log_channels` - Channel configuration

### Security Shield Tables (Planned)
- `ip_bans` - Banned IP addresses
- `threat_scores` - IP threat scoring
- `security_events` - Security incidents
- `waf_rules` - Firewall rules

---

## Quick Start

```bash
# Create new project
mkdir my-site && cd my-site
composer init

# Add repository (for local development)
composer config repositories.admin path ../enterprise-admin-panel
composer config repositories.logger path ../enterprise-psr3-logger

# Install packages
composer require adoslabs/enterprise-admin-panel adoslabs/enterprise-psr3-logger

# Start services
docker-compose -f vendor/adoslabs/enterprise-admin-panel/setup/docker-compose.yml up -d

# Run migrations
php vendor/adoslabs/enterprise-admin-panel/bin/migrate.php

# Create admin user
php vendor/adoslabs/enterprise-admin-panel/bin/create-admin.php

# Create entry point
cp vendor/adoslabs/enterprise-admin-panel/public/index.php public/index.php
# Edit public/index.php to use project autoloader

# Start server
php -S localhost:8080 -t public

# Access admin panel
# URL will be displayed after running create-admin.php
```

---

## Version Compatibility

| Package | PHP | PostgreSQL | MySQL | Redis |
|---------|-----|------------|-------|-------|
| admin-panel | ^8.1 | ^14 | ^8.0 | ^6.0 |
| psr3-logger | ^8.1 | ^14 | ^8.0 | Optional |
| security-shield | ^8.1 | ^14 | - | ^6.0 |
| database-pool | ^8.1 | ^14 | ^8.0 | Optional |
| bootstrap | ^8.1 | - | - | Optional |

---

## License

All packages are MIT licensed.
