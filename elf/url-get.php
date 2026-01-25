#!/usr/bin/env php
<?php
/**
 * Enterprise Admin Panel - Get Admin URL
 *
 * Retrieves the secret admin URL. Requires triple authentication.
 *
 * Usage:
 *   php elf/url-get.php --token=MASTER_TOKEN --email=EMAIL --password=PASSWORD
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

// Parse arguments
$options = getopt('', ['token:', 'email:', 'password:', 'json', 'help']);

if (isset($options['help'])) {
    echo <<<HELP
Enterprise Admin Panel - Get Admin URL
======================================

Retrieves the secret admin URL. Requires triple authentication for security.

Usage:
  php elf/url-get.php --token=TOKEN --email=EMAIL --password=PASSWORD [--json]

Options:
  --token=TOKEN     Master CLI token (from installation)
  --email=EMAIL     Admin email address
  --password=PASS   Admin password
  --json            Output in JSON format
  --help            Show this help

Example:
  php elf/url-get.php --token=master-abc123 --email=admin@example.com --password=MyPass123!

Security:
  - All three credentials are required
  - Failed attempts are logged
  - Use this command sparingly (audit logged)

HELP;
    exit(0);
}

// Validate required arguments
$token = $options['token'] ?? null;
$email = $options['email'] ?? null;
$password = $options['password'] ?? null;
$jsonOutput = isset($options['json']);

if (!$token || !$email || !$password) {
    echo "Error: Missing required arguments.\n\n";
    echo "Usage: php elf/url-get.php --token=TOKEN --email=EMAIL --password=PASSWORD\n";
    echo "Run with --help for more information.\n";
    exit(1);
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
    $auditService->log('cli_url_get_failed', null, [
        'reason' => 'user_not_found',
        'email' => $email,
    ]);
    echo "Error: Invalid credentials.\n";
    exit(1);
}

if (!$user['is_active']) {
    $auditService->log('cli_url_get_failed', $user['id'], [
        'reason' => 'account_inactive',
    ]);
    echo "Error: Account is inactive.\n";
    exit(1);
}

if (!$user['is_master']) {
    $auditService->log('cli_url_get_failed', $user['id'], [
        'reason' => 'not_master_admin',
    ]);
    echo "Error: Only master admin can retrieve the URL.\n";
    exit(1);
}

// Verify password
if (!password_verify($password, $user['password_hash'])) {
    $auditService->log('cli_url_get_failed', $user['id'], [
        'reason' => 'invalid_password',
    ]);
    echo "Error: Invalid credentials.\n";
    exit(1);
}

// Verify master token
if (!$user['cli_token_hash'] || !password_verify($token, $user['cli_token_hash'])) {
    $auditService->log('cli_url_get_failed', $user['id'], [
        'reason' => 'invalid_token',
    ]);
    echo "Error: Invalid master token.\n";
    exit(1);
}

// Get the admin URL
$adminBasePath = $configService->getAdminBasePath();
$fullUrl = "http://localhost:8080{$adminBasePath}/login";

// Audit log
$auditService->log('cli_url_get_success', $user['id'], [
    'ip' => 'CLI',
]);

if ($jsonOutput) {
    echo json_encode([
        'success' => true,
        'url' => $fullUrl,
        'base_path' => $adminBasePath,
    ], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║  ADMIN PANEL URL (SECRET - DO NOT SHARE!)                                   ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "  {$fullUrl}\n";
    echo "\n";
}
