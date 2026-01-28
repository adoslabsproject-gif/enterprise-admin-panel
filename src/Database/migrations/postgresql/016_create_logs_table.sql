-- Migration: 016_create_logs_table
-- Description: Create logs table for PSR-3 Logger database handler
-- Part of: Enterprise Admin Panel + PSR-3 Logger integration

CREATE TABLE IF NOT EXISTS logs (
    id BIGSERIAL PRIMARY KEY,

    -- Channel name (e.g., 'app', 'security', 'api')
    channel VARCHAR(100) NOT NULL DEFAULT 'app',

    -- PSR-3 log level
    level VARCHAR(20) NOT NULL,

    -- Log message
    message TEXT NOT NULL,

    -- Additional context (JSON)
    context JSONB DEFAULT '{}'::JSONB,

    -- Extra data (JSON)
    extra JSONB DEFAULT '{}'::JSONB,

    -- Timestamp
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for efficient querying
CREATE INDEX IF NOT EXISTS idx_logs_channel ON logs(channel);
CREATE INDEX IF NOT EXISTS idx_logs_level ON logs(level);
CREATE INDEX IF NOT EXISTS idx_logs_created_at ON logs(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_logs_channel_level ON logs(channel, level);

-- Add comment
COMMENT ON TABLE logs IS 'Application logs from PSR-3 Logger database handler';
