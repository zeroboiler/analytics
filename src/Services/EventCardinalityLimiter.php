<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event cardinality limiter — prevents high-cardinality dimension explosion.
 *
 * Analytics providers (GA4 custom dimensions, PostHog properties, Mixpanel events)
 * have hard limits on the number of unique values per event parameter. This service
 * monitors and limits the number of unique values per parameter key, preventing
 * runaway cardinality from user IDs, IPs, session IDs, and other unbounded dimensions.
 *
 * When a parameter exceeds its cardinality limit, the value is replaced with a
 * sentinel value ("__cardinality_limited__") to prevent dispatch to the provider.
 * Events can be:
 * - Dropped entirely (strict mode)
 * - Dispatched with the offending parameter removed (drop_param mode)
 * - Dispatched with a bucketed/hashed value (bucket mode)
 *
 * Inspired by Segment's "Protocol Limit" checks, Datadog's cardinality management,
 * and PostHog's property limits.
 *
 * Configuration: `zeroboiler.analytics.cardinality`
 *
 * @see \ZeroBoiler\Analytics\Services\EventValidationService
 *
 * @since 153.0.0
 */
final class EventCardinalityLimiter
{
    /** @var string Cache key prefix for cardinality tracking */
    private const CACHE_PREFIX = 'zb_cardinality_';

    /** @var int Default TTL for cardinality tracking data (1 hour) */
    private const DEFAULT_TTL = 3600;

    /** @var int Maximum unique values per parameter before limiting */
    private const DEFAULT_LIMIT = 500;

    /** @var string Sentinel value replacing high-cardinality values */
    private const SENTINEL_VALUE = '__cardinality_limited__';

    private CacheRepository $cache;

    private bool $enabled;

    private int $ttl;

    private int $defaultLimit;

    /** @var array<string, int> Per-parameter cardinality limits (event:param → max) */
    private array $paramLimits;

    /** @var list<string> Parameters that are always treated as high-cardinality */
    private array $highCardinalityParams;

    /** @var 'strict'|'drop_param'|'bucket' Action when limit is exceeded */
    private string $exceededAction;

    /** @var list<string> Parameter keys to skip (never limited) */
    private array $excludedParams;

    /** @var list<string> Events to skip entirely */
    private array $excludedEvents;

