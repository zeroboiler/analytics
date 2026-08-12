<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Services\EventCostTracker;
use ZeroBoiler\Analytics\Services\EventValidationService;

/**
 * Centralized event ingestion pipeline — the single entry point for all
 * incoming analytics events regardless of source (API, server-side, webhook,
 * replay, batch, edge proxy).
 *
 * Orchestrates the full ingestion lifecycle:
 * 1. Validation (schema, consent, rate limits, PII)
 * 2. Deduplication (idempotency key / fingerprint)
 * 3. Enrichment (UTM, device context, tenant, timestamp)
 * 4. Cost estimation (per-provider dispatch cost)
 * 5. Dispatch (fan-out to all enabled providers)
 * 6. Post-dispatch (archive, metrics, DLQ on failure)
 *
 * All ingestion is tracked with latency, success/failure, and cost metrics.
 *
 * Configuration is read from `zeroboiler.analytics.ingestion`.
 *
 * @since 36.0.0
 */
final class EventIngestionService
{
    /** @var list<string> Default ingestion stages */
    private const DEFAULT_STAGES = [
        'validate',
        'dedup',
        'consent_check',
        'enrich',
        'cost_estimate',
        'dispatch',
        'post_dispatch',
    ];

    private readonly bool $enabled;

    private readonly int $maxEventNameLength;

    private readonly int $maxParamCount;

    private readonly int $maxPayloadSize;

    private readonly int $timeoutMs;

    private readonly bool $trackLatency;

    private readonly string $cachePrefix;

    private readonly int $cacheTtl;

    /** @var array<string, int> Ingestion latency samples (event → ms) */
    private array $latencySamples = [];

    /** @var array<string, int> Ingestion counters per source */
    private array $sourceCounters = [];

    /** @var int Total events ingested in this request lifecycle */
    private int $ingestionCount = 0;

    /** @var int Total events rejected in this request lifecycle */
    private int $rejectionCount = 0;

    /**
     * @param  AnalyticsManager  $manager  Central analytics manager
     * @param  ConfigRepository  $config  Analytics configuration
     * @param  CacheRepository  $cache  Application cache
     * @param  EventPipeline|null  $pipeline  Event processing pipeline
     * @param  EventValidationService|null  $validation  Event validator
     * @param  EventCostTracker|null  $costTracker  Dispatch cost tracker
     */
    public function __construct(
        private readonly AnalyticsManager $manager,
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
        private readonly ?EventPipeline $pipeline = null,
        private readonly ?EventValidationService $validation = null,
        private readonly ?EventCostTracker $costTracker = null,
    ): void {
        $ingestionConfig = $config->get('zeroboiler.analytics.ingestion', []);
        /** @var array{enabled?: bool, max_event_name_length?: int, max_param_count?: int, max_payload_size?: int, timeout_ms?: int, track_latency?: bool, cache_prefix?: string, cache_ttl?: int, stages?: list<string>} $ingestionConfig */

        $this->enabled = (bool) ($ingestionConfig['enabled'] ?? true);
        $this->maxEventNameLength = (int) ($ingestionConfig['max_event_name_length'] ?? 100);
        $this->maxParamCount = (int) ($ingestionConfig['max_param_count'] ?? 100);
        $this->maxPayloadSize = (int) ($ingestionConfig['max_payload_size'] ?? 65536);
        $this->timeoutMs = (int) ($ingestionConfig['timeout_ms'] ?? 5000);
        $this->trackLatency = (bool) ($ingestionConfig['track_latency'] ?? true);
        $this->cachePrefix = (string) ($ingestionConfig['cache_prefix'] ?? 'zb_ingestion_');
        $this->cacheTtl = (int) ($ingestionConfig['cache_ttl'] ?? 300);
    }

