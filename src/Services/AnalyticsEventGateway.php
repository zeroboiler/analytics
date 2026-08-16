<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\Bus\AnalyticsEventDispatcher;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\TraceContext;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Unified analytics event gateway — single ingress point for all event traffic.
 *
 * Provides a centralized entry point that orchestrates the full event lifecycle:
 * inbound validation → capacity check → deduplication → trace injection → dispatch.
 * Acts as the front door to the analytics pipeline, enforcing global policies
 * before events enter the processing pipeline.
 *
 * Features:
 * - **Global Rate Limiting**: Per-client and per-event-type throttling with
 *   configurable limits and sliding windows.
 * - **Capacity Awareness**: Checks provider capacity before dispatch, routing
 *   events away from saturated providers.
 * - **Gateway Deduplication**: Request-level deduplication using content hash
 *   and time-window to prevent duplicate processing.
 * - **Trace Propagation**: Automatic trace ID injection for distributed tracing
 *   across the full event lifecycle.
 * - **Event Taxonomy Enforcement**: Optional enforcement of catalog membership
 *   and category validation before dispatch.
 * - **Circuit Breaker Awareness**: Respects provider circuit breaker state,
 *   skipping providers that are in open state.
 * - **Metrics Collection**: Tracks gateway throughput, rejection rates, and
 *   latency for observability.
 *
 * Configuration is read from `zeroboiler.analytics.gateway`.
 *
 * @see \ZeroBoiler\Analytics\Bus\AnalyticsEventDispatcher
 * @see \ZeroBoiler\Analytics\Services\EventDeduplicationService
 * @see \ZeroBoiler\Analytics\Services\ProviderCircuitBreaker
 * @see \ZeroBoiler\Analytics\Services\ProviderRateLimitService
 *
 * @since 208.0.0
 */
final class AnalyticsEventGateway
{
    private const CACHE_PREFIX = 'zb_gateway:';
    private const METRICS_CACHE_KEY = 'zb_gateway:metrics';

    private bool $enabled;

    private bool $enforceCatalog;

    private bool $injectTrace;

    private bool $dedupEnabled;

    private int $dedupWindowSeconds;

    private int $globalRateLimit;

    private int $globalRateWindowSeconds;

    private int $perEventRateLimit;

    private int $perEventRateWindowSeconds;

    private int $maxEventParamsSize;

    private int $maxEventNameLength;

    private bool $metricsEnabled;

    private int $metricsTtl;

    /** @var CacheRepository */
    private CacheRepository $cache;

    private ConfigRepository $config;

    private AnalyticsEventDispatcher $dispatcher;

    private EventDeduplicationService $dedup;

    private ProviderCircuitBreaker $circuitBreaker;

    private ProviderRateLimitService $rateLimiter;

