<?php
/**
 * Enterprise Admin Panel - CLI Token Service
 *
 * Manages hierarchical CLI tokens for secure admin access.
 *
 * TOKEN HIERARCHY:
 * ================
 * 1. MASTER TOKEN: Generated during installation
 *    - Required for all administrative operations
 *    - Can create sub-admin and emergency tokens
 *    - Derived from: email + password + secret
 *
 * 2. SUB-ADMIN TOKEN: Created by master for other admins
 *    - Limited permissions
 *    - Can access admin panel via CLI
 *    - Cannot create other tokens
 *
 * 3. EMERGENCY TOKEN: One-time use for recovery
 *    - Stored offline (printed/safe)
 *    - Can regenerate master token
 *    - Invalidated after single use
 *
 * SECURITY MODEL:
 * ===============
 * - Only SHA-256 hash stored in database
 * - Raw tokens shown ONCE during generation
 * - All operations logged to error_log and audit table
 * - Old tokens remain valid until explicitly revoked
 *
 * @version 2.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Services;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use RuntimeException;

final class CliTokenService
{
    /**
     * Token prefixes for identification
     */
    private const MASTER_TOKEN_PREFIX = 'eap-master-';
    private const SUB_TOKEN_PREFIX = 'eap-sub-';
    private const EMERGENCY_TOKEN_PREFIX = 'eap-emergency-';

    /**
     * Token entropy in bytes (64 bytes = 512 bits)
     */
    private const TOKEN_ENTROPY_BYTES = 64;

    /**
     * HMAC algorithm for token generation
     */
    private const HMAC_ALGORITHM = 'sha256';

    public function __construct(
        private DatabasePool $db
    ) {}

    // =========================================================================
    // MASTER TOKEN OPERATIONS
    // =========================================================================

    /**
     * Generate master CLI token
     *
     * @param string $email Master admin email
     * @param string $password Master admin password (plain text)
     * @param string $masterSecret Application master secret
     * @return array{success: bool, token?: string, error?: string, is_first?: bool}
     */
    public function generateMasterToken(string $email, string $password, string $masterSecret): array
    {
        // Find user and verify password
        $users = $this->db->query(
            'SELECT id, password_hash, is_master, cli_token_generation_count FROM admin_users WHERE email = ? AND is_active = true',
            [$email]
        );
        $user = $users[0] ?? null;

        if (!$user) {
            return ['success' => false, 'error' => 'User not found or inactive'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Invalid password'];
        }

        $userId = (int) $user['id'];
        $generationCount = (int) ($user['cli_token_generation_count'] ?? 0);

        // Check master admin status
        if (!$user['is_master']) {
            $existingMasters = $this->db->query('SELECT id FROM admin_users WHERE is_master = true');
            if (!empty($existingMasters)) {
                return [
                    'success' => false,
                    'error' => 'Another master admin exists. Only master can generate CLI tokens.',
                ];
            }
            // Make this user master
            $this->db->execute('UPDATE admin_users SET is_master = true WHERE id = ?', [$userId]);
        }

        // Generate token
        $token = $this->createToken(self::MASTER_TOKEN_PREFIX, $userId, $password, $masterSecret);
        $tokenHash = hash('sha256', $token);

        // Store hash
        $this->db->execute(
            'UPDATE admin_users SET cli_token_hash = ?, cli_token_generated_at = NOW(), cli_token_generation_count = cli_token_generation_count + 1 WHERE id = ?',
            [$tokenHash, $userId]
        );

        // Audit log
        $this->logTokenAction('master_token_' . ($generationCount > 0 ? 'regenerated' : 'generated'), $userId, $email);

        if ($generationCount > 0) {
            error_log(sprintf(
                '[SECURITY] Master CLI token regenerated for user %d (%s) at %s. Count: %d',
                $userId, $email, date('Y-m-d H:i:s'), $generationCount + 1
            ));
        }

        return [
            'success' => true,
            'token' => $token,
            'is_first' => $generationCount === 0,
        ];
    }

    /**
     * Verify master token
     *
     * @param string $token Raw master token
     * @return array{valid: bool, user_id?: int, email?: string, error?: string}
     */
    public function verifyMasterToken(string $token): array
    {
        if (!str_starts_with($token, self::MASTER_TOKEN_PREFIX)) {
            return ['valid' => false, 'error' => 'Invalid token format (expected master token)'];
        }

        $tokenHash = hash('sha256', $token);

        $users = $this->db->query(
            'SELECT id, email FROM admin_users WHERE cli_token_hash = ? AND is_active = true AND is_master = true',
            [$tokenHash]
        );
        $user = $users[0] ?? null;

        if (!$user) {
            return ['valid' => false, 'error' => 'Invalid or expired master token'];
        }

        return [
            'valid' => true,
            'user_id' => (int) $user['id'],
            'email' => $user['email'],
        ];
    }

    /**
     * Revoke master token
     *
     * @param string $masterToken Valid master token for authentication
     * @return array{success: bool, error?: string}
     */
    public function revokeMasterToken(string $masterToken): array
    {
        $verification = $this->verifyMasterToken($masterToken);
        if (!$verification['valid']) {
            return ['success' => false, 'error' => $verification['error']];
        }

        $this->db->execute(
            'UPDATE admin_users SET cli_token_hash = NULL WHERE id = ?',
            [$verification['user_id']]
        );

        $this->logTokenAction('master_token_revoked', $verification['user_id'], $verification['email']);

        error_log(sprintf(
            '[SECURITY] Master CLI token revoked for user %d (%s) at %s',
            $verification['user_id'], $verification['email'], date('Y-m-d H:i:s')
        ));

        return ['success' => true];
    }

    // =========================================================================
    // SUB-ADMIN TOKEN OPERATIONS
    // =========================================================================

    /**
     * Create sub-admin token for another admin
     *
     * @param string $masterToken Valid master token
     * @param int $targetUserId User ID to create token for
     * @param string $masterSecret Application master secret
     * @return array{success: bool, token?: string, error?: string}
     */
    public function createSubToken(string $masterToken, int $targetUserId, string $masterSecret): array
    {
        // Verify master token
        $master = $this->verifyMasterToken($masterToken);
        if (!$master['valid']) {
            return ['success' => false, 'error' => 'Invalid master token'];
        }

        // Cannot create sub-token for self
        if ($master['user_id'] === $targetUserId) {
            return ['success' => false, 'error' => 'Cannot create sub-token for master admin'];
        }

        // Verify target user exists and is not master
        $targetUsers = $this->db->query(
            'SELECT id, email, is_master FROM admin_users WHERE id = ? AND is_active = true',
            [$targetUserId]
        );
        $targetUser = $targetUsers[0] ?? null;

        if (!$targetUser) {
            return ['success' => false, 'error' => 'Target user not found or inactive'];
        }

        if ($targetUser['is_master']) {
            return ['success' => false, 'error' => 'Cannot create sub-token for master admin'];
        }

        // Generate sub-token
        $token = $this->createToken(self::SUB_TOKEN_PREFIX, $targetUserId, '', $masterSecret);
        $tokenHash = hash('sha256', $token);

        // Store hash
        $this->db->execute(
            'UPDATE admin_users SET sub_token_hash = ?, sub_token_created_at = NOW(), sub_token_created_by = ? WHERE id = ?',
            [$tokenHash, $master['user_id'], $targetUserId]
        );

        // Audit log
        $this->logTokenAction(
            'sub_token_created',
            $master['user_id'],
            $master['email'],
            $targetUserId,
            $targetUser['email']
        );

        error_log(sprintf(
            '[SECURITY] Sub-admin token created by master %d for user %d (%s) at %s',
            $master['user_id'], $targetUserId, $targetUser['email'], date('Y-m-d H:i:s')
        ));

        return [
            'success' => true,
            'token' => $token,
            'target_email' => $targetUser['email'],
        ];
    }

    /**
     * Verify sub-admin token
     *
     * @param string $token Raw sub-admin token
     * @return array{valid: bool, user_id?: int, email?: string, error?: string}
     */
    public function verifySubToken(string $token): array
    {
        if (!str_starts_with($token, self::SUB_TOKEN_PREFIX)) {
            return ['valid' => false, 'error' => 'Invalid token format (expected sub-admin token)'];
        }

        $tokenHash = hash('sha256', $token);

        $users = $this->db->query(
            'SELECT id, email FROM admin_users WHERE sub_token_hash = ? AND is_active = true AND is_master = false',
            [$tokenHash]
        );
        $user = $users[0] ?? null;

        if (!$user) {
            return ['valid' => false, 'error' => 'Invalid or expired sub-admin token'];
        }

        return [
            'valid' => true,
            'user_id' => (int) $user['id'],
            'email' => $user['email'],
        ];
    }

    /**
     * Revoke sub-admin token
     *
     * @param string $masterToken Valid master token
     * @param int $targetUserId User ID to revoke token for
     * @return array{success: bool, error?: string}
     */
    public function revokeSubToken(string $masterToken, int $targetUserId): array
    {
        $master = $this->verifyMasterToken($masterToken);
        if (!$master['valid']) {
            return ['success' => false, 'error' => 'Invalid master token'];
        }

        $targetUsers = $this->db->query('SELECT email FROM admin_users WHERE id = ?', [$targetUserId]);
        $targetEmail = $targetUsers[0]['email'] ?? null;

        $this->db->execute(
            'UPDATE admin_users SET sub_token_hash = NULL, sub_token_created_at = NULL, sub_token_created_by = NULL WHERE id = ?',
            [$targetUserId]
        );

        $this->logTokenAction(
            'sub_token_revoked',
            $master['user_id'],
            $master['email'],
            $targetUserId,
            $targetEmail ?: 'unknown'
        );

        return ['success' => true];
    }

    // =========================================================================
    // EMERGENCY TOKEN OPERATIONS
    // =========================================================================

    /**
     * Create emergency token
     *
     * @param string $masterToken Valid master token
     * @param string $name Token name/description
     * @param int|null $expiresInDays Expiration in days (null = never)
     * @return array{success: bool, token?: string, error?: string}
     */
    public function createEmergencyToken(
        string $masterToken,
        string $name = 'Emergency Token',
        ?int $expiresInDays = null
    ): array {
        $master = $this->verifyMasterToken($masterToken);
        if (!$master['valid']) {
            return ['success' => false, 'error' => 'Invalid master token'];
        }

        // Generate emergency token
        $token = $this->createToken(self::EMERGENCY_TOKEN_PREFIX, $master['user_id'], '', '');
        $tokenHash = hash('sha256', $token);

        // Calculate expiration
        $expiresAt = $expiresInDays !== null
            ? date('Y-m-d H:i:s', strtotime("+{$expiresInDays} days"))
            : null;

        // Store in emergency tokens table
        $this->db->execute(
            'INSERT INTO admin_emergency_tokens (master_user_id, token_hash, name, expires_at) VALUES (?, ?, ?, ?)',
            [$master['user_id'], $tokenHash, $name, $expiresAt]
        );

        $this->logTokenAction(
            'emergency_token_created',
            $master['user_id'],
            $master['email'],
            null,
            null,
            ['name' => $name, 'expires_at' => $expiresAt]
        );

        error_log(sprintf(
            '[SECURITY] Emergency token "%s" created by master %d at %s',
            $name, $master['user_id'], date('Y-m-d H:i:s')
        ));

        return [
            'success' => true,
            'token' => $token,
            'name' => $name,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Use emergency token to regenerate master token
     *
     * @param string $emergencyToken Raw emergency token
     * @param string $email Master admin email
     * @param string $password Master admin password
     * @param string $masterSecret Application master secret
     * @return array{success: bool, new_master_token?: string, error?: string}
     */
    public function useEmergencyToken(
        string $emergencyToken,
        string $email,
        string $password,
        string $masterSecret
    ): array {
        if (!str_starts_with($emergencyToken, self::EMERGENCY_TOKEN_PREFIX)) {
            return ['success' => false, 'error' => 'Invalid token format (expected emergency token)'];
        }

        $tokenHash = hash('sha256', $emergencyToken);

        // Find and validate emergency token
        $tokenRows = $this->db->query(
            'SELECT et.id, et.master_user_id, et.name, et.is_used, et.expires_at, u.email FROM admin_emergency_tokens et JOIN admin_users u ON u.id = et.master_user_id WHERE et.token_hash = ?',
            [$tokenHash]
        );
        $tokenData = $tokenRows[0] ?? null;

        if (!$tokenData) {
            return ['success' => false, 'error' => 'Emergency token not found'];
        }

        if ($tokenData['is_used']) {
            return ['success' => false, 'error' => 'Emergency token already used'];
        }

        if ($tokenData['expires_at'] !== null && strtotime($tokenData['expires_at']) < time()) {
            return ['success' => false, 'error' => 'Emergency token expired'];
        }

        // Verify email matches the master user
        if (strtolower($tokenData['email']) !== strtolower($email)) {
            return ['success' => false, 'error' => 'Email does not match token owner'];
        }

        // Verify password
        $passwordRows = $this->db->query(
            'SELECT password_hash FROM admin_users WHERE id = ?',
            [$tokenData['master_user_id']]
        );
        $passwordHash = $passwordRows[0]['password_hash'] ?? null;

        if (!password_verify($password, $passwordHash)) {
            return ['success' => false, 'error' => 'Invalid password'];
        }

        // Mark emergency token as used
        $this->db->execute(
            'UPDATE admin_emergency_tokens SET is_used = true, used_at = NOW(), used_from_ip = ? WHERE id = ?',
            [$_SERVER['REMOTE_ADDR'] ?? 'CLI', $tokenData['id']]
        );

        // Generate new master token
        $result = $this->generateMasterToken($email, $password, $masterSecret);

        if (!$result['success']) {
            return $result;
        }

        $this->logTokenAction(
            'emergency_token_used',
            $tokenData['master_user_id'],
            $email,
            null,
            null,
            ['emergency_token_name' => $tokenData['name']]
        );

        error_log(sprintf(
            '[SECURITY] Emergency token "%s" used to regenerate master token for user %d at %s',
            $tokenData['name'], $tokenData['master_user_id'], date('Y-m-d H:i:s')
        ));

        return [
            'success' => true,
            'new_master_token' => $result['token'],
        ];
    }

    /**
     * List emergency tokens for master
     *
     * @param string $masterToken Valid master token
     * @return array{success: bool, tokens?: array, error?: string}
     */
    public function listEmergencyTokens(string $masterToken): array
    {
        $master = $this->verifyMasterToken($masterToken);
        if (!$master['valid']) {
            return ['success' => false, 'error' => 'Invalid master token'];
        }

        $tokens = $this->db->query(
            "SELECT id, name, is_used, used_at, expires_at, created_at, CASE WHEN is_used THEN 'used' WHEN expires_at IS NOT NULL AND expires_at < NOW() THEN 'expired' ELSE 'active' END AS status FROM admin_emergency_tokens WHERE master_user_id = ? ORDER BY created_at DESC",
            [$master['user_id']]
        );

        return [
            'success' => true,
            'tokens' => $tokens,
        ];
    }

    /**
     * Revoke emergency token
     *
     * @param string $masterToken Valid master token
     * @param int $tokenId Emergency token ID
     * @return array{success: bool, error?: string}
     */
    public function revokeEmergencyToken(string $masterToken, int $tokenId): array
    {
        $master = $this->verifyMasterToken($masterToken);
        if (!$master['valid']) {
            return ['success' => false, 'error' => 'Invalid master token'];
        }

        $affectedRows = $this->db->execute(
            'DELETE FROM admin_emergency_tokens WHERE id = ? AND master_user_id = ?',
            [$tokenId, $master['user_id']]
        );

        if ($affectedRows === 0) {
            return ['success' => false, 'error' => 'Token not found or not owned by you'];
        }

        $this->logTokenAction(
            'emergency_token_revoked',
            $master['user_id'],
            $master['email'],
            null,
            null,
            ['token_id' => $tokenId]
        );

        return ['success' => true];
    }

    // =========================================================================
    // UTILITY METHODS
    // =========================================================================

    /**
     * Verify any token type
     *
     * @param string $token Raw token of any type
     * @return array{valid: bool, type?: string, user_id?: int, email?: string, error?: string}
     */
    public function verifyAnyToken(string $token): array
    {
        if (str_starts_with($token, self::MASTER_TOKEN_PREFIX)) {
            $result = $this->verifyMasterToken($token);
            return $result['valid'] ? array_merge($result, ['type' => 'master']) : $result;
        }

        if (str_starts_with($token, self::SUB_TOKEN_PREFIX)) {
            $result = $this->verifySubToken($token);
            return $result['valid'] ? array_merge($result, ['type' => 'sub']) : $result;
        }

        return ['valid' => false, 'error' => 'Unknown token type'];
    }

    /**
     * Get admin URL using any valid token
     *
     * @param string $token Any valid CLI token
     * @return array{success: bool, url?: string, full_url?: string, error?: string}
     */
    public function getAdminUrl(string $token): array
    {
        $verification = $this->verifyAnyToken($token);

        if (!$verification['valid']) {
            return ['success' => false, 'error' => $verification['error']];
        }

        $configRows = $this->db->query(
            "SELECT config_value FROM admin_config WHERE config_key = 'admin_base_path'"
        );
        $adminBasePath = $configRows[0]['config_value'] ?? null;

        if (!$adminBasePath) {
            return ['success' => false, 'error' => 'Admin URL not configured'];
        }

        $baseUrl = $_ENV['APP_URL'] ?? 'http://localhost:8080';

        return [
            'success' => true,
            'url' => $adminBasePath,
            'full_url' => rtrim($baseUrl, '/') . $adminBasePath . '/login',
            'token_type' => $verification['type'],
        ];
    }

    /**
     * Check if user has any token
     *
     * @param int $userId User ID
     * @return array{has_master: bool, has_sub: bool}
     */
    public function getUserTokenStatus(int $userId): array
    {
        $users = $this->db->query(
            'SELECT cli_token_hash, sub_token_hash, is_master FROM admin_users WHERE id = ?',
            [$userId]
        );
        $user = $users[0] ?? null;

        if (!$user) {
            return ['has_master' => false, 'has_sub' => false];
        }

        return [
            'has_master' => $user['is_master'] && !empty($user['cli_token_hash']),
            'has_sub' => !$user['is_master'] && !empty($user['sub_token_hash']),
        ];
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Create a token with the given prefix
     *
     * @param string $prefix Token prefix
     * @param int $userId User ID
     * @param string $password User password (optional)
     * @param string $masterSecret Application secret
     * @return string Raw token
     */
    private function createToken(string $prefix, int $userId, string $password, string $masterSecret): string
    {
        $nonce = bin2hex(random_bytes(self::TOKEN_ENTROPY_BYTES));

        $payload = implode('|', [
            $prefix,
            $userId,
            $password,
            $nonce,
            time(),
        ]);

        $hmac = hash_hmac(self::HMAC_ALGORITHM, $payload, $masterSecret . $nonce, false);

        return $prefix . substr($nonce, 0, 32) . $hmac;
    }

    /**
     * Log token action to audit table
     *
     * @param string $action Action type
     * @param int|null $userId User performing action
     * @param string|null $userEmail User email
     * @param int|null $targetUserId Target user ID
     * @param string|null $targetEmail Target email
     * @param array $details Additional details
     */
    private function logTokenAction(
        string $action,
        ?int $userId,
        ?string $userEmail,
        ?int $targetUserId = null,
        ?string $targetEmail = null,
        array $details = []
    ): void {
        try {
            $this->db->execute(
                'INSERT INTO admin_token_audit_log (user_id, user_email, action, target_user_id, target_email, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    $userEmail,
                    $action,
                    $targetUserId,
                    $targetEmail,
                    json_encode($details),
                    $_SERVER['REMOTE_ADDR'] ?? 'CLI',
                    $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
                ]
            );
        } catch (\Throwable $e) {
            // Log to error_log if audit table doesn't exist yet
            error_log("[TOKEN AUDIT] {$action} by {$userEmail} (ID: {$userId})");
        }
    }
}
