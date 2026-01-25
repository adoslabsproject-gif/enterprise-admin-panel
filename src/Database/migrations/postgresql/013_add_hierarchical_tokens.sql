-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Hierarchical Token System
-- ============================================================================
-- Database: PostgreSQL
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
ADD COLUMN IF NOT EXISTS sub_token_created_at TIMESTAMP DEFAULT NULL;

-- Add who created this user's sub-token (foreign key to master)
ALTER TABLE admin_users
ADD COLUMN IF NOT EXISTS sub_token_created_by BIGINT DEFAULT NULL;

-- ============================================================================
-- EMERGENCY TOKENS TABLE
-- ============================================================================
-- Emergency tokens are stored separately as they can be multiple
-- and have special one-time use behavior

CREATE TABLE IF NOT EXISTS admin_emergency_tokens (
    id BIGSERIAL PRIMARY KEY,

    -- Owner (master admin who created it)
    user_id BIGINT NOT NULL REFERENCES admin_users(id) ON DELETE CASCADE,

    -- Token hash (Argon2id - requires 255 chars)
    token_hash VARCHAR(255) NOT NULL,

    -- Token name/description (for identification)
    name VARCHAR(100) NOT NULL DEFAULT 'Emergency Token',

    -- Usage tracking
    is_used BOOLEAN DEFAULT false,
    used_at TIMESTAMP DEFAULT NULL,
    used_from_ip VARCHAR(45) DEFAULT NULL,

    -- Expiration (optional)
    expires_at TIMESTAMP DEFAULT NULL,

    -- Creation tracking
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    CONSTRAINT chk_emergency_tokens_unused CHECK (
        (is_used = false AND used_at IS NULL) OR
        (is_used = true AND used_at IS NOT NULL)
    )
);

-- ============================================================================
-- TOKEN AUDIT LOG
-- ============================================================================
-- Separate audit log for token operations (more sensitive than regular audit)

CREATE TABLE IF NOT EXISTS admin_token_audit_log (
    id BIGSERIAL PRIMARY KEY,

    -- Who performed the action
    user_id BIGINT REFERENCES admin_users(id) ON DELETE SET NULL,
    user_email VARCHAR(255),

    -- Action type
    action VARCHAR(50) NOT NULL,
    -- Possible values:
    -- 'master_token_generated'
    -- 'master_token_regenerated'
    -- 'master_token_revoked'
    -- 'sub_token_created'
    -- 'sub_token_revoked'
    -- 'emergency_token_created'
    -- 'emergency_token_used'
    -- 'emergency_token_revoked'

    -- Target (for sub-token actions, who received the token)
    target_user_id BIGINT DEFAULT NULL,
    target_email VARCHAR(255) DEFAULT NULL,

    -- Additional context
    details JSONB DEFAULT '{}'::JSONB,

    -- Request metadata
    ip_address VARCHAR(45),
    user_agent TEXT,

    -- Timestamp
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- INDEXES
-- ============================================================================

-- Fast lookup by sub-token hash
CREATE INDEX IF NOT EXISTS idx_admin_users_sub_token
ON admin_users(sub_token_hash)
WHERE sub_token_hash IS NOT NULL;

-- Emergency tokens lookup
CREATE INDEX IF NOT EXISTS idx_emergency_tokens_user
ON admin_emergency_tokens(user_id);

CREATE INDEX IF NOT EXISTS idx_emergency_tokens_unused
ON admin_emergency_tokens(user_id, is_used, expires_at)
WHERE is_used = false;

-- Token audit log indexes
CREATE INDEX IF NOT EXISTS idx_token_audit_user
ON admin_token_audit_log(user_id);

CREATE INDEX IF NOT EXISTS idx_token_audit_action
ON admin_token_audit_log(action);

CREATE INDEX IF NOT EXISTS idx_token_audit_time
ON admin_token_audit_log(created_at);

-- ============================================================================
-- FOREIGN KEYS
-- ============================================================================

-- Sub-token creator reference
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_name = 'fk_admin_users_sub_token_creator'
    ) THEN
        ALTER TABLE admin_users
        ADD CONSTRAINT fk_admin_users_sub_token_creator
        FOREIGN KEY (sub_token_created_by) REFERENCES admin_users(id)
        ON DELETE SET NULL;
    END IF;
END $$;

-- ============================================================================
-- COMMENTS
-- ============================================================================

COMMENT ON COLUMN admin_users.sub_token_hash IS 'SHA-256 hash of sub-admin CLI token (for non-master admins)';
COMMENT ON COLUMN admin_users.sub_token_created_at IS 'When the sub-admin token was created';
COMMENT ON COLUMN admin_users.sub_token_created_by IS 'Master admin who created this sub-admin token';

COMMENT ON TABLE admin_emergency_tokens IS 'One-time emergency tokens for master admin recovery';
COMMENT ON COLUMN admin_emergency_tokens.token_hash IS 'SHA-256 hash of emergency token (shown once during creation)';
COMMENT ON COLUMN admin_emergency_tokens.is_used IS 'Once used, emergency token cannot be reused';
COMMENT ON COLUMN admin_emergency_tokens.expires_at IS 'Optional expiration (NULL = never expires)';

COMMENT ON TABLE admin_token_audit_log IS 'Audit log for sensitive token operations';
COMMENT ON COLUMN admin_token_audit_log.action IS 'Type of token action performed';
