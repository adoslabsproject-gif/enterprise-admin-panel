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
INSERT IGNORE INTO log_channels (channel, min_level, enabled, description, handlers) VALUES
    ('default', 'info', 1, 'Default application logs', '["file", "database"]'),
    ('security', 'info', 1, 'Security events, authentication, authorization', '["file", "database"]'),
    ('api', 'warning', 1, 'API requests and responses', '["file"]'),
    ('database', 'warning', 1, 'Database queries, slow queries, errors', '["file"]'),
    ('email', 'info', 1, 'Email sending, SMTP errors', '["file", "database"]'),
    ('performance', 'warning', 1, 'Performance metrics, slow operations', '["file"]'),
    ('audit', 'info', 1, 'Audit trail, user actions', '["database"]');

-- Create table for Telegram notification configuration
CREATE TABLE IF NOT EXISTS log_telegram_config (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Whether Telegram notifications are enabled
    enabled TINYINT(1) NOT NULL DEFAULT 0,

    -- Telegram bot token
    bot_token VARCHAR(255) NULL,

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
