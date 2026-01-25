-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Admin Users Table
-- ============================================================================
-- Database: MySQL 8.0+
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
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Authentication
    email VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,

    -- Profile
    name VARCHAR(100) NOT NULL,
    avatar_url VARCHAR(500) DEFAULT NULL,

    -- RBAC
    role VARCHAR(50) NOT NULL DEFAULT 'admin',
    permissions JSON DEFAULT NULL,

    -- Account status
    is_active TINYINT(1) DEFAULT 1,
    email_verified_at TIMESTAMP NULL DEFAULT NULL,

    -- Login tracking
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    last_login_ip VARCHAR(45) DEFAULT NULL,

    -- Brute force protection
    failed_login_attempts INT UNSIGNED DEFAULT 0,
    locked_until TIMESTAMP NULL DEFAULT NULL,

    -- Two-factor authentication
    two_factor_secret VARCHAR(255) DEFAULT NULL,
    two_factor_enabled TINYINT(1) DEFAULT 0,
    two_factor_recovery_codes JSON DEFAULT NULL,

    -- Password reset
    password_reset_token VARCHAR(64) DEFAULT NULL,
    password_reset_expires_at TIMESTAMP NULL DEFAULT NULL,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    UNIQUE KEY uq_admin_users_email (email),

    -- Indexes
    INDEX idx_admin_users_email (email),
    INDEX idx_admin_users_role (role),
    INDEX idx_admin_users_active (is_active),
    INDEX idx_admin_users_locked (locked_until),
    INDEX idx_admin_users_reset_token (password_reset_token)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
