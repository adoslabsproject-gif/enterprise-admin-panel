#!/usr/bin/env php
<?php
/**
 * Enterprise Lightning Framework (ELF) - Generate Master Token
 *
 * Creates the master CLI token for the admin user.
 * This token is required to create emergency tokens and sub-admin tokens.
 *
 * Usage:
 *   php elf/token-master-generate.php --email=admin@example.com
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
    'email:',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo <<<HELP
Enterprise Lightning Framework (ELF) - Generate Master Token
=============================================================

Creates the master CLI token for admin operations.
This token is required to create emergency tokens and sub-admin tokens.

Usage:
  php elf/token-master-generate.php --email=EMAIL

Required:
  --email=EMAIL    Master admin email

Options:
  --json           Output as JSON
  --help           Show this help

Security:
  - You will be prompted for your password
  - Token is shown ONCE - save it securely
  - Token is hashed (Argon2id) in database

HELP;
    exit(0);
}

if (empty($options['email'])) {
    echo "ERROR: --email is required\n";
    exit(1);
}

$email = $options['email'];
$jsonOutput = isset($options['json']);

// Prompt for password
echo "Enter your password: ";
system('stty -echo 2>/dev/null');
$password = trim(fgets(STDIN));
system('stty echo 2>/dev/null');
echo "\n";

if (empty($password)) {
    echo "ERROR: Password is required\n";
    exit(1);
}

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
    echo "ERROR: Database connection failed: {$e->getMessage()}\n";
    exit(1);
}

// ============================================================================
// Verify user and password
// ============================================================================

$stmt = $pdo->prepare('SELECT id, password_hash, is_master FROM admin_users WHERE email = ? AND is_active = true');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo "ERROR: User not found: {$email}\n";
    exit(1);
}

if (!$user['is_master']) {
    echo "ERROR: Only master admin can generate master tokens\n";
    exit(1);
}

if (!password_verify($password, $user['password_hash'])) {
    echo "ERROR: Invalid password\n";
    exit(1);
}

echo "  [OK] Authentication verified\n\n";

// ============================================================================
// Generate master token
// ============================================================================

// Format: master-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX (128 bits)
$plainToken = 'master-' . implode('-', str_split(bin2hex(random_bytes(16)), 8));

// Hash with Argon2id for storage
$tokenHash = password_hash($plainToken, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,
    'time_cost' => 4,
    'threads' => 3,
]);

// Store in database
$stmt = $pdo->prepare('UPDATE admin_users SET cli_token_hash = ? WHERE id = ?');
$stmt->execute([$tokenHash, $user['id']]);

// Audit log
$stmt = $pdo->prepare("
    INSERT INTO admin_audit_log (user_id, action, metadata, ip_address, created_at)
    VALUES (?, 'master_token_generated', ?, 'CLI', NOW())
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
        'token' => $plainToken,
        'warning' => 'SAVE THIS TOKEN! It will NOT be shown again.',
    ], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║  MASTER TOKEN GENERATED - SAVE IT NOW!                                      ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "┌────────────────────────────────────────────────────────────────────────────────┐\n";
echo "│  YOUR MASTER TOKEN (save in password manager):                                │\n";
echo "├────────────────────────────────────────────────────────────────────────────────┤\n";
echo "│                                                                               │\n";
printf("│  %-77s│\n", $plainToken);
echo "│                                                                               │\n";
echo "└────────────────────────────────────────────────────────────────────────────────┘\n";
echo "\n";
echo "This token is required to:\n";
echo "  - Create emergency recovery tokens\n";
echo "  - Create sub-admin tokens\n";
echo "  - Perform sensitive CLI operations\n\n";
echo "[!!] This token will NOT be shown again!\n\n";
