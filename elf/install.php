#!/usr/bin/env php
<?php
/**
 * Enterprise Lightning Framework (ELF) - First-Time Installation
 *
 * THIS SCRIPT SHOWS CREDENTIALS ONLY ONCE!
 * Save the admin URL, password, and master token securely.
 *
 * Usage (from project root):
 *   php vendor/ados-labs/enterprise-admin-panel/elf/install.php
 *   php vendor/ados-labs/enterprise-admin-panel/elf/install.php --email=admin@company.com
 *
 * This creates:
 *   - .env in project root
 *   - public/index.php in project root
 */

declare(strict_types=1);

// ============================================================================
// Detect project root (where composer.json is)
// ============================================================================

$packageRoot = dirname(__DIR__); // enterprise-admin-panel/
$projectRoot = null;

// Walk up to find project root (has composer.json but is NOT our package)
$searchDir = getcwd();
for ($i = 0; $i < 10; $i++) {
    if (file_exists($searchDir . '/composer.json')) {
        $composerJson = json_decode(file_get_contents($searchDir . '/composer.json'), true);
        // Check if this is NOT the package itself
        if (($composerJson['name'] ?? '') !== 'ados-labs/enterprise-admin-panel') {
            $projectRoot = $searchDir;
            break;
        }
    }
    $parent = dirname($searchDir);
    if ($parent === $searchDir) break;
    $searchDir = $parent;
}

// Fallback: if running from package dir directly, use package as project
if ($projectRoot === null) {
    $projectRoot = $packageRoot;
}

// ============================================================================
// Autoload
// ============================================================================

$autoloadPaths = [
    $projectRoot . '/vendor/autoload.php',
    $packageRoot . '/vendor/autoload.php',
];

$autoloaded = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $autoloaded = true;
        break;
    }
}

if (!$autoloaded) {
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║  ERROR: Could not find autoload.php                              ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "Run composer install first:\n\n";
    echo "  cd {$projectRoot}\n";
    echo "  composer install\n";
    echo "\n";
    exit(1);
}

use AdosLabs\AdminPanel\Database\MigrationRunner;
use AdosLabs\AdminPanel\Services\EncryptionService;

// Parse command line arguments
$options = getopt('', [
    'driver:',
    'host:',
    'port:',
    'database:',
    'username:',
    'password:',
    'email:',
    'admin-name:',
    'send-email:',
    'send-telegram:',
    'non-interactive',
    'json',
    'help',
]);

$nonInteractive = isset($options['non-interactive']);
$jsonOutput = isset($options['json']);

if (isset($options['help'])) {
    echo <<<HELP
Enterprise Lightning Framework (ELF) - First-Time Installation
===============================================================

This script:
1. Runs all database migrations
2. Creates the master admin user with secure password
3. Generates the SECURE ADMIN URL (shown ONCE!)
4. Generates the MASTER CLI TOKEN (shown ONCE!)

Usage:
  php elf/install.php [options]

Options:
  --driver=DRIVER         Database driver: postgresql or mysql (default: postgresql)
  --host=HOST             Database host (default: localhost)
  --port=PORT             Database port (default: 5432/3306)
  --database=DB           Database name (default: admin_panel)
  --username=USER         Database username (default: admin)
  --password=PASS         Database password (default: secret)
  --email=EMAIL           Admin email (default: admin@example.com)
  --admin-name=NAME       Admin name (default: Administrator)
  --send-email=EMAIL      Send credentials to this email
  --send-telegram=ID      Send credentials to this Telegram chat ID
  --non-interactive       Suppress interactive prompts (for scripting)
  --json                  Output credentials as JSON (for automation)
  --help                  Show this help

SECURITY WARNING:
  All credentials are shown ONLY ONCE during installation!
  - Admin URL
  - Admin Password
  - Master CLI Token

  Save them in a password manager or secure location.
  You will NOT be able to retrieve them without the recovery process.

HELP;
    exit(0);
}

// ============================================================================
// Load existing .env if present (for DB credentials, etc.)
// ============================================================================

