-- ============================================================================
-- ENTERPRISE ADMIN PANEL: URL Whitelist Table
-- ============================================================================
-- Database: PostgreSQL
-- Version: 1.0.0
-- ============================================================================
-- Cryptographically secure admin URLs with:
-- - HMAC-SHA256 tokens (256-bit security)
-- - User binding (prevents URL sharing)
-- - IP binding (optional, max security)
-- - Automatic expiry (4-hour rotation)
-- - Revocation support (instant invalidation)
-- - Emergency URLs (one-time use)
-- - Complete audit trail
-- ============================================================================

CREATE TABLE IF NOT EXISTS admin_url_whitelist (
    -- Primary key
    id BIGSERIAL PRIMARY KEY,

    -- URL token (HMAC-SHA256, 64 hex characters = 256 bits)
    token VARCHAR(64) NOT NULL,

    -- User binding (1 URL = 1 user, prevents sharing)
    user_id BIGINT NOT NULL,

    -- URL pattern used (for analytics/debugging)
    pattern VARCHAR(50) NOT NULL,

    -- Expiry (automatic rotation every 4 hours)
    expires_at TIMESTAMP NOT NULL,

    -- IP binding (optional, max security mode)
    bound_ip VARCHAR(45) DEFAULT NULL,

    -- Emergency flag (one-time use URLs)
    is_emergency BOOLEAN DEFAULT false,

    -- Max uses (for emergency URLs, NULL = unlimited)
    max_uses INT DEFAULT NULL,

    -- Access tracking (audit trail)
    access_count INT DEFAULT 0,
    last_used_at TIMESTAMP DEFAULT NULL,

    -- Revocation support (instant invalidation)
    revoked BOOLEAN DEFAULT false,
    revoked_at TIMESTAMP DEFAULT NULL,
    revoke_reason VARCHAR(255) DEFAULT NULL,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    CONSTRAINT uq_admin_url_token UNIQUE (token)
);

-- ============================================================================
-- INDEXES
-- ============================================================================

CREATE INDEX IF NOT EXISTS idx_admin_url_token_user ON admin_url_whitelist(token, user_id);
CREATE INDEX IF NOT EXISTS idx_admin_url_expires ON admin_url_whitelist(expires_at);
CREATE INDEX IF NOT EXISTS idx_admin_url_revoked ON admin_url_whitelist(revoked, revoked_at);
CREATE INDEX IF NOT EXISTS idx_admin_url_user_id ON admin_url_whitelist(user_id);
CREATE INDEX IF NOT EXISTS idx_admin_url_bound_ip ON admin_url_whitelist(bound_ip) WHERE bound_ip IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_admin_url_emergency ON admin_url_whitelist(is_emergency) WHERE is_emergency = true;

-- ============================================================================
-- TRIGGER: Auto-update updated_at
-- ============================================================================

CREATE OR REPLACE FUNCTION update_admin_url_whitelist_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_admin_url_whitelist_updated_at ON admin_url_whitelist;
CREATE TRIGGER trg_admin_url_whitelist_updated_at
    BEFORE UPDATE ON admin_url_whitelist
    FOR EACH ROW
    EXECUTE FUNCTION update_admin_url_whitelist_updated_at();

-- ============================================================================
-- FOREIGN KEY
-- ============================================================================

ALTER TABLE admin_url_whitelist
    DROP CONSTRAINT IF EXISTS fk_admin_url_user;

ALTER TABLE admin_url_whitelist
    ADD CONSTRAINT fk_admin_url_user
    FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE;

-- ============================================================================
-- COMMENTS
-- ============================================================================

COMMENT ON TABLE admin_url_whitelist IS 'Cryptographically secure admin URL whitelist with HMAC-SHA256 tokens';
COMMENT ON COLUMN admin_url_whitelist.token IS 'HMAC-SHA256 token (64 hex chars = 256 bits)';
COMMENT ON COLUMN admin_url_whitelist.user_id IS 'User binding - each URL tied to specific user';
COMMENT ON COLUMN admin_url_whitelist.bound_ip IS 'Optional IP binding for maximum security mode';
COMMENT ON COLUMN admin_url_whitelist.is_emergency IS 'Emergency URL flag (one-time use, 1-hour expiry)';
