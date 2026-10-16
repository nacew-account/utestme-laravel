<?php

namespace Nacew\Utestme;

use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Collects what one request (or one artisan command, one queued job) has to
 * say, and sends it to uTestMe after the response has gone to the visitor.
 *
 * It sends to the same endpoint, with the same key and batch, as the uTestMe
 * log agent: to uTestMe an app is one more "server", so its errors are
 * grouped, explained and alerted on like any other. It never sends request
 * bodies, headers, cookies or query strings; secrets in messages are removed
 * again on arrival.
 *
 * PHP forgets everything between requests, so the request counts per minute
 * are kept in Laravel's cache, and the first request of a new minute sends
 * the minutes before it.
 */
class Client
{
    public const VERSION = '0.1.1';

    private const MAX_BREADCRUMBS = 10;
    private const BREADCRUMB_CHARS = 300;
    private const MAX_QUEUE = 50;
    private const STATUS_EVERY = 3600;
    private const MAX_MESSAGE_CHARS = 8000;

    /** @var array<int, array{at: int, sdk: array<string, mixed>}> */
    private array $queue = [];

    /** @var string[] */
    private array $breadcrumbs = [];

    /** @var array{method: string, path: string, route: ?string, start: float}|null */
    private ?array $request = null;

    /** @var array<int, array<string, int>> */
    private array $minutes = [];

    private ?string $user = null;

    public function __construct(
        private array $config,
        private ?Cache $cache = null,
        private ?string $basePath = null,
        private ?string $laravelVersion = null,
    ) {
    }

    public function enabled(): bool
    {
        return is_string($this->config['key'] ?? null) && str_starts_with($this->config['key'], 'utm_sk_');
    }

    /** The request being served, so an error knows where it happened. */
    public function startRequest(string $method, string $path, ?string $route = null): void
    {
        $this->request = [
            'method' => strtoupper($method),
            'path' => substr(strtok($path, '?#') ?: '/', 0, 2048),
            'route' => $route,
            'start' => microtime(true),
        ];
    }

    public function setRoute(?string $route): void
    {
        if ($this->request !== null && $route) {
            $this->request['route'] = '/'.ltrim($route, '/');
        }
    }

    /** The user the request is for: an id, never an email. */
    public function setUser(string|int|null $id): void
    {
        $this->user = $id === null ? null : (string) $id;
    }

    public function addBreadcrumb(string $line): void
    {
        $this->breadcrumbs[] = mb_substr(preg_replace('/\s+/', ' ', $line) ?? $line, 0, self::BREADCRUMB_CHARS);
        if (count($this->breadcrumbs) > self::MAX_BREADCRUMBS) {
            array_shift($this->breadcrumbs);
        }
    }

    /**
     * Map a Monolog / Laravel log level to what uTestMe stores, or null when
     * the line is not an error or warning (info, debug, notice stay breadcrumbs).
     */
    public function mapLogLevel(string $level): ?string
    {
        return match (strtolower($level)) {
            'emergency', 'alert', 'critical' => 'fatal',
            'error' => 'error',
            'warning', 'warn' => 'warn',
            default => null,
        };
    }

    public function capture(Throwable $e, array $context = []): void
    {
        if (! $this->enabled()) {
            return;
        }
        $this->enqueue([
            'level' => $context['level'] ?? 'error',
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'stack' => $e->getTraceAsString(),
            'handled' => $context['handled'] ?? false,
            'status' => $context['status'] ?? 500,
            'tags' => $context['tags'] ?? null,
        ]);
    }

    /**
     * A Log::error / Log::warning (or emergency/alert/critical) line, without
     * a Throwable. Skipped when the line is Laravel logging an exception that
     * reportable already sent.
     */
    public function captureMessage(string $message, string $level, array $context = []): void
    {
        if (! $this->enabled()) {
            return;
        }
        $message = trim($message);
        if ($message === '') {
            return;
        }
        $this->enqueue([
            'level' => $level,
            'type' => 'Log',
            'message' => mb_substr($message, 0, self::MAX_MESSAGE_CHARS),
            'handled' => $context['handled'] ?? true,
            'tags' => $context['tags'] ?? null,
        ]);
    }

    /**
     * Send what this request collected. Called after the response is sent.
     *
     * @return array{status: int, events: int|null}|null null when nothing to send
     */
    public function flush(): ?array
    {
        if (! $this->enabled() || (! $this->queue && ! $this->minutes)) {
            return null;
        }
        $eventCount = count($this->queue);
        $batch = [
            'v' => 1,
            'batch' => bin2hex(random_bytes(12)),
            'agent' => [
                'version' => 'sdk-laravel/'.self::VERSION,
                'mode' => null,
                'hostname' => (string) gethostname(),
                'os' => php_uname('s').' '.php_uname('r'),
                'tz' => date_default_timezone_get(),
                'status' => $this->status(),
            ],
            'entries' => $this->queue,
            'minutes' => $this->minutes,
        ];
        $this->queue = [];
        $this->minutes = [];

        return $this->post($batch, $eventCount);
    }

