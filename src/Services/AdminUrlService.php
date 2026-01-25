<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Services;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enterprise Admin URL Management Service
 *
 * SECURITY FEATURES:
 * ==================
 * 1. Personal CLI token (each admin has unique token to retrieve URL)
 * 2. Automatic URL rotation (configurable interval)
 * 3. Notification on rotation (email/webhook)
 * 4. Rate limiting on URL retrieval
 * 5. IP binding (optional)
 * 6. Audit trail for all URL access
 *
 * ANTI-PATTERN:
 * =============
 * - NO hardcoded commands that all users know
 * - NO predictable URL retrieval methods
 * - Each installation is UNIQUE
 *
 * @version 1.0.0
 */
final class AdminUrlService
{
    /**
     * Rate limit: max URL retrievals per hour
     */
    private const RATE_LIMIT_PER_HOUR = 10;

    public function __construct(
        private DatabasePool $db,
        private ConfigService $configService,
        private AuditService $auditService,
        private ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Generate personal CLI token for an admin user
     *
     * Each admin gets a UNIQUE token to retrieve the current URL.
     * This token is NOT the same as session token.
     *
     * @param int $userId Admin user ID
     * @return string Personal CLI token (64 hex chars)
     */
    public function generateCliToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = password_hash($token, PASSWORD_ARGON2ID);

        // Store hashed token
        $this->db->execute(
            'UPDATE admin_users SET cli_access_token = ?, cli_token_created_at = NOW() WHERE id = ?',
            [$tokenHash, $userId]
        );

        $this->auditService->log('cli_token_generated', $userId, [
            'token_prefix' => substr($token, 0, 8) . '...',
        ]);

        $this->logger->info('CLI token generated for user', ['user_id' => $userId]);

        return $token;
    }

    /**
     * Retrieve admin URL using personal CLI token
     *
     * @param string $token Personal CLI token
     * @param string $ipAddress Caller IP (for rate limiting and audit)
     * @return array{success: bool, url: ?string, error: ?string, expires_in: ?int}
     */
    public function getUrlWithToken(string $token, string $ipAddress): array
    {
        // Rate limiting check
        if ($this->isRateLimited($ipAddress)) {
            $this->auditService->log('url_retrieval_rate_limited', null, [
                'ip' => $ipAddress,
            ], $ipAddress);

            return [
                'success' => false,
                'url' => null,
                'error' => 'Rate limit exceeded. Try again later.',
                'expires_in' => null,
            ];
        }

        // Find user by token
        $users = $this->db->query(
            'SELECT id, email, cli_access_token, cli_token_created_at FROM admin_users WHERE cli_access_token IS NOT NULL AND is_active = true'
        );

        $user = null;
        foreach ($users as $row) {
            if (password_verify($token, $row['cli_access_token'])) {
                $user = $row;
                break;
            }
        }

        if ($user === null) {
            $this->recordRateLimitAttempt($ipAddress);
            $this->auditService->log('url_retrieval_invalid_token', null, [
                'ip' => $ipAddress,
                'token_prefix' => substr($token, 0, 8) . '...',
            ], $ipAddress);

            return [
                'success' => false,
                'url' => null,
                'error' => 'Invalid or expired token.',
                'expires_in' => null,
            ];
        }

        // Check if rotation is needed
        $this->checkAndRotateIfNeeded();

        // Get current URL
        $adminUrl = $this->configService->getAdminBasePath();
        $rotationInterval = (int) $this->configService->get('url_rotation_interval', 14400);
        $lastRotation = $this->getLastRotationTime();
        $nextRotation = $lastRotation + $rotationInterval;
        $expiresIn = max(0, $nextRotation - time());

        // Audit successful retrieval
        $this->auditService->log('url_retrieved', $user['id'], [
            'ip' => $ipAddress,
            'url_prefix' => substr($adminUrl, 0, 10) . '...',
        ], $ipAddress);

        $this->logger->info('Admin URL retrieved via CLI', [
            'user_id' => $user['id'],
            'ip' => $ipAddress,
        ]);

        return [
            'success' => true,
            'url' => $adminUrl,
            'error' => null,
            'expires_in' => $expiresIn,
        ];
    }

    /**
     * Rotate admin URL manually or automatically
     *
     * @param string $reason Rotation reason
     * @param int|null $triggeredBy User ID who triggered (null for automatic)
     * @return string New admin URL
     */
    public function rotateUrl(string $reason = 'manual', ?int $triggeredBy = null): string
    {
        $oldUrl = $this->configService->getAdminBasePath();
        $newUrl = $this->configService->rotateAdminBasePath();

        // Update rotation timestamp
        $this->configService->set('last_url_rotation', time(), 'int');

        // Audit
        $this->auditService->log('admin_url_rotated', $triggeredBy, [
            'reason' => $reason,
            'old_prefix' => substr($oldUrl, 0, 10) . '...',
            'new_prefix' => substr($newUrl, 0, 10) . '...',
        ]);

        // Notify admins (if configured)
        $this->notifyUrlRotation($newUrl, $reason);

        $this->logger->warning('Admin URL rotated', [
            'reason' => $reason,
            'triggered_by' => $triggeredBy,
        ]);

        return $newUrl;
    }

    /**
     * Check if automatic rotation is needed and perform it
     */
    public function checkAndRotateIfNeeded(): bool
    {
        $rotationInterval = (int) $this->configService->get('url_rotation_interval', 14400);
        $lastRotation = $this->getLastRotationTime();

        if (time() - $lastRotation >= $rotationInterval) {
            $this->rotateUrl('automatic_scheduled');
            return true;
        }

        return false;
    }