$targetEnvFile = $projectRoot . '/.env';
if (file_exists($targetEnvFile)) {
    $envLines = file($targetEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Don't override if already set in environment
            if (getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }
}

// ============================================================================
// Configuration (from CLI options, then .env, then defaults)
// ============================================================================

$driverInput = $options['driver'] ?? getenv('DB_DRIVER') ?: 'pgsql';
// Normalize driver name: accept both 'postgresql' and 'pgsql', store as 'pgsql'
$driver = match ($driverInput) {
    'postgresql', 'pgsql' => 'pgsql',
    'mysql' => 'mysql',
    default => 'pgsql',
};
$host = $options['host'] ?? getenv('DB_HOST') ?: 'localhost';
$port = $options['port'] ?? getenv('DB_PORT') ?: ($driver === 'mysql' ? '3306' : '5432');
$database = $options['database'] ?? getenv('DB_DATABASE') ?: 'admin_panel';
$username = $options['username'] ?? getenv('DB_USERNAME') ?: 'admin';
$password = $options['password'] ?? getenv('DB_PASSWORD') ?: null;

// SECURITY: No hardcoded passwords - must be in .env or CLI
if (empty($password)) {
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║  [ERROR] DB_PASSWORD is required                                 ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "Set the database password in one of these ways:\n";
    echo "\n";
    echo "  1. Create .env file BEFORE running install:\n";
    echo "     echo 'DB_PASSWORD=your_secure_password' >> .env\n";
    echo "\n";
    echo "  2. Pass via CLI option:\n";
    echo "     php vendor/.../elf/install.php --password=your_secure_password\n";
    echo "\n";
    exit(1);
}

$adminEmail = $options['email'] ?? 'admin@example.com';
$adminPassword = generate_secure_password(20); // Secure password with special chars
$adminName = $options['admin-name'] ?? 'Administrator';

$sendEmail = $options['send-email'] ?? null;
$sendTelegram = $options['send-telegram'] ?? null;

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║     ENTERPRISE LIGHTNING FRAMEWORK (ELF) - INSTALLATION         ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
echo "\n";

// ============================================================================
// Generate or Load APP_KEY (Required for encryption)
// ============================================================================

// ALWAYS use project root for .env - never CWD or package dir
$targetEnvFile = $projectRoot . '/.env';

$appKey = getenv('APP_KEY');

if (!$appKey || $appKey === '') {
    // Check if already in .env file
    if (file_exists($targetEnvFile)) {
        $envContent = file_get_contents($targetEnvFile);
        if (preg_match('/^APP_KEY=([a-f0-9]{64})$/m', $envContent, $matches)) {
            $appKey = $matches[1];
            putenv("APP_KEY={$appKey}");
            echo "Using existing APP_KEY from {$targetEnvFile}\n";
        }
    }
}

if (!$appKey || $appKey === '') {
    // Generate new APP_KEY
    $appKey = bin2hex(random_bytes(32));
    echo "Generating encryption key (APP_KEY)...\n";

    if (file_exists($targetEnvFile)) {
        $envContent = file_get_contents($targetEnvFile);
        if (str_contains($envContent, 'APP_KEY=')) {
            // Replace empty APP_KEY
            $envContent = preg_replace('/^APP_KEY=.*$/m', "APP_KEY={$appKey}", $envContent);
        } else {
            // Add APP_KEY
            $envContent .= "\nAPP_KEY={$appKey}\n";
        }
    } else {
        // Create new .env file - NO hardcoded passwords!
        $envContent = <<<ENV
# Enterprise Admin Panel Configuration
# Generated by install.php

# Encryption key (NEVER share, NEVER commit to git)
APP_KEY={$appKey}

# Database - SET YOUR PASSWORD BEFORE RUNNING INSTALL!
DB_DRIVER={$driver}
DB_HOST={$host}
DB_PORT={$port}
DB_DATABASE={$database}
DB_USERNAME={$username}
DB_PASSWORD={$password}

# Redis (optional - for distributed circuit breaker)
# REDIS_HOST=localhost
# REDIS_PORT=6379
# REDIS_PASSWORD=your_redis_password

# SMTP (Mailpit for development)
SMTP_HOST=localhost
SMTP_PORT=1025
SMTP_FROM=admin@localhost

# Environment
APP_ENV=development
APP_DEBUG=true
ENV;
    }

    file_put_contents($targetEnvFile, $envContent);

    putenv("APP_KEY={$appKey}");
    echo "  [OK] APP_KEY generated and saved to {$targetEnvFile}\n";
    echo "  [!!] BACKUP THIS KEY! Without it, you cannot decrypt the admin URL.\n\n";
}

$encryption = new EncryptionService($appKey);

// ============================================================================
// Database Connection
// ============================================================================

$dsn = match ($driver) {
    'postgresql', 'pgsql' => "pgsql:host={$host};port={$port};dbname={$database}",
    'mysql' => "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    default => throw new InvalidArgumentException("Unsupported driver: {$driver}"),
};

echo "Connecting to {$driver}://{$host}:{$port}/{$database}...\n";

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "  [OK] Connected successfully\n\n";
} catch (PDOException $e) {
    echo "  [FAIL] Connection failed: {$e->getMessage()}\n\n";
    echo "Make sure the database is running:\n";
    echo "  cd elf && docker-compose up -d\n\n";
    exit(1);
}

