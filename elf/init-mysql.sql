-- ============================================================================
-- Enterprise Lightning Framework (ELF) - MySQL Initialization
-- ============================================================================
-- This file is executed automatically when the MySQL container starts
-- for the first time. It sets up the initial database configuration.
-- ============================================================================

-- Set timezone
SET GLOBAL time_zone = '+00:00';

-- Grant privileges
GRANT ALL PRIVILEGES ON admin_panel.* TO 'admin'@'%';
FLUSH PRIVILEGES;
