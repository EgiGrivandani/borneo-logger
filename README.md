# Borneo Logger

A lightweight PHP SDK for sending structured event logs to any HTTP ingest endpoint — ideal for audit trails, user activity tracking, and application monitoring.

## Installation

```bash
composer require borneo/logger
```

## Configuration

Call `configure()` once in your application bootstrap or config file:

```php
use Borneo\BorneoLogger;

BorneoLogger::configure(
    endpoint: 'https://your-ingest-endpoint.com/logs',
    apiKey:   'your-api-key',
    service:  'your-service-name'
);
```

## Usage

```php
use Borneo\BorneoLogger;

// Successful login
BorneoLogger::loginSuccess($userId);

// Failed login
BorneoLogger::loginFailed('wrong_password');

// Logout
BorneoLogger::logout($userId);

// Custom event
BorneoLogger::log('payment.created', [
    'user_id'  => 123,
    'status'   => 'success',
    'metadata' => ['amount' => 50000, 'method' => 'transfer'],
]);
```

## Log Payload Structure

Each log entry is sent as a JSON object with the following fields:

```json
{
  "timestamp":  "2026-01-01T00:00:00.000Z",
  "service":    "your-service-name",
  "event_type": "user.login",
  "status":     "success",
  "user_id":    123,
  "ip":         "1.2.3.4",
  "user_agent": "Mozilla/5.0..."
}
```

## Requirements

- PHP >= 8.0
- `allow_url_fopen = On` (for HTTP requests)

## Advanced Usage

You can also use BorneoLogger as an instance with dependency injection:

```php
$logger = new BorneoLogger('my-service');
$logger->send('order.shipped', ['order_id' => 456]);
```

Override the service name per-call for multi-service applications:

```php
BorneoLogger::log('inventory.updated', ['item_id' => 789], 'warehouse-service');
```

## License

MIT
