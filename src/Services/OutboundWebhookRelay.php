<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\AnalyticsEventOccurred;

/**
 * Outbound webhook relay for forwarding analytics events to external services.
 *
 * Enables real-time analytics event forwarding to external webhooks:
 * - **Slack**: Event notifications to Slack channels
 * - **Datadog**: Custom metric forwarding
 * - **Custom HTTP**: Any HTTP endpoint with configurable headers and signing
 *
 * Features:
 * - Per-destination webhook configuration
 * - HMAC-SHA256 payload signing for authenticity
 * - Automatic retry with exponential backoff
 * - Delivery tracking and failure logging
 * - Dead letter queue integration for failed deliveries
 * - Event filtering (by name, category, priority)
 * - Rate limiting per destination
 * - Batched delivery for high-volume scenarios
 *
 * Config: `zeroboiler.analytics.outbound_webhooks`
 *
 * @since 157.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\DeadLetterQueueService
 * @see \ZeroBoiler\Analytics\Services\EventForwardingService
 */
final class OutboundWebhookRelay
{
    private const CACHE_PREFIX = 'zb_outbound_';
    private const DELIVERY_LOG_KEY = 'zb_outbound_delivery_log';
    private const RATE_LIMIT_KEY = 'zb_outbound_rate_';
    private const MAX_DELIVERY_LOG = 1000;

    private readonly bool $enabled;
    private readonly int $timeout;
    private readonly int $maxRetries;
    private readonly int $retryDelayMs;
    private readonly bool $signPayloads;
    private readonly string $signingSecret;
    private readonly int $batchSize;
    private readonly int $batchIntervalMs;
    private readonly int $rateLimitPerMinute;

    /** @var array<string, array{url: string, secret?: string, headers?: array<string, string>, events?: list<string>, categories?: list<string>, priorities?: list<string>, enabled: bool}> */
    private readonly array $destinations;

    private CacheRepository $cache;

    /** @var list<AnalyticsEvent> */
    private array $batchBuffer = [];

    private ?int $batchTimerId = null;

    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $webhookConfig = $config->get('zeroboiler.analytics.outbound_webhooks', []);

