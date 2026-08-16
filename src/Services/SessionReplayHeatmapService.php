<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Session Replay Heatmap Aggregator.
 *
 * Aggregates user interaction data into page-level heatmap zones.
 * Tracks where users click, scroll to, hover over, and spend time within
 * defined page sections. Produces heat intensity scores per zone for
 * product teams to understand user behavior patterns.
 *
 * Page zones are defined by CSS selector or viewport percentage ranges.
 * Each zone accumulates interaction counts and derives a heat score
 * (0–100) relative to the page's total interactions.
 *
 * Use cases:
 * - **CTA optimization**: Which button placement drives the most clicks?
 * - **Content engagement**: Which page sections are actually read?
 * - **Form friction detection**: Where do users abandon forms?
 * - **Layout validation**: Does the new design improve content discovery?
 *
 * Configuration: `zeroboiler.analytics.heatmap`
 *
 * @phpstan-type ZoneData array{zone_id: string, clicks: int, hovers: int, scroll_reaches: int, time_ms: int, heat_score: float|null, impressions: int}
 * @phpstan-type PageHeatmap array{page: string, zones: array<string, ZoneData>, total_interactions: int, hottest_zone: string|null, coldest_zone: string|null, engagement_depth: float, unique_visitors: int, session_id: string|null, recorded_at: string}
 * @phpstan-type HeatmapSummary array{pages: int, total_zones: int, avg_heat_score: float, hottest_page: string|null, engagement_funnel: array{top_zone: string|null, middle_zone: string|null, bottom_zone: string|null}, recommendations: list<string>, computed_at: string}
 *
 * @since 186.0.0
 */
final class SessionReplayHeatmapService
{
    private const CACHE_PREFIX = 'zb_heatmap_';

    private const DEFAULT_TTL = 1800; // 30 minutes

    private const MAX_ZONES_PER_PAGE = 50;

    private const MAX_PAGES = 100;

    private bool $enabled;

    private int $cacheTtl;

    private int $maxZonesPerPage;

    private int $maxPages;

    private float $clickWeight;

    private float $hoverWeight;

    private float $scrollWeight;

    private float $timeWeight;

    private CacheRepository $cache;

    /** @var array<string, array<string, ZoneData>> page → zone_id → ZoneData */
    private array $pageZones = [];

    /** @var array<string, int> page → unique visitor count */
    private array $pageVisitors = [];

    public function __construct(
        private readonly ConfigRepository $config,
        ?CacheRepository $cache = null,
    ): void {
        $this->cache = $cache ?? app(CacheRepository::class);

        $heatmapConfig = $config->get('zeroboiler.analytics.heatmap', []);
        /** @var array{enabled?: bool, cache_ttl?: int, max_zones_per_page?: int, max_pages?: int, click_weight?: float, hover_weight?: float, scroll_weight?: float, time_weight?: float} $heatmapConfig */
        $this->enabled = (bool) ($heatmapConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($heatmapConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->maxZonesPerPage = (int) ($heatmapConfig['max_zones_per_page'] ?? self::MAX_ZONES_PER_PAGE);
        $this->maxPages = (int) ($heatmapConfig['max_pages'] ?? self::MAX_PAGES);
        $this->clickWeight = (float) ($heatmapConfig['click_weight'] ?? 1.0);
        $this->hoverWeight = (float) ($heatmapConfig['hover_weight'] ?? 0.3);
        $this->scrollWeight = (float) ($heatmapConfig['scroll_weight'] ?? 0.5);
        $this->timeWeight = (float) ($heatmapConfig['time_weight'] ?? 0.1);
    }

    /**
     * Record a click interaction in a page zone.
     *
     * @param  string  $page  Page URL or path
     * @param  string  $zoneId  Zone identifier (CSS selector or viewport range)
     * @param  string|null  $sessionId  Optional session identifier for deduplication
     */
    public function recordClick(string $page, string $zoneId, ?string $sessionId = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->ensurePage($page);
        $this->pageZones[$page][$zoneId]['clicks']++;
        $this->pageZones[$page][$zoneId]['impressions']++;
    }

    /**
     * Record a hover interaction in a page zone.
     *
     * @param  string  $page  Page URL or path
     * @param  string  $zoneId  Zone identifier
     * @param  int  $durationMs  Hover duration in milliseconds
     * @param  string|null  $sessionId  Optional session identifier
     */
    public function recordHover(string $page, string $zoneId, int $durationMs = 0, ?string $sessionId = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->ensurePage($page);
        $this->pageZones[$page][$zoneId]['hovers']++;
        $this->pageZones[$page][$zoneId]['time_ms'] += $durationMs;
    }

    /**
     * Record a scroll-reach event for a page zone (viewport percentage range).
     *
     * @param  string  $page  Page URL or path
     * @param  string  $zoneId  Zone identifier (e.g. "viewport-75-100")
     * @param  string|null  $sessionId  Optional session identifier
     */
    public function recordScrollReach(string $page, string $zoneId, ?string $sessionId = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->ensurePage($page);
        $this->pageZones[$page][$zoneId]['scroll_reaches']++;
        $this->pageZones[$page][$zoneId]['impressions']++;
    }

    /**
     * Record time spent in a specific page zone.
     *
     * @param  string  $page  Page URL or path
     * @param  string  $zoneId  Zone identifier
     * @param  int  $durationMs  Time spent in milliseconds
     */
    public function recordDwellTime(string $page, string $zoneId, int $durationMs): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->ensurePage($page);
        $this->pageZones[$page][$zoneId]['time_ms'] += $durationMs;
    }

    /**
     * Record a page impression (unique visitor tracking).
     *
     * @param  string  $page  Page URL or path
     * @param  string|null  $visitorId  Unique visitor identifier
     */
    public function recordImpression(string $page, ?string $visitorId = null): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->pageVisitors[$page] = ($this->pageVisitors[$page] ?? 0) + 1;
    }

