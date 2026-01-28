#!/usr/bin/env php
<?php
/**
 * Enterprise Admin Panel - Rotate Admin URL
 *
 * Generates a new cryptographic admin URL. The old URL becomes invalid immediately.
 * Requires triple authentication.
 *
 * Usage:
 *   php elf/url-rotate.php --token=MASTER_TOKEN --email=EMAIL --password=PASSWORD
 *
 * SECURITY WARNING:
 *   - In production, pass credentials via environment variables, NOT command line
 *   - Example: EAP_TOKEN=xxx EAP_EMAIL=xxx EAP_PASSWORD=xxx php elf/url-rotate.php
 *
 * @package AdosLabs\AdminPanel
 */

declare(strict_types=1);

// Find project root
$packageRoot = dirname(__DIR__);
$projectRoot = null;

$searchDir = getcwd();
for ($i = 0; $i < 10; $i++) {
    if (file_exists($searchDir . '/composer.json')) {
        $composerJson = json_decode(file_get_contents($searchDir . '/composer.json'), true);
        if (($composerJson['name'] ?? '') !== 'ados-labs/enterprise-admin-panel') {
            $projectRoot = $searchDir;
            break;
        }
    }
    $parent = dirname($searchDir);
    if ($parent === $searchDir) break;
    $searchDir = $parent;
}

if ($projectRoot === null) {
    $projectRoot = $packageRoot;
}

// Autoload
$autoloadPaths = [
    $projectRoot . '/vendor/autoload.php',
    $packageRoot . '/vendor/autoload.php',
];

foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

use AdosLabs\AdminPanel\Bootstrap;
use AdosLabs\AdminPanel\Services\ConfigService;
use AdosLabs\AdminPanel\Services\AuditService;

// Parse arguments - support both CLI args and environment variables
$options = getopt('', ['token:', 'email:', 'password:', 'json', 'help', 'reason:']);

if (isset($options['help'])) {
    echo <<<HELP
Enterprise Admin Panel - Rotate Admin URL
==========================================

Generates a new cryptographic admin URL. The old URL becomes INVALID immediately.
All existing bookmarks will stop working.

Usage:
  php elf/url-rotate.php --token=TOKEN --email=EMAIL --password=PASSWORD [--reason=REASON] [--json]

  Or via environment variables (RECOMMENDED for production):
  EAP_TOKEN=xxx EAP_EMAIL=xxx EAP_PASSWORD=xxx php elf/url-rotate.php

Options:
  --token=TOKEN     Master CLI token (from installation)
  --email=EMAIL     Admin email address
  --password=PASS   Admin password
  --reason=REASON   Reason for rotation (logged for audit)
  --json            Output in JSON format
  --help            Show this help

Environment Variables (takes precedence over CLI args):
  EAP_TOKEN         Master CLI token
  EAP_EMAIL         Admin email
  EAP_PASSWORD      Admin password

Example:
  # Development (OK to use CLI args)
  php elf/url-rotate.php --token=master-abc123 --email=admin@example.com --password=MyPass123!

  # Production (use environment variables - NO hardcoded credentials!)
  export EAP_TOKEN="master-abc123"
  export EAP_EMAIL="admin@example.com"
  export EAP_PASSWORD="MySecretPass"
  php elf/url-rotate.php --reason="Monthly rotation"

Security:
  - All three credentials are required
  - Old URL becomes invalid IMMEDIATELY
  - URL rotation is audit logged
  - Notification sent to admins (if configured)

WARNING: In production, NEVER hardcode credentials in scripts or command line.
         Use environment variables or a secrets manager.

HELP;
    exit(0);
}

// Get credentials (environment variables take precedence)
$token = getenv('EAP_TOKEN') ?: ($options['token'] ?? null);
$email = getenv('EAP_EMAIL') ?: ($options['email'] ?? null);
$password = getenv('EAP_PASSWORD') ?: ($options['password'] ?? null);
$reason = $options['reason'] ?? 'Manual rotation via CLI';
$jsonOutput = isset($options['json']);

