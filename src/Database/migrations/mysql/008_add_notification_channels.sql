-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Add Multi-Channel Notification Support
-- ============================================================================
-- Database: MySQL 8.0+
-- Version: 1.0.0
-- ============================================================================
-- Adds support for multi-channel 2FA and notifications:
-- - Email (default, via SMTP/Mailhog)
-- - Telegram
-- - Discord
-- - Slack
-- ============================================================================

-- Add notification channel columns to admin_users
ALTER TABLE admin_users
    ADD COLUMN notification_channel VARCHAR(20) DEFAULT 'email',
    ADD COLUMN telegram_chat_id VARCHAR(100) DEFAULT NULL,
    ADD COLUMN discord_user_id VARCHAR(100) DEFAULT NULL,
    ADD COLUMN slack_user_id VARCHAR(100) DEFAULT NULL,
    ADD COLUMN notify_on_rotation BOOLEAN DEFAULT true,
    ADD COLUMN notify_on_login BOOLEAN DEFAULT false;

-- Add notification configuration to admin_config
INSERT IGNORE INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES
    ('notification_email_enabled', 'true', 'bool', 'Enable email notifications (SMTP)', 0, 1),
    ('notification_telegram_enabled', 'false', 'bool', 'Enable Telegram notifications', 0, 1),
    ('notification_discord_enabled', 'false', 'bool', 'Enable Discord notifications', 0, 1),
    ('notification_slack_enabled', 'false', 'bool', 'Enable Slack notifications', 0, 1),
    ('smtp_host', 'localhost', 'string', 'SMTP server host', 0, 1),
    ('smtp_port', '1025', 'int', 'SMTP server port (1025 for Mailhog)', 0, 1),
    ('smtp_from_email', 'admin@localhost', 'string', 'From email address', 0, 1),
    ('smtp_from_name', 'Enterprise Admin', 'string', 'From name', 0, 1);

-- Create 2FA codes table for email/sms/telegram verification
CREATE TABLE IF NOT EXISTS admin_2fa_codes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    code VARCHAR(10) NOT NULL,
    channel VARCHAR(20) NOT NULL DEFAULT 'email',
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_active_code (user_id, code),
    INDEX idx_2fa_codes_user (user_id),
    INDEX idx_2fa_codes_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
