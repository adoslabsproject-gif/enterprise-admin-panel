#!/usr/bin/env php
<?php
/**
 * Enterprise Lightning Framework (ELF) - First-Time Installation
 *
 * THIS SCRIPT SHOWS THE ADMIN URL ONLY ONCE!
 * Save it securely - you won't be able to see it again without recovery.
 *
 * Usage:
 *   php elf/install.php
 *   php elf/install.php --driver=mysql
 *   php elf/install.php --send-email=admin@company.com
 *   php elf/install.php --send-telegram=123456789
 */

declare(strict_types=1);

// Autoload
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
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
    echo "Dependencies are not installed. Run this command first:\n\n";

    // Detect the package root (where composer.json is)
    $packageRoot = dirname(__DIR__);
    echo "  cd {$packageRoot}\n";
    echo "  composer install\n";
    echo "\n";
    echo "Then run this script again:\n\n";
    echo "  cd {$packageRoot}/elf\n";
    echo "  php install.php\n";
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
    'admin-email:',
    'admin-password:',
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
2. Creates the admin user
3. Generates the SECURE ADMIN URL
4. Shows the URL ONCE (save it!)

Usage:
  php elf/install.php [options]

Options:
  --driver=DRIVER         Database driver: postgresql or mysql (default: postgresql)
  --host=HOST             Database host (default: localhost)
  --port=PORT             Database port (default: 5432/3306)
  --database=DB           Database name (default: admin_panel)
  --username=USER         Database username (default: admin)
  --password=PASS         Database password (default: secret)
  --admin-email=EMAIL     Admin email (default: admin@example.com)
  --admin-password=PASS   Admin password (default: random generated)
  --admin-name=NAME       Admin name (default: Administrator)
  --send-email=EMAIL      Send credentials to this email
  --send-telegram=ID      Send credentials to this Telegram chat ID
  --non-interactive       Suppress interactive prompts (for scripting)
  --json                  Output credentials as JSON (for automation)
  --help                  Show this help

SECURITY WARNING:
  The admin URL is shown ONLY ONCE during installation!
  Save it in a password manager or secure location.
  You will NOT be able to retrieve it without the recovery process.

HELP;
    exit(0);
}

// ============================================================================
// Configuration
// ============================================================================

$driver = $options['driver'] ?? 'postgresql';
$host = $options['host'] ?? 'localhost';
$port = $options['port'] ?? ($driver === 'mysql' ? '3306' : '5432');
$database = $options['database'] ?? 'admin_panel';
$username = $options['username'] ?? 'admin';
$password = $options['password'] ?? 'secret';

$adminEmail = $options['admin-email'] ?? 'admin@example.com';
$adminPassword = $options['admin-password'] ?? bin2hex(random_bytes(12)); // Random 24-char password
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

$envFile = dirname(__DIR__) . '/.env';
$projectEnvFile = getcwd() . '/.env';
$targetEnvFile = file_exists($projectEnvFile) ? $projectEnvFile : $envFile;

$appKey = getenv('APP_KEY');

if (!$appKey || $appKey === '') {
    // Check if already in .env file
    if (file_exists($targetEnvFile)) {
        $envContent = file_get_contents($targetEnvFile);
        if (preg_match('/^APP_KEY=([a-f0-9]{64})$/m', $envContent, $matches)) {
            $appKey = $matches[1];
            putenv("APP_KEY={$appKey}");
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
            // Add APP_KEY after APP_URL or at end
            if (str_contains($envContent, 'APP_URL=')) {
                $envContent = preg_replace('/(APP_URL=[^\n]*\n)/', "$1APP_KEY={$appKey}\n", $envContent);
            } else {
                $envContent .= "\nAPP_KEY={$appKey}\n";
            }
        }
        file_put_contents($targetEnvFile, $envContent);
    }

    putenv("APP_KEY={$appKey}");
    echo "  [OK] APP_KEY generated and saved to .env\n";
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
        echo "The admin URL was shown during the first installation.\n";
        echo "For security, it cannot be displayed again.\n\n";
        echo "If you lost the URL, use emergency recovery:\n";
        echo "  php elf/token-emergency-use.php --token=YOUR_TOKEN\n\n";
        exit(1);
    }
} catch (PDOException $e) {
    // Table doesn't exist yet, continue with installation
}

// ============================================================================
// Run Migrations
// ============================================================================

echo "Running database migrations...\n";

$migrationsPath = __DIR__ . '/../src/Database/migrations/' . ($driver === 'mysql' ? 'mysql' : 'postgresql');
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
// Create Admin User
// ============================================================================

echo "Creating admin user...\n";

// Check if already exists
$stmt = $pdo->prepare('SELECT id FROM admin_users WHERE email = ?');
$stmt->execute([$adminEmail]);

