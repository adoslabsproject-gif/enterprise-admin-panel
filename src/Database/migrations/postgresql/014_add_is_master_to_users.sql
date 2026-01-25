-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Add is_master flag to admin_users
-- ============================================================================
-- Database: PostgreSQL
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
ADD COLUMN IF NOT EXISTS is_master BOOLEAN DEFAULT false;

-- Create index for fast lookup
CREATE INDEX IF NOT EXISTS idx_admin_users_is_master
ON admin_users(is_master)
WHERE is_master = true;

-- ============================================================================
-- Set first super_admin as master (if not already set)
-- ============================================================================

UPDATE admin_users
SET is_master = true
WHERE id = (
    SELECT id FROM admin_users
    WHERE role = 'super_admin' AND is_active = true
    ORDER BY created_at ASC
    LIMIT 1
)
AND NOT EXISTS (
    SELECT 1 FROM admin_users WHERE is_master = true
);

-- ============================================================================
-- COMMENTS
-- ============================================================================

COMMENT ON COLUMN admin_users.is_master IS 'True for the primary master admin who can manage tokens and critical settings';
