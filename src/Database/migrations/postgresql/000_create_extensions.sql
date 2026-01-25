-- ============================================================================
-- ENTERPRISE ADMIN PANEL: PostgreSQL Extensions
-- ============================================================================
-- Database: PostgreSQL
-- Version: 1.0.0
-- ============================================================================
-- Creates required PostgreSQL extensions for the admin panel.
-- Must run FIRST before any other migrations.
-- ============================================================================

-- UUID generation (uuid_generate_v4)
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Cryptographic functions (gen_random_bytes, digest, etc.)
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- ============================================================================
-- COMMENTS
-- ============================================================================

COMMENT ON EXTENSION "uuid-ossp" IS 'UUID generation functions for PostgreSQL';
COMMENT ON EXTENSION pgcrypto IS 'Cryptographic functions for secure random bytes generation';