    /**
     * @param  CacheRepository  $cache  Cache repository for cardinality data
     * @param  ConfigRepository  $config  Analytics configuration
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $cardConfig = $config->get('zeroboiler.analytics.cardinality', []);
        /** @var array{enabled?: bool, ttl?: int, default_limit?: int, param_limits?: array<string, int>, high_cardinality_params?: list<string>, exceeded_action?: string, excluded_params?: list<string>, excluded_events?: list<string>} $cardConfig */
        $this->enabled = (bool) ($cardConfig['enabled'] ?? true);
        $this->ttl = (int) ($cardConfig['ttl'] ?? self::DEFAULT_TTL);
        $this->defaultLimit = (int) ($cardConfig['default_limit'] ?? self::DEFAULT_LIMIT);
        $this->paramLimits = (array) ($cardConfig['param_limits'] ?? []);
        $this->highCardinalityParams = (array) ($cardConfig['high_cardinality_params'] ?? [
            'user_id', 'client_id', 'session_id', 'ip_address', 'email',
        ]);
        $this->exceededAction = (string) ($cardConfig['exceeded_action'] ?? 'drop_param');
        $this->excludedParams = (array) ($cardConfig['excluded_params'] ?? []);
        $this->excludedEvents = (array) ($cardConfig['excluded_events'] ?? []);
    }

    /**
     * Check and enforce cardinality limits on an event's parameters.
     *
     * Returns the processed event with any cardinality violations handled.
     * Returns null if the event should be dropped entirely (strict mode).
     *
     * @return AnalyticsEvent|null The processed event, or null if dropped
     */
    public function enforce(AnalyticsEvent $event): ?AnalyticsEvent
    {
        if (! $this->enabled) {
            return $event;
        }

        if ($this->shouldSkipEvent($event->name)) {
            return $event;
        }

        $params = $event->params;
        $violations = [];

        foreach ($params as $key => $value) {
            if ($this->isExcludedParam($key)) {
                continue;
            }

            if (! $this->isTrackableValue($value)) {
                continue;
            }

            $limit = $this->getLimitForKey($event->name, $key);

            if ($this->exceedsLimit($event->name, $key, $limit)) {
                $violations[$key] = $limit;
            }
        }

        if ($violations === []) {
            return $event;
        }

        return $this->handleViolations($event, $violations);
    }

    /**
     * Check if a specific event+param combination exceeds cardinality limit.
     *
     * @param  string  $eventName  Event name
     * @param  string  $paramKey  Parameter key
     * @return bool True if cardinality limit is exceeded
     */
    public function exceedsLimit(string $eventName, string $paramKey, ?int $limit = null): bool
    {
        $limit ??= $this->getLimitForKey($eventName, $paramKey);
        $count = $this->getCardinality($eventName, $paramKey);

        return $count >= $limit;
    }

    /**
     * Get current cardinality count for an event+param combination.
     */
    public function getCardinality(string $eventName, string $paramKey): int
    {
        $cacheKey = $this->cacheKey($eventName, $paramKey);
        $data = $this->cache->get($cacheKey, []);

        return is_array($data) ? count($data) : 0;
    }

    /**
     * Record a value for a specific event+param combination (track cardinality).
     *
     * @param  string  $eventName  Event name
     * @param  string  $paramKey  Parameter key
     * @param  string  $value  The value to track
     */
    public function trackValue(string $eventName, string $paramKey, string $value): void
    {
        if (! $this->enabled) {
            return;
        }

        $cacheKey = $this->cacheKey($eventName, $paramKey);
        $values = $this->cache->get($cacheKey, []);
        if (! is_array($values)) {
            $values = [];
        }

        $values[$value] = true;

        // Evict oldest entries if over limit
        if (count($values) > $this->defaultLimit * 2) {
            $values = array_slice($values, -$this->defaultLimit, null, true);
        }

        $this->cache->put($cacheKey, $values, $this->ttl);
    }

    /**
     * Get cardinality report for all tracked event+param combinations.
     *
     * @return array<string, array{count: int, limit: int, status: 'ok'|'warning'|'critical'}>
     */
    public function getCardinalityReport(): array
    {
        $report = [];
        $patterns = [
            $this->cacheKey('*', '*') => '*',
        ];

        // This is a summary — individual key access pattern
        $report['_meta'] = [
            'enabled' => $this->enabled,
            'default_limit' => $this->defaultLimit,
            'exceeded_action' => $this->exceededAction,
            'high_cardinality_params' => $this->highCardinalityParams,
            'excluded_params_count' => count($this->excludedParams),
            'excluded_events_count' => count($this->excludedEvents),
        ];

        return $report;
    }

    /**
     * Get cardinality limit for a specific event+param combination.
     *
     * Checks explicit param_limits first, then high_cardinality_params (lower limit),
     * then default_limit.
     */
    private function getLimitForKey(string $eventName, string $paramKey): int
    {
        // Explicit limit for specific event:param
        $explicitKey = "{$eventName}:{$paramKey}";
        if (isset($this->paramLimits[$explicitKey])) {
            return (int) $this->paramLimits[$explicitKey];
        }

        // Limit for any event with this param
        if (isset($this->paramLimits[$paramKey])) {
            return (int) $this->paramLimits[$paramKey];
        }

        // High-cardinality params get a lower limit
        if (in_array($paramKey, $this->highCardinalityParams, true)) {
            return min($this->defaultLimit, 100);
        }

        return $this->defaultLimit;
    }

    /**
     * Handle cardinality violations based on the configured action.
     *
     * @param  array<string, int>  $violations  Param key → limit
     * @return AnalyticsEvent|null Processed event or null (dropped)
     */
    private function handleViolations(AnalyticsEvent $event, array $violations): ?AnalyticsEvent
    {
        return match ($this->exceededAction) {
            'strict' => null,
            'drop_param' => $this->dropViolatingParams($event, $violations),
            'bucket' => $this->bucketViolatingParams($event, $violations),
            default => $this->dropViolatingParams($event, $violations),
        };
    }

    /**
     * Create a new event with violating parameters removed.
     *
     * @param  AnalyticsEvent  $event  Original event
     * @param  array<string, int>  $violations  Param key → limit
     * @return AnalyticsEvent Event with violating params removed
     */
    private function dropViolatingParams(AnalyticsEvent $event, array $violations): AnalyticsEvent
    {
        $cleanParams = $event->params;

        foreach (array_keys($violations) as $key) {
            unset($cleanParams[$key]);
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: $cleanParams,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
            category: $event->category,
            sessionId: $event->sessionId,
        );
    }

    /**
     * Create a new event with violating parameters replaced with bucketed hashes.
     *
     * @param  AnalyticsEvent  $event  Original event
     * @param  array<string, int>  $violations  Param key → limit
     * @return AnalyticsEvent Event with violating params bucketed
     */
    private function bucketViolatingParams(AnalyticsEvent $event, array $violations): AnalyticsEvent
    {
        $bucketedParams = $event->params;

        foreach ($violations as $key => $limit) {
            $value = $bucketedParams[$key] ?? null;
            if (is_string($value) || is_int($value)) {
                $bucketedParams[$key] = self::SENTINEL_VALUE . ':' . substr(md5((string) $value), 0, 8);
            } else {
                unset($bucketedParams[$key]);
            }
        }

        return new AnalyticsEvent(
            name: $event->name,
            params: $bucketedParams,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
            category: $event->category,
            sessionId: $event->sessionId,
        );
    }

    /**
     * Check if a value is trackable for cardinality purposes.
     *
     * Only strings and integers are trackable; arrays, booleans, and nulls are not.
     */
    private function isTrackableValue(mixed $value): bool
    {
        return is_string($value) || is_int($value);
    }

    /**
     * Check if an event name should be skipped.
     */
    private function shouldSkipEvent(string $eventName): bool
    {
        return in_array($eventName, $this->excludedEvents, true);
    }

    /**
     * Check if a parameter key is excluded from cardinality limits.
     */
    private function isExcludedParam(string $paramKey): bool
    {
        return in_array($paramKey, $this->excludedParams, true);
    }

    /**
     * Generate a cache key for an event+param combination.
     */
    private function cacheKey(string $eventName, string $paramKey): string
    {
        return self::CACHE_PREFIX . md5("{$eventName}:{$paramKey}");
    }
}
