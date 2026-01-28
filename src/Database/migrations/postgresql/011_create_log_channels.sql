-- Migration: 011_create_log_channels
-- Description: Create log_channels table for PSR-3 Logger configuration
-- Part of: Enterprise Admin Panel + PSR-3 Logger integration
--
-- This table stores channel-level logging configuration that can be
-- modified from the admin panel UI. The should_log() function in
-- enterprise-bootstrap reads from this table (via cache) to determine
-- if a log entry should be written.

CREATE TABLE IF NOT EXISTS log_channels (
    id SERIAL PRIMARY KEY,

    -- Channel identifier (e.g., 'default', 'security', 'api', 'database')
    channel VARCHAR(100) NOT NULL,

    -- Minimum log level for this channel
    -- PSR-3 levels: debug, info, notice, warning, error, critical, alert, emergency
    min_level VARCHAR(20) NOT NULL DEFAULT 'debug',

    -- Whether this channel is enabled
    enabled BOOLEAN NOT NULL DEFAULT TRUE,

    -- Human-readable description
    description VARCHAR(255),

    -- Handlers configuration (JSON array of handler names)
    -- e.g., ["file", "database", "telegram"]
    handlers JSONB NOT NULL DEFAULT '["file"]'::JSONB,

    -- Additional configuration (JSON)
    -- e.g., {"telegram_level": "error", "file_rotation": "daily"}
    config JSONB NOT NULL DEFAULT '{}'::JSONB,

    -- Statistics (updated by triggers or application)
    log_count BIGINT NOT NULL DEFAULT 0,
    last_log_at TIMESTAMP,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Unique constraint on channel name
    CONSTRAINT uq_log_channels_channel UNIQUE (channel)
);

-- Indexes for fast lookups
CREATE INDEX idx_log_channels_enabled ON log_channels(enabled);
CREATE INDEX idx_log_channels_min_level ON log_channels(min_level);

-- Trigger to update updated_at on modification
CREATE OR REPLACE FUNCTION update_log_channels_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_log_channels_updated_at
    BEFORE UPDATE ON log_channels
    FOR EACH ROW
    EXECUTE FUNCTION update_log_channels_updated_at();

-- Insert default channels
-- IMPORTANT: Only 'security' channel logs to database for audit compliance
-- All other channels log to file only to prevent database bloat
INSERT INTO log_channels (channel, min_level, enabled, description, handlers) VALUES
    ('default', 'info', TRUE, 'Default application logs', '["file"]'),
    ('security', 'info', TRUE, 'Security events, authentication, authorization', '["file", "database"]'),
    ('api', 'warning', TRUE, 'API requests and responses', '["file"]'),
    ('database', 'warning', TRUE, 'Database queries, slow queries, errors', '["file"]'),
    ('email', 'info', TRUE, 'Email sending, SMTP errors', '["file"]'),
    ('performance', 'warning', TRUE, 'Performance metrics, slow operations', '["file"]'),
    ('audit', 'info', TRUE, 'Audit trail, user actions', '["file"]')
ON CONFLICT (channel) DO NOTHING;

-- Create table for Telegram notification configuration
CREATE TABLE IF NOT EXISTS log_telegram_config (
    id SERIAL PRIMARY KEY,

    -- Whether Telegram notifications are enabled
    enabled BOOLEAN NOT NULL DEFAULT FALSE,

    -- Telegram bot token
    bot_token VARCHAR(255),

    -- Chat ID to send notifications to
    chat_id VARCHAR(100),

    -- Minimum level for Telegram notifications (SEPARATE from channel level)
    -- This allows: channel=warning, telegram=error (only errors go to Telegram)
    min_level VARCHAR(20) NOT NULL DEFAULT 'error',

    -- Which channels to notify (JSON array, ["*"] = all)
    notify_channels JSONB NOT NULL DEFAULT '["*"]'::JSONB,

    -- Rate limiting
    rate_limit_per_minute INTEGER NOT NULL DEFAULT 10,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Trigger for telegram config
CREATE TRIGGER trg_log_telegram_config_updated_at
    BEFORE UPDATE ON log_telegram_config
    FOR EACH ROW
    EXECUTE FUNCTION update_log_channels_updated_at();

-- Insert default Telegram config (disabled)
INSERT INTO log_telegram_config (id, enabled, min_level) VALUES (1, FALSE, 'error')
ON CONFLICT DO NOTHING;

-- Add comment for documentation
COMMENT ON TABLE log_channels IS 'PSR-3 Logger channel configuration. Used by should_log() in enterprise-bootstrap.';
COMMENT ON TABLE log_telegram_config IS 'Telegram notification settings for PSR-3 Logger. Separate min_level allows channel=warning but telegram=error.';
COMMENT ON COLUMN log_channels.min_level IS 'Minimum PSR-3 level: debug < info < notice < warning < error < critical < alert < emergency';
COMMENT ON COLUMN log_channels.handlers IS 'JSON array of handler names: file, database, telegram, redis, webhook';
