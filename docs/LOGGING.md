# Enterprise Admin Panel - Sistema di Logging

## Panoramica

Il sistema di logging di Enterprise Admin Panel è progettato per essere **ultra-performante** e **non-bloccante**. I log vengono accumulati in memoria durante la request e scritti in modo asincrono al termine, garantendo zero impatto sulle performance dell'applicazione.

## Architettura

```
┌─────────────────────────────────────────────────────────────────────┐
│                         DURANTE LA REQUEST                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│   log_warning('auth', 'Login failed', ['ip' => '...'])              │
│                          │                                          │
│                          ▼                                          │
│                  ┌───────────────┐                                  │
│                  │   should_log()│  ← Multi-layer cache             │
│                  │   ~3μs/call   │    (static → APCu → Redis → DB)  │
│                  └───────┬───────┘                                  │
│                          │ (se abilitato)                           │
│                          ▼                                          │
│                  ┌───────────────┐                                  │
│                  │   LogBuffer   │  ← Solo memoria, ~26μs/call      │
│                  │  (in-memory)  │                                  │
│                  └───────────────┘                                  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────────┐
│                      FINE REQUEST (shutdown)                        │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│                  ┌───────────────┐                                  │
│                  │   LogBuffer   │                                  │
│                  └───────┬───────┘                                  │
│                          │                                          │
│                          ▼                                          │
│                  ┌───────────────┐                                  │
│                  │  LogFlusher   │                                  │
│                  └───────┬───────┘                                  │
│                          │                                          │
│              ┌───────────┴───────────┐                              │
│              │                       │                              │
│              ▼                       ▼                              │
│     ┌─────────────────┐     ┌─────────────────┐                     │
│     │  Redis Queue    │     │  File Fallback  │                     │
│     │ (se disponibile)│     │ (sempre attivo) │                     │
│     └────────┬────────┘     └─────────────────┘                     │
│              │                                                      │
│              ▼                                                      │
│     ┌─────────────────┐                                             │
│     │   LogWorker     │  ← Processo separato (async)                │
│     │  (batch INSERT) │                                             │
│     └────────┬────────┘                                             │
│              │                                                      │
│              ▼                                                      │
│     ┌─────────────────┐                                             │
│     │    Database     │                                             │
│     └─────────────────┘                                             │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Performance

| Operazione | Tempo | Note |
|------------|-------|------|
| Bootstrap (warm) | ~0.05ms | Ben sotto il target di 10ms |
| `should_log()` (cached) | ~3μs | Cache statica in memoria |
| `log_warning()` | ~26μs | Solo scrittura in memoria |
| Memoria per 100 log | ~23KB | Buffer auto-flush a 1000 entries |

## Sintassi e Utilizzo

### Helper Functions

```php
// Sintassi base
log_message(string $channel, string $level, string $message, array $context = []);

// Helper per livello specifico
log_debug(string $channel, string $message, array $context = []);
log_info(string $channel, string $message, array $context = []);
log_notice(string $channel, string $message, array $context = []);
log_warning(string $channel, string $message, array $context = []);
log_error(string $channel, string $message, array $context = []);
log_critical(string $channel, string $message, array $context = []);
log_alert(string $channel, string $message, array $context = []);
log_emergency(string $channel, string $message, array $context = []);
```

### Esempi Pratici

```php
// Autenticazione
log_warning('auth', 'Login attempt with unknown email', [
    'email' => $email,
    'ip' => $ipAddress,
]);

// Sicurezza
log_error('security', 'Account locked due to excessive failed attempts', [
    'user_id' => $userId,
    'ip' => $ipAddress,
    'attempts' => $attempts,
    'lockout_minutes' => $lockoutMinutes,
]);

// Database
log_critical('database', 'Connection pool exhausted', [
    'pool_size' => $poolSize,
    'active_connections' => $active,
    'waiting_requests' => $waiting,
]);

// Accessi HTTP
log_info('access', 'HTTP Request', [
    'method' => 'POST',
    'uri' => '/admin/users/create',
    'status' => 201,
    'duration_ms' => 45.3,
    'ip' => '192.168.1.100',
]);

