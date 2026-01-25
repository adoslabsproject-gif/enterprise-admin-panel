-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Add CLI Access Token to Users
-- ============================================================================
-- Database: MySQL 8.0+
-- Version: 1.0.0
-- ============================================================================
-- Adds personal CLI token for secure URL retrieval.
-- Each admin has a UNIQUE token - no shared/predictable commands.
-- ============================================================================

-- Add CLI token columns (MySQL syntax)
ALTER TABLE admin_users
    ADD COLUMN cli_access_token VARCHAR(255) DEFAULT NULL,
    ADD COLUMN cli_token_created_at TIMESTAMP NULL DEFAULT NULL;

-- Index for token lookup
CREATE INDEX idx_admin_users_cli_token ON admin_users(cli_access_token(191));

-- Add installation-specific identifier to config
INSERT IGNORE INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    'installation_id',
    LOWER(HEX(RANDOM_BYTES(16))),
    'string',
    'Unique installation identifier (used for CLI commands)',
    1,
    0
);

-- Add last rotation timestamp
INSERT IGNORE INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    'last_url_rotation',
    UNIX_TIMESTAMP(),
    'int',
    'Timestamp of last URL rotation',
    0,
    0
);

-- Add rotation notification settings
INSERT IGNORE INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    'notify_on_rotation',
    'true',
    'bool',
    'Send notification when admin URL is rotated',
    0,
    1
);
