<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Controllers;

use AdosLabs\AdminPanel\Http\Response;
use AdosLabs\AdminPanel\Services\LogConfigService;

/**
 * Logger Controller
 *
 * Manages PSR-3 Logger configuration through the admin panel:
 * - Channel CRUD (create, read, update, delete)
 * - Log level configuration per channel
 * - Telegram notification settings
 * - Log viewer with filters
 * - Database log management
 *
 * @package adoslabs/enterprise-admin-panel
 */
class LoggerController extends BaseController
{
    private ?LogConfigService $logConfigService = null;

    /**
     * Get LogConfigService instance
     */
    private function getLogConfigService(): LogConfigService
    {
        if ($this->logConfigService === null) {
            $this->logConfigService = LogConfigService::getInstance($this->db);
        }

        return $this->logConfigService;
    }

    /**
     * Logger dashboard - overview of channels and stats
     *
     * GET /admin/logger
     */
    public function index(): Response
    {
        $service = $this->getLogConfigService();
        $channels = $service->getAllChannels();

        // Get log statistics
        $stats = $this->getLogStats();

        // Get recent logs
        $recentLogs = $this->getRecentLogs(10);

        // Get available log files
        $logFiles = $this->getLogFiles();

        return $this->view('logger/index', [
            'channels' => $channels,
            'stats' => $stats,
            'recent_logs' => $recentLogs,
            'log_files' => $logFiles,
            'telegram_config' => $service->getTelegramConfig(),
            'log_levels' => LogConfigService::getLevelNames(),
        ]);
    }

    /**
     * Channels management page
     *
     * GET /admin/logger/channels
     */
    public function channels(): Response
    {
        $service = $this->getLogConfigService();

        // Get channels with extended info
        $channels = $this->getChannelsWithStats();

        return $this->view('logger/channels', [
            'channels' => $channels,
            'log_levels' => LogConfigService::getLevelNames(),
        ]);
    }

    /**
     * Update channel configuration
     *
     * POST /admin/logger/channels/update
     *
     * Supports both full form updates and AJAX partial updates:
     * - Full form: channel, min_level, enabled, description
     * - AJAX partial: channel + one of (min_level | enabled | auto_reset_enabled)
     */
    public function updateChannel(): Response
    {
        $body = $this->getBody();
        $channel = trim($body['channel'] ?? '');

        if (empty($channel)) {
            if ($this->isAjaxRequest()) {
                return $this->json(['success' => false, 'error' => 'Channel name required']);
            }
            return $this->withFlash('error', 'Channel name required',
                $this->adminUrl('logger/channels'));
        }

        $service = $this->getLogConfigService();
        $oldConfig = $service->getChannelConfig($channel);

        // AJAX partial update (single field)
        if ($this->isAjaxRequest()) {
            $updated = false;
            $updateType = '';

            if (isset($body['min_level'])) {
                $level = $body['min_level'];
                if (!in_array($level, LogConfigService::getLevelNames(), true)) {
                    return $this->json(['success' => false, 'error' => 'Invalid log level']);
                }
                $updated = $service->updateChannelLevel($channel, $level);
                $updateType = 'level';
            } elseif (array_key_exists('enabled', $body)) {
                $enabled = $body['enabled'] === '1' || $body['enabled'] === 'true' || $body['enabled'] === true;
                $updated = $service->updateChannelEnabled($channel, $enabled);
                $updateType = 'enabled';
            } elseif (array_key_exists('auto_reset_enabled', $body)) {
                $autoReset = $body['auto_reset_enabled'] === '1' || $body['auto_reset_enabled'] === 'true' || $body['auto_reset_enabled'] === true;
                $updated = $service->updateChannelAutoReset($channel, $autoReset);
                $updateType = 'auto_reset';
            } else {
                return $this->json(['success' => false, 'error' => 'No update field provided']);
            }

            if ($updated) {
                $this->audit("logger.channel.update.{$updateType}", ['channel' => $channel]);
                return $this->json(['success' => true, 'message' => 'Channel updated']);
            }

            return $this->json(['success' => false, 'error' => 'Failed to update channel']);
        }

        // Full form update
        $validation = $this->validate([
            'min_level' => 'required|in:debug,info,notice,warning,error,critical,alert,emergency',
        ]);

        if (!$validation['valid']) {
            return $this->withFlash('error', 'Invalid data: ' . implode(', ', $validation['errors']),
                $this->adminUrl('logger/channels'));
        }

        $minLevel = $body['min_level'];
        $enabled = isset($body['enabled']);
        $description = $body['description'] ?? null;

        if ($service->upsertChannel($channel, $minLevel, $enabled, false, $description)) {
            $this->auditEntityChange(
                'logger.channel.update',
                'log_channel',
                0, // No numeric ID for channels
                ['channel' => $channel, 'old' => $oldConfig],
                ['channel' => $channel, 'min_level' => $minLevel, 'enabled' => $enabled]
            );

            return $this->withFlash('success', "Channel '{$channel}' updated successfully",
                $this->adminUrl('logger/channels'));
        }

        return $this->withFlash('error', 'Failed to update channel',
            $this->adminUrl('logger/channels'));
    }

