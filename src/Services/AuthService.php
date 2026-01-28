<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Services;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use DateTimeImmutable;
use RuntimeException;
use AdosLabs\AdminPanel\Services\TwoFactorService;
use AdosLabs\AdminPanel\Services\NotificationService;
use AdosLabs\AdminPanel\Services\ConfigService;
use AdosLabs\AdminPanel\Services\EncryptionService;

/**
 * Enterprise Authentication Service
 *
 * Features:
 * - Argon2id password hashing (OWASP recommended)
 * - Brute force protection (progressive lockout)
 * - Two-factor authentication (TOTP)
 * - Secure session management
 * - Complete audit trail
 *
 * @version 1.0.0
 */
final class AuthService
{
    /**
     * Lockout configuration
     */
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_DURATION_MINUTES = 15;
    private const LOCKOUT_MULTIPLIER = 2; // Exponential backoff

    /**
     * Password hashing options (Argon2id - OWASP 2024 recommended)
     */
    private const PASSWORD_ALGO = PASSWORD_ARGON2ID;
    private const PASSWORD_OPTIONS = [
        'memory_cost' => 65536,  // 64 MB
        'time_cost' => 4,        // 4 iterations
        'threads' => 3,          // 3 parallel threads
    ];

    /**
     * Password reset token expiration (1 hour)
     */
    private const PASSWORD_RESET_EXPIRY_HOURS = 1;

    private ?TwoFactorService $twoFactorService = null;
    private ?EncryptionService $encryptionService = null;

    public function __construct(
        private DatabasePool $db,
        private SessionService $sessionService,
        private AuditService $auditService,
        private ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();

        // Initialize encryption service for 2FA secrets
        try {
            $this->encryptionService = new EncryptionService();
        } catch (RuntimeException $e) {
            // APP_KEY not configured - 2FA encryption will fail
            $this->logger->warning('EncryptionService not available: ' . $e->getMessage());
        }
    }

    /**
     * Set encryption service (for dependency injection)
     */
    public function setEncryptionService(EncryptionService $encryptionService): void
    {
        $this->encryptionService = $encryptionService;
    }

    /**
     * Set TwoFactorService for OTP-based 2FA
     */
    public function setTwoFactorService(TwoFactorService $twoFactorService): void
    {
        $this->twoFactorService = $twoFactorService;
    }

