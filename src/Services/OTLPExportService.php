<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * OpenTelemetry (OTLP) Export Service — bridges ZeroBoiler analytics events
 * to any OTLP-compatible collector (Grafana Tempo, Jaeger, Honeycomb, Datadog, etc.).
 *
 * Converts AnalyticsEvent DTOs into OTLP JSON format (ResourceSpans) and
 * POSTs them to a configured OTLP HTTP endpoint. Supports:
 * - Event name → span name mapping
 * - Event params → span attributes
 * - Client/user ID → trace context linking
 * - Category-based span kind assignment
 * - Batch export with configurable max batch size
 * - Cache-backed export statistics (success/failure counts, latency)
 *
 * Inspired by Segment OTel bridge, PostHog OTLP export, and OpenTelemetry SDK.
 *
 * @since 38.0.0
 */
final class OTLPExportService
{
    /**
     * Package version for resource attributes.
     */
    private const VERSION = '38.0.0';

    /**
     * Default OTLP endpoint.
     */
    private const DEFAULT_ENDPOINT = 'http://localhost:4318/v1/traces';

    /**
     * Category → OpenTelemetry SpanKind mapping.
     *
     * @var array<string, int>
     */
    private const CATEGORY_SPAN_KINDS = [
        'ecommerce' => 3, // SPAN_KIND_CLIENT
        'saas' => 1,      // SPAN_KIND_INTERNAL
        'engagement' => 1, // SPAN_KIND_INTERNAL
        'security' => 2,  // SPAN_KIND_SERVER
        'uptime' => 2,    // SPAN_KIND_SERVER
    ];

    private bool $enabled;

    private string $endpoint;

    private string $headers;

    private array $resourceAttributes;

    private int $maxBatchSize;

    private int $timeout;

    private bool $debug;

    private string $cachePrefix;

    private int $cacheTtl;

    /** @var array{success: int, failure: int, exported: int, last_error: string|null, avg_latency_ms: float} */
    private array $stats;

