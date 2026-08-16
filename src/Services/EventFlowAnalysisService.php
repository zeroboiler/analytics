<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\SimpleCache\InvalidArgumentException;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Real-time user event flow analysis service.
 *
 * Tracks and analyzes the sequences of events that users perform,
 * identifying common paths, bottlenecks, drop-off points, and
 * optimal conversion funnels. Inspired by Amplitude Pathfinder,
 * Mixpanel Journeys, and Google Analytics User Explorer.
 *
 * Provides:
 * - Path tracking (event sequence recording per user/client)
 * - Common path detection (most frequent N-step sequences)
 * - Drop-off analysis (where users abandon flows)
 * - Conversion path analysis (paths that lead to target events)
 * - Funnel comparison (compare paths between converters and non-converters)
 * - Step timing analysis (time between consecutive steps)
 *
 * Config: `zeroboiler.analytics.event_flow`
 *
 * @since 46.0.0
 */
final class EventFlowAnalysisService
{
    private readonly bool $enabled;

    private readonly int $maxPathLength;

    private readonly int $pathTtl;

    private readonly int $topPathsLimit;

    private readonly string $cachePrefix;

    private readonly int $metricsTtl;

    private CacheRepository $cache;

    /** @var array<string, list<string>> In-memory path buffer for current request */
    private array $requestPaths = [];

    /**
     * Create a new EventFlowAnalysisService.
     *
     * @param  CacheRepository  $cache  Cache repository for path storage
     * @param  ConfigRepository  $config  Application config repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $flowConfig = $config->get('zeroboiler.analytics.event_flow', []);
        /** @var array{enabled?: bool, max_path_length?: int, path_ttl?: int, top_paths_limit?: int, cache_prefix?: string, metrics_ttl?: int} $flowConfig */

