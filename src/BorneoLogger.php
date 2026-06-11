<?php

namespace Borneo;

/**
 * BorneoLogger — Lightweight PHP SDK for sending structured event logs
 * to any HTTP ingest endpoint.
 *
 * Supports two dispatch modes (auto-detected):
 *   - Mode 1 (File): Writes JSON to a local log file. Lightning fast (~0.01ms).
 *                    A log shipper (e.g. Vector) on the server tails the file.
 *                    RECOMMENDED for production.
 *   - Mode 2 (HTTP): Fire-and-forget HTTP POST to the ingest endpoint.
 *                    Used as fallback when no logFile is configured.
 *
 * Quick start (Mode 1 — File):
 *
 *   use Borneo\BorneoLogger;
 *
 *   BorneoLogger::configure(
 *       endpoint: 'https://your-ingest-endpoint.com/logs',
 *       apiKey:   'your-api-key',
 *       service:  'your-service-name',
 *       logFile:  '/var/log/app/events.log'  // requires a log shipper on server
 *   );
 *
 *   BorneoLogger::loginSuccess($userId);
 *   BorneoLogger::loginFailed('wrong_password');
 *   BorneoLogger::log('payment.created', ['user_id' => 1, 'status' => 'success', 'metadata' => ['amount' => 50000]]);
 *
 * Quick start (Mode 2 — HTTP fallback):
 *
 *   BorneoLogger::configure('https://your-ingest-endpoint.com/logs', 'your-api-key', 'your-service-name');
 */
class BorneoLogger
{
    // -------------------------------------------------------
    // Configuration — set via BorneoLogger::configure()
    // -------------------------------------------------------
    private static string  $endpoint = '';
    private static string  $apiKey   = '';
    private static string  $service  = 'app';
    private static float   $timeout  = 0.5;
    private static string  $logFile  = ''; // Absolute path to local log file (Mode 1)

    /**
     * Configure the logger — call once in your bootstrap or config file.
     *
     * @param string $endpoint  Ingest endpoint URL (used as HTTP fallback)
     * @param string $apiKey    API key for authentication
     * @param string $service   Service name, e.g. 'api', 'admin', 'worker'
     * @param float  $timeout   HTTP timeout in seconds (default: 0.5, Mode 2 only)
     * @param string $logFile   Absolute path to local log file (Mode 1). Leave empty to use HTTP.
     *                          Example: '/var/log/app/events.log'
     */
    public static function configure(
        string $endpoint,
        string $apiKey   = '',
        string $service  = 'app',
        float  $timeout  = 0.5,
        string $logFile  = ''
    ): void {
        static::$endpoint = rtrim($endpoint, '/');
        static::$apiKey   = $apiKey;
        static::$service  = $service;
        static::$timeout  = $timeout;
        static::$logFile  = $logFile;
    }

    // -------------------------------------------------------
    // Public API — General
    // -------------------------------------------------------

