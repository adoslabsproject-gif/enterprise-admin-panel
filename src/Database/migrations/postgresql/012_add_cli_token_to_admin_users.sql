-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Add CLI Token for Secure URL Generation
-- ============================================================================
-- Database: PostgreSQL
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

-- Add CLI token hash column (only for master admin)
ALTER TABLE admin_users
ADD COLUMN IF NOT EXISTS cli_token_hash VARCHAR(64) DEFAULT NULL;

-- Add flag for master admin (only one user can be master)
ALTER TABLE admin_users
ADD COLUMN IF NOT EXISTS is_master BOOLEAN DEFAULT false;

-- Add token generation timestamp (for logging/audit)
ALTER TABLE admin_users
ADD COLUMN IF NOT EXISTS cli_token_generated_at TIMESTAMP DEFAULT NULL;

-- Add token generation count (for security monitoring)
ALTER TABLE admin_users
ADD COLUMN IF NOT EXISTS cli_token_generation_count INTEGER DEFAULT 0;

-- Index for fast token lookup
CREATE INDEX IF NOT EXISTS idx_admin_users_cli_token
ON admin_users(cli_token_hash)
WHERE cli_token_hash IS NOT NULL;

-- Only one master admin allowed
CREATE UNIQUE INDEX IF NOT EXISTS idx_admin_users_master
ON admin_users(is_master)
WHERE is_master = true;

-- ============================================================================
-- COMMENTS
-- ============================================================================

COMMENT ON COLUMN admin_users.cli_token_hash IS 'SHA-256 hash of CLI token (used to verify token for URL generation)';
COMMENT ON COLUMN admin_users.is_master IS 'Master admin flag (only one user can be master, required for CLI token)';
COMMENT ON COLUMN admin_users.cli_token_generated_at IS 'Timestamp of last CLI token generation';
COMMENT ON COLUMN admin_users.cli_token_generation_count IS 'Number of times CLI token was generated (logged for security)';