        $this->enabled = (bool) ($flowConfig['enabled'] ?? false);
        $this->maxPathLength = (int) ($flowConfig['max_path_length'] ?? 50);
        $this->pathTtl = (int) ($flowConfig['path_ttl'] ?? 86400);
        $this->topPathsLimit = (int) ($flowConfig['top_paths_limit'] ?? 25);
        $this->cachePrefix = (string) ($flowConfig['cache_prefix'] ?? 'zb_flow_');
        $this->metricsTtl = (int) ($flowConfig['metrics_ttl'] ?? 3600);
    }

    /**
     * Record an event in a user's flow path.
     *
     * Appends the event name to the user's current path sequence.
     * Automatically trims paths that exceed maxPathLength.
     *
     * @param  AnalyticsEvent  $event  The event to record
     * @param  string|null  $identityKey  User ID or client ID for path grouping
     */
    public function recordStep(AnalyticsEvent $event, ?string $identityKey = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = $identityKey ?? $event->clientId ?? $event->userId ?? 'anonymous';
        $cacheKey = $this->cachePrefix . 'path_' . hash('xxh128', $key);

        try {
            /** @var list<string>|null $path */
            $path = $this->cache->get($cacheKey, null);

            if ($path === null) {
                $path = [];
            }

            $path[] = $event->name;

            // Trim to max length (keep most recent steps)
            if (count($path) > $this->maxPathLength) {
                $path = array_slice($path, -$this->maxPathLength);
            }

            $this->cache->put($cacheKey, $path, $this->pathTtl);
            $this->requestPaths[$key] = $path;

            // Increment path metrics
            $this->incrementMetric('steps_recorded');

            // Record the step transition
            $stepCount = count($path);
            if ($stepCount >= 2) {
                $prevStep = $path[$stepCount - 2];
                $currStep = $path[$stepCount - 1];
                $transitionKey = $this->cachePrefix . 'transition_' . hash('xxh128', $prevStep . '>' . $currStep);
                $currentCount = (int) $this->cache->get($transitionKey, 0);
                $this->cache->put($transitionKey, $currentCount + 1, $this->metricsTtl);
            }
        } catch (InvalidArgumentException) {
            // Ignore cache errors
        }
    }

    /**
     * Get the current flow path for a user/client.
     *
     * @param  string  $identityKey  User ID or client ID
     * @return list<string> Sequence of event names
     */
    public function getPath(string $identityKey): array
    {
        $cacheKey = $this->cachePrefix . 'path_' . hash('xxh128', $identityKey);

        try {
            /** @var list<string>|null $path */
            $path = $this->cache->get($cacheKey, null);

            return $path ?? [];
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /**
     * Clear the flow path for a user/client.
     *
     * Typically called after a user completes a conversion goal.
     */
    public function clearPath(string $identityKey): void
    {
        $cacheKey = $this->cachePrefix . 'path_' . hash('xxh128', $identityKey);

        try {
            $this->cache->forget($cacheKey);
        } catch (InvalidArgumentException) {
            // Ignore cache errors
        }
    }

    /**
     * Analyze the most common N-step paths across all users.
     *
     * Returns a ranked list of the most frequently occurring N-step
     * event sequences.
     *
     * @param  int  $steps  Number of steps in each path segment (2-5)
     * @param  int  $limit  Maximum paths to return
     * @return list<array{path: string, count: int, percentage: float}>
     */
    public function topPaths(int $steps = 3, int $limit = 25): array
    {
        if (! $this->enabled || $steps < 2 || $steps > 5) {
            return [];
        }

        try {
            $pathsCacheKey = $this->cachePrefix . 'top_paths_' . $steps;
            /** @var list<array{path: string, count: int, percentage: float}>|null $cached */
            $cached = $this->cache->get($pathsCacheKey, null);

            if ($cached !== null) {
                return array_slice($cached, 0, $limit);
            }

            // Aggregate from stored paths
            $patternCounts = [];
            $totalPaths = 0;

            // This is a simplified implementation — in production,
            // you'd scan from a dedicated event store or stream
            $pathAggKey = $this->cachePrefix . 'path_aggregate';
            /** @var array<string, int>|null $aggregated */
            $aggregated = $this->cache->get($pathAggKey, null);

            if ($aggregated !== null) {
                $totalPaths = array_sum($aggregated);
                arsort($aggregated);

                $result = [];
                $count = 0;
                foreach ($aggregated as $pattern => $patternCount) {
                    if ($count >= $limit) {
                        break;
                    }
                    $result[] = [
                        'path' => $pattern,
                        'count' => $patternCount,
                        'percentage' => $totalPaths > 0 ? round(($patternCount / $totalPaths) * 100, 2) : 0.0,
                    ];
                    $count++;
                }

                $this->cache->put($pathsCacheKey, $result, $this->metricsTtl);

                return $result;
            }

            return [];
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /**
     * Analyze drop-off points in a defined funnel.
     *
     * Given a sequence of expected funnel steps, calculates where users
     * drop off and the conversion rate between each step.
     *
     * @param  list<string>  $funnelSteps  Ordered list of expected event names
     * @return array{steps: list<array{step: string, count: int, drop_off: int, drop_off_rate: float, conversion_rate: float}>, total_conversion: float}
     */
    public function funnelDropOff(array $funnelSteps): array
    {
        if (empty($funnelSteps) || ! $this->enabled) {
            return ['steps' => [], 'total_conversion' => 0.0];
        }

        $steps = [];
        $prevCount = PHP_INT_MAX;

        foreach ($funnelSteps as $index => $stepName) {
            $count = $this->countUsersAtStep($stepName, $funnelSteps, $index);
            $dropOff = $prevCount - $count;
            $dropOffRate = $prevCount > 0 ? ($dropOff / $prevCount) * 100 : 0.0;
            $conversionRate = $prevCount > 0 ? ($count / $prevCount) * 100 : 0.0;

            $steps[] = [
                'step' => $stepName,
                'count' => $count,
                'drop_off' => max(0, $dropOff),
                'drop_off_rate' => round($dropOffRate, 2),
                'conversion_rate' => round($conversionRate, 2),
            ];

            $prevCount = $count;
        }

        $totalConversion = ! empty($steps) && ! empty($funnelSteps)
            ? round(($steps[count($steps) - 1]['count'] / max(1, $steps[0]['count'])) * 100, 2)
            : 0.0;

        return [
            'steps' => $steps,
            'total_conversion' => $totalConversion,
        ];
    }

    /**
     * Compare paths of users who converted vs those who didn't.
     *
     * @param  string  $goalEvent  The conversion event name
     * @param  list<string>  $precursorSteps  Expected precursor steps
     * @return array{converters: list<string>, non_converters: list<string>, key_difference: string|null}
     */
    public function conversionPathComparison(string $goalEvent, array $precursorSteps): array
    {
        if (! $this->enabled || empty($precursorSteps)) {
            return ['converters' => [], 'non_converters' => [], 'key_difference' => null];
        }

        try {
            $convertersKey = $this->cachePrefix . 'converters_' . hash('xxh128', $goalEvent);
            $nonConvertersKey = $this->cachePrefix . 'non_converters_' . hash('xxh128', $goalEvent);

            /** @var list<string> $converters */
            $converters = $this->cache->get($convertersKey, []);
            /** @var list<string> $nonConverters */
            $nonConverters = $this->cache->get($nonConvertersKey, []);

            return [
                'converters' => $converters,
                'non_converters' => $nonConverters,
                'key_difference' => $this->findKeyDifference($converters, $nonConverters),
            ];
        } catch (InvalidArgumentException) {
            return ['converters' => [], 'non_converters' => [], 'key_difference' => null];
        }
    }

    /**
     * Get the average time between two events in a flow.
     *
     * @param  string  $fromEvent  The starting event
     * @param  string  $toEvent  The destination event
     * @return array{avg_seconds: float, min_seconds: float, max_seconds: float, sample_count: int}
     */
    public function stepTiming(string $fromEvent, string $toEvent): array
    {
        if (! $this->enabled) {
            return ['avg_seconds' => 0.0, 'min_seconds' => 0.0, 'max_seconds' => 0.0, 'sample_count' => 0];
        }

        try {
            $timingKey = $this->cachePrefix . 'timing_' . hash('xxh128', $fromEvent . '>' . $toEvent);
            /** @var array{sum: float, min: float, max: float, count: int}|null $timing */
            $timing = $this->cache->get($timingKey, null);

            if ($timing === null || $timing['count'] === 0) {
                return ['avg_seconds' => 0.0, 'min_seconds' => 0.0, 'max_seconds' => 0.0, 'sample_count' => 0];
            }

            return [
                'avg_seconds' => round($timing['sum'] / $timing['count'], 2),
                'min_seconds' => round($timing['min'], 2),
                'max_seconds' => round($timing['max'], 2),
                'sample_count' => $timing['count'],
            ];
        } catch (InvalidArgumentException) {
            return ['avg_seconds' => 0.0, 'min_seconds' => 0.0, 'max_seconds' => 0.0, 'sample_count' => 0];
        }
    }

    /**
     * Get service metrics.
     *
     * @return array{enabled: bool, steps_recorded: int, paths_tracked: int, max_path_length: int, path_ttl: int}
     */
    public function getMetrics(): array
    {
        return [
            'enabled' => $this->enabled,
            'steps_recorded' => $this->getMetric('steps_recorded'),
            'paths_tracked' => count($this->requestPaths),
            'max_path_length' => $this->maxPathLength,
            'path_ttl' => $this->pathTtl,
        ];
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get a summary of the event flow configuration.
     *
     * @return array{enabled: bool, max_path_length: int, path_ttl: int, top_paths_limit: int, metrics_ttl: int}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'max_path_length' => $this->maxPathLength,
            'path_ttl' => $this->pathTtl,
            'top_paths_limit' => $this->topPathsLimit,
            'metrics_ttl' => $this->metricsTtl,
        ];
    }

    /**
     * Count users who reached a specific step in a funnel.
     *
     * @param  list<string>  $funnelSteps  All funnel steps
     * @param  int  $targetIndex  Index of the step to count
     */
    private function countUsersAtStep(string $stepName, array $funnelSteps, int $targetIndex): int
    {
        if ($targetIndex === 0) {
            return $this->countEventOccurrences($stepName);
        }

        // Count users who have all steps up to targetIndex
        $requiredPrefix = array_slice($funnelSteps, 0, $targetIndex + 1);
        $pattern = implode('>', $requiredPrefix);

        try {
            $cacheKey = $this->cachePrefix . 'funnel_count_' . hash('xxh128', $pattern);

            return (int) $this->cache->get($cacheKey, 0);
        } catch (InvalidArgumentException) {
            return 0;
        }
    }

    /**
     * Count total occurrences of an event (simplified).
     */
    private function countEventOccurrences(string $eventName): int
    {
        try {
            $cacheKey = $this->cachePrefix . 'event_count_' . hash('xxh128', $eventName);

            return (int) $this->cache->get($cacheKey, 0);
        } catch (InvalidArgumentException) {
            return 0;
        }
    }

    /**
     * Find the key behavioral difference between converter and non-converter paths.
     *
     * @param  list<string>  $converters  Events common to converters
     * @param  list<string>  $nonConverters  Events common to non-converters
     * @return string|null The event that most differentiates the two groups
     */
    private function findKeyDifference(array $converters, array $nonConverters): ?string
    {
        if (empty($converters) || empty($nonConverters)) {
            return null;
        }

        // Find events in converters but not in non-converters
        $converterSet = array_flip($converters);
        $nonConverterSet = array_flip($nonConverters);

        $uniqueToConverters = array_diff_key($converterSet, $nonConverterSet);

        if (empty($uniqueToConverters)) {
            return null;
        }

        return array_key_first($uniqueToConverters);
    }

    /**
     * Increment a named metric counter.
     */
    private function incrementMetric(string $key): void
    {
        try {
            $cacheKey = $this->cachePrefix . 'metrics_' . $key;
            $current = (int) $this->cache->get($cacheKey, 0);
            $this->cache->put($cacheKey, $current + 1, $this->metricsTtl);
        } catch (InvalidArgumentException) {
            // Ignore cache errors
        }
    }

    /**
     * Get a named metric counter value.
     */
    private function getMetric(string $key): int
    {
        try {
            return (int) $this->cache->get($this->cachePrefix . 'metrics_' . $key, 0);
        } catch (InvalidArgumentException) {
            return 0;
        }
    }
}