    /**
     * Attempt to authenticate user
     *
     * @param string $email User email
     * @param string $password Plain text password
     * @param string $ipAddress Client IP address
     * @param string|null $userAgent Client user agent
     * @return array{success: bool, user: ?array, error: ?string, requires_2fa: bool}
     */
    public function attempt(
        string $email,
        string $password,
        string $ipAddress,
        ?string $userAgent = null
    ): array {
        $email = strtolower(trim($email));

        // Find user
        $user = $this->findUserByEmail($email);

        if ($user === null) {
            // Don't reveal if user exists (timing attack protection)
            password_hash('dummy', self::PASSWORD_ALGO, self::PASSWORD_OPTIONS);

            $this->auditService->log('login_failed', null, [
                'reason' => 'user_not_found',
                'email' => $email,
            ], $ipAddress, $userAgent);

            // Strategic log: unknown email login attempt
            log_warning('auth', 'Login attempt with unknown email', [
                'email' => $email,
                'ip' => $ipAddress,
            ]);

            return [
                'success' => false,
                'user' => null,
                'error' => 'Invalid credentials',
                'requires_2fa' => false,
            ];
        }

        // Check if account is locked
        if ($this->isAccountLocked($user)) {
            $this->auditService->log('login_failed', $user['id'], [
                'reason' => 'account_locked',
            ], $ipAddress, $userAgent);

            // Strategic log: locked account access attempt
            log_warning('auth', 'Login attempt on locked account', [
                'user_id' => $user['id'],
                'email' => $email,
                'ip' => $ipAddress,
                'locked_until' => $user['locked_until'],
            ]);

            return [
                'success' => false,
                'user' => null,
                'error' => 'Account temporarily locked. Please try again later.',
                'requires_2fa' => false,
            ];
        }

        // Check if account is active
        if (!$user['is_active']) {
            $this->auditService->log('login_failed', $user['id'], [
                'reason' => 'account_disabled',
            ], $ipAddress, $userAgent);

            // Strategic log: disabled account access attempt
            log_warning('auth', 'Login attempt on disabled account', [
                'user_id' => $user['id'],
                'email' => $email,
                'ip' => $ipAddress,
            ]);

            return [
                'success' => false,
                'user' => null,
                'error' => 'Account is disabled',
                'requires_2fa' => false,
            ];
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            $this->recordFailedAttempt($user['id'], $ipAddress, $userAgent);

            // Strategic log: password failure
            log_warning('auth', 'Invalid password attempt', [
                'user_id' => $user['id'],
                'email' => $email,
                'ip' => $ipAddress,
                'failed_attempts' => $user['failed_login_attempts'] + 1,
            ]);

            return [
                'success' => false,
                'user' => null,
                'error' => 'Invalid credentials',
                'requires_2fa' => false,
            ];
        }

        // Check if password needs rehashing
        if (password_needs_rehash($user['password_hash'], self::PASSWORD_ALGO, self::PASSWORD_OPTIONS)) {
            $this->updatePasswordHash($user['id'], $password);
        }

        // Check if 2FA is required
        if ($user['two_factor_enabled']) {
            // Create temporary session for 2FA verification
            $twoFaSessionId = $this->sessionService->create2FASession($user['id'], $ipAddress, $userAgent);

            // Determine 2FA method
            $method = $user['two_factor_method'] ?? 'totp';

            // For OTP-based methods (email, telegram, discord, slack), send the code now
            if ($method !== 'totp' && $this->twoFactorService !== null) {
                $sendResult = $this->twoFactorService->sendCode($user['id'], $method);

                if (!$sendResult['success']) {
                    $this->auditService->log('2fa_send_failed', $user['id'], [
                        'method' => $method,
                        'error' => $sendResult['error'],
                    ], $ipAddress, $userAgent);

                    // Still require 2FA but log the error
                    $this->logger->error('Failed to send 2FA code', [
                        'user_id' => $user['id'],
                        'method' => $method,
                        'error' => $sendResult['error'],
                    ]);
                } else {
                    $this->logger->info('2FA code sent', [
                        'user_id' => $user['id'],
                        'method' => $method,
                    ]);
                }
            }

            return [
                'success' => false,
                'user' => $this->sanitizeUser($user),
                'error' => null,
                'requires_2fa' => true,
                '2fa_method' => $method,
                '2fa_session_id' => $twoFaSessionId,
            ];
        }

        // Complete login
        return $this->completeLogin($user, $ipAddress, $userAgent);
    }

    /**
     * Verify 2FA code and complete login
     *
     * @param int $userId User ID
     * @param string $code TOTP code, OTP code, or recovery code
     * @param string $ipAddress Client IP
     * @param string|null $userAgent Client user agent
     * @return array{success: bool, user: ?array, error: ?string}
     */
    public function verify2FA(
        int $userId,
        string $code,
        string $ipAddress,
        ?string $userAgent = null
    ): array {
        $user = $this->findUserById($userId);

        if ($user === null || !$user['two_factor_enabled']) {
            return [
                'success' => false,
                'user' => null,
                'error' => '2FA not configured',
            ];
        }

        $method = $user['two_factor_method'] ?? 'totp';

        // For OTP-based methods, use TwoFactorService
        if ($method !== 'totp' && $this->twoFactorService !== null) {
            $result = $this->twoFactorService->verifyCode($userId, $code, $method);

            if ($result['success']) {
                return $this->completeLogin($user, $ipAddress, $userAgent);
            }

            // Try recovery code as fallback
            if ($this->verifyRecoveryCode($userId, $code)) {
                $this->auditService->log('2fa_recovery_used', $userId, [
                    'code_prefix' => substr($code, 0, 4) . '****',
                ], $ipAddress, $userAgent);

                // Strategic log: recovery code used (important security event)
                log_warning('security', '2FA recovery code used', [
                    'user_id' => $userId,
                    'ip' => $ipAddress,
                    'method' => $method,
                ]);

                return $this->completeLogin($user, $ipAddress, $userAgent);
            }

            return [
                'success' => false,
                'user' => null,
                'error' => $result['error'] ?? 'Invalid 2FA code',
            ];
        }

        // TOTP verification (decrypt secret first)
        if (!empty($user['two_factor_secret'])) {
            $secret = $this->decryptTwoFactorSecret($user['two_factor_secret']);
            if ($secret !== null && $this->verifyTOTP($secret, $code)) {
                return $this->completeLogin($user, $ipAddress, $userAgent);
            }
        }

        // Try recovery code
        if ($this->verifyRecoveryCode($userId, $code)) {
            $this->auditService->log('2fa_recovery_used', $userId, [
                'code_prefix' => substr($code, 0, 4) . '****',
            ], $ipAddress, $userAgent);

            // Strategic log: recovery code used (TOTP path)
            log_warning('security', '2FA recovery code used', [
                'user_id' => $userId,
                'ip' => $ipAddress,
                'method' => 'totp',
            ]);

            return $this->completeLogin($user, $ipAddress, $userAgent);
        }

        $this->auditService->log('2fa_failed', $userId, [], $ipAddress, $userAgent);

        // Strategic log: 2FA verification failed
        log_warning('security', '2FA verification failed', [
            'user_id' => $userId,
            'ip' => $ipAddress,
            'method' => $method,
        ]);

        return [
            'success' => false,
            'user' => null,
            'error' => 'Invalid 2FA code',
        ];
    }

