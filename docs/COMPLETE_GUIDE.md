# Enterprise Admin Panel - Guida Completa

## Indice

1. [Installazione](#1-installazione)
2. [Configurazione Database](#2-configurazione-database)
3. [Creazione Utente Admin](#3-creazione-utente-admin)
4. [Comandi CLI](#4-comandi-cli)
5. [Autenticazione e 2FA](#5-autenticazione-e-2fa)
6. [Emergency Recovery](#6-emergency-recovery)
7. [Gestione Configurazioni](#7-gestione-configurazioni)
8. [HTTPS e Sicurezza](#8-https-e-sicurezza)
9. [Pagine di Errore Custom](#9-pagine-di-errore-custom)
10. [Architettura Modulare](#10-architettura-modulare)
11. [Comandi SQL Utili](#11-comandi-sql-utili)
12. [Troubleshooting](#12-troubleshooting)
13. [Session Guard e Heartbeat](#13-gestione-sessioni)
14. [Configurazione Email (Mailhog)](#14-configurazione-email-mailhog)

---

## 1. Installazione

### Requisiti

- PHP 8.2+
- PostgreSQL 15+ o MySQL 8+
- Composer
- Docker/OrbStack (opzionale, per sviluppo)

### Setup con Docker

```bash
# Clona il repository
cd enterprise-admin-panel

# Installa dipendenze
composer install

# Avvia i servizi
docker-compose up -d

# Servizi disponibili:
# - PostgreSQL: localhost:5432 (admin/secret)
# - Redis: localhost:6379
# - Mailhog: localhost:8025 (Web UI)
```

### Setup Manuale (senza Docker)

```bash
# Configura le variabili d'ambiente
cp .env.example .env

# Modifica .env con i tuoi dati
nano .env
```

---

## 2. Configurazione Database

### Variabili d'ambiente (.env)

```bash
# Database
DB_DRIVER=postgresql          # postgresql o mysql
DB_HOST=localhost
DB_PORT=5432                  # 5432 per PostgreSQL, 3306 per MySQL
DB_DATABASE=admin_panel
DB_USERNAME=admin
DB_PASSWORD=secret

# Sicurezza (OBBLIGATORI)
APP_KEY=                      # 64 caratteri hex - per crittografia 2FA secrets
RECOVERY_MASTER_KEY=          # 64 caratteri hex - per recovery tokens

# Ambiente
APP_ENV=development           # development o production

# HTTPS (Production)
FORCE_HTTPS=true
HSTS_ENABLED=true
HSTS_MAX_AGE=31536000

# SMTP (per 2FA via email)
SMTP_HOST=localhost
SMTP_PORT=1025
SMTP_USERNAME=
SMTP_PASSWORD=

# Notifiche (opzionali)
TELEGRAM_BOT_TOKEN=
DISCORD_WEBHOOK_URL=
SLACK_WEBHOOK_URL=
```

### Eseguire le Migrazioni

```bash
# Con Docker
php setup/init-db.php

# Oppure manualmente (PostgreSQL)
psql -h localhost -U admin -d admin_panel -f src/Database/migrations/postgresql/001_create_admin_users.sql
psql -h localhost -U admin -d admin_panel -f src/Database/migrations/postgresql/002_create_admin_url_whitelist.sql
# ... continua per tutti i file

# Con Docker exec
docker exec -i admin-panel-postgres psql -U admin -d admin_panel < src/Database/migrations/postgresql/001_create_admin_users.sql
```

### Generare Chiavi di Sicurezza

#### APP_KEY (per crittografia 2FA secrets)

```bash
php -r "echo 'APP_KEY=' . bin2hex(random_bytes(32)) . PHP_EOL;"
```

Output:
```
APP_KEY=a1b2c3d4e5f6789012345678901234567890123456789012345678901234abcd
```

#### RECOVERY_MASTER_KEY (per recovery tokens)

```bash
php setup/generate-recovery-token.php --setup-master-key
```

Output:
```
RECOVERY_MASTER_KEY=8c7fedf9242a0cfedfdcc03340b4cfdd2b9ffc9e1d97d5cec3ae59bb26cf20db

Add this to your .env file
```

---

## 3. Creazione Utente Admin

### Metodo 1: Script di Seed (Consigliato)

```bash
php setup/seed-admin.php
```

Opzioni disponibili:
```bash
php setup/seed-admin.php --admin-email=admin@example.com --admin-password=MySecurePass123 --admin-name="Super Admin"
```

### Metodo 2: SQL Diretto

```bash
# Genera hash della password
php -r "echo password_hash('LA_TUA_PASSWORD', PASSWORD_ARGON2ID) . PHP_EOL;"

# Inserisci nel database (PostgreSQL con Docker)
docker exec -i admin-panel-postgres psql -U admin -d admin_panel << 'EOF'
INSERT INTO admin_users (email, password_hash, name, role, is_master, is_active)
VALUES (
    'admin@example.com',
    '$argon2id$v=19$m=65536,t=4,p=3$...',
    'Administrator',
    'super_admin',
    true,
    true
);
EOF
```

### Metodo 3: Comando Rapido (Reset Password)

```bash
# Resetta la password a "Admin123!"
NEW_HASH=$(php -r "echo password_hash('Admin123!', PASSWORD_ARGON2ID);")
docker exec admin-panel-postgres psql -U admin -d admin_panel -c "UPDATE admin_users SET password_hash = '$NEW_HASH' WHERE email = 'admin@example.com';"
```

---

## 4. Comandi CLI

### Riferimento Rapido

| Comando | Descrizione |
|---------|-------------|
| `php setup/get-admin-url.php` | Ottieni URL + credenziali (sviluppo) |
| `php setup/seed-admin.php` | Crea utente admin |
| `php bin/admin-url --generate-token --user-id=1` | Genera token CLI |
| `php bin/admin-url --token=TOKEN` | Ottieni URL con autenticazione |
| `php setup/generate-recovery-token.php --setup-master-key` | Setup chiave recovery |
| `php setup/generate-recovery-token.php --email=EMAIL` | Genera token recovery |
| `php setup/migrate-2fa-secrets.php` | Cripta secret 2FA legacy |

### 4.1 Ottenere URL Admin (Sviluppo)

```bash
php setup/get-admin-url.php
```

**Output:**
```
╔══════════════════════════════════════════════════════════════════╗
║              ENTERPRISE ADMIN PANEL - URL                        ║
╚══════════════════════════════════════════════════════════════════╝

Admin Panel URL:
  http://localhost:8080/x-b64062a0356d8d81be244e1a1aa1be02/login

Credentials:
  Email:    admin@example.com
  Password: Admin123!

Start server:
  php -S localhost:8080 -t public
```

### 4.2 Generare Token CLI (Produzione)

```bash
php bin/admin-url --generate-token --user-id=1
```

**Output:**
```
CLI Token generated successfully!
Token: a7f3b2c8d9e4f1a6b2c8d9e4f1a6b2c8d9e4f1a6b2c8d9e4f1a6b2c8d9e4f1a6

IMPORTANT: Save this token securely. It will NOT be shown again.
```

### 4.3 Usare Token per Ottenere URL

```bash
php bin/admin-url --token=IL_TUO_TOKEN_64_CARATTERI
```

### 4.4 Recovery Token

```bash
# Genera token di recovery (master admin only)
php setup/generate-recovery-token.php --email=admin@example.com

# Dry run
php setup/generate-recovery-token.php --email=admin@example.com --dry-run
```

### 4.5 Migrare Secret 2FA

```bash
# Dry run prima
php setup/migrate-2fa-secrets.php --dry-run

# Esegui migrazione
php setup/migrate-2fa-secrets.php
```

---

## 5. Autenticazione e 2FA

### Metodi 2FA Supportati

| Metodo | Descrizione | Come Abilitare |
|--------|-------------|----------------|
| **TOTP** | Google Authenticator, Authy | Scansiona QR nel profilo |
| **Email** | Codice via email | Configura SMTP |
| **Telegram** | Codice via bot | `TELEGRAM_BOT_TOKEN` in .env |
| **Discord** | Codice via webhook | `DISCORD_WEBHOOK_URL` in .env |
| **Slack** | Codice via webhook | `SLACK_WEBHOOK_URL` in .env |

### Abilitare 2FA per un Utente

#### Via UI (Consigliato)
1. Login al pannello admin
2. Vai su **Profilo > Sicurezza**
3. Seleziona metodo 2FA
4. Segui le istruzioni

#### Via SQL (TOTP)

```sql
-- Abilita 2FA TOTP con secret di test
UPDATE admin_users
SET two_factor_enabled = true,
    two_factor_method = 'totp',
    two_factor_secret = 'JBSWY3DPEHPK3PXP'
WHERE email = 'admin@example.com';
```

#### Via SQL (Email)

```sql
UPDATE admin_users
SET two_factor_enabled = true,
    two_factor_method = 'email'
WHERE email = 'admin@example.com';
```

### Disabilitare 2FA

```sql
UPDATE admin_users
SET two_factor_enabled = false
WHERE email = 'admin@example.com';
```

### Generare Codice TOTP (per test)

```bash
# Installa oathtool (macOS)
brew install oath-toolkit

# Genera codice con il secret di test
oathtool --totp -b JBSWY3DPEHPK3PXP
```

### Verificare Stato 2FA

```sql
SELECT email, two_factor_enabled, two_factor_method,
       two_factor_secret IS NOT NULL as has_secret
FROM admin_users
WHERE email = 'admin@example.com';
```

---

## 6. Emergency Recovery

### Cos'è

Permette ai Master Admin di bypassare il 2FA usando un token one-time generato via CLI.

### Requisiti

1. L'utente deve avere `is_master = true`
2. `RECOVERY_MASTER_KEY` deve essere configurato in `.env`
3. Emergency Recovery deve essere abilitato (default: sì)

### Abilitare/Disabilitare Emergency Recovery

#### Abilitare

```sql
-- PostgreSQL con Docker
docker exec admin-panel-postgres psql -U admin -d admin_panel -c \
  "INSERT INTO admin_config (config_key, config_value, value_type, description)
   VALUES ('emergency_recovery_enabled', 'true', 'boolean', 'Enable emergency recovery')
   ON CONFLICT (config_key) DO UPDATE SET config_value = 'true';"
```

#### Disabilitare

```sql
docker exec admin-panel-postgres psql -U admin -d admin_panel -c \
  "UPDATE admin_config SET config_value = 'false' WHERE config_key = 'emergency_recovery_enabled';"
```

#### Verificare Stato

```sql
SELECT config_key, config_value FROM admin_config WHERE config_key = 'emergency_recovery_enabled';
```

### Generare Token di Recovery

```bash
# Genera token (richiede RECOVERY_MASTER_KEY in .env)
php setup/generate-recovery-token.php --email=admin@example.com
```

**Output:**
```
Recovery Token Generated
========================

Token: REC-a7f3b2c8-d9e4f1a6-b2c8d9e4-f1a6b2c8

Send this token to the user via a secure channel.
Token expires in 24 hours.
Rate limit: 3 tokens per 24 hours.
```

### Usare il Token

1. Vai alla pagina di login
2. Clicca **"Emergency Recovery (Bypass 2FA)"**
3. Inserisci email del Master Admin
4. Inserisci il token ricevuto
5. Accesso concesso

### Rendere un Utente Master Admin

```sql
UPDATE admin_users SET is_master = true WHERE email = 'admin@example.com';
```

### Rimuovere Privilegi Master

```sql
UPDATE admin_users SET is_master = false WHERE email = 'admin@example.com';
```

---

## 7. Gestione Configurazioni

### Tabella admin_config

| config_key | Tipo | Default | Descrizione |
|------------|------|---------|-------------|
| `emergency_recovery_enabled` | boolean | true | Abilita recovery di emergenza |
| `session_idle_timeout` | integer | 30 | Timeout sessione inattiva (minuti) |
| `max_login_attempts` | integer | 5 | Tentativi login prima del blocco |
| `lockout_duration` | integer | 15 | Durata blocco account (minuti) |

### Leggere Configurazione

```sql
SELECT config_key, config_value, value_type, description
FROM admin_config;
```

### Modificare Configurazione

```sql
-- Modifica valore esistente
UPDATE admin_config SET config_value = 'false' WHERE config_key = 'emergency_recovery_enabled';

-- Aggiungi nuova configurazione
INSERT INTO admin_config (config_key, config_value, value_type, description, is_editable)
VALUES ('my_setting', 'my_value', 'string', 'Description', true);
```

### Script Helper

```bash
# Abilita Emergency Recovery
docker exec admin-panel-postgres psql -U admin -d admin_panel -c \
  "UPDATE admin_config SET config_value = 'true' WHERE config_key = 'emergency_recovery_enabled';"

# Disabilita Emergency Recovery
docker exec admin-panel-postgres psql -U admin -d admin_panel -c \
  "UPDATE admin_config SET config_value = 'false' WHERE config_key = 'emergency_recovery_enabled';"
```

---

## 8. HTTPS e Sicurezza

### Sviluppo (localhost)

HTTPS enforcement è **disabilitato** per localhost. Puoi sviluppare con HTTP normale.

### Produzione

Il `HttpsMiddleware` automaticamente:
1. Redirect HTTP → HTTPS (301)
2. Aggiunge header HSTS
3. Aggiunge security headers

### Configurazione HTTPS (.env)

```bash
APP_ENV=production
FORCE_HTTPS=true
HSTS_ENABLED=true
HSTS_MAX_AGE=31536000       # 1 anno
HSTS_INCLUDE_SUBDOMAINS=true
HSTS_PRELOAD=false          # true solo dopo test
```

### Security Headers Automatici

```
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: accelerometer=(), camera=(), geolocation=()...
```

### Setup Nginx (Reverse Proxy)

```nginx
server {
    listen 443 ssl http2;
    server_name admin.example.com;

    ssl_certificate /etc/letsencrypt/live/admin.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/admin.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}

server {
    listen 80;
    server_name admin.example.com;
    return 301 https://$server_name$request_uri;
}
```

### Setup Caddy (HTTPS Automatico)

```caddyfile
admin.example.com {
    reverse_proxy localhost:8080
}
```

---

## 9. Pagine di Errore Custom

### 404 - "Lost in the Matrix"

- Effetto pioggia digitale stile Matrix
- Messaggio "Page Derezzed"
- Glitch animation sul codice errore
- Link per tornare alla home

### 403 - "Vault Door"

- Visualizzazione porta del vault
- Manopola che trema (accesso negato)
- Indicatore di lock rosso pulsante
- Motivo dell'errore mostrato

### 500 - "System Meltdown"

- Reattore nucleare in meltdown
- Strisce di warning animate
- Effetto fumo che sale
- Error ID per debugging
- Stack trace in development mode (APP_ENV=development)

### Uso Programmatico

```php
use AdosLabs\AdminPanel\Http\ErrorPages;

// Render e exit
ErrorPages::render404($homeUrl, $requestedPath);
ErrorPages::render403($homeUrl, $reason);
ErrorPages::render500($homeUrl, $errorId, $showDetails, $message, $trace);

// Come Response object
$response = ErrorPages::get404Response($homeUrl, $requestedPath);
$response = ErrorPages::get403Response($homeUrl, $reason);
$response = ErrorPages::get500Response($homeUrl, $errorId, $showDetails);
```

---

## 10. Architettura Modulare

### Auto-Discovery

I moduli vengono scoperti automaticamente dai pacchetti composer:

```json
{
    "name": "adoslabs/my-module",
    "extra": {
        "admin-panel": {
            "module": "AdosLabs\\MyModule\\AdminModule",
            "priority": 50
        }
    }
}
```

### Creare un Modulo

```php
use AdosLabs\AdminPanel\Modules\BaseModule;

class MyModule extends BaseModule
{
    public function getName(): string
    {
        return 'My Module';
    }

    public function getTabs(): array
    {
        return [
            [
                'label' => 'My Tab',
                'url' => '/admin/my-module',
                'icon' => 'star',
                'priority' => 50,
            ],
        ];
    }

    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/admin/my-module',
                'handler' => [MyController::class, 'index'],
            ],
        ];
    }
}
```

---

## 11. Comandi SQL Utili

### Gestione Utenti

```sql
-- Visualizza tutti gli utenti
SELECT id, email, name, role, is_master, is_active, two_factor_enabled
FROM admin_users;

-- Crea nuovo utente (genera hash prima con PHP)
INSERT INTO admin_users (email, password_hash, name, role, is_master, is_active)
VALUES ('new@example.com', '$argon2id$...', 'New User', 'admin', false, true);

-- Resetta tentativi login falliti
UPDATE admin_users
SET failed_login_attempts = 0, locked_until = NULL
WHERE email = 'admin@example.com';

-- Disattiva utente
UPDATE admin_users SET is_active = false WHERE email = 'user@example.com';

-- Riattiva utente
UPDATE admin_users SET is_active = true WHERE email = 'user@example.com';

-- Rendi master admin
UPDATE admin_users SET is_master = true WHERE email = 'admin@example.com';

-- Rimuovi master admin
UPDATE admin_users SET is_master = false WHERE email = 'admin@example.com';
```

### Gestione 2FA

```sql
-- Abilita 2FA TOTP
UPDATE admin_users
SET two_factor_enabled = true, two_factor_method = 'totp', two_factor_secret = 'JBSWY3DPEHPK3PXP'
WHERE email = 'admin@example.com';

-- Abilita 2FA Email
UPDATE admin_users
SET two_factor_enabled = true, two_factor_method = 'email'
WHERE email = 'admin@example.com';

-- Disabilita 2FA
UPDATE admin_users SET two_factor_enabled = false WHERE email = 'admin@example.com';

-- Verifica stato 2FA
SELECT email, two_factor_enabled, two_factor_method FROM admin_users;
```

### Gestione Configurazioni

```sql
-- Visualizza configurazioni
SELECT config_key, config_value, value_type FROM admin_config;

-- Abilita emergency recovery
UPDATE admin_config SET config_value = 'true' WHERE config_key = 'emergency_recovery_enabled';

-- Disabilita emergency recovery
UPDATE admin_config SET config_value = 'false' WHERE config_key = 'emergency_recovery_enabled';

-- Aggiungi configurazione
INSERT INTO admin_config (config_key, config_value, value_type, description)
VALUES ('my_config', 'value', 'string', 'My configuration');
```

### Gestione Sessioni

```sql
-- Visualizza sessioni attive
SELECT s.id, u.email, s.ip_address, s.last_activity, s.expires_at
FROM admin_sessions s
JOIN admin_users u ON s.user_id = u.id
WHERE s.expires_at > NOW();

-- Termina tutte le sessioni di un utente
DELETE FROM admin_sessions WHERE user_id = (SELECT id FROM admin_users WHERE email = 'admin@example.com');

-- Pulisci sessioni scadute
DELETE FROM admin_sessions WHERE expires_at < NOW();
```

### Audit Log

```sql
-- Ultimi 10 eventi
SELECT al.action, u.email, al.ip_address, al.created_at
FROM admin_audit_log al
LEFT JOIN admin_users u ON al.user_id = u.id
ORDER BY al.created_at DESC
LIMIT 10;

-- Login falliti
SELECT u.email, al.ip_address, al.created_at
FROM admin_audit_log al
LEFT JOIN admin_users u ON al.user_id = u.id
WHERE al.action = 'login_failed'
ORDER BY al.created_at DESC;
```

---

## 12. Troubleshooting

### "404 Not Found" su /admin/login

**Normale!** L'URL `/admin/` è bloccato per sicurezza. Usa:
```bash
php setup/get-admin-url.php
```

### Credenziali non funzionano

1. Verifica password:
```bash
# Resetta a Admin123!
NEW_HASH=$(php -r "echo password_hash('Admin123!', PASSWORD_ARGON2ID);")
docker exec admin-panel-postgres psql -U admin -d admin_panel -c "UPDATE admin_users SET password_hash = '$NEW_HASH' WHERE email = 'admin@example.com';"
```

2. Verifica account non bloccato:
```sql
SELECT failed_login_attempts, locked_until FROM admin_users WHERE email = 'admin@example.com';
```

3. Resetta blocco:
```sql
UPDATE admin_users SET failed_login_attempts = 0, locked_until = NULL WHERE email = 'admin@example.com';
```

### Pagina 2FA non appare

1. Verifica 2FA abilitato:
```sql
SELECT two_factor_enabled, two_factor_method FROM admin_users WHERE email = 'admin@example.com';
```

2. Abilita per test:
```sql
UPDATE admin_users SET two_factor_enabled = true, two_factor_method = 'totp', two_factor_secret = 'JBSWY3DPEHPK3PXP' WHERE email = 'admin@example.com';
```

### Emergency Recovery non visibile

1. Verifica configurazione:
```sql
SELECT config_value FROM admin_config WHERE config_key = 'emergency_recovery_enabled';
```

2. Abilita:
```sql
INSERT INTO admin_config (config_key, config_value, value_type) VALUES ('emergency_recovery_enabled', 'true', 'boolean') ON CONFLICT (config_key) DO UPDATE SET config_value = 'true';
```

### Recovery token non funziona

1. Verifica is_master:
```sql
SELECT is_master FROM admin_users WHERE email = 'admin@example.com';
-- Se false, abilita:
UPDATE admin_users SET is_master = true WHERE email = 'admin@example.com';
```

2. Verifica RECOVERY_MASTER_KEY in .env

3. Controlla rate limit:
```sql
SELECT COUNT(*) FROM admin_recovery_tokens WHERE user_id = 1 AND created_at > NOW() - INTERVAL '24 hours';
```

### Email 2FA non arriva

1. Controlla Mailhog: http://localhost:8025
2. Verifica SMTP in .env:
```bash
SMTP_HOST=localhost
SMTP_PORT=1025
```

### APP_KEY not found

```bash
php -r "echo 'APP_KEY=' . bin2hex(random_bytes(32)) . PHP_EOL;" >> .env
```

### Sessione scade troppo velocemente

La sessione ha un **hard cap di 60 minuti**. Tuttavia, se c'è attività negli ultimi 5 minuti prima della scadenza, la sessione viene automaticamente estesa di altri 60 minuti.

### Email 2FA non arriva

1. Verifica che il metodo 2FA sia `email`:
```sql
SELECT two_factor_method FROM admin_users WHERE email = 'admin@example.com';
-- Se è 'totp', cambialo:
UPDATE admin_users SET two_factor_method = 'email' WHERE email = 'admin@example.com';
```

2. Verifica che Mailhog sia in esecuzione:
```bash
nc -zv localhost 1025  # Dovrebbe connettersi
```

3. Verifica Mailhog Web UI: http://localhost:8025

4. Verifica config notifications:
```sql
SELECT * FROM admin_config WHERE config_key LIKE 'notification%';
```

---

## 13. Gestione Sessioni

### Session Guard con Heartbeat

Il sistema implementa un meccanismo di sicurezza avanzato per le sessioni:

- **Durata massima**: 60 minuti dalla creazione
- **Heartbeat**: Ogni 30 secondi il frontend invia un ping al server
- **Estensione automatica**: Se c'è attività negli ultimi 5 minuti prima della scadenza, la sessione viene estesa
- **Warning**: 5 minuti prima della scadenza appare un dialog di warning
- **Auto-logout**: Se la sessione scade, redirect automatico al login

### Funzionamento Session Guard

Il `SessionGuard` (file `/public/js/session-guard.js`) gestisce:

1. **Inizializzazione**: Al caricamento della pagina, estrae il base path dall'URL
2. **Heartbeat Loop**: Ogni 30 secondi invia una richiesta GET all'endpoint heartbeat
3. **Activity Tracking**: Monitora mouse, keyboard, scroll e touch per tracciare l'attività utente
4. **Expiry Check**: Ogni 10 secondi verifica se la sessione sta per scadere
5. **Warning Dialog**: Mostra un dialog con countdown quando mancano 5 minuti

### Visualizzare il Heartbeat nella Console

Per debuggare il SessionGuard, apri la console del browser (F12 > Console). Vedrai:

```
[SessionGuard] Initialized with basePath: /x-94924ff90843ff0d0597898367d5c2d6
[SessionGuard] Sending heartbeat to: /x-94924ff90843ff0d0597898367d5c2d6/api/session/heartbeat
[SessionGuard] Heartbeat response: 200
[SessionGuard] Session status: {active: true, expires_in: 3540, should_warn: false, extension_count: 0}
```

Se vedi errori:
- **403**: Token CSRF non valido - ricarica la pagina
- **Session expired**: La sessione è scaduta - login richiesto

### URL Rotation al Logout

**IMPORTANTE**: Quando fai logout, l'URL admin viene rigenerato automaticamente. Devi eseguire nuovamente:
```bash
php setup/get-admin-url.php
```

Questo è un meccanismo di sicurezza per prevenire:
- Session hijacking
- Bookmark di URL vecchi
- Accesso non autorizzato

### Perché l'URL Cambia Dopo il Logout?

1. **Sicurezza**: Nessun URL statico = nessun target per attacchi
2. **Sessione invalidata**: Anche se qualcuno ha il vecchio URL, non funziona più
3. **Audit trail**: Ogni cambio URL viene loggato

### Pagina di Arrivederci

Dopo il logout, viene mostrata una pagina elegante che:
- Conferma il logout avvenuto con successo
- Informa che l'URL è stato rigenerato
- Mostra il comando CLI da eseguire per ottenere il nuovo URL
- Previene la navigazione indietro con il tasto del browser

### Cache Control e Sicurezza

Tutte le pagine admin includono header HTTP che prevengono il caching:

```http
Cache-Control: no-cache, no-store, must-revalidate, max-age=0
Pragma: no-cache
Expires: Thu, 01 Jan 1970 00:00:00 GMT
```

Questo garantisce che:
- Il tasto "indietro" del browser non mostri pagine cached
- Nessuna pagina sensibile viene salvata nella cache
- Le sessioni scadute non possono essere "riviste"

### API Heartbeat

Il frontend chiama automaticamente:
```
GET /{admin-base}/api/session/heartbeat
```

Risposta:
```json
{
  "active": true,
  "expires_in": 3540,
  "should_warn": false,
  "extension_count": 0
}
```

Parametri risposta:
- `active`: `true` se la sessione è valida
- `expires_in`: Secondi rimanenti prima della scadenza
- `should_warn`: `true` se mancano meno di 5 minuti
- `extension_count`: Numero di volte che la sessione è stata estesa

---

## 14. Configurazione Email (Mailhog)

### Avvio Mailhog con Docker

```bash
# Nel docker-compose.yml è già configurato
docker-compose up -d mailhog

# Oppure standalone
docker run -d -p 1025:1025 -p 8025:8025 mailhog/mailhog
```

### Configurazione .env

```bash
# SMTP per Mailhog (sviluppo)
SMTP_HOST=localhost
SMTP_PORT=1025
SMTP_USERNAME=
SMTP_PASSWORD=
SMTP_ENCRYPTION=
SMTP_FROM_EMAIL=admin@localhost
SMTP_FROM_NAME=Enterprise Admin
```

### Verifica Email

1. **Web UI Mailhog**: http://localhost:8025
2. **Test invio manuale**:
```bash
php -r "
require 'vendor/autoload.php';
use AdosLabs\\AdminPanel\\Services\\ConfigService;
use AdosLabs\\AdminPanel\\Services\\NotificationService;

\$pdo = new PDO('pgsql:host=localhost;port=5432;dbname=admin_panel', 'admin', 'secret');
\$configService = new ConfigService(\$pdo);
\$notificationService = new NotificationService(\$pdo, \$configService);
\$result = \$notificationService->send2FACode(1, '123456', 'email');
print_r(\$result);
"
```

### Cambio Metodo 2FA

```sql
-- Da TOTP a Email
UPDATE admin_users SET two_factor_method = 'email' WHERE email = 'admin@example.com';

-- Da Email a TOTP
UPDATE admin_users SET two_factor_method = 'totp' WHERE email = 'admin@example.com';

-- Verifica
SELECT two_factor_method FROM admin_users WHERE email = 'admin@example.com';
```

---

## Riferimento Rapido

### Comandi Essenziali

```bash
# Avvia server
php -S localhost:8080 -t public

# Ottieni URL
php setup/get-admin-url.php

# Crea utente
php setup/seed-admin.php

# Genera chiavi
php -r "echo bin2hex(random_bytes(32));"                    # APP_KEY
php setup/generate-recovery-token.php --setup-master-key    # RECOVERY_KEY

# Recovery token
php setup/generate-recovery-token.php --email=admin@example.com
```

### Docker Exec Helper

```bash
# Esegui SQL
docker exec admin-panel-postgres psql -U admin -d admin_panel -c "SQL_QUERY"

# Bash interattivo
docker exec -it admin-panel-postgres psql -U admin -d admin_panel
```

### File Importanti

| File | Scopo |
|------|-------|
| `.env` | Configurazione ambiente |
| `public/index.php` | Entry point |
| `setup/seed-admin.php` | Crea utente admin |
| `setup/get-admin-url.php` | Ottieni URL pannello |
| `setup/generate-recovery-token.php` | Genera token recovery |
| `docs/COMPLETE_GUIDE.md` | Questa guida |

---

## Supporto

Per problemi o domande: support@adoslabs.com