    /**
     * Get the computed heatmap for a specific page.
     *
     * @return PageHeatmap
     */
    public function getPageHeatmap(string $page): array
    {
        $this->ensurePage($page);

        $zones = $this->pageZones[$page];
        $totalInteractions = 0;

        // Compute raw interaction scores
        $rawScores = [];
        foreach ($zones as $zoneId => $data) {
            $score = ($data['clicks'] * $this->clickWeight)
                + ($data['hovers'] * $this->hoverWeight)
                + ($data['scroll_reaches'] * $this->scrollWeight)
                + (($data['time_ms'] / 1000) * $this->timeWeight);

            $rawScores[$zoneId] = $score;
            $totalInteractions += $score;
        }

        // Normalize to heat scores (0–100)
        $heatScores = [];
        $maxScore = max(...array_values($rawScores), 1.0);

        foreach ($zones as $zoneId => $data) {
            $zones[$zoneId]['heat_score'] = $totalInteractions > 0
                ? round(($rawScores[$zoneId] / $maxScore) * 100, 1)
                : 0.0;
        }

        // Sort zones by heat score descending
        uasort($zones, fn (array $a, array $b): int =>
            ($b['heat_score'] ?? 0.0) <=> ($a['heat_score'] ?? 0.0)
        );

        // Find hottest and coldest zones
        $zoneList = array_values($zones);
        $hottestZone = ! empty($zoneList) ? $zoneList[0]['zone_id'] : null;
        $coldestZone = ! empty($zoneList)
            ? $zoneList[array_key_last($zoneList)]['zone_id']
            : null;

        // Compute engagement depth (average viewport reach)
        $scrollZones = array_filter($zones, fn (array $z): bool =>
            ($z['scroll_reaches'] ?? 0) > 0 && str_starts_with($z['zone_id'], 'viewport-')
        );

        $avgScrollDepth = 0.0;
        if (! empty($scrollZones)) {
            $depths = array_map(function (array $zone): float {
                $parts = explode('-', str_replace('viewport-', '', $zone['zone_id']));

                return isset($parts[1]) ? (float) $parts[1] : 0.0;
            }, $scrollZones);

            $weightedSum = 0.0;
            $totalWeight = 0;
            foreach ($depths as $i => $depth) {
                $zoneId = array_keys($scrollZones)[$i];
                $weight = $scrollZones[$zoneId]['scroll_reaches'];
                $weightedSum += $depth * $weight;
                $totalWeight += $weight;
            }
            $avgScrollDepth = $totalWeight > 0 ? $weightedSum / $totalWeight : 0.0;
        }

        return [
            'page' => $page,
            'zones' => $zones,
            'total_interactions' => (int) round($totalInteractions),
            'hottest_zone' => $hottestZone,
            'coldest_zone' => $coldestZone,
            'engagement_depth' => round($avgScrollDepth, 2),
            'unique_visitors' => $this->pageVisitors[$page] ?? 0,
            'session_id' => null,
            'recorded_at' => date('c'),
        ];
    }

