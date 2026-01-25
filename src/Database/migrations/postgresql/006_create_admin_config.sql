-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Configuration Table
-- ============================================================================
-- Database: PostgreSQL
-- Version: 1.0.0
-- ============================================================================
-- Stores admin panel configuration including:
-- - Dynamic base URL (cryptographic)
-- - Secret key for HMAC
-- - Global settings
-- ============================================================================

CREATE TABLE IF NOT EXISTS admin_config (
    -- Primary key
    id BIGSERIAL PRIMARY KEY,

    -- Configuration key (unique identifier)
    config_key VARCHAR(100) NOT NULL,

    -- Configuration value (JSON for complex values)
    config_value TEXT NOT NULL,

    -- Value type (string, int, bool, json)
    value_type VARCHAR(20) DEFAULT 'string',

    -- Description
    description TEXT DEFAULT NULL,

    -- Is this a sensitive value? (shown as **** in UI)
    is_sensitive BOOLEAN DEFAULT false,

    -- Can be changed via UI?
    is_editable BOOLEAN DEFAULT true,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    CONSTRAINT uq_admin_config_key UNIQUE (config_key)
);

-- ============================================================================
-- INDEXES
-- ============================================================================

CREATE INDEX IF NOT EXISTS idx_admin_config_key ON admin_config(config_key);

-- ============================================================================
-- TRIGGER: Auto-update updated_at
-- ============================================================================

CREATE OR REPLACE FUNCTION update_admin_config_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_admin_config_updated_at ON admin_config;
CREATE TRIGGER trg_admin_config_updated_at
    BEFORE UPDATE ON admin_config
    FOR EACH ROW
    EXECUTE FUNCTION update_admin_config_updated_at();

-- ============================================================================
-- DEFAULT CONFIGURATION VALUES
-- ============================================================================
-- NOTE: admin_base_path and hmac_secret are created by install.php
-- because they need to be ENCRYPTED with APP_KEY before storage.
-- DO NOT add them here in plaintext!

-- URL rotation interval (seconds)
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    'url_rotation_interval',
    '14400',
    'int',
    'Interval in seconds for automatic URL rotation (default: 4 hours)',
    false,
    true
) ON CONFLICT (config_key) DO NOTHING;

-- Session timeout (minutes)
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    'session_timeout',
    '30',
    'int',
    'Session idle timeout in minutes',
    false,
    true
) ON CONFLICT (config_key) DO NOTHING;

-- 2FA enforcement
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    '2fa_required',
    'false',
    'bool',
    'Require 2FA for all admin users',
    false,
    true
) ON CONFLICT (config_key) DO NOTHING;

-- IP whitelist mode
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    'ip_whitelist_enabled',
    'false',
    'bool',
    'Restrict admin access to whitelisted IPs only',
    false,
    true
) ON CONFLICT (config_key) DO NOTHING;

-- Whitelisted IPs (JSON array)
INSERT INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    'ip_whitelist',
    '["127.0.0.1", "::1"]',
    'json',
    'List of IPs allowed to access admin panel',
    false,
    true
) ON CONFLICT (config_key) DO NOTHING;

-- ============================================================================
-- COMMENTS
-- ============================================================================

COMMENT ON TABLE admin_config IS 'Admin panel configuration with cryptographic URL settings';
COMMENT ON COLUMN admin_config.config_key IS 'Unique configuration key';
COMMENT ON COLUMN admin_config.config_value IS 'Configuration value (text/JSON)';
COMMENT ON COLUMN admin_config.is_sensitive IS 'Mask value in UI (e.g., secrets)';
