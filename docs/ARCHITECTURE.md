# Enterprise Framework - Architettura Modulare

## Overview

Il framework Enterprise è composto da pacchetti modulari che funzionano sia standalone che integrati:

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        YOUR APPLICATION                                  │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                         │
│   ┌─────────────────────┐    ┌─────────────────────┐                   │
│   │ enterprise-admin-panel│◄───│ enterprise-psr3-logger│                │
│   │                     │    │                     │                   │
│   │ • UI Management     │    │ • PSR-3 Logging     │                   │
│   │ • LogConfigService  │    │ • Handlers          │                   │
│   │ • Channel Config    │    │ • Formatters        │                   │
│   │ • Telegram Config   │    │ • Calls should_log()│                   │
│   └─────────┬───────────┘    └──────────┬──────────┘                   │
│             │                           │                               │
│             │         INTEGRATION       │                               │
│             ▼                           ▼                               │
│   ┌─────────────────────────────────────────────────┐                   │
│   │            enterprise-bootstrap                  │                   │
│   │                                                 │                   │
│   │  • should_log() function (intelligent filter)   │                   │
│   │  • Cache helpers (cache(), db(), session())     │                   │
│   │  • Multi-layer caching for log decisions        │                   │
│   │  • Connects to LogConfigService when available  │                   │
│   └─────────────────────────────────────────────────┘                   │
│                                                                         │
└─────────────────────────────────────────────────────────────────────────┘
```

## Flusso di should_log()

```
Logger::info('api', 'Request received')
           │
           ▼
┌──────────────────────────────────────────────────────────────┐
│                    should_log('api', 'info')                 │
│                                                              │
│  L0: Decision Cache (static)  ──► HIT? Return immediately    │
│           │                        (~0.001μs)                │
│           ▼ MISS                                             │
│  L1: LogConfigService         ──► HIT? Cache & return        │
│      (if admin-panel installed)    (~0.01μs via APCu)        │
│           │                                                  │
│           ▼ NOT INSTALLED                                    │
│  L2: Environment Config       ──► LOG_CHANNELS env var       │
│           │                        or config file            │
│           ▼ NOT SET                                          │
│  L3: Production Default       ──► is_production()? warning   │
│                                   else: debug                │
└──────────────────────────────────────────────────────────────┘
           │
           ▼
    TRUE: Log written
    FALSE: Skip (ZERO overhead)
```

## Ordine di Installazione

### 1. Enterprise Admin Panel (Obbligatorio)

```bash
composer require adoslabs/enterprise-admin-panel
```

Esegui l'installer:
```bash
php vendor/adoslabs/enterprise-admin-panel/setup/install.php \
    --driver=pgsql \
    --host=localhost \
    --database=myapp \
    --username=admin \
    --password=secret
```

Questo crea:
- Tabelle admin (`admin_users`, `admin_sessions`, `admin_config`, etc.)
- Tabella `log_channels` per configurazione logging
- Tabella `log_telegram_config` per notifiche Telegram
- Utente admin iniziale

Pubblica gli assets:
```bash
php vendor/bin/publish-assets
```

### 2. Enterprise Bootstrap (Consigliato)

```bash
composer require adoslabs/enterprise-bootstrap
```

Fornisce:
- `should_log()` intelligente con multi-layer cache
- Helpers: `cache()`, `db()`, `session()`, `config()`
- Integrazione automatica con LogConfigService

**Non richiede installazione database** - è una libreria pura.

### 3. Enterprise PSR-3 Logger (Opzionale)

```bash
composer require adoslabs/enterprise-psr3-logger
```

Esegui l'installer per creare la tabella `logs`:
```bash
php vendor/adoslabs/enterprise-psr3-logger/setup/install.php \
    --driver=pgsql \
    --database=myapp \
    --username=admin \
    --password=secret
```

## Configurazione Canali

### Via Admin Panel UI

1. Accedi al pannello admin
2. Vai su **Logger → Channels**
3. Configura ogni canale:
   - **Min Level**: debug, info, notice, warning, error, critical, alert, emergency
   - **Enabled**: On/Off
   - **Description**: Descrizione del canale

### Via Database (Direttamente)

```sql
-- Aggiungi un canale
INSERT INTO log_channels (channel, min_level, enabled, description)
VALUES ('payment', 'info', true, 'Payment processing logs');

-- Aggiorna un canale
UPDATE log_channels
SET min_level = 'warning', enabled = true
WHERE channel = 'api';
```

### Via Environment Variables (Senza Admin Panel)

```bash
# Livello globale
export LOG_LEVEL=warning

# Configurazione per canale (JSON)
export LOG_CHANNELS='{"security":"info","api":"warning","*":"error"}'
```

## Esempio Completo

```php
<?php
// index.php

// 1. Load Composer autoload (carica bootstrap con should_log())
require __DIR__ . '/vendor/autoload.php';

use AdosLabs\EnterpriseBootstrap\Core\Application;
use AdosLabs\EnterprisePSR3Logger\LoggerFactory;
use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;

