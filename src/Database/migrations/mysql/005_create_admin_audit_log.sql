-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Admin Audit Log Table
-- ============================================================================
-- Database: MySQL 8.0+
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
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- User who performed the action (NULL for system actions)
    user_id BIGINT UNSIGNED DEFAULT NULL,

    -- Action performed
    action VARCHAR(100) NOT NULL,

    -- Entity affected
    entity_type VARCHAR(100) DEFAULT NULL,
    entity_id BIGINT UNSIGNED DEFAULT NULL,

    -- Change tracking
    old_values JSON DEFAULT NULL,
    new_values JSON DEFAULT NULL,

    -- Additional context
    metadata JSON DEFAULT NULL,

    -- Client information
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,

    -- Timestamp
    created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),

    -- Indexes
    INDEX idx_admin_audit_user (user_id),
    INDEX idx_admin_audit_action (action),
    INDEX idx_admin_audit_entity (entity_type, entity_id),
    INDEX idx_admin_audit_time (created_at),
    INDEX idx_admin_audit_ip (ip_address),

    -- Foreign key (soft - allows NULL for deleted users)
    CONSTRAINT fk_admin_audit_user
        FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE SET NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
