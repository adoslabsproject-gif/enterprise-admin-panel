-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Modules Registry Table
-- ============================================================================
-- Database: MySQL 8.0+
-- Version: 1.0.0
-- ============================================================================
-- Module management with:
-- - Enable/disable state (without uninstall)
-- - Per-module configuration (JSON)
-- - Installation timestamp
-- - Module metadata
-- ============================================================================

CREATE TABLE IF NOT EXISTS admin_modules (
    -- Primary key
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Module unique identifier (fully qualified class name)
    name VARCHAR(255) NOT NULL,

    -- Module display name (human-readable)
    display_name VARCHAR(100) DEFAULT NULL,

    -- Module description
    description TEXT DEFAULT NULL,

    -- Module version (semantic versioning)
    version VARCHAR(20) DEFAULT '1.0.0',

    -- Enable/disable state (without uninstall)
    enabled TINYINT(1) DEFAULT 1,

    -- Module configuration (JSON)
    config JSON DEFAULT NULL,

    -- Installation timestamp
    installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Last update timestamp
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Constraints
    UNIQUE KEY uq_admin_modules_name (name),

    -- Indexes
    INDEX idx_admin_modules_enabled (enabled),
    INDEX idx_admin_modules_name (name)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
