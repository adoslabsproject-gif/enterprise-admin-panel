-- Migration: 012_add_security_columns
-- Description: Add security-related columns to log_channels and log_telegram_config
-- Part of: Enterprise Admin Panel + PSR-3 Logger integration
--
-- Changes:
-- 1. log_channels: Add auto_reset_enabled and auto_reset_at columns
-- 2. log_telegram_config: Add is_encrypted column for bot token encryption status

-- ============================================================================
-- LOG CHANNELS: Auto-reset columns
-- ============================================================================
-- Auto-reset feature automatically resets debug-level channels to WARNING
-- after a configurable timeout (default 8 hours) for security.

ALTER TABLE log_channels
ADD COLUMN IF NOT EXISTS auto_reset_enabled BOOLEAN NOT NULL DEFAULT TRUE;

ALTER TABLE log_channels
ADD COLUMN IF NOT EXISTS auto_reset_at TIMESTAMP;

COMMENT ON COLUMN log_channels.auto_reset_enabled IS 'Whether auto-reset to WARNING is enabled for this channel when set below WARNING';
COMMENT ON COLUMN log_channels.auto_reset_at IS 'Timestamp when this channel will auto-reset to WARNING (set when level < WARNING)';

-- ============================================================================
-- LOG TELEGRAM CONFIG: Encryption status
-- ============================================================================
-- is_encrypted indicates whether the bot_token is stored encrypted (AES-256-GCM)
-- Required for proper decryption on retrieval

ALTER TABLE log_telegram_config
ADD COLUMN IF NOT EXISTS is_encrypted BOOLEAN NOT NULL DEFAULT FALSE;

COMMENT ON COLUMN log_telegram_config.is_encrypted IS 'Whether bot_token is encrypted with AES-256-GCM (requires APP_KEY)';

-- Update existing rows to indicate unencrypted tokens (legacy data)
UPDATE log_telegram_config
SET is_encrypted = FALSE
WHERE is_encrypted IS NULL;
