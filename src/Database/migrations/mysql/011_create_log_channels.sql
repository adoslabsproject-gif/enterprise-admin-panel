-- Migration: 011_create_log_channels
-- Description: Create log_channels table for PSR-3 Logger configuration
-- Part of: Enterprise Admin Panel + PSR-3 Logger integration
--
-- This table stores channel-level logging configuration that can be
-- modified from the admin panel UI. The should_log() function in
-- enterprise-bootstrap reads from this table (via cache) to determine
-- if a log entry should be written.

CREATE TABLE IF NOT EXISTS log_channels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Channel identifier (e.g., 'default', 'security', 'api', 'database')
    channel VARCHAR(100) NOT NULL,

    -- Minimum log level for this channel
    -- PSR-3 levels: debug, info, notice, warning, error, critical, alert, emergency
    min_level VARCHAR(20) NOT NULL DEFAULT 'debug',

    -- Whether this channel is enabled
    enabled TINYINT(1) NOT NULL DEFAULT 1,

    -- Human-readable description
    description VARCHAR(255) NULL,

    -- Handlers configuration (JSON array of handler names)
    -- e.g., ["file", "database", "telegram"]
    handlers JSON NOT NULL DEFAULT ('["file"]'),

    -- Additional configuration (JSON)
    -- e.g., {"telegram_level": "error", "file_rotation": "daily"}
    config JSON NOT NULL DEFAULT ('{}'),

    -- Auto-reset feature: automatically resets debug-level channels to WARNING
    -- after a configurable timeout (default 8 hours) for security
    auto_reset_enabled TINYINT(1) NOT NULL DEFAULT 1,
    auto_reset_at DATETIME NULL,

    -- Statistics (updated by triggers or application)
    log_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_log_at TIMESTAMP NULL,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Unique constraint on channel name
    UNIQUE KEY uq_log_channels_channel (channel),

    -- Indexes for fast lookups
    KEY idx_log_channels_enabled (enabled),
    KEY idx_log_channels_min_level (min_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default channels
-- IMPORTANT: Only 'security' channel logs to database for audit compliance
-- All other channels log to file only to prevent database bloat
-- DEFAULT LEVEL: warning (safe) for all channels
INSERT INTO log_channels (channel, min_level, enabled, description, handlers, config) VALUES
    ('default', 'warning', 1, 'Default application logs', '["file"]', '{}'),
    ('security', 'warning', 1, 'Security events, authentication, authorization', '["file", "database"]', '{"db_min_level": "warning"}'),
    ('api', 'warning', 1, 'API requests and responses', '["file"]', '{}'),
    ('database', 'warning', 1, 'Database queries, slow queries, errors', '["file"]', '{}'),
    ('email', 'warning', 1, 'Email sending, SMTP errors', '["file"]', '{}'),
    ('performance', 'warning', 1, 'Performance metrics, slow operations', '["file"]', '{}'),
    ('error', 'error', 1, 'Application errors, exceptions, failures', '["file"]', '{}'),
    ('js_errors', 'warning', 1, 'Client-side JavaScript errors and exceptions', '["file"]', '{}')
ON DUPLICATE KEY UPDATE
    handlers = IF(handlers = '["file"]' AND VALUES(handlers) != '["file"]', VALUES(handlers), handlers),
    config = IF(config = '{}' AND VALUES(config) != '{}', VALUES(config), config);

-- Create security_log table for DatabaseHandler (security channel audit trail)
-- Extended schema with dedicated columns for attacker identification
CREATE TABLE IF NOT EXISTS security_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Channel name (always 'security' for this table)
    channel VARCHAR(255) NOT NULL DEFAULT 'security',

    -- PSR-3 log level name (e.g., 'WARNING', 'ERROR')
    level VARCHAR(20) NOT NULL,

    -- Numeric level value for filtering (100=DEBUG, 200=INFO, 300=WARNING, 400=ERROR, etc.)
    level_value SMALLINT NOT NULL,

    -- Log message
    message TEXT NOT NULL,

    -- =====================================================================
    -- ATTACKER IDENTIFICATION COLUMNS (dedicated for fast queries)
    -- =====================================================================

    -- IP address of the request (IPv4 or IPv6)
    ip_address VARCHAR(45) NULL,

    -- User ID if authenticated (NULL for anonymous)
    user_id BIGINT UNSIGNED NULL,

    -- User email if available
    user_email VARCHAR(255) NULL,

    -- User agent string
    user_agent TEXT NULL,

    -- Session ID (truncated for security)
    session_id VARCHAR(64) NULL,

    -- =====================================================================
    -- STRUCTURED DATA (JSON)
    -- =====================================================================

    -- Structured context data (JSON) - additional context
    context JSON,

    -- Extra processor data (JSON) - contains request_id, memory, execution_time, etc.
    extra JSON,

    -- Timestamp with microseconds for precise ordering
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),

    -- Request ID for correlating logs within the same request
    request_id VARCHAR(36),

    -- Indexes for fast lookups
    KEY idx_security_log_channel (channel),
    KEY idx_security_log_level (level_value),
    KEY idx_security_log_created_at (created_at),
    KEY idx_security_log_request_id (request_id),
    -- Attacker identification indexes
    KEY idx_security_log_ip (ip_address),
    KEY idx_security_log_user_id (user_id),
    KEY idx_security_log_user_email (user_email),
    KEY idx_security_log_ip_time (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create table for Telegram notification configuration
CREATE TABLE IF NOT EXISTS log_telegram_config (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Whether Telegram notifications are enabled
    enabled TINYINT(1) NOT NULL DEFAULT 0,

    -- Telegram bot token (encrypted with AES-256-GCM if APP_KEY is set)
    bot_token VARCHAR(512) NULL,

    -- Whether bot_token is stored encrypted
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,

    -- Chat ID to send notifications to
    chat_id VARCHAR(100) NULL,

    -- Minimum level for Telegram notifications (SEPARATE from channel level)
    -- This allows: channel=warning, telegram=error (only errors go to Telegram)
    min_level VARCHAR(20) NOT NULL DEFAULT 'error',

    -- Which channels to notify (JSON array, ["*"] = all)
    notify_channels JSON NOT NULL DEFAULT ('["*"]'),

    -- Rate limiting
    rate_limit_per_minute INT UNSIGNED NOT NULL DEFAULT 10,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default Telegram config (disabled)
INSERT IGNORE INTO log_telegram_config (id, enabled, min_level) VALUES (1, 0, 'error');