// Performance
log_warning('performance', 'Slow query detected', [
    'query' => 'SELECT * FROM users...',
    'duration_ms' => 523,
    'rows_affected' => 15000,
]);
```

### Canali Predefiniti

| Canale | Descrizione | Livello Consigliato |
|--------|-------------|---------------------|
| `auth` | Autenticazione e login | warning+ |
| `security` | Eventi di sicurezza | warning+ |
| `access` | Log accessi HTTP | info+ |
| `database` | Query e connessioni DB | warning+ |
| `cache` | Operazioni cache | warning+ |
| `session` | Gestione sessioni | info+ |
| `performance` | Metriche performance | warning+ |
| `audit` | Audit trail azioni utente | info+ |
| `api` | Chiamate API esterne | warning+ |
| `queue` | Job queue e worker | warning+ |

### Livelli PSR-3

```
emergency → Il sistema è inutilizzabile
alert     → Azione immediata richiesta
critical  → Condizioni critiche
error     → Errori runtime che non richiedono azione immediata
warning   → Situazioni anomale ma non errori
notice    → Eventi normali ma significativi
info      → Messaggi informativi
debug     → Informazioni di debug dettagliate
```

## Configurazione

### Variabili d'Ambiente

```env
# Logging
LOG_CHANNEL=stack
LOG_LEVEL=warning
LOG_DAYS=14

# Redis (per queue asincrona)
REDIS_HOST=localhost
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0

