<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Conversion Path Discovery Service.
 *
 * Analyzes event sequences to discover the most common multi-step conversion
 * paths users take through a SaaS application. Identifies high-converting
 * patterns, common drop-off points, and optimal journey sequences.
 *
 * Designed for product analytics dashboards, conversion funnel optimization,
 * and growth team insights.
 *
 * Data is stored in cache with configurable TTL. Supports configurable
 * max path depth, minimum sample thresholds, and funnel step definitions.
 *
 * @since 83.0.0
 */
final class ConversionPathDiscoveryService
{
    private const CACHE_PREFIX = 'zb_conv_path_';
    private const DEFAULT_TTL = 86400; // 24 hours
    private const DEFAULT_MAX_PATH_DEPTH = 10;
    private const DEFAULT_MIN_SAMPLES = 3;
    private const DEFAULT_TOP_PATHS_LIMIT = 20;

    /**
     * @param  CacheRepository  $cache  Cache store for path data
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ){}

    /**
     * Record a user's event step in a conversion path.
     *
     * Appends the event to the user's path history, maintaining order
     * and respecting the configured max path depth.
     *
     * @param  string  $funnelName  Funnel identifier (e.g., 'signup', 'purchase')
     * @param  string  $identity  User ID or client ID
     * @param  string  $eventStep  Event name or step name
     * @param  array<string, mixed>  $metadata  Additional context (timestamp, source, etc.)
     */
    public function recordStep(
        string $funnelName,
        string $identity,
        string $eventStep,
        array $metadata = [],
    ): void {
        $cacheKey = $this->pathKey($funnelName, $identity);
        $path = $this->cache->get($cacheKey, []);

        if (! is_array($path)) {
            $path = [];
        }

        $maxDepth = (int) ($this->config->get('zeroboiler.analytics.conversion_paths.max_depth', self::DEFAULT_MAX_PATH_DEPTH));

        $path[] = [
            'step' => $eventStep,
            'timestamp' => $metadata['timestamp'] ?? now()->toIso8601String(),
            'metadata' => $metadata,
        ];

        // Trim to max depth
        if (count($path) > $maxDepth) {
            $path = array_slice($path, -$maxDepth);
        }

        $ttl = (int) ($this->config->get('zeroboiler.analytics.conversion_paths.cache_ttl', self::DEFAULT_TTL));
        $this->cache->put($cacheKey, $path, $ttl);
    }

    /**
     * Mark a user's path as converted.
     *
     * Records the completed path as a conversion pattern for aggregation.
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  string  $identity  User ID or client ID
     * @param  string  $conversionEvent  The event that triggered conversion
     */
    public function markConverted(
        string $funnelName,
        string $identity,
        string $conversionEvent = 'conversion',
    ): void {
        $pathKey = $this->pathKey($funnelName, $identity);
        $path = $this->cache->get($pathKey, []);

        if (! is_array($path) || empty($path)) {
            return;
        }

        $steps = array_map(
            static fn (array $p): string => $p['step'],
            $path,
        );

        // Append conversion event
        $steps[] = $conversionEvent;

        $patternKey = $this->patternKey($funnelName);
        $patterns = $this->cache->get($patternKey, []);

        if (! is_array($patterns)) {
            $patterns = [];
        }

        $patternString = implode(' → ', $steps);

        if (! isset($patterns[$patternString])) {
            $patterns[$patternString] = [
                'pattern' => $patternString,
                'steps' => $steps,
                'count' => 0,
                'first_seen' => now()->toIso8601String(),
            ];
        }

        $patterns[$patternString]['count']++;
        $patterns[$patternString]['last_seen'] = now()->toIso8601String();

        $ttl = (int) ($this->config->get('zeroboiler.analytics.conversion_paths.cache_ttl', self::DEFAULT_TTL));
        $this->cache->put($patternKey, $patterns, $ttl);

        // Clear the individual user path
        $this->cache->forget($pathKey);
    }

