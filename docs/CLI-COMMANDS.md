# CLI Commands Reference

All CLI commands are located in the `elf/` directory.

## Authentication Model

All commands (except `token-emergency-use.php`) require **three authentication factors**:

| Factor | Parameter | Description |
|--------|-----------|-------------|
| Token | `--token=` | Master CLI token (generated during installation) |
| Email | `--email=` | Admin email address |
| Password | `--password=` | Admin password |

This triple authentication ensures that even if one factor is compromised, the system remains secure.

## Commands

### install.php

First-time installation. Creates database schema, admin user, and generates all credentials.

```bash
php elf/install.php [options]
```

**Options:**
- `--email=EMAIL` - Admin email (default: admin@example.com)
- `--admin-name=NAME` - Admin name (default: Administrator)
- `--driver=DRIVER` - Database: postgresql or mysql (default: postgresql)
- `--host=HOST` - Database host (default: localhost)
- `--port=PORT` - Database port (default: 5432/3306)
- `--database=DB` - Database name (default: admin_panel)
- `--username=USER` - Database username (default: admin)
- `--password=PASS` - Database password (default: secret)
- `--send-email=EMAIL` - Send credentials to this email
- `--send-telegram=ID` - Send credentials to Telegram chat ID
- `--json` - Output as JSON (for automation)
- `--help` - Show help

**Output (shown only once!):**
- Admin URL (secret, 128-bit entropy)
- Admin password (secure, 20 chars with special characters)
- Master CLI token (for all future CLI operations)

**Example:**
```bash
php elf/install.php --email=admin@company.com --driver=postgresql
```

---

### password-change.php

Changes the admin password.

```bash
php elf/password-change.php --token=TOKEN --email=EMAIL --password=CURRENT --new-password=NEW
```

**Required:**
- `--token=TOKEN` - Master CLI token
- `--email=EMAIL` - Admin email
- `--password=CURRENT` - Current password
- `--new-password=NEW` - New password

**Password Requirements:**
- Minimum 12 characters
- At least 1 number
- At least 1 special character (!@#$%^&*-_=+)

**Effects:**
- All active sessions are invalidated
- Master CLI token remains unchanged
- Admin URL remains unchanged

**Options:**
- `--json` - Output as JSON
- `--help` - Show help

---

### token-master-regenerate.php

Regenerates the master CLI token.

```bash
php elf/token-master-regenerate.php --token=CURRENT_TOKEN --email=EMAIL --password=PASSWORD
```

**Required:**
- `--token=CURRENT_TOKEN` - Current master CLI token
- `--email=EMAIL` - Admin email
- `--password=PASSWORD` - Admin password

**Effects:**
- New token is generated and shown (save it!)
- Old token is immediately invalidated
- Password and admin URL remain unchanged

**Options:**
- `--json` - Output as JSON
- `--help` - Show help

---

### token-emergency-create.php

Creates a one-time emergency login token.

```bash
php elf/token-emergency-create.php --token=TOKEN --email=EMAIL --password=PASSWORD [options]
```

**Required:**
- `--token=TOKEN` - Master CLI token
- `--email=EMAIL` - Admin email
- `--password=PASSWORD` - Admin password

**Options:**
- `--name=NAME` - Token description (default: "Emergency Login Token")
- `--expires=DAYS` - Expiration in days (default: 30)
- `--json` - Output as JSON
- `--help` - Show help

**Security:**
- Token is ONE-TIME USE (invalidated after login)
- Bypasses password AND 2FA verification
- Store offline (print and put in a safe)

**Example:**
```bash
php elf/token-emergency-create.php --token=master-xxx --email=admin@company.com --password=MyPass123! --name="Safe deposit box" --expires=365
```

---

### token-emergency-use.php

Uses an emergency token to reveal the admin URL.

```bash
php elf/token-emergency-use.php --token=EMERGENCY_TOKEN
```

**Required:**
- `--token=EMERGENCY_TOKEN` - Emergency token (starts with `emergency-`)

**Note:** This command does NOT require email/password - it's designed for when you've lost access.

**Effects:**
- Admin URL is revealed
- Emergency token is invalidated (one-time use)

**Options:**
- `--json` - Output as JSON
- `--help` - Show help

**Alternative:** Use in browser:
```
http://localhost:8080/emergency-login?token=YOUR_EMERGENCY_TOKEN
```

---

## Security Best Practices

### 1. Store Credentials Securely

After installation, store in a password manager:
- Admin URL
- Admin password
- Master CLI token
- At least one emergency token (stored offline)

### 2. Generate Emergency Tokens Proactively

Create emergency tokens BEFORE you need them:
```bash
php elf/token-emergency-create.php --token=... --email=... --password=... --name="Paper backup 1"
php elf/token-emergency-create.php --token=... --email=... --password=... --name="Paper backup 2"
```

Print and store in different secure locations (safe, bank deposit box).

### 3. Rotate Master Token Periodically

Regenerate the master token periodically:
```bash
php elf/token-master-regenerate.php --token=... --email=... --password=...
```

### 4. Change Password After Installation

The installation password is random but was displayed on screen. Change it immediately:
```bash
php elf/password-change.php --token=... --email=... --password=INSTALL_PASS --new-password=YOUR_SECRET_PASS
```

---

## Automation (JSON Output)

All commands support `--json` for automation:

```bash
php elf/install.php --json > credentials.json

# Parse with jq
cat credentials.json | jq '.admin_url'
cat credentials.json | jq '.master_token'
```

**Warning:** JSON output contains sensitive data. Handle securely.

---

## Troubleshooting

### "Invalid master token"

The token is hashed with Argon2id. Common issues:
- Copy-paste error (check for extra spaces)
- Token was regenerated
- Wrong user account

### "Invalid password"

- Password is case-sensitive
- Check for special characters
- Account might be locked (check `locked_until` in database)

### "User not found"

- Check email spelling
- User might be deactivated (`is_active = false`)

### Lost all credentials

If you lost everything (admin URL, password, master token, emergency tokens):
1. You still have database access? Reset directly:
   ```sql
   -- Generate new password hash
   -- php -r "echo password_hash('NewPass123!', PASSWORD_ARGON2ID);"
   UPDATE admin_users SET password_hash = 'HASH' WHERE email = 'admin@example.com';

   -- Clear token to allow regeneration
   UPDATE admin_users SET cli_token_hash = NULL WHERE email = 'admin@example.com';
   ```

2. No database access? Reinstall:
   ```bash
   # Drop all tables and reinstall
   php elf/install.php
   ```
