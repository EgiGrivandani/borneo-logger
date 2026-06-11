# Borneo Logger

A lightweight PHP SDK for sending structured event logs to any HTTP ingest endpoint — ideal for audit trails, user activity tracking, and application monitoring.

## Installation

```bash
composer require borneo/logger
```

> Package belum stable release — install dengan:
> ```bash
> composer require borneo/logger:@dev
> ```

---

## Dispatch Modes

BorneoLogger supports two modes, **auto-detected** based on configuration:

| Mode | How | Latency | Recommended |
|------|-----|---------|-------------|
| **Mode 1 — File** | Writes JSON to a local log file. A log shipper (e.g. [Vector](https://vector.dev)) tails and ships it. | ~0.01ms | ✅ Production |
| **Mode 2 — HTTP** | Fire-and-forget HTTP POST directly to the ingest endpoint. | ~0.5ms (blocking) | Fallback / simple setups |

### Why Mode 1 for Production?

Mode 2 uses `file_get_contents()` which is **synchronous and blocking** in PHP — even with a short timeout. If the monitoring server is down for maintenance, every log call will block until timeout:

```
User request → login → BorneoLogger::loginSuccess()
                           ↓
                       [waiting 0.5s for HTTP timeout...]
                           ↓
                       Response finally sent ← user feels delay
```

Mode 1 writes to a local file (~0.01ms) and does not care about network or monitoring server availability. A **Vector Agent** running on the same server reads the file in real-time and ships it.

```
App writes to file (~0.01ms) → Response sent immediately
         ↓ (async, decoupled)
Vector Agent reads file → forwards to monitoring server
```

---

## Configuration

Call `configure()` once in your application bootstrap or config file:

```php
use Borneo\BorneoLogger;

// Mode 1 — File (recommended for production)
BorneoLogger::configure(
    endpoint: 'https://your-ingest-endpoint.com/logs',
    apiKey:   'your-api-key',
    service:  'your-service-name',
    logFile:  '/var/log/borneo/your-service.log'
);

// Mode 2 — HTTP fallback (no logFile needed)
BorneoLogger::configure(
    endpoint: 'https://your-ingest-endpoint.com/logs',
    apiKey:   'your-api-key',
    service:  'your-service-name'
);
```

### Using Environment Variables (Recommended)

```php
BorneoLogger::configure(
    endpoint: getenv('LOGGER_ENDPOINT') ?: '',
    apiKey:   getenv('LOGGER_API_KEY')  ?: '',
    service:  getenv('LOGGER_SERVICE')  ?: 'my-app',
    logFile:  getenv('LOGGER_LOG_FILE') ?: '', // empty = use HTTP mode
);
```

`.env` example:
```dotenv
# Borneo Logger
LOGGER_ENDPOINT=https://log.yourdomain.com/logs
LOGGER_API_KEY=your-vector-api-key
LOGGER_SERVICE=my-service-name
# Production: /var/log/borneo/my-service.log | Local dev: leave empty
LOGGER_LOG_FILE=
```

---

## Usage

### Auth Events

```php
// Successful login
BorneoLogger::loginSuccess($userId);

// Failed login (great for brute-force detection)
BorneoLogger::loginFailed('wrong_password');
BorneoLogger::loginFailed('account_banned', ['metadata' => ['email' => $email]]);
BorneoLogger::loginFailed('invalid_otp', ['user_id' => $userId]);

// Logout
BorneoLogger::logout($userId);
```

### HTTP Request Logging

Best placed in API Gateway or middleware, **after** the response is sent:

```php
BorneoLogger::httpRequest(
    method:     'POST',
    path:       '/api/checkout',
    statusCode: 200,
    latencyMs:  143,
    userId:     $userId,
    traceId:    $traceId
);
```

Status is automatically derived: `success` (2xx), `failed` (4xx), `error` (5xx).

### Exception & Error Logging

```php
// Catch any Throwable (ideal in a global exception handler)
set_exception_handler(fn($e) => BorneoLogger::exception($e, $userId));

// Generic error without an exception object
BorneoLogger::error('Payment gateway timeout', ['gateway' => 'midtrans'], $userId);
```

### Custom Events

```php
BorneoLogger::log('payment.created', [
    'user_id'  => 123,
    'status'   => 'success',
    'metadata' => ['amount' => 50000, 'method' => 'transfer'],
]);
```

`metadata` accepts any array — it will be auto-encoded to a JSON string.

---

## Log Payload Structure

Each log entry is dispatched as a JSON object (one line per entry in Mode 1):

```json
{
  "timestamp":  "2026-06-10T09:42:24.123Z",
  "service":    "your-service-name",
  "event_type": "user.login",
  "status":     "success",
  "user_id":    123,
  "ip":         "1.2.3.4",
  "user_agent": "Mozilla/5.0...",
  "metadata":   "{\"amount\":50000,\"method\":\"transfer\"}"
}
```

---

## Framework Integration

### CodeIgniter 3

**1. Create config file** `application/config/logger.php`:

```php
<?php defined('BASEPATH') OR exit('No direct script access allowed');

use Borneo\BorneoLogger;

BorneoLogger::configure(
    endpoint: getenv('LOGGER_ENDPOINT') ?: '',
    apiKey:   getenv('LOGGER_API_KEY')  ?: '',
    service:  getenv('LOGGER_SERVICE')  ?: 'my-app',
    logFile:  getenv('LOGGER_LOG_FILE') ?: '',
);
```

**2. Load vendor autoload and bootstrap** in `application/core/MY_Controller.php`:

```php
// Composer autoloader
require_once FCPATH . 'vendor/autoload.php';

class MY_Controller extends MX_Controller
{
    public function __construct()
    {
        parent::__construct();
        // Bootstrap BorneoLogger once
        $this->config->load('logger');
    }
}
```

**3. Use in controllers:**

```php
use Borneo\BorneoLogger;

class Auth extends MY_Controller
{
    public function login()
    {
        $user = $this->authService->authenticate($email, $password);
        if (!$user) {
            BorneoLogger::loginFailed('wrong_password', [
                'metadata' => ['email' => $email]
            ]);
            // ...
        }
    }

    private function _complete_login(object $user): void
    {
        // ... session logic ...
        BorneoLogger::loginSuccess((int)$user->id, [
            'metadata' => ['role' => $user->role],
        ]);
    }

    public function logout(): void
    {
        BorneoLogger::logout((int)$this->userdata['id']);
        // ... redirect ...
    }
}
```

---

## Production Deployment — Mode 1 with Vector Agent

In production, PHP writes logs to a local file and a **Vector Agent** (installed on the same PHP server) ships them to the monitoring endpoint. This completely decouples your application from the monitoring infrastructure.

```
[PHP Server]                        [Monitoring Server]
  App → /var/log/borneo/*.log
              ↓ (real-time, ~<1s)
        Vector Agent  ──── HTTPS ───►  Vector → ClickHouse → Grafana
```

### Step 1 — Create Log Directory

For single-user servers:
```bash
mkdir -p /var/log/borneo
chown your-user:your-user /var/log/borneo
chmod 775 /var/log/borneo
```

For **multi-user servers** (e.g. shared cPanel with multiple PHP apps):
```bash
# Create shared group
groupadd borneo-logs

# Add all app users to the group
usermod -aG borneo-logs user1
usermod -aG borneo-logs user2
usermod -aG borneo-logs user3

# Create folder with setgid bit
# setgid (2775) = new files automatically inherit the group
mkdir -p /var/log/borneo
chown root:borneo-logs /var/log/borneo
chmod 2775 /var/log/borneo

# Verify — must show 's' in group execute position
ls -la /var/log/ | grep borneo
# drwxrwsr-x  2 root borneo-logs ... borneo
```

Each service writes to its own file, all in the same watched directory:
```
/var/log/borneo/
├── panel-admin.log    ← LOGGER_SERVICE=panel-admin
├── core.log           ← LOGGER_SERVICE=core
├── pcd.log            ← LOGGER_SERVICE=pcd
└── bdw.log            ← LOGGER_SERVICE=bdw
```

### Step 2 — Install Vector Agent

```bash
# Download binary (recommended — no package manager needed)
curl -Lo /tmp/vector.tar.gz \
  https://github.com/vectordotdev/vector/releases/download/v0.43.0/vector-0.43.0-x86_64-unknown-linux-musl.tar.gz

tar -xzf /tmp/vector.tar.gz -C /tmp
cp /tmp/vector-x86_64-unknown-linux-musl/bin/vector /usr/local/bin/
chmod +x /usr/local/bin/vector

vector --version
```

### Step 3 — Configure Vector Agent

Create `/etc/vector/vector.toml`:

```toml
# Read all .log files from the borneo log directory
[sources.php_log_files]
  type      = "file"
  include   = ["/var/log/borneo/*.log"]
  read_from = "end"
  data_dir  = "/var/lib/vector"

# Parse each line as JSON
[transforms.parse_json]
  type   = "remap"
  inputs = ["php_log_files"]
  source = '''
    parsed, err = parse_json(.message)
    if err == null {
      . = merge!(., parsed)
    }
    del(.message)
    del(.source_type)
    del(.file)
    del(.host)
  '''

# Drop lines that failed to parse
[transforms.filter_valid]
  type      = "filter"
  inputs    = ["parse_json"]
  condition = 'exists(.event_type) && exists(.service)'

# Forward to monitoring server
[sinks.forward_to_monitoring]
  type           = "http"
  inputs         = ["filter_valid"]
  uri            = "https://log.yourdomain.com/logs"   # your Vector ingest URL
  method         = "post"
  auth.strategy  = "bearer"
  auth.token     = "YOUR_VECTOR_API_KEY"
  encoding.codec = "json"

  # Batch for efficiency
  batch.max_events   = 100
  batch.timeout_secs = 3

  # Retry if monitoring server is temporarily unavailable
  # Logs are buffered locally and not lost
  request.retry_attempts             = 10
  request.retry_initial_backoff_secs = 2
```

```bash
mkdir -p /var/lib/vector
vector validate /etc/vector/vector.toml
```

### Step 4 — Create systemd Service

```bash
cat > /etc/systemd/system/vector.service << 'EOF'
[Unit]
Description=Vector Log Agent
Documentation=https://vector.dev
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/local/bin/vector --config /etc/vector/vector.toml
Restart=on-failure
RestartSec=5s
StandardOutput=journal
StandardError=journal
User=root

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable vector
systemctl start vector
systemctl status vector
```

### Step 5 — Test End-to-End

```bash
# Write a test log entry manually
echo '{"timestamp":"2026-06-11T00:00:00.000Z","service":"panel-admin","event_type":"test.ping","status":"success","user_id":0,"ip":"127.0.0.1","user_agent":"manual-test","metadata":"{}"}' \
  >> /var/log/borneo/panel-admin.log

# Watch Vector ship it in real-time
journalctl -u vector -f
```

Check in Grafana/ClickHouse within a few seconds:
```sql
SELECT * FROM events WHERE service = 'panel-admin' ORDER BY timestamp DESC LIMIT 10
```

### Step 6 — Set Production `.env`

```dotenv
LOGGER_ENDPOINT=https://log.yourdomain.com/logs
LOGGER_API_KEY=                                  # not needed in Mode 1, leave empty
LOGGER_SERVICE=panel-admin
LOGGER_LOG_FILE=/var/log/borneo/panel-admin.log  # activates Mode 1
```

> **Note for local development:** Leave `LOGGER_LOG_FILE` empty. Logger will silently skip (no HTTP call, no error) since `LOGGER_ENDPOINT` is also empty or unreachable.

---

## Advanced Usage

### Dependency Injection

Use BorneoLogger as an instance — the service name is scoped to that instance only and does **not** affect the global configuration:

```php
$logger = new BorneoLogger('payment-service');
$logger->send('order.shipped', ['order_id' => 456]);
```

### Per-call Service Override

```php
BorneoLogger::log('inventory.updated', ['item_id' => 789], 'warehouse-service');
```

---

## Requirements

- PHP >= 8.0
- `allow_url_fopen = On` (required for Mode 2 HTTP only)

## License

MIT
