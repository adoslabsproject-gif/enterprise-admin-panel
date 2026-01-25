-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Modules Registry Table
-- ============================================================================
-- Database: PostgreSQL
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
    id BIGSERIAL PRIMARY KEY,

    -- Module unique identifier (fully qualified class name)
    name VARCHAR(255) NOT NULL,

    -- Module display name (human-readable)
    display_name VARCHAR(100) DEFAULT NULL,

    -- Module description
    description TEXT DEFAULT NULL,

    -- Module version (semantic versioning)
    version VARCHAR(20) DEFAULT '1.0.0',

    -- Enable/disable state (without uninstall)
    enabled BOOLEAN DEFAULT true,

    -- Module configuration (JSON)
    config JSONB DEFAULT '{}',

    -- Installation timestamp
    installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Last update timestamp
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    -- Constraints
    CONSTRAINT uq_admin_modules_name UNIQUE (name)
);

-- ============================================================================
-- INDEXES
-- ============================================================================

CREATE INDEX IF NOT EXISTS idx_admin_modules_enabled ON admin_modules(enabled);
CREATE INDEX IF NOT EXISTS idx_admin_modules_name ON admin_modules(name);

-- ============================================================================
-- TRIGGER: Auto-update updated_at
-- ============================================================================

CREATE OR REPLACE FUNCTION update_admin_modules_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_admin_modules_updated_at ON admin_modules;
CREATE TRIGGER trg_admin_modules_updated_at
    BEFORE UPDATE ON admin_modules
    FOR EACH ROW
    EXECUTE FUNCTION update_admin_modules_updated_at();

-- ============================================================================
-- COMMENTS
-- ============================================================================

COMMENT ON TABLE admin_modules IS 'Registry of installed admin panel modules';
COMMENT ON COLUMN admin_modules.name IS 'Module class name (unique identifier)';
COMMENT ON COLUMN admin_modules.enabled IS 'Module state: true = enabled, false = disabled but installed';
COMMENT ON COLUMN admin_modules.config IS 'Module-specific configuration as JSONB';
