# Borneo Logger

A lightweight PHP SDK for sending structured event logs to any HTTP ingest endpoint — ideal for audit trails, user activity tracking, and application monitoring.

## Installation

```bash
composer require borneo/logger
```

## Dispatch Modes

BorneoLogger supports two modes, auto-detected based on configuration:

| Mode | How | Latency | Recommended |
|------|-----|---------|-------------|
| **Mode 1 — File** | Writes JSON to a local log file. A log shipper (e.g. [Vector](https://vector.dev)) tails and ships it. | ~0.01ms | ✅ Production |
| **Mode 2 — HTTP** | Fire-and-forget HTTP POST directly to the ingest endpoint. | ~0.5ms | Fallback / simple setups |

## Configuration

Call `configure()` once in your application bootstrap or config file:

```php
use Borneo\BorneoLogger;

// Mode 1 — File (recommended for production)
BorneoLogger::configure(
    endpoint: 'https://your-ingest-endpoint.com/logs',
    apiKey:   'your-api-key',
    service:  'your-service-name',
    logFile:  '/var/log/app/events.log'  // a log shipper tails this file
);

// Mode 2 — HTTP fallback (no logFile needed)
BorneoLogger::configure(
    endpoint: 'https://your-ingest-endpoint.com/logs',
    apiKey:   'your-api-key',
    service:  'your-service-name'
);
```

## Usage

### Auth Events

```php
// Successful login
BorneoLogger::loginSuccess($userId);

// Failed login (great for brute-force detection)
BorneoLogger::loginFailed('wrong_password');

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

## Log Payload Structure

Each log entry is dispatched as a JSON object:

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

## Requirements

- PHP >= 8.0
- `allow_url_fopen = On` (required for Mode 2 HTTP)

## License

MIT