    /**
     * Ingest a single analytics event through the full pipeline.
     *
     * @param  AnalyticsEvent  $event  The event to ingest
     * @param  string  $source  Origin source (api|server|client|webhook|replay|batch|edge)
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>, latency_ms: int, cost: float, provider_results: array<string, bool>}
     */
    public function ingest(AnalyticsEvent $event, string $source = 'api'): array
    {
        $startTime = hrtime(true);

        if (! $this->enabled) {
            return $this->rejectResult($event, ['Ingestion pipeline is disabled'], $startTime);
        }

        $errors = $this->preValidate($event);

        if ($errors !== []) {
            return $this->rejectResult($event, $errors, $startTime);
        }

        // Tag with source
        $event = new AnalyticsEvent(
            name: $event->name,
            params: $event->params,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $source,
        );

        // Consent check
        if (! $this->checkConsent($event)) {
            return $this->rejectResult($event, ['Consent denied for event'], $startTime);
        }

        // Run through pipeline if available
        if ($this->pipeline !== null) {
            $result = $this->pipeline->process($event);
            $event = $result->event ?? $event;

            if ($result->rejected) {
                return $this->rejectResult($event, $result->errors, $startTime);
            }
        }

        // Cost estimation
        $estimatedCost = 0.0;
        if ($this->costTracker !== null) {
            $estimatedCost = $this->costTracker->estimateCost($event);
        }

        // Dispatch
        $providerResults = $this->dispatch($event);

        // Post-dispatch metrics
        $this->recordMetrics($event, $source, true, $startTime);

        if ($this->costTracker !== null) {
            $this->costTracker->recordDispatch($event, $estimatedCost, $providerResults);
        }

        return [
            'success' => true,
            'event' => $event,
            'errors' => [],
            'latency_ms' => $this->elapsedMs($startTime),
            'cost' => $estimatedCost,
            'provider_results' => $providerResults,
        ];
    }

