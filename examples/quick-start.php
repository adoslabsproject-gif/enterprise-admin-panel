<?php

/**
 * ENTERPRISE ADMIN PANEL - Quick Start Example
 *
 * This example shows complete setup of the admin panel with:
 * - Database connection
 * - URL generation
 * - Module discovery
 * - 2FA setup
 *
 * PREREQUISITES:
 * - PostgreSQL database created
 * - Migrations run: php vendor/bin/admin-panel install
 * - Admin user created
 *
 * @version 1.0.0
 * @since 2026-01-24
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AdosLabs\AdminPanel\Core\CryptographicAdminUrlGenerator;
use AdosLabs\AdminPanel\Core\ModuleRegistry;
use Psr\Log\NullLogger;

// ============================================================================
// CONFIGURATION
// ============================================================================

$config = [
    'database' => [
        'host' => $_ENV['DB_HOST'] ?? 'localhost',
        'port' => $_ENV['DB_PORT'] ?? 5432,
        'database' => $_ENV['DB_NAME'] ?? 'your_app',
        'username' => $_ENV['DB_USER'] ?? 'postgres',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
    ],
    'app' => [
        'secret' => $_ENV['APP_SECRET'] ?? 'CHANGE_THIS_TO_RANDOM_64_CHARS',
        'url' => $_ENV['APP_URL'] ?? 'https://example.com',
    ],
];

// ============================================================================
// STEP 1: Database Connection
// ============================================================================

try {
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s',
        $config['database']['host'],
        $config['database']['port'],
        $config['database']['database']
    );

    $pdo = new PDO(
        $dsn,
        $config['database']['username'],
        $config['database']['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "✅ Database connected successfully\n\n";

} catch (PDOException $e) {
    die("❌ Database connection failed: " . $e->getMessage() . "\n");
}

// ============================================================================
// STEP 2: Generate Secure Admin URL
// ============================================================================

echo "🔐 STEP 2: Generating Cryptographic Admin URL\n";
echo str_repeat('=', 80) . "\n";

// Assume admin user ID = 1 (adjust as needed)
$adminUserId = 1;

try {
    // Generate standard URL (4-hour expiry)
    $adminUrl = CryptographicAdminUrlGenerator::generate(
        userId: $adminUserId,
        pdo: $pdo,
        secret: $config['app']['secret'],
        bindToIp: false, // Set true for max security (IP-bound)
        logger: new NullLogger()
    );

    echo "✅ Admin URL generated successfully\n";
    echo "   Full URL: {$config['app']['url']}{$adminUrl}\n";
    echo "   Pattern: " . explode('/', $adminUrl)[1] . "\n";
    echo "   Token length: " . strlen(explode('/', $adminUrl)[2] ?? explode('-', $adminUrl)[1]) . " chars (256-bit)\n";
    echo "   Expires: 4 hours from now\n";
    echo "   User binding: User ID {$adminUserId}\n\n";

} catch (Exception $e) {
    die("❌ URL generation failed: " . $e->getMessage() . "\n");
}

// ============================================================================
// STEP 3: Discover Modules
// ============================================================================

echo "🧩 STEP 3: Auto-Discovering Modules\n";
echo str_repeat('=', 80) . "\n";

$registry = new ModuleRegistry($pdo, new NullLogger());

// Discover modules from composer packages
$discoveredCount = $registry->discoverModules();

echo "✅ Discovered {$discoveredCount} module(s)\n\n";

// Get enabled modules
$enabledModules = $registry->getEnabledModules();

if (empty($enabledModules)) {
    echo "⚠️  No modules enabled yet\n";
    echo "   Install packages like:\n";
    echo "   - adoslabs/enterprise-security-shield\n";
    echo "   - adoslabs/enterprise-psr3-logger\n\n";
} else {
    echo "Enabled modules:\n";
    foreach ($enabledModules as $name => $module) {
        echo "   • {$module->getName()} (v{$module->getVersion()})\n";
        echo "     {$module->getDescription()}\n";
    }
    echo "\n";
}

// ============================================================================
// STEP 4: Get Admin Sidebar Tabs
// ============================================================================

echo "📊 STEP 4: Admin Sidebar Tabs\n";
echo str_repeat('=', 80) . "\n";

$tabs = $registry->getTabs();

if (empty($tabs)) {
    echo "⚠️  No tabs registered yet (no modules enabled)\n\n";
} else {
    echo "Available tabs:\n";
    foreach ($tabs as $tab) {
        $badge = isset($tab['badge']) ? " [{$tab['badge']}]" : "";
        echo "   • {$tab['label']}{$badge}\n";
        echo "     URL: {$tab['url']}\n";
        echo "     Icon: {$tab['icon']}\n";
        echo "     Priority: {$tab['priority']}\n";
    }
    echo "\n";
}

// ============================================================================
// STEP 5: Validate Admin URL (Simulate Request)
// ============================================================================

echo "🔍 STEP 5: Validating Admin URL\n";
echo str_repeat('=', 80) . "\n";

// Simulate validation (as if user accessed the URL)
$isValid = CryptographicAdminUrlGenerator::validate(
    url: $adminUrl,
    userId: $adminUserId,
    pdo: $pdo,
    secret: $config['app']['secret'],
    logger: new NullLogger()
);

if ($isValid) {
    echo "✅ Admin URL is VALID\n";
    echo "   Access granted for User ID {$adminUserId}\n\n";
} else {
    echo "❌ Admin URL is INVALID\n";
    echo "   Possible reasons:\n";
    echo "   - URL expired (>4 hours)\n";
    echo "   - Wrong user ID\n";
    echo "   - URL revoked\n";
    echo "   - IP mismatch (if IP-bound)\n\n";
}

// ============================================================================
// STEP 6: Generate Emergency URL
// ============================================================================

echo "🚨 STEP 6: Generating Emergency Access URL\n";
echo str_repeat('=', 80) . "\n";

try {
    $emergencyUrl = CryptographicAdminUrlGenerator::generateEmergency(
        userId: $adminUserId,
        pdo: $pdo,
        secret: $config['app']['secret'],
        logger: new NullLogger()
    );

    echo "✅ Emergency URL generated\n";
    echo "   Full URL: {$config['app']['url']}{$emergencyUrl}\n";
    echo "   Expires: 1 hour from now\n";
    echo "   Max uses: 1 (one-time access)\n";
    echo "   ⚠️  IMPORTANT: Store this URL securely offline!\n\n";

} catch (Exception $e) {
    echo "❌ Emergency URL generation failed: " . $e->getMessage() . "\n\n";
}

// ============================================================================
// STEP 7: Admin Panel Stats
// ============================================================================

echo "📈 STEP 7: Admin Panel Statistics\n";
echo str_repeat('=', 80) . "\n";

try {
    // Count active URLs
    $stmt = $pdo->query("
        SELECT COUNT(*) as count
        FROM admin_url_whitelist
        WHERE expires_at > NOW()
          AND revoked = false
    ");
    $activeUrls = $stmt->fetch()['count'] ?? 0;

    // Count total admin users
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM admin_users");
    $totalAdmins = $stmt->fetch()['count'] ?? 0;

    // Count enabled modules
    $stmt = $pdo->query("
        SELECT COUNT(*) as count
        FROM admin_modules
        WHERE enabled = true
    ");
    $enabledModulesCount = $stmt->fetch()['count'] ?? 0;

    echo "   • Active admin URLs: {$activeUrls}\n";
    echo "   • Total admin users: {$totalAdmins}\n";
    echo "   • Enabled modules: {$enabledModulesCount}\n\n";

} catch (PDOException $e) {
    echo "⚠️  Could not fetch stats (tables may not exist yet)\n";
    echo "   Run migrations: php vendor/bin/admin-panel install\n\n";
}

// ============================================================================
// DONE
// ============================================================================

echo str_repeat('=', 80) . "\n";
echo "✅ QUICK START COMPLETE!\n\n";

echo "Next steps:\n";
echo "1. Visit your admin URL: {$config['app']['url']}{$adminUrl}\n";
echo "2. Log in with your admin credentials\n";
echo "3. Set up 2FA (email or Telegram)\n";
echo "4. Install modules (security-shield, psr3-logger)\n";
echo "5. Configure module settings\n\n";

echo "Security reminders:\n";
echo "• URL rotates every 4 hours automatically\n";
echo "• Keep your APP_SECRET secure (64+ random chars)\n";
echo "• Enable 2FA for all admin users\n";
echo "• Monitor admin_audit_log table regularly\n";
echo "• Use IP binding for max security (if static IP available)\n\n";

echo "Need help? Check README.md or examples/\n";
