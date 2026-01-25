<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Services;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use AdosLabs\AdminPanel\Database\Pool\PooledConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use DateTimeImmutable;

/**
 * Enterprise Audit Service
 *
 * Features:
 * - Complete action audit trail
 * - Entity change tracking (diff)
 * - Client metadata (IP, user-agent)
 * - Query and filtering
 * - Analytics and reporting
 *
 * @version 1.0.0
 */
final class AuditService
{
    /**
     * Standard audit actions
     */
    public const ACTION_LOGIN = 'login';
    public const ACTION_LOGOUT = 'logout';
    public const ACTION_LOGIN_FAILED = 'login_failed';
    public const ACTION_PASSWORD_CHANGE = 'password_change';
    public const ACTION_2FA_ENABLE = '2fa_enable';
    public const ACTION_2FA_DISABLE = '2fa_disable';
    public const ACTION_ACCOUNT_LOCK = 'account_lock';
    public const ACTION_ACCOUNT_UNLOCK = 'account_unlock';
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_VIEW = 'view';
    public const ACTION_EXPORT = 'export';

    public function __construct(
        private DatabasePool $db,
        private ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Log an audit event
     *
     * @param string $action Action performed
     * @param int|null $userId User who performed the action (null for system)
     * @param array $metadata Additional context
     * @param string|null $ipAddress Client IP
     * @param string|null $userAgent Client user agent
     * @return int Audit log ID
     */
    public function log(
        string $action,
        ?int $userId,
        array $metadata = [],
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): int {
        // Use transaction to get lastInsertId
        $connection = $this->db->beginTransaction();

        try {
            $pdo = $connection->getPdo();
            $stmt = $pdo->prepare(
                'INSERT INTO admin_audit_log (user_id, action, metadata, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $action,
                !empty($metadata) ? json_encode($metadata) : null,
                $ipAddress,
                $userAgent,
            ]);

            $id = (int) $pdo->lastInsertId();
            $this->db->commit($connection);

            $this->logger->debug('Audit event logged', [
                'id' => $id,
                'action' => $action,
                'user_id' => $userId,
            ]);

            return $id;
        } catch (\Throwable $e) {
            $this->db->rollback($connection);
            throw $e;
        }
    }

    /**
     * Log entity change with old/new values diff
     *
     * @param string $action Action (create, update, delete)
     * @param int|null $userId User who performed the action
     * @param string $entityType Entity type (e.g., 'user', 'module')
     * @param int $entityId Entity ID
     * @param array|null $oldValues Old values (for update/delete)
     * @param array|null $newValues New values (for create/update)
     * @param string|null $ipAddress Client IP
     * @param string|null $userAgent Client user agent
     * @return int Audit log ID
     */
    public function logEntityChange(
        string $action,
        ?int $userId,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): int {
        // Remove sensitive fields from logging
        $sensitiveFields = ['password_hash', 'two_factor_secret', 'two_factor_recovery_codes'];

        if ($oldValues !== null) {
            $oldValues = array_diff_key($oldValues, array_flip($sensitiveFields));
        }

        if ($newValues !== null) {
            $newValues = array_diff_key($newValues, array_flip($sensitiveFields));
        }

        // Use transaction to get lastInsertId
        $connection = $this->db->beginTransaction();

        try {
            $pdo = $connection->getPdo();
            $stmt = $pdo->prepare(
                'INSERT INTO admin_audit_log (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $action,
                $entityType,
                $entityId,
                $oldValues !== null ? json_encode($oldValues) : null,
                $newValues !== null ? json_encode($newValues) : null,
                $ipAddress,
                $userAgent,
            ]);

            $id = (int) $pdo->lastInsertId();
            $this->db->commit($connection);

            $this->logger->debug('Entity change logged', [
                'id' => $id,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);

            return $id;
        } catch (\Throwable $e) {
            $this->db->rollback($connection);
            throw $e;
        }
    }

    /**
     * Query audit logs with filtering
     *
     * @param array{
     *     user_id?: int,
     *     action?: string|array<string>,
     *     entity_type?: string,
     *     entity_id?: int,
     *     ip_address?: string,
     *     from?: DateTimeImmutable,
     *     to?: DateTimeImmutable,
     *     limit?: int,
     *     offset?: int
     * } $filters
     * @return array<array>
     */
    public function query(array $filters = []): array
    {
        $conditions = [];
        $params = [];

        if (isset($filters['user_id'])) {
            $conditions[] = 'a.user_id = ?';
            $params[] = $filters['user_id'];
        }

        if (isset($filters['action'])) {
            if (is_array($filters['action'])) {
                $placeholders = implode(',', array_fill(0, count($filters['action']), '?'));
                $conditions[] = "a.action IN ({$placeholders})";
                $params = array_merge($params, $filters['action']);
            } else {
                $conditions[] = 'a.action = ?';
                $params[] = $filters['action'];
            }
        }

        if (isset($filters['entity_type'])) {
            $conditions[] = 'a.entity_type = ?';
            $params[] = $filters['entity_type'];
        }

        if (isset($filters['entity_id'])) {
            $conditions[] = 'a.entity_id = ?';
            $params[] = $filters['entity_id'];
        }

        if (isset($filters['ip_address'])) {
            $conditions[] = 'a.ip_address = ?';
            $params[] = $filters['ip_address'];
        }

        if (isset($filters['from'])) {
            $conditions[] = 'a.created_at >= ?';
            $params[] = $filters['from']->format('Y-m-d H:i:s');
        }

        if (isset($filters['to'])) {
            $conditions[] = 'a.created_at <= ?';
            $params[] = $filters['to']->format('Y-m-d H:i:s');
        }

        $whereClause = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $limit = $filters['limit'] ?? 100;
        $offset = $filters['offset'] ?? 0;

        $sql = "SELECT a.*, u.email as user_email, u.name as user_name FROM admin_audit_log a LEFT JOIN admin_users u ON a.user_id = u.id {$whereClause} ORDER BY a.created_at DESC LIMIT ? OFFSET ?";

        $params[] = $limit;
        $params[] = $offset;

        $results = $this->db->query($sql, $params);

        // Decode JSON fields
        foreach ($results as &$row) {
            $row['metadata'] = $row['metadata'] ? json_decode($row['metadata'], true) : null;
            $row['old_values'] = $row['old_values'] ? json_decode($row['old_values'], true) : null;
            $row['new_values'] = $row['new_values'] ? json_decode($row['new_values'], true) : null;
        }

        return $results;
    }

