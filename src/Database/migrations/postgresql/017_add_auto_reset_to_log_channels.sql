-- Migration: 017_add_auto_reset_to_log_channels
-- Description: Add auto-reset columns for automatic level reset feature
-- Part of: Enterprise Admin Panel + PSR-3 Logger integration
--
-- Features:
-- - auto_reset_enabled: Toggle to enable/disable auto-reset (default ON)
-- - auto_reset_at: Timestamp when channel should reset to WARNING
--
-- When auto_reset_enabled=true AND min_level < WARNING:
-- - auto_reset_at = NOW() + 8 hours
-- - A page load check resets expired channels to WARNING
--
-- When auto_reset_enabled=false OR min_level >= WARNING:
-- - auto_reset_at = NULL (no reset needed)

-- Add auto_reset_at column if not exists
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'log_channels' AND column_name = 'auto_reset_at'
    ) THEN
        ALTER TABLE log_channels ADD COLUMN auto_reset_at TIMESTAMP;
    END IF;
END $$;

-- Add auto_reset_enabled column if not exists (default TRUE)
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'log_channels' AND column_name = 'auto_reset_enabled'
    ) THEN
        ALTER TABLE log_channels ADD COLUMN auto_reset_enabled BOOLEAN NOT NULL DEFAULT TRUE;
    END IF;
END $$;

-- Create index for efficient expired channel lookup
CREATE INDEX IF NOT EXISTS idx_log_channels_auto_reset
    ON log_channels(auto_reset_at)
    WHERE auto_reset_at IS NOT NULL;

-- Add comments for documentation
COMMENT ON COLUMN log_channels.auto_reset_at IS 'Timestamp when channel should auto-reset to WARNING. NULL means no reset scheduled.';
COMMENT ON COLUMN log_channels.auto_reset_enabled IS 'Whether auto-reset to WARNING is enabled for this channel. Default TRUE.';

-- Update default channels to use WARNING as default (safer for production)
UPDATE log_channels SET min_level = 'warning' WHERE min_level = 'debug' AND channel != 'security';
UPDATE log_channels SET min_level = 'info' WHERE channel = 'security';
