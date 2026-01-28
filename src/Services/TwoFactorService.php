<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Services;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;

/**
 * Enterprise Two-Factor Authentication Service
 *
 * Supports:
 * - TOTP (Time-based One-Time Password) via authenticator apps
 * - OTP via Email (Mailhog for development)
 * - OTP via Telegram
 * - OTP via Discord
 * - OTP via Slack
 *
 * @version 1.0.0
 */
final class TwoFactorService
{
    /**
     * OTP code length
     */
    private const CODE_LENGTH = 6;

    /**
     * OTP expiry in seconds (5 minutes)
     */
    private const CODE_EXPIRY = 300;

    /**
     * 2FA Methods
     */
    public const METHOD_TOTP = 'totp';
    public const METHOD_EMAIL = 'email';
    public const METHOD_TELEGRAM = 'telegram';
    public const METHOD_DISCORD = 'discord';
    public const METHOD_SLACK = 'slack';

    public function __construct(
        private DatabasePool $db,
        private NotificationService $notificationService,
        private AuditService $auditService,
        private ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Generate and send OTP code
     *
     * @param int $userId User ID
     * @param string|null $method 2FA method (null = use user's preferred method)
     * @return array{success: bool, method: string, error: ?string, expires_in: int}
     */
    public function sendCode(int $userId, ?string $method = null): array
    {
        // Get user's 2FA configuration
        $user = $this->getUser($userId);

        if ($user === null) {
            return ['success' => false, 'method' => '', 'error' => 'User not found', 'expires_in' => 0];
        }

        // Determine method
        $method = $method ?? $user['two_factor_method'] ?? self::METHOD_EMAIL;

        // For TOTP, no code needs to be sent (user generates from authenticator app)
        if ($method === self::METHOD_TOTP) {
            if (empty($user['two_factor_secret'])) {
                return ['success' => false, 'method' => $method, 'error' => '2FA not configured', 'expires_in' => 0];
            }
            return ['success' => true, 'method' => $method, 'error' => null, 'expires_in' => 30];
        }

        // Generate OTP code
        $code = $this->generateCode();

        // Store code in database
        $this->storeCode($userId, $code, $method);

        // Send via notification service
        $channel = match ($method) {
            self::METHOD_EMAIL => NotificationService::CHANNEL_EMAIL,
            self::METHOD_TELEGRAM => NotificationService::CHANNEL_TELEGRAM,
            self::METHOD_DISCORD => NotificationService::CHANNEL_DISCORD,
            self::METHOD_SLACK => NotificationService::CHANNEL_SLACK,
            default => NotificationService::CHANNEL_EMAIL,
        };

        $result = $this->notificationService->send2FACode($userId, $code, $channel);

        if ($result['success']) {
            $this->auditService->log('2fa_code_sent', $userId, [
                'method' => $method,
                'channel' => $channel,
            ]);

            // Strategic log for 2FA code sent
            Logger::channel('email')->info( '2FA code sent', [
                'user_id' => $userId,
                'method' => $method,
                'channel' => $channel,
                'expires_in' => self::CODE_EXPIRY,
            ]);

            return [
                'success' => true,
                'method' => $method,
                'error' => null,
                'expires_in' => self::CODE_EXPIRY,
            ];
        }

        // Log failed 2FA send
        Logger::channel('email')->warning( '2FA code send failed', [
            'user_id' => $userId,
            'method' => $method,
            'channel' => $channel,
            'error' => $result['error'] ?? 'Unknown error',
        ]);

        return [
            'success' => false,
            'method' => $method,
            'error' => $result['error'] ?? 'Failed to send code',
            'expires_in' => 0,
        ];
    }

    /**
     * Verify OTP code
     *
     * @param int $userId User ID
     * @param string $code Code to verify
     * @param string|null $method Expected method (null = any)
     * @return array{success: bool, error: ?string}
     */
    public function verifyCode(int $userId, string $code, ?string $method = null): array
    {
        $user = $this->getUser($userId);

        if ($user === null) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Determine actual method
        $actualMethod = $method ?? $user['two_factor_method'] ?? self::METHOD_EMAIL;

        // TOTP verification
        if ($actualMethod === self::METHOD_TOTP) {
            return $this->verifyTOTP($user, $code);
        }

        // OTP verification (email, telegram, discord, slack)
        return $this->verifyOTP($userId, $code, $actualMethod);
    }

    /**
     * Verify TOTP code
     */
    private function verifyTOTP(array $user, string $code): array
    {
        if (empty($user['two_factor_secret'])) {
            return ['success' => false, 'error' => 'TOTP not configured'];
        }

        // Allow 1 step tolerance (30 seconds before/after)
        $timestamp = time();
        $steps = [-1, 0, 1];

        foreach ($steps as $step) {
            $calculatedCode = $this->generateTOTP($user['two_factor_secret'], $timestamp + ($step * 30));
            if (hash_equals($calculatedCode, $code)) {
                $this->auditService->log('2fa_verified', $user['id'], ['method' => self::METHOD_TOTP]);
                return ['success' => true, 'error' => null];
            }
        }

        $this->auditService->log('2fa_failed', $user['id'], ['method' => self::METHOD_TOTP]);
        return ['success' => false, 'error' => 'Invalid code'];
    }

    /**
     * Verify OTP code from database
     */
    private function verifyOTP(int $userId, string $code, string $method): array
    {
        // Sanitize code (remove any spaces)
        $code = preg_replace('/\s+/', '', $code);

        // Find valid code
        $codeRecords = $this->db->query(
            'SELECT id FROM admin_2fa_codes WHERE user_id = ? AND code = ? AND channel = ? AND expires_at > NOW() AND used_at IS NULL LIMIT 1',
            [$userId, $code, $method]
        );

        if (empty($codeRecords)) {
            $this->auditService->log('2fa_failed', $userId, ['method' => $method]);
            return ['success' => false, 'error' => 'Invalid or expired code'];
        }

        // Mark code as used
        $this->db->execute(
            'UPDATE admin_2fa_codes SET used_at = NOW() WHERE id = ?',
            [$codeRecords[0]['id']]
        );

        // Clean up old codes for this user
        $this->cleanupOldCodes($userId);

        $this->auditService->log('2fa_verified', $userId, ['method' => $method]);

        return ['success' => true, 'error' => null];
    }

    /**
     * Generate random OTP code
     */
    private function generateCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= random_int(0, 9);
        }
        return $code;
    }

