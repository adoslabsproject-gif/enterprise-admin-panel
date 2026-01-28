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
ADD COLUMN IF NOT EXISTS auto_reset_enabled TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE log_channels
ADD COLUMN IF NOT EXISTS auto_reset_at DATETIME DEFAULT NULL;

-- ============================================================================
-- LOG TELEGRAM CONFIG: Encryption status
-- ============================================================================
-- is_encrypted indicates whether the bot_token is stored encrypted (AES-256-GCM)
-- Required for proper decryption on retrieval

ALTER TABLE log_telegram_config
ADD COLUMN IF NOT EXISTS is_encrypted TINYINT(1) NOT NULL DEFAULT 0;

-- Update existing rows to indicate unencrypted tokens (legacy data)
UPDATE log_telegram_config
SET is_encrypted = 0
WHERE is_encrypted IS NULL;