    /**
     * Get an aggregated summary across all tracked pages.
     *
     * @return HeatmapSummary
     */
    public function getSummary(): array
    {
        $pages = array_keys($this->pageZones);

        $totalZones = 0;
        $allHeatScores = [];
        $pageAvgScores = [];
        $topZoneInteractions = 0;
        $middleZoneInteractions = 0;
        $bottomZoneInteractions = 0;

        foreach ($pages as $page) {
            $heatmap = $this->getPageHeatmap($page);
            $totalZones += count($heatmap['zones']);

            foreach ($heatmap['zones'] as $zone) {
                $score = $zone['heat_score'] ?? 0.0;
                $allHeatScores[] = $score;

                // Classify zone position
                if (str_contains($zone['zone_id'], 'viewport-0')) {
                    $topZoneInteractions += ($zone['clicks'] ?? 0) + ($zone['hovers'] ?? 0);
                } elseif (str_contains($zone['zone_id'], 'viewport-50') || str_contains($zone['zone_id'], 'viewport-75')) {
                    $middleZoneInteractions += ($zone['clicks'] ?? 0) + ($zone['hovers'] ?? 0);
                } elseif (str_contains($zone['zone_id'], 'viewport-100')) {
                    $bottomZoneInteractions += ($zone['clicks'] ?? 0) + ($zone['hovers'] ?? 0);
                }
            }

            $zoneScores = array_map(fn (array $z): float => $z['heat_score'] ?? 0.0, $heatmap['zones']);
            $pageAvgScores[$page] = count($zoneScores) > 0
                ? array_sum($zoneScores) / count($zoneScores)
                : 0.0;
        }

        $avgHeatScore = count($allHeatScores) > 0
            ? round(array_sum($allHeatScores) / count($allHeatScores), 1)
            : 0.0;

        arsort($pageAvgScores);
        $hottestPage = array_key_first($pageAvgScores) ?? null;

        // Recommendations based on patterns
        $recommendations = $this->generateRecommendations($pages, $pageAvgScores, $avgHeatScore);

        return [
            'pages' => count($pages),
            'total_zones' => $totalZones,
            'avg_heat_score' => $avgHeatScore,
            'hottest_page' => $hottestPage,
            'engagement_funnel' => [
                'top_zone' => $topZoneInteractions > 0
                    ? 'viewport-top (' . $topZoneInteractions . ' interactions)'
                    : null,
                'middle_zone' => $middleZoneInteractions > 0
                    ? 'viewport-mid (' . $middleZoneInteractions . ' interactions)'
                    : null,
                'bottom_zone' => $bottomZoneInteractions > 0
                    ? 'viewport-bottom (' . $bottomZoneInteractions . ' interactions)'
                    : null,
            ],
            'recommendations' => $recommendations,
            'computed_at' => date('c'),
        ];
    }

    /**
     * Get a quick health summary of heatmap tracking.
     *
     * @return array{tracked_pages: int, total_zones: int, avg_heat: float, status: string}
     */
    public function quickSummary(): array
    {
        $pages = array_keys($this->pageZones);
        $totalZones = array_reduce(
            $this->pageZones,
            fn (int $carry, array $zones): int => $carry + count($zones),
            0,
        );

        $allScores = [];
        foreach ($this->pageZones as $pageZones) {
            foreach ($pageZones as $zone) {
                $allScores[] = $zone['heat_score'] ?? 0.0;
            }
        }

        $avgHeat = count($allScores) > 0
            ? round(array_sum($allScores) / count($allScores), 1)
            : 0.0;

        $status = 'inactive';
        if (count($pages) > 0 && $totalZones > 0) {
            $status = $avgHeat >= 50 ? 'active_hot' : ($avgHeat >= 20 ? 'active_warm' : 'active_cold');
        }

        return [
            'tracked_pages' => count($pages),
            'total_zones' => $totalZones,
            'avg_heat' => $avgHeat,
            'status' => $status,
        ];
    }

    /**
     * Clear all tracked heatmap data.
     */
    public function clear(): void
    {
        $this->pageZones = [];
        $this->pageVisitors = [];
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get configured interaction weights.
     *
     * @return array{click: float, hover: float, scroll: float, time: float}
     */
    public function getWeights(): array
    {
        return [
            'click' => $this->clickWeight,
            'hover' => $this->hoverWeight,
            'scroll' => $this->scrollWeight,
            'time' => $this->timeWeight,
        ];
    }

    /**
     * Ensure a page exists in the tracking data.
     */
    private function ensurePage(string $page): void
    {
        if (! isset($this->pageZones[$page])) {
            if (count($this->pageZones) >= $this->maxPages) {
                // Evict oldest page (first key)
                array_shift($this->pageZones);
            }
            $this->pageZones[$page] = [];
        }
    }

    /**
     * Generate actionable recommendations based on heatmap data.
     *
     * @param  list<string>  $pages
     * @param  array<string, float>  $pageAvgScores
     * @return list<string>
     */
    private function generateRecommendations(array $pages, array $pageAvgScores, float $avgHeat): array
    {
        $recommendations = [];

        if (empty($pages)) {
            $recommendations[] = 'No pages tracked yet. Enable heatmap tracking on key pages.';
            return $recommendations;
        }

        if ($avgHeat < 20) {
            $recommendations[] = 'Low overall engagement. Consider simplifying page layout and improving CTA visibility.';
        } elseif ($avgHeat > 70) {
            $recommendations[] = 'High engagement detected. Focus on conversion optimization in hot zones.';
        }

        // Check for cold spots (zones with 0 interactions)
        foreach ($this->pageZones as $page => $zones) {
            $coldZones = array_filter($zones, fn (array $z): bool =>
                ($z['heat_score'] ?? 0.0) === 0.0 && ($z['impressions'] ?? 0) > 0
            );

            if (count($coldZones) > 3) {
                $recommendations[] = "Page '{$page}' has " . count($coldZones) . ' ignored zones — consider reducing content or repositioning elements.';
            }
        }

        if (empty($recommendations)) {
            $recommendations[] = 'Engagement patterns look healthy. Continue monitoring for changes.';
        }

        return $recommendations;
    }
}
