#!/usr/bin/env php
<?php
/**
 * Enterprise Lightning Framework (ELF) - Regenerate Master Token
 *
 * Regenerates the master CLI token for the admin user.
 * Requires current token + email + password for authentication.
 *
 * Usage:
 *   php elf/token-master-regenerate.php --token=CURRENT_TOKEN --email=admin@example.com --password=PASSWORD
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
    'password:',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo <<<HELP
Enterprise Lightning Framework (ELF) - Regenerate Master Token
===============================================================

Regenerates the master CLI token.
All three authentication factors are required: current token + email + password.

Usage:
  php elf/token-master-regenerate.php --token=CURRENT_TOKEN --email=EMAIL --password=PASSWORD

Required:
  --token=TOKEN           Current master CLI token
  --email=EMAIL           Admin email address
  --password=PASSWORD     Admin password

Options:
  --json                  Output as JSON
  --help                  Show this help

Security:
  - Requires all three: current master token + email + password
  - The new token is shown ONCE - save it securely
  - Old token is immediately invalidated

HELP;
    exit(0);
}

// Validate required parameters
$requiredParams = ['token', 'email', 'password'];
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
$password = $options['password'];
$jsonOutput = isset($options['json']);

// Database connection
$driver = $_ENV['DB_DRIVER'] ?? 'pgsql';
$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? ($driver === 'mysql' ? '3306' : '5432');
$database = $_ENV['DB_DATABASE'] ?? 'admin_panel';
$dbUsername = $_ENV['DB_USERNAME'] ?? 'admin';
$dbPassword = $_ENV['DB_PASSWORD'] ?? 'secret';

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
// Verify user: email + password + token (all three required)
// ============================================================================

$stmt = $pdo->prepare('SELECT id, password_hash, cli_token_hash, is_master, is_active FROM admin_users WHERE email = ?');
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

if (!$user['is_master']) {
    $error = "Only master admin can regenerate master token";
    if ($jsonOutput) {
        echo json_encode(['success' => false, 'error' => $error], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "ERROR: {$error}\n";
    }
    exit(1);
}

// Verify password
if (!password_verify($password, $user['password_hash'])) {
    $error = "Invalid password";
    if ($jsonOutput) {
        echo json_encode(['success' => false, 'error' => $error], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "ERROR: {$error}\n";
    }
    exit(1);
}

// Verify current master token
if (empty($user['cli_token_hash'])) {
    $error = "No master token set for this user";
    if ($jsonOutput) {
        echo json_encode(['success' => false, 'error' => $error], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "ERROR: {$error}\n";
    }
    exit(1);
}

if (!password_verify($token, $user['cli_token_hash'])) {
    $error = "Invalid current master token";
    if ($jsonOutput) {
        echo json_encode(['success' => false, 'error' => $error], JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "ERROR: {$error}\n";
    }
    exit(1);
}

if (!$jsonOutput) {
    echo "  [OK] Authentication verified (token + email + password)\n\n";
}

// ============================================================================
// Generate new master token
// ============================================================================

// Format: master-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX (128 bits)
$newPlainToken = generate_master_token();

// Hash with Argon2id for storage
$newTokenHash = password_hash($newPlainToken, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,
    'time_cost' => 4,
    'threads' => 3,
]);

// Update in database
$stmt = $pdo->prepare('UPDATE admin_users SET cli_token_hash = ?, cli_token_generated_at = NOW(), cli_token_generation_count = cli_token_generation_count + 1 WHERE id = ?');
$stmt->execute([$newTokenHash, $user['id']]);

// Audit log
$stmt = $pdo->prepare("
    INSERT INTO admin_audit_log (user_id, action, metadata, ip_address, created_at)
    VALUES (?, 'master_token_regenerated', ?, 'CLI', NOW())
");
$stmt->execute([
    $user['id'],
    json_encode(['email' => $email]),
]);

// ============================================================================
// Output
// ============================================================================

if ($jsonOutput) {
    echo json_encode([
        'success' => true,
        'token' => $newPlainToken,
        'warning' => 'SAVE THIS TOKEN! It will NOT be shown again.',
    ], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║  NEW MASTER TOKEN GENERATED - SAVE IT NOW!                                  ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "┌────────────────────────────────────────────────────────────────────────────────┐\n";
echo "│  YOUR NEW MASTER TOKEN (save in password manager):                            │\n";
echo "├────────────────────────────────────────────────────────────────────────────────┤\n";
echo "│                                                                               │\n";
printf("│  %-77s│\n", $newPlainToken);
echo "│                                                                               │\n";
echo "└────────────────────────────────────────────────────────────────────────────────┘\n";
echo "\n";
echo "  - Old token has been invalidated\n";
echo "  - This token will NOT be shown again\n";
echo "\n";