    /**
     * Complete the login process
     */
    private function completeLogin(array $user, string $ipAddress, ?string $userAgent): array
    {
        // Reset failed attempts
        $this->resetFailedAttempts($user['id']);

        // Update last login
        $this->updateLastLogin($user['id'], $ipAddress);

        // Create session
        $sessionId = $this->sessionService->create($user['id'], $ipAddress, $userAgent);

        // Audit log
        $this->auditService->log('login', $user['id'], [
            'session_id' => substr($sessionId, 0, 16) . '...',
        ], $ipAddress, $userAgent);

        // Strategic security log for successful login
        log_info('security', 'User logged in successfully', [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'ip' => $ipAddress,
            'session_id' => substr($sessionId, 0, 16) . '...',
            '2fa_verified' => $user['two_factor_enabled'] ? 'yes' : 'not_required',
        ]);

        $this->logger->info('User logged in', [
            'user_id' => $user['id'],
            'email' => $user['email'],
        ]);

        return [
            'success' => true,
            'user' => $this->sanitizeUser($user),
            'session_id' => $sessionId,
            'error' => null,
            'requires_2fa' => false,
        ];
    }

    /**
     * Create session for a user directly (used for recovery bypass)
     *
     * This method bypasses normal authentication flow and should only be used
     * for emergency recovery after the recovery token has been validated.
     *
     * @param int $userId User ID
     * @param string $ipAddress Client IP address
     * @param string|null $userAgent Client user agent
     * @param bool $isRecoveryLogin Whether this is a recovery login (audit tracking)
     * @return array{success: bool, session_id?: string, error?: string}
     */
    public function createSessionForUser(
        int $userId,
        string $ipAddress,
        ?string $userAgent = null,
        bool $isRecoveryLogin = false
    ): array {
        $user = $this->findUserById($userId);

        if ($user === null) {
            return ['success' => false, 'error' => 'User not found'];
        }

        if (!$user['is_active']) {
            return ['success' => false, 'error' => 'Account is disabled'];
        }

        // Reset failed attempts (recovery access should reset lockout)
        $this->resetFailedAttempts($userId);

        // Update last login
        $this->updateLastLogin($userId, $ipAddress);

        // Create session
        $sessionId = $this->sessionService->create($userId, $ipAddress, $userAgent);

        // Audit log with recovery context
        $this->auditService->log(
            $isRecoveryLogin ? 'recovery_login' : 'login',
            $userId,
            [
                'session_id' => substr($sessionId, 0, 16) . '...',
                'bypass_2fa' => $isRecoveryLogin,
            ],
            $ipAddress,
            $userAgent
        );

        $this->logger->info('Session created for user', [
            'user_id' => $userId,
            'email' => $user['email'],
            'is_recovery' => $isRecoveryLogin,
        ]);

        // Strategic security logs
        if ($isRecoveryLogin) {
            // Recovery login bypasses 2FA - log as warning
            log_warning('security', 'Recovery login used - 2FA bypassed', [
                'user_id' => $userId,
                'email' => $user['email'],
                'ip' => $ipAddress,
                'session_id' => substr($sessionId, 0, 16) . '...',
            ]);
        } else {
            // Direct session creation (e.g., API token, SSO)
            log_info('security', 'User session created directly', [
                'user_id' => $userId,
                'email' => $user['email'],
                'ip' => $ipAddress,
                'session_id' => substr($sessionId, 0, 16) . '...',
            ]);
        }

        return [
            'success' => true,
            'session_id' => $sessionId,
            'user' => $this->sanitizeUser($user),
        ];
    }

