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

## Features Overview

| Feature | Description |
|---------|-------------|
| **Cryptographic URLs** | 128-bit entropy, impossible to guess |
| **Two-Factor Auth** | Email, Telegram, Discord, Slack, TOTP |
| **Session Management** | Database-backed, 256-bit session IDs |
| **CSRF Protection** | Per-session tokens, constant-time comparison |
| **Database Pool** | Connection pooling with circuit breaker |
| **Audit Logging** | All actions logged with IP, user agent |
| **Emergency Access** | Recovery tokens for lockout scenarios |
| **Multi-Channel Notifications** | URL rotation alerts, security alerts |

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
SMTP_FROM_EMAIL=admin@localhost
SMTP_FROM_NAME=Enterprise Admin

# Telegram (optional)
# TELEGRAM_BOT_TOKEN=123456:ABC-DEF...

# Discord (optional)
# DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/...

# Slack (optional)
# SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
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

## Two-Factor Authentication (2FA)

### Supported Methods

| Method | Configuration | Description |
|--------|--------------|-------------|
| **Email** | Default | OTP sent via SMTP |
| **TOTP** | User setup | Google Authenticator, Authy, etc. |
| **Telegram** | Bot token required | OTP sent via Telegram bot |
| **Discord** | Webhook required | OTP sent via Discord webhook |
| **Slack** | Webhook required | OTP sent via Slack webhook |

### Email 2FA (Default)

Email 2FA works out of the box with Mailpit (development) or any SMTP server.

```env
# .env
SMTP_HOST=localhost
SMTP_PORT=1025
SMTP_FROM_EMAIL=admin@localhost
```

**Development:** View codes at http://localhost:8025 (Mailpit)

### TOTP Setup

Users can enable TOTP from their profile:

1. Click "Enable Authenticator App"
2. Scan QR code with Google Authenticator/Authy
3. Enter verification code to confirm
4. Save 8 recovery codes (XXXX-XXXX format)

```php
// Programmatic TOTP setup
$twoFactorService = $container->get(TwoFactorService::class);

// Generate secret and QR code
$setup = $twoFactorService->setupTOTP($userId);
// Returns: ['secret' => 'BASE32...', 'qr_uri' => 'otpauth://totp/...', 'recovery_codes' => [...]]

// Enable after user verifies code
$twoFactorService->enable($userId, 'totp', $verificationCode);
```

### Telegram 2FA

