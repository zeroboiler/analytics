<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event Value Attribution Engine — assigns monetary value to non-revenue events.
 *
 * Every event in the analytics pipeline contributes to revenue outcomes, even
 * indirect events like page_view, scroll_depth, or search. This service uses
 * a position-based attribution model to assign fractional monetary value to
 * each event based on its position in the conversion funnel.
 *
 * **How it works:**
 * 1. Define a "value budget" (typically ARPU or LTV) for conversion events
 * 2. Walk backward through the conversion funnel assigning fractional credit
 * 3. Events closer to conversion receive higher attribution
 * 4. Events in multiple funnel paths accumulate value from all paths
 *
 * **Use cases:**
 * - Identify which non-revenue events drive the most revenue
 * - Prioritize feature development based on event revenue impact
 * - Calculate ROI of engagement features (search, scroll, form fills)
 * - Build "event economics" dashboards for product teams
 *
 * Configuration: `zeroboiler.analytics.event_value_attribution`
 *
 * Inspired by Segment's Event Value Attribution and Amplitude's Pathfinder.
 *
 * @see \ZeroBoiler\Analytics\Services\AttributionModelService
 * @see \ZeroBoiler\Analytics\Services\EventImpactService
 *
 * @since 175.0.0
 */
final class EventValueAttributionService
{
    private const CACHE_PREFIX = 'zb_evt_value_';

    private const DEFAULT_CACHE_TTL = 3600; // 1 hour

    /**
     * Default funnel paths from top-of-funnel to conversion.
     *
     * Each path is a sequence of events ending in a conversion event.
     * The position in the path determines the attribution weight.
     *
     * @var array<string, array{path: list<string>, conversion_event: string, value_budget: float}>
     */
    private const DEFAULT_FUNNEL_PATHS = [
        'signup' => [
            'path' => ['page_view', 'scroll_depth', 'click', 'form_start', 'form_submit'],
            'conversion_event' => 'sign_up',
            'value_budget' => 50.0,
        ],
        'trial' => [
            'path' => ['page_view', 'click', 'sign_up', 'feature_used', 'start_trial'],
            'conversion_event' => 'start_trial',
            'value_budget' => 150.0,
        ],
        'purchase' => [
            'path' => ['page_view', 'view_item', 'add_to_cart', 'view_cart', 'begin_checkout', 'purchase'],
            'conversion_event' => 'purchase',
            'value_budget' => 99.0,
        ],
        'subscription' => [
            'path' => ['page_view', 'click', 'sign_up', 'start_trial', 'feature_used', 'subscribe'],
            'conversion_event' => 'subscribe',
            'value_budget' => 500.0,
        ],
        'plan_upgrade' => [
            'path' => ['feature_used', 'usage_quota_reached', 'plan_upgrade'],
            'conversion_event' => 'plan_upgrade',
            'value_budget' => 200.0,
        ],
        'engagement_retention' => [
            'path' => ['page_view', 'search', 'click', 'feature_used'],
            'conversion_event' => 'feature_used',
            'value_budget' => 30.0,
        ],
    ];

    /**
     * Default per-event base values when not computed from funnel position.
     *
     * Used as fallback for events that don't appear in any funnel path.
     *
     * @var array<string, float>
     */
    private const DEFAULT_BASE_VALUES = [
        'page_view' => 0.05,
        'scroll_depth' => 0.02,
        'click' => 0.10,
        'form_start' => 1.50,
        'form_submit' => 5.00,
        'search' => 0.50,
        'share' => 2.00,
        'view_item' => 0.75,
        'add_to_cart' => 3.00,
        'view_cart' => 1.00,
        'begin_checkout' => 8.00,
        'feature_used' => 1.50,
        'sign_up' => 25.00,
        'login' => 0.20,
        'start_trial' => 75.00,
        'invite_sent' => 15.00,
        'error' => -0.50,
    ];

    private readonly CacheRepository $cache;

    private readonly AnalyticsMetrics $metrics;

    private readonly int $cacheTtl;

    /** @var array<string, array{path: list<string>, conversion_event: string, value_budget: float}> */
    private readonly array $funnelPaths;

    /** @var array<string, float> */
    private readonly array $baseValues;

    /** @var string Attribution model: 'position_decay' | 'linear' | 'equal' */
    private readonly string $model;

