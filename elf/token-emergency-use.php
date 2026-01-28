#!/usr/bin/env php
<?php
/**
 * Enterprise Lightning Framework (ELF) - Use Emergency Token (CLI)
 *
 * Uses emergency token to get the admin URL and create a login session.
 * NO password or 2FA required - direct access.
 *
 * For browser access, use: /emergency-login?token=YOUR_TOKEN
 *
 * Usage:
 *   php elf/token-emergency-use.php --token=emergency-xxxxxxxx-xxxxxxxx-...
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

use AdosLabs\AdminPanel\Services\EncryptionService;

$options = getopt('', [
    'token:',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo <<<HELP
Enterprise Lightning Framework (ELF) - Use Emergency Token
===========================================================

Uses emergency token to access the admin panel.
NO password or 2FA required.

Usage:
  php elf/token-emergency-use.php --token=TOKEN

Options:
  --token=TOKEN    Emergency token (required)
  --json           Output as JSON
  --help           Show this help

This will:
  1. Verify the emergency token
  2. Show the admin URL (decrypted)
  3. Invalidate the token (ONE-TIME USE)

For browser access, use:
  http://localhost:8080/emergency-login?token=YOUR_TOKEN

HELP;
    exit(0);
}

if (empty($options['token'])) {
    echo "ERROR: --token is required\n";
    exit(1);
}

$token = $options['token'];
$jsonOutput = isset($options['json']);

// Database connection
$driver = $_ENV['DB_DRIVER'] ?? 'pgsql';
$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? ($driver === 'mysql' ? '3306' : '5432');
$database = $_ENV['DB_DATABASE'] ?? 'admin_panel';
$dbUsername = $_ENV['DB_USERNAME'] ?? 'admin';
$dbPassword = $_ENV['DB_PASSWORD'] ?? null;
if ($dbPassword === null) {
    echo "ERROR: DB_PASSWORD environment variable is required.\n";
    echo "Set it in your .env file or export it: export DB_PASSWORD=your_password\n";
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
    echo "ERROR: Database connection failed: {$e->getMessage()}\n";
    exit(1);
}

// ============================================================================
// Step 1: Find and verify emergency token (checking all active tokens)
// ============================================================================

$stmt = $pdo->query('
    SELECT et.id, et.user_id, et.token_hash, et.name, et.expires_at, et.is_used,
           u.email, u.name as user_name, u.is_master
    FROM admin_emergency_tokens et
    JOIN admin_users u ON et.user_id = u.id
    WHERE et.is_used = false
      AND (et.expires_at IS NULL OR et.expires_at > NOW())
');

$matchedToken = null;
while ($row = $stmt->fetch()) {
    if (password_verify($token, $row['token_hash'])) {
        $matchedToken = $row;
        break;
    }
}

if ($matchedToken === null) {
    echo "ERROR: Invalid, expired, or already used emergency token\n";
    exit(1);
}

if (!$matchedToken['is_master']) {
    echo "ERROR: Emergency tokens are only valid for master admin accounts\n";
    exit(1);
}

// ============================================================================
// Step 2: Get encrypted admin URL and decrypt it
// ============================================================================

$appKey = $_ENV['APP_KEY'] ?? getenv('APP_KEY');
if (empty($appKey)) {
    echo "ERROR: APP_KEY not found in environment\n";
    exit(1);
}

$encryption = new EncryptionService($appKey);

$stmt = $pdo->query("SELECT config_value FROM admin_config WHERE config_key = 'admin_base_path'");
$encryptedUrl = $stmt->fetchColumn();

if (!$encryptedUrl) {
    echo "ERROR: Admin URL not configured\n";
    exit(1);
}

$adminUrl = $encryption->decrypt($encryptedUrl);
if ($adminUrl === null) {
    echo "ERROR: Failed to decrypt admin URL. APP_KEY may be incorrect.\n";
    exit(1);
}

// ============================================================================
// Step 3: Mark token as used (ONE-TIME USE)
// ============================================================================

$stmt = $pdo->prepare('
    UPDATE admin_emergency_tokens
    SET is_used = true, used_at = NOW(), used_from_ip = ?
    WHERE id = ?
');
$stmt->execute(['CLI', $matchedToken['id']]);

// Audit log
$stmt = $pdo->prepare("
    INSERT INTO admin_audit_log (user_id, action, metadata, ip_address, created_at)
    VALUES (?, 'emergency_token_used', ?, 'CLI', NOW())
");
$stmt->execute([
    $matchedToken['user_id'],
    json_encode([
        'token_id' => $matchedToken['id'],
        'token_name' => $matchedToken['name'],
        'method' => 'cli',
    ]),
]);

// ============================================================================
// Output
// ============================================================================

$fullUrl = "http://localhost:8080{$adminUrl}/login";
$dashboardUrl = "http://localhost:8080{$adminUrl}/dashboard";

if ($jsonOutput) {
    echo json_encode([
        'success' => true,
        'admin_url' => $fullUrl,
        'dashboard_url' => $dashboardUrl,
        'admin_base_path' => $adminUrl,
        'user' => [
            'email' => $matchedToken['email'],
            'name' => $matchedToken['user_name'],
        ],
        'token_invalidated' => true,
        'message' => 'Token has been used and is now INVALID. Open the URL in browser to login.',
    ], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║  EMERGENCY ACCESS GRANTED                                                    ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "Token '{$matchedToken['name']}' has been USED and is now INVALID.\n\n";

echo "┌────────────────────────────────────────────────────────────────────────────────┐\n";
echo "│  YOUR ADMIN PANEL URL:                                                        │\n";
echo "├────────────────────────────────────────────────────────────────────────────────┤\n";
echo "│                                                                               │\n";
printf("│  %-77s│\n", $fullUrl);
echo "│                                                                               │\n";
echo "└────────────────────────────────────────────────────────────────────────────────┘\n";
echo "\n";
echo "User: {$matchedToken['user_name']} ({$matchedToken['email']})\n\n";

echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║  NEXT STEPS                                                                  ║\n";
echo "╠══════════════════════════════════════════════════════════════════════════════╣\n";
echo "║  1. Open the URL above in your browser                                       ║\n";
echo "║  2. Login with your email and password                                       ║\n";
echo "║  3. Generate a new emergency token from the dashboard                        ║\n";
echo "║  4. SAVE THE URL in a password manager!                                      ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
