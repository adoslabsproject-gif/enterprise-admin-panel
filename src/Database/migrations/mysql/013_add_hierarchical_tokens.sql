-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Hierarchical Token System
-- ============================================================================
-- Database: MySQL
-- Version: 1.0.0
-- ============================================================================
-- Implements hierarchical token system:
--
-- TOKEN HIERARCHY:
-- ================
-- 1. MASTER TOKEN: Generated during installation by master admin
--    - Required for all token operations
--    - Can create sub-admin tokens
--    - Can create emergency tokens
--    - Derived from: email + password + secret
--
-- 2. SUB-ADMIN TOKEN: Created by master for other admins
--    - Limited permissions
--    - Can access admin panel
--    - Cannot create other tokens
--    - Created by master using their master token
--
-- 3. EMERGENCY TOKEN: One-time use for recovery
--    - Used when master token is lost
--    - Must be stored offline (printed/safe)
--    - Can regenerate master token
--    - Invalidated after use
--
-- SECURITY:
-- =========
-- - Only SHA-256 hash of tokens stored in database
-- - Raw tokens shown ONCE during generation
-- - All token operations logged to error_log
-- - Old tokens remain valid (user manages revocation)
-- ============================================================================

-- Add sub-admin token hash
ALTER TABLE admin_users
ADD COLUMN IF NOT EXISTS sub_token_hash VARCHAR(64) DEFAULT NULL;

-- Add sub-admin token creation tracking
ALTER TABLE admin_users
ADD COLUMN IF NOT EXISTS sub_token_created_at DATETIME DEFAULT NULL;

-- Add who created this user's sub-token (foreign key to master)
ALTER TABLE admin_users
ADD COLUMN IF NOT EXISTS sub_token_created_by BIGINT DEFAULT NULL;

-- ============================================================================
-- EMERGENCY TOKENS TABLE
-- ============================================================================

CREATE TABLE IF NOT EXISTS admin_emergency_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,

    -- Owner (master admin who created it)
    user_id BIGINT NOT NULL,

    -- Token hash (Argon2id - requires 255 chars)
    token_hash VARCHAR(255) NOT NULL,

    -- Token name/description (for identification)
    name VARCHAR(100) NOT NULL DEFAULT 'Emergency Token',

    -- Usage tracking
    is_used TINYINT(1) DEFAULT 0,
    used_at DATETIME DEFAULT NULL,
    used_from_ip VARCHAR(45) DEFAULT NULL,

    -- Expiration (optional)
    expires_at DATETIME DEFAULT NULL,

    -- Creation tracking
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    CONSTRAINT fk_emergency_tokens_user
        FOREIGN KEY (user_id) REFERENCES admin_users(id)
        ON DELETE CASCADE,

    INDEX idx_emergency_tokens_user (user_id),
    INDEX idx_emergency_tokens_unused (user_id, is_used, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TOKEN AUDIT LOG
-- ============================================================================

CREATE TABLE IF NOT EXISTS admin_token_audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,

    -- Who performed the action
    user_id BIGINT DEFAULT NULL,
    user_email VARCHAR(255),

    -- Action type
    action VARCHAR(50) NOT NULL,

    -- Target (for sub-token actions)
    target_user_id BIGINT DEFAULT NULL,
    target_email VARCHAR(255) DEFAULT NULL,

    -- Additional context
    details JSON,

    -- Request metadata
    ip_address VARCHAR(45),
    user_agent TEXT,

    -- Timestamp
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    -- Foreign key
    CONSTRAINT fk_token_audit_user
        FOREIGN KEY (user_id) REFERENCES admin_users(id)
        ON DELETE SET NULL,

    INDEX idx_token_audit_user (user_id),
    INDEX idx_token_audit_action (action),
    INDEX idx_token_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INDEXES FOR ADMIN_USERS
-- ============================================================================

CREATE INDEX IF NOT EXISTS idx_admin_users_sub_token
ON admin_users(sub_token_hash);

-- Sub-token creator foreign key
ALTER TABLE admin_users
ADD CONSTRAINT fk_admin_users_sub_token_creator
FOREIGN KEY (sub_token_created_by) REFERENCES admin_users(id)
ON DELETE SET NULL;
