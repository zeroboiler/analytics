<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\CDP;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * CDP Computed Trait Engine — calculates user traits from event history.
 *
 * Processes analytics events and computes aggregate user properties:
 * - **sum**: Total revenue, total page views
 * - **count**: Session count, error count
 * - **avg**: Average order value, average session duration
 * - **max**: Highest purchase amount
 * - **min**: Lowest purchase amount
 * - **latest**: Last plan name, last device used
 * - **unique_count**: Number of unique features used, unique devices
 *
 * Computed traits are recalculated based on configurable intervals to avoid
 * performance impact. Uses cache-backed event accumulators.
 *
 * @see \ZeroBoiler\Analytics\CDP\CdpProfileService
 * @see \ZeroBoiler\Analytics\CDP\CdpTraitDefinition
 *
 * @since 196.0.0
 */
final class CdpTraitComputer
{
    private const CACHE_PREFIX = 'zb_cdp_traits_';

    private const ACCUMULATOR_PREFIX = 'zb_cdp_accum_';

    /** @var array<string, CdpTraitDefinition> */
    private array $traitDefinitions = [];

    /** @var array<string, int> Last computation timestamp per user+trait */
    private array $lastComputed = [];

    private int $traitTtl;

    private int $accumulatorTtl;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ) {
        $cdpConfig = $config->get('zeroboiler.analytics.cdp', []);
        /** @var array{trait_ttl?: int, accumulator_ttl?: int} $cdpConfig */

        $this->traitTtl = (int) ($cdpConfig['trait_ttl'] ?? 7776000); // 90 days
        $this->accumulatorTtl = (int) ($cdpConfig['accumulator_ttl'] ?? 86400); // 24 hours

        $this->registerDefaultTraits();
    }

    /**
     * Register a trait definition.
     *
     * @param  CdpTraitDefinition  $definition
     * @return void
     */
    public function registerTrait(CdpTraitDefinition $definition): void
    {
        $this->traitDefinitions[$definition->name] = $definition;
    }

    /**
     * Register multiple trait definitions.
     *
     * @param  list<CdpTraitDefinition>  $definitions
     * @return void
     */
    public function registerTraits(array $definitions): void
    {
        foreach ($definitions as $definition) {
            $this->registerTrait($definition);
        }
    }

    /**
     * Get all registered trait definitions.
     *
     * @return array<string, CdpTraitDefinition>
     */
    public function getTraitDefinitions(): array
    {
        return $this->traitDefinitions;
    }

    /**
     * Process an event and update accumulators for computed traits.
     *
     * Only computed traits whose source event matches the incoming event
     * will have their accumulators updated.
     *
     * @param  AnalyticsEvent  $event  The analytics event
     * @param  string  $userId  The user ID to attribute the event to
     * @return list<string>  Names of traits that were updated
     */
    public function processEvent(AnalyticsEvent $event, string $userId): array
    {
        $updatedTraits = [];
        $eventName = $event->name;

        foreach ($this->traitDefinitions as $name => $definition) {
            if (! $definition->computed) {
                continue;
            }

            if ($definition->sourceEvent !== $eventName) {
                continue;
            }

            // Check recalculation interval
            $cacheKey = self::ACCUMULATOR_PREFIX . $userId . '_' . $name;
            $accumulator = $this->getAccumulator($userId, $name);

            $this->updateAccumulator($accumulator, $definition, $event);
            $this->putAccumulator($userId, $name, $accumulator);

            // Recalculate if interval allows
            $lastComputed = $this->lastComputed[$userId . '_' . $name] ?? 0;
            $canRecompute = $definition->recalculateIntervalSeconds === 0
                || (time() - $lastComputed) >= $definition->recalculateIntervalSeconds;

            if ($canRecompute) {
                $this->computeAndCacheTrait($userId, $name, $definition, $accumulator);
                $this->lastComputed[$userId . '_' . $name] = time();
                $updatedTraits[] = $name;
            }
        }

        return $updatedTraits;
    }

    /**
     * Compute the current value of a single trait for a user.
     *
     * If the cached value is stale or missing, recalculates from accumulators.
     *
     * @param  string  $userId
     * @param  string  $traitName
     * @return mixed  The computed trait value
     */
    public function computeTrait(string $userId, string $traitName): mixed
    {
        $definition = $this->traitDefinitions[$traitName] ?? null;

        if ($definition === null) {
            return null;
        }

        // Static traits are not computed
        if (! $definition->computed) {
            return null;
        }

        // Try cache first
        $cacheKey = self::CACHE_PREFIX . $userId . '_trait_' . $traitName;
        /** @var mixed $cached */
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        // Recalculate from accumulator
        $accumulator = $this->getAccumulator($userId, $traitName);
        $value = $this->aggregate($accumulator, $definition);

        if ($value !== null) {
            $this->cache->put($cacheKey, $value, $this->traitTtl);
        }

        return $value;
    }

    /**
     * Compute all trait values for a user.
     *
     * @param  string  $userId
     * @return array<string, mixed>  Map of trait name to value
     */
    public function computeAllTraits(string $userId): array
    {
        $traits = [];

        foreach ($this->traitDefinitions as $name => $definition) {
            if ($definition->computed) {
                $value = $this->computeTrait($userId, $name);
                $traits[$name] = $value ?? $definition->defaultValue;
            }
        }

        return $traits;
    }

    /**
     * Get accumulator for a user+trait.
     *
     * @param  string  $userId
     * @param  string  $traitName
     * @return array{sum: float, count: int, values: list<float>, latest: mixed, unique: array<string, bool>}
     */
    private function getAccumulator(string $userId, string $traitName): array
    {
        $cacheKey = self::ACCUMULATOR_PREFIX . $userId . '_' . $traitName;
        /** @var array<string, mixed>|null $accumulator */
        $accumulator = $this->cache->get($cacheKey);

        if (is_array($accumulator)) {
            return [
                'sum' => (float) ($accumulator['sum'] ?? 0.0),
                'count' => (int) ($accumulator['count'] ?? 0),
                'values' => (array) ($accumulator['values'] ?? []),
                'latest' => $accumulator['latest'] ?? null,
                'unique' => (array) ($accumulator['unique'] ?? []),
            ];
        }

        return [
            'sum' => 0.0,
            'count' => 0,
            'values' => [],
            'latest' => null,
            'unique' => [],
        ];
    }

    /**
     * Store accumulator for a user+trait.
     *
     * @param  string  $userId
     * @param  string  $traitName
     * @param  array{sum: float, count: int, values: list<float>, latest: mixed, unique: array<string, bool>}  $accumulator
     * @return void
     */
    private function putAccumulator(string $userId, string $traitName, array $accumulator): void
    {
        $cacheKey = self::ACCUMULATOR_PREFIX . $userId . '_' . $traitName;
        $this->cache->put($cacheKey, $accumulator, $this->accumulatorTtl);
    }

    /**
     * Update accumulator based on event and aggregation method.
     *
     * @param  array{sum: float, count: int, values: list<float>, latest: mixed, unique: array<string, bool>}  $accumulator
     * @param  CdpTraitDefinition  $definition
     * @param  AnalyticsEvent  $event
     * @return void
     */
    private function updateAccumulator(array &$accumulator, CdpTraitDefinition $definition, AnalyticsEvent $event): void
    {
        $field = $definition->sourceField;
        $properties = $event->properties;

        // Get the value to aggregate
        $value = null;
        if ($field !== null && isset($properties[$field])) {
            $value = is_numeric($properties[$field]) ? (float) $properties[$field] : $properties[$field];
        } elseif ($field === null) {
            $value = 1.0; // count events themselves
        }

        if ($value === null) {
            return;
        }

        $accumulator['count']++;

        if (is_numeric($value)) {
            $accumulator['sum'] += (float) $value;
            $accumulator['values'][] = (float) $value;

            // Keep values array bounded
            if (count($accumulator['values']) > 1000) {
                $accumulator['values'] = array_slice($accumulator['values'], -500);
            }
        }

        // Latest value (any type)
        $accumulator['latest'] = $value;

        // Unique tracking (string-based)
        if (! is_numeric($value) || $definition->aggregation === 'unique_count') {
            $uniqueKey = is_string($value) ? $value : (string) $value;
            $accumulator['unique'][$uniqueKey] = true;
        }

        // For unique_count on numeric, also track the string representation
        if ($definition->aggregation === 'unique_count' && is_numeric($value)) {
            $accumulator['unique'][(string) $value] = true;
        }
    }

    /**
     * Perform the aggregation on an accumulator.
     *
     * @param  array{sum: float, count: int, values: list<float>, latest: mixed, unique: array<string, bool>}  $accumulator
     * @param  CdpTraitDefinition  $definition
     * @return mixed
     */
    private function aggregate(array $accumulator, CdpTraitDefinition $definition): mixed
    {
        if ($accumulator['count'] === 0) {
            return $definition->defaultValue;
        }

        $aggregation = $definition->aggregation ?? 'count';

        return match ($aggregation) {
            'sum' => $accumulator['sum'],
            'count' => $accumulator['count'],
            'avg' => round($accumulator['sum'] / $accumulator['count'], 2),
            'max' => $accumulator['values'] !== [] ? (float) max($accumulator['values']) : $definition->defaultValue,
            'min' => $accumulator['values'] !== [] ? (float) min($accumulator['values']) : $definition->defaultValue,
            'latest' => $accumulator['latest'],
            'unique_count' => count($accumulator['unique']),
            default => $accumulator['count'],
        };
    }

    /**
     * Compute trait and cache the result.
     *
     * @param  string  $userId
     * @param  string  $name
     * @param  CdpTraitDefinition  $definition
     * @param  array{sum: float, count: int, values: list<float>, latest: mixed, unique: array<string, bool>}  $accumulator
     * @return void
     */
    private function computeAndCacheTrait(
        string $userId,
        string $name,
        CdpTraitDefinition $definition,
        array $accumulator,
    ): void {
        $value = $this->aggregate($accumulator, $definition);
        $cacheKey = self::CACHE_PREFIX . $userId . '_trait_' . $name;
        $this->cache->put($cacheKey, $value, $this->traitTtl);
    }

    /**
     * Register built-in SaaS computed trait definitions.
     *
     * Provides sensible defaults for common SaaS metrics:
     * - total_revenue (sum of purchase revenue)
     * - purchase_count (count of purchase events)
     * - session_count (count of session_start events)
     * - page_view_count (count of page_view events)
     * - avg_order_value (avg of purchase revenue)
     * - max_purchase (max purchase revenue)
     * - unique_features_used (unique_count of feature_used events)
     * - error_count (count of error events)
     * - form_submit_count (count of form_submit events)
     * - search_count (count of search events)
     *
     * @return void
     */
    private function registerDefaultTraits(): void
    {
        $defaults = [
            // Revenue traits
            CdpTraitDefinition::computed(
                name: 'total_revenue',
                sourceEvent: 'purchase',
                aggregation: 'sum',
                type: 'float',
                sourceField: 'revenue',
                recalculateIntervalSeconds: 300,
                defaultValue: 0.0,
                description: 'Total revenue from all purchases',
            ),
            CdpTraitDefinition::computed(
                name: 'purchase_count',
                sourceEvent: 'purchase',
                aggregation: 'count',
                type: 'int',
                recalculateIntervalSeconds: 300,
                defaultValue: 0,
                description: 'Total number of purchases',
            ),
            CdpTraitDefinition::computed(
                name: 'avg_order_value',
                sourceEvent: 'purchase',
                aggregation: 'avg',
                type: 'float',
                sourceField: 'revenue',
                recalculateIntervalSeconds: 600,
                defaultValue: 0.0,
                description: 'Average purchase value',
            ),
            CdpTraitDefinition::computed(
                name: 'max_purchase',
                sourceEvent: 'purchase',
                aggregation: 'max',
                type: 'float',
                sourceField: 'revenue',
                recalculateIntervalSeconds: 600,
                defaultValue: 0.0,
                description: 'Highest single purchase amount',
            ),

            // Engagement traits
            CdpTraitDefinition::computed(
                name: 'session_count',
                sourceEvent: 'session_start',
                aggregation: 'count',
                type: 'int',
                recalculateIntervalSeconds: 0,
                defaultValue: 0,
                description: 'Total sessions initiated',
            ),
            CdpTraitDefinition::computed(
                name: 'page_view_count',
                sourceEvent: 'page_view',
                aggregation: 'count',
                type: 'int',
                recalculateIntervalSeconds: 0,
                defaultValue: 0,
                description: 'Total page views',
            ),
            CdpTraitDefinition::computed(
                name: 'search_count',
                sourceEvent: 'search',
                aggregation: 'count',
                type: 'int',
                recalculateIntervalSeconds: 0,
                defaultValue: 0,
                description: 'Total searches performed',
            ),
            CdpTraitDefinition::computed(
                name: 'form_submit_count',
                sourceEvent: 'form_submit',
                aggregation: 'count',
                type: 'int',
                recalculateIntervalSeconds: 0,
                defaultValue: 0,
                description: 'Total form submissions',
            ),

            // Error traits
            CdpTraitDefinition::computed(
                name: 'error_count',
                sourceEvent: 'error',
                aggregation: 'count',
                type: 'int',
                recalculateIntervalSeconds: 0,
                defaultValue: 0,
                description: 'Total errors encountered',
            ),

            // Feature adoption traits
            CdpTraitDefinition::computed(
                name: 'unique_features_used',
                sourceEvent: 'feature_used',
                aggregation: 'unique_count',
                type: 'int',
                sourceField: 'feature_name',
                recalculateIntervalSeconds: 0,
                defaultValue: 0,
                description: 'Number of unique features used',
            ),

            // SaaS lifecycle traits
            CdpTraitDefinition::computed(
                name: 'login_count',
                sourceEvent: 'login',
                aggregation: 'count',
                type: 'int',
                recalculateIntervalSeconds: 0,
                defaultValue: 0,
                description: 'Total login events',
            ),
            CdpTraitDefinition::computed(
                name: 'last_plan',
                sourceEvent: 'plan_upgrade',
                aggregation: 'latest',
                type: 'string',
                sourceField: 'plan_name',
                recalculateIntervalSeconds: 0,
                defaultValue: null,
                description: 'Most recent plan name',
            ),
        ];

        foreach ($defaults as $definition) {
            $this->registerTrait($definition);
        }
    }
}
