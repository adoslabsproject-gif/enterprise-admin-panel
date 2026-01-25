-- ============================================================================
-- Enterprise Lightning Framework (ELF) - PostgreSQL Initialization
-- ============================================================================
-- This file is executed automatically when the PostgreSQL container starts
-- for the first time. It sets up the initial database configuration.
-- ============================================================================

-- Enable useful extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- Set timezone
SET timezone = 'UTC';

-- Grant privileges
GRANT ALL PRIVILEGES ON DATABASE admin_panel TO admin;
