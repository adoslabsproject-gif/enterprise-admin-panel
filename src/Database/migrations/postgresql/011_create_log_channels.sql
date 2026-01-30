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
    -- Keys:
    --   db_min_level: Minimum level for database logging (only for channels with "database" handler)
    --                 Allows file=warning but db=error (separate thresholds)
    --   telegram_level: Minimum level for Telegram notifications
    --   file_rotation: Rotation strategy (daily, weekly, monthly)
    -- Example: {"db_min_level": "error", "telegram_level": "critical"}
    config JSONB NOT NULL DEFAULT '{}'::JSONB,

    -- Auto-reset feature: automatically resets debug-level channels to WARNING
    -- after a configurable timeout (default 8 hours) for security
    auto_reset_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    auto_reset_at TIMESTAMP,

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
-- DEFAULT LEVEL: warning (safe) for all channels
INSERT INTO log_channels (channel, min_level, enabled, description, handlers, config) VALUES
    ('default', 'warning', TRUE, 'Default application logs', '["file"]', '{}'),
    ('security', 'warning', TRUE, 'Security events, authentication, authorization', '["file", "database"]', '{"db_min_level": "warning"}'),
    ('api', 'warning', TRUE, 'API requests and responses', '["file"]', '{}'),
    ('database', 'warning', TRUE, 'Database queries, slow queries, errors', '["file"]', '{}'),
    ('email', 'warning', TRUE, 'Email sending, SMTP errors', '["file"]', '{}'),
    ('performance', 'warning', TRUE, 'Performance metrics, slow operations', '["file"]', '{}'),
    ('error', 'error', TRUE, 'Application errors, exceptions, failures', '["file"]', '{}'),
    ('js_errors', 'warning', TRUE, 'Client-side JavaScript errors and exceptions', '["file"]', '{}')
ON CONFLICT (channel) DO UPDATE SET
    handlers = EXCLUDED.handlers,
    config = EXCLUDED.config
WHERE log_channels.handlers = '["file"]' AND EXCLUDED.handlers != '["file"]';

-- Create security_log table for DatabaseHandler (security channel audit trail)
-- Extended schema with dedicated columns for attacker identification
CREATE TABLE IF NOT EXISTS security_log (
    id BIGSERIAL PRIMARY KEY,

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
    ip_address VARCHAR(45),

    -- User ID if authenticated (NULL for anonymous)
    user_id BIGINT,

    -- User email if available
    user_email VARCHAR(255),

    -- User agent string
    user_agent TEXT,

    -- Session ID (truncated for security)
    session_id VARCHAR(64),

    -- =====================================================================
    -- STRUCTURED DATA (JSON)
    -- =====================================================================

    -- Structured context data (JSON) - additional context
    context JSONB,

    -- Extra processor data (JSON) - contains request_id, memory, execution_time, etc.
    extra JSONB,

    -- Timestamp with microseconds for precise ordering
    created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Request ID for correlating logs within the same request
    request_id VARCHAR(36)
);

-- Indexes for security_log
CREATE INDEX IF NOT EXISTS idx_security_log_channel ON security_log(channel);
CREATE INDEX IF NOT EXISTS idx_security_log_level ON security_log(level_value);
CREATE INDEX IF NOT EXISTS idx_security_log_created_at ON security_log(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_security_log_request_id ON security_log(request_id);

-- Attacker identification indexes
CREATE INDEX IF NOT EXISTS idx_security_log_ip ON security_log(ip_address) WHERE ip_address IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_security_log_user_id ON security_log(user_id) WHERE user_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_security_log_user_email ON security_log(user_email) WHERE user_email IS NOT NULL;

-- Partial index for errors only (most common query)
CREATE INDEX IF NOT EXISTS idx_security_log_errors ON security_log(created_at DESC) WHERE level_value >= 400;

-- Composite index for IP + time (attack pattern detection)
CREATE INDEX IF NOT EXISTS idx_security_log_ip_time ON security_log(ip_address, created_at DESC) WHERE ip_address IS NOT NULL;

-- Comment for documentation
COMMENT ON TABLE security_log IS 'Security audit trail with attacker identification. Extended from DatabaseHandler schema.';
COMMENT ON COLUMN security_log.ip_address IS 'Client IP address (IPv4/IPv6) for attacker identification';
COMMENT ON COLUMN security_log.user_id IS 'Authenticated user ID (NULL for anonymous requests)';
COMMENT ON COLUMN security_log.user_email IS 'User email for quick identification';
COMMENT ON COLUMN security_log.user_agent IS 'Browser/client user agent string';
COMMENT ON COLUMN security_log.session_id IS 'Session ID (truncated) for session tracking';
COMMENT ON COLUMN security_log.context IS 'Additional JSON context data';
COMMENT ON COLUMN security_log.extra IS 'Processor data - request_id, memory, execution_time, etc.';

-- Create table for Telegram notification configuration
CREATE TABLE IF NOT EXISTS log_telegram_config (
    id SERIAL PRIMARY KEY,

    -- Whether Telegram notifications are enabled
    enabled BOOLEAN NOT NULL DEFAULT FALSE,

    -- Telegram bot token (encrypted with AES-256-GCM if APP_KEY is set)
    bot_token VARCHAR(512),

    -- Whether bot_token is stored encrypted
    is_encrypted BOOLEAN NOT NULL DEFAULT FALSE,

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
COMMENT ON COLUMN log_channels.auto_reset_enabled IS 'Whether auto-reset to WARNING is enabled when level < WARNING';
COMMENT ON COLUMN log_channels.auto_reset_at IS 'Timestamp when channel will auto-reset to WARNING';
COMMENT ON COLUMN log_telegram_config.is_encrypted IS 'Whether bot_token is encrypted with AES-256-GCM';
