-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Add CLI Token for Secure URL Generation
-- ============================================================================
-- Database: MySQL 8.0+
-- Version: 1.0.1
-- ============================================================================
-- The CLI token is used to securely generate/retrieve the admin panel URL.
--
-- SECURITY MODEL:
-- 1. User generates token via CLI: php setup/generate-cli-token.php
-- 2. Token is derived from: HMAC-SHA256(user_id + password + timestamp, master_secret)
-- 3. Only the HASH of the token is stored in database (SHA-256)
-- 4. To generate/view admin URL, user must provide the raw token
-- 5. If database is compromised, attacker cannot reverse the token hash
--
-- USAGE:
-- - First time: php setup/install.php --email=x --password=y (generates token + URL)
-- - Get URL:    php setup/get-admin-url.php --token=YOUR_TOKEN
-- - Regenerate: php setup/regenerate-cli-token.php --email=x --password=y
-- ============================================================================

-- Add CLI token columns using procedure for conditional ADD COLUMN
DELIMITER //

CREATE PROCEDURE add_cli_token_columns_if_not_exists()
BEGIN
    -- Add cli_token_hash if not exists
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'admin_users'
          AND column_name = 'cli_token_hash'
    ) THEN
        ALTER TABLE admin_users ADD COLUMN cli_token_hash VARCHAR(64) DEFAULT NULL;
    END IF;

    -- Add cli_token_generated_at if not exists
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'admin_users'
          AND column_name = 'cli_token_generated_at'
    ) THEN
        ALTER TABLE admin_users ADD COLUMN cli_token_generated_at DATETIME DEFAULT NULL;
    END IF;

    -- Add cli_token_generation_count if not exists
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'admin_users'
          AND column_name = 'cli_token_generation_count'
    ) THEN
        ALTER TABLE admin_users ADD COLUMN cli_token_generation_count INT UNSIGNED DEFAULT 0;
    END IF;
END //

DELIMITER ;

CALL add_cli_token_columns_if_not_exists();
DROP PROCEDURE IF EXISTS add_cli_token_columns_if_not_exists;

-- Index for fast token lookup (only create if not exists)
CREATE INDEX idx_admin_users_cli_token ON admin_users(cli_token_hash);
