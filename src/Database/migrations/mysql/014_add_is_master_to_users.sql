-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Add is_master flag to admin_users
-- ============================================================================
-- Database: MySQL
-- Version: 1.0.0
-- ============================================================================
-- Adds is_master flag to distinguish the master admin from sub-admins.
-- Only the master admin can:
-- - Create emergency tokens
-- - Create sub-admin tokens
-- - Access critical security settings
-- ============================================================================

-- Add is_master column
ALTER TABLE admin_users
ADD COLUMN IF NOT EXISTS is_master TINYINT(1) DEFAULT 0;

-- Create index for fast lookup
CREATE INDEX idx_admin_users_is_master ON admin_users(is_master);

-- ============================================================================
-- Set first super_admin as master (if not already set)
-- ============================================================================

UPDATE admin_users
SET is_master = 1
WHERE id = (
    SELECT min_id FROM (
        SELECT MIN(id) as min_id FROM admin_users
        WHERE role = 'super_admin' AND is_active = 1
    ) AS subquery
)
AND NOT EXISTS (
    SELECT 1 FROM admin_users WHERE is_master = 1
);

-- ============================================================================
-- COMMENTS (MySQL does not support COMMENT ON COLUMN, use ALTER TABLE)
-- ============================================================================