    /**
     * Mark a user's path as abandoned (drop-off).
     *
     * Records the incomplete path as a drop-off pattern for analysis.
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  string  $identity  User ID or client ID
     * @param  string|null  $dropOffStep  The step at which the user abandoned
     */
    public function markAbandoned(
        string $funnelName,
        string $identity,
        ?string $dropOffStep = null,
    ): void {
        $pathKey = $this->pathKey($funnelName, $identity);
        $path = $this->cache->get($pathKey, []);

        if (! is_array($path) || empty($path)) {
            return;
        }

        $steps = array_map(
            static fn (array $p): string => $p['step'],
            $path,
        );

        $dropOffKey = $this->dropOffKey($funnelName);
        $dropOffs = $this->cache->get($dropOffKey, []);

        if (! is_array($dropOffs)) {
            $dropOffs = [];
        }

        $patternString = implode(' → ', $steps);

        if (! isset($dropOffs[$patternString])) {
            $dropOffs[$patternString] = [
                'pattern' => $patternString,
                'steps' => $steps,
                'count' => 0,
                'drop_off_step' => $dropOffStep ?? end($steps),
            ];
        }

        $dropOffs[$patternString]['count']++;

        $ttl = (int) ($this->config->get('zeroboiler.analytics.conversion_paths.cache_ttl', self::DEFAULT_TTL));
        $this->cache->put($dropOffKey, $dropOffs, $ttl);

        // Clear the individual user path
        $this->cache->forget($pathKey);
    }

    /**
     * Get the top conversion paths for a funnel.
     *
     * Returns the most common paths that led to conversion,
     * sorted by frequency descending.
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  int  $limit  Maximum number of paths to return
     * @return list<array{pattern: string, steps: list<string>, count: int, first_seen: string|null, last_seen: string|null, share: float}>
     */
    public function topConversionPaths(string $funnelName, int $limit = self::DEFAULT_TOP_PATHS_LIMIT): array
    {
        $patternKey = $this->patternKey($funnelName);
        $patterns = $this->cache->get($patternKey, []);

        if (! is_array($patterns) || empty($patterns)) {
            return [];
        }

        $minSamples = (int) ($this->config->get('zeroboiler.analytics.conversion_paths.min_samples', self::DEFAULT_MIN_SAMPLES));

        // Filter by minimum sample threshold
        $filtered = array_filter(
            $patterns,
            static fn (array $p): bool => $p['count'] >= $minSamples,
        );

        // Sort by count descending
        usort($filtered, function (array $a, array $b): int {
            return $b['count'] <=> $a['count'];
        });

        $totalConversions = array_sum(array_column($patterns, 'count'));

        return array_map(function (array $p) use ($totalConversions): array {
            return [
                'pattern' => $p['pattern'],
                'steps' => $p['steps'],
                'count' => $p['count'],
                'first_seen' => $p['first_seen'] ?? null,
                'last_seen' => $p['last_seen'] ?? null,
                'share' => $totalConversions > 0 ? round(($p['count'] / $totalConversions) * 100, 2) : 0.0,
            ];
        }, array_slice($filtered, 0, $limit));
    }

    /**
     * Get the top drop-off paths for a funnel.
     *
     * Returns the most common paths where users abandoned the funnel,
     * sorted by frequency descending.
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  int  $limit  Maximum number of paths to return
     * @return list<array{pattern: string, steps: list<string>, count: int, drop_off_step: string|null, share: float}>
     */
    public function topDropOffPaths(string $funnelName, int $limit = self::DEFAULT_TOP_PATHS_LIMIT): array
    {
        $dropOffKey = $this->dropOffKey($funnelName);
        $dropOffs = $this->cache->get($dropOffKey, []);

        if (! is_array($dropOffs) || empty($dropOffs)) {
            return [];
        }

        $minSamples = (int) ($this->config->get('zeroboiler.analytics.conversion_paths.min_samples', self::DEFAULT_MIN_SAMPLES));

        $filtered = array_filter(
            $dropOffs,
            static fn (array $d): bool => $d['count'] >= $minSamples,
        );

        usort($filtered, function (array $a, array $b): int {
            return $b['count'] <=> $a['count'];
        });

        $totalDropOffs = array_sum(array_column($dropOffs, 'count'));

        return array_map(function (array $d) use ($totalDropOffs): array {
            return [
                'pattern' => $d['pattern'],
                'steps' => $d['steps'],
                'count' => $d['count'],
                'drop_off_step' => $d['drop_off_step'] ?? null,
                'share' => $totalDropOffs > 0 ? round(($d['count'] / $totalDropOffs) * 100, 2) : 0.0,
            ];
        }, array_slice($filtered, 0, $limit));
    }