if (!$token || !$email || !$password) {
    echo "Error: Missing required credentials.\n\n";
    echo "Usage: php elf/url-rotate.php --token=TOKEN --email=EMAIL --password=PASSWORD\n";
    echo "   Or: EAP_TOKEN=xxx EAP_EMAIL=xxx EAP_PASSWORD=xxx php elf/url-rotate.php\n";
    echo "\nRun with --help for more information.\n";
    exit(1);
}

// Security warning for CLI args
if (isset($options['password'])) {
    echo "\n";
    echo "WARNING: Passing password via command line is insecure.\n";
    echo "         In production, use environment variables instead:\n";
    echo "         EAP_PASSWORD=xxx php elf/url-rotate.php\n";
    echo "\n";
}

// Initialize Bootstrap
Bootstrap::init($projectRoot);

$dbPool = db();
$configService = new ConfigService($dbPool);
$auditService = new AuditService($dbPool);

// Find user
$stmt = $dbPool->query(
    'SELECT id, email, password_hash, cli_token_hash, is_master, is_active FROM admin_users WHERE email = ?',
    [$email]
);
$users = iterator_to_array($stmt);
$user = $users[0] ?? null;

if (!$user) {
    $auditService->log('cli_url_rotate_failed', null, [
        'reason' => 'user_not_found',
        'email' => $email,
    ]);
    echo "Error: Invalid credentials.\n";
    exit(1);
}

if (!$user['is_active']) {
    $auditService->log('cli_url_rotate_failed', $user['id'], [
        'reason' => 'account_inactive',
    ]);
    echo "Error: Account is inactive.\n";
    exit(1);
}

if (!$user['is_master']) {
    $auditService->log('cli_url_rotate_failed', $user['id'], [
        'reason' => 'not_master_admin',
    ]);
    echo "Error: Only master admin can rotate the URL.\n";
    exit(1);
}

// Verify password
if (!password_verify($password, $user['password_hash'])) {
    $auditService->log('cli_url_rotate_failed', $user['id'], [
        'reason' => 'invalid_password',
    ]);
    echo "Error: Invalid credentials.\n";
    exit(1);
}

// Verify master token
if (!$user['cli_token_hash'] || !password_verify($token, $user['cli_token_hash'])) {
    $auditService->log('cli_url_rotate_failed', $user['id'], [
        'reason' => 'invalid_token',
    ]);
    echo "Error: Invalid master token.\n";
    exit(1);
}

// Get old URL for logging
$oldBasePath = $configService->getAdminBasePath();

// ROTATE the URL
$newBasePath = $configService->rotateAdminBasePath();
$fullUrl = "http://localhost:8080{$newBasePath}/login";

// Audit log
$auditService->log('cli_url_rotated', $user['id'], [
    'old_path_prefix' => substr($oldBasePath, 0, 10) . '...',
    'new_path_prefix' => substr($newBasePath, 0, 10) . '...',
    'reason' => $reason,
    'ip' => 'CLI',
]);

// Strategic log
log_warning('security', 'Admin URL rotated via CLI', [
    'user_id' => $user['id'],
    'reason' => $reason,
]);

if ($jsonOutput) {
    echo json_encode([
        'success' => true,
        'url' => $fullUrl,
        'base_path' => $newBasePath,
        'old_path_prefix' => substr($oldBasePath, 0, 10) . '...',
        'reason' => $reason,
    ], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\n";
    echo "==============================================================================\n";
    echo "  URL ROTATED SUCCESSFULLY!\n";
    echo "==============================================================================\n";
    echo "\n";
    echo "  OLD URL: INVALIDATED (prefix: " . substr($oldBasePath, 0, 15) . "...)\n";
    echo "\n";
    echo "  NEW ADMIN URL:\n";
    echo "  {$fullUrl}\n";
    echo "\n";
    echo "  Reason: {$reason}\n";
    echo "\n";
    echo "  IMPORTANT:\n";
    echo "  - The old URL no longer works\n";
    echo "  - Update your bookmarks\n";
    echo "  - All active sessions remain valid\n";
    echo "\n";
}
