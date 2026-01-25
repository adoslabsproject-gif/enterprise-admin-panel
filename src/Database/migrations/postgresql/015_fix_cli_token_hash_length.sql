-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Fix CLI Token Hash Column Length
-- ============================================================================
-- Database: PostgreSQL
-- Version: 1.0.2
-- ============================================================================
-- Argon2id hashes are ~96 characters, VARCHAR(64) is too short.
-- This migration extends the column to VARCHAR(255).
-- ============================================================================

ALTER TABLE admin_users
ALTER COLUMN cli_token_hash TYPE VARCHAR(255);

COMMENT ON COLUMN admin_users.cli_token_hash IS 'Argon2id hash of CLI token (used to verify token for CLI operations)';