    /**
     * Send a custom log event.
     *
     * @param string $eventType  e.g. 'user.login', 'payment.created'
     * @param array  $data       Payload: status, user_id, ip, metadata, etc.
     * @param string $service    Override the service name (optional)
     */
    public static function log(string $eventType, array $data = [], string $service = ''): void
    {
        try {
            // Auto-encode metadata array → JSON string (ClickHouse requires String type)
            if (isset($data['metadata']) && is_array($data['metadata'])) {
                $data['metadata'] = json_encode($data['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $payload = array_merge([
                'timestamp'  => static::timestamp(),
                'service'    => $service ?: static::$service,
                'event_type' => $eventType,
                'ip'         => static::getClientIp(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'status'     => 'success',
                'user_id'    => 0,
                'metadata'   => '{}',
            ], $data);

            static::dispatch($payload);
        } catch (\Throwable $e) {
            // Logging must NEVER break the main application.
            // Silently swallow all errors — the app continues normally.
        }
    }

    // -------------------------------------------------------
    // Public API — Auth Shortcuts
    // -------------------------------------------------------

    /** Log successful login */
    public static function loginSuccess(int $userId, array $extra = []): void
    {
        static::log('user.login', array_merge([
            'user_id' => $userId,
            'status'  => 'success',
        ], $extra));
    }

    /** Log failed login attempt (great for brute-force detection in Grafana) */
    public static function loginFailed(string $reason = '', array $extra = []): void
    {
        static::log('user.login', array_merge([
            'status'   => 'failed',
            'metadata' => ['reason' => $reason],
        ], $extra));
    }

    /** Log user logout */
    public static function logout(int $userId, array $extra = []): void
    {
        static::log('user.logout', array_merge([
            'user_id' => $userId,
            'status'  => 'success',
        ], $extra));
    }

    // -------------------------------------------------------
    // Public API — Error & Exception Shortcuts
    // -------------------------------------------------------

    /**
     * Log a PHP Exception or Error.
     * Ideal to call in a global exception handler.
     *
     * Example:
     *   set_exception_handler(fn($e) => BorneoLogger::exception($e));
     *
     * @param \Throwable $e       The caught exception/error
     * @param int        $userId  Current user ID if known
     */
    public static function exception(\Throwable $e, int $userId = 0): void
    {
        static::log('app.exception', [
            'status'   => 'error',
            'user_id'  => $userId,
            'metadata' => [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
                'code'    => $e->getCode(),
            ],
        ]);
    }

    /**
     * Log a generic application error (without an Exception object).
     *
     * Example:
     *   BorneoLogger::error('Payment gateway timeout', ['gateway' => 'midtrans']);
     */
    public static function error(string $message, array $extra = [], int $userId = 0): void
    {
        static::log('app.error', array_merge([
            'status'   => 'error',
            'user_id'  => $userId,
            'metadata' => ['message' => $message],
        ], $extra));
    }

    // -------------------------------------------------------
    // Public API — HTTP / API Gateway Shortcuts
    // -------------------------------------------------------

    /**
     * Log an incoming HTTP request.
     * Best used in API Gateway or middleware, AFTER the response is sent.
     *
     * Example:
     *   BorneoLogger::httpRequest('POST', '/api/checkout', 200, 450, $userId, $traceId);
     *
     * @param string $method      HTTP method (GET, POST, etc.)
     * @param string $path        Request path (e.g. '/api/v1/login')
     * @param int    $statusCode  HTTP response status code (200, 404, 500, etc.)
     * @param int    $latencyMs   Total request latency in milliseconds
     * @param int    $userId      Authenticated user ID (0 if guest)
     * @param string $traceId     Correlation/Trace ID (for cross-service tracking)
     */
    public static function httpRequest(
        string $method,
        string $path,
        int    $statusCode,
        int    $latencyMs   = 0,
        int    $userId      = 0,
        string $traceId     = ''
    ): void {
        $status = $statusCode >= 500 ? 'error'
                : ($statusCode >= 400 ? 'failed'
                : 'success');

        static::log('http.request', [
            'status'   => $status,
            'user_id'  => $userId,
            'metadata' => [
                'method'      => strtoupper($method),
                'path'        => $path,
                'status_code' => $statusCode,
                'latency_ms'  => $latencyMs,
                'trace_id'    => $traceId,
            ],
        ]);
    }

    // -------------------------------------------------------
    // Instance API — for use with dependency injection
    // -------------------------------------------------------

    /**
     * Per-instance service name override.
     * Stored here (not in static) so multiple instances don't pollute each other.
     */
    private string $instanceService = '';

    /**
     * @param string $serviceName  Override service name for this instance only.
     *                             Does NOT mutate the global static::$service.
     */
    public function __construct(string $serviceName = '')
    {
        $this->instanceService = $serviceName;
    }

    public function send(string $eventType, array $data = []): void
    {
        static::log($eventType, $data, $this->instanceService);
    }

    // -------------------------------------------------------
    // Internals
    // -------------------------------------------------------

    private static function dispatch(array $payload): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        // MODE 1: Write to local log file (fastest, 0.01ms)
        // Vector Agent on this server will tail the file and ship it to ingest server.
        if (!empty(static::$logFile)) {
            $dir = dirname(static::$logFile);
            if (is_writable($dir) || is_writable(static::$logFile)) {
                @file_put_contents(static::$logFile, $body, FILE_APPEND | LOCK_EX);
                return; // Done — no HTTP needed
            }
        }

        // MODE 2: HTTP Fire & forget (fallback)
        if (empty(static::$endpoint)) {
            return; // Nothing configured — skip silently
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . static::$apiKey,
                    'Content-Length: ' . strlen($body),
                ]),
                'content'       => $body,
                'timeout'       => static::$timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        // Fire & forget — does not wait for response to avoid blocking
        @file_get_contents(static::$endpoint, false, $context);
    }

    /**
     * Generate an ISO 8601 UTC timestamp with millisecond precision.
     * Uses microtime(true) for accurate sub-second values.
     *
     * Example output: "2026-06-10T09:42:24.123Z"
     */
    private static function timestamp(): string
    {
        $t = microtime(true);
        return gmdate('Y-m-d\TH:i:s.', (int) $t)
            . sprintf('%03d', ($t - (int) $t) * 1000)
            . 'Z';
    }

    private static function getClientIp(): string
    {
        $keys = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', $_SERVER[$key])[0]);
            }
        }

        return '';
    }
}
