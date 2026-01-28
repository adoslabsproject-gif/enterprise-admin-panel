<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Services;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;

/**
 * Emergency Recovery Token Service
 *
 * Provides one-time bypass tokens for master admin to access the panel
 * when 2FA is unavailable (lost authenticator, no email access, etc.)
 *
 * Security features:
 * - Tokens are hashed with Argon2id (never stored in plaintext)
 * - One-time use only (immediately invalidated after use)
 * - 24-hour expiration
 * - Only ONE active token per user at a time
 * - Only master admin can generate/use recovery tokens
 * - Full audit trail
 *
 * @version 1.0.0
 */
final class RecoveryService
{
    /**
     * Token configuration
     */
    private const TOKEN_BYTES = 32; // 256 bits of entropy
    private const TOKEN_LIFETIME_HOURS = 24;

    /**
     * Argon2id parameters (matching AuthService for consistency)
     */
    private const ARGON2_MEMORY_COST = 65536;  // 64 MB
    private const ARGON2_TIME_COST = 4;
    private const ARGON2_THREADS = 3;

    public function __construct(
        private DatabasePool $db,
        private NotificationService $notificationService,
        private AuditService $auditService,
        private ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Generate a new recovery token for master admin
     *
     * @param int $userId User ID (must be master admin)
     * @param string $deliveryMethod How to send the token (email, telegram, discord, slack)
     * @param string $createdByIp IP address of the requester
     * @return array{success: bool, token?: string, error?: string, expires_at?: string}
     */
    public function generateToken(
        int $userId,
        string $deliveryMethod = 'email',
        string $createdByIp = '127.0.0.1'
    ): array {
        // Verify user is master admin
        $users = $this->db->query(
            'SELECT id, email, name, is_master FROM admin_users WHERE id = ?',
            [$userId]
        );
        $user = $users[0] ?? null;

        if (!$user) {
            Logger::channel('security')->warning('Recovery token request for unknown user', [
                'user_id' => $userId,
                'ip' => $createdByIp,
            ]);
            return ['success' => false, 'error' => 'User not found'];
        }

        if (!$user['is_master']) {
            $this->auditService->log('recovery_token_denied', $userId, [
                'reason' => 'not_master_admin',
                'ip' => $createdByIp,
            ]);
            Logger::channel('security')->warning('Recovery token denied - not master admin', [
                'user_id' => $userId,
                'email' => $user['email'],
                'ip' => $createdByIp,
            ]);
            return ['success' => false, 'error' => 'Only master admin can generate recovery tokens'];
        }

        // Revoke any existing active tokens for this user
        $this->revokeActiveTokens($userId, 'new_token_generated');

        // Generate cryptographically secure token
        $plainToken = $this->generateSecureToken();
        $tokenHash = $this->hashToken($plainToken);

        $now = new DateTimeImmutable();
        $expiresAt = $now->modify('+' . self::TOKEN_LIFETIME_HOURS . ' hours');

        // Store hashed token - use transaction to get lastInsertId
        $connection = $this->db->beginTransaction();
        try {
            $pdo = $connection->getPdo();
            $stmt = $pdo->prepare(
                'INSERT INTO admin_recovery_tokens (token_hash, user_id, delivery_method, expires_at, created_by_ip) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $tokenHash,
                $userId,
                $deliveryMethod,
                $expiresAt->format('Y-m-d H:i:s'),
                $createdByIp,
            ]);
            $tokenId = (int) $pdo->lastInsertId();
            $this->db->commit($connection);
        } catch (\Throwable $e) {
            $this->db->rollback($connection);
            Logger::channel('error')->error( 'Recovery token generation failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->auditService->log('recovery_token_generated', $userId, [
            'token_id' => $tokenId,
            'delivery_method' => $deliveryMethod,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'ip' => $createdByIp,
        ]);

        Logger::channel('security')->warning('Recovery token generated', [
            'user_id' => $userId,
            'token_id' => $tokenId,
            'delivery_method' => $deliveryMethod,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'ip' => $createdByIp,
        ]);

        return [
            'success' => true,
            'token' => $plainToken,
            'token_id' => $tokenId,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'user_email' => $user['email'],
            'user_name' => $user['name'],
        ];
    }

