-- Migration: 010_add_two_factor_method
-- Description: Add two_factor_method column to support multiple 2FA methods
-- Date: 2026-01-24

-- ============================================================================
-- Add two_factor_method column to admin_users
-- ============================================================================
-- Supported methods: totp, email, telegram, discord, slack
-- Default is 'email' for backwards compatibility

-- Check if column exists and add if not
SET @col_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'admin_users'
    AND COLUMN_NAME = 'two_factor_method'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE admin_users ADD COLUMN two_factor_method VARCHAR(20) DEFAULT ''email''',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set existing 2FA users to TOTP if they have a secret
UPDATE admin_users
SET two_factor_method = 'totp'
WHERE two_factor_enabled = 1 AND two_factor_secret IS NOT NULL AND two_factor_method IS NULL;

-- ============================================================================
-- Add index for 2FA queries
-- ============================================================================
CREATE INDEX idx_admin_users_2fa_method ON admin_users(two_factor_method);