    /** @var float Decay factor for position_decay model (higher = more weight on later events) */
    private readonly float $decayFactor;

    private readonly bool $enabled;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     * @param  AnalyticsMetrics  $metrics
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        AnalyticsMetrics $metrics,
    ): void {
        $this->cache = $cache;
        $this->metrics = $metrics;

        $attrConfig = $config->get('zeroboiler.analytics.event_value_attribution', []);
        /** @var array{enabled?: bool, cache_ttl?: int, model?: string, decay_factor?: float, funnel_paths?: array<string, array{path: list<string>, conversion_event: string, value_budget: float}>, base_values?: array<string, float>} $attrConfig */

        $this->enabled = (bool) ($attrConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($attrConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->model = (string) ($attrConfig['model'] ?? 'position_decay');
        $this->decayFactor = (float) ($attrConfig['decay_factor'] ?? 0.7);

        $this->funnelPaths = $attrConfig['funnel_paths'] ?? self::DEFAULT_FUNNEL_PATHS;
        $this->baseValues = array_merge(self::DEFAULT_BASE_VALUES, $attrConfig['base_values'] ?? []);
    }

    /**
     * Calculate attributed monetary value for a specific event.
     *
     * Returns the maximum value assigned across all funnel paths the event
     * appears in. If the event doesn't appear in any funnel, returns the
     * base value from defaults.
     *
     * @param  string  $eventName  Event name to value
     * @return array{value: float, currency: string, source: string, funnel_paths: int, position_avg: float, is_conversion: bool}
     */
    public function valueOf(string $eventName): array
    {
        if (! $this->enabled) {
            return $this->disabledValue($eventName);
        }

        $maxValue = 0.0;
        $pathCount = 0;
        $positionSum = 0.0;
        $isConversion = false;

        foreach ($this->funnelPaths as $pathName => $funnel) {
            $path = $funnel['path'];
            $budget = $funnel['value_budget'];

            if ($funnel['conversion_event'] === $eventName) {
                $isConversion = true;
                $maxValue = max($maxValue, $budget);
                $pathCount++;
                continue;
            }

            $position = array_search($eventName, $path, true);
            if ($position === false) {
                continue;
            }

            $position = (int) $position;
            $pathLength = count($path);
            $pathCount++;
            $positionSum += $position;

            $attributedValue = $this->computePositionValue($position, $pathLength, $budget);
            $maxValue = max($maxValue, $attributedValue);
        }

        // Fallback to base value if event not in any funnel
        if ($maxValue === 0.0 && isset($this->baseValues[$eventName])) {
            $maxValue = $this->baseValues[$eventName];
        }

        return [
            'value' => round($maxValue, 4),
            'currency' => 'USD',
            'source' => $pathCount > 0 ? 'funnel_attribution' : 'base_value',
            'funnel_paths' => $pathCount,
            'position_avg' => $pathCount > 0 ? round($positionSum / $pathCount, 1) : 0.0,
            'is_conversion' => $isConversion,
        ];
    }

    /**
     * Calculate attributed values for multiple events at once.
     *
     * @param  list<string>  $eventNames  List of event names
     * @return array{values: array<string, array{value: float, currency: string, source: string, is_conversion: bool}>, total_value: float, top_value_event: string|null, conversion_events: list<string>}
     */
    public function valueOfMany(array $eventNames): array
    {
        $values = [];
        $totalValue = 0.0;
        $topValueEvent = null;
        $topValue = 0.0;
        $conversionEvents = [];

        foreach ($eventNames as $name) {
            $result = $this->valueOf($name);
            $values[$name] = [
                'value' => $result['value'],
                'currency' => $result['currency'],
                'source' => $result['source'],
                'is_conversion' => $result['is_conversion'],
            ];
            $totalValue += $result['value'];

            if ($result['value'] > $topValue) {
                $topValue = $result['value'];
                $topValueEvent = $name;
            }

            if ($result['is_conversion']) {
                $conversionEvents[] = $name;
            }
        }

        return [
            'values' => $values,
            'total_value' => round($totalValue, 4),
            'top_value_event' => $topValueEvent,
            'conversion_events' => $conversionEvents,
        ];
    }

    /**
     * Build a full event economics report.
     *
     * Values all events in the catalog and provides analytics for:
     * - Top revenue-driving events
     * - Conversion event values
     * - Funnel coverage analysis
     * - Value distribution by category
     *
     * @return array{generated_at: string, model: string, events_valued: int, top_events: list<array{name: string, value: float, source: string}>, by_category: array<string, array{total_value: float, events: int, avg_value: float}>, funnel_coverage: array{paths: int, events_covered: int, events_uncovered: int, coverage_pct: float}, conversion_events: array<string, float>, recommendations: list<string>}
     */
    public function report(): array
    {
        if (! $this->enabled) {
            return $this->disabledReport();
        }

        $cacheKey = self::CACHE_PREFIX . 'report_' . $this->model;

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $catalog = EventCatalog::all();
        $eventValues = [];
        $byCategory = [];
        $conversionEvents = [];

        foreach ($catalog as $name => $entry) {
            $valued = $this->valueOf($name);
            $eventValues[] = [
                'name' => $name,
                'value' => $valued['value'],
                'source' => $valued['source'],
                'is_conversion' => $valued['is_conversion'],
            ];

            $category = $entry['category'] ?? 'unknown';
            if (! isset($byCategory[$category])) {
                $byCategory[$category] = ['total_value' => 0.0, 'events' => 0, 'values' => []];
            }
            $byCategory[$category]['total_value'] += $valued['value'];
            $byCategory[$category]['events']++;
            $byCategory[$category]['values'][] = $valued['value'];

            if ($valued['is_conversion']) {
                $conversionEvents[$name] = $valued['value'];
            }
        }

        // Sort by value descending for top events
        usort($eventValues, fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        $topEvents = array_slice($eventValues, 0, 20);

        // Build category summaries
        $categorySummary = [];
        foreach ($byCategory as $cat => $data) {
            $categorySummary[$cat] = [
                'total_value' => round($data['total_value'], 4),
                'events' => $data['events'],
                'avg_value' => $data['events'] > 0 ? round($data['total_value'] / $data['events'], 4) : 0.0,
            ];
        }

        // Funnel coverage
        $funnelEvents = [];
        foreach ($this->funnelPaths as $funnel) {
            foreach ($funnel['path'] as $evt) {
                $funnelEvents[$evt] = true;
            }
            $funnelEvents[$funnel['conversion_event']] = true;
        }
        $eventsCovered = count($funnelEvents);
        $totalCatalog = count($catalog);
        $coveragePct = $totalCatalog > 0 ? ($eventsCovered / $totalCatalog) * 100 : 0.0;

        $report = [
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'model' => $this->model,
            'events_valued' => $totalCatalog,
            'top_events' => array_map(fn (array $e): array => [
                'name' => $e['name'],
                'value' => $e['value'],
                'source' => $e['source'],
            ], $topEvents),
            'by_category' => $categorySummary,
            'funnel_coverage' => [
                'paths' => count($this->funnelPaths),
                'events_covered' => $eventsCovered,
                'events_uncovered' => $totalCatalog - $eventsCovered,
                'coverage_pct' => round($coveragePct, 1),
            ],
            'conversion_events' => $conversionEvents,
            'recommendations' => $this->buildRecommendations($topEvents, $coveragePct, $categorySummary),
        ];

        $this->cache->put($cacheKey, $report, $this->cacheTtl);

        return $report;
    }

    /**
     * Simulate the value of a user journey (sequence of events).
     *
     * Calculates cumulative attributed value for a sequence of events,
     * identifying which events in the journey contributed the most value.
     *
     * @param  list<string>  $journeyEvents  Ordered list of event names in the user's journey
     * @return array{total_value: float, events: list<array{name: string, value: float, cumulative: float}>, peak_value_event: string|null, value_density: float}
     */
    public function valueJourney(array $journeyEvents): array
    {
        $totalValue = 0.0;
        $events = [];
        $peakValueEvent = null;
        $peakValue = 0.0;

        foreach ($journeyEvents as $name) {
            $valued = $this->valueOf($name);
            $totalValue += $valued['value'];

            $events[] = [
                'name' => $name,
                'value' => $valued['value'],
                'cumulative' => round($totalValue, 4),
            ];

            if ($valued['value'] > $peakValue) {
                $peakValue = $valued['value'];
                $peakValueEvent = $name;
            }
        }

        $eventCount = count($journeyEvents);
        $valueDensity = $eventCount > 0 ? $totalValue / $eventCount : 0.0;

        return [
            'total_value' => round($totalValue, 4),
            'events' => $events,
            'peak_value_event' => $peakValueEvent,
            'value_density' => round($valueDensity, 4),
        ];
    }

    /**
     * Get the configured funnel paths.
     *
     * @return array<string, array{path: list<string>, conversion_event: string, value_budget: float}>
     */
    public function getFunnelPaths(): array
    {
        return $this->funnelPaths;
    }

    /**
     * Get the base values for events not in funnel paths.
     *
     * @return array<string, float>
     */
    public function getBaseValues(): array
    {
        return $this->baseValues;
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Compute the attributed value for an event at a given position in a funnel.
     *
     * @param  int  $position  Zero-based position in the funnel path
     * @param  int  $pathLength  Total number of events in the path
     * @param  float  $budget  Total value budget for this funnel's conversion
     */
    private function computePositionValue(int $position, int $pathLength, float $budget): float
    {
        return match ($this->model) {
            'linear' => $budget / $pathLength,
            'equal' => $budget / $pathLength,
            'position_decay' => $budget * (pow($this->decayFactor, $pathLength - 1 - $position))
                / array_sum(array_map(fn (int $i): float => pow($this->decayFactor, $pathLength - 1 - $i), range(0, $pathLength - 1))),
            default => $budget / $pathLength,
        };
    }

    /**
     * Build actionable recommendations from the report data.
     *
     * @param  list<array{name: string, value: float, source: string}>  $topEvents
     * @param  float  $coveragePct
     * @param  array<string, array{total_value: float, events: int, avg_value: float}>  $categorySummary
     * @return list<string>
     */
    private function buildRecommendations(array $topEvents, float $coveragePct, array $categorySummary): array
    {
        $recommendations = [];

        if ($coveragePct < 30.0) {
            $recommendations[] = 'Low funnel coverage (' . round($coveragePct, 1) . '%). Consider defining funnel paths for more event categories to improve attribution accuracy.';
        }

        $negativeEvents = array_filter($topEvents, fn (array $e): bool => $e['value'] < 0);
        if (count($negativeEvents) > 0) {
            $recommendations[] = count($negativeEvents) . ' event(s) have negative attributed value (e.g., error events). Investigate root causes to reduce revenue-impacting friction.';
        }

        // Find category with highest avg value
        $maxAvg = 0.0;
        $maxCategory = '';
        foreach ($categorySummary as $cat => $data) {
            if ($data['avg_value'] > $maxAvg) {
                $maxAvg = $data['avg_value'];
                $maxCategory = $cat;
            }
        }
        if ($maxCategory !== '') {
            $recommendations[] = "The '{$maxCategory}' category has the highest average event value (\${$maxAvg}). Prioritize instrumentation in this domain.";
        }

        if (count($recommendations) === 0) {
            $recommendations[] = 'Event value attribution looks healthy. Continue monitoring funnel paths and value budgets as conversion rates change.';
        }

        return $recommendations;
    }

    /**
     * Build a disabled response for valueOf().
     *
     * @param  string  $eventName
     * @return array{value: float, currency: string, source: string, funnel_paths: int, position_avg: float, is_conversion: bool}
     */
    private function disabledValue(string $eventName): array
    {
        return [
            'value' => 0.0,
            'currency' => 'USD',
            'source' => 'disabled',
            'funnel_paths' => 0,
            'position_avg' => 0.0,
            'is_conversion' => false,
        ];
    }

    /**
     * Build a disabled response for report().
     *
     * @return array{generated_at: string, model: string, events_valued: int, top_events: list<empty>, by_category: array<empty, empty>, funnel_coverage: array{paths: int, events_covered: int, events_uncovered: int, coverage_pct: float}, conversion_events: array<empty, empty>, recommendations: list<string>}
     */
    private function disabledReport(): array
    {
        return [
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'model' => $this->model,
            'events_valued' => 0,
            'top_events' => [],
            'by_category' => [],
            'funnel_coverage' => [
                'paths' => 0,
                'events_covered' => 0,
                'events_uncovered' => 0,
                'coverage_pct' => 0.0,
            ],
            'conversion_events' => [],
            'recommendations' => ['Event value attribution is disabled. Enable via zeroboiler.analytics.event_value_attribution.enabled.'],
        ];
    }
}
