<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Services;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;

/**
 * Enterprise Multi-Channel Notification Service
 *
 * Supports:
 * - Email (SMTP, Mailhog for development)
 * - Telegram
 * - Discord (Webhooks)
 * - Slack (Webhooks)
 *
 * Used for:
 * - 2FA code delivery
 * - URL rotation notifications
 * - Security alerts
 *
 * @version 1.0.0
 */
final class NotificationService
{
    /**
     * Available channels
     */
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_TELEGRAM = 'telegram';
    public const CHANNEL_DISCORD = 'discord';
    public const CHANNEL_SLACK = 'slack';

    private array $config;

    public function __construct(
        private DatabasePool $db,
        private ConfigService $configService,
        private ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->loadConfig();
    }

    /**
     * Load configuration from database and environment
     */
    private function loadConfig(): void
    {
        $this->config = [
            // Email (SMTP)
            'email' => [
                'enabled' => (bool) $this->configService->get('notification_email_enabled', true),
                'host' => getenv('SMTP_HOST') ?: $this->configService->get('smtp_host', 'localhost'),
                'port' => (int) (getenv('SMTP_PORT') ?: $this->configService->get('smtp_port', 1025)),
                'username' => getenv('SMTP_USERNAME') ?: $this->configService->get('smtp_username', ''),
                'password' => getenv('SMTP_PASSWORD') ?: $this->configService->get('smtp_password', ''),
                'encryption' => getenv('SMTP_ENCRYPTION') ?: $this->configService->get('smtp_encryption', ''), // tls, ssl, or empty
                'from_email' => getenv('SMTP_FROM_EMAIL') ?: $this->configService->get('smtp_from_email', 'admin@localhost'),
                'from_name' => getenv('SMTP_FROM_NAME') ?: $this->configService->get('smtp_from_name', 'Enterprise Admin'),
            ],

            // Telegram
            'telegram' => [
                'enabled' => (bool) $this->configService->get('notification_telegram_enabled', false),
                'bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: $this->configService->get('telegram_bot_token', ''),
            ],

            // Discord
            'discord' => [
                'enabled' => (bool) $this->configService->get('notification_discord_enabled', false),
                'webhook_url' => getenv('DISCORD_WEBHOOK_URL') ?: $this->configService->get('discord_webhook_url', ''),
            ],

            // Slack
            'slack' => [
                'enabled' => (bool) $this->configService->get('notification_slack_enabled', false),
                'webhook_url' => getenv('SLACK_WEBHOOK_URL') ?: $this->configService->get('slack_webhook_url', ''),
            ],
        ];
    }

    /**
     * Send 2FA verification code
     *
     * @param int $userId User ID
     * @param string $code 6-digit verification code
     * @param string|null $preferredChannel Preferred channel (null = use user's default)
     * @return array{success: bool, channel: string, error: ?string}
     */
    public function send2FACode(int $userId, string $code, ?string $preferredChannel = null): array
    {
        $user = $this->getUser($userId);

        if ($user === null) {
            return ['success' => false, 'channel' => '', 'error' => 'User not found'];
        }

        // Determine channel
        $channel = $preferredChannel ?? $user['notification_channel'] ?? self::CHANNEL_EMAIL;

        // Validate channel is enabled
        if (!$this->isChannelEnabled($channel)) {
            // Fallback to email
            $channel = self::CHANNEL_EMAIL;

            if (!$this->isChannelEnabled($channel)) {
                return ['success' => false, 'channel' => $channel, 'error' => 'No notification channel available'];
            }
        }

        // Get user's channel-specific address
        $address = $this->getUserChannelAddress($user, $channel);

        if ($address === null) {
            return ['success' => false, 'channel' => $channel, 'error' => "No {$channel} address configured"];
        }

        // Send notification
        $message = $this->format2FAMessage($code);
        $result = $this->sendToChannel($channel, $address, '2FA Verification Code', $message);

        if ($result['success']) {
            $this->logger->info('2FA code sent', [
                'user_id' => $userId,
                'channel' => $channel,
            ]);
        } else {
            $this->logger->error('Failed to send 2FA code', [
                'user_id' => $userId,
                'channel' => $channel,
                'error' => $result['error'],
            ]);
        }

        return $result;
    }

