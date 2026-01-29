<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Controllers;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use Psr\Log\LoggerInterface;
use AdosLabs\AdminPanel\Http\Response;
use AdosLabs\AdminPanel\Services\AuditService;
use AdosLabs\AdminPanel\Services\SessionService;
use AdosLabs\AdminPanel\Core\ModuleRegistry;

/**
 * Dashboard Controller
 *
 * Main admin dashboard with:
 * - System overview statistics
 * - Module status
 * - Recent activity
 * - Quick actions
 *
 * @version 1.0.0
 */
final class DashboardController extends BaseController
{
    public function __construct(
        DatabasePool $db,
        SessionService $sessionService,
        AuditService $auditService,
        ModuleRegistry $moduleRegistry,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($db, $sessionService, $auditService, $logger, $moduleRegistry);
    }

    /**
     * Main dashboard
     * GET /admin/dashboard
     */
    public function index(): Response
    {
        // Get statistics
        $stats = $this->getStats();

        // Get recent activity
        $recentActivity = $this->auditService->query(['limit' => 10]);

        // Get enabled modules
        $modules = $this->moduleRegistry->getEnabledModules();
        $moduleInfo = [];

        foreach ($modules as $name => $module) {
            $moduleInfo[] = [
                'name' => $module->getName(),
                'description' => $module->getDescription(),
                'version' => $module->getVersion(),
                'tabs' => count($module->getTabs()),
            ];
        }

        // Get sidebar tabs
        $tabs = $this->moduleRegistry->getTabs();

        return $this->view('dashboard/index', [
            'stats' => $stats,
            'recent_activity' => $recentActivity,
            'modules' => $moduleInfo,
            'tabs' => $tabs,
            'page_title' => 'Dashboard',
            'extra_scripts' => ['/js/dashboard.js'],
        ]);
    }

    /**
     * System statistics API endpoint
     * GET /admin/api/stats
     */
    public function stats(): Response
    {
        return $this->json($this->getStats());
    }

    /**
     * Recent activity API endpoint
     * GET /admin/api/activity
     */
    public function activity(): Response
    {
        $limit = (int) $this->input('limit', 20);
        $offset = (int) $this->input('offset', 0);

        $activity = $this->auditService->query([
            'limit' => min($limit, 100),
            'offset' => $offset,
        ]);

        return $this->json([
            'data' => $activity,
            'total' => count($activity),
        ]);
    }

    /**
     * Module status API endpoint
     * GET /admin/api/modules
     */
    public function modules(): Response
    {
        $modules = $this->moduleRegistry->getEnabledModules();
        $result = [];

        foreach ($modules as $name => $module) {
            $result[] = [
                'name' => $name,
                'display_name' => $module->getName(),
                'description' => $module->getDescription(),
                'version' => $module->getVersion(),
                'tabs' => $module->getTabs(),
                'dependencies' => $module->getDependencies(),
            ];
        }

        return $this->json($result);
    }

    /**
     * Database Pool metrics API endpoint
     * GET /admin/api/dbpool
     */
    public function dbPoolMetrics(): Response
    {
        $stats = $this->db->getStats();

        return $this->json([
            'pool' => $stats['pool'],
            'circuit_breaker' => $stats['circuit_breaker'],
            'metrics' => $stats['metrics'],
            'config' => [
                'driver' => $stats['config']['driver'],
                'host' => $stats['config']['host'],
                'database' => $stats['config']['database'],
                'min_connections' => $stats['config']['min_connections'],
                'max_connections' => $stats['config']['max_connections'],
            ],
        ]);
    }