    /**
     * Logout user
     */
    public function logout(string $sessionId, string $ipAddress, ?string $userAgent = null): bool
    {
        $session = $this->sessionService->get($sessionId);

        if ($session === null) {
            return false;
        }

        $userId = $session['user_id'];

        // Destroy session
        $this->sessionService->destroy($sessionId);

        // Audit log
        $this->auditService->log('logout', $userId, [], $ipAddress, $userAgent);

        // Strategic security log for logout
        log_info('security', 'User logged out', [
            'user_id' => $userId,
            'ip' => $ipAddress,
            'session_id' => substr($sessionId, 0, 16) . '...',
        ]);

        $this->logger->info('User logged out', ['user_id' => $userId]);

        return true;
    }

    /**
     * Hash a password using Argon2id
     */
    public function hashPassword(string $password): string
    {
        $hash = password_hash($password, self::PASSWORD_ALGO, self::PASSWORD_OPTIONS);

        if ($hash === false) {
            throw new RuntimeException('Failed to hash password');
        }

        return $hash;
    }

    /**
     * Change user password
     */
    public function changePassword(
        int $userId,
        string $currentPassword,
        string $newPassword,
        string $ipAddress,
        ?string $userAgent = null
    ): bool {
        $user = $this->findUserById($userId);

        if ($user === null) {
            return false;
        }

        // Verify current password
        if (!password_verify($currentPassword, $user['password_hash'])) {
            $this->auditService->log('password_change_failed', $userId, [
                'reason' => 'invalid_current_password',
            ], $ipAddress, $userAgent);

            // Strategic log: password change with wrong current password
            log_warning('security', 'Password change failed - invalid current password', [
                'user_id' => $userId,
                'ip' => $ipAddress,
            ]);

            return false;
        }

        // Update password
        $this->db->execute(
            'UPDATE admin_users SET password_hash = ?, updated_at = NOW() WHERE id = ?',
            [$this->hashPassword($newPassword), $userId]
        );

        // Strategic log: password changed via changePassword()
        log_warning('security', 'Password changed successfully', [
            'user_id' => $userId,
            'ip' => $ipAddress,
            'method' => 'changePassword',
        ]);

        // Invalidate all other sessions
        $this->sessionService->destroyAllExcept($userId, null);

        // Audit log
        $this->auditService->log('password_change', $userId, [], $ipAddress, $userAgent);

        $this->logger->info('Password changed', ['user_id' => $userId]);

        return true;
    }

    /**
     * Setup 2FA for user
     *
     * SECURITY: The TOTP secret is encrypted with AES-256-GCM before storage.
     * Recovery codes are hashed with bcrypt.
     *
     * @return array{secret: string, qr_uri: string, recovery_codes: array<string>}
     */
    public function setup2FA(int $userId): array
    {
        if ($this->encryptionService === null) {
            throw new RuntimeException('Encryption service not configured. Set APP_KEY in .env');
        }

        // Generate TOTP secret (160 bits = 32 chars base32)
        $secret = $this->generateTOTPSecret();

        // Generate recovery codes (hashed with bcrypt)
        $recoveryCodes = $this->generateRecoveryCodes();
        $hashedCodes = array_map(fn($code) => password_hash($code, PASSWORD_BCRYPT), $recoveryCodes);

        $user = $this->findUserById($userId);
        $issuer = 'AdminPanel';
        $label = urlencode($user['email']);

        // Generate QR code URI
        $qrUri = "otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";

        // SECURITY: Encrypt the TOTP secret before storage
        $encryptedSecret = $this->encryptionService->encrypt($secret);

        // Store encrypted secret and hashed recovery codes
        $this->db->execute(
            'UPDATE admin_users SET two_factor_secret = ?, two_factor_recovery_codes = ? WHERE id = ?',
            [$encryptedSecret, json_encode($hashedCodes), $userId]
        );

        $this->logger->info('2FA setup initiated', ['user_id' => $userId]);

        return [
            'secret' => $secret,
            'qr_uri' => $qrUri,
            'recovery_codes' => $recoveryCodes,
        ];
    }

