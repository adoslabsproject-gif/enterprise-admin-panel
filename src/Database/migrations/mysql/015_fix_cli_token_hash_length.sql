-- ============================================================================
-- ENTERPRISE ADMIN PANEL: Fix CLI Token Hash Column Length
-- ============================================================================
-- Database: MySQL
-- Version: 1.0.2
-- ============================================================================
-- Argon2id hashes are ~96 characters, VARCHAR(64) is too short.
-- This migration extends the column to VARCHAR(255).
-- ============================================================================

ALTER TABLE admin_users
MODIFY COLUMN cli_token_hash VARCHAR(255) DEFAULT NULL;