    /**
     * Get step-by-step conversion analysis for a funnel.
     *
     * Computes the conversion rate and drop-off rate for each step
     * in the funnel, based on aggregated conversion and drop-off patterns.
     *
     * @param  string  $funnelName  Funnel identifier
     * @return array{funnel: string, steps: list<array{step: string, entries: int, conversions: int, conversion_rate: float, drop_offs: int, drop_off_rate: float}>, total_conversions: int, total_drop_offs: int, overall_rate: float}
     */
    public function stepAnalysis(string $funnelName): array
    {
        $patterns = $this->cache->get($this->patternKey($funnelName), []);
        $dropOffs = $this->cache->get($this->dropOffKey($funnelName), []);

        $patterns = is_array($patterns) ? $patterns : [];
        $dropOffs = is_array($dropOffs) ? $dropOffs : [];

        // Aggregate step-level counts from conversion patterns
        $stepEntries = [];
        $stepConversions = [];

        foreach ($patterns as $pattern) {
            $steps = $pattern['steps'] ?? [];
            $count = $pattern['count'] ?? 1;

            foreach ($steps as $i => $step) {
                if (! isset($stepEntries[$step])) {
                    $stepEntries[$step] = 0;
                    $stepConversions[$step] = 0;
                }
                $stepEntries[$step] += $count;

                // A step "converts" if it's not the last step
                if ($i < count($steps) - 1) {
                    $stepConversions[$step] += $count;
                }
            }
        }

        // Aggregate drop-off counts
        foreach ($dropOffs as $dropOff) {
            $steps = $dropOff['steps'] ?? [];
            $count = $dropOff['count'] ?? 1;
            $lastStep = end($steps);

            if ($lastStep !== false) {
                if (! isset($stepEntries[$lastStep])) {
                    $stepEntries[$lastStep] = 0;
                }
                $stepEntries[$lastStep] += $count;
            }
        }

        $totalConversions = array_sum(array_column($patterns, 'count'));
        $totalDropOffs = array_sum(array_column($dropOffs, 'count'));

        $resultSteps = [];
        $uniqueSteps = array_unique(array_merge(array_keys($stepEntries), array_keys($stepConversions)));

        foreach ($uniqueSteps as $step) {
            $entries = $stepEntries[$step] ?? 0;
            $conversions = $stepConversions[$step] ?? 0;
            $dropOffsForStep = max(0, $entries - $conversions);

            $resultSteps[] = [
                'step' => $step,
                'entries' => $entries,
                'conversions' => $conversions,
                'conversion_rate' => $entries > 0 ? round(($conversions / $entries) * 100, 2) : 0.0,
                'drop_offs' => $dropOffsForStep,
                'drop_off_rate' => $entries > 0 ? round(($dropOffsForStep / $entries) * 100, 2) : 0.0,
            ];
        }

        $totalEvents = $totalConversions + $totalDropOffs;

        return [
            'funnel' => $funnelName,
            'steps' => $resultSteps,
            'total_conversions' => $totalConversions,
            'total_drop_offs' => $totalDropOffs,
            'overall_rate' => $totalEvents > 0 ? round(($totalConversions / $totalEvents) * 100, 2) : 0.0,
        ];
    }

    /**
     * Compare two funnels' conversion patterns.
     *
     * Useful for A/B testing analysis, time-period comparisons, or
     * segment-based path comparison.
     *
     * @param  string  $funnelA  First funnel identifier
     * @param  string  $funnelB  Second funnel identifier
     * @return array{funnel_a: string, funnel_b: string, paths_a: list<array<string, mixed>>, paths_b: list<array<string, mixed>>, shared_patterns: list<string>, unique_a: list<string>, unique_b: list<string>, conversion_rate_diff: float}
     */
    public function compareFunnels(string $funnelA, string $funnelB): array
    {
        $pathsA = $this->topConversionPaths($funnelA, 50);
        $pathsB = $this->topConversionPaths($funnelB, 50);

        $patternsA = array_column($pathsA, 'pattern');
        $patternsB = array_column($pathsB, 'pattern');

        $shared = array_values(array_intersect($patternsA, $patternsB));
        $uniqueA = array_values(array_diff($patternsA, $patternsB));
        $uniqueB = array_values(array_diff($patternsB, $patternsA));

        // Compute overall conversion rate for each
        $patternA = $this->cache->get($this->patternKey($funnelA), []);
        $patternB = $this->cache->get($this->patternKey($funnelB), []);
        $dropOffA = $this->cache->get($this->dropOffKey($funnelA), []);
        $dropOffB = $this->cache->get($this->dropOffKey($funnelB), []);

        $totalA = array_sum(array_column(is_array($patternA) ? $patternA : [], 'count'))
            + array_sum(array_column(is_array($dropOffA) ? $dropOffA : [], 'count'));
        $totalB = array_sum(array_column(is_array($patternB) ? $patternB : [], 'count'))
            + array_sum(array_column(is_array($dropOffB) ? $dropOffB : [], 'count'));

        $convCountA = array_sum(array_column(is_array($patternA) ? $patternA : [], 'count'));
        $convCountB = array_sum(array_column(is_array($patternB) ? $patternB : [], 'count'));

        $rateA = $totalA > 0 ? round(($convCountA / $totalA) * 100, 2) : 0.0;
        $rateB = $totalB > 0 ? round(($convCountB / $totalB) * 100, 2) : 0.0;

        return [
            'funnel_a' => $funnelA,
            'funnel_b' => $funnelB,
            'paths_a' => $pathsA,
            'paths_b' => $pathsB,
            'shared_patterns' => $shared,
            'unique_a' => $uniqueA,
            'unique_b' => $uniqueB,
            'conversion_rate_a' => $rateA,
            'conversion_rate_b' => $rateB,
            'conversion_rate_diff' => round($rateA - $rateB, 2),
        ];
    }