    /**
     * Send recovery token via configured channel
     *
     * @param int $tokenId Token ID
     * @param string $plainToken The plaintext token (only available at generation time)
     * @return array{success: bool, error?: string}
     */
    public function sendToken(int $tokenId, string $plainToken): array
    {
        $tokens = $this->db->query(
            'SELECT rt.*, u.email, u.name, u.telegram_chat_id, u.discord_user_id, u.slack_user_id FROM admin_recovery_tokens rt JOIN admin_users u ON rt.user_id = u.id WHERE rt.id = ? AND rt.used_at IS NULL AND rt.is_revoked = FALSE',
            [$tokenId]
        );
        $token = $tokens[0] ?? null;

        if (!$token) {
            return ['success' => false, 'error' => 'Token not found or already used'];
        }

        $method = $token['delivery_method'];
        $deliveredTo = null;

        try {
            switch ($method) {
                case 'email':
                    $this->notificationService->sendRecoveryToken(
                        (int) $token['user_id'],
                        $plainToken,
                        $token['expires_at'],
                        'email'
                    );
                    $deliveredTo = $token['email'];
                    break;

                case 'telegram':
                    if (empty($token['telegram_chat_id'])) {
                        return ['success' => false, 'error' => 'Telegram not configured for this user'];
                    }
                    $this->notificationService->sendRecoveryToken(
                        (int) $token['user_id'],
                        $plainToken,
                        $token['expires_at'],
                        'telegram'
                    );
                    $deliveredTo = $token['telegram_chat_id'];
                    break;

                case 'discord':
                    if (empty($token['discord_user_id'])) {
                        return ['success' => false, 'error' => 'Discord not configured for this user'];
                    }
                    $this->notificationService->sendRecoveryToken(
                        (int) $token['user_id'],
                        $plainToken,
                        $token['expires_at'],
                        'discord'
                    );
                    $deliveredTo = $token['discord_user_id'];
                    break;

                case 'slack':
                    if (empty($token['slack_user_id'])) {
                        return ['success' => false, 'error' => 'Slack not configured for this user'];
                    }
                    $this->notificationService->sendRecoveryToken(
                        (int) $token['user_id'],
                        $plainToken,
                        $token['expires_at'],
                        'slack'
                    );
                    $deliveredTo = $token['slack_user_id'];
                    break;

                default:
                    return ['success' => false, 'error' => 'Unknown delivery method: ' . $method];
            }

            // Update delivery status
            $this->db->execute(
                'UPDATE admin_recovery_tokens SET delivered_at = NOW(), delivered_to = ? WHERE id = ?',
                [$deliveredTo, $tokenId]
            );

            $this->auditService->log('recovery_token_sent', (int) $token['user_id'], [
                'token_id' => $tokenId,
                'delivery_method' => $method,
                'delivered_to' => $this->maskSensitiveData($deliveredTo, $method),
            ]);

            return ['success' => true, 'delivered_to' => $deliveredTo];
        } catch (\Exception $e) {
            Logger::channel('error')->error('Failed to send recovery token', [
                'token_id' => $tokenId,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);
            Logger::channel('security')->error('Recovery token send failed', [
                'token_id' => $tokenId,
                'method' => $method,
                'user_id' => $token['user_id'],
            ]);
            return ['success' => false, 'error' => 'Failed to send token: ' . $e->getMessage()];
        }
    }

    /**
     * Verify and use a recovery token (one-time use)
     *
     * @param string $plainToken The plaintext token
     * @param string $ipAddress IP address of the user
     * @param string|null $userAgent User agent string
     * @return array{success: bool, user_id?: int, error?: string}
     */
    public function verifyAndUseToken(
        string $plainToken,
        string $ipAddress,
        ?string $userAgent = null
    ): array {
        // Find all active tokens (we need to check each hash)
        $tokens = $this->db->query(
            'SELECT rt.*, u.email, u.name, u.is_master FROM admin_recovery_tokens rt JOIN admin_users u ON rt.user_id = u.id WHERE rt.used_at IS NULL AND rt.is_revoked = FALSE AND rt.expires_at > NOW()'
        );

        foreach ($tokens as $token) {
            if ($this->verifyToken($plainToken, $token['token_hash'])) {
                // Verify user is still master admin
                if (!$token['is_master']) {
                    $this->auditService->log('recovery_token_rejected', (int) $token['user_id'], [
                        'token_id' => $token['id'],
                        'reason' => 'user_no_longer_master',
                        'ip' => $ipAddress,
                    ]);
                    return ['success' => false, 'error' => 'User is no longer master admin'];
                }

                // Mark token as used (one-time use)
                $this->db->execute(
                    'UPDATE admin_recovery_tokens SET used_at = NOW(), used_ip = ?, used_user_agent = ? WHERE id = ?',
                    [$ipAddress, $userAgent, $token['id']]
                );

                $this->auditService->log('recovery_token_used', (int) $token['user_id'], [
                    'token_id' => $token['id'],
                    'ip' => $ipAddress,
                    'user_agent' => $userAgent,
                ]);

                Logger::channel('security')->warning('Recovery token used - 2FA bypass', [
                    'user_id' => $token['user_id'],
                    'token_id' => $token['id'],
                    'email' => $token['email'],
                    'ip' => $ipAddress,
                ]);

                return [
                    'success' => true,
                    'user_id' => (int) $token['user_id'],
                    'user_email' => $token['email'],
                    'user_name' => $token['name'],
                ];
            }
        }

        // Log failed attempt
        $this->auditService->log('recovery_token_failed', null, [
            'reason' => 'invalid_or_expired_token',
            'ip' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        Logger::channel('security')->warning('Recovery token verification failed', [
            'reason' => 'invalid_or_expired_token',
            'ip' => $ipAddress,
        ]);

        return ['success' => false, 'error' => 'Invalid or expired recovery token'];
    }

    /**
     * Revoke all active tokens for a user
     *
     * @param int $userId User ID
     * @param string $reason Revocation reason
     * @return int Number of tokens revoked
     */
    public function revokeActiveTokens(int $userId, string $reason = 'manual_revocation'): int
    {
        $count = $this->db->execute(
            'UPDATE admin_recovery_tokens SET is_revoked = TRUE, revoked_at = NOW(), revoked_reason = ? WHERE user_id = ? AND used_at IS NULL AND is_revoked = FALSE',
            [$reason, $userId]
        );

        if ($count > 0) {
            $this->auditService->log('recovery_tokens_revoked', $userId, [
                'count' => $count,
                'reason' => $reason,
            ]);
        }

        return $count;
    }

    /**
     * Cleanup expired tokens
     *
     * @return int Number of tokens cleaned up
     */
    public function cleanupExpired(): int
    {
        return $this->db->execute(
            'DELETE FROM admin_recovery_tokens WHERE expires_at < NOW() AND used_at IS NULL'
        );
    }

    /**
     * Get active token status for a user
     *
     * @param int $userId User ID
     * @return array|null Token info or null if no active token
     */
    public function getActiveToken(int $userId): ?array
    {
        $tokens = $this->db->query(
            'SELECT id, delivery_method, delivered_at, delivered_to, expires_at, created_at FROM admin_recovery_tokens WHERE user_id = ? AND used_at IS NULL AND is_revoked = FALSE AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1',
            [$userId]
        );

        return $tokens[0] ?? null;
    }

    /**
     * Generate cryptographically secure token
     *
     * Format: recovery-XXXX-XXXX-XXXX-XXXX (easier to read/type)
     */
    private function generateSecureToken(): string
    {
        $bytes = random_bytes(self::TOKEN_BYTES);
        $hex = bin2hex($bytes);

        // Format: recovery-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX
        $formatted = 'recovery-' . implode('-', str_split($hex, 8));

        return $formatted;
    }

    /**
     * Hash token with Argon2id
     */
    private function hashToken(string $token): string
    {
        return password_hash($token, PASSWORD_ARGON2ID, [
            'memory_cost' => self::ARGON2_MEMORY_COST,
            'time_cost' => self::ARGON2_TIME_COST,
            'threads' => self::ARGON2_THREADS,
        ]);
    }

    /**
     * Verify token against hash
     */
    private function verifyToken(string $plainToken, string $hash): bool
    {
        return password_verify($plainToken, $hash);
    }

    /**
     * Mask sensitive data for audit logs
     */
    private function maskSensitiveData(string $value, string $type): string
    {
        return match ($type) {
            'email' => preg_replace('/(?<=.).(?=.*@)/', '*', $value),
            'telegram', 'discord', 'slack' => substr($value, 0, 4) . '****' . substr($value, -4),
            default => '****',
        };
    }
}