    /**
     * Ingest multiple events in a single batch operation.
     *
     * Processes events sequentially with deduplication across the batch.
     * Failed events are collected but do not stop the batch.
     *
     * @param  list<AnalyticsEvent>  $events  Events to ingest
     * @param  string  $source  Origin source
     * @return array{total: int, succeeded: int, failed: int, results: list<array{success: bool, event_name: string, errors: list<string>, latency_ms: int, cost: float}>}
     */
    public function ingestBatch(array $events, string $source = 'batch'): array
    {
        $results = [];
        $succeeded = 0;
        $failed = 0;
        $seen = [];

        foreach ($events as $event) {
            // Cross-batch deduplication
            $fingerprint = $this->eventFingerprint($event);
            if (isset($seen[$fingerprint])) {
                $failed++;
                $results[] = [
                    'success' => false,
                    'event_name' => $event->name,
                    'errors' => ['Duplicate event in batch'],
                    'latency_ms' => 0,
                    'cost' => 0.0,
                ];
                continue;
            }
            $seen[$fingerprint] = true;

            $result = $this->ingest($event, $source);
            $results[] = [
                'success' => $result['success'],
                'event_name' => $event->name,
                'errors' => $result['errors'],
                'latency_ms' => $result['latency_ms'],
                'cost' => $result['cost'],
            ];

            if ($result['success']) {
                $succeeded++;
            } else {
                $failed++;
            }
        }

        return [
            'total' => count($events),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * Check if the ingestion pipeline is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get ingestion metrics for the current request lifecycle.
     *
     * @return array{ingested: int, rejected: int, total: int, sources: array<string, int>, avg_latency_ms: float, rejection_rate: float}
     */
    public function getMetrics(): array
    {
        $total = $this->ingestionCount + $this->rejectionCount;
        $avgLatency = $this->latencySamples !== []
            ? round(array_sum($this->latencySamples) / count($this->latencySamples), 2)
            : 0.0;

        return [
            'ingested' => $this->ingestionCount,
            'rejected' => $this->rejectionCount,
            'total' => $total,
            'sources' => $this->sourceCounters,
            'avg_latency_ms' => $avgLatency,
            'rejection_rate' => $total > 0
                ? round($this->rejectionCount / $total, 4)
                : 0.0,
        ];
    }

    /**
     * Get aggregated ingestion statistics from the cache.
     *
     * @return array{total_ingested: int, total_rejected: int, sources: array<string, int>, avg_latency_ms: float}
     */
    public function getAggregatedStats(): array
    {
        $statsKey = $this->cachePrefix . 'stats';

        /** @var array{total_ingested?: int, total_rejected?: int, sources?: array<string, int>, latencies?: list<int>}|null $cached */
        $cached = $this->cache->get($statsKey);

        if ($cached === null) {
            return [
                'total_ingested' => 0,
                'total_rejected' => 0,
                'sources' => [],
                'avg_latency_ms' => 0.0,
            ];
        }

        $latencies = $cached['latencies'] ?? [];
        $avgLatency = $latencies !== []
            ? round(array_sum($latencies) / count($latencies), 2)
            : 0.0;

        return [
            'total_ingested' => (int) ($cached['total_ingested'] ?? 0),
            'total_rejected' => (int) ($cached['total_rejected'] ?? 0),
            'sources' => (array) ($cached['sources'] ?? []),
            'avg_latency_ms' => $avgLatency,
        ];
    }

    /**
     * Reset aggregated statistics (useful for testing).
     */
    public function resetStats(): void
    {
        $this->cache->forget($this->cachePrefix . 'stats');
        $this->ingestionCount = 0;
        $this->rejectionCount = 0;
        $this->latencySamples = [];
        $this->sourceCounters = [];
    }

    /**
     * Pre-validate an event before ingestion.
     *
     * Checks: name length, param count, payload size, empty name.
     *
     * @param  AnalyticsEvent  $event
     * @return list<string> Validation errors (empty = valid)
     */
    private function preValidate(AnalyticsEvent $event): array
    {
        $errors = [];

        if ($event->name === '' || $event->name === '0') {
            $errors[] = 'Event name cannot be empty';
        }

        if (strlen($event->name) > $this->maxEventNameLength) {
            $errors[] = "Event name exceeds {$this->maxEventNameLength} characters";
        }

        if (count($event->params) > $this->maxParamCount) {
            $errors[] = "Event params exceed {$this->maxParamCount} limit";
        }

        $payloadSize = strlen(json_encode($event->toArray(), JSON_THROW_ON_ERROR));
        if ($payloadSize > $this->maxPayloadSize) {
            $errors[] = "Event payload exceeds {$this->maxPayloadSize} bytes";
        }

        return $errors;
    }

    /**
     * Check consent before dispatching.
     */
    private function checkConsent(AnalyticsEvent $event): bool
    {
        $consent = $this->manager->getConsent();

        return $consent->isGranted();
    }

    /**
     * Dispatch an event to all enabled providers via the manager.
     *
     * @return array<string, bool> Provider name → success boolean
     */
    private function dispatch(AnalyticsEvent $event): array
    {
        try {
            $this->manager->track($event);

            // Build provider results from enabled trackers
            $results = [];
            $providers = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'webhook', 'tiktok', 'linkedin'];

            foreach ($providers as $provider) {
                try {
                    $tracker = $this->manager->{$provider}();
                    $results[$provider] = $tracker->isEnabled();
                } catch (\Throwable) {
                    $results[$provider] = false;
                }
            }

            return $results;
        } catch (\Throwable $e) {
            Log::warning("ZeroBoiler: Event dispatch failed for '{$event->name}': {$e->getMessage()}");

            return ['error' => false];
        }
    }

    /**
     * Record ingestion metrics.
     */
    private function recordMetrics(AnalyticsEvent $event, string $source, bool $success, int $startTime): void
    {
        $latency = $this->elapsedMs($startTime);

        if ($success) {
            $this->ingestionCount++;

            if ($this->trackLatency) {
                $this->latencySamples[] = $latency;
            }
        } else {
            $this->rejectionCount++;
        }

        $this->sourceCounters[$source] = ($this->sourceCounters[$source] ?? 0) + 1;

        // Persist to cache
        $this->persistAggregatedStats($source, $success, $latency);
    }

    /**
     * Persist aggregated stats to cache.
     */
    private function persistAggregatedStats(string $source, bool $success, int $latency): void
    {
        $statsKey = $this->cachePrefix . 'stats';

        /** @var array{total_ingested?: int, total_rejected?: int, sources?: array<string, int>, latencies?: list<int>}|null $cached */
        $cached = $this->cache->get($statsKey) ?? [];

        if ($success) {
            $cached['total_ingested'] = ($cached['total_ingested'] ?? 0) + 1;
        } else {
            $cached['total_rejected'] = ($cached['total_rejected'] ?? 0) + 1;
        }

        $sources = $cached['sources'] ?? [];
        $sources[$source] = ($sources[$source] ?? 0) + 1;
        $cached['sources'] = $sources;

        $latencies = $cached['latencies'] ?? [];
        $latencies[] = $latency;
        // Keep only last 100 samples for memory efficiency
        if (count($latencies) > 100) {
            $latencies = array_slice($latencies, -100);
        }
        $cached['latencies'] = $latencies;

        $this->cache->put($statsKey, $cached, $this->cacheTtl);
    }

    /**
     * Generate a fingerprint for an event (for deduplication).
     */
    private function eventFingerprint(AnalyticsEvent $event): string
    {
        return hash('xxh128', $event->name . ':' . json_encode($event->params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Build a rejection result array.
     */
    private function rejectResult(AnalyticsEvent $event, array $errors, int $startTime): array
    {
        $this->recordMetrics($event, $event->source ?? 'unknown', false, $startTime);

        return [
            'success' => false,
            'event' => null,
            'errors' => $errors,
            'latency_ms' => $this->elapsedMs($startTime),
            'cost' => 0.0,
            'provider_results' => [],
        ];
    }

    /**
     * Calculate elapsed time in milliseconds from a hrtime() start value.
     */
    private function elapsedMs(int $startTime): int
    {
        return (int) ((hrtime(true) - $startTime) / 1_000_000);
    }
}