    /**
     * Send URL rotation notification
     */
    public function sendUrlRotationNotification(string $newUrl, string $reason): array
    {
        $results = [];

        // Get all admins who should be notified
        $users = $this->db->query(
            'SELECT id, email, name, notification_channel, telegram_chat_id, discord_user_id, slack_user_id FROM admin_users WHERE is_active = true AND notify_on_rotation = true'
        );

        $message = $this->formatUrlRotationMessage($newUrl, $reason);

        foreach ($users as $user) {
            $channel = $user['notification_channel'] ?? self::CHANNEL_EMAIL;
            $address = $this->getUserChannelAddress($user, $channel);

            if ($address !== null && $this->isChannelEnabled($channel)) {
                $result = $this->sendToChannel($channel, $address, 'Admin URL Rotated', $message);
                $results[$user['id']] = $result;
            }
        }

        return $results;
    }

    /**
     * Send security alert
     */
    public function sendSecurityAlert(int $userId, string $alertType, array $details): array
    {
        $user = $this->getUser($userId);

        if ($user === null) {
            return ['success' => false, 'channel' => '', 'error' => 'User not found'];
        }

        $channel = $user['notification_channel'] ?? self::CHANNEL_EMAIL;
        $address = $this->getUserChannelAddress($user, $channel);

        if ($address === null || !$this->isChannelEnabled($channel)) {
            return ['success' => false, 'channel' => $channel, 'error' => 'Channel not available'];
        }

        $message = $this->formatSecurityAlertMessage($alertType, $details);

        return $this->sendToChannel($channel, $address, "Security Alert: {$alertType}", $message);
    }

    /**
     * Send to specific channel
     */
    private function sendToChannel(string $channel, string $address, string $subject, string $message): array
    {
        return match ($channel) {
            self::CHANNEL_EMAIL => $this->sendEmail($address, $subject, $message),
            self::CHANNEL_TELEGRAM => $this->sendTelegram($address, $message),
            self::CHANNEL_DISCORD => $this->sendDiscord($address, $subject, $message),
            self::CHANNEL_SLACK => $this->sendSlack($address, $subject, $message),
            default => ['success' => false, 'channel' => $channel, 'error' => 'Unknown channel'],
        };
    }

    /**
     * Send email via SMTP
     */
    private function sendEmail(string $to, string $subject, string $body): array
    {
        $config = $this->config['email'];

        try {
            // Create socket connection to SMTP server
            $socket = @fsockopen(
                $config['encryption'] === 'ssl' ? "ssl://{$config['host']}" : $config['host'],
                $config['port'],
                $errno,
                $errstr,
                10
            );

            if (!$socket) {
                throw new \RuntimeException("SMTP connection failed: {$errstr} ({$errno})");
            }

            // Read greeting
            $this->smtpRead($socket);

            // EHLO
            $this->smtpCommand($socket, "EHLO localhost");

            // STARTTLS if needed
            if ($config['encryption'] === 'tls') {
                $this->smtpCommand($socket, "STARTTLS");
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->smtpCommand($socket, "EHLO localhost");
            }

            // Auth if credentials provided
            if (!empty($config['username'])) {
                $this->smtpCommand($socket, "AUTH LOGIN");
                $this->smtpCommand($socket, base64_encode($config['username']));
                $this->smtpCommand($socket, base64_encode($config['password']));
            }

            // Send email
            $this->smtpCommand($socket, "MAIL FROM:<{$config['from_email']}>");
            $this->smtpCommand($socket, "RCPT TO:<{$to}>");
            $this->smtpCommand($socket, "DATA");

            // Email content
            $headers = [
                "From: {$config['from_name']} <{$config['from_email']}>",
                "To: {$to}",
                "Subject: {$subject}",
                "MIME-Version: 1.0",
                "Content-Type: text/plain; charset=utf-8",
                "Date: " . date('r'),
            ];

            $email = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
            fwrite($socket, $email . "\r\n");
            $this->smtpRead($socket);

            // Quit
            $this->smtpCommand($socket, "QUIT");
            fclose($socket);

            // Strategic log for email sent
            Logger::channel('email')->info( 'Email sent successfully', [
                'to' => $to,
                'subject' => $subject,
                'smtp_host' => $config['host'],
                'smtp_port' => $config['port'],
            ]);

            return ['success' => true, 'channel' => self::CHANNEL_EMAIL, 'error' => null];
        } catch (\Throwable $e) {
            // Strategic log for email failure
            Logger::channel('email')->error( 'Email send failed', [
                'to' => $to,
                'subject' => $subject,
                'smtp_host' => $config['host'] ?? 'unknown',
                'smtp_port' => $config['port'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'channel' => self::CHANNEL_EMAIL, 'error' => $e->getMessage()];
        }
    }

    private function smtpCommand($socket, string $command): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->smtpRead($socket);
    }

    private function smtpRead($socket): string
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $response;
    }