// ============================================================================
// Check if already installed
// ============================================================================

try {
    $stmt = $pdo->query("SELECT config_value FROM admin_config WHERE config_key = 'installed_at'");
    $installedAt = $stmt->fetchColumn();

    if ($installedAt) {
        echo "╔══════════════════════════════════════════════════════════════════╗\n";
        echo "║  [!!] ALREADY INSTALLED                                          ║\n";
        echo "╚══════════════════════════════════════════════════════════════════╝\n";
        echo "\n";
        echo "This admin panel was already installed on: {$installedAt}\n\n";
        echo "The admin URL and credentials were shown during the first installation.\n";
        echo "For security, they cannot be displayed again.\n\n";
        echo "If you lost access, use emergency recovery:\n";
        echo "  php elf/token-emergency-create.php --token=YOUR_MASTER_TOKEN --email=YOUR_EMAIL --password=YOUR_PASSWORD\n\n";
        exit(1);
    }
} catch (PDOException $e) {
    // Table doesn't exist yet, continue with installation
}

// ============================================================================
// Run Migrations
// ============================================================================

echo "Running database migrations...\n";

// Normalize driver name for migrations folder
$migrationsDriver = match ($driver) {
    'mysql' => 'mysql',
    'postgresql', 'pgsql' => 'postgresql',
    default => 'postgresql',
};
$migrationsPath = __DIR__ . '/../src/Database/migrations/' . $migrationsDriver;
$runner = new MigrationRunner($pdo, null, $migrationsPath);

$result = $runner->migrate();

echo "  [OK] Executed: {$result['executed']} migrations\n";

if (!empty($result['errors'])) {
    echo "  [FAIL] Errors:\n";
    foreach ($result['errors'] as $error) {
        echo "    - {$error}\n";
    }
    exit(1);
}

echo "\n";

// ============================================================================
// Create Admin User with Master Token
// ============================================================================

echo "Creating master admin user...\n";

// Check if already exists
$stmt = $pdo->prepare('SELECT id FROM admin_users WHERE email = ?');
$stmt->execute([$adminEmail]);

if ($stmt->fetch()) {
    echo "  [FAIL] Admin user already exists: {$adminEmail}\n";
    echo "  If you need to reset, drop the admin_users table and re-run install.\n\n";
    exit(1);
}

// Hash password with Argon2id
$passwordHash = password_hash($adminPassword, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,
    'time_cost' => 4,
    'threads' => 3,
]);

// Generate master CLI token
$plainMasterToken = generate_master_token();

// Hash master token with Argon2id (like passwords)
$masterTokenHash = password_hash($plainMasterToken, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,
    'time_cost' => 4,
    'threads' => 3,
]);