1. Create a Telegram bot via [@BotFather](https://t.me/botfather)
2. Get the bot token
3. User sends `/start` to your bot
4. Get user's chat ID from the message

```env
# .env
TELEGRAM_BOT_TOKEN=123456789:ABC-DEF1234ghIkl-zyx57W2v1u123ew11
```

```php
// Configure user's Telegram
$notificationService->configureUserChannel($userId, 'telegram', $chatId);
```

### Discord 2FA

1. Create a Discord webhook in your server settings
2. Add webhook URL to `.env`
3. Configure user's Discord ID

```env
# .env
DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/123456789/abcdef...
```

```php
// Configure user's Discord
$notificationService->configureUserChannel($userId, 'discord', $discordUserId);
```

### Slack 2FA

1. Create Slack Incoming Webhook in your workspace
2. Add webhook URL to `.env`
3. Configure user's Slack ID

```env
# .env
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
```

```php
// Configure user's Slack
$notificationService->configureUserChannel($userId, 'slack', $slackUserId);
```

### Enable Notification Channels in Database

Enable channels via admin config:

```sql
-- Enable Telegram notifications
INSERT INTO admin_config (key, value) VALUES ('notification_telegram_enabled', 'true');

-- Enable Discord notifications
INSERT INTO admin_config (key, value) VALUES ('notification_discord_enabled', 'true');

-- Enable Slack notifications
INSERT INTO admin_config (key, value) VALUES ('notification_slack_enabled', 'true');
```

### Recovery Codes

When TOTP is enabled, 8 recovery codes are generated:

- Format: `XXXX-XXXX` (hex)
- One-time use
- Stored as bcrypt hashes
- Can be regenerated from profile

---

## Session Management

### Features

- **256-bit session IDs** - Cryptographically secure
- **Database-backed** - No filesystem dependency
- **60-minute lifetime** - With activity-based extension
- **Multi-device tracking** - View/revoke sessions

### Session Lifecycle

1. Login → 60-minute session created
2. Activity within last 5 minutes before expiry → Extended by 60 minutes
3. No activity → Session expires
4. Explicit logout → Session destroyed

### Configuration

```php
// Default configuration
const SESSION_ID_BYTES = 32;            // 256-bit
const SESSION_MAX_LIFETIME_MINUTES = 60;
const SESSION_EXTENSION_WINDOW_MINUTES = 5;
const SESSION_EXTENSION_AMOUNT_MINUTES = 60;
const CSRF_TOKEN_BYTES = 32;
```

### API

```php
$sessionService = $container->get(SessionService::class);

// Create session after login
$sessionId = $sessionService->create($userId, $clientIp, $userAgent);

// Validate session
$session = $sessionService->validate($sessionId);

// Get all user sessions
$sessions = $sessionService->getUserSessions($userId);

// Destroy all sessions except current
$sessionService->destroyAllExcept($userId, $currentSessionId);

// Flash messages
$sessionService->flash($sessionId, 'success', 'Password changed');
$message = $sessionService->getFlash($sessionId, 'success');

// CSRF token
$token = $sessionService->getCsrfToken($sessionId);
$valid = $sessionService->verifyCsrfToken($sessionId, $submittedToken);
```

---

## CLI Commands

All commands are in `vendor/ados-labs/enterprise-admin-panel/elf/`.

**All commands require triple authentication:**
- `--token=` Master CLI token
- `--email=` Admin email
- `--password=` Admin password

### Available Commands

| Command | Description |
|---------|-------------|
| `install.php` | First-time installation |
| `url-get.php` | Retrieve current admin URL |
| `url-rotate.php` | Rotate admin URL (security) |
| `password-change.php` | Change admin password |
| `token-master-regenerate.php` | Regenerate master CLI token |
| `token-emergency-create.php` | Create emergency access token |
| `token-emergency-use.php` | Use emergency token for access |
| `cache-clear.php` | Clear application cache |
| `opcache-setup.php` | Setup OPcache configuration |

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

**Password Requirements:**
- Minimum 12 characters
- At least 1 number
- At least 1 special character (!@#$%^&*-_=+)

### Rotate Admin URL

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/url-rotate.php \
  --token=MASTER_TOKEN \
  --email=admin@example.com \
  --password=YOUR_PASSWORD \
  --reason="Scheduled rotation"
```

**Effects:**
- New 128-bit URL generated
- Old URL returns 404
- All admins notified via their preferred channel

### Create Emergency Access Token

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/token-emergency-create.php \
  --token=MASTER_TOKEN \
  --email=admin@example.com \
  --password=PASSWORD \
  --name="Safe deposit box" \
  --expires=365
```

**Security:**
- ONE-TIME USE
- Bypasses password AND 2FA
- Store offline (print and secure)

### Use Emergency Token

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/token-emergency-use.php \
  --token=EMERGENCY_TOKEN
```

Or via browser:
```
http://localhost:8080/emergency-login?token=YOUR_EMERGENCY_TOKEN
```

---

## Notification System

### Channels

| Channel | Configuration | Use Cases |
|---------|--------------|-----------|
| **Email** | SMTP settings | 2FA codes, URL rotation, alerts |
| **Telegram** | Bot token | 2FA codes, security alerts |
| **Discord** | Webhook URL | 2FA codes, team notifications |
| **Slack** | Webhook URL | 2FA codes, team notifications |

### Notification Types

- **2FA Verification Codes** - 6-digit OTP, 5-minute expiry
- **URL Rotation** - New URL, reason, timestamp
- **Security Alerts** - Failed logins, suspicious activity
- **Recovery Tokens** - Emergency access instructions

### API

```php
$notificationService = $container->get(NotificationService::class);

// Send 2FA code
$result = $notificationService->send2FACode($userId, $code, 'telegram');

// Send security alert
$notificationService->sendSecurityAlert($userId, 'Failed Login', [
    'ip' => $clientIp,
    'attempts' => 5,
]);

// Test channel connectivity
$result = $notificationService->testChannel('telegram', $chatId);

// Configure user channel
$notificationService->configureUserChannel($userId, 'discord', $discordUserId);
```

---

## Security Features

### URL Security

| Feature | Traditional | This Panel |
|---------|-------------|------------|
| URL Pattern | `/admin` | `/x-{random 32 hex}` |
| Entropy | 0 bits | 128 bits |
| Brute Force | Easy | 2^128 combinations |

### CSRF Protection

- Per-session CSRF tokens (256-bit)
- Constant-time comparison (`hash_equals`)
- Auto-regeneration available

```php
// In views
<?= $csrf_input ?>
<!-- Outputs: <input type="hidden" name="_csrf" value="..."> -->

// Validation (automatic in middleware)
$valid = $sessionService->verifyCsrfToken($sessionId, $_POST['_csrf']);
```

### Audit Logging

All actions are logged:

```php
$auditService->log('login_success', $userId, [
    'ip' => $clientIp,
    'method' => '2fa_totp',
]);
```

Logged events:
- `login_success`, `login_failed`
- `2fa_enabled`, `2fa_disabled`
- `password_changed`
- `session_created`, `session_destroyed`
- `url_rotated`
- `emergency_token_created`, `emergency_token_used`

### Database Pool

- Connection pooling with LIFO reuse
- Circuit breaker (trips on failures, auto-recovers)
- Distributed state via Redis
- Metrics and monitoring

```php
$pool = $container->get(DatabasePool::class);

// Execute query with automatic connection management
$users = $pool->query('SELECT * FROM admin_users WHERE id = ?', [$id]);

// Pool stats
$stats = $pool->getStats();
// ['active' => 2, 'idle' => 8, 'total' => 10, 'circuit' => 'CLOSED']
```

---

## Dashboard Features

The admin dashboard includes real-time metrics:

- **Database Pool** - Connections, queries, circuit breaker state
- **Redis** - Workers, memory, commands
- **Audit Log** - Recent activity
- **System Info** - PHP version, memory usage

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

| Service | URL | Purpose |
|---------|-----|---------|
| PostgreSQL | localhost:5432 | Database |
| Redis | localhost:6379 | Circuit breaker |
| Mailpit | http://localhost:8025 | View 2FA emails |
| Admin Panel | (secret URL) | Your admin panel |

---

## Documentation

See the [`docs/`](docs/) folder:

- [Quick Start](docs/QUICK_START.md) - Get running fast
- [CLI Commands](docs/CLI-COMMANDS.md) - All CLI commands in detail
- [Performance](docs/PERFORMANCE.md) - OPcache, Redis, caching
- [Database](docs/DATABASE.md) - Database access and configuration
- [Architecture](docs/ARCHITECTURE.md) - System design

---

## Development Setup (Package Maintainers)

If you're developing the package itself (not using it as a dependency), follow these steps:

### Prerequisites

Your test project must have **both** packages installed:

```json
{
    "require": {
        "ados-labs/enterprise-admin-panel": "*",
        "ados-labs/enterprise-psr3-logger": "*"
    },
    "repositories": [
        {"type": "path", "url": "/path/to/enterprise-admin-panel", "options": {"symlink": true}},
        {"type": "path", "url": "/path/to/enterprise-psr3-logger", "options": {"symlink": true}}
    ]
}
```

### Get Admin URL (Development)

**IMPORTANT:** Run the command from your **test project directory**, not from the package directory!

```bash
# CORRECT - from test project directory
cd /path/to/myproject
php /path/to/enterprise-admin-panel/elf/url-get.php \
  --token='master-xxxxx' \
  --email='admin@example.com' \
  --password='your_password'

# WRONG - this will fail with "Class not found"
cd /path/to/enterprise-admin-panel
php elf/url-get.php --token=... --email=... --password=...
```

**Why?** The CLI scripts need access to both `enterprise-admin-panel` AND `enterprise-psr3-logger` classes. Your test project's `vendor/autoload.php` has both, while the package's own `vendor/` only has its direct dependencies.

### After Modifying composer.json in the Package

If you change `composer.json` in `enterprise-psr3-logger` or `enterprise-admin-panel` (e.g., removing/adding autoload files), you **must** reinstall in your test project:

```bash
cd /path/to/myproject
rm -rf vendor composer.lock
composer install
```

This refreshes the autoloader with the new configuration.

---

## Troubleshooting

### "DB_PASSWORD is required"

Create `.env` with `DB_PASSWORD` before running the installer.

### "404 Not Found" on /admin

Expected. Use the secret URL from installation.

### Lost the admin URL?

**If using as dependency:**
```bash
php vendor/ados-labs/enterprise-admin-panel/elf/url-get.php \
  --token=MASTER_TOKEN --email=EMAIL --password=PASSWORD
```

**If developing the package:**
```bash
cd /path/to/myproject  # Your test project with both packages
php /path/to/enterprise-admin-panel/elf/url-get.php \
  --token=MASTER_TOKEN --email=EMAIL --password=PASSWORD
```

### 2FA codes not arriving

**Email:** Check Mailpit: http://localhost:8025

**Telegram:**
1. Verify bot token: `curl https://api.telegram.org/bot<TOKEN>/getMe`
2. Verify chat ID: User must have sent `/start` to your bot

**Discord/Slack:** Test webhook manually with curl

### "Class not found" errors in CLI commands

**Error:** `Class "AdosLabs\AdminPanel\Bootstrap" not found` or `Class "AdosLabs\EnterprisePSR3Logger\LoggerFacade" not found`

**Cause:** You're running the command from the wrong directory. The package's own `vendor/` doesn't include all required dependencies.

**Solution:** Run from your test project directory:
```bash
cd /path/to/myproject  # Has both packages installed
php /path/to/enterprise-admin-panel/elf/url-get.php --token=... --email=... --password=...
```

### "Failed to open stream: should_log_stub.php"

**Error:** `require(...should_log_stub.php): Failed to open stream: No such file or directory`

**Cause:** The `composer.lock` file has stale autoload configuration after the package was updated.

**Solution:** Reinstall dependencies:
```bash
cd /path/to/myproject
rm -rf vendor composer.lock
composer install
```

### Connection refused

Make sure Docker services are running:
```bash
docker-compose up -d
docker-compose ps
```

---

## License

MIT License - see [LICENSE](LICENSE)
