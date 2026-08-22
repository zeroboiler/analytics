<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event debug capture and replay service.
 *
 * Captures analytics events for debugging purposes, providing a
 * studio-like experience for inspecting, replaying, and simulating
 * events during development and troubleshooting.
 *
 * Features:
 * - **Capture**: Store dispatched events with full context (params, provider results, timing)
 * - **Inspect**: Retrieve captured events with filter/search capabilities
 * - **Replay**: Re-dispatch a previously captured event through the pipeline
 * - **Simulate**: Create and dispatch synthetic events for testing
 * - **Validate**: Check event structure against schema before dispatch
 *
 * Events are stored in cache with configurable TTL and max count.
 * In production, capture should be disabled to avoid performance impact.
 *
 * Configuration: `zeroboiler.analytics.debug_capture`
 *
 * @see \ZeroBoiler\Analytics\Services\EventValidationService
 * @see \ZeroBoiler\Analytics\AnalyticsManager
 *
 * @since 29.0.0
 */
final class EventDebugCaptureService
{
    private const CACHE_PREFIX = 'zb_debug_';
    private const INDEX_KEY = 'zb_debug_index';
    private const DEFAULT_TTL = 3600; // 1 hour
    private const MAX_CAPTURED_EVENTS = 500;
    private const MAX_REPLAY_SIZE = 100;

    private CacheRepository $cache;

    private ConfigRepository $config;

    private string $cachePrefix;

    private int $captureTtl;

    private int $maxEvents;

    private bool $enabled;

    private bool $debug;

    /** @var list<callable(AnalyticsEvent, array<string, mixed>): void> */
    private array $observers = [];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->cache = $cache;
        $this->config = $config;

        $debugConfig = $config->get('zeroboiler.analytics.debug_capture', []);
        /** @var array{enabled?: bool, debug?: bool, cache_prefix?: string, capture_ttl?: int, max_events?: int} $debugConfig */