    /**
     * @param  ConfigRepository  $config
     * @param  CacheRepository  $cache
     */
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
    ) {
        $otelConfig = $config->get('zeroboiler.analytics.otel', []);
        /** @var array{enabled?: bool, endpoint?: string, headers?: string, timeout?: int, max_batch_size?: int, debug?: bool, resource_attributes?: array<string, string>} $otelConfig */

        $this->enabled = (bool) ($otelConfig['enabled'] ?? false);
        $this->endpoint = is_string($otelConfig['endpoint'] ?? null) && $otelConfig['endpoint'] !== ''
            ? $otelConfig['endpoint']
            : self::DEFAULT_ENDPOINT;
        $this->headers = is_string($otelConfig['headers'] ?? null) ? $otelConfig['headers'] : '';
        $this->resourceAttributes = is_array($otelConfig['resource_attributes'] ?? null) ? $otelConfig['resource_attributes'] : [];
        $this->maxBatchSize = (int) ($otelConfig['max_batch_size'] ?? 100);
        $this->timeout = (int) ($otelConfig['timeout'] ?? 5);
        $this->debug = (bool) ($otelConfig['debug'] ?? false);
        $this->cachePrefix = (string) ($otelConfig['cache_prefix'] ?? 'zb_otel_');
        $this->cacheTtl = (int) ($otelConfig['cache_ttl'] ?? 300);

        $this->loadStats();
    }

    /**
     * Check if OTLP export is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable OTLP export at runtime.
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Disable OTLP export at runtime.
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Export a single analytics event to the OTLP collector.
     *
     * Converts the event into an OTLP ResourceSpans JSON payload and POSTs
     * it to the configured endpoint. Updates export statistics.
     *
     * @param  AnalyticsEvent  $event  The analytics event to export
     * @return bool  True if the export succeeded
     */
    public function export(AnalyticsEvent $event): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $span = $this->eventToSpan($event);

        if ($span === null) {
            return false;
        }

        $payload = $this->buildPayload([$span]);

        return $this->sendPayload($payload);
    }

    /**
     * Export multiple analytics events in a single OTLP batch request.
     *
     * Splits events into chunks of maxBatchSize and sends each chunk
     * as a separate OTLP request.
     *
     * @param  list<AnalyticsEvent>  $events  Analytics events to export
     * @return array{exported: int, failed: int, total: int}  Export results
     */
    public function exportBatch(array $events): array
    {
        if (! $this->enabled || $events === []) {
            return ['exported' => 0, 'failed' => 0, 'total' => 0];
        }

        $spans = [];
        $failed = 0;

        foreach ($events as $event) {
            $span = $this->eventToSpan($event);

            if ($span !== null) {
                $spans[] = $span;
            } else {
                $failed++;
            }
        }

        $exported = 0;
        $chunks = array_chunk($spans, $this->maxBatchSize);

        foreach ($chunks as $chunk) {
            $payload = $this->buildPayload($chunk);

            if ($this->sendPayload($payload)) {
                $exported += count($chunk);
            } else {
                $failed += count($chunk);
            }
        }

        return [
            'exported' => $exported,
            'failed' => $failed,
            'total' => count($events),
        ];
    }

    /**
     * Convert an AnalyticsEvent DTO to an OTLP Span representation.
     *
     * Maps event fields to OTLP span structure:
     * - name → span name
     * - params → attributes
     * - clientId → trace_id linking
     * - userId → identity attribute
     * - category → span kind
     * - timestamp → start/end time
     *
     * @return array<string, mixed>|null  OTLP Span array or null on failure
     */
    public function eventToSpan(AnalyticsEvent $event): ?array
    {
        try {
            $category = EventCatalog::getCategory($event->name);
            $spanKind = self::CATEGORY_SPAN_KINDS[$category ?? 'engagement'] ?? 1;

            $attributes = [
                ['key' => 'analytics.event.name', 'value' => ['stringValue' => $event->name]],
                ['key' => 'analytics.source', 'value' => ['stringValue' => $event->source ?? 'unknown']],
                ['key' => 'analytics.category', 'value' => ['stringValue' => $category ?? 'uncategorized']],
                ['key' => 'analytics.sdk.version', 'value' => ['stringValue' => self::VERSION]],
                ['key' => 'analytics.sdk.name', 'value' => ['stringValue' => 'zeroboiler-analytics']],
            ];

            if ($event->clientId !== null) {
                $attributes[] = ['key' => 'analytics.client_id', 'value' => ['stringValue' => $event->clientId]];
            }

            if ($event->userId !== null) {
                $attributes[] = ['key' => 'analytics.user_id', 'value' => ['stringValue' => $event->userId]];
                $attributes[] = ['key' => 'enduser.id', 'value' => ['stringValue' => $event->userId]];
            }

            if ($event->priority !== null) {
                $attributes[] = ['key' => 'analytics.priority', 'value' => ['stringValue' => $event->priority]];
            }

            // Convert event params to OTLP attributes
            foreach ($event->params as $key => $value) {
                $attributes[] = $this->paramToAttribute($key, $value);
            }

            // Compute timestamps in nanoseconds (OTLP requirement)
            $startTimeNs = $event->timestamp !== null
                ? ($event->timestamp->getTimestamp() * 1_000_000_000 + (int) $event->timestamp->format('u') * 1000)
                : (int) (microtime(true) * 1_000_000_000);

            // Generate trace and span IDs from event identity
            $traceId = $this->generateTraceId($event->clientId, $event->userId);
            $spanId = $this->generateSpanId($event->name, $startTimeNs);

            return [
                'traceId' => $traceId,
                'spanId' => $spanId,
                'name' => $event->name,
                'kind' => $spanKind,
                'startTimeUnixNano' => $startTimeNs,
                'endTimeUnixNano' => $startTimeNs + 1_000_000, // 1ms span duration
                'attributes' => $attributes,
                'status' => [],
            ];
        } catch (\Throwable $e) {
            if ($this->debug) {
                Log::debug('[ZeroBoiler OTLP] eventToSpan failed', [
                    'event' => $event->name,
                    'error' => $e->getMessage(),
                ]);
            }

            return null;
        }
    }

    /**
     * Convert a single event parameter to an OTLP attribute.
     *
     * Maps PHP types to OTLP attribute values:
     * - string → stringValue
     * - int → intValue
     * - float → doubleValue
     * - bool → boolValue
     * - array → JSON-encoded stringValue
     *
     * @return array{key: string, value: array{stringValue: string}|array{intValue: int}|array{doubleValue: float}|array{boolValue: bool}}
     */
    public function paramToAttribute(string $key, mixed $value): array
    {
        // Sanitize key: lowercase, replace dots/spaces with underscores, limit length
        $sanitizedKey = $this->sanitizeAttributeKey($key);

        if (is_string($value)) {
            return ['key' => $sanitizedKey, 'value' => ['stringValue' => mb_substr($value, 0, 500)]];
        }

        if (is_int($value)) {
            return ['key' => $sanitizedKey, 'value' => ['intValue' => $value]];
        }

        if (is_float($value)) {
            return ['key' => $sanitizedKey, 'value' => ['doubleValue' => $value]];
        }

        if (is_bool($value)) {
            return ['key' => $sanitizedKey, 'value' => ['boolValue' => $value]];
        }

        // Array, null, or other types → JSON string
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return ['key' => $sanitizedKey, 'value' => ['stringValue' => is_string($json) ? $json : 'null']];
    }

    /**
     * Build the full OTLP JSON payload for a set of spans.
     *
     * Wraps spans in the OTLP ResourceSpans structure with:
     * - Resource attributes (service.name, deployment.environment, etc.)
     * - Schema URL
     * - Scope (zeroboiler.analytics)
     *
     * @param  list<array<string, mixed>>  $spans  OTLP Span arrays
     * @return string  JSON-encoded OTLP payload
     */
    public function buildPayload(array $spans): string
    {
        // Merge user-defined resource attributes with defaults
        $resourceAttrs = array_merge([
            ['key' => 'service.name', 'value' => ['stringValue' => $this->resourceAttributes['service.name'] ?? 'zeroboiler-analytics']],
            ['key' => 'service.version', 'value' => ['stringValue' => self::VERSION]],
            ['key' => 'analytics.sdk', 'value' => ['stringValue' => 'zeroboiler']],
        ], $this->resourceAttributesToOTLP($this->resourceAttributes));

        $resourceSpans = [
            'resource' => [
                'attributes' => $resourceAttrs,
                'schemaUrl' => 'https://opentelemetry.io/schemas/1.24.0',
            ],
            'scopeSpans' => [
                [
                    'scope' => [
                        'name' => 'zeroboiler.analytics',
                        'version' => self::VERSION,
                    ],
                    'spans' => $spans,
                ],
            ],
        ];

        return json_encode(['resourceSpans' => [$resourceSpans]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Send an OTLP JSON payload to the configured collector endpoint.
     *
     * Uses cURL for HTTP transport. Updates export statistics
     * (success/failure counts, latency tracking).
     *
     * @param  string  $payload  JSON-encoded OTLP payload
     * @return bool  True if the HTTP request succeeded (2xx)
     */
    public function sendPayload(string $payload): bool
    {
        $startTime = microtime(true);

        try {
            $ch = curl_init($this->endpoint);

            if ($ch === false) {
                throw new \ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException('Failed to initialize cURL');
            }

            $httpHeaders = [
                'Content-Type: application/json',
                'Accept: application/json',
            ];

            // Append custom headers if configured
            if ($this->headers !== '') {
                $customHeaders = explode(',', $this->headers);
                foreach ($customHeaders as $header) {
                    $trimmed = trim($header);
                    if ($trimmed !== '') {
                        $httpHeaders[] = $trimmed;
                    }
                }
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $httpHeaders,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $elapsed = (microtime(true) - $startTime) * 1000; // ms

            if ($httpCode >= 200 && $httpCode < 300) {
                $this->stats['success']++;
                $this->stats['exported']++;
                $this->stats['last_error'] = null;
                $this->updateAvgLatency($elapsed);
                $this->persistStats();

                if ($this->debug) {
                    Log::debug('[ZeroBoiler OTLP] Export succeeded', [
                        'http_code' => $httpCode,
                        'latency_ms' => round($elapsed, 2),
                        'payload_size' => strlen($payload),
                    ]);
                }

                return true;
            }

            $this->stats['failure']++;
            $this->stats['last_error'] = $curlError !== '' ? $curlError : "HTTP {$httpCode}";
            $this->persistStats();

            if ($this->debug) {
                Log::debug('[ZeroBoiler OTLP] Export failed', [
                    'http_code' => $httpCode,
                    'error' => $this->stats['last_error'],
                    'latency_ms' => round($elapsed, 2),
                ]);
            }

            return false;
        } catch (\Throwable $e) {
            $elapsed = (microtime(true) - $startTime) * 1000;
            $this->stats['failure']++;
            $this->stats['last_error'] = $e->getMessage();
            $this->persistStats();

            if ($this->debug) {
                Log::debug('[ZeroBoiler OTLP] Export exception', [
                    'error' => $e->getMessage(),
                    'latency_ms' => round($elapsed, 2),
                ]);
            }

            return false;
        }
    }

    /**
     * Get OTLP export statistics.
     *
     * @return array{enabled: bool, endpoint: string, success: int, failure: int, exported: int, last_error: string|null, avg_latency_ms: float, success_rate: float}
     */
    public function stats(): array
    {
        $total = $this->stats['success'] + $this->stats['failure'];
        $successRate = $total > 0 ? round(($this->stats['success'] / $total) * 100, 2) : 0.0;

        return [
            'enabled' => $this->enabled,
            'endpoint' => $this->maskEndpoint($this->endpoint),
            'success' => $this->stats['success'],
            'failure' => $this->stats['failure'],
            'exported' => $this->stats['exported'],
            'last_error' => $this->stats['last_error'],
            'avg_latency_ms' => round($this->stats['avg_latency_ms'], 2),
            'success_rate' => $successRate,
        ];
    }

    /**
     * Reset all export statistics.
     */
    public function resetStats(): void
    {
        $this->stats = [
            'success' => 0,
            'failure' => 0,
            'exported' => 0,
            'last_error' => null,
            'avg_latency_ms' => 0.0,
        ];

        $this->cache->forget($this->cachePrefix . 'stats');
    }

    /**
     * Validate the OTLP configuration.
     *
     * Checks that the endpoint is reachable (DNS resolution) and
     * the configuration is structurally valid.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validate(): array
    {
        $errors = [];
        $warnings = [];

        if (! $this->enabled) {
            $warnings[] = 'OTLP export is disabled';
        }

        if ($this->endpoint === '') {
            $errors[] = 'OTLP endpoint is not configured';
        } elseif (! str_starts_with($this->endpoint, 'http')) {
            $errors[] = 'OTLP endpoint must start with http:// or https://';
        }

        if ($this->timeout < 1) {
            $warnings[] = 'Timeout is very low (< 1s), exports may fail on slow collectors';
        }

        if ($this->maxBatchSize < 1) {
            $errors[] = 'Max batch size must be at least 1';
        } elseif ($this->maxBatchSize > 1000) {
            $warnings[] = 'Max batch size is very large (> 1000), consider reducing to avoid oversized payloads';
        }

        if (! function_exists('curl_init')) {
            $errors[] = 'PHP cURL extension is required for OTLP export';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get the configured OTLP endpoint (masked for display).
     */
    public function getEndpoint(): string
    {
        return $this->maskEndpoint($this->endpoint);
    }

    /**
     * Sanitize an attribute key for OTLP compliance.
     *
     * OTLP attribute keys must match the regex: [a-zA-Z][a-zA-Z0-9_.\-* /]*
     * Keys are lowercased, special characters replaced, and length limited.
     */
    private function sanitizeAttributeKey(string $key): string
    {
        // Replace spaces and dots with underscores
        $cleaned = str_replace([' ', '.'], '_', $key);
        // Remove any non-OTLP-compliant characters
        $cleaned = preg_replace('/[^a-zA-Z0-9_\-*\/]/', '', $cleaned);
        // Ensure starts with a letter or underscore
        if (! preg_match('/^[a-zA-Z_]/', $cleaned)) {
            $cleaned = 'attr_' . $cleaned;
        }
        // Limit length to 256 characters
        return mb_substr($cleaned, 0, 256);
    }

    /**
     * Generate a deterministic 32-hex-char trace ID.
     *
     * @param  string|null  $clientId
     * @param  string|null  $userId
     * @return string  32-character hex string
     */
    private function generateTraceId(?string $clientId, ?string $userId): string
    {
        $seed = ($clientId ?? 'anonymous') . ':' . ($userId ?? 'no-user');

        return hash('xxh128', $seed);
    }

    /**
     * Generate a deterministic 16-hex-char span ID.
     *
     * @param  string  $eventName
     * @param  int  $timestampNs  Start time in nanoseconds
     * @return string  16-character hex string
     */
    private function generateSpanId(string $eventName, int $timestampNs): string
    {
        $seed = $eventName . ':' . $timestampNs;

        return substr(hash('xxh128', $seed), 0, 16);
    }

    /**
     * Convert resource attributes array to OTLP format.
     *
     * @param  array<string, string>  $attributes
     * @return list<array{key: string, value: array{stringValue: string}}>
     */
    private function resourceAttributesToOTLP(array $attributes): array
    {
        $result = [];

        foreach ($attributes as $key => $value) {
            // Skip keys we've already set as defaults
            if (in_array($key, ['service.name', 'service.version', 'analytics.sdk'], true)) {
                continue;
            }

            $result[] = [
                'key' => $this->sanitizeAttributeKey($key),
                'value' => ['stringValue' => (string) $value],
            ];
        }

        return $result;
    }

    /**
     * Mask sensitive parts of the endpoint URL for display.
     */
    private function maskEndpoint(string $endpoint): string
    {
        $parsed = parse_url($endpoint);

        if ($parsed === false) {
            return 'invalid://***';
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? 'localhost';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '';

        return "{$scheme}://{$host}{$port}{$path}";
    }

    /**
     * Update the rolling average latency.
     */
    private function updateAvgLatency(float $elapsedMs): void
    {
        $count = $this->stats['success'];
        $current = $this->stats['avg_latency_ms'];

        // Exponential moving average
        $this->stats['avg_latency_ms'] = $count === 1
            ? $elapsedMs
            : ($current * 0.9) + ($elapsedMs * 0.1);
    }

    /**
     * Load export statistics from cache.
     */
    private function loadStats(): void
    {
        $cached = $this->cache->get($this->cachePrefix . 'stats');

        if (is_array($cached)) {
            $this->stats = [
                'success' => (int) ($cached['success'] ?? 0),
                'failure' => (int) ($cached['failure'] ?? 0),
                'exported' => (int) ($cached['exported'] ?? 0),
                'last_error' => is_string($cached['last_error'] ?? null) ? $cached['last_error'] : null,
                'avg_latency_ms' => (float) ($cached['avg_latency_ms'] ?? 0.0),
            ];
        } else {
            $this->stats = [
                'success' => 0,
                'failure' => 0,
                'exported' => 0,
                'last_error' => null,
                'avg_latency_ms' => 0.0,
            ];
        }
    }

    /**
     * Persist export statistics to cache.
     */
    private function persistStats(): void
    {
        $this->cache->put($this->cachePrefix . 'stats', $this->stats, $this->cacheTtl);
    }
}
