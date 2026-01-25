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
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Token (hashed with Argon2id for security)
    token_hash VARCHAR(255) NOT NULL,

    -- Only master admin can use recovery tokens
    user_id BIGINT UNSIGNED NOT NULL,

    -- Delivery tracking
    delivery_method VARCHAR(20) NOT NULL DEFAULT 'email', -- email, telegram, discord, slack
    delivered_at DATETIME,
    delivered_to VARCHAR(255), -- email address, chat ID, etc.

    -- Usage tracking
    used_at DATETIME,
    used_ip VARCHAR(45),
    used_user_agent TEXT,

    -- Security
    expires_at DATETIME NOT NULL,
    is_revoked TINYINT(1) DEFAULT 0,
    revoked_at DATETIME,
    revoked_reason VARCHAR(255),

    -- Metadata
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by_ip VARCHAR(45),

    -- Foreign key
    CONSTRAINT fk_recovery_tokens_user FOREIGN KEY (user_id)
        REFERENCES admin_users(id) ON DELETE CASCADE,

    -- Indexes for fast lookups
    INDEX idx_recovery_tokens_user (user_id),
    INDEX idx_recovery_tokens_expires (expires_at),
    INDEX idx_recovery_tokens_active (user_id, used_at, is_revoked, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Add is_master flag to admin_users if not exists
-- ============================================================================
-- MySQL doesn't support adding columns conditionally, so we use a procedure

DELIMITER //

CREATE PROCEDURE add_is_master_column_if_not_exists()
BEGIN
    DECLARE column_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO column_exists
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'admin_users'
      AND column_name = 'is_master';

    IF column_exists = 0 THEN
        ALTER TABLE admin_users ADD COLUMN is_master TINYINT(1) DEFAULT 0;

        -- Set first admin as master by default
        UPDATE admin_users SET is_master = 1
        WHERE id = (
            SELECT id FROM (
                SELECT MIN(id) as id FROM admin_users
                WHERE role IN ('super_admin', 'admin')
            ) AS tmp
        );
    END IF;
END //

DELIMITER ;

CALL add_is_master_column_if_not_exists();
DROP PROCEDURE IF EXISTS add_is_master_column_if_not_exists;

-- ============================================================================
-- Audit log for recovery token events
-- ============================================================================
-- Events: recovery_token_generated, recovery_token_sent, recovery_token_used,
--         recovery_token_revoked, recovery_token_expired
