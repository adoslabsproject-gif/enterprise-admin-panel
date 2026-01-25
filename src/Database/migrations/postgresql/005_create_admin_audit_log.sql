-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Admin Audit Log Table
-- ============================================================================
-- Database: PostgreSQL
-- Version: 1.0.0
-- ============================================================================
-- Complete audit trail with:
-- - User action tracking
-- - Entity change tracking (old/new values)
-- - Client information (IP, user-agent)
-- - Timestamp precision
-- ============================================================================

CREATE TABLE IF NOT EXISTS admin_audit_log (
    -- Primary key
    id BIGSERIAL PRIMARY KEY,

    -- User who performed the action (NULL for system actions)
    user_id BIGINT DEFAULT NULL,

    -- Action performed
    action VARCHAR(100) NOT NULL,

    -- Entity affected
    entity_type VARCHAR(100) DEFAULT NULL,
    entity_id BIGINT DEFAULT NULL,

    -- Change tracking
    old_values JSONB DEFAULT NULL,
    new_values JSONB DEFAULT NULL,

    -- Additional context
    metadata JSONB DEFAULT NULL,

    -- Client information
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,

    -- Timestamp (with timezone)
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,

    -- Foreign key (soft - allows NULL for deleted users)
    CONSTRAINT fk_admin_audit_user
        FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE SET NULL
);

-- ============================================================================
-- INDEXES
-- ============================================================================

CREATE INDEX IF NOT EXISTS idx_admin_audit_user ON admin_audit_log(user_id);
CREATE INDEX IF NOT EXISTS idx_admin_audit_action ON admin_audit_log(action);
CREATE INDEX IF NOT EXISTS idx_admin_audit_entity ON admin_audit_log(entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_admin_audit_time ON admin_audit_log(created_at);
CREATE INDEX IF NOT EXISTS idx_admin_audit_ip ON admin_audit_log(ip_address);

-- Partial index for security-related actions (faster security audits)
CREATE INDEX IF NOT EXISTS idx_admin_audit_security ON admin_audit_log(action, created_at)
    WHERE action IN ('login', 'logout', 'login_failed', 'password_change', '2fa_enable', '2fa_disable', 'account_lock');

-- ============================================================================
-- COMMENTS
-- ============================================================================

COMMENT ON TABLE admin_audit_log IS 'Complete audit trail of all admin actions';
COMMENT ON COLUMN admin_audit_log.action IS 'Action type (e.g., create, update, delete, login, logout)';
COMMENT ON COLUMN admin_audit_log.entity_type IS 'Type of entity affected (e.g., user, module, config)';
COMMENT ON COLUMN admin_audit_log.old_values IS 'Previous values before change (for updates)';
COMMENT ON COLUMN admin_audit_log.new_values IS 'New values after change (for creates/updates)';
COMMENT ON COLUMN admin_audit_log.metadata IS 'Additional context (request ID, correlation ID, etc.)';