    /**
     * Confirm and enable 2FA
     *
     * SECURITY: Decrypts the stored secret to verify the TOTP code.
     */
    public function enable2FA(int $userId, string $code, string $ipAddress, ?string $userAgent = null): bool
    {
        $user = $this->findUserById($userId);

        if ($user === null || empty($user['two_factor_secret'])) {
            return false;
        }

        // Decrypt the stored secret
        $secret = $this->decryptTwoFactorSecret($user['two_factor_secret']);
        if ($secret === null) {
            $this->logger->error('Failed to decrypt 2FA secret', ['user_id' => $userId]);
            return false;
        }

        // Verify code before enabling
        if (!$this->verifyTOTP($secret, $code)) {
            return false;
        }

        $this->db->execute(
            'UPDATE admin_users SET two_factor_enabled = true WHERE id = ?',
            [$userId]
        );

        $this->auditService->log('2fa_enable', $userId, [], $ipAddress, $userAgent);

        return true;
    }

    /**
     * Disable 2FA
     */
    public function disable2FA(int $userId, string $password, string $ipAddress, ?string $userAgent = null): bool
    {
        $user = $this->findUserById($userId);

        if ($user === null) {
            return false;
        }

        // Require password confirmation
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }

        $this->db->execute(
            'UPDATE admin_users SET two_factor_enabled = false, two_factor_secret = NULL, two_factor_recovery_codes = NULL WHERE id = ?',
            [$userId]
        );

        $this->auditService->log('2fa_disable', $userId, [], $ipAddress, $userAgent);

