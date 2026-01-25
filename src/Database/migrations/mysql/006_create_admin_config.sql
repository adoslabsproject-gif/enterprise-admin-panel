-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Configuration Table
-- ============================================================================
-- Database: MySQL 8.0+
-- Version: 1.0.0
-- ============================================================================
-- Stores admin panel configuration including:
-- - Dynamic base URL (cryptographic)
-- - Secret key for HMAC
-- - Global settings
-- ============================================================================

CREATE TABLE IF NOT EXISTS admin_config (
    -- Primary key
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Configuration key (unique identifier)
    config_key VARCHAR(100) NOT NULL,

    -- Configuration value (JSON for complex values)
    config_value TEXT NOT NULL,

    -- Value type (string, int, bool, json)
    value_type VARCHAR(20) DEFAULT 'string',

    -- Description
    description TEXT DEFAULT NULL,

    -- Is this a sensitive value? (shown as **** in UI)
    is_sensitive TINYINT(1) DEFAULT 0,

    -- Can be changed via UI?
    is_editable TINYINT(1) DEFAULT 1,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    UNIQUE KEY uq_admin_config_key (config_key),

    -- Indexes
    INDEX idx_admin_config_key (config_key)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DEFAULT CONFIGURATION VALUES
-- ============================================================================
-- NOTE: admin_base_path and hmac_secret are created by install.php
-- because they need to be ENCRYPTED with APP_KEY before storage.
-- DO NOT add them here in plaintext!

-- URL rotation interval (seconds)
INSERT IGNORE INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    'url_rotation_interval',
    '14400',
    'int',
    'Interval in seconds for automatic URL rotation (default: 4 hours)',
    0,
    1
);

-- Session timeout (minutes)
INSERT IGNORE INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    'session_timeout',
    '30',
    'int',
    'Session idle timeout in minutes',
    0,
    1
);

-- 2FA enforcement
INSERT IGNORE INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    '2fa_required',
    'false',
    'bool',
    'Require 2FA for all admin users',
    0,
    1
);

-- IP whitelist mode
INSERT IGNORE INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    'ip_whitelist_enabled',
    'false',
    'bool',
    'Restrict admin access to whitelisted IPs only',
    0,
    1
);

-- Whitelisted IPs (JSON array)
INSERT IGNORE INTO admin_config (config_key, config_value, value_type, description, is_sensitive, is_editable)
VALUES (
    'ip_whitelist',
    '["127.0.0.1", "::1"]',
    'json',
    'List of IPs allowed to access admin panel',
    0,
    1
);
