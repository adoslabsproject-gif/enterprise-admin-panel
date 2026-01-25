#!/usr/bin/env php
<?php
/**
 * Enterprise Lightning Framework (ELF) - Create Emergency Login Token
 *
 * Creates a one-time emergency token that bypasses login (including 2FA).
 *
 * REQUIRES: master_token + email + password
 * GENERATES: emergency token (ONE-TIME USE, hashed in DB)
 *
 * The emergency token allows direct dashboard access without password/2FA.
 * STORE OFFLINE (printed, in a safe).
 *
 * Usage:
 *   php elf/token-emergency-create.php --master-token=TOKEN --email=admin@example.com
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
    'master-token:',
    'email:',
    'name:',
    'expires:',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo <<<HELP
Enterprise Lightning Framework (ELF) - Create Emergency Login Token
=====================================================================

Creates a one-time emergency token for direct dashboard access.
Bypasses password and 2FA verification.

Usage:
  php elf/token-emergency-create.php --master-token=TOKEN --email=EMAIL [options]

Required:
  --master-token=TOKEN   Your master CLI token
  --email=EMAIL          Master admin email

Options:
  --name=NAME            Token name/description (default: "Emergency Login Token")
  --expires=DAYS         Expires in N days (default: 30)
  --json                 Output as JSON
  --help                 Show this help

Security:
  - You will be prompted for your password (not passed via command line)
  - Token is ONE-TIME USE - invalidated after login
  - Token is HASHED in database (Argon2id)
  - STORE OFFLINE: Print and put in a safe

HELP;
    exit(0);
}

if (empty($options['master-token'])) {
    echo "ERROR: --master-token is required\n";
    exit(1);
}

if (empty($options['email'])) {
    echo "ERROR: --email is required\n";
    exit(1);
}

$masterToken = $options['master-token'];
$email = $options['email'];
$tokenName = $options['name'] ?? 'Emergency Login Token';
$expiresDays = isset($options['expires']) ? (int) $options['expires'] : 30;
$jsonOutput = isset($options['json']);

// Prompt for password (hidden input)
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
// Step 1: Verify master token
// ============================================================================

$stmt = $pdo->prepare('SELECT id, email, cli_token_hash, is_master FROM admin_users WHERE email = ? AND is_active = true');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo "ERROR: User not found: {$email}\n";
    exit(1);
}

if (!$user['is_master']) {
    echo "ERROR: Only master admin can create emergency tokens\n";
    exit(1);
}

if (empty($user['cli_token_hash'])) {
    echo "ERROR: No master token configured. Generate one first:\n";
    echo "  php elf/token-master-generate.php --email={$email}\n";
    exit(1);
}

// Verify master token (it's hashed with Argon2id)
if (!password_verify($masterToken, $user['cli_token_hash'])) {
    echo "ERROR: Invalid master token\n";
    exit(1);
}

// ============================================================================
// Step 2: Verify password
// ============================================================================

$stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
$stmt->execute([$user['id']]);
$passwordHash = $stmt->fetchColumn();

if (!password_verify($password, $passwordHash)) {
    echo "ERROR: Invalid password\n";
    exit(1);
}

echo "  [OK] Authentication verified\n\n";

// ============================================================================
// Step 3: Generate emergency token
// ============================================================================

// Format: emergency-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX (128 bits)
$plainToken = 'emergency-' . implode('-', str_split(bin2hex(random_bytes(16)), 8));

// Hash with Argon2id for storage
$tokenHash = password_hash($plainToken, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,
    'time_cost' => 4,
    'threads' => 3,
]);

$expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresDays} days"));

// Store in database
$stmt = $pdo->prepare('
    INSERT INTO admin_emergency_tokens (user_id, token_hash, name, expires_at, created_at)
    VALUES (?, ?, ?, ?, NOW())
');
$stmt->execute([$user['id'], $tokenHash, $tokenName, $expiresAt]);
$tokenId = $pdo->lastInsertId();

// Audit log
$stmt = $pdo->prepare("
    INSERT INTO admin_audit_log (user_id, action, metadata, ip_address, created_at)
    VALUES (?, 'emergency_token_created', ?, 'CLI', NOW())
");
$stmt->execute([
    $user['id'],
    json_encode(['token_id' => $tokenId, 'name' => $tokenName, 'expires_at' => $expiresAt]),
]);

// ============================================================================
// Output
// ============================================================================

if ($jsonOutput) {
    echo json_encode([
        'success' => true,
        'token' => $plainToken,
        'name' => $tokenName,
        'expires_at' => $expiresAt,
        'usage' => 'Access: http://localhost:8080/emergency-login?token=' . urlencode($plainToken),
        'warning' => 'ONE-TIME USE. PRINT AND STORE OFFLINE!',
    ], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║  EMERGENCY LOGIN TOKEN CREATED - PRINT AND STORE OFFLINE!                   ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "Name: {$tokenName}\n";
echo "Expires: {$expiresAt}\n\n";

echo "┌────────────────────────────────────────────────────────────────────────────────┐\n";
echo "│  EMERGENCY TOKEN (print and store in safe):                                   │\n";
echo "├────────────────────────────────────────────────────────────────────────────────┤\n";
echo "│                                                                               │\n";
printf("│  %-77s│\n", $plainToken);
echo "│                                                                               │\n";
echo "└────────────────────────────────────────────────────────────────────────────────┘\n";
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║  HOW TO USE THIS TOKEN                                                       ║\n";
echo "╠══════════════════════════════════════════════════════════════════════════════╣\n";
echo "║  1. Open browser and go to:                                                  ║\n";
echo "║     http://localhost:8080/emergency-login?token=YOUR_TOKEN                   ║\n";
echo "║                                                                              ║\n";
echo "║  2. Or use CLI:                                                              ║\n";
echo "║     php elf/token-emergency-use.php --token=YOUR_TOKEN                       ║\n";
echo "║                                                                              ║\n";
echo "║  IMPORTANT:                                                                  ║\n";
echo "║  - Token is ONE-TIME USE - becomes invalid after login                       ║\n";
echo "║  - Bypasses password AND 2FA                                                 ║\n";
echo "║  - Takes you directly to dashboard                                           ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
