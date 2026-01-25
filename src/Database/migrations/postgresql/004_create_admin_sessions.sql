-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Admin Sessions Table
-- ============================================================================
-- Database: PostgreSQL
-- Version: 1.0.0
-- ============================================================================
-- Secure session management with:
-- - Token-based session IDs (256-bit)
-- - User binding
-- - IP and user-agent tracking
-- - Session payload (JSON)
-- - Activity tracking
-- - Automatic expiry
-- ============================================================================

CREATE TABLE IF NOT EXISTS admin_sessions (
    -- Session ID (256-bit token, 64 hex chars)
    id VARCHAR(128) PRIMARY KEY,

    -- User binding
    user_id BIGINT NOT NULL,

    -- Client information
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT DEFAULT NULL,

    -- Session payload (arbitrary data)
    payload JSONB DEFAULT '{}'::JSONB,

    -- Activity tracking
    last_activity TIMESTAMP NOT NULL,

    -- Expiry
    expires_at TIMESTAMP NOT NULL,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Foreign key
    CONSTRAINT fk_admin_sessions_user
        FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
);

-- ============================================================================
-- INDEXES
-- ============================================================================

CREATE INDEX IF NOT EXISTS idx_admin_sessions_user ON admin_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_admin_sessions_expires ON admin_sessions(expires_at);
CREATE INDEX IF NOT EXISTS idx_admin_sessions_activity ON admin_sessions(last_activity);
CREATE INDEX IF NOT EXISTS idx_admin_sessions_ip ON admin_sessions(ip_address);

-- ============================================================================
-- COMMENTS
-- ============================================================================

COMMENT ON TABLE admin_sessions IS 'Secure admin sessions with token-based IDs';
COMMENT ON COLUMN admin_sessions.id IS 'Session token (256-bit, cryptographically secure)';
COMMENT ON COLUMN admin_sessions.payload IS 'Session data as JSONB (flash messages, CSRF tokens, etc.)';
COMMENT ON COLUMN admin_sessions.last_activity IS 'Last activity timestamp for idle timeout';