if ($stmt->fetch()) {
    echo "  [!!] Admin user already exists: {$adminEmail}\n";
} else {
    $passwordHash = password_hash($adminPassword, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 3,
    ]);

    $stmt = $pdo->prepare('
        INSERT INTO admin_users (email, password_hash, name, role, permissions, is_active, is_master, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ');

    $stmt->execute([
        $adminEmail,
        $passwordHash,
        $adminName,
        'super_admin',
        json_encode(['*']),
        true,
        true, // MASTER ADMIN
    ]);

    echo "  [OK] Admin user created: {$adminEmail} (MASTER)\n";
}

echo "\n";

// ============================================================================
// Generate and ENCRYPT the Admin URL
// ============================================================================

echo "Generating secure admin URL...\n";

// Generate cryptographic URL: /x-{32 hex chars} = 128 bits entropy
$adminUrl = '/x-' . bin2hex(random_bytes(16));

// ENCRYPT before storing in database
$encryptedUrl = $encryption->encrypt($adminUrl);

// Check if admin_base_path exists
$stmt = $pdo->query("SELECT config_value FROM admin_config WHERE config_key = 'admin_base_path'");
$existingUrl = $stmt->fetchColumn();

$isFirstInstall = false;

if ($existingUrl) {
    // URL already exists - this is a re-install, DO NOT show it
    echo "  [!!] Admin URL already configured (encrypted in database)\n";
    echo "  [!!] For security, the URL cannot be displayed again.\n";
    echo "  [!!] Use emergency recovery token if you lost it.\n\n";

    // Try to decrypt existing URL for internal use
    $adminUrl = $encryption->decrypt($existingUrl);
    if ($adminUrl === null) {
        echo "  [FAIL] Error: Cannot decrypt existing URL. APP_KEY may have changed.\n";
        exit(1);
    }
} else {
    $isFirstInstall = true;

    // Save ENCRYPTED URL
    $stmt = $pdo->prepare("
        INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
        VALUES ('admin_base_path', ?, 'string', 'Encrypted admin panel base URL', true, false)
    ");
    $stmt->execute([$encryptedUrl]);
    echo "  [OK] Admin URL generated and encrypted\n\n";
}

// ============================================================================
// Mark as installed (prevents showing URL again)
// ============================================================================

$stmt = $pdo->prepare("
    INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
    VALUES ('installed_at', ?, 'string', 'Installation timestamp', false, false)
    ON CONFLICT (config_key) DO NOTHING
");
$stmt->execute([date('Y-m-d H:i:s')]);

// Build full URL (needed for email/telegram notifications)
$fullUrl = "http://localhost:8080{$adminUrl}/login";

// ============================================================================
// Optional: Send via Email (using Mailpit in dev, SMTP in production)
// ============================================================================

if ($sendEmail && $isFirstInstall) {
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

SECURITY WARNINGS:
1. Save these credentials in a password manager
2. The URL above will NOT be shown again
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

if ($sendTelegram && $isFirstInstall) {
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
        'first_install' => $isFirstInstall,
        'database' => [
            'driver' => $driver,
            'host' => $host,
            'port' => $port,
            'database' => $database,
        ],
        'next_steps' => [
            'Start server: php -S localhost:8080 -t public',
        ],
    ];

    // Only include sensitive data on first install
    if ($isFirstInstall) {
        $output['admin_url'] = $fullUrl;
        $output['admin_base_path'] = $adminUrl;
        $output['credentials'] = [
            'email' => $adminEmail,
            'password' => $adminPassword,
        ];
        $output['next_steps'][] = 'Open: ' . $fullUrl;
        $output['next_steps'][] = 'Login with credentials above';
    } else {
        $output['message'] = 'Already installed. URL hidden for security.';
    }

    echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

// Human-readable output
if ($isFirstInstall) {
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║  [!!] SAVE THESE CREDENTIALS NOW - SHOWN ONLY ONCE!             ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "┌────────────────────────────────────────────────────────────────┐\n";
    echo "│  ADMIN PANEL URL (SECRET - DO NOT SHARE!)                      │\n";
    echo "├────────────────────────────────────────────────────────────────┤\n";
    echo "│                                                                │\n";
    printf("│  %-62s│\n", $fullUrl);
    echo "│                                                                │\n";
    echo "├────────────────────────────────────────────────────────────────┤\n";
    echo "│  LOGIN CREDENTIALS                                             │\n";
    echo "├────────────────────────────────────────────────────────────────┤\n";
    printf("│  Email:    %-51s│\n", $adminEmail);
    printf("│  Password: %-51s│\n", $adminPassword);
    echo "│                                                                │\n";
    echo "└────────────────────────────────────────────────────────────────┘\n";
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║  SECURITY WARNINGS                                              ║\n";
    echo "╠══════════════════════════════════════════════════════════════════╣\n";
    echo "║  1. Save these credentials in a PASSWORD MANAGER                ║\n";
    echo "║  2. The URL above will NEVER be shown again                     ║\n";
    echo "║  3. /admin/login is BLOCKED - only the secret URL works         ║\n";
    echo "║  4. Change your password after first login                      ║\n";
    echo "║  5. Enable 2FA for maximum security                             ║\n";
    echo "║  6. Generate emergency recovery tokens from the dashboard       ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
} else {
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════╗\n";
    echo "║  ALREADY INSTALLED                                              ║\n";
    echo "╚══════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "The admin URL is encrypted in the database and cannot be shown.\n\n";
    echo "If you lost access:\n";
    echo "  1. Use your emergency recovery token (if you created one)\n";
    echo "  2. Or reset the database and re-install\n\n";
}
echo "Next steps:\n";
echo "  1. Start server:  php -S localhost:8080 -t public\n";
if ($isFirstInstall) {
    echo "  2. Open browser:  {$fullUrl}\n";
    echo "  3. Login with:    {$adminEmail} / {$adminPassword}\n";
}
echo "\n";
echo "Installation complete!\n\n";