    /**
     * Get audit log entry by ID
     */
    public function getById(int $id): ?array
    {
        $rows = $this->db->query(
            'SELECT a.*, u.email as user_email, u.name as user_name FROM admin_audit_log a LEFT JOIN admin_users u ON a.user_id = u.id WHERE a.id = ?',
            [$id]
        );

        if (empty($rows)) {
            return null;
        }

        $row = $rows[0];
        $row['metadata'] = $row['metadata'] ? json_decode($row['metadata'], true) : null;
        $row['old_values'] = $row['old_values'] ? json_decode($row['old_values'], true) : null;
        $row['new_values'] = $row['new_values'] ? json_decode($row['new_values'], true) : null;

        return $row;
    }

    /**
     * Get entity history
     *
     * @param string $entityType Entity type
     * @param int $entityId Entity ID
     * @return array<array>
     */
    public function getEntityHistory(string $entityType, int $entityId): array
    {
        return $this->query([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'limit' => 1000,
        ]);
    }

    /**
     * Get user activity
     *
     * @param int $userId User ID
     * @param int $limit Max results
     * @return array<array>
     */
    public function getUserActivity(int $userId, int $limit = 100): array
    {
        return $this->query([
            'user_id' => $userId,
            'limit' => $limit,
        ]);
    }

    /**
     * Get security events (login, logout, failed login, etc.)
     *
     * @param int $limit Max results
     * @return array<array>
     */
    public function getSecurityEvents(int $limit = 100): array
    {
        return $this->query([
            'action' => [
                self::ACTION_LOGIN,
                self::ACTION_LOGOUT,
                self::ACTION_LOGIN_FAILED,
                self::ACTION_PASSWORD_CHANGE,
                self::ACTION_2FA_ENABLE,
                self::ACTION_2FA_DISABLE,
                self::ACTION_ACCOUNT_LOCK,
                self::ACTION_ACCOUNT_UNLOCK,
            ],
            'limit' => $limit,
        ]);
    }

    /**
     * Get statistics for dashboard
     *
     * @return array{
     *     total_events: int,
     *     today_events: int,
     *     logins_today: int,
     *     failed_logins_today: int,
     *     unique_users_today: int
     * }
     */
    public function getStats(): array
    {
        $today = (new DateTimeImmutable())->format('Y-m-d');

        $rows = $this->db->query(
            "SELECT COUNT(*) as total_events, SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) as today_events, SUM(CASE WHEN DATE(created_at) = ? AND action = 'login' THEN 1 ELSE 0 END) as logins_today, SUM(CASE WHEN DATE(created_at) = ? AND action = 'login_failed' THEN 1 ELSE 0 END) as failed_logins_today FROM admin_audit_log",
            [$today, $today, $today]
        );
        $result = $rows[0] ?? [];

        // Unique users today
        $uniqueRows = $this->db->query(
            'SELECT COUNT(DISTINCT user_id) as cnt FROM admin_audit_log WHERE DATE(created_at) = ? AND user_id IS NOT NULL',
            [$today]
        );
        $uniqueUsers = $uniqueRows[0]['cnt'] ?? 0;

        return [
            'total_events' => (int) ($result['total_events'] ?? 0),
            'today_events' => (int) ($result['today_events'] ?? 0),
            'logins_today' => (int) ($result['logins_today'] ?? 0),
            'failed_logins_today' => (int) ($result['failed_logins_today'] ?? 0),
            'unique_users_today' => (int) $uniqueUsers,
        ];
    }

    /**
     * Get action count by type for charts
     *
     * @param int $days Number of days to look back
     * @return array<string, int>
     */
    public function getActionCounts(int $days = 7): array
    {
        $fromDate = (new DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d H:i:s');

        $rows = $this->db->query(
            'SELECT action, COUNT(*) as count FROM admin_audit_log WHERE created_at >= ? GROUP BY action ORDER BY count DESC',
            [$fromDate]
        );

        $results = [];
        foreach ($rows as $row) {
            $results[$row['action']] = (int) $row['count'];
        }

        return $results;
    }

    /**
     * Cleanup old audit logs
     *
     * @param int $retentionDays Keep logs for this many days
     * @return int Number of logs deleted
     */
    public function cleanup(int $retentionDays = 365): int
    {
        $cutoffDate = (new DateTimeImmutable())->modify("-{$retentionDays} days")->format('Y-m-d H:i:s');

        $count = $this->db->execute(
            'DELETE FROM admin_audit_log WHERE created_at < ?',
            [$cutoffDate]
        );

        if ($count > 0) {
            $this->logger->info('Audit logs cleaned up', [
                'count' => $count,
                'retention_days' => $retentionDays,
            ]);
        }

        return $count;
    }
}
