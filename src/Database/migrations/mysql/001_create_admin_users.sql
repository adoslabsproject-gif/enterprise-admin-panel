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

    -- Brute force protection (login)
    failed_login_attempts INT UNSIGNED DEFAULT 0,
    locked_until TIMESTAMP NULL DEFAULT NULL,

    -- Recovery code rate limiting (separate from login)
    recovery_attempts INT UNSIGNED DEFAULT 0,
    recovery_locked_until TIMESTAMP NULL DEFAULT NULL,

    -- Two-factor authentication (ENABLED BY DEFAULT for security)
    two_factor_secret VARCHAR(255) DEFAULT NULL,
    two_factor_enabled TINYINT(1) DEFAULT 1,
    two_factor_method VARCHAR(20) DEFAULT 'email',
    two_factor_recovery_codes JSON DEFAULT NULL,

    -- Password reset
    password_reset_token VARCHAR(64) DEFAULT NULL,
    password_reset_expires_at TIMESTAMP NULL DEFAULT NULL,

    -- CLI token for secure URL retrieval
    -- Token is HMAC-SHA256(user_id + password + timestamp, master_secret)
    -- Only the SHA-256 HASH is stored (not the token itself)
    cli_token_hash VARCHAR(128) DEFAULT NULL,
    cli_token_generated_at TIMESTAMP NULL DEFAULT NULL,
    cli_token_generation_count INT UNSIGNED DEFAULT 0,

    -- Master admin flag (only one per installation)
    is_master TINYINT(1) DEFAULT 0,

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
    INDEX idx_admin_users_recovery_locked (recovery_locked_until),
    INDEX idx_admin_users_reset_token (password_reset_token),
    INDEX idx_admin_users_cli_token (cli_token_hash),
    INDEX idx_admin_users_is_master (is_master),
    INDEX idx_admin_users_2fa_method (two_factor_method)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