    /**
     * Get a comprehensive funnel summary with paths, drop-offs, and step analysis.
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  int  $topPathsLimit  Maximum paths to include
     * @return array{funnel: string, top_conversion_paths: list<array<string, mixed>>, top_drop_off_paths: list<array<string, mixed>>, step_analysis: array<string, mixed>, summary: array{total_conversions: int, total_drop_offs: int, overall_rate: float, path_diversity: float}}
     */
    public function funnelSummary(string $funnelName, int $topPathsLimit = self::DEFAULT_TOP_PATHS_LIMIT): array
    {
        $convPaths = $this->topConversionPaths($funnelName, $topPathsLimit);
        $dropPaths = $this->topDropOffPaths($funnelName, $topPathsLimit);
        $analysis = $this->stepAnalysis($funnelName);

        // Path diversity: unique patterns / total conversions (normalized 0-100)
        $totalConversions = $analysis['total_conversions'];
        $uniquePatterns = count($convPaths);
        $diversity = $totalConversions > 0 && $uniquePatterns > 0
            ? round(min(100, ($uniquePatterns / $totalConversions) * 1000), 2)
            : 0.0;

        return [
            'funnel' => $funnelName,
            'top_conversion_paths' => $convPaths,
            'top_drop_off_paths' => $dropPaths,
            'step_analysis' => $analysis,
            'summary' => [
                'total_conversions' => $totalConversions,
                'total_drop_offs' => $analysis['total_drop_offs'],
                'overall_rate' => $analysis['overall_rate'],
                'path_diversity' => $diversity,
            ],
        ];
    }

    /**
     * Clear all conversion path data for a funnel.
     *
     * @param  string  $funnelName  Funnel identifier
     */
    public function clearFunnel(string $funnelName): void
    {
        $this->cache->forget($this->patternKey($funnelName));
        $this->cache->forget($this->dropOffKey($funnelName));
    }

    /**
     * Get the raw conversion patterns for a funnel.
     *
     * @param  string  $funnelName  Funnel identifier
     * @return array<string, array{pattern: string, steps: list<string>, count: int}>
     */
    public function rawPatterns(string $funnelName): array
    {
        $patterns = $this->cache->get($this->patternKey($funnelName), []);

        return is_array($patterns) ? $patterns : [];
    }

    /**
     * Get the raw drop-off patterns for a funnel.
     *
     * @param  string  $funnelName  Funnel identifier
     * @return array<string, array{pattern: string, steps: list<string>, count: int, drop_off_step: string|null}>
     */
    public function rawDropOffs(string $funnelName): array
    {
        $dropOffs = $this->cache->get($this->dropOffKey($funnelName), []);

        return is_array($dropOffs) ? $dropOffs : [];
    }

    /**
     * Get a user's current (in-progress) path for a funnel.
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  string  $identity  User ID or client ID
     * @return list<array{step: string, timestamp: string, metadata: array<string, mixed>}>
     */
    public function userCurrentPath(string $funnelName, string $identity): array
    {
        $path = $this->cache->get($this->pathKey($funnelName, $identity), []);

        return is_array($path) ? $path : [];
    }

    /**
     * Generate a cache key for a user's path.
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  string  $identity  User ID or client ID
     */
    private function pathKey(string $funnelName, string $identity): string
    {
        return self::CACHE_PREFIX . 'path:' . $funnelName . ':' . hash('xxh128', $identity);
    }

    /**
     * Generate a cache key for conversion patterns.
     *
     * @param  string  $funnelName  Funnel identifier
     */
    private function patternKey(string $funnelName): string
    {
        return self::CACHE_PREFIX . 'patterns:' . $funnelName;
    }

    /**
     * Generate a cache key for drop-off patterns.
     *
     * @param  string  $funnelName  Funnel identifier
     */
    private function dropOffKey(string $funnelName): string
    {
        return self::CACHE_PREFIX . 'dropoffs:' . $funnelName;
    }
}
