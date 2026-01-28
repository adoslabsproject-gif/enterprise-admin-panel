#!/usr/bin/env php
<?php
/**
 * Enterprise Lightning Framework (ELF) - Change Admin Password
 *
 * Changes the password for an admin user.
 * Requires master token + email for authentication.
 * The master token IS the ultimate authority - no current password needed.
 *
 * Usage:
 *   php elf/password-change.php --token=MASTER_TOKEN --email=admin@example.com --new-password=NEW_PASSWORD
 */

declare(strict_types=1);

$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

// Load .env
$envFile = __DIR__ . '/../.env';
if (!file_exists($envFile)) {
    $envFile = getcwd() . '/.env';
}
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

$options = getopt('', [
    'token:',
    'email:',
    'new-password:',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo <<<HELP
Enterprise Lightning Framework (ELF) - Change Admin Password
=============================================================

Changes the password for an admin user.
The master token IS the ultimate authority - no current password needed.

Usage:
  php elf/password-change.php --token=TOKEN --email=EMAIL --new-password=NEW

Required:
  --token=TOKEN           Master CLI token (generated during install)
  --email=EMAIL           Admin email address
  --new-password=NEW      New password (minimum 8 characters)

Options:
  --json                  Output as JSON
  --help                  Show this help

Security:
  - Master token = full authority, no current password required
  - All active sessions are invalidated after password change
  - Master CLI token remains unchanged

HELP;
    exit(0);
}

// Validate required parameters
$requiredParams = ['token', 'email', 'new-password'];
$missing = [];
foreach ($requiredParams as $param) {
    if (empty($options[$param])) {
        $missing[] = "--{$param}";
    }
}

if (!empty($missing)) {
    echo "ERROR: Missing required parameters: " . implode(', ', $missing) . "\n";
    echo "Run with --help for usage information.\n";
    exit(1);
}

$token = $options['token'];
$email = $options['email'];
$newPassword = $options['new-password'];
$jsonOutput = isset($options['json']);

// Validate new password (simple: just minimum length)
if (strlen($newPassword) < 8) {
    $error = 'Password must be at least 8 characters';
    if ($jsonOutput) {
        echo json_encode(['success' => false, 'error' => $error], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "ERROR: {$error}\n";
    }
    exit(1);
}

// Database connection
$driver = $_ENV['DB_DRIVER'] ?? 'pgsql';
$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? ($driver === 'mysql' ? '3306' : '5432');
$database = $_ENV['DB_DATABASE'] ?? 'admin_panel';
$dbUsername = $_ENV['DB_USERNAME'] ?? 'admin';
$dbPassword = $_ENV['DB_PASSWORD'] ?? null;
if ($dbPassword === null) {
    $error = 'DB_PASSWORD environment variable is required. Set it in your .env file.';
    if ($jsonOutput) {
        echo json_encode(['success' => false, 'error' => $error], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "ERROR: {$error}\n";
    }
    exit(1);
}

$dsn = match ($driver) {
    'pgsql', 'postgresql' => "pgsql:host={$host};port={$port};dbname={$database}",
    'mysql' => "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
    default => throw new InvalidArgumentException("Unsupported driver: {$driver}"),
};

try {
    $pdo = new PDO($dsn, $dbUsername, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    $error = "Database connection failed: {$e->getMessage()}";
    if ($jsonOutput) {
        echo json_encode(['success' => false, 'error' => $error], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "ERROR: {$error}\n";
    }
    exit(1);
}

// ============================================================================
// Verify user: email + master token (master token = ultimate authority)
// ============================================================================

$stmt = $pdo->prepare('SELECT id, cli_token_hash, is_master, is_active FROM admin_users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    $error = "User not found: {$email}";
    if ($jsonOutput) {
        echo json_encode(['success' => false, 'error' => $error], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "ERROR: {$error}\n";
    }
    exit(1);
}

if (!$user['is_active']) {
    $error = "User account is deactivated";
    if ($jsonOutput) {
        echo json_encode(['success' => false, 'error' => $error], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "ERROR: {$error}\n";
    }
    exit(1);
}

// Verify master token - THIS IS THE ULTIMATE AUTHORITY
if (empty($user['cli_token_hash'])) {
    $error = "No master token set for this user. Run install.php first.";
    if ($jsonOutput) {
        echo json_encode(['success' => false, 'error' => $error], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "ERROR: {$error}\n";
    }
    exit(1);
}

if (!password_verify($token, $user['cli_token_hash'])) {
    $error = "Invalid master token";
    if ($jsonOutput) {
        echo json_encode(['success' => false, 'error' => $error], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "ERROR: {$error}\n";
    }
    exit(1);
}

if (!$jsonOutput) {
    echo "  [OK] Master token verified - full authority granted\n\n";
}

// ============================================================================
// Update password
// ============================================================================

// Hash new password with Argon2id
$newPasswordHash = password_hash($newPassword, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,
    'time_cost' => 4,
    'threads' => 3,
]);

// Update in database
$stmt = $pdo->prepare('UPDATE admin_users SET password_hash = ?, updated_at = NOW() WHERE id = ?');
$stmt->execute([$newPasswordHash, $user['id']]);

// Invalidate all sessions for this user
$stmt = $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = ?');
$stmt->execute([$user['id']]);

// Audit log
$stmt = $pdo->prepare("
    INSERT INTO admin_audit_log (user_id, action, metadata, ip_address, created_at)
    VALUES (?, 'password_changed', ?, 'CLI', NOW())
");
$stmt->execute([
    $user['id'],
    json_encode(['email' => $email, 'method' => 'cli']),
]);

// ============================================================================
// Output
// ============================================================================

if ($jsonOutput) {
    echo json_encode([
        'success' => true,
        'message' => 'Password changed successfully',
        'sessions_invalidated' => true,
        'master_token_unchanged' => true,
    ], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo "╔══════════════════════════════════════════════════════════════════╗\n";
echo "║  PASSWORD CHANGED SUCCESSFULLY                                   ║\n";
echo "╚══════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "  - All active sessions have been invalidated\n";
echo "  - Master CLI token remains unchanged\n";
echo "  - Admin URL remains unchanged\n";
echo "\n";
echo "You can now login with your new password.\n\n";
