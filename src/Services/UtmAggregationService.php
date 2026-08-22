<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * UTM aggregation service for marketing attribution analytics.
 *
 * Aggregates events by UTM source, medium, campaign, and content
 * to provide marketing attribution insights. Tracks conversion counts,
 * event totals, and unique users per UTM combination.
 *
 * Configuration: `zeroboiler.analytics.utm_aggregation`
 *
 * @since 1.0.0
 */
final class UtmAggregationService
{
    private const CACHE_PREFIX = 'zb_utm_agg_';

    private const DEFAULT_TTL = 2592000; // 30 days

    private CacheRepository $cache;

    private bool $enabled;

    private int $cacheTtl;

    private int $maxCombinations;

    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->cache = $cache;

        $utmConfig = $config->get('zeroboiler.analytics.utm_aggregation', []);
        /** @var array{enabled?: bool, cache_ttl?: int, max_combinations?: int} $utmConfig */
        $this->enabled = (bool) ($utmConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($utmConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->maxCombinations = (int) ($utmConfig['max_combinations'] ?? 5000);
    }

    /**
     * Record an event with UTM parameters.
     *
     * @param  string  $eventName  Event name
     * @param  array{utm_source?: string, utm_medium?: string, utm_campaign?: string, utm_term?: string, utm_content?: string}  $utmParams  UTM parameters
     * @param  string|null  $userId  Optional user ID for unique counting
     */
    public function record(string $eventName, array $utmParams, ?string $userId = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $key = $this->buildKey($utmParams);

        if ($key === '') {
            return;
        }

        $agg = $this->cache->get($key, $this->emptyAgg());
        /** @var array{source: string|null, medium: string|null, campaign: string|null, term: string|null, content: string|null, events: array<string, int>, total: int, users: array<string, bool>} $agg */

        $agg['events'][$eventName] = ($agg['events'][$eventName] ?? 0) + 1;
        $agg['total']++;

        if ($userId !== null && $userId !== '' && ! isset($agg['users'][$userId])) {
            $agg['users'][$userId] = true;
        }

        $this->cache->put($key, $agg, $this->cacheTtl);

        // Update source-level aggregation
        $source = $utmParams['utm_source'] ?? null;
        if (is_string($source) && $source !== '') {
            $this->aggregateBySource($source, $eventName);
        }
    }

    /**
     * Get aggregated data for a specific UTM combination.
     *
     * @param  array{utm_source?: string, utm_medium?: string, utm_campaign?: string}  $utmParams
     * @return array{source: string|null, medium: string|null, campaign: string|null, events: array<string, int>, total: int, unique_users: int}|null
     */
    public function get(array $utmParams): ?array
    {
        $key = $this->buildKey($utmParams);

        if ($key === '') {
            return null;
        }

        $agg = $this->cache->get($key);

        if (! is_array($agg)) {
            return null;
        }

        /** @var array{source: string|null, medium: string|null, campaign: string|null, events: array<string, int>, total: int, users: array<string, bool>} $agg */

        return [
            'source' => $agg['source'],
            'medium' => $agg['medium'],
            'campaign' => $agg['campaign'],
            'events' => $agg['events'],
            'total' => $agg['total'],
            'unique_users' => count($agg['users']),
        ];
    }

    /**
     * Get top UTM sources by total event count.
     *
     * @return list<array{source: string, total: int, events: array<string, int>}>
     */
    public function topSources(int $limit = 20): array
    {
        $sourcesKey = self::CACHE_PREFIX . 'sources';
        $sources = $this->cache->get($sourcesKey, []);
        /** @var array<string, array{total: int, events: array<string, int>}> $sources */

        // Sort by total descending
        uasort($sources, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $result = [];
        foreach ($sources as $source => $data) {
            $result[] = [
                'source' => $source,
                'total' => $data['total'],
                'events' => $data['events'],
            ];

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Get top UTM campaigns by total event count.
     *
     * @return list<array{campaign: string, total: int}>
     */
    public function topCampaigns(int $limit = 20): array
    {
        $campaignsKey = self::CACHE_PREFIX . 'campaigns';
        $campaigns = $this->cache->get($campaignsKey, []);
        /** @var array<string, int> $campaigns */

        arsort($campaigns);

        $result = [];
        foreach ($campaigns as $campaign => $total) {
            $result[] = ['campaign' => $campaign, 'total' => $total];

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    /**
     * Get UTM source/medium breakdown.
     *
     * @return array<string, array<string, int>>
     */
    public function sourceMediumBreakdown(): array
    {
        $key = self::CACHE_PREFIX . 'source_medium';
        $data = $this->cache->get($key, []);
        /** @var array<string, array<string, int>> $data */

        return $data;
    }

    /**
     * Get a comprehensive summary of UTM aggregation.
     *
     * @return array{enabled: bool, top_sources: list<array{source: string, total: int, events: array<string, int>}>, top_campaigns: list<array{campaign: string, total: int}>, source_medium_breakdown: array<string, array<string, int>>}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'top_sources' => $this->topSources(10),
            'top_campaigns' => $this->topCampaigns(10),
            'source_medium_breakdown' => $this->sourceMediumBreakdown(),
        ];
    }

    /**
     * Check if UTM aggregation is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Clear all UTM aggregation data.
     */
    public function clear(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'sources');
        $this->cache->forget(self::CACHE_PREFIX . 'campaigns');
        $this->cache->forget(self::CACHE_PREFIX . 'source_medium');
    }

    /**
     * Build a cache key from UTM parameters.
     */
    private function buildKey(array $utmParams): string
    {
        $source = $utmParams['utm_source'] ?? '';
        $medium = $utmParams['utm_medium'] ?? '';
        $campaign = $utmParams['utm_campaign'] ?? '';

        $parts = array_filter([
            is_string($source) ? $source : '',
            is_string($medium) ? $medium : '',
            is_string($campaign) ? $campaign : '',
        ]);

        if (empty($parts)) {
            return '';
        }

        return self::CACHE_PREFIX . implode(':', $parts);
    }

    /**
     * Create an empty aggregation record.
     *
     * @return array{source: string|null, medium: string|null, campaign: string|null, term: string|null, content: string|null, events: array<string, int>, total: int, users: array<string, bool>}
     */
    private function emptyAgg(): array
    {
        return [
            'source' => null,
            'medium' => null,
            'campaign' => null,
            'term' => null,
            'content' => null,
            'events' => [],
            'total' => 0,
            'users' => [],
        ];
    }

    /**
     * Aggregate event counts by UTM source.
     */
    private function aggregateBySource(string $source, string $eventName): void
    {
        $key = self::CACHE_PREFIX . 'sources';
        $sources = $this->cache->get($key, []);
        /** @var array<string, array{total: int, events: array<string, int>}> $sources */

        if (! isset($sources[$source])) {
            $sources[$source] = ['total' => 0, 'events' => []];
        }

        $sources[$source]['total']++;
        $sources[$source]['events'][$eventName] = ($sources[$source]['events'][$eventName] ?? 0) + 1;

        $this->cache->put($key, $sources, $this->cacheTtl);
    }
}