    /**
     * Check if request is AJAX
     */
    private function isAjaxRequest(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Delete channel configuration
     *
     * POST /admin/logger/channels/delete
     */
    public function deleteChannel(): Response
    {
        $body = $this->getBody();
        $channel = trim($body['channel'] ?? '');

        if (empty($channel)) {
            return $this->withFlash('error', 'Channel name required',
                $this->adminUrl('logger/channels'));
        }

        // Prevent deletion of default channels
        $protectedChannels = ['default', 'security', 'audit'];
        if (in_array($channel, $protectedChannels, true)) {
            return $this->withFlash('error', "Cannot delete protected channel: {$channel}",
                $this->adminUrl('logger/channels'));
        }

        $service = $this->getLogConfigService();

        if ($service->deleteChannel($channel)) {
            $this->audit('logger.channel.delete', ['channel' => $channel]);

            return $this->withFlash('success', "Channel '{$channel}' deleted",
                $this->adminUrl('logger/channels'));
        }

        return $this->withFlash('error', 'Failed to delete channel',
            $this->adminUrl('logger/channels'));
    }

    /**
     * Telegram configuration page
     *
     * GET /admin/logger/telegram
     */
    public function telegram(): Response
    {
        $service = $this->getLogConfigService();

        return $this->view('logger/telegram', [
            'config' => $service->getTelegramConfig(),
            'channels' => $service->getAllChannels(),
            'log_levels' => LogConfigService::getLevelNames(),
        ]);
    }

    /**
     * Save Telegram configuration
     *
     * POST /admin/logger/telegram/save
     */
    public function saveTelegram(): Response
    {
        $body = $this->getBody();

        $config = [
            'enabled' => isset($body['enabled']),
            'bot_token' => $body['bot_token'] ?? null,
            'chat_id' => $body['chat_id'] ?? null,
            'min_level' => $body['min_level'] ?? 'error',
            'notify_channels' => $body['notify_channels'] ?? ['*'],
            'rate_limit_per_minute' => (int)($body['rate_limit'] ?? 10),
        ];

        // Validate bot token format if provided
        if (!empty($config['bot_token']) && !preg_match('/^\d+:[A-Za-z0-9_-]+$/', $config['bot_token'])) {
            return $this->withFlash('error', 'Invalid Telegram bot token format',
                $this->adminUrl('logger/telegram'));
        }

        $service = $this->getLogConfigService();

        if ($service->updateTelegramConfig($config)) {
            $this->audit('logger.telegram.update', ['enabled' => $config['enabled']]);

            return $this->withFlash('success', 'Telegram configuration saved',
                $this->adminUrl('logger/telegram'));
        }

        return $this->withFlash('error', 'Failed to save Telegram configuration',
            $this->adminUrl('logger/telegram'));
    }

    /**
     * Test Telegram connection
     *
     * POST /admin/logger/telegram/test
     */
    public function testTelegram(): Response
    {
        $body = $this->getBody();

        $botToken = $body['bot_token'] ?? '';
        $chatId = $body['chat_id'] ?? '';

        if (empty($botToken) || empty($chatId)) {
            return $this->json(['success' => false, 'message' => 'Bot token and chat ID required']);
        }

        // Send test message
        $message = "🧪 Test message from Enterprise Admin Panel\n\n" .
                   "Timestamp: " . date('Y-m-d H:i:s') . "\n" .
                   "Server: " . gethostname();

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return $this->json(['success' => false, 'message' => "Connection error: {$error}"]);
        }

        $result = json_decode($response, true);

        if ($httpCode === 200 && ($result['ok'] ?? false)) {
            $this->audit('logger.telegram.test', ['success' => true]);
            return $this->json(['success' => true, 'message' => 'Test message sent successfully']);
        }

        $errorMsg = $result['description'] ?? 'Unknown error';
        return $this->json(['success' => false, 'message' => "Telegram error: {$errorMsg}"]);
    }