    /**
     * Store OTP code in database
     */
    private function storeCode(int $userId, string $code, string $method): void
    {
        // Invalidate previous codes for this user/method
        $this->db->execute(
            'UPDATE admin_2fa_codes SET used_at = NOW() WHERE user_id = ? AND channel = ? AND used_at IS NULL',
            [$userId, $method]
        );

        // Insert new code
        $expiresAt = date('Y-m-d H:i:s', time() + self::CODE_EXPIRY);

        $this->db->execute(
            'INSERT INTO admin_2fa_codes (user_id, code, channel, expires_at) VALUES (?, ?, ?, ?)',
            [$userId, $code, $method, $expiresAt]
        );
    }

    /**
     * Clean up old codes
     */
    private function cleanupOldCodes(int $userId): void
    {
        $this->db->execute(
            'DELETE FROM admin_2fa_codes WHERE user_id = ? AND (used_at IS NOT NULL OR expires_at < NOW())',
            [$userId]
        );
    }

    /**
     * Setup TOTP for user
     *
     * @return array{secret: string, qr_uri: string, recovery_codes: array<string>}
     */
    public function setupTOTP(int $userId): array
    {
        $user = $this->getUser($userId);

        if ($user === null) {
            throw new \RuntimeException('User not found');
        }

        // Generate TOTP secret (160 bits = 32 chars base32)
        $secret = $this->generateTOTPSecret();

        // Generate recovery codes
        $recoveryCodes = $this->generateRecoveryCodes();
        $hashedCodes = array_map(fn($code) => password_hash($code, PASSWORD_BCRYPT), $recoveryCodes);

        $issuer = 'EnterpriseAdmin';
        $label = urlencode($user['email']);

        // Generate QR code URI
        $qrUri = "otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";

        // Store secret and recovery codes (not enabled yet)
        $this->db->execute(
            'UPDATE admin_users SET two_factor_secret = ?, two_factor_recovery_codes = ? WHERE id = ?',
            [$secret, json_encode($hashedCodes), $userId]
        );

        $this->logger->info('TOTP setup initiated', ['user_id' => $userId]);

        return [
            'secret' => $secret,
            'qr_uri' => $qrUri,
            'recovery_codes' => $recoveryCodes,
        ];
    }