        return true;
    }

    /**
     * Find user by email
     */
    private function findUserByEmail(string $email): ?array
    {
        $users = $this->db->query(
            'SELECT * FROM admin_users WHERE email = ?',
            [$email]
        );

        return $users[0] ?? null;
    }

    /**
     * Find user by ID
     */
    private function findUserById(int $id): ?array
    {
        $users = $this->db->query(
            'SELECT * FROM admin_users WHERE id = ?',
            [$id]
        );

        return $users[0] ?? null;
    }

    /**
     * Check if account is locked
     */
    private function isAccountLocked(array $user): bool
    {
        if ($user['locked_until'] === null) {
            return false;
        }

        $lockedUntil = new DateTimeImmutable($user['locked_until']);
        return $lockedUntil > new DateTimeImmutable();
    }

    /**
     * Record failed login attempt
     */
    private function recordFailedAttempt(int $userId, string $ipAddress, ?string $userAgent): void
    {
        // Increment failed attempts
        $this->db->execute(
            'UPDATE admin_users SET failed_login_attempts = failed_login_attempts + 1 WHERE id = ?',
            [$userId]
        );

        // Get updated count
        $rows = $this->db->query(
            'SELECT failed_login_attempts FROM admin_users WHERE id = ?',
            [$userId]
        );
        $attempts = (int) ($rows[0]['failed_login_attempts'] ?? 0);

        // Lock account if threshold exceeded
        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            // Progressive lockout (exponential backoff)
            $lockoutMinutes = self::LOCKOUT_DURATION_MINUTES * pow(self::LOCKOUT_MULTIPLIER, $attempts - self::MAX_FAILED_ATTEMPTS);
            $lockoutMinutes = min($lockoutMinutes, 1440); // Max 24 hours

            $this->db->execute(
                "UPDATE admin_users SET locked_until = NOW() + INTERVAL '" . (int) $lockoutMinutes . " minutes' WHERE id = ?",
                [$userId]
            );

            $this->auditService->log('account_lock', $userId, [
                'attempts' => $attempts,
                'lockout_minutes' => $lockoutMinutes,
            ], $ipAddress, $userAgent);

            $this->logger->warning('Account locked due to failed attempts', [
                'user_id' => $userId,
                'attempts' => $attempts,
                'lockout_minutes' => $lockoutMinutes,
            ]);

            // Strategic log: account locked (potential brute force)
            log_error('security', 'Account locked due to excessive failed attempts', [
                'user_id' => $userId,
                'ip' => $ipAddress,
                'attempts' => $attempts,
                'lockout_minutes' => $lockoutMinutes,
            ]);
        }

        $this->auditService->log('login_failed', $userId, [
            'reason' => 'invalid_password',
            'attempts' => $attempts,
        ], $ipAddress, $userAgent);
    }

    /**
     * Reset failed login attempts
     */
    private function resetFailedAttempts(int $userId): void
    {
        $this->db->execute(
            'UPDATE admin_users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?',
            [$userId]
        );
    }

    /**
     * Update last login timestamp
     */
    private function updateLastLogin(int $userId, string $ipAddress): void
    {
        $this->db->execute(
            'UPDATE admin_users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?',
            [$ipAddress, $userId]
        );
    }

    /**
     * Update password hash (for rehashing)
     */
    private function updatePasswordHash(int $userId, string $password): void
    {
        $this->db->execute(
            'UPDATE admin_users SET password_hash = ? WHERE id = ?',
            [$this->hashPassword($password), $userId]
        );

        // Strategic log: password rehashed (security upgrade)
        log_info('security', 'Password rehashed (algo upgrade)', [
            'user_id' => $userId,
            'method' => 'updatePasswordHash',
        ]);

        $this->logger->info('Password rehashed for user', ['user_id' => $userId]);
    }

    /**
     * Verify TOTP code
     */
    private function verifyTOTP(string $secret, string $code): bool
    {
        // Allow 1 step tolerance (30 seconds before/after)
        $timestamp = time();
        $steps = [-1, 0, 1];

        foreach ($steps as $step) {
            $calculatedCode = $this->generateTOTP($secret, $timestamp + ($step * 30));
            if (hash_equals($calculatedCode, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate TOTP code
     */
    private function generateTOTP(string $secret, int $timestamp): string
    {
        $counter = floor($timestamp / 30);
        $secretBytes = $this->base32Decode($secret);

        $counterBytes = pack('J', $counter);
        $hash = hash_hmac('sha1', $counterBytes, $secretBytes, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $binary % 1000000;

        return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Generate TOTP secret
     */
    private function generateTOTPSecret(): string
    {
        $bytes = random_bytes(20); // 160 bits
        return $this->base32Encode($bytes);
    }

    /**
     * Generate recovery codes
     *
     * @return array<string>
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4))) . '-' . strtoupper(bin2hex(random_bytes(4)));
        }
        return $codes;
    }

    /**
     * Verify recovery code
     */
    private function verifyRecoveryCode(int $userId, string $code): bool
    {
        $rows = $this->db->query(
            'SELECT two_factor_recovery_codes FROM admin_users WHERE id = ?',
            [$userId]
        );
        $codesJson = $rows[0]['two_factor_recovery_codes'] ?? null;

        if (!$codesJson) {
            return false;
        }

        $hashedCodes = json_decode($codesJson, true);

        foreach ($hashedCodes as $index => $hashedCode) {
            if (password_verify($code, $hashedCode)) {
                // Remove used code
                unset($hashedCodes[$index]);
                $hashedCodes = array_values($hashedCodes);

                $this->db->execute(
                    'UPDATE admin_users SET two_factor_recovery_codes = ? WHERE id = ?',
                    [json_encode($hashedCodes), $userId]
                );

                return true;
            }
        }

        return false;
    }

    /**
     * Base32 encode
     */
    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';

        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($binary, 5);
        $result = '';

        foreach ($chunks as $chunk) {
            $chunk = str_pad($chunk, 5, '0');
            $result .= $alphabet[bindec($chunk)];
        }

        return $result;
    }

    /**
     * Base32 decode
     */
    private function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $binary = '';

        foreach (str_split(strtoupper($data)) as $char) {
            $index = strpos($alphabet, $char);
            if ($index !== false) {
                $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
            }
        }

        $bytes = '';
        foreach (str_split($binary, 8) as $byte) {
            if (strlen($byte) === 8) {
                $bytes .= chr(bindec($byte));
            }
        }

        return $bytes;
    }

    /**
     * Remove sensitive data from user array
     */
    private function sanitizeUser(array $user): array
    {
        unset(
            $user['password_hash'],
            $user['two_factor_secret'],
            $user['two_factor_recovery_codes'],
            $user['password_reset_token'],
            $user['password_reset_expires_at']
        );

        return $user;
    }

    // ========================================================================
    // Two-Factor Secret Encryption
    // ========================================================================

    /**
     * Decrypt 2FA secret from database
     *
     * Handles both encrypted secrets (new) and plaintext secrets (legacy).
     * If a plaintext secret is detected, it will be encrypted on next access.
     *
     * @param string $storedSecret The stored (potentially encrypted) secret
     * @return string|null The plaintext secret, or null if decryption fails
     */
    private function decryptTwoFactorSecret(string $storedSecret): ?string
    {
        // If encryption service not available, assume plaintext (legacy)
        if ($this->encryptionService === null) {
            $this->logger->warning('EncryptionService not available, using plaintext secret');
            return $storedSecret;
        }

        // Try to decrypt
        $decrypted = $this->encryptionService->decrypt($storedSecret);

        if ($decrypted !== null) {
            return $decrypted;
        }

        // Decryption failed - might be a legacy plaintext secret
        // Check if it looks like a valid base32 TOTP secret (not encrypted)
        if (preg_match('/^[A-Z2-7]{16,32}$/', $storedSecret)) {
            $this->logger->info('Legacy plaintext 2FA secret detected');
            return $storedSecret;
        }

        $this->logger->error('Failed to decrypt 2FA secret and not a valid plaintext secret');
        return null;
    }

    // ========================================================================
    // Password Reset with Hashed Token
    // ========================================================================

    /**
     * Request password reset
     *
     * SECURITY: The reset token is hashed with Argon2id before storage.
     * Only the hash is stored; the plaintext token is returned once.
     *
     * @param string $email User email
     * @param string $ipAddress Client IP
     * @return array{success: bool, token?: string, error?: string}
     */
    public function requestPasswordReset(string $email, string $ipAddress): array
    {
        $email = strtolower(trim($email));
        $user = $this->findUserByEmail($email);

        if ($user === null) {
            // Don't reveal if user exists (timing attack protection)
            password_hash('dummy', self::PASSWORD_ALGO, self::PASSWORD_OPTIONS);

            $this->auditService->log('password_reset_request_unknown_email', null, [
                'email' => $email,
            ], $ipAddress);

            // Return success to prevent email enumeration
            return ['success' => true];
        }

        // Check if account is locked or disabled
        if (!$user['is_active']) {
            $this->auditService->log('password_reset_denied_inactive', $user['id'], [], $ipAddress);
            return ['success' => true]; // Don't reveal account status
        }

        // Generate secure token (256 bits)
        $plainToken = bin2hex(random_bytes(32));

        // Hash the token for storage (using Argon2id)
        $tokenHash = password_hash($plainToken, self::PASSWORD_ALGO, self::PASSWORD_OPTIONS);

        // Calculate expiry
        $expiresAt = (new DateTimeImmutable())
            ->modify('+' . self::PASSWORD_RESET_EXPIRY_HOURS . ' hours')
            ->format('Y-m-d H:i:s');

        // Store hashed token
        $this->db->execute(
            'UPDATE admin_users SET password_reset_token = ?, password_reset_expires_at = ? WHERE id = ?',
            [$tokenHash, $expiresAt, $user['id']]
        );

        $this->auditService->log('password_reset_requested', $user['id'], [
            'expires_at' => $expiresAt,
        ], $ipAddress);

        $this->logger->info('Password reset requested', [
            'user_id' => $user['id'],
            'email' => $email,
        ]);

        return [
            'success' => true,
            'token' => $plainToken,
            'user_id' => $user['id'],
            'user_email' => $user['email'],
            'user_name' => $user['name'],
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Verify password reset token
     *
     * @param string $token The plaintext token to verify
     * @return array{valid: bool, user_id?: int, error?: string}
     */
    public function verifyPasswordResetToken(string $token): array
    {
        // Get all users with non-expired reset tokens
        $users = $this->db->query(
            'SELECT id, password_reset_token, password_reset_expires_at FROM admin_users WHERE password_reset_token IS NOT NULL AND password_reset_expires_at > NOW()'
        );

        foreach ($users as $user) {
            // Verify token hash
            if (password_verify($token, $user['password_reset_token'])) {
                return [
                    'valid' => true,
                    'user_id' => (int) $user['id'],
                ];
            }
        }

        return [
            'valid' => false,
            'error' => 'Invalid or expired reset token',
        ];
    }

    /**
     * Reset password using token
     *
     * @param string $token The plaintext reset token
     * @param string $newPassword The new password
     * @param string $ipAddress Client IP
     * @param string|null $userAgent Client user agent
     * @return array{success: bool, error?: string}
     */
    public function resetPasswordWithToken(
        string $token,
        string $newPassword,
        string $ipAddress,
        ?string $userAgent = null
    ): array {
        // Verify token first
        $verification = $this->verifyPasswordResetToken($token);

        if (!$verification['valid']) {
            $this->auditService->log('password_reset_invalid_token', null, [
                'reason' => 'invalid_or_expired',
            ], $ipAddress, $userAgent);

            // Strategic log: invalid reset token attempt
            log_warning('security', 'Invalid password reset token used', [
                'ip' => $ipAddress,
                'token_prefix' => substr($token, 0, 8) . '...',
            ]);

            return [
                'success' => false,
                'error' => $verification['error'],
            ];
        }

        $userId = $verification['user_id'];

        // Update password and clear reset token
        $this->db->execute(
            'UPDATE admin_users SET password_hash = ?, password_reset_token = NULL, password_reset_expires_at = NULL, updated_at = NOW() WHERE id = ?',
            [$this->hashPassword($newPassword), $userId]
        );

        // Strategic log: password reset via token
        log_warning('security', 'Password reset via token', [
            'user_id' => $userId,
            'ip' => $ipAddress,
            'method' => 'resetPassword',
        ]);

        // Invalidate all sessions
        $this->sessionService->destroyAllExcept($userId, null);

        // Audit log
        $this->auditService->log('password_reset_completed', $userId, [], $ipAddress, $userAgent);

        $this->logger->info('Password reset completed', ['user_id' => $userId]);

        return ['success' => true];
    }

    /**
     * Migrate legacy plaintext 2FA secrets to encrypted format
     *
     * Run this once after deploying encryption support.
     *
     * @return array{migrated: int, failed: int}
     */
    public function migrateLegacy2FASecrets(): array
    {
        if ($this->encryptionService === null) {
            throw new RuntimeException('EncryptionService required for migration');
        }

        $users = $this->db->query(
            "SELECT id, two_factor_secret FROM admin_users WHERE two_factor_secret IS NOT NULL AND two_factor_secret != ''"
        );

        $migrated = 0;
        $failed = 0;

        foreach ($users as $user) {
            $secret = $user['two_factor_secret'];

            // Skip if already encrypted (base64 format)
            if (!preg_match('/^[A-Z2-7]{16,32}$/', $secret)) {
                continue; // Already encrypted or invalid
            }

            try {
                $encrypted = $this->encryptionService->encrypt($secret);

                $this->db->execute(
                    'UPDATE admin_users SET two_factor_secret = ? WHERE id = ?',
                    [$encrypted, $user['id']]
                );

                $migrated++;
                $this->logger->info('Migrated 2FA secret', ['user_id' => $user['id']]);
            } catch (\Exception $e) {
                $failed++;
                $this->logger->error('Failed to migrate 2FA secret', [
                    'user_id' => $user['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['migrated' => $migrated, 'failed' => $failed];
    }
}
