-- Migration: 010_add_two_factor_method
-- Description: Add two_factor_method column to support multiple 2FA methods
-- Date: 2026-01-24

-- ============================================================================
-- Add two_factor_method column to admin_users
-- ============================================================================
-- Supported methods: totp, email, telegram, discord, slack
-- Default is 'email' for backwards compatibility

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'admin_users' AND column_name = 'two_factor_method'
    ) THEN
        ALTER TABLE admin_users ADD COLUMN two_factor_method VARCHAR(20) DEFAULT 'email';

        -- Set existing 2FA users to TOTP if they have a secret
        UPDATE admin_users
        SET two_factor_method = 'totp'
        WHERE two_factor_enabled = true AND two_factor_secret IS NOT NULL;

        COMMENT ON COLUMN admin_users.two_factor_method IS 'Two-factor authentication method: totp, email, telegram, discord, slack';
    END IF;
END $$;

-- ============================================================================
-- Add index for 2FA queries
-- ============================================================================
CREATE INDEX IF NOT EXISTS idx_admin_users_2fa_method
    ON admin_users(two_factor_method)
    WHERE two_factor_enabled = true;
