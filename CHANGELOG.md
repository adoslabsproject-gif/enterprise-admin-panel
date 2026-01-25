# Changelog

All notable changes to `enterprise-admin-panel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-01-24

### Added
- **Cryptographic URL Security**
  - HMAC-SHA256 based URL generation (256-bit entropy)
  - Multiple URL patterns (6 formats) for anti-fingerprinting
  - Per-user URL binding (prevents sharing)
  - Optional IP binding (max security mode)
  - Automatic 4-hour rotation
  - Instant revocation support
  - Emergency access URLs (one-time use, 1 hour)
  - Complete audit trail
  - `CryptographicAdminUrlGenerator` class

- **Modular Architecture**
  - Auto-discovery from composer packages
  - `ModuleRegistry` for dynamic module loading
  - `AdminModuleInterface` contract
  - `BaseModule` abstract class for easier development
  - Priority-based loading order
  - Enable/disable without uninstall
  - Per-module configuration

- **Built-in Module Examples**
  - `SecurityShieldModule` - Integration for `enterprise-security-shield`
  - Full tab system (Security, WAF, Honeypot, Banned IPs, etc.)
  - Auto-registration when package installed

- **Database Schema**
  - `admin_url_whitelist` - Cryptographic URL storage
  - `admin_modules` - Module registry
  - PostgreSQL migrations included
  - Indexes for performance
  - Foreign key support (optional)

- **Documentation**
  - Comprehensive README with examples
  - Quick start guide (`examples/quick-start.php`)
  - API reference
  - Security best practices
  - Module development guide

- **Developer Experience**
  - PSR-3 logger interface support
  - PSR-4 autoloading
  - PHPStan Level 9 compatible
  - Composer scripts for testing
  - MIT License

### Security
- HMAC-SHA256 for keyed hashing (prevents length extension)
- CSPRNG (cryptographically secure random) for nonces
- Time-based rotation (4-hour windows)
- User binding prevents URL sharing
- IP binding option for max security
- Audit log for all admin actions
- Fail-safe error handling
- No sensitive data in logs (token prefixes only)

### Performance
- Static validation cache (prevents duplicate queries)
- Lazy module instantiation
- Efficient database queries with indexes
- Automatic cleanup of expired URLs
- Minimal overhead (<1ms per validation)

---

## Versioning Strategy

- **Major (X.0.0)**: Breaking changes to public API
- **Minor (0.X.0)**: New features, backward compatible
- **Patch (0.0.X)**: Bug fixes, security patches

---

## Upgrade Guide

### From 0.x to 1.0.0
This is the initial release. No upgrade needed.

---

## Security Vulnerabilities

If you discover a security vulnerability, please email adoslabs@gmail.com.
All security vulnerabilities will be promptly addressed.

---

## Links

- [Homepage](https://github.com/adoslabs/enterprise-admin-panel)
- [Documentation](https://github.com/adoslabs/enterprise-admin-panel/blob/main/README.md)
- [Issue Tracker](https://github.com/adoslabs/enterprise-admin-panel/issues)
- [Releases](https://github.com/adoslabs/enterprise-admin-panel/releases)