# SMTP Email (per invio 2FA e notifiche)
# NESSUN DATO SENSIBILE HARDCODED - Tutto configurabile via .env
SMTP_HOST=localhost
SMTP_PORT=1025
SMTP_ENCRYPTION=           # tls, ssl, or empty for no encryption
SMTP_USERNAME=             # Leave empty for Mailhog/dev
SMTP_PASSWORD=             # Leave empty for Mailhog/dev
SMTP_FROM_EMAIL=admin@localhost
SMTP_FROM_NAME="Enterprise Admin"
```

> **NOTA SICUREZZA**: Tutti i parametri SMTP sono configurabili esclusivamente via file `.env`.
> Non ci sono credenziali hardcoded nel codice. Per produzione, configura un SMTP
> reale (es. Amazon SES, SendGrid, Mailgun) nel file `.env`.

### Configurazione Canali (Database)

I canali sono configurabili via database nella tabella `log_channels`:

```sql
CREATE TABLE log_channels (
    id SERIAL PRIMARY KEY,
    channel VARCHAR(100) NOT NULL UNIQUE,
    min_level VARCHAR(20) NOT NULL DEFAULT 'warning',
    enabled BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Esempi
INSERT INTO log_channels (channel, min_level, enabled) VALUES
('auth', 'warning', true),
('security', 'warning', true),
('access', 'info', true),
('database', 'warning', true),
('performance', 'warning', true);
```

### should_log() - Decisione Multi-Layer

La funzione `should_log()` determina se un log deve essere scritto usando una cache multi-livello:

```php
function should_log(string $channel, string $level): bool
{
    // Layer 1: Static cache (stessa request) - ~0.001μs
    static $decisions = [];
    $key = "{$channel}:{$level}";
    if (isset($decisions[$key])) {
        return $decisions[$key];
    }

    // Layer 2: APCu cache (cross-request, stesso server) - ~0.01μs
    // Layer 3: Redis cache (cross-server) - ~0.1ms
    // Layer 4: Database (source of truth) - ~1ms

    // SAFETY: Se non inizializzato o errore, permetti TUTTI i log
    // Meglio avere troppi log che perdere messaggi critici
}
```

## Componenti

### LogBuffer

Buffer in memoria che accumula i log durante la request.

```php
use AdosLabs\AdminPanel\Logging\LogBuffer;

$buffer = LogBuffer::getInstance();

// Stato del buffer
$size = $buffer->getBufferSize();        // Numero entries
$memory = $buffer->getEstimatedMemory(); // Bytes usati
$entries = $buffer->getBuffer();         // Array entries

// Limiti automatici
// - Max 1000 entries (poi auto-flush)
// - Max 1MB memoria (poi auto-flush)
```

### LogFlusher

Scrive i log bufferizzati su Redis o file.

```php
use AdosLabs\AdminPanel\Logging\LogFlusher;

$flusher = new LogFlusher($redis, '/path/to/logs');

// Flush manuale (normalmente automatico a shutdown)
$success = $flusher->flush($buffer->getBuffer());

// Verifica se usa Redis
$usingRedis = $flusher->isUsingRedis();
```

### LogWorker

Processo background che legge dalla queue Redis e scrive sul database.

```bash
# Avvia il worker
php artisan log:worker

# O direttamente
php -r "
require 'vendor/autoload.php';
\AdosLabs\AdminPanel\Logging\LogWorker::create(\$pdo, \$redis)->run();
"
```

```php
use AdosLabs\AdminPanel\Logging\LogWorker;

$worker = LogWorker::create($pdo, $redis);

// Configurazione
$worker->setBatchSize(100);      // Entries per batch INSERT
$worker->setMaxRuntime(3600);    // Max 1 ora di esecuzione
$worker->setSleepInterval(1000); // 1 secondo tra i poll

// Avvia (loop infinito)
$worker->run();

// O processa un singolo batch
$processed = $worker->processBatch();
```

## File di Log

### Formato

I log su file seguono questo formato:

```
[YYYY-MM-DD HH:MM:SS.microseconds] channel.LEVEL: message {"context":"json"}
```

Esempio:
```
[2026-01-27 17:26:48.670526] auth.WARNING: Login attempt with unknown email {"email":"test@example.com","ip":"192.168.1.100"}
[2026-01-27 17:26:48.671247] security.ERROR: Account locked due to failed attempts {"user_id":123,"ip":"192.168.1.100","attempts":5,"lockout_minutes":15}
```

### Posizione File

```
storage/logs/
├── app-2026-01-27.log      # Log applicazione (default)
├── access-2026-01-27.log   # Log accessi HTTP
├── security-2026-01-27.log # Log sicurezza (separati)
└── php_errors.log          # Errori PHP
```

## Strategic Logging Implementato

### AuthService

| Evento | Livello | Canale |
|--------|---------|--------|
| **Login riuscito** | INFO | security |
| **Logout** | INFO | security |
| **Sessione creata (diretto)** | INFO | security |
| Login email sconosciuta | WARNING | auth |
| Account bloccato | WARNING | auth |
| Account disabilitato | WARNING | auth |
| Password invalida | WARNING | auth |
| Account lock (brute force) | ERROR | security |
| 2FA fallito | WARNING | security |
| Recovery code usato | WARNING | security |
| Password change fallito | WARNING | security |
| Reset token invalido | WARNING | security |
| Recovery login (bypass 2FA) | WARNING | security |

### TwoFactorService

| Evento | Livello | Canale |
|--------|---------|--------|
| **2FA code inviato** | INFO | email |
| 2FA code invio fallito | WARNING | email |

### NotificationService

| Evento | Livello | Canale |
|--------|---------|--------|
| **Email inviata** | INFO | email |
| Email invio fallito | ERROR | email |

### SessionService

| Evento | Livello | Canale |
|--------|---------|--------|
| Sessione scaduta | INFO | session |
| Mass session invalidation | WARNING | security |

### CsrfMiddleware

| Evento | Livello | Canale |
|--------|---------|--------|
| CSRF validation failed | WARNING | security |

### AuthMiddleware

| Evento | Livello | Canale |
|--------|---------|--------|
| Permission denied | WARNING | security |
| Role-based access denied | WARNING | security |

### AccessLogMiddleware (PHP-FPM Style)

| Evento | Livello | Canale |
|--------|---------|--------|
| HTTP Request (2xx, 3xx) | INFO | access |
| Client Error (4xx, no 404) | WARNING | access |
| Server Error (5xx) | ERROR | access |
| Slow request (>1s) | WARNING | performance |

#### Formato Log (simile NGINX combined)

```
192.168.1.100 user:123 "POST /admin/users HTTP/1.1" 201 1234 "https://example.com" "Mozilla/5.0..."
```

#### Integrazione

```php
// Nel middleware stack (PRIMO middleware per misurare tutta la request)
$app->pipe(new AccessLogMiddleware(
    excludedPaths: ['/health', '/metrics'],           // Escludi completamente
    minimalLogPaths: ['/admin/api/heartbeat'],        // Solo errori
    logRequestBody: false,                             // Non loggare body POST
    maxBodyLogSize: 1024                               // Max 1KB se abilitato
));
```

#### Output Esempio

```
[2026-01-27 18:30:15.123456] access.INFO: 192.168.1.100 user:42 "GET /admin/dashboard HTTP/1.1" 200 15234 "-" "Mozilla/5.0..." {"method":"GET","uri":"/admin/dashboard","status":200,"duration_ms":45.32,"user_id":42,"ip":"192.168.1.100"}

[2026-01-27 18:30:16.789012] access.ERROR: 192.168.1.100 - "POST /admin/api/users HTTP/1.1" 500 512 "-" "curl/7.68" {"method":"POST","uri":"/admin/api/users","status":500,"duration_ms":1523.45,"ip":"192.168.1.100","error":"Database connection failed"}
```

## Best Practices

### 1. Usa il Canale Corretto

```php
// CORRETTO
log_warning('auth', 'Login failed', [...]);
log_error('database', 'Connection failed', [...]);

// SBAGLIATO
log_warning('app', 'Login failed', [...]);  // Canale generico
```

### 2. Includi Context Utile

```php
// CORRETTO
log_warning('auth', 'Login failed', [
    'user_id' => $userId,
    'ip' => $ipAddress,
    'reason' => 'invalid_password',
    'attempts' => $failedAttempts,
]);

// SBAGLIATO
log_warning('auth', 'Login failed for user ' . $userId);  // No context strutturato
```

### 3. Non Loggare Dati Sensibili

```php
// CORRETTO
log_warning('auth', 'Password reset requested', [
    'user_id' => $userId,
    'token_prefix' => substr($token, 0, 8) . '...',
]);

// SBAGLIATO
log_warning('auth', 'Password reset requested', [
    'user_id' => $userId,
    'token' => $token,        // MAI loggare token completi
    'password' => $password,  // MAI loggare password
]);
```

### 4. Usa il Livello Appropriato

```php
// ERROR: Qualcosa è andato storto e richiede attenzione
log_error('database', 'Query failed', ['error' => $e->getMessage()]);

// WARNING: Situazione anomala ma gestita
log_warning('auth', 'Too many login attempts', ['attempts' => 5]);

// INFO: Evento normale ma significativo
log_info('access', 'User logged in', ['user_id' => $userId]);

// DEBUG: Solo per sviluppo (disabilitato in production)
log_debug('cache', 'Cache miss', ['key' => $key]);
```

### 5. Non Loggare in Loop Stretti

```php
// SBAGLIATO
foreach ($users as $user) {
    log_info('sync', 'Processing user', ['id' => $user->id]);
    // ... processo
}

// CORRETTO
log_info('sync', 'Starting batch user sync', ['count' => count($users)]);
foreach ($users as $user) {
    // ... processo
}
log_info('sync', 'Batch user sync completed', [
    'processed' => $processed,
    'failed' => $failed,
]);
```

## Troubleshooting

### I log non vengono scritti

1. Verifica che Bootstrap sia inizializzato:
   ```php
   if (!Bootstrap::isInitialized()) {
       Bootstrap::init();
   }
   ```

2. Verifica la directory dei log:
   ```bash
   ls -la storage/logs/
   # Deve essere scrivibile
   chmod 755 storage/logs/
   ```

3. Verifica il livello minimo del canale:
   ```sql
   SELECT * FROM log_channels WHERE channel = 'auth';
   ```

### Performance degradate

1. Verifica che Redis sia disponibile (evita file fallback):
   ```bash
   redis-cli ping
   ```

2. Verifica che il LogWorker sia in esecuzione:
   ```bash
   redis-cli LLEN eap:logs:queue
   # Se cresce, il worker non sta processando
   ```

3. Verifica la cache APCu:
   ```php
   var_dump(apcu_cache_info());
   ```

### Log duplicati

I log potrebbero duplicarsi se:
- Il buffer viene flushato più volte (bug)
- Il worker processa lo stesso batch due volte (raro)

Soluzione: Verifica che `LogBuffer::getInstance()->clear()` sia chiamato dopo il flush.
