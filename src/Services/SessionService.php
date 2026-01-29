<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Services;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use DateTimeImmutable;
use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;

/**
 * Enterprise Session Service
 *
 * Features:
 * - Cryptographically secure session IDs (256-bit)
 * - Database-backed sessions
 * - Session payload (CSRF tokens, flash messages)
 * - Automatic expiry
 * - Activity tracking
 * - Multi-device session management
 *
 * @version 1.0.0
 */
final class SessionService
{
    /**
     * Session configuration
     */
    private const SESSION_ID_BYTES = 32; // 256 bits
    private const SESSION_MAX_LIFETIME_MINUTES = 60; // Hard cap: 60 minutes
    private const SESSION_EXTENSION_WINDOW_MINUTES = 5; // Extend if activity in last 5 minutes
    private const SESSION_EXTENSION_AMOUNT_MINUTES = 60; // Extend by 60 minutes when active
    private const CSRF_TOKEN_BYTES = 32;

    public function __construct(
        private DatabasePool $db,
        private ?LoggerInterface $logger = null,
        private int $maxLifetimeMinutes = self::SESSION_MAX_LIFETIME_MINUTES,
        private int $extensionWindowMinutes = self::SESSION_EXTENSION_WINDOW_MINUTES
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Create a new session
     *
     * Session lifecycle:
     * - Max lifetime: 60 minutes from creation
     * - If active in last 5 minutes before expiry, session is extended by 60 minutes
     * - Extension is tracked to prevent infinite session extension
     *
     * @param int $userId User ID
     * @param string $ipAddress Client IP
     * @param string|null $userAgent Client user agent
     * @return string Session ID
     */
    public function create(int $userId, string $ipAddress, ?string $userAgent = null): string
    {
        $sessionId = $this->generateSessionId();
        $csrfToken = $this->generateCsrfToken();

        $now = new DateTimeImmutable();
        $expiresAt = $now->modify("+{$this->maxLifetimeMinutes} minutes");

        $payload = [
            'csrf_token' => $csrfToken,
            'flash' => [],
            'created_at' => $now->format('Y-m-d H:i:s'),
            'extension_count' => 0,
        ];

        $this->db->execute(
            'INSERT INTO admin_sessions (id, user_id, ip_address, user_agent, payload, last_activity, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $sessionId,
                $userId,
                $ipAddress,
                $userAgent,
                json_encode($payload),
                $now->format('Y-m-d H:i:s'),
                $expiresAt->format('Y-m-d H:i:s'),
            ]
        );

        $this->logger->info('Session created', [
            'session_id' => substr($sessionId, 0, 16) . '...',
            'user_id' => $userId,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        // Strategic security log: new session created
        Logger::channel('security')->warning( 'Session created', [
            'session_id_prefix' => substr($sessionId, 0, 16),
            'user_id' => $userId,
            'ip' => $ipAddress,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return $sessionId;
    }

    /**
     * Create temporary session for 2FA verification
     *
     * @param int $userId User ID
     * @param string $ipAddress Client IP
     * @param string|null $userAgent Client user agent
     * @return string Session ID
     */
    public function create2FASession(int $userId, string $ipAddress, ?string $userAgent = null): string
    {
        $sessionId = $this->generateSessionId();

        $now = new DateTimeImmutable();
        $expiresAt = $now->modify('+5 minutes'); // Short-lived

        $payload = [
            'pending_2fa' => true,
            'csrf_token' => $this->generateCsrfToken(),
        ];

        $this->db->execute(
            'INSERT INTO admin_sessions (id, user_id, ip_address, user_agent, payload, last_activity, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $sessionId,
                $userId,
                $ipAddress,
                $userAgent,
                json_encode($payload),
                $now->format('Y-m-d H:i:s'),
                $expiresAt->format('Y-m-d H:i:s'),
            ]
        );

        return $sessionId;
    }

    /**
     * Get session by ID
     *
     * Checks if session is expired and handles extension window logic.
     *
     * @param string $sessionId Session ID
     * @return array|null Session data or null if not found/expired
     */
    public function get(string $sessionId): ?array
    {
        $sessions = $this->db->query(
            'SELECT s.*, u.email, u.name, u.role, u.permissions, u.avatar_url FROM admin_sessions s JOIN admin_users u ON s.user_id = u.id WHERE s.id = ?',
            [$sessionId]
        );

        if (empty($sessions)) {
            return null;
        }

        $session = $sessions[0];

        // Decode payload
        $session['payload'] = json_decode($session['payload'], true) ?? [];

        $now = new DateTimeImmutable();
        $expiresAt = new DateTimeImmutable($session['expires_at']);
        $lastActivity = new DateTimeImmutable($session['last_activity']);

        // Check if session is expired
        if ($now > $expiresAt) {
            // Check if there was activity in the extension window before expiry
            $extensionWindowStart = $expiresAt->modify("-{$this->extensionWindowMinutes} minutes");

            if ($lastActivity >= $extensionWindowStart) {
                // User was active in the last 5 minutes before expiry - extend session
                $extended = $this->extend($sessionId, $session);

                if ($extended) {
                    $this->logger->info('Session extended due to recent activity', [
                        'session_id' => substr($sessionId, 0, 16) . '...',
                    ]);
                }

                // IMPORTANT: Refetch session directly (no recursion) to get updated data
                // Whether we extended or another request did, we need fresh data
                $freshSessions = $this->db->query(
                    'SELECT s.*, u.email, u.name, u.role, u.permissions, u.avatar_url FROM admin_sessions s JOIN admin_users u ON s.user_id = u.id WHERE s.id = ?',
                    [$sessionId]
                );

                if (empty($freshSessions)) {
                    return null;
                }

                $freshSession = $freshSessions[0];
                $freshSession['payload'] = json_decode($freshSession['payload'], true) ?? [];
                return $freshSession;
            } else {
                // Session expired with no recent activity
                $this->destroy($sessionId);
                $this->logger->info('Session expired', [
                    'session_id' => substr($sessionId, 0, 16) . '...',
                    'last_activity' => $lastActivity->format('Y-m-d H:i:s'),
                ]);

                // Strategic log: session expired (monitor for unusual patterns)
                Logger::channel('security')->warning( 'Session expired due to inactivity', [
                    'session_id_prefix' => substr($sessionId, 0, 16),
                    'user_id' => $session['user_id'] ?? null,
                    'last_activity' => $lastActivity->format('Y-m-d H:i:s'),
                ]);

                return null;
            }
        }

        return $session;
    }

    /**
     * Extend session by the configured extension amount
     *
     * RACE CONDITION FIX: Uses conditional UPDATE to prevent double-extension.
     * Only extends if expires_at hasn't changed since we read it (optimistic locking).
     *
     * @param string $sessionId Session ID
     * @param array|null $session Session data (if null, will be fetched)
     * @return bool True if session was extended, false if not found or already extended
     */
    public function extend(string $sessionId, ?array $session = null): bool
    {
        if ($session === null) {
            $session = $this->get($sessionId);
        }

        if ($session === null) {
            return false;
        }

        $now = new DateTimeImmutable();
        $newExpiresAt = $now->modify('+' . self::SESSION_EXTENSION_AMOUNT_MINUTES . ' minutes');

        // Get current expires_at for optimistic locking
        $currentExpiresAt = $session['expires_at'];

        $payload = $session['payload'];
        $payload['extension_count'] = ($payload['extension_count'] ?? 0) + 1;
        $payload['last_extended_at'] = $now->format('Y-m-d H:i:s');

        // RACE CONDITION FIX: Only update if expires_at hasn't changed
        // This prevents double-extension when two requests check simultaneously
        $affectedRows = $this->db->execute(
            'UPDATE admin_sessions SET expires_at = ?, payload = ?, last_activity = ? WHERE id = ? AND expires_at = ?',
            [
                $newExpiresAt->format('Y-m-d H:i:s'),
                json_encode($payload),
                $now->format('Y-m-d H:i:s'),
                $sessionId,
                $currentExpiresAt,
            ]
        );

        // If no rows affected, another request already extended the session
        if ($affectedRows === 0) {
            $this->logger->debug('Session extension skipped - already extended by another request', [
                'session_id' => substr($sessionId, 0, 16) . '...',
            ]);
            return false;
        }

        return true;
    }

    /**
     * Validate session and update activity
     *
     * @param string $sessionId Session ID
     * @return array|null Session data or null
     */
    public function validate(string $sessionId): ?array
    {
        $session = $this->get($sessionId);

        if ($session === null) {
            return null;
        }

        // Check if it's a pending 2FA session
        if (!empty($session['payload']['pending_2fa'])) {
            return null; // Don't allow access with pending 2FA
        }

        // Update last activity
        $this->touch($sessionId);

        return $session;
    }

    /**
     * Update session last activity
     */
    public function touch(string $sessionId): void
    {
        $this->db->execute(
            'UPDATE admin_sessions SET last_activity = NOW() WHERE id = ?',
            [$sessionId]
        );
    }

    /**
     * Destroy a session
     */
    public function destroy(string $sessionId): bool
    {
        $affected = $this->db->execute(
            'DELETE FROM admin_sessions WHERE id = ?',
            [$sessionId]
        );

        $this->logger->info('Session destroyed', [
            'session_id' => substr($sessionId, 0, 16) . '...',
        ]);

        // Strategic security log: session destroyed
        Logger::channel('security')->warning( 'Session destroyed', [
            'session_id_prefix' => substr($sessionId, 0, 16),
        ]);

        return $affected > 0;
    }

    /**
     * Destroy all sessions for a user except one
     *
     * @param int $userId User ID
     * @param string|null $exceptSessionId Session ID to keep
     */
    public function destroyAllExcept(int $userId, ?string $exceptSessionId): int
    {
        if ($exceptSessionId) {
            $count = $this->db->execute(
                'DELETE FROM admin_sessions WHERE user_id = ? AND id != ?',
                [$userId, $exceptSessionId]
            );
        } else {
            $count = $this->db->execute(
                'DELETE FROM admin_sessions WHERE user_id = ?',
                [$userId]
            );
        }

        if ($count > 0) {
            $this->logger->info('Sessions destroyed for user', [
                'user_id' => $userId,
                'count' => $count,
            ]);

            // Strategic log: mass session invalidation (security-relevant event)
            Logger::channel('security')->warning( 'Multiple sessions invalidated for user', [
                'user_id' => $userId,
                'sessions_destroyed' => $count,
                'kept_session' => $exceptSessionId ? substr($exceptSessionId, 0, 16) : null,
            ]);
        }

        return $count;
    }

    /**
     * Get all active sessions for a user
     *
     * @param int $userId User ID
     * @return array<array>
     */
    public function getUserSessions(int $userId): array
    {
        return $this->db->query(
            'SELECT id, ip_address, user_agent, last_activity, created_at, expires_at FROM admin_sessions WHERE user_id = ? AND expires_at > NOW() ORDER BY last_activity DESC',
            [$userId]
        );
    }

    /**
     * Set session payload data
     *
     * @param string $sessionId Session ID
     * @param string $key Payload key
     * @param mixed $value Payload value
     */
    public function set(string $sessionId, string $key, mixed $value): void
    {
        $rows = $this->db->query(
            'SELECT payload FROM admin_sessions WHERE id = ?',
            [$sessionId]
        );

        $payloadJson = $rows[0]['payload'] ?? null;
        $payload = $payloadJson ? json_decode($payloadJson, true) : [];
        $payload[$key] = $value;

        $this->db->execute(
            'UPDATE admin_sessions SET payload = ? WHERE id = ?',
            [json_encode($payload), $sessionId]
        );
    }

    /**
     * Get session payload data
     *
     * @param string $sessionId Session ID
     * @param string $key Payload key
     * @param mixed $default Default value
     * @return mixed
     */
    public function getPayload(string $sessionId, string $key, mixed $default = null): mixed
    {
        $rows = $this->db->query(
            'SELECT payload FROM admin_sessions WHERE id = ?',
            [$sessionId]
        );

        $payloadJson = $rows[0]['payload'] ?? null;

        if (!$payloadJson) {
            return $default;
        }

        $payload = json_decode($payloadJson, true);

        return $payload[$key] ?? $default;
    }

    /**
     * Get CSRF token for session
     */
    public function getCsrfToken(string $sessionId): ?string
    {
        return $this->getPayload($sessionId, 'csrf_token');
    }

    /**
     * Verify CSRF token
     */
    public function verifyCsrfToken(string $sessionId, string $token): bool
    {
        $storedToken = $this->getCsrfToken($sessionId);

        if ($storedToken === null) {
            return false;
        }

        return hash_equals($storedToken, $token);
    }

    /**
     * Regenerate CSRF token
     */
    public function regenerateCsrfToken(string $sessionId): string
    {
        $newToken = $this->generateCsrfToken();
        $this->set($sessionId, 'csrf_token', $newToken);

        return $newToken;
    }

    /**
     * Set flash message
     */
    public function flash(string $sessionId, string $key, mixed $value): void
    {
        $flash = $this->getPayload($sessionId, 'flash') ?? [];
        $flash[$key] = $value;
        $this->set($sessionId, 'flash', $flash);
    }

    /**
     * Get and clear flash message
     */
    public function getFlash(string $sessionId, string $key): mixed
    {
        $flash = $this->getPayload($sessionId, 'flash') ?? [];

        if (!isset($flash[$key])) {
            return null;
        }

        $value = $flash[$key];
        unset($flash[$key]);
        $this->set($sessionId, 'flash', $flash);

        return $value;
    }

    /**
     * Cleanup expired sessions
     *
     * @return int Number of sessions cleaned up
     */
    public function cleanup(): int
    {
        $count = $this->db->execute('DELETE FROM admin_sessions WHERE expires_at < NOW()');

        if ($count > 0) {
            $this->logger->info('Expired sessions cleaned up', ['count' => $count]);
        }

        return $count;
    }

    /**
     * Get session statistics
     *
     * @return array{total: int, active: int, expired: int}
     */
    public function getStats(): array
    {
        $rows = $this->db->query(
            'SELECT COUNT(*) as total, SUM(CASE WHEN expires_at > NOW() THEN 1 ELSE 0 END) as active, SUM(CASE WHEN expires_at <= NOW() THEN 1 ELSE 0 END) as expired FROM admin_sessions'
        );

        $result = $rows[0] ?? [];

        return [
            'total' => (int) ($result['total'] ?? 0),
            'active' => (int) ($result['active'] ?? 0),
            'expired' => (int) ($result['expired'] ?? 0),
        ];
    }

    /**
     * Generate cryptographically secure session ID
     */
    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(self::SESSION_ID_BYTES));
    }

    /**
     * Generate CSRF token
     */
    private function generateCsrfToken(): string
    {
        return bin2hex(random_bytes(self::CSRF_TOKEN_BYTES));
    }
}
