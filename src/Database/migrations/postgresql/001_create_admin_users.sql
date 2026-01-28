-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Admin Users Table
-- ============================================================================
-- Database: PostgreSQL
-- Version: 1.0.0
-- ============================================================================
-- Enterprise-grade admin users with:
-- - Secure password hashing (Argon2id ready)
-- - Role-based access control (RBAC)
-- - Two-factor authentication (2FA)
-- - Account lockout protection
-- - Complete audit trail
-- ============================================================================

CREATE TABLE IF NOT EXISTS admin_users (
    -- Primary key
    id BIGSERIAL PRIMARY KEY,

    -- Authentication
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,

    -- Profile
    name VARCHAR(100) NOT NULL,
    avatar_url VARCHAR(500) DEFAULT NULL,

    -- RBAC
    role VARCHAR(50) NOT NULL DEFAULT 'admin',
    permissions JSONB DEFAULT '[]'::JSONB,

    -- Account status
    is_active BOOLEAN DEFAULT true,
    email_verified_at TIMESTAMP DEFAULT NULL,

    -- Login tracking
    last_login_at TIMESTAMP DEFAULT NULL,
    last_login_ip VARCHAR(45) DEFAULT NULL,

    -- Brute force protection
    failed_login_attempts INTEGER DEFAULT 0,
    locked_until TIMESTAMP DEFAULT NULL,

    -- Two-factor authentication (ENABLED BY DEFAULT for security)
    two_factor_secret VARCHAR(255) DEFAULT NULL,
    two_factor_enabled BOOLEAN DEFAULT true,
    two_factor_method VARCHAR(20) DEFAULT 'email',
    two_factor_recovery_codes JSONB DEFAULT NULL,

    -- Password reset
    password_reset_token VARCHAR(64) DEFAULT NULL,
    password_reset_expires_at TIMESTAMP DEFAULT NULL,

    -- CLI token for secure URL retrieval
    -- Token is HMAC-SHA256(user_id + password + timestamp, master_secret)
    -- Only the SHA-256 HASH is stored (not the token itself)
    cli_token_hash VARCHAR(128) DEFAULT NULL,
    cli_token_generated_at TIMESTAMP DEFAULT NULL,
    cli_token_generation_count INTEGER DEFAULT 0,

    -- Master admin flag (only one per installation)
    is_master BOOLEAN DEFAULT FALSE,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    CONSTRAINT uq_admin_users_email UNIQUE (email)
);

-- ============================================================================
-- INDEXES
-- ============================================================================

CREATE INDEX IF NOT EXISTS idx_admin_users_email ON admin_users(email);
CREATE INDEX IF NOT EXISTS idx_admin_users_role ON admin_users(role);
CREATE INDEX IF NOT EXISTS idx_admin_users_active ON admin_users(is_active);
CREATE INDEX IF NOT EXISTS idx_admin_users_locked ON admin_users(locked_until) WHERE locked_until IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_admin_users_reset_token ON admin_users(password_reset_token) WHERE password_reset_token IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_admin_users_cli_token ON admin_users(cli_token_hash) WHERE cli_token_hash IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_admin_users_is_master ON admin_users(is_master) WHERE is_master = TRUE;
CREATE INDEX IF NOT EXISTS idx_admin_users_2fa_method ON admin_users(two_factor_method) WHERE two_factor_enabled = TRUE;

-- ============================================================================
-- TRIGGER: Auto-update updated_at
-- ============================================================================

CREATE OR REPLACE FUNCTION update_admin_users_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_admin_users_updated_at ON admin_users;
CREATE TRIGGER trg_admin_users_updated_at
    BEFORE UPDATE ON admin_users
    FOR EACH ROW
    EXECUTE FUNCTION update_admin_users_updated_at();

-- ============================================================================
-- COMMENTS
-- ============================================================================

COMMENT ON TABLE admin_users IS 'Enterprise admin users with RBAC, 2FA, and brute force protection';
COMMENT ON COLUMN admin_users.password_hash IS 'Argon2id hashed password (never store plain text)';
COMMENT ON COLUMN admin_users.permissions IS 'JSON array of specific permissions beyond role defaults';
COMMENT ON COLUMN admin_users.failed_login_attempts IS 'Counter for brute force protection (reset on successful login)';
COMMENT ON COLUMN admin_users.locked_until IS 'Account locked until this timestamp (NULL = not locked)';
COMMENT ON COLUMN admin_users.two_factor_secret IS 'TOTP secret for 2FA (encrypted at rest recommended)';
COMMENT ON COLUMN admin_users.two_factor_method IS 'Two-factor authentication method: totp, email, telegram, discord, slack';
COMMENT ON COLUMN admin_users.two_factor_recovery_codes IS 'JSON array of hashed recovery codes';
COMMENT ON COLUMN admin_users.cli_token_hash IS 'SHA-256 hash of CLI token (used to verify token for URL generation)';
COMMENT ON COLUMN admin_users.cli_token_generated_at IS 'Timestamp of last CLI token generation';
COMMENT ON COLUMN admin_users.cli_token_generation_count IS 'Number of times CLI token was generated (security audit)';
COMMENT ON COLUMN admin_users.is_master IS 'Master admin flag (only one per installation, has full privileges)';