    /**
     * Database logs viewer
     *
     * GET /admin/logger/database
     */
    public function database(): Response
    {
        $query = $this->getQuery();

        $filters = [
            'channel' => $query['channel'] ?? '',
            'level' => $query['level'] ?? '',
            'search' => $query['search'] ?? '',
            'from' => $query['from'] ?? '',
            'to' => $query['to'] ?? '',
        ];

        $page = max(1, (int)($query['page'] ?? 1));
        $perPage = 50;

        // Build query
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['channel'])) {
            $where[] = 'channel = :channel';
            $params['channel'] = $filters['channel'];
        }

        if (!empty($filters['level'])) {
            $where[] = 'level = :level';
            $params['level'] = $filters['level'];
        }

        if (!empty($filters['search'])) {
            $where[] = 'message ILIKE :search';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['from'])) {
            $where[] = 'created_at >= :from';
            $params['from'] = $filters['from'];
        }

        if (!empty($filters['to'])) {
            $where[] = 'created_at <= :to';
            $params['to'] = $filters['to'];
        }

        $whereClause = implode(' AND ', $where);

        // Count total
        try {
            $countSql = "SELECT COUNT(*) as cnt FROM logs WHERE {$whereClause}";
            $countRows = $this->db->query($countSql, $params);
            $total = (int)($countRows[0]['cnt'] ?? 0);
        } catch (\PDOException $e) {
            $total = 0;
        }

        $pages = max(1, (int)ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        // Fetch logs
        try {
            $sql = "SELECT * FROM logs WHERE {$whereClause} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
            $params[':limit'] = $perPage;
            $params[':offset'] = $offset;
            $logs = $this->db->query($sql, $params);

            // Decode JSON context
            foreach ($logs as &$log) {
                $log['context'] = json_decode($log['context'] ?? '{}', true) ?: [];
            }
        } catch (\PDOException $e) {
            $logs = [];
        }

        // Get distinct channels and levels for filters
        $channels = $this->getDistinctValues('channel');
        $levels = LogConfigService::getLevelNames();

        return $this->view('logger/database', [
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'filters' => $filters,
            'channels' => $channels,
            'levels' => $levels,
        ]);
    }

    /**
     * Clear old database logs
     *
     * POST /admin/logger/database/clear
     */
    public function clearDatabaseLogs(): Response
    {
        $body = $this->getBody();
        $olderThan = $body['older_than'] ?? '7 days';

        // Validate period
        $validPeriods = ['1 day', '3 days', '7 days', '14 days', '30 days'];
        if (!in_array($olderThan, $validPeriods, true)) {
            return $this->withFlash('error', 'Invalid time period',
                $this->adminUrl('logger/database'));
        }

        try {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$olderThan}"));

            $deleted = $this->db->execute("DELETE FROM logs WHERE created_at < :cutoff", [':cutoff' => $cutoff]);

            $this->audit('logger.database.clear', ['older_than' => $olderThan, 'deleted' => $deleted]);

            return $this->withFlash('success', "Deleted {$deleted} log entries older than {$olderThan}",
                $this->adminUrl('logger/database'));
        } catch (\PDOException $e) {
            return $this->withFlash('error', 'Failed to clear logs: ' . $e->getMessage(),
                $this->adminUrl('logger/database'));
        }
    }

    /**
     * View log file
     *
     * GET /admin/logger/file?name=filename.log
     */
    public function fileView(): Response
    {
        $query = $this->getQuery();
        $filename = $query['name'] ?? '';

        if (empty($filename)) {
            return $this->withFlash('error', 'File name required',
                $this->adminUrl('logger'));
        }

        $logDir = $this->getLogDir();
        $filepath = $logDir . '/' . basename($filename);

        if (!file_exists($filepath)) {
            return $this->withFlash('error', 'Log file not found: ' . basename($filename),
                $this->adminUrl('logger'));
        }

        // Read last 500 lines
        $content = $this->tailFile($filepath, 500);
        $totalLines = $this->countFileLines($filepath);

        return $this->view('logger/file', [
            'file_name' => basename($filepath),
            'content' => $content,
            'lines' => 500,
            'file_size' => $this->formatBytes(filesize($filepath)),
            'file_modified' => date('Y-m-d H:i:s', filemtime($filepath)),
        ]);
    }

    /**
     * Download log file
     *
     * GET /admin/logger/file/download?name=filename.log
     */
    public function fileDownload(): Response
    {
        $query = $this->getQuery();
        $filename = $query['name'] ?? '';

        if (empty($filename)) {
            return $this->error('File name required', 400);
        }

        $logDir = $this->getLogDir();
        $filepath = $logDir . '/' . basename($filename);

        if (!file_exists($filepath)) {
            return $this->error('Log file not found', 404);
        }

        $this->audit('logger.file.download', ['file' => basename($filepath)]);

        return Response::download($filepath, basename($filepath));
    }

    /**
     * Delete log file(s)
     *
     * POST /admin/logger/file/delete
     */
    public function fileDelete(): Response
    {
        $body = $this->getBody();
        $files = $body['files'] ?? [];

        if (empty($files)) {
            return $this->json(['success' => false, 'error' => 'No files specified']);
        }

        $logDir = $this->getLogDir();
        $realLogDir = realpath($logDir);
        $deleted = [];
        $failed = [];

        foreach ($files as $filename) {
            $filepath = realpath($logDir . '/' . basename($filename));

            // Security check: ensure file is within log directory
            if ($filepath === false || ($realLogDir && !str_starts_with($filepath, $realLogDir))) {
                $failed[] = $filename;
                continue;
            }

            if (file_exists($filepath) && unlink($filepath)) {
                $deleted[] = basename($filepath);
            } else {
                $failed[] = $filename;
            }
        }

        if (!empty($deleted)) {
            $this->audit('logger.file.delete', ['files' => $deleted]);
        }

        if (!empty($failed)) {
            return $this->json([
                'success' => false,
                'error' => 'Failed to delete: ' . implode(', ', $failed),
                'deleted' => $deleted
            ]);
        }

        return $this->json(['success' => true, 'deleted' => $deleted]);
    }

    /**
     * PHP errors log viewer
     *
     * GET /admin/logger/php-errors
     */
    public function phpErrors(): Response
    {
        $errorLog = ini_get('error_log');

        if (empty($errorLog) || !file_exists($errorLog)) {
            return $this->view('logger/php-errors', [
                'exists' => false,
                'content' => '',
                'filepath' => $errorLog ?: 'Not configured',
            ]);
        }

        $content = $this->tailFile($errorLog, 1000);

        return $this->view('logger/php-errors', [
            'exists' => true,
            'content' => $content,
            'file_size' => filesize($errorLog),
            'modified' => filemtime($errorLog),
            'filepath' => $errorLog,
        ]);
    }

    /**
     * Clear PHP errors log
     *
     * POST /admin/logger/php-errors/clear
     */
    public function clearPhpErrors(): Response
    {
        $errorLog = ini_get('error_log');

        if (empty($errorLog) || !file_exists($errorLog)) {
            return $this->withFlash('error', 'PHP error log not found',
                $this->adminUrl('logger/php-errors'));
        }

        if (@file_put_contents($errorLog, '') === false) {
            return $this->withFlash('error', 'Failed to clear PHP error log (permission denied)',
                $this->adminUrl('logger/php-errors'));
        }

        $this->audit('logger.php-errors.clear');

        return $this->withFlash('success', 'PHP error log cleared',
            $this->adminUrl('logger/php-errors'));
    }

    /**
     * API: Get log entries (for AJAX)
     *
     * GET /admin/logger/api/logs
     */
    public function apiLogs(): Response
    {
        $query = $this->getQuery();

        $channel = $query['channel'] ?? null;
        $level = $query['level'] ?? null;
        $limit = min(100, max(1, (int)($query['limit'] ?? 20)));
        $offset = max(0, (int)($query['offset'] ?? 0));

        $where = ['1=1'];
        $params = [];

        if ($channel) {
            $where[] = 'channel = :channel';
            $params['channel'] = $channel;
        }

        if ($level) {
            $where[] = 'level = :level';
            $params['level'] = $level;
        }

        $whereClause = implode(' AND ', $where);

        try {
            $sql = "SELECT * FROM logs WHERE {$whereClause} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;
            $logs = $this->db->query($sql, $params);

            foreach ($logs as &$log) {
                $log['context'] = json_decode($log['context'] ?? '{}', true);
            }

            return $this->success($logs);
        } catch (\PDOException $e) {
            return $this->error('Failed to fetch logs');
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Get log statistics
     */
    private function getLogStats(): array
    {
        try {
            // Total logs today
            $rows = $this->db->query(
                "SELECT COUNT(*) as cnt FROM logs WHERE created_at >= CURRENT_DATE"
            );
            $todayCount = (int)($rows[0]['cnt'] ?? 0);

            // Errors today (error, critical, alert, emergency)
            $rows = $this->db->query(
                "SELECT COUNT(*) as cnt FROM logs
                 WHERE created_at >= CURRENT_DATE
                 AND level IN ('error', 'critical', 'alert', 'emergency')"
            );
            $errorsToday = (int)($rows[0]['cnt'] ?? 0);

            // Total logs
            $rows = $this->db->query("SELECT COUNT(*) as cnt FROM logs");
            $totalCount = (int)($rows[0]['cnt'] ?? 0);

            // Logs by level
            $rows = $this->db->query(
                "SELECT level, COUNT(*) as count FROM logs GROUP BY level ORDER BY count DESC"
            );
            $byLevel = [];
            foreach ($rows as $row) {
                $byLevel[$row['level']] = (int)$row['count'];
            }

            return [
                'today' => $todayCount,
                'errors_today' => $errorsToday,
                'total' => $totalCount,
                'by_level' => $byLevel,
            ];
        } catch (\PDOException $e) {
            return [
                'today' => 0,
                'errors_today' => 0,
                'total' => 0,
                'by_level' => [],
            ];
        }
    }

    /**
     * Get recent logs
     */
    private function getRecentLogs(int $limit): array
    {
        try {
            $logs = $this->db->query(
                "SELECT * FROM logs ORDER BY created_at DESC LIMIT :limit",
                [':limit' => $limit]
            );

            foreach ($logs as &$log) {
                $log['context'] = json_decode($log['context'] ?? '{}', true);
            }

            return $logs;
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Get channels with statistics
     */
    private function getChannelsWithStats(): array
    {
        $service = $this->getLogConfigService();
        $channels = $service->getAllChannels();

        // Get log counts per channel
        try {
            $rows = $this->db->query(
                "SELECT channel, COUNT(*) as count, MAX(created_at) as last_log
                 FROM logs GROUP BY channel"
            );
            $stats = [];
            foreach ($rows as $row) {
                $stats[$row['channel']] = [
                    'log_count' => (int)$row['count'],
                    'last_log' => $row['last_log'],
                ];
            }
        } catch (\PDOException $e) {
            $stats = [];
        }

        // Merge stats into channels
        $result = [];
        foreach ($channels as $name => $config) {
            $result[] = array_merge($config, [
                'channel' => $name,
                'log_count' => $stats[$name]['log_count'] ?? 0,
                'last_log' => $stats[$name]['last_log'] ?? null,
            ]);
        }

        return $result;
    }

    /**
     * Get log files in configured directory
     */
    private function getLogFiles(): array
    {
        $logDir = $this->getLogDir();

        if (!is_dir($logDir)) {
            return [];
        }

        $files = [];
        foreach (glob($logDir . '/*.log') as $file) {
            $files[] = [
                'name' => basename($file),
                'size' => filesize($file),
                'modified' => filemtime($file),
            ];
        }

        // Sort by modified date (newest first)
        usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);

        return array_slice($files, 0, 20);
    }

    /**
     * Get log directory path
     */
    private function getLogDir(): string
    {
        // Check env first
        $logDir = getenv('LOG_DIR');
        if ($logDir && is_dir($logDir)) {
            return $logDir;
        }

        // Check storage/logs relative to project root
        $projectRoot = defined('EAP_PROJECT_ROOT') ? EAP_PROJECT_ROOT : dirname($_SERVER['DOCUMENT_ROOT']);
        $storageLogs = $projectRoot . '/storage/logs';
        if (is_dir($storageLogs)) {
            return $storageLogs;
        }

        // Fallback
        return '/var/log/app';
    }

    /**
     * Get distinct values from logs table
     */
    private function getDistinctValues(string $column): array
    {
        try {
            $rows = $this->db->query("SELECT DISTINCT {$column} FROM logs ORDER BY {$column}");
            return array_column($rows, $column);
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Read last N lines of a file
     */
    private function tailFile(string $filepath, int $lines): string
    {
        if (!file_exists($filepath)) {
            return '';
        }

        $file = new \SplFileObject($filepath, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        $start = max(0, $totalLines - $lines);
        $file->seek($start);

        $content = '';
        while (!$file->eof()) {
            $content .= $file->fgets();
        }

        return $content;
    }

    /**
     * Count lines in a file
     */
    private function countFileLines(string $filepath): int
    {
        if (!file_exists($filepath)) {
            return 0;
        }

        $file = new \SplFileObject($filepath, 'r');
        $file->seek(PHP_INT_MAX);

        return $file->key() + 1;
    }
}