    /**
     * Enable 2FA for user
     */
    public function enable(int $userId, string $method, ?string $verificationCode = null): bool
    {
        // For TOTP, verify the code first
        if ($method === self::METHOD_TOTP && $verificationCode !== null) {
            $result = $this->verifyCode($userId, $verificationCode, $method);
            if (!$result['success']) {
                return false;
            }
        }

        $this->db->execute(
            'UPDATE admin_users SET two_factor_enabled = true, two_factor_method = ? WHERE id = ?',
            [$method, $userId]
        );

        $this->auditService->log('2fa_enabled', $userId, ['method' => $method]);
        $this->logger->info('2FA enabled', ['user_id' => $userId, 'method' => $method]);

        return true;
    }

    /**
     * Disable 2FA for user
     */
    public function disable(int $userId, string $password): bool
    {
        $user = $this->getUser($userId);

        if ($user === null) {
            return false;
        }

        // Require password confirmation
        if (!password_verify($password, $user['password_hash'])) {
            $this->auditService->log('2fa_disable_failed', $userId, ['reason' => 'invalid_password']);
            return false;
        }

        $this->db->execute(
            'UPDATE admin_users SET two_factor_enabled = false, two_factor_secret = NULL, two_factor_recovery_codes = NULL, two_factor_method = NULL WHERE id = ?',
            [$userId]
        );

        $this->auditService->log('2fa_disabled', $userId);
        $this->logger->info('2FA disabled', ['user_id' => $userId]);

        return true;
    }

    /**
     * Get available 2FA methods for user
     */
    public function getAvailableMethods(int $userId): array
    {
        $methods = [];

        // TOTP is always available
        $methods[] = [
            'id' => self::METHOD_TOTP,
            'name' => 'Authenticator App',
            'description' => 'Use Google Authenticator, Authy, or similar apps',
            'configured' => $this->hasMethodConfigured($userId, self::METHOD_TOTP),
        ];

        // Email is available if notification service supports it
        if ($this->notificationService->isChannelEnabled(NotificationService::CHANNEL_EMAIL)) {
            $methods[] = [
                'id' => self::METHOD_EMAIL,
                'name' => 'Email',
                'description' => 'Receive codes via email',
                'configured' => true, // Email is always available for users
            ];
        }

        // Telegram
        if ($this->notificationService->isChannelEnabled(NotificationService::CHANNEL_TELEGRAM)) {
            $methods[] = [
                'id' => self::METHOD_TELEGRAM,
                'name' => 'Telegram',
                'description' => 'Receive codes via Telegram',
                'configured' => $this->hasMethodConfigured($userId, self::METHOD_TELEGRAM),
            ];
        }

        // Discord
        if ($this->notificationService->isChannelEnabled(NotificationService::CHANNEL_DISCORD)) {
            $methods[] = [
                'id' => self::METHOD_DISCORD,
                'name' => 'Discord',
                'description' => 'Receive codes via Discord',
                'configured' => $this->hasMethodConfigured($userId, self::METHOD_DISCORD),
            ];
        }

        // Slack
        if ($this->notificationService->isChannelEnabled(NotificationService::CHANNEL_SLACK)) {
            $methods[] = [
                'id' => self::METHOD_SLACK,
                'name' => 'Slack',
                'description' => 'Receive codes via Slack',
                'configured' => $this->hasMethodConfigured($userId, self::METHOD_SLACK),
            ];
        }

        return $methods;
    }

    /**
     * Check if user has a method configured
     */
    private function hasMethodConfigured(int $userId, string $method): bool
    {
        $user = $this->getUser($userId);

        if ($user === null) {
            return false;
        }

        return match ($method) {
            self::METHOD_TOTP => !empty($user['two_factor_secret']),
            self::METHOD_EMAIL => !empty($user['email']),
            self::METHOD_TELEGRAM => !empty($user['telegram_chat_id']),
            self::METHOD_DISCORD => !empty($user['discord_user_id']),
            self::METHOD_SLACK => !empty($user['slack_user_id']),
            default => false,
        };
    }

    /**
     * Get user by ID
     */
    private function getUser(int $userId): ?array
    {
        $users = $this->db->query(
            'SELECT * FROM admin_users WHERE id = ?',
            [$userId]
        );

        return $users[0] ?? null;
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
}