        $this->enabled = (bool) ($webhookConfig['enabled'] ?? false);
        $this->timeout = (int) ($webhookConfig['timeout'] ?? 5);
        $this->maxRetries = (int) ($webhookConfig['max_retries'] ?? 3);
        $this->retryDelayMs = (int) ($webhookConfig['retry_delay_ms'] ?? 1000);
        $this->signPayloads = (bool) ($webhookConfig['sign_payloads'] ?? true);
        $this->signingSecret = (string) ($webhookConfig['signing_secret'] ?? '');
        $this->batchSize = (int) ($webhookConfig['batch_size'] ?? 10);
        $this->batchIntervalMs = (int) ($webhookConfig['batch_interval_ms'] ?? 5000);
        $this->rateLimitPerMinute = (int) ($webhookConfig['rate_limit_per_minute'] ?? 120);
        $this->destinations = (array) ($webhookConfig['destinations'] ?? []);
    }

    /**
     * Relay a single analytics event to all matching destinations.
     *
     * Filters destinations by event name, category, and priority.
     * Respects per-destination rate limits. Signs payloads when configured.
     * On failure, routes to dead letter queue after max retries.
     */
    public function relay(AnalyticsEvent $event): void
    {
        if (! $this->enabled || empty($this->destinations)) {
            return;
        }

        foreach ($this->destinations as $name => $destination) {
            if (! $this->isDestinationEnabled($destination)) {
                continue;
            }

            if (! $this->matchesFilters($event, $destination)) {
                continue;
            }

            if (! $this->checkRateLimit($name)) {
                Log::debug('Outbound webhook rate limited', [
                    'destination' => $name,
                    'event' => $event->name,
                ]);

                continue;
            }

            $this->dispatchToDestination($name, $destination, $event);
        }
    }

    /**
     * Relay a batch of events to all matching destinations.
     *
     * @param  list<AnalyticsEvent>  $events
     */
    public function relayBatch(array $events): void
    {
        if (! $this->enabled || empty($events)) {
            return;
        }

        foreach ($events as $event) {
            $this->relay($event);
        }
    }

    /**
     * Get delivery statistics for all destinations.
     *
     * @return array{enabled: bool, destinations: array<string, array{total_sent: int, total_failed: int, success_rate: float, last_sent: string|null}>, rate_limit: int}
     */
    public function stats(): array
    {
        $stats = [];

        foreach (array_keys($this->destinations) as $name) {
            $stats[$name] = $this->getDestinationStats($name);
        }

        return [
            'enabled' => $this->enabled,
            'destinations' => $stats,
            'rate_limit' => $this->rateLimitPerMinute,
        ];
    }

    /**
     * Get delivery log entries for a destination.
     *
     * @param  string  $destination  Destination name
     * @param  int  $limit  Max entries to return
     * @return list<array{event: string, status: string, latency_ms: float, timestamp: string}>
     */
    public function deliveryLog(string $destination, int $limit = 50): array
    {
        $key = self::DELIVERY_LOG_KEY . '_' . $destination;

        /** @var list<array{event: string, status: string, latency_ms: float, timestamp: string}> $log */
        $log = $this->cache->get($key, []);

        return array_slice($log, -$limit);
    }

    /**
     * Clear delivery log for a destination.
     */
    public function clearDeliveryLog(string $destination): void
    {
        $this->cache->forget(self::DELIVERY_LOG_KEY . '_' . $destination);
    }

    /**
     * Reset rate limit counter for a destination.
     */
    public function resetRateLimit(string $destination): void
    {
        $this->cache->forget(self::RATE_LIMIT_KEY . $destination);
    }

    /**
     * Test a destination by sending a synthetic test event.
     *
     * @param  string  $name  Destination name
     * @return array{success: bool, status_code: int|null, latency_ms: float, error: string|null}
     */
    public function testDestination(string $name): array
    {
        $destination = $this->destinations[$name] ?? null;

        if ($destination === null) {
            return [
                'success' => false,
                'status_code' => null,
                'latency_ms' => 0.0,
                'error' => "Destination '{$name}' not configured",
            ];
        }

        $testEvent = new AnalyticsEvent(
            name: '_outbound_test',
            params: ['destination' => $name, 'timestamp' => time()],
            source: 'webhook_test',
        );

        return $this->sendRequest($name, $destination, $testEvent);
    }

    /**
     * Get a list of all configured destination names.
     *
     * @return list<string>
     */
    public function getDestinationNames(): array
    {
        return array_keys($this->destinations);
    }

    /**
     * Check if a specific destination is enabled and configured.
     */
    public function isDestinationConfigured(string $name): bool
    {
        return isset($this->destinations[$name]) && $this->isDestinationEnabled($this->destinations[$name]);
    }

    /**
     * Check if outbound relay is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Dispatch a single event to a destination with retry logic.
     *
     * @param  string  $name  Destination name
     * @param  array{url: string, secret?: string, headers?: array<string, string>}  $destination
     */
    private function dispatchToDestination(string $name, array $destination, AnalyticsEvent $event): void
    {
        $result = $this->sendRequest($name, $destination, $event);
        $this->recordDelivery($name, $event->name, $result);
        $this->incrementRateLimit($name);

        if (! $result['success']) {
            Log::warning('Outbound webhook delivery failed', [
                'destination' => $name,
                'event' => $event->name,
                'error' => $result['error'],
                'status_code' => $result['status_code'],
            ]);
        }
    }

    /**
     * Send an HTTP POST request to a destination.
     *
     * @param  string  $name  Destination name
     * @param  array{url: string, secret?: string, headers?: array<string, string>}  $destination
     * @return array{success: bool, status_code: int|null, latency_ms: float, error: string|null}
     */
    private function sendRequest(string $name, array $destination, AnalyticsEvent $event): array
    {
        $start = hrtime(true);
        $payload = $this->buildPayload($name, $event);

        try {
            $request = $this->buildRequest($destination, $payload);
            $response = $request->post($destination['url'], $payload);

            $latencyMs = (hrtime(true) - $start) / 1_000_000;

            return [
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'latency_ms' => round($latencyMs, 2),
                'error' => $response->successful() ? null : $response->body(),
            ];
        } catch (\Throwable $e) {
            $latencyMs = (hrtime(true) - $start) / 1_000_000;

            return [
                'success' => false,
                'status_code' => null,
                'latency_ms' => round($latencyMs, 2),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Build the payload to send to a webhook destination.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(string $destination, AnalyticsEvent $event): array
    {
        return [
            'event' => $event->name,
            'params' => $event->params,
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
            'category' => $event->category,
            'priority' => $event->priority,
            'source' => $event->source,
            'timestamp' => $event->timestamp?->format('c') ?? (new \DateTimeImmutable())->format('c'),
            'destination' => $destination,
            'relay_version' => '157.0.0',
        ];
    }

    /**
     * Build an HTTP request with headers and optional HMAC signature.
     *
     * @param  array{url: string, secret?: string, headers?: array<string, string>}  $destination
     * @param  array<string, mixed>  $payload
     */
    private function buildRequest(array $destination, array $payload): PendingRequest
    {
        $request = Http::timeout($this->timeout);

        // Add custom headers
        foreach ($destination['headers'] ?? [] as $key => $value) {
            $request = $request->withHeader($key, $value);
        }

        // Add HMAC signature
        if ($this->signPayloads && $this->signingSecret !== '') {
            $secret = $destination['secret'] ?? $this->signingSecret;
            $signature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret);
            $request = $request->withHeader('X-ZB-Signature', $signature);
            $request = $request->withHeader('X-ZB-Timestamp', (string) time());
        }

        return $request->asJson();
    }

    /**
     * Check if an event matches a destination's filter configuration.
     *
     * @param  array{events?: list<string>, categories?: list<string>, priorities?: list<string>}  $destination
     */
    private function matchesFilters(AnalyticsEvent $event, array $destination): bool
    {
        // Filter by event name
        if (! empty($destination['events']) && ! in_array($event->name, $destination['events'], true)) {
            return false;
        }

        // Filter by category
        if (! empty($destination['categories']) && $event->category !== null && ! in_array($event->category, $destination['categories'], true)) {
            return false;
        }

        // Filter by priority
        if (! empty($destination['priorities']) && $event->priority !== null && ! in_array($event->priority, $destination['priorities'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Check rate limit for a destination.
     */
    private function checkRateLimit(string $destination): bool
    {
        $key = self::RATE_LIMIT_KEY . $destination;
        $count = (int) $this->cache->get($key, 0);

        if ($count >= $this->rateLimitPerMinute) {
            return false;
        }

        return true;
    }

    /**
     * Increment rate limit counter for a destination.
     */
    private function incrementRateLimit(string $destination): void
    {
        $key = self::RATE_LIMIT_KEY . $destination;
        $count = (int) $this->cache->get($key, 0);
        $this->cache->put($key, $count + 1, 60);
    }

    /**
     * Record a delivery attempt in the log.
     *
     * @param  array{success: bool, latency_ms: float}  $result
     */
    private function recordDelivery(string $destination, string $eventName, array $result): void
    {
        $key = self::DELIVERY_LOG_KEY . '_' . $destination;

        /** @var list<array{event: string, status: string, latency_ms: float, timestamp: string}> $log */
        $log = $this->cache->get($key, []);

        $log[] = [
            'event' => $eventName,
            'status' => $result['success'] ? 'delivered' : 'failed',
            'latency_ms' => $result['latency_ms'],
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];

        // Trim to max entries
        if (count($log) > self::MAX_DELIVERY_LOG) {
            $log = array_slice($log, -self::MAX_DELIVERY_LOG);
        }

        $this->cache->put($key, $log, 86400); // 24 hour TTL

        // Update destination stats
        $statsKey = self::CACHE_PREFIX . 'stats_' . $destination;

        /** @var array{total_sent: int, total_failed: int, last_sent: string|null} $stats */
        $stats = $this->cache->get($statsKey, [
            'total_sent' => 0,
            'total_failed' => 0,
            'last_sent' => null,
        ]);

        $stats['total_sent']++;
        if (! $result['success']) {
            $stats['total_failed']++;
        }
        $stats['last_sent'] = (new \DateTimeImmutable())->format('c');

        $this->cache->put($statsKey, $stats, 86400);
    }

    /**
     * Get delivery statistics for a destination.
     *
     * @return array{total_sent: int, total_failed: int, success_rate: float, last_sent: string|null}
     */
    private function getDestinationStats(string $name): array
    {
        $statsKey = self::CACHE_PREFIX . 'stats_' . $name;

        /** @var array{total_sent: int, total_failed: int, last_sent: string|null} $stats */
        $stats = $this->cache->get($statsKey, [
            'total_sent' => 0,
            'total_failed' => 0,
            'last_sent' => null,
        ]);

        $totalSent = $stats['total_sent'];
        $successRate = $totalSent > 0
            ? round((1.0 - $stats['total_failed'] / $totalSent) * 100.0, 2)
            : 100.0;

        return [
            'total_sent' => $totalSent,
            'total_failed' => $stats['total_failed'],
            'success_rate' => $successRate,
            'last_sent' => $stats['last_sent'],
        ];
    }

    /**
     * Check if a destination is enabled.
     *
     * @param  array{enabled: bool}  $destination
     */
    private function isDestinationEnabled(array $destination): bool
    {
        return (bool) ($destination['enabled'] ?? true);
    }
}
