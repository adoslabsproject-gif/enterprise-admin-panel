# Multi-Channel 2FA Setup

## Overview

2FA is enabled by default. After entering email/password, a 6-digit code is sent to your configured channel.

Supported channels:
- Email (default)
- Telegram
- Discord
- Slack
- TOTP (Google Authenticator, Authy)

## Email (Default)

Works out of the box with Mailpit in development.

### Development Setup

Mailpit is included in Docker Compose:
```bash
cd elf
docker compose up -d
```

View emails at: http://localhost:8025

### Production Setup

Configure SMTP in `.env`:
```bash
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USERNAME=your-email@gmail.com
SMTP_PASSWORD=your-app-password
SMTP_ENCRYPTION=tls
SMTP_FROM_ADDRESS=noreply@yourdomain.com
SMTP_FROM_NAME="Admin Panel"
```

For Gmail, use an App Password (not your regular password):
1. Go to https://myaccount.google.com/apppasswords
2. Generate a new app password
3. Use that as SMTP_PASSWORD

## Telegram

### Step 1: Create a Bot

1. Open Telegram, search for `@BotFather`
2. Send `/newbot`
3. Follow instructions to create bot
4. Save the bot token (format: `123456789:ABCdefGHIjklMNOpqrsTUVwxyz`)

### Step 2: Get Your Chat ID

1. Search for `@userinfobot` in Telegram
2. Start a chat with it
3. It will show your Chat ID (a number like `123456789`)

### Step 3: Configure

Add to `.env`:
```bash
TELEGRAM_BOT_TOKEN=123456789:ABCdefGHIjklMNOpqrsTUVwxyz
TELEGRAM_CHAT_ID=123456789
```

### Step 4: Start the Bot

Send any message to your bot (e.g., `/start`). This is required before the bot can send you messages.

### Step 5: Enable for User

```sql
UPDATE admin_users
SET two_factor_method = 'telegram',
    telegram_chat_id = '123456789'
WHERE email = 'admin@example.com';
```

## Discord

### Step 1: Create Webhook

1. Open Discord, go to your server
2. Server Settings → Integrations → Webhooks
3. Create New Webhook
4. Copy webhook URL

### Step 2: Configure

Add to `.env`:
```bash
DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/123456789/abcdefghij
```

### Step 3: Enable for User

```sql
UPDATE admin_users
SET two_factor_method = 'discord'
WHERE email = 'admin@example.com';
```

## Slack

### Step 1: Create Incoming Webhook

1. Go to https://api.slack.com/apps
2. Create New App → From Scratch
3. Features → Incoming Webhooks → Activate
4. Add New Webhook to Workspace
5. Copy webhook URL

### Step 2: Configure

Add to `.env`:
```bash
SLACK_WEBHOOK_URL=your-slack-webhook-url-here
```

### Step 3: Enable for User

```sql
UPDATE admin_users
SET two_factor_method = 'slack'
WHERE email = 'admin@example.com';
```

## TOTP (Google Authenticator / Authy)

### Step 1: Generate Secret

During first login, or via admin panel:
1. Go to Profile → Security
2. Click "Enable TOTP"
3. Scan QR code with authenticator app

### Step 2: Verify Setup

Enter the 6-digit code from your app to confirm setup.

### Database Structure

```sql
UPDATE admin_users
SET two_factor_method = 'totp',
    two_factor_secret = 'encrypted-base32-secret'
WHERE email = 'admin@example.com';
```

The secret is encrypted using APP_KEY before storage.

## Code Delivery

All channels receive codes in this format:
```
Your admin panel verification code: 123456

This code expires in 5 minutes.
Do not share this code with anyone.
```

## Configuration Options

```bash
# Code settings
TWO_FACTOR_CODE_LENGTH=6        # 6 digits
TWO_FACTOR_CODE_EXPIRY=300      # 5 minutes
TWO_FACTOR_MAX_ATTEMPTS=3       # Lock after 3 wrong codes

# Rate limiting
TWO_FACTOR_RATE_LIMIT=3         # Max 3 codes per 10 minutes
TWO_FACTOR_COOLDOWN=120         # 2 minutes between resends
```

## Disabling 2FA

For testing only:
```sql
UPDATE admin_users
SET two_factor_enabled = false
WHERE email = 'admin@example.com';
```

For production, use emergency recovery instead of disabling.

## Emergency Recovery

If you lose access to your 2FA method:

1. Generate emergency token:
```bash
php elf/token-emergency-create.php --master-token=YOUR_MASTER_TOKEN
```

2. Use it on the login page or via CLI:
```bash
php elf/token-emergency-use.php --token=EMERGENCY_TOKEN
```

Emergency tokens:
- Single use
- Expire in 24 hours
- Require master token to generate
- Limited to 3 per 24 hours

## Security Considerations

1. **Store recovery codes**: Generate and store emergency tokens before you need them
2. **Backup TOTP**: Export your TOTP secret or keep backup codes
3. **Multiple channels**: Consider configuring a backup channel
4. **Rate limiting**: Codes are rate-limited to prevent brute force
5. **Expiration**: Codes expire in 5 minutes