    /**
     * Send Telegram message
     */
    private function sendTelegram(string $chatId, string $message): array
    {
        $config = $this->config['telegram'];

        if (empty($config['bot_token'])) {
            return ['success' => false, 'channel' => self::CHANNEL_TELEGRAM, 'error' => 'Bot token not configured'];
        }

        $url = "https://api.telegram.org/bot{$config['bot_token']}/sendMessage";

        $payload = json_encode([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 10,
            ],
        ]);

        try {
            $response = @file_get_contents($url, false, $context);
            $result = json_decode($response, true);

            if ($result['ok'] ?? false) {
                return ['success' => true, 'channel' => self::CHANNEL_TELEGRAM, 'error' => null];
            }

            return ['success' => false, 'channel' => self::CHANNEL_TELEGRAM, 'error' => $result['description'] ?? 'Unknown error'];
        } catch (\Throwable $e) {
            return ['success' => false, 'channel' => self::CHANNEL_TELEGRAM, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send Discord message via webhook
     */
    private function sendDiscord(string $userId, string $title, string $message): array
    {
        $config = $this->config['discord'];

        if (empty($config['webhook_url'])) {
            return ['success' => false, 'channel' => self::CHANNEL_DISCORD, 'error' => 'Webhook URL not configured'];
        }

        $payload = json_encode([
            'content' => "<@{$userId}>",
            'embeds' => [
                [
                    'title' => $title,
                    'description' => $message,
                    'color' => 0x2563eb, // Blue
                    'timestamp' => date('c'),
                ],
            ],
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 10,
            ],
        ]);

        try {
            $response = @file_get_contents($config['webhook_url'], false, $context);

            // Discord returns empty response on success
            if ($response === '' || $response === false) {
                // Check HTTP response code
                if (isset($http_response_header[0]) && str_contains($http_response_header[0], '204')) {
                    return ['success' => true, 'channel' => self::CHANNEL_DISCORD, 'error' => null];
                }
            }

            return ['success' => true, 'channel' => self::CHANNEL_DISCORD, 'error' => null];
        } catch (\Throwable $e) {
            return ['success' => false, 'channel' => self::CHANNEL_DISCORD, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send Slack message via webhook
     */
    private function sendSlack(string $userId, string $title, string $message): array
    {
        $config = $this->config['slack'];

        if (empty($config['webhook_url'])) {
            return ['success' => false, 'channel' => self::CHANNEL_SLACK, 'error' => 'Webhook URL not configured'];
        }

        $payload = json_encode([
            'text' => "<@{$userId}> {$title}",
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => $title,
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $message,
                    ],
                ],
            ],
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'timeout' => 10,
            ],
        ]);

        try {
            $response = @file_get_contents($config['webhook_url'], false, $context);

            if ($response === 'ok') {
                return ['success' => true, 'channel' => self::CHANNEL_SLACK, 'error' => null];
            }

            return ['success' => false, 'channel' => self::CHANNEL_SLACK, 'error' => $response ?: 'Unknown error'];
        } catch (\Throwable $e) {
            return ['success' => false, 'channel' => self::CHANNEL_SLACK, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send recovery token for emergency access
     *
     * @param int $userId User ID
     * @param string $token The recovery token
     * @param string $expiresAt Token expiration time
     * @param string|null $preferredChannel Override channel
     * @return array{success: bool, channel: string, error: ?string}
     */
    public function sendRecoveryToken(
        int $userId,
        string $token,
        string $expiresAt,
        ?string $preferredChannel = null
    ): array {
        $user = $this->getUser($userId);

        if ($user === null) {
            return ['success' => false, 'channel' => '', 'error' => 'User not found'];
        }

        $channel = $preferredChannel ?? $user['notification_channel'] ?? self::CHANNEL_EMAIL;
        $address = $this->getUserChannelAddress($user, $channel);

        if ($address === null || !$this->isChannelEnabled($channel)) {
            // Fallback to email
            $channel = self::CHANNEL_EMAIL;
            $address = $user['email'];
        }

        $message = $this->formatRecoveryTokenMessage($token, $expiresAt, $user['name'] ?? 'Admin');

        return $this->sendToChannel(
            $channel,
            $address,
            'Emergency Recovery Token - Enterprise Admin',
            $message
        );
    }

    /**
     * Format recovery token message
     */
    private function formatRecoveryTokenMessage(string $token, string $expiresAt, string $userName): string
    {
        $now = date('Y-m-d H:i:s T');

        return <<<MSG
EMERGENCY RECOVERY TOKEN
========================

Hello {$userName},

An emergency recovery token has been generated for your account.
This token allows ONE-TIME access to the admin panel, bypassing 2FA.

**Recovery Token:**
{$token}

**Expires:** {$expiresAt}
**Generated:** {$now}

HOW TO USE:
1. Go to the admin panel login page
2. Click "Emergency Recovery" link
3. Enter this recovery token
4. You will be logged in directly

SECURITY WARNING:
- This token can only be used ONCE
- It will be invalidated immediately after use
- If you didn't request this token, someone may have access to your CLI credentials
- In that case, rotate your CLI token immediately

If you didn't request this, please secure your account immediately.
MSG;
    }

    /**
     * Format 2FA message
     */
    private function format2FAMessage(string $code): string
    {
        return <<<MSG
Your verification code is: **{$code}**

This code expires in 5 minutes.

If you didn't request this code, please ignore this message and ensure your account is secure.
MSG;
    }

    /**
     * Format URL rotation message
     */
    private function formatUrlRotationMessage(string $newUrl, string $reason): string
    {
        return <<<MSG
The admin panel URL has been rotated.

**New URL:** {$newUrl}
**Reason:** {$reason}
**Time:** {date('Y-m-d H:i:s T')}

Update your bookmarks and notify your team.
MSG;
    }

    /**
     * Format security alert message
     */
    private function formatSecurityAlertMessage(string $alertType, array $details): string
    {
        $detailsStr = '';
        foreach ($details as $key => $value) {
            $detailsStr .= "- **{$key}:** {$value}\n";
        }

        return <<<MSG
Security Alert: **{$alertType}**

{$detailsStr}
Time: {date('Y-m-d H:i:s T')}

If this wasn't you, please take immediate action.
MSG;
    }

    /**
     * Check if channel is enabled
     */
    public function isChannelEnabled(string $channel): bool
    {
        return $this->config[$channel]['enabled'] ?? false;
    }

    /**
     * Get user's address for channel
     */
    private function getUserChannelAddress(array $user, string $channel): ?string
    {
        return match ($channel) {
            self::CHANNEL_EMAIL => $user['email'] ?? null,
            self::CHANNEL_TELEGRAM => $user['telegram_chat_id'] ?? null,
            self::CHANNEL_DISCORD => $user['discord_user_id'] ?? null,
            self::CHANNEL_SLACK => $user['slack_user_id'] ?? null,
            default => null,
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
     * Get available channels for a user
     */
    public function getAvailableChannels(int $userId): array
    {
        $user = $this->getUser($userId);

        if ($user === null) {
            return [];
        }

        $channels = [];

        if ($this->isChannelEnabled(self::CHANNEL_EMAIL) && !empty($user['email'])) {
            $channels[] = ['id' => self::CHANNEL_EMAIL, 'label' => 'Email', 'address' => $this->maskEmail($user['email'])];
        }

        if ($this->isChannelEnabled(self::CHANNEL_TELEGRAM) && !empty($user['telegram_chat_id'])) {
            $channels[] = ['id' => self::CHANNEL_TELEGRAM, 'label' => 'Telegram', 'address' => 'Configured'];
        }

        if ($this->isChannelEnabled(self::CHANNEL_DISCORD) && !empty($user['discord_user_id'])) {
            $channels[] = ['id' => self::CHANNEL_DISCORD, 'label' => 'Discord', 'address' => 'Configured'];
        }

        if ($this->isChannelEnabled(self::CHANNEL_SLACK) && !empty($user['slack_user_id'])) {
            $channels[] = ['id' => self::CHANNEL_SLACK, 'label' => 'Slack', 'address' => 'Configured'];
        }

        return $channels;
    }

    /**
     * Mask email for display
     */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email);
        $maskedLocal = substr($local, 0, 2) . str_repeat('*', max(0, strlen($local) - 2));
        return $maskedLocal . '@' . $domain;
    }

    /**
     * Test channel connectivity
     */
    public function testChannel(string $channel, string $address): array
    {
        if (!$this->isChannelEnabled($channel)) {
            return ['success' => false, 'error' => 'Channel not enabled'];
        }

        return $this->sendToChannel($channel, $address, 'Test Notification', 'This is a test notification from Enterprise Admin Panel.');
    }

    /**
     * Configure channel for user
     */
    public function configureUserChannel(int $userId, string $channel, string $address): bool
    {
        $column = match ($channel) {
            self::CHANNEL_TELEGRAM => 'telegram_chat_id',
            self::CHANNEL_DISCORD => 'discord_user_id',
            self::CHANNEL_SLACK => 'slack_user_id',
            default => null,
        };

        if ($column === null) {
            return false;
        }

        $affected = $this->db->execute(
            "UPDATE admin_users SET {$column} = ?, notification_channel = ? WHERE id = ?",
            [$address, $channel, $userId]
        );
        return $affected > 0;
    }
}