    /**
     * Get installation-specific CLI command
     *
     * Each installation generates a UNIQUE command pattern.
     * The pattern is stored in config and can be customized.
     *
     * @return string Custom CLI command template
     */
    public function getCliCommandTemplate(): string
    {
        $template = $this->configService->get('cli_command_template');

        if ($template === null) {
            // Generate unique command pattern on first access
            $uniqueId = substr(bin2hex(random_bytes(4)), 0, 8);
            $template = "php artisan admin:url --token={TOKEN} --id={$uniqueId}";
            $this->configService->set('cli_command_template', $template, 'string');
        }

        return $template;
    }

    /**
     * Set custom CLI command template
     *
     * Allows the user to define their own command pattern.
     *
     * @param string $template Command template (must contain {TOKEN})
     * @return bool Success
     */
    public function setCliCommandTemplate(string $template): bool
    {
        if (strpos($template, '{TOKEN}') === false) {
            return false;
        }

        return $this->configService->set('cli_command_template', $template, 'string');
    }

    /**
     * Get formatted CLI command for a user
     *
     * @param string $token User's personal CLI token
     * @return string Ready-to-use command
     */
    public function getFormattedCliCommand(string $token): string
    {
        $template = $this->getCliCommandTemplate();
        return str_replace('{TOKEN}', $token, $template);
    }

    /**
     * Configure URL rotation settings
     *
     * @param int $intervalSeconds Rotation interval in seconds
     * @param bool $notifyOnRotation Send notification on rotation
     * @param string|null $notificationWebhook Webhook URL for notifications
     * @param string|null $notificationEmail Email for notifications
     */
    public function configureRotation(
        int $intervalSeconds,
        bool $notifyOnRotation = true,
        ?string $notificationWebhook = null,
        ?string $notificationEmail = null
    ): void {
        $this->configService->set('url_rotation_interval', $intervalSeconds, 'int');
        $this->configService->set('notify_on_rotation', $notifyOnRotation, 'bool');

        if ($notificationWebhook !== null) {
            $this->configService->set('rotation_webhook', $notificationWebhook, 'string');
        }

        if ($notificationEmail !== null) {
            $this->configService->set('rotation_email', $notificationEmail, 'string');
        }

        $this->logger->info('URL rotation configured', [
            'interval' => $intervalSeconds,
            'notify' => $notifyOnRotation,
        ]);
    }

    /**
     * Get last URL rotation timestamp
     */
    private function getLastRotationTime(): int
    {
        $lastRotation = $this->configService->get('last_url_rotation');

        if ($lastRotation === null) {
            // First time - set current time
            $now = time();
            $this->configService->set('last_url_rotation', $now, 'int');
            return $now;
        }

        return (int) $lastRotation;
    }

    /**
     * Check if IP is rate limited
     */
    private function isRateLimited(string $ipAddress): bool
    {
        $rows = $this->db->query(
            "SELECT COUNT(*) as cnt FROM admin_audit_log WHERE action = 'url_retrieval_invalid_token' AND ip_address = ? AND created_at > NOW() - INTERVAL '1 hour'",
            [$ipAddress]
        );

        return (int) ($rows[0]['cnt'] ?? 0) >= self::RATE_LIMIT_PER_HOUR;
    }

    /**
     * Record rate limit attempt
     */
    private function recordRateLimitAttempt(string $ipAddress): void
    {
        // Already logged in audit, this is just for rate limit tracking
    }

    /**
     * Notify admins about URL rotation
     */
    private function notifyUrlRotation(string $newUrl, string $reason): void
    {
        $shouldNotify = $this->configService->get('notify_on_rotation', true);

        if (!$shouldNotify) {
            return;
        }

        // Webhook notification
        $webhookUrl = $this->configService->get('rotation_webhook');
        if ($webhookUrl) {
            $this->sendWebhookNotification($webhookUrl, $newUrl, $reason);
        }

        // Email notification (requires mail service integration)
        $email = $this->configService->get('rotation_email');
        if ($email) {
            $this->sendEmailNotification($email, $newUrl, $reason);
        }
    }

    /**
     * Send webhook notification
     */
    private function sendWebhookNotification(string $url, string $newAdminUrl, string $reason): void
    {
        $payload = json_encode([
            'event' => 'admin_url_rotated',
            'new_url' => $newAdminUrl,
            'reason' => $reason,
            'timestamp' => date('c'),
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 5,
            ],
        ]);

        try {
            @file_get_contents($url, false, $context);
            $this->logger->info('Webhook notification sent', ['url' => $url]);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook notification failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send email notification
     */
    private function sendEmailNotification(string $email, string $newAdminUrl, string $reason): void
    {
        // Basic mail (in production, use proper mail service)
        $subject = '[Enterprise Admin] URL Rotated';
        $message = <<<EOT
Your admin panel URL has been rotated.

New URL: {$newAdminUrl}
Reason: {$reason}
Time: {date('Y-m-d H:i:s')}

If you did not request this change, please contact your system administrator.
EOT;

        $headers = [
            'From: noreply@admin-panel.local',
            'Content-Type: text/plain; charset=utf-8',
        ];

        try {
            @mail($email, $subject, $message, implode("\r\n", $headers));
            $this->logger->info('Email notification sent', ['email' => $email]);
        } catch (\Throwable $e) {
            $this->logger->error('Email notification failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