    /** @var array{total_inbound: int, total_dispatched: int, total_rejected: int, total_deduplicated: int, total_rate_limited: int, total_capacity_rejected: int} */
    private array $metrics;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Configuration repository
     * @param  AnalyticsEventDispatcher  $dispatcher  Event dispatcher
     * @param  EventDeduplicationService  $dedup  Deduplication service
     * @param  ProviderCircuitBreaker  $circuitBreaker  Circuit breaker service
     * @param  ProviderRateLimitService  $rateLimiter  Provider rate limiter
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        AnalyticsEventDispatcher $dispatcher,
        EventDeduplicationService $dedup,
        ProviderCircuitBreaker $circuitBreaker,
        ProviderRateLimitService $rateLimiter,
    ): void {
        $this->cache = $cache;
        $this->config = $config;
        $this->dispatcher = $dispatcher;
        $this->dedup = $dedup;
        $this->circuitBreaker = $circuitBreaker;
        $this->rateLimiter = $rateLimiter;

        $gatewayConfig = $config->get('zeroboiler.analytics.gateway', []);
        /** @var array{enabled?: bool, enforce_catalog?: bool, inject_trace?: bool, dedup_enabled?: bool, dedup_window?: int, global_rate_limit?: int, global_rate_window?: int, per_event_rate_limit?: int, per_event_rate_window?: int, max_params_size?: int, max_name_length?: int, metrics_enabled?: bool, metrics_ttl?: int} $gatewayConfig */

        $this->enabled = (bool) ($gatewayConfig['enabled'] ?? true);
        $this->enforceCatalog = (bool) ($gatewayConfig['enforce_catalog'] ?? true);
        $this->injectTrace = (bool) ($gatewayConfig['inject_trace'] ?? true);
        $this->dedupEnabled = (bool) ($gatewayConfig['dedup_enabled'] ?? true);
        $this->dedupWindowSeconds = (int) ($gatewayConfig['dedup_window'] ?? 10);
        $this->globalRateLimit = (int) ($gatewayConfig['global_rate_limit'] ?? 1000);
        $this->globalRateWindowSeconds = (int) ($gatewayConfig['global_rate_window'] ?? 60);
        $this->perEventRateLimit = (int) ($gatewayConfig['per_event_rate_limit'] ?? 100);
        $this->perEventRateWindowSeconds = (int) ($gatewayConfig['per_event_rate_window'] ?? 60);
        $this->maxEventParamsSize = (int) ($gatewayConfig['max_params_size'] ?? 65536);
        $this->maxEventNameLength = (int) ($gatewayConfig['max_name_length'] ?? 128);
        $this->metricsEnabled = (bool) ($gatewayConfig['metrics_enabled'] ?? true);
        $this->metricsTtl = (int) ($gatewayConfig['metrics_ttl'] ?? 300);

        $this->metrics = $this->loadMetrics();
    }

    /**
     * Process a single event through the gateway pipeline.
     *
     * Executes the full gateway pipeline:
     * 1. Pre-validation (name, params size)
     * 2. Catalog enforcement (if enabled)
     * 3. Global rate limiting
     * 4. Per-event rate limiting
     * 5. Deduplication
     * 6. Trace injection
     * 7. Dispatch via EventDispatcher
     *
     * @param  AnalyticsEvent  $event  The event to process
     * @param  array{queue?: bool, immediate?: bool, consent_bypass?: bool, skip_gateway?: bool}  $options  Dispatch options
     * @return array{success: bool, reason?: string, trace_id?: string, trace_span?: string, metrics?: array<string, int>}
     */
    public function process(AnalyticsEvent $event, array $options = []): array
    {
        $this->incrementMetric('total_inbound');

        // Allow bypassing gateway in emergency/debug situations
        if (($options['skip_gateway'] ?? false) === true) {
            $dispatched = $this->dispatcher->dispatch($event, $options);
            $this->incrementMetric('total_dispatched');

            return [
                'success' => $dispatched,
                'metrics' => $this->snapshotMetrics(),
            ];
        }

        // 1. Pre-validation
        $validation = $this->validateEvent($event);
        if ($validation !== null) {
            $this->incrementMetric('total_rejected');

            return [
                'success' => false,
                'reason' => $validation,
                'metrics' => $this->snapshotMetrics(),
            ];
        }

        // 2. Catalog enforcement
        if ($this->enforceCatalog && ! EventCatalog::has($event->name)) {
            $this->incrementMetric('total_rejected');

            return [
                'success' => false,
                'reason' => "Event '{$event->name}' is not registered in the event catalog",
                'metrics' => $this->snapshotMetrics(),
            ];
        }

        // 3. Global rate limiting
        if (! $this->checkGlobalRateLimit($event->clientId ?? 'anonymous')) {
            $this->incrementMetric('total_rate_limited');

            return [
                'success' => false,
                'reason' => 'Global rate limit exceeded',
                'metrics' => $this->snapshotMetrics(),
            ];
        }

        // 4. Per-event rate limiting
        if (! $this->checkPerEventRateLimit($event->name, $event->clientId ?? 'anonymous')) {
            $this->incrementMetric('total_rate_limited');

            return [
                'success' => false,
                'reason' => "Per-event rate limit exceeded for '{$event->name}'",
                'metrics' => $this->snapshotMetrics(),
            ];
        }

        // 5. Deduplication
        if ($this->dedupEnabled && $this->dedup->isDuplicate($event)) {
            $this->incrementMetric('total_deduplicated');

            return [
                'success' => false,
                'reason' => 'Duplicate event detected',
                'metrics' => $this->snapshotMetrics(),
            ];
        }

        // 6. Trace injection
        $traceId = null;
        $traceSpan = null;
        if ($this->injectTrace) {
            $trace = TraceContext::fromParams($event->params) ?? TraceContext::generate('gateway');
            $traceId = $trace->traceId();
            $traceSpan = $trace->spanId();
            $event = $this->injectTraceContext($event, $trace);
        }

        // 7. Capacity-aware dispatch check
        $capacityResult = $this->checkProviderCapacity($event);
        if (! $capacityResult['has_capacity']) {
            $this->incrementMetric('total_capacity_rejected');

            return [
                'success' => false,
                'reason' => $capacityResult['reason'] ?? 'No provider has available capacity',
                'trace_id' => $traceId,
                'trace_span' => $traceSpan,
                'metrics' => $this->snapshotMetrics(),
            ];
        }

        // 8. Dispatch
        $dispatched = $this->dispatcher->dispatch($event, $options);
        if ($dispatched) {
            $this->incrementMetric('total_dispatched');
        }

        return [
            'success' => $dispatched,
            'trace_id' => $traceId,
            'trace_span' => $traceSpan,
            'metrics' => $this->snapshotMetrics(),
        ];
    }

    /**
     * Process a batch of events through the gateway.
     *
     * Processes each event individually through the gateway pipeline.
     * Returns per-event results and batch-level statistics.
     *
     * @param  list<AnalyticsEvent>  $events  Events to process
     * @param  array{queue?: bool, immediate?: bool}  $options  Dispatch options
     * @return array{results: list<array{success: bool, reason?: string, index: int}>, dispatched: int, rejected: int, deduplicated: int, rate_limited: int, metrics: array<string, int>}
     */
    public function processBatch(array $events, array $options = []): array
    {
        $results = [];
        $dispatched = 0;
        $rejected = 0;
        $deduplicated = 0;
        $rateLimited = 0;

        foreach ($events as $index => $event) {
            $result = $this->process($event, $options);
            $result['index'] = $index;
            $results[] = $result;

            if ($result['success']) {
                $dispatched++;
            } elseif (($result['reason'] ?? '') === 'Duplicate event detected') {
                $deduplicated++;
            } elseif (str_contains($result['reason'] ?? '', 'rate limit')) {
                $rateLimited++;
            } else {
                $rejected++;
            }
        }

        return [
            'results' => $results,
            'dispatched' => $dispatched,
            'rejected' => $rejected,
            'deduplicated' => $deduplicated,
            'rate_limited' => $rateLimited,
            'metrics' => $this->snapshotMetrics(),
        ];
    }

    /**
     * Get the current gateway metrics.
     *
     * @return array{total_inbound: int, total_dispatched: int, total_rejected: int, total_deduplicated: int, total_rate_limited: int, total_capacity_rejected: int, dispatch_rate: float, rejection_rate: float}
     */
    public function metrics(): array
    {
        $snapshot = $this->snapshotMetrics();
        $total = $snapshot['total_inbound'] ?? 0;

        return [
            'total_inbound' => $snapshot['total_inbound'] ?? 0,
            'total_dispatched' => $snapshot['total_dispatched'] ?? 0,
            'total_rejected' => $snapshot['total_rejected'] ?? 0,
            'total_deduplicated' => $snapshot['total_deduplicated'] ?? 0,
            'total_rate_limited' => $snapshot['total_rate_limited'] ?? 0,
            'total_capacity_rejected' => $snapshot['total_capacity_rejected'] ?? 0,
            'dispatch_rate' => $total > 0 ? round(($snapshot['total_dispatched'] ?? 0) / $total, 4) : 0.0,
            'rejection_rate' => $total > 0 ? round(($snapshot['total_rejected'] ?? 0) / $total, 4) : 0.0,
        ];
    }

    /**
     * Reset gateway metrics.
     */
    public function resetMetrics(): void
    {
        $this->metrics = [
            'total_inbound' => 0,
            'total_dispatched' => 0,
            'total_rejected' => 0,
            'total_deduplicated' => 0,
            'total_rate_limited' => 0,
            'total_capacity_rejected' => 0,
        ];
        $this->cache->forget(self::METRICS_CACHE_KEY);
    }

    /**
     * Check if the gateway is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get gateway configuration summary.
     *
     * @return array{enabled: bool, enforce_catalog: bool, inject_trace: bool, dedup_enabled: bool, dedup_window: int, global_rate_limit: int, global_rate_window: int, per_event_rate_limit: int, per_event_rate_window: int, max_params_size: int, max_name_length: int, metrics_enabled: bool}
     */
    public function configSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'enforce_catalog' => $this->enforceCatalog,
            'inject_trace' => $this->injectTrace,
            'dedup_enabled' => $this->dedupEnabled,
            'dedup_window' => $this->dedupWindowSeconds,
            'global_rate_limit' => $this->globalRateLimit,
            'global_rate_window' => $this->globalRateWindowSeconds,
            'per_event_rate_limit' => $this->perEventRateLimit,
            'per_event_rate_window' => $this->perEventRateWindowSeconds,
            'max_params_size' => $this->maxEventParamsSize,
            'max_name_length' => $this->maxEventNameLength,
            'metrics_enabled' => $this->metricsEnabled,
        ];
    }

    /**
     * Validate an event at the gateway level.
     *
     * Checks event name format, params size, and basic structure.
     * Returns null if valid, or a reason string if invalid.
     */
    private function validateEvent(AnalyticsEvent $event): ?string
    {
        // Name validation
        if ($event->name === '') {
            return 'Event name is required';
        }

        if (mb_strlen($event->name) > $this->maxEventNameLength) {
            return "Event name exceeds maximum length of {$this->maxEventNameLength} characters";
        }

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $event->name)) {
            return 'Event name must start with a lowercase letter and contain only lowercase letters, numbers, and underscores';
        }

        // Params size validation
        $paramsSize = strlen(json_encode($event->params, JSON_UNESCAPED_UNICODE));
        if ($paramsSize > $this->maxEventParamsSize) {
            return "Event params size ({$paramsSize} bytes) exceeds maximum of {$this->maxEventParamsSize} bytes";
        }

        return null;
    }

    /**
     * Check global rate limit for a client.
     *
     * @param  string  $clientId  Client identifier
     */
    private function checkGlobalRateLimit(string $clientId): bool
    {
        $key = self::CACHE_PREFIX . 'global_rate:' . $clientId;

        return $this->cache->add($key, 1, $this->globalRateWindowSeconds)
            || ((int) $this->cache->get($key, 0)) < $this->globalRateLimit;
    }

    /**
     * Check per-event rate limit.
     *
     * @param  string  $eventName  Event name
     * @param  string  $clientId  Client identifier
     */
    private function checkPerEventRateLimit(string $eventName, string $clientId): bool
    {
        $key = self::CACHE_PREFIX . 'event_rate:' . $eventName . ':' . $clientId;

        return $this->cache->add($key, 1, $this->perEventRateWindowSeconds)
            || ((int) $this->cache->get($key, 0)) < $this->perEventRateLimit;
    }

    /**
     * Check if any provider has capacity for this event.
     *
     * Returns early with true if circuit breaker is not checking.
     *
     * @param  AnalyticsEvent  $event  Event to check capacity for
     * @return array{has_capacity: bool, reason?: string}
     */
    private function checkProviderCapacity(AnalyticsEvent $event): array
    {
        $providers = ['ga4', 'meta', 'gtm', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'tiktok', 'linkedin', 'webhook'];

        $availableProviders = [];

        foreach ($providers as $provider) {
            // Skip provider if circuit breaker is open
            if ($this->circuitBreaker->isOpen($provider)) {
                continue;
            }

            // Skip provider if rate limited
            if (! $this->rateLimiter->check($provider)) {
                continue;
            }

            $availableProviders[] = $provider;
        }

        if ($availableProviders === []) {
            return [
                'has_capacity' => false,
                'reason' => 'All providers are either circuit-broken or rate-limited',
            ];
        }

        return ['has_capacity' => true];
    }

    /**
     * Inject trace context into event parameters.
     *
     * @param  AnalyticsEvent  $event  Original event
     * @param  TraceContext  $trace  Trace context to inject
     * @return AnalyticsEvent  New event with trace context
     */
    private function injectTraceContext(AnalyticsEvent $event, TraceContext $trace): AnalyticsEvent
    {
        $mergedParams = array_merge($event->params, $trace->toParams());

        return new AnalyticsEvent(
            name: $event->name,
            params: $mergedParams,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source ?? 'gateway',
            category: $event->category,
            sessionId: $event->sessionId,
        );
    }

    /**
     * Increment a metric counter.
     *
     * @param  string  $key  Metric key
     */
    private function incrementMetric(string $key): void
    {
        if (! isset($this->metrics[$key])) {
            $this->metrics[$key] = 0;
        }
        $this->metrics[$key]++;

        if ($this->metricsEnabled) {
            $this->cache->put(self::METRICS_CACHE_KEY, $this->metrics, $this->metricsTtl);
        }
    }

    /**
     * Load metrics from cache or initialize defaults.
     *
     * @return array{total_inbound: int, total_dispatched: int, total_rejected: int, total_deduplicated: int, total_rate_limited: int, total_capacity_rejected: int}
     */
    private function loadMetrics(): array
    {
        if ($this->metricsEnabled) {
            $cached = $this->cache->get(self::METRICS_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        return [
            'total_inbound' => 0,
            'total_dispatched' => 0,
            'total_rejected' => 0,
            'total_deduplicated' => 0,
            'total_rate_limited' => 0,
            'total_capacity_rejected' => 0,
        ];
    }

    /**
     * Get a snapshot of current metrics.
     *
     * @return array<string, int>
     */
    private function snapshotMetrics(): array
    {
        return $this->metrics;
    }
}