    /** Count one response; kept in the cache until its minute is over. */
    public function countResponse(int $status): void
    {
        if (! $this->enabled() || ! $this->cache || ! ($this->config['count_requests'] ?? true)) {
            return;
        }
        $minute = intdiv(time(), 60) * 60;
        $class = $status >= 500 ? 's5xx' : ($status >= 400 ? 's4xx' : ($status >= 300 ? 's3xx' : 's2xx'));
        try {
            foreach (['requests', $class] as $field) {
                $key = "utestme:m:$minute:$field";
                $this->cache->add($key, 0, 7200);
                $this->cache->increment($key);
            }
            // The first request of a minute sends the minutes before it that
            // nobody has sent yet (at most the last hour's).
            if ($this->cache->add("utestme:flushed:$minute", 1, 7200)) {
                $from = max((int) $this->cache->get('utestme:last-flushed', $minute - 3600), $minute - 3600);
                for ($m = $from + 60; $m < $minute; $m += 60) {
                    $counts = ['minute' => $m * 1000];
                    foreach (['requests', 's2xx', 's3xx', 's4xx', 's5xx'] as $field) {
                        $counts[$field] = (int) $this->cache->pull("utestme:m:$m:$field", 0);
                    }
                    if ($counts['requests'] > 0) {
                        $this->minutes[] = $counts;
                    }
                }
                $this->cache->put('utestme:last-flushed', $minute - 60, 86400 * 7);
            }
        } catch (Throwable) {
            // A cache that fails must never break the app.
        }
    }

    /**
     * @param  array{
     *   level: string,
     *   type: string,
     *   message: string,
     *   file?: string,
     *   line?: int,
     *   stack?: string,
     *   handled: bool,
     *   status?: int,
     *   tags: mixed
     * }  $fields
     */
    private function enqueue(array $fields): void
    {
        $request = null;
        if ($this->request !== null) {
            $request = [
                'method' => $this->request['method'],
                'path' => $this->request['path'],
                'durationMs' => (int) round((microtime(true) - $this->request['start']) * 1000),
            ];
            // Exceptions default to 500; Log::error during a normal page has no status yet.
            if (array_key_exists('status', $fields) && $fields['status'] !== null) {
                $request['status'] = $fields['status'];
            } elseif (isset($fields['stack'])) {
                $request['status'] = 500;
            }
        }
        $this->queue[] = [
            'at' => $this->nowMs(),
            'sdk' => array_filter([
                'runtime' => 'php',
                'level' => $fields['level'],
                'type' => $fields['type'],
                'message' => $fields['message'],
                'file' => $fields['file'] ?? null,
                'line' => $fields['line'] ?? null,
                'stack' => $fields['stack'] ?? null,
                'at' => $this->nowMs(),
                'app' => (string) ($this->config['app'] ?? 'laravel'),
                'request' => $request,
                'route' => $this->request['route'] ?? null,
                'release' => $this->config['release'] ?? null,
                'environment' => $this->config['environment'] ?? null,
                'user' => $this->user,
                'handled' => $fields['handled'],
                'breadcrumbs' => $this->breadcrumbs,
                'tags' => $fields['tags'],
            ], fn ($v) => $v !== null),
        ];
        if (count($this->queue) > self::MAX_QUEUE) {
            $this->queue = array_slice($this->queue, -self::MAX_QUEUE);
        }
    }

    /** What the app runs, at most hourly: PHP and Laravel versions. */
    private function status(): ?array
    {
        try {
            if ($this->cache && ! $this->cache->add('utestme:status-sent', 1, self::STATUS_EVERY)) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return [
            'inventory' => [
                'collectedAt' => $this->nowMs(),
                'os' => php_uname('s').' '.php_uname('r'),
                'kernel' => php_uname('r'),
                'arch' => php_uname('m'),
                'runtimes' => [['name' => 'php', 'version' => PHP_VERSION]],
                'sites' => $this->basePath ? [[
                    'path' => $this->basePath,
                    'name' => (string) ($this->config['app'] ?? 'laravel'),
                    'framework' => 'laravel',
                    'version' => $this->laravelVersion,
                ]] : [],
            ],
        ];
    }

    /**
     * POST the batch; status and how many events uTestMe said it took.
     *
     * @return array{status: int, events: int|null}|null
     */
    private function post(array $batch, int $sentEvents): ?array
    {
        $url = rtrim((string) ($this->config['url'] ?? 'https://utest.me'), '/').'/api/logs/agent/ingest';
        $body = json_encode($batch, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($body === false) {
            return null;
        }
        $headers = [
            'Authorization: Bearer '.$this->config['key'],
            'Content-Type: application/json',
            'User-Agent: utestme-laravel/'.self::VERSION,
        ];
        $timeout = (int) ($this->config['timeout'] ?? 3);
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                ]);
                $response = curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if (! $status) {
                    return null;
                }

                return ['status' => $status, 'events' => $this->eventsFromResponse($response, $sentEvents)];
            }
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ]]);
            $response = @file_get_contents($url, false, $context);
            $line = $http_response_header[0] ?? '';
            if (! preg_match('/\s(\d{3})\s/', $line, $m)) {
                return null;
            }

            return ['status' => (int) $m[1], 'events' => $this->eventsFromResponse($response, $sentEvents)];
        } catch (Throwable) {
            return null;
        }
    }

    /** How many events the ingest reported, or what we sent when the body is opaque. */
    private function eventsFromResponse(mixed $response, int $sentEvents): ?int
    {
        if (! is_string($response) || $response === '') {
            return null;
        }
        $decoded = json_decode($response, true);
        if (! is_array($decoded) || ! array_key_exists('events', $decoded)) {
            // 2xx with no JSON body: assume what we sent arrived (older endpoints).
            return $sentEvents;
        }

        return is_numeric($decoded['events']) ? (int) $decoded['events'] : null;
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
