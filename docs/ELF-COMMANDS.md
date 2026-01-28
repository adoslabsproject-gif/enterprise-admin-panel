# Enterprise Lightning Framework (ELF) - Comandi CLI

## Credenziali Attuali

```
URL Admin:     http://localhost:8080/x-f85856d90c140ed849a55680ae6ccdea/login
Email:         admin@adoslabs.it
Password:      Admin2026!Secure
Master Token:  master-1a4abc50-b1f9e274-ee83a90b-2303b8ee
```

> **NOTA**: Le password generate dall'installer usano solo caratteri "shell-safe"
> per evitare problemi quando passate via CLI. Caratteri evitati: `! $ \` \\ ' " ( ) { } [ ] < > | & ; # ~`

---

## 1. Ottenere URL Admin

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/url-get.php \
  --token=master-1a4abc50-b1f9e274-ee83a90b-2303b8ee \
  --email=admin@adoslabs.it \
  --password='^YA_Y!ERm#m!ehQ3=Lf2'
```

---

## 2. Cambiare Password

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/password-change.php \
  --token=master-1a4abc50-b1f9e274-ee83a90b-2303b8ee \
  --email=admin@adoslabs.it \
  --password='^YA_Y!ERm#m!ehQ3=Lf2' \
  --new-password='NuovaPasswordSicura123!'
```

---

## 3. Accesso di Emergenza (Bypass Login + 2FA)

### 3.1 Creare Token di Emergenza

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/token-emergency-create.php \
  --token=master-1a4abc50-b1f9e274-ee83a90b-2303b8ee \
  --email=admin@adoslabs.it \
  --password='^YA_Y!ERm#m!ehQ3=Lf2'
```

### 3.2 Usare Token di Emergenza

```bash
# Via CLI
php vendor/ados-labs/enterprise-admin-panel/elf/token-emergency-use.php \
  --token=EMERGENCY_TOKEN_GENERATO

# Via Browser
http://localhost:8080/emergency-login?token=EMERGENCY_TOKEN_GENERATO
```

---

## 4. Rigenerare Master Token

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/token-master-regenerate.php \
  --token=master-1a4abc50-b1f9e274-ee83a90b-2303b8ee \
  --email=admin@adoslabs.it \
  --password='^YA_Y!ERm#m!ehQ3=Lf2'
```

**ATTENZIONE:** Il vecchio master token viene invalidato!

---

## 5. Setup OPcache (Performance)

```bash
# Verifica configurazione
php vendor/ados-labs/enterprise-admin-panel/elf/opcache-setup.php --check

# Genera file preload
php vendor/ados-labs/enterprise-admin-panel/elf/opcache-setup.php --generate

# Installa in produzione
sudo php vendor/ados-labs/enterprise-admin-panel/elf/opcache-setup.php --install --fpm-restart
```

---

## 6. Avviare il Server

```bash
cd /Users/zelistore/myproject
php -S localhost:8080 router.php

# Apri nel browser:
# http://localhost:8080/x-f85856d90c140ed849a55680ae6ccdea/login
```

---

## 7. Prima Installazione (Solo se nuovo progetto)

```bash
php vendor/ados-labs/enterprise-admin-panel/elf/install.php \
  --email=admin@example.com \
  --password=DB_PASSWORD

# Con tutte le opzioni
php vendor/ados-labs/enterprise-admin-panel/elf/install.php \
  --driver=postgresql \
  --host=localhost \
  --port=5432 \
  --database=admin_panel \
  --username=admin \
  --password=DB_PASSWORD \
  --email=admin@example.com \
  --admin-name="Administrator"
```

---

## Riepilogo Comandi Rapidi

| Azione | Comando |
|--------|---------|
| Ottenere URL | `php elf/url-get.php --token=... --email=... --password=...` |
| Cambiare password | `php elf/password-change.php --token=... --email=... --password=... --new-password=...` |
| Emergenza (crea) | `php elf/token-emergency-create.php --token=... --email=... --password=...` |
| Emergenza (usa) | `php elf/token-emergency-use.php --token=EMERGENCY_TOKEN` |
| Rigenera master | `php elf/token-master-regenerate.php --token=... --email=... --password=...` |
| Installazione | `php elf/install.php --email=... --password=...` |

---

## Note Sicurezza

- **URL Segreto**: `/admin/login` è BLOCCATO. Solo l'URL segreto funziona.
- **2FA**: Abilitato di default. Codici via email (Mailpit: http://localhost:8025)
- **Triple Auth**: Tutti i comandi CLI richiedono token + email + password.
- **Master Token**: Salvalo in un password manager! Non è recuperabile.

---

## Troubleshooting

### Password con caratteri speciali
Usa gli apici singoli per evitare problemi con la shell:
```bash
--password='^YA_Y!ERm#m!ehQ3=Lf2'
```

### Database non raggiungibile
```bash
# Verifica che PostgreSQL sia attivo
docker-compose up -d

# Verifica .env
cat .env | grep DB_
```

### Perso il Master Token?
Non è recuperabile. Devi accedere al DB e ricreare l'utente:
```sql
DELETE FROM admin_users WHERE email = 'admin@adoslabs.it';
-- Poi riesegui install.php
```
