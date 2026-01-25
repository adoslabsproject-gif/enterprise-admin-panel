-- Migration: 009_add_recovery_tokens
-- Description: Add emergency recovery tokens for master admin bypass
-- Date: 2026-01-24

-- ============================================================================
-- Emergency Recovery Tokens Table
-- ============================================================================
-- One-time use tokens that allow master admin to bypass 2FA in emergencies.
-- Generated via CLI, sent via secure channel (email/telegram/discord/slack).
-- Each token can only be used ONCE and expires after 24 hours.

CREATE TABLE IF NOT EXISTS admin_recovery_tokens (
    id BIGSERIAL PRIMARY KEY,

    -- Token (hashed with Argon2id for security)
    token_hash VARCHAR(255) NOT NULL,

    -- Only master admin can use recovery tokens
    user_id BIGINT NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,

    -- Delivery tracking
    delivery_method VARCHAR(20) NOT NULL DEFAULT 'email', -- email, telegram, discord, slack
    delivered_at TIMESTAMP,
    delivered_to VARCHAR(255), -- email address, chat ID, etc.

    -- Usage tracking
    used_at TIMESTAMP,
    used_ip VARCHAR(45),
    used_user_agent TEXT,

    -- Security
    expires_at TIMESTAMP NOT NULL,
    is_revoked BOOLEAN DEFAULT FALSE,
    revoked_at TIMESTAMP,
    revoked_reason VARCHAR(255),

    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by_ip VARCHAR(45)
);

-- Partial unique index: only one active token per user at a time
-- (PostgreSQL requires CREATE UNIQUE INDEX for partial uniqueness)
CREATE UNIQUE INDEX idx_recovery_tokens_one_active_per_user
    ON admin_recovery_tokens(user_id)
    WHERE used_at IS NULL AND is_revoked = FALSE;

-- Indexes for fast lookups
CREATE INDEX IF NOT EXISTS idx_recovery_tokens_user ON admin_recovery_tokens(user_id);
CREATE INDEX IF NOT EXISTS idx_recovery_tokens_expires ON admin_recovery_tokens(expires_at) WHERE used_at IS NULL;
CREATE INDEX IF NOT EXISTS idx_recovery_tokens_active ON admin_recovery_tokens(user_id)
    WHERE used_at IS NULL AND is_revoked = FALSE;

-- ============================================================================
-- Audit log for recovery token events
-- ============================================================================
-- Events: recovery_token_generated, recovery_token_sent, recovery_token_used,
--         recovery_token_revoked, recovery_token_expired