// 2. Bootstrap application
$app = new Application([
    'database' => [
        'enabled' => true,
        'driver' => 'pgsql',
        'host' => 'localhost',
        'database' => 'myapp',
        'username' => 'admin',
        'password' => 'secret',
    ],
    'cache' => [
        'enabled' => true,
        'driver' => 'redis',
    ],
]);
$app->bootstrap();

// 3. Registra i logger (opzionale con LoggerFacade)
$logDir = '/var/log/app';
LoggerRegistry::register(LoggerFactory::production('default', $logDir), 'default');
LoggerRegistry::register(LoggerFactory::production('security', $logDir), 'security');
LoggerRegistry::register(LoggerFactory::production('api', $logDir), 'api');

// 4. Usa i logger
Logger::info('Application started');
Logger::security('warning', 'Failed login attempt', ['ip' => '1.2.3.4']);
Logger::api('error', 'API timeout', ['endpoint' => '/users']);

// Internamente, ogni log chiama:
// should_log('security', 'warning') → consulta LogConfigService → decide
```

## Telegram Integration

### Funzionamento

Telegram ha un **livello separato** dalla configurazione canale:

```
Esempio:
- Canale "api" configurato a level "warning"
- Telegram configurato a level "error"

Scenario 1: Logger::api('warning', 'Slow response')
→ should_log('api', 'warning') = TRUE (warning >= warning)
→ Log scritto in file/database ✓
→ shouldNotifyTelegram('api', 'warning') = FALSE (warning < error)
→ Telegram NON notificato ✗

Scenario 2: Logger::api('error', 'API timeout')
→ should_log('api', 'error') = TRUE (error >= warning)
→ Log scritto in file/database ✓
→ shouldNotifyTelegram('api', 'error') = TRUE (error >= error)
→ Telegram notificato ✓
```

### Setup

1. Crea bot Telegram con @BotFather
2. Ottieni Chat ID con @userinfobot
3. Configura in Admin Panel → Logger → Telegram

## Performance

| Operazione | Tempo | Note |
|------------|-------|------|
| should_log() cache hit | ~0.001μs | 99%+ delle chiamate |
| should_log() APCu miss | ~0.01μs | Shared memory |
| should_log() Redis miss | ~0.1μs | Network call |
| should_log() DB miss | ~1-5ms | Solo cold start |
| Log write (file) | ~0.1ms | Dipende da I/O |
| Log write (database) | ~0.5ms | Con batch |

## Modalità Standalone

Ogni pacchetto funziona anche da solo:

### Solo PSR-3 Logger (senza admin-panel e bootstrap)

```php
// Senza should_log() → logga tutto con warning
use AdosLabs\EnterprisePSR3Logger\LoggerFactory;

$logger = LoggerFactory::production('app', '/var/log/app');
$logger->info('This will be logged (no filtering)');
```

### Solo Bootstrap (senza admin-panel)

```php
// should_log() usa environment variables
export LOG_LEVEL=warning

// O definisci manualmente prima di autoload
function should_log(string $channel, string $level): bool {
    return in_array($level, ['warning', 'error', 'critical', 'alert', 'emergency']);
}

require 'vendor/autoload.php';
```

### Solo Admin Panel (senza psr3-logger)

Il pannello admin funziona senza il logger. La sezione Logger mostra:
- Configurazione canali (per quando logger sarà installato)
- Configurazione Telegram
- Nessun log da visualizzare (tabella `logs` non esiste)

## Tabelle Database

| Tabella | Pacchetto | Descrizione |
|---------|-----------|-------------|
| `admin_users` | admin-panel | Utenti admin |
| `admin_sessions` | admin-panel | Sessioni |
| `admin_config` | admin-panel | Configurazione |
| `admin_audit_log` | admin-panel | Audit trail |
| `log_channels` | admin-panel | Config canali logger |
| `log_telegram_config` | admin-panel | Config Telegram |
| `logs` | psr3-logger | Log entries |

## Best Practices

1. **Installa sempre admin-panel prima** - contiene le migrazioni per log_channels
2. **Usa bootstrap per should_log()** - fornisce caching intelligente
3. **Configura i canali via UI** - più facile che modificare env vars
4. **Telegram solo per errori** - evita spam impostando min_level=error
5. **Pulisci i log vecchi** - usa l'opzione "Clear Old Logs" nell'admin

## Troubleshooting

### should_log() non trovata
```
[ENTERPRISE PSR-3 LOGGER] WARNING: should_log() function not found.
```

**Soluzione**: Installa `adoslabs/enterprise-bootstrap`

### Canali non caricati dal database
```
should_log() returns true for everything
```

**Soluzione**: Verifica che LogConfigService abbia accesso al database:
1. Controlla che admin-panel sia installato
2. Verifica che le migrazioni siano state eseguite
3. Controlla la connessione database in bootstrap

### Telegram non invia messaggi

1. Verifica bot token format: `123456789:ABCdef...`
2. Verifica di aver avviato la chat con il bot
3. Usa il pulsante "Test Connection" nell'admin
4. Controlla il rate limit (default: 10/minuto)