    /**
     * Redis metrics API endpoint
     * GET /admin/api/redis
     */
    public function redisMetrics(): Response
    {
        $poolStats = $this->db->getStats();
        $redisConnected = $poolStats['redis']['connected'] ?? false;

        $result = [
            'enabled' => $poolStats['config']['redis_enabled'] ?? false,
            'connected' => $redisConnected,
            'worker_count' => $poolStats['redis']['worker_count'] ?? 0,
        ];

        // Get Redis server info if connected
        // SECURITY: Use existing Redis connection from pool - never create direct connections
        // PERFORMANCE: Use DBSIZE instead of KEYS() which blocks Redis
        if ($redisConnected) {
            $redisManager = $this->db->getRedisStateManager();
            if ($redisManager !== null) {
                try {
                    // Get Redis instance from the pool (no credentials exposed)
                    $redis = $redisManager->getRedis();
                    if ($redis !== null) {
                        $info = $redis->info();
                        $result['server'] = [
                            'version' => $info['redis_version'] ?? 'unknown',
                            'uptime_days' => round(($info['uptime_in_seconds'] ?? 0) / 86400, 1),
                            'connected_clients' => (int) ($info['connected_clients'] ?? 0),
                            'used_memory' => $info['used_memory_human'] ?? '0B',
                            'used_memory_peak' => $info['used_memory_peak_human'] ?? '0B',
                            'total_commands' => (int) ($info['total_commands_processed'] ?? 0),
                            'keyspace_hits' => (int) ($info['keyspace_hits'] ?? 0),
                            'keyspace_misses' => (int) ($info['keyspace_misses'] ?? 0),
                        ];

                        // PERFORMANCE: Use DBSIZE instead of KEYS (O(1) vs O(N))
                        // Note: This counts ALL keys, not just EAP keys, but it's non-blocking
                        $result['total_keys'] = $redis->dbSize();

                        // Circuit breaker state (specific key lookup is fine)
                        $prefix = $redisManager->getPrefix();
                        $database = $this->db->getConfig()->getDatabase();
                        $circuitKey = $prefix . 'dbpool:circuit:' . $database;
                        $circuitState = $redis->hGetAll($circuitKey);
                        if (!empty($circuitState)) {
                            $result['circuit_breaker'] = $circuitState;
                        }
                    }
                } catch (\Throwable $e) {
                    // SECURITY: Don't expose connection details in error
                    $result['error'] = 'Failed to retrieve Redis info';
                }
            }
        }

        return $this->json($result);
    }

    /**
     * Get dashboard statistics
     */
    private function getStats(): array
    {
        $auditStats = $this->auditService->getStats();
        $sessionStats = $this->sessionService->getStats();

        // User stats
        $userStats = $this->getUserStats();

        // Module stats
        $moduleCount = count($this->moduleRegistry->getEnabledModules());

        // Database pool stats
        $dbPoolStats = $this->db->getStats();

        return [
            'users' => [
                'total' => $userStats['total'],
                'active' => $userStats['active'],
                'locked' => $userStats['locked'],
            ],
            'sessions' => [
                'active' => $sessionStats['active'],
                'total' => $sessionStats['total'],
            ],
            'audit' => [
                'today_events' => $auditStats['today_events'],
                'logins_today' => $auditStats['logins_today'],
                'failed_logins' => $auditStats['failed_logins_today'],
                'unique_users' => $auditStats['unique_users_today'],
            ],
            'modules' => [
                'enabled' => $moduleCount,
            ],
            'database' => [
                'driver' => $dbPoolStats['config']['driver'],
                'connections' => $dbPoolStats['pool']['total'],
                'idle' => $dbPoolStats['pool']['idle'],
                'in_use' => $dbPoolStats['pool']['in_use'],
                'circuit_state' => $dbPoolStats['circuit_breaker']['state'] ?? 'unknown',
                'queries' => $dbPoolStats['metrics']['local']['total_queries'] ?? 0,
                'avg_query_ms' => round($dbPoolStats['metrics']['local']['avg_query_time_ms'] ?? 0, 2),
            ],
            'redis' => [
                'enabled' => $dbPoolStats['config']['redis_enabled'] ?? false,
                'connected' => $dbPoolStats['redis']['connected'] ?? false,
                'workers' => $dbPoolStats['redis']['worker_count'] ?? 0,
            ],
            'system' => [
                'php_version' => PHP_VERSION,
                'memory_usage' => $this->formatBytes(memory_get_usage(true)),
                'memory_peak' => $this->formatBytes(memory_get_peak_usage(true)),
            ],
        ];
    }

    /**
     * Get user statistics
     */
    private function getUserStats(): array
    {
        $rows = $this->db->query('
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN locked_until IS NOT NULL AND locked_until > NOW() THEN 1 ELSE 0 END) as locked
            FROM admin_users
        ');

        $result = $rows[0] ?? [];

        return [
            'total' => (int) ($result['total'] ?? 0),
            'active' => (int) ($result['active'] ?? 0),
            'locked' => (int) ($result['locked'] ?? 0),
        ];
    }

    /**
     * Format bytes to human readable
     *
     * ENTERPRISE: Extended units up to PB, with explicit loop bound for safety.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $maxIndex = count($units) - 1;
        $i = 0;

        $value = (float) $bytes;

        // Explicit max iterations (5) for safety, plus array bounds check
        while ($value >= 1024.0 && $i < $maxIndex) {
            $value /= 1024.0;
            $i++;
        }

        return round($value, 2) . ' ' . $units[$i];
    }
}
