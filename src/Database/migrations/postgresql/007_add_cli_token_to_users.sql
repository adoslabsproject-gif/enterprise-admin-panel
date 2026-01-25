-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Add CLI Access Token to Users
-- ============================================================================
-- Database: PostgreSQL
-- Version: 1.0.0
-- ============================================================================
-- Adds personal CLI token for secure URL retrieval.
-- Each admin has a UNIQUE token - no shared/predictable commands.
-- ============================================================================

-- Add CLI token columns
ALTER TABLE admin_users
    ADD COLUMN IF NOT EXISTS cli_access_token VARCHAR(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS cli_token_created_at TIMESTAMP DEFAULT NULL;

-- Index for token lookup
CREATE INDEX IF NOT EXISTS idx_admin_users_cli_token
    ON admin_users(cli_access_token)
    WHERE cli_access_token IS NOT NULL;

-- Add installation-specific identifier to config
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    'installation_id',
    encode(gen_random_bytes(16), 'hex'),
    'string',
    'Unique installation identifier (used for CLI commands)',
    true,
    false
) ON CONFLICT (config_key) DO NOTHING;

-- Add last rotation timestamp
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    'last_url_rotation',
    EXTRACT(EPOCH FROM NOW())::TEXT,
    'int',
    'Timestamp of last URL rotation',
    false,
    false
) ON CONFLICT (config_key) DO NOTHING;

-- Add rotation notification settings
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    'notify_on_rotation',
    'true',
    'bool',
    'Send notification when admin URL is rotated',
    false,
    true
) ON CONFLICT (config_key) DO NOTHING;

-- Comments
COMMENT ON COLUMN admin_users.cli_access_token IS 'Personal CLI token (Argon2id hashed) for URL retrieval';
COMMENT ON COLUMN admin_users.cli_token_created_at IS 'When the CLI token was generated';
