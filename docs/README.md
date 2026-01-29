# Enterprise Admin Panel Documentation

## Quick Links

| Document | Description |
|----------|-------------|
| [QUICK_START.md](QUICK_START.md) | Get running in 5 minutes |
| [SECURITY.md](SECURITY.md) | All security features (2FA, CSRF, XSS, rate limiting) |
| [URL-SECURITY.md](URL-SECURITY.md) | Cryptographic URL system deep-dive |
| [ARCHITECTURE.md](ARCHITECTURE.md) | System design and components |
| [DATABASE.md](DATABASE.md) | Database configuration and migrations |
| [PERFORMANCE.md](PERFORMANCE.md) | OPcache, Redis, caching strategies |
| [CLI-COMMANDS.md](CLI-COMMANDS.md) | CLI installation and management |
| [ELF-COMMANDS.md](ELF-COMMANDS.md) | Enterprise Lightning Framework commands |
| [LOGGING.md](LOGGING.md) | Logging architecture and configuration |
| [MODULES.md](MODULES.md) | Module system and creating custom modules |
| [FRAMEWORK.md](FRAMEWORK.md) | Integration with Enterprise Lightning Framework |
| [2FA-SETUP.md](2FA-SETUP.md) | Two-factor authentication setup |
| [PACKAGES.md](PACKAGES.md) | Related packages and integration |
| [COMPLETE_GUIDE.md](COMPLETE_GUIDE.md) | Comprehensive reference guide |

## Package Overview

Enterprise Admin Panel provides a secure, cryptographically-protected admin interface.

### Key Features

| Feature | Description |
|---------|-------------|
| Cryptographic URLs | 256-bit HMAC signatures, no predictable `/admin` |
| 2FA Default | Email, Telegram, Discord, Slack, TOTP |
| Session Security | IP binding, device tracking, auto-rotation |
| Database Pool | Connection pooling with circuit breaker |
| Module System | Pluggable admin modules |
| Audit Trail | Complete logging of all actions |

### Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                          Admin Panel                                  │
├─────────────────────────────────────────────────────────────────────┤
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐               │
│  │   Modules    │  │  Middleware  │  │   Services   │               │
│  │ - Dashboard  │  │ - Auth       │  │ - AuthService│               │
│  │ - Users      │  │ - CSRF       │  │ - 2FA Service│               │
│  │ - Logs       │  │ - HTTPS      │  │ - Session    │               │
│  │ - Settings   │  │ - AccessLog  │  │ - Audit      │               │
│  └──────────────┘  └──────────────┘  └──────────────┘               │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │                    Database Pool                              │   │
│  │   - Connection pooling (LIFO)                                 │   │
│  │   - Circuit breaker (distributed via Redis)                   │   │
│  │   - Query validation                                          │   │
│  │   - Metrics collection                                        │   │
│  └──────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────┘
```

### Security at a Glance

- **URL Security**: 256-bit entropy (2^256 combinations)
- **Brute Force**: Account lockout after 5 failed attempts
- **CSRF**: Stateless tokens with 60-minute validity
- **XSS**: All output escaped with `esc()` helper
- **SQL Injection**: Prepared statements only
- **Session**: HttpOnly, Secure, SameSite=Strict cookies
- **Rate Limiting**: Sliding window algorithm

### Integration with PSR-3 Logger

When `enterprise-psr3-logger` is installed:
- Channel configuration UI
- Log file viewer
- Telegram notification setup
- PHP error log monitoring

```bash
composer require ados-labs/enterprise-psr3-logger
```

### Getting Started

```bash
# 1. Install
composer require ados-labs/enterprise-admin-panel

# 2. Configure .env
cat > .env << 'EOF'
DB_DRIVER=pgsql
DB_HOST=localhost
DB_DATABASE=admin_panel
DB_USERNAME=admin
DB_PASSWORD=your_secure_password
EOF

# 3. Run installer
php vendor/ados-labs/enterprise-admin-panel/elf/install.php \
  --email=admin@example.com

# 4. Save the secret URL and credentials!
```

## Support

- GitHub Issues: https://github.com/adoslabsproject-gif/enterprise-admin-panel/issues
- Security: security@adoslabs.com

## License

MIT License - See [LICENSE](../LICENSE)

---

**Part of the Enterprise Lightning Framework**

Author: Nicola Cucurachi (ADOS Labs)
