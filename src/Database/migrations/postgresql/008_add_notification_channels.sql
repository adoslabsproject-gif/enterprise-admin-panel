-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Add Multi-Channel Notification Support
-- ============================================================================
-- Database: PostgreSQL
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
    ADD COLUMN IF NOT EXISTS notification_channel VARCHAR(20) DEFAULT 'email',
    ADD COLUMN IF NOT EXISTS telegram_chat_id VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS discord_user_id VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS slack_user_id VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS notify_on_rotation BOOLEAN DEFAULT true,
    ADD COLUMN IF NOT EXISTS notify_on_login BOOLEAN DEFAULT false;

-- Add notification configuration to admin_config
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES
    ('notification_email_enabled', 'true', 'bool', 'Enable email notifications (SMTP)', false, true),
    ('notification_telegram_enabled', 'false', 'bool', 'Enable Telegram notifications', false, true),
    ('notification_discord_enabled', 'false', 'bool', 'Enable Discord notifications', false, true),
    ('notification_slack_enabled', 'false', 'bool', 'Enable Slack notifications', false, true),
    ('smtp_host', 'localhost', 'string', 'SMTP server host', false, true),
    ('smtp_port', '1025', 'int', 'SMTP server port (1025 for Mailhog)', false, true),
    ('smtp_from_email', 'admin@localhost', 'string', 'From email address', false, true),
    ('smtp_from_name', 'Enterprise Admin', 'string', 'From name', false, true)
ON CONFLICT (config_key) DO NOTHING;

-- Create 2FA codes table for email/sms/telegram verification
CREATE TABLE IF NOT EXISTS admin_2fa_codes (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,
    code VARCHAR(10) NOT NULL,
    channel VARCHAR(20) NOT NULL DEFAULT 'email',
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_active_code UNIQUE (user_id, code)
);

CREATE INDEX IF NOT EXISTS idx_2fa_codes_user ON admin_2fa_codes(user_id);
CREATE INDEX IF NOT EXISTS idx_2fa_codes_expires ON admin_2fa_codes(expires_at);

-- Comments
COMMENT ON COLUMN admin_users.notification_channel IS 'Preferred notification channel: email, telegram, discord, slack';
COMMENT ON COLUMN admin_users.telegram_chat_id IS 'Telegram chat ID for notifications';
COMMENT ON COLUMN admin_users.discord_user_id IS 'Discord user ID for mentions';
COMMENT ON COLUMN admin_users.slack_user_id IS 'Slack user ID for mentions';
COMMENT ON COLUMN admin_users.notify_on_rotation IS 'Notify when admin URL rotates';
COMMENT ON COLUMN admin_users.notify_on_login IS 'Notify on successful login';
COMMENT ON TABLE admin_2fa_codes IS 'Temporary 2FA verification codes';