$stmt = $pdo->prepare('
    INSERT INTO admin_users (
        email, password_hash, name, role, permissions,
        is_active, is_master, cli_token_hash, cli_token_generated_at,
        created_at, updated_at
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())
');

$stmt->execute([
    $adminEmail,
    $passwordHash,
    $adminName,
    'super_admin',
    json_encode(['*']),
    true,
    true, // MASTER ADMIN
    $masterTokenHash,
    // 2FA enabled by default from database schema (two_factor_enabled DEFAULT true)
]);

$adminUserId = $pdo->lastInsertId();

echo "  [OK] Master admin created: {$adminEmail}\n";
echo "  [OK] Master CLI token generated\n\n";

// ============================================================================
// Generate and ENCRYPT the Admin URL
// ============================================================================

echo "Generating secure admin URL...\n";

// Generate cryptographic URL: /x-{32 hex chars} = 128 bits entropy
$adminUrl = '/x-' . bin2hex(random_bytes(16));

// ENCRYPT before storing in database
$encryptedUrl = $encryption->encrypt($adminUrl);

// Save ENCRYPTED URL
$stmt = $pdo->prepare("
    INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
    VALUES ('admin_base_path', ?, 'string', 'Encrypted admin panel base URL', true, false)
");
$stmt->execute([$encryptedUrl]);
echo "  [OK] Admin URL generated and encrypted\n\n";

// ============================================================================
// Mark as installed (prevents showing URL again)
// ============================================================================

// Multi-driver support for installed_at marker
$installedAtSql = match ($migrationsDriver) {
    'mysql' => "INSERT IGNORE INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
                VALUES ('installed_at', ?, 'string', 'Installation timestamp', false, false)",
    default => "INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
                VALUES ('installed_at', ?, 'string', 'Installation timestamp', false, false)
                ON CONFLICT (config_key) DO NOTHING",
};
$stmt = $pdo->prepare($installedAtSql);
$stmt->execute([date('Y-m-d H:i:s')]);

// ============================================================================
// Audit log
// ============================================================================

$stmt = $pdo->prepare("
    INSERT INTO admin_audit_log (user_id, action, metadata, ip_address, created_at)
    VALUES (?, 'system_installed', ?, 'CLI', NOW())
");
$stmt->execute([
    $adminUserId,
    json_encode([
        'email' => $adminEmail,
        'driver' => $driver,
        'host' => $host,
    ]),
]);

// Build full URL
$fullUrl = "http://localhost:8080{$adminUrl}/login";

// ============================================================================
// Optional: Send via Email (using Mailpit in dev, SMTP in production)
// ============================================================================

if ($sendEmail) {
    echo "Sending credentials to {$sendEmail}...\n";

    $smtpHost = getenv('SMTP_HOST') ?: 'localhost';
    $smtpPort = (int)(getenv('SMTP_PORT') ?: 1025);
    $smtpFrom = getenv('SMTP_FROM') ?: 'admin@localhost';

    $subject = "Enterprise Admin Panel - Your Credentials";
    $body = <<<EMAIL
Enterprise Lightning Framework (ELF) - Installation Complete
=============================================================

Your admin panel has been installed successfully.

ADMIN URL (SECRET - DO NOT SHARE):
{$fullUrl}

LOGIN CREDENTIALS:
Email:    {$adminEmail}
Password: {$adminPassword}

MASTER CLI TOKEN (for emergency recovery and CLI operations):
{$plainMasterToken}

SECURITY WARNINGS:
1. Save these credentials in a password manager
2. The URL, password, and token will NOT be shown again
3. /admin/login is BLOCKED - only the secret URL works
4. Change your password after first login
5. Enable 2FA for maximum security

---
This email was sent automatically during installation.
EMAIL;

    try {
        $socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 5);
        if ($socket) {
            // Simple SMTP conversation
            fgets($socket, 1024);
            fputs($socket, "HELO localhost\r\n");
            fgets($socket, 1024);
            fputs($socket, "MAIL FROM:<{$smtpFrom}>\r\n");
            fgets($socket, 1024);
            fputs($socket, "RCPT TO:<{$sendEmail}>\r\n");
            fgets($socket, 1024);
            fputs($socket, "DATA\r\n");
            fgets($socket, 1024);
            fputs($socket, "Subject: {$subject}\r\n");
            fputs($socket, "From: Enterprise Admin <{$smtpFrom}>\r\n");
            fputs($socket, "To: {$sendEmail}\r\n");
            fputs($socket, "Content-Type: text/plain; charset=UTF-8\r\n");
            fputs($socket, "\r\n");
            fputs($socket, "{$body}\r\n");
            fputs($socket, ".\r\n");
            fgets($socket, 1024);
            fputs($socket, "QUIT\r\n");
            fclose($socket);
            echo "  [OK] Email sent to {$sendEmail}\n";
            echo "    View in Mailpit: http://localhost:8025\n";
        } else {
            echo "  [!!] Could not connect to SMTP server: {$errstr}\n";
        }
    } catch (Exception $e) {
        echo "  [!!] Email failed: {$e->getMessage()}\n";
    }
}

// ============================================================================
// Optional: Send via Telegram
// ============================================================================

if ($sendTelegram) {
    echo "Sending credentials to Telegram chat {$sendTelegram}...\n";

    $botToken = getenv('TELEGRAM_BOT_TOKEN');
    if (!$botToken) {
        echo "  [!!] TELEGRAM_BOT_TOKEN environment variable not set\n";
    } else {
        $message = <<<TELEGRAM
*Enterprise Lightning Framework (ELF) - Installation Complete*

*Admin URL:*
`{$fullUrl}`

*Credentials:*
Email: `{$adminEmail}`
Password: `{$adminPassword}`

*Master CLI Token:*
`{$plainMasterToken}`

*Security:* Save these credentials securely!
TELEGRAM;

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $data = [
            'chat_id' => $sendTelegram,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            echo "  [OK] Telegram message sent\n";
        } else {
            echo "  [!!] Telegram failed: HTTP {$httpCode}\n";
        }
    }
}

// ============================================================================
// Display Credentials (ONLY ON FIRST INSTALL!)
// ============================================================================

// JSON output for automation
if ($jsonOutput) {
    $output = [
        'success' => true,
        'admin_url' => $fullUrl,
        'admin_base_path' => $adminUrl,
        'credentials' => [
            'email' => $adminEmail,
            'password' => $adminPassword,
        ],
        'master_token' => $plainMasterToken,
        'database' => [
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => $database,
        ],
        'next_steps' => [
            'Start server: php -S localhost:8080 router.php',
            'Open: ' . $fullUrl,
            'Login with credentials above',
        ],
        'warning' => 'SAVE THESE CREDENTIALS! They will NOT be shown again.',
    ];

    echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

// Human-readable output
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║  [!!] SAVE THESE CREDENTIALS NOW - SHOWN ONLY ONCE!                         ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "┌──────────────────────────────────────────────────────────────────────────────┐\n";
echo "│  ADMIN PANEL URL (SECRET - DO NOT SHARE!)                                   │\n";
echo "├──────────────────────────────────────────────────────────────────────────────┤\n";
echo "│                                                                             │\n";
printf("│  %-75s│\n", $fullUrl);
echo "│                                                                             │\n";
echo "├──────────────────────────────────────────────────────────────────────────────┤\n";
echo "│  LOGIN CREDENTIALS                                                          │\n";
echo "├──────────────────────────────────────────────────────────────────────────────┤\n";
printf("│  Email:    %-64s│\n", $adminEmail);
printf("│  Password: %-64s│\n", $adminPassword);
echo "│                                                                             │\n";
echo "├──────────────────────────────────────────────────────────────────────────────┤\n";
echo "│  MASTER CLI TOKEN (for CLI operations)                                      │\n";
echo "├──────────────────────────────────────────────────────────────────────────────┤\n";
echo "│                                                                             │\n";
printf("│  %-75s│\n", $plainMasterToken);
echo "│                                                                             │\n";
echo "└──────────────────────────────────────────────────────────────────────────────┘\n";
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║  SECURITY INFO                                                              ║\n";
echo "╠══════════════════════════════════════════════════════════════════════════════╣\n";
echo "║  • 2FA is ENABLED by default (codes sent via email)                         ║\n";
echo "║  • Save ALL credentials in a PASSWORD MANAGER                               ║\n";
echo "║  • These credentials will NEVER be shown again                              ║\n";
echo "║  • /admin is BLOCKED - only the secret URL works                            ║\n";
echo "║  • To disable 2FA: UPDATE admin_users SET two_factor_enabled=false          ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "DEVELOPMENT TOOLS:\n";
echo "  Mailpit (view 2FA emails): http://localhost:8025\n";
echo "\n";
echo "CLI COMMANDS (all require --token=MASTER_TOKEN --email=EMAIL --password=PASSWORD):\n";
echo "  Change password:        php vendor/ados-labs/enterprise-admin-panel/elf/password-change.php --new-password=...\n";
echo "  Create emergency token: php vendor/ados-labs/enterprise-admin-panel/elf/token-emergency-create.php\n";
echo "  Regenerate CLI token:   php vendor/ados-labs/enterprise-admin-panel/elf/token-master-regenerate.php\n";
echo "\n";
echo "EMERGENCY ACCESS (if locked out - bypasses login + 2FA, goes to dashboard):\n";
echo "  1. First create token:  php elf/token-emergency-create.php --token=... --email=... --password=...\n";
echo "  2. Then use via CLI:    php elf/token-emergency-use.php --token=EMERGENCY_TOKEN\n";
echo "  3. Or via browser:      http://localhost:8080/emergency-login?token=EMERGENCY_TOKEN\n";
echo "\n";

// ============================================================================
// Create public/index.php in project root
// ============================================================================

$publicDir = $projectRoot . '/public';
$indexFile = $publicDir . '/index.php';

if (!is_dir($publicDir)) {
    mkdir($publicDir, 0755, true);
    echo "Creating public/ directory...\n";
}

// Create index.php that bootstraps from vendor
$indexContent = <<<'PHP'
<?php
/**
 * Enterprise Admin Panel - Entry Point
 *
 * This file bootstraps the admin panel from vendor.
 * Generated by: php vendor/ados-labs/enterprise-admin-panel/elf/install.php
 */

declare(strict_types=1);

// Define project root BEFORE loading the package
// This ensures .env is found correctly even with symlinked packages
define('EAP_PROJECT_ROOT', dirname(__DIR__));

// Load the actual index.php from vendor
require __DIR__ . '/../vendor/ados-labs/enterprise-admin-panel/public/index.php';
PHP;

file_put_contents($indexFile, $indexContent);
echo "  [OK] Created public/index.php\n";

// Copy CSS and JS assets
$packagePublic = $packageRoot . '/public';
$assetDirs = ['css', 'js'];

foreach ($assetDirs as $assetDir) {
    $sourceDir = $packagePublic . '/' . $assetDir;
    $targetDir = $publicDir . '/' . $assetDir;

    if (is_dir($sourceDir)) {
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // Copy all files
        $files = glob($sourceDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                copy($file, $targetDir . '/' . basename($file));
            }
        }
        echo "  [OK] Copied {$assetDir}/ assets\n";
    }
}

// Copy favicon files
$faviconFiles = [
    'favicon.ico',
    'favicon-16x16.png',
    'favicon-32x32.png',
    'apple-touch-icon.png',
    'android-chrome-192x192.png',
    'android-chrome-512x512.png',
];

$faviconsCopied = 0;
foreach ($faviconFiles as $favicon) {
    $source = $packagePublic . '/' . $favicon;
    if (file_exists($source)) {
        copy($source, $publicDir . '/' . $favicon);
        $faviconsCopied++;
    }
}
if ($faviconsCopied > 0) {
    echo "  [OK] Copied {$faviconsCopied} favicon files\n";
}

// Copy router.php for PHP built-in server
$routerSource = $packageRoot . '/router.php';
$routerTarget = $projectRoot . '/router.php';

if (file_exists($routerSource) && !file_exists($routerTarget)) {
    copy($routerSource, $routerTarget);
    echo "  [OK] Copied router.php (for PHP built-in server)\n";
}

echo "\n";
echo "Next steps:\n";
echo "  1. cd {$projectRoot}\n";
echo "  2. php -S localhost:8080 router.php\n";
echo "  3. Open browser:  {$fullUrl}\n";
echo "  4. Login with:    {$adminEmail} / {$adminPassword}\n";
echo "\n";

// ============================================================================
// OPcache Performance Setup
// ============================================================================

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║  PERFORMANCE OPTIMIZATION (Optional)                                         ║\n";
echo "╠══════════════════════════════════════════════════════════════════════════════╣\n";
echo "║  Enable OPcache preloading for 50-100ms faster first requests:              ║\n";
echo "║                                                                             ║\n";
echo "║  php vendor/ados-labs/enterprise-admin-panel/elf/opcache-setup.php --check  ║\n";
echo "║  php vendor/ados-labs/enterprise-admin-panel/elf/opcache-setup.php --generate║\n";
echo "║                                                                             ║\n";
echo "║  For production: sudo php elf/opcache-setup.php --install --fpm-restart     ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                                                                              ║\n";
echo "║     ▄▄▄       ██████▄  ▒█████    ██████     ██▓    ▄▄▄       ▄▄▄▄    ██████  ║\n";
echo "║    ▒████▄    ▒██    ▒ ▒██▒  ██▒▒██    ▒    ▓██▒   ▒████▄    ▓█████▄ ▒██    ▒ ║\n";
echo "║    ▒██  ▀█▄  ░ ▓██▄   ▒██░  ██▒░ ▓██▄      ▒██░   ▒██  ▀█▄  ▒██▒ ▄██░ ▓██▄   ║\n";
echo "║    ░██▄▄▄▄██   ▒   ██▒▒██   ██░  ▒   ██▒   ▒██░   ░██▄▄▄▄██ ▒██░█▀    ▒   ██▒║\n";
echo "║     ▓█   ▓██▒▒██████▒▒░ ████▓▒░▒██████▒▒   ░██████▒▓█   ▓██▒░▓█  ▀█▓▒██████▒▒║\n";
echo "║     ▒▒   ▓▒█░▒ ▒▓▒ ▒ ░░ ▒░▒░▒░ ▒ ▒▓▒ ▒ ░   ░ ▒░▓  ░▒▒   ▓▒█░░▒▓███▀▒▒ ▒▓▒ ▒ ░║\n";
echo "║      ▒   ▒▒ ░░ ░▒  ░ ░  ░ ▒ ▒░ ░ ░▒  ░ ░   ░ ░ ▒  ░ ▒   ▒▒ ░▒░▒   ░ ░ ░▒  ░ ░║\n";
echo "║      ░   ▒   ░  ░  ░  ░ ░ ░ ▒  ░  ░  ░       ░ ░    ░   ▒    ░    ░ ░  ░  ░  ║\n";
echo "║          ░  ░      ░      ░ ░        ░         ░  ░     ░  ░ ░            ░  ║\n";
echo "║                                                                   ░         ║\n";
echo "║                                                                              ║\n";
echo "║          Enterprise Lightning Framework (ELF) by ADOS LABS                   ║\n";
echo "║                                                                              ║\n";
echo "║    ╔════════════════════════════════════════════════════════════════════╗    ║\n";
echo "║    ║     IL FRAMEWORK PHP PIU' SICURO E VELOCE AL MONDO!               ║    ║\n";
echo "║    ╚════════════════════════════════════════════════════════════════════╝    ║\n";
echo "║                                                                              ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "Installation complete!\n\n";
