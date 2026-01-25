-- ============================================================================
-- ENTERPRISE ADMIN PANEL: URL Whitelist Table
-- ============================================================================
-- Database: MySQL 8.0+
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
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- URL token (HMAC-SHA256, 64 hex characters = 256 bits)
    token VARCHAR(64) NOT NULL,

    -- User binding (1 URL = 1 user, prevents sharing)
    user_id BIGINT UNSIGNED NOT NULL,

    -- URL pattern used (for analytics/debugging)
    pattern VARCHAR(50) NOT NULL,

    -- Expiry (automatic rotation every 4 hours)
    expires_at TIMESTAMP NOT NULL,

    -- IP binding (optional, max security mode)
    bound_ip VARCHAR(45) DEFAULT NULL,

    -- Emergency flag (one-time use URLs)
    is_emergency TINYINT(1) DEFAULT 0,

    -- Max uses (for emergency URLs, NULL = unlimited)
    max_uses INT UNSIGNED DEFAULT NULL,

    -- Access tracking (audit trail)
    access_count INT UNSIGNED DEFAULT 0,
    last_used_at TIMESTAMP NULL DEFAULT NULL,

    -- Revocation support (instant invalidation)
    revoked TINYINT(1) DEFAULT 0,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    revoke_reason VARCHAR(255) DEFAULT NULL,

    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    UNIQUE KEY uq_admin_url_token (token),

    -- Indexes
    INDEX idx_admin_url_token_user (token, user_id),
    INDEX idx_admin_url_expires (expires_at),
    INDEX idx_admin_url_revoked (revoked, revoked_at),
    INDEX idx_admin_url_user_id (user_id),
    INDEX idx_admin_url_bound_ip (bound_ip),
    INDEX idx_admin_url_emergency (is_emergency),

    -- Foreign key
    CONSTRAINT fk_admin_url_user
        FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