        $this->cachePrefix = (string) ($debugConfig['cache_prefix'] ?? self::CACHE_PREFIX);
        $this->captureTtl = (int) ($debugConfig['capture_ttl'] ?? self::DEFAULT_TTL);
        $this->maxEvents = (int) ($debugConfig['max_events'] ?? self::MAX_CAPTURED_EVENTS);
        $this->enabled = (bool) ($debugConfig['enabled'] ?? false);
        $this->debug = (bool) ($debugConfig['debug'] ?? false);
    }

    /**
     * Capture an analytics event with its dispatch context.
     *
     * Stores the event data, provider results, timing, and metadata
     * for later inspection or replay.
     *
     * @param  AnalyticsEvent  $event  The dispatched event
     * @param  array<string, mixed>  $context  Dispatch context (provider results, timing, etc.)
     * @return string|null Capture ID (null if capture is disabled)
     */
    public function capture(AnalyticsEvent $event, array $context = []): ?string
    {
        if (! $this->enabled) {
            return null;
        }

        $captureId = $this->generateCaptureId($event);

        $capture = [
            'id' => $captureId,
            'event_name' => $event->name,
            'params' => $event->params,
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
            'timestamp' => $event->timestamp?->format('c') ?? date('c'),
            'priority' => $event->priority,
            'source' => $event->source,
            'context' => $context,
            'captured_at' => date('c'),
            'capture_version' => '30.0.0',
        ];

        $this->cache->put(
            $this->cachePrefix . $captureId,
            $capture,
            $this->captureTtl,
        );

        $this->addToIndex($captureId);

        // Notify observers
        foreach ($this->observers as $observer) {
            try {
                $observer($event, $capture);
            } catch (\Throwable $e) {
                // Observer failures should not break capture
            }
        }

        if ($this->debug) {
            Log::debug('EventDebug: captured', [
                'capture_id' => $captureId,
                'event' => $event->name,
                'params_count' => count($event->params),
            ]);
        }

        return $captureId;
    }

    /**
     * Get a captured event by ID.
     *
     * @param  string  $captureId
     * @return array<string, mixed>|null Captured event data or null
     */
    public function getCapture(string $captureId): ?array
    {
        /** @var array<string, mixed>|null $capture */
        $capture = $this->cache->get($this->cachePrefix . $captureId);

        return is_array($capture) ? $capture : null;
    }

    /**
     * Get all captured events, optionally filtered.
     *
     * @param  array{name?: string|null, client_id?: string|null, user_id?: string|null, source?: string|null, limit?: int, offset?: int}  $filters
     * @return array{events: list<array<string, mixed>>, total: int, filters: array<string, mixed>}
     */
    public function getCapturedEvents(array $filters = []): array
    {
        if (! $this->enabled) {
            return ['events' => [], 'total' => 0, 'filters' => $filters];
        }

        $index = $this->getIndex();
        $limit = min((int) ($filters['limit'] ?? 50), $this->maxEvents);
        $offset = max((int) ($filters['offset'] ?? 0), 0);

        $events = [];
        $filteredIndex = [];

        foreach ($index as $captureId) {
            $capture = $this->cache->get($this->cachePrefix . $captureId);
            if (! is_array($capture)) {
                continue;
            }

            $matches = true;

            if (isset($filters['name']) && $filters['name'] !== null) {
                if (! str_contains($capture['event_name'] ?? '', $filters['name'])) {
                    $matches = false;
                }
            }

            if (isset($filters['client_id']) && $filters['client_id'] !== null) {
                if (($capture['client_id'] ?? null) !== $filters['client_id']) {
                    $matches = false;
                }
            }

            if (isset($filters['user_id']) && $filters['user_id'] !== null) {
                if (($capture['user_id'] ?? null) !== $filters['user_id']) {
                    $matches = false;
                }
            }

            if (isset($filters['source']) && $filters['source'] !== null) {
                if (($capture['source'] ?? null) !== $filters['source']) {
                    $matches = false;
                }
            }

            if ($matches) {
                $filteredIndex[] = $capture;
            }
        }

        $total = count($filteredIndex);
        $paged = array_slice($filteredIndex, $offset, $limit);

        return [
            'events' => $paged,
            'total' => $total,
            'filters' => $filters,
        ];
    }

    /**
     * Replay a captured event.
     *
     * Reconstructs an AnalyticsEvent from the captured data and returns it
     * for re-dispatch through AnalyticsManager.
     *
     * @param  string  $captureId
     * @return AnalyticsEvent|null Reconstructed event or null if not found
     */
    public function replay(string $captureId): ?AnalyticsEvent
    {
        $capture = $this->getCapture($captureId);

        if ($capture === null) {
            return null;
        }

        $timestamp = isset($capture['timestamp']) && is_string($capture['timestamp'])
            ? \DateTimeImmutable::createFromFormat('c', $capture['timestamp']) ?: null
            : null;

        $event = new AnalyticsEvent(
            name: (string) ($capture['event_name'] ?? ''),
            params: is_array($capture['params'] ?? null) ? $capture['params'] : [],
            clientId: isset($capture['client_id']) && is_string($capture['client_id']) ? $capture['client_id'] : null,
            userId: isset($capture['user_id']) && is_string($capture['user_id']) ? $capture['user_id'] : null,
            timestamp: $timestamp,
            priority: isset($capture['priority']) && is_string($capture['priority']) ? $capture['priority'] : null,
            source: 'replay',
        );

        Log::info('EventDebug: replaying event', [
            'capture_id' => $captureId,
            'event' => $capture['event_name'] ?? 'unknown',
        ]);

        return $event;
    }

    /**
     * Create a synthetic event for simulation/testing.
     *
     * @param  string  $name  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $clientId  Optional client ID
     * @param  string|null  $userId  Optional user ID
     * @return AnalyticsEvent
     */
    public function simulate(
        string $name,
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
    ): AnalyticsEvent {
        $event = new AnalyticsEvent(
            name: $name,
            params: $params,
            clientId: $clientId,
            userId: $userId,
            timestamp: new \DateTimeImmutable(),
            priority: null,
            source: 'simulation',
        );

        if ($this->debug) {
            Log::debug('EventDebug: simulated event', [
                'event' => $name,
                'params_count' => count($params),
            ]);
        }

        return $event;
    }

    /**
     * Batch replay multiple captured events.
     *
     * @param  list<string>  $captureIds
     * @return array{replayed: int, failed: int, events: list<AnalyticsEvent>}
     */
    public function replayBatch(array $captureIds): array
    {
        $ids = array_slice($captureIds, 0, self::MAX_REPLAY_SIZE);
        $events = [];
        $failed = 0;

        foreach ($ids as $captureId) {
            $event = $this->replay($captureId);
            if ($event !== null) {
                $events[] = $event;
            } else {
                $failed++;
            }
        }

        return [
            'replayed' => count($events),
            'failed' => $failed,
            'events' => $events,
        ];
    }

    /**
     * Clear all captured events.
     *
     * @return int Number of events cleared
     */
    public function clear(): int
    {
        $index = $this->getIndex();
        $count = count($index);

        foreach ($index as $captureId) {
            $this->cache->forget($this->cachePrefix . $captureId);
        }

        $this->cache->forget(self::INDEX_KEY);

        Log::info('EventDebug: cleared all captured events', ['count' => $count]);

        return $count;
    }

    /**
     * Register an observer callback for captured events.
     *
     * Observers are called synchronously when an event is captured.
     * Use for real-time debugging, logging, or external integrations.
     *
     * @param  callable(AnalyticsEvent $event, array<string, mixed> $capture): void  $observer
     */
    public function registerObserver(callable $observer): void
    {
        $this->observers[] = $observer;
    }

    /**
     * Check if capture is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get debug capture statistics.
     *
     * @return array{enabled: bool, captured_count: int, max_events: int, capture_ttl: int, observers: int}
     */
    public function stats(): array
    {
        return [
            'enabled' => $this->enabled,
            'captured_count' => count($this->getIndex()),
            'max_events' => $this->maxEvents,
            'capture_ttl' => $this->captureTtl,
            'observers' => count($this->observers),
        ];
    }

    /**
     * Generate a unique capture ID for an event.
     *
     * @param  AnalyticsEvent  $event
     * @return string
     */
    private function generateCaptureId(AnalyticsEvent $event): string
    {
        $hash = substr(md5($event->name . json_encode($event->params, JSON_THROW_ON_ERROR) . microtime(true)), 0, 12);

        return 'cap_' . date('Ymd_His') . '_' . $hash;
    }

    /**
     * Get the capture index (list of capture IDs).
     *
     * @return list<string>
     */
    private function getIndex(): array
    {
        /** @var list<string>|null $index */
        $index = $this->cache->get(self::INDEX_KEY);

        return is_array($index) ? $index : [];
    }

    /**
     * Add a capture ID to the index.
     *
     * @param  string  $captureId
     */
    private function addToIndex(string $captureId): void
    {
        $index = $this->getIndex();
        $index[] = $captureId;

        // Enforce max events
        if (count($index) > $this->maxEvents) {
            $removed = array_splice($index, 0, count($index) - $this->maxEvents);
            foreach ($removed as $oldId) {
                $this->cache->forget($this->cachePrefix . $oldId);
            }
        }

        $this->cache->put(self::INDEX_KEY, $index, $this->captureTtl);
    }
}
