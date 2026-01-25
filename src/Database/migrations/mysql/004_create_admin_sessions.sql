-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Admin Sessions Table
-- ============================================================================
-- Database: MySQL 8.0+
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
    user_id BIGINT UNSIGNED NOT NULL,

    -- Client information
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT DEFAULT NULL,

    -- Session payload (arbitrary data)
    payload JSON DEFAULT NULL,

    -- Activity tracking
    last_activity TIMESTAMP NOT NULL,

    -- Expiry
    expires_at TIMESTAMP NOT NULL,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Indexes
    INDEX idx_admin_sessions_user (user_id),
    INDEX idx_admin_sessions_expires (expires_at),
    INDEX idx_admin_sessions_activity (last_activity),
    INDEX idx_admin_sessions_ip (ip_address),

    -- Foreign key
    CONSTRAINT fk_admin_sessions_user
        FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
