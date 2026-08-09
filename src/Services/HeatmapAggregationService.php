<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\AnalyticsManager;

/**
 * Click Heatmap Aggregation Service.
 *
 * Aggregates click event coordinates by page URL for heatmap visualization.
 * Stores click data in the cache for configurable TTL, grouped by URL path
 * and viewport dimensions.
 *
 * Provides aggregated data suitable for rendering client-side heatmaps
 * (e.g., using canvas overlays or third-party heatmap libraries).
 *
 * Privacy-first: coordinates are bucketed into grid cells (default 50px)
 * to prevent tracking exact click positions, complying with GDPR data
 * minimization principles.
 */
final class HeatmapAggregationService
{
    private CacheRepository $cache;

    private int $gridSize;

    private int $cacheTtl;

    private int $maxClicksPerUrl;

    private string $cachePrefix;

    private bool $enabled;

    /**
     * @param  CacheRepository  $cache
     * @param  int  $gridSize  Grid cell size in pixels (default 50)
     * @param  int  $cacheTtl  Cache TTL in seconds (default 86400 = 24h)
     * @param  int  $maxClicksPerUrl  Max click data points per URL (default 10000)
     * @param  string  $cachePrefix  Cache key prefix
     */
    public function __construct(
        CacheRepository $cache,
        int $gridSize = 50,
        int $cacheTtl = 86400,
        int $maxClicksPerUrl = 10000,
        string $cachePrefix = 'zb_heatmap_',
        bool $enabled = true,
    ): void {
        $this->cache = $cache;
        $this->gridSize = $gridSize;
        $this->cacheTtl = $cacheTtl;
        $this->maxClicksPerUrl = $maxClicksPerUrl;
        $this->cachePrefix = $cachePrefix;
        $this->enabled = $enabled;
    }

    /**
     * Record a click event for heatmap aggregation.
     *
     * @param  string  $url  Page URL path (e.g. '/pricing')
     * @param  int  $x  Click X coordinate
     * @param  int  $y  Click Y coordinate
     * @param  int|null  $viewportWidth  Viewport width in pixels
     * @param  string|null  $element  Target element tag or selector
     * @param  string|null  $clientId  Client tracking ID
     */
    public function recordClick(
        string $url,
        int $x,
        int $y,
        ?int $viewportWidth = null,
        ?string $element = null,
        ?string $clientId = null,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $normalizedUrl = $this->normalizeUrl($url);
        $gridKey = $this->bucketCoordinates($x, $y);
        $cacheKey = $this->cachePrefix . md5($normalizedUrl);

        /** @var array{grid: array<string, int>, total: int, element_counts: array<string, int>}|null $data */
        $data = $this->cache->get($cacheKey);

        if ($data === null) {
            $data = [
                'grid' => [],
                'total' => 0,
                'element_counts' => [],
                'viewport_width' => $viewportWidth,
                'grid_size' => $this->gridSize,
            ];
        }

        // Increment grid cell count
        if (! isset($data['grid'][$gridKey])) {
            $data['grid'][$gridKey] = 0;
        }
        $data['grid'][$gridKey]++;

        // Track element type counts
        if ($element !== null && $element !== '') {
            $tagName = $this->normalizeElement($element);
            if (! isset($data['element_counts'][$tagName])) {
                $data['element_counts'][$tagName] = 0;
            }
            $data['element_counts'][$tagName]++;
        }

        $data['total']++;

        // Enforce max clicks per URL
        if ($data['total'] > $this->maxClicksPerUrl) {
            return;
        }

        $this->cache->put($cacheKey, $data, $this->cacheTtl);
    }

    /**
     * Get aggregated heatmap data for a specific URL.
     *
     * @param  string  $url  Page URL path
     * @return array{grid: array<string, int>, total: int, element_counts: array<string, int>, grid_size: int, heat_zones: list<array{cell: string, x: int, y: int, count: int, intensity: float>}, hotspots: list<array{cell: string, x: int, y: int, count: int}>}|null
     */
    public function getHeatmapData(string $url): ?array
    {
        $normalizedUrl = $this->normalizeUrl($url);
        $cacheKey = $this->cachePrefix . md5($normalizedUrl);

        /** @var array{grid: array<string, int>, total: int, element_counts: array<string, int>, viewport_width: int|null, grid_size: int}|null $data */
        $data = $this->cache->get($cacheKey);

        if ($data === null || ($data['total'] ?? 0) === 0) {
            return null;
        }

        // Calculate heat intensity (0-1 normalized)
        $maxCount = max($data['grid']);

        $heatZones = [];
        $hotspots = [];

        foreach ($data['grid'] as $cell => $count) {
            [$x, $y] = explode(':', $cell);
            $intensity = $maxCount > 0 ? $count / $maxCount : 0;

            $heatZones[] = [
                'cell' => $cell,
                'x' => (int) $x,
                'y' => (int) $y,
                'count' => $count,
                'intensity' => round($intensity, 4),
            ];

            // Hotspots are cells with >70% intensity
            if ($intensity > 0.7) {
                $hotspots[] = [
                    'cell' => $cell,
                    'x' => (int) $x,
                    'y' => (int) $y,
                    'count' => $count,
                ];
            }
        }

        // Sort heat zones by intensity descending
        usort($heatZones, fn (array $a, array $b): int => $b['intensity'] <=> $a['intensity']);

        return [
            'grid' => $data['grid'],
            'total' => $data['total'],
            'element_counts' => $data['element_counts'],
            'grid_size' => $data['grid_size'],
            'heat_zones' => $heatZones,
            'hotspots' => $hotspots,
        ];
    }

    /**
     * Get a list of all URLs that have heatmap data.
     *
     * @return list<array{url: string, total_clicks: int}>
     */
    public function getTrackedUrls(): array
    {
        // This is a best-effort approach — cache doesn't support listing by prefix efficiently
        // In production, use a separate index key
        $indexKey = $this->cachePrefix . 'index';

        /** @var array<string, int>|null $index */
        $index = $this->cache->get($indexKey);

        if ($index === null) {
            return [];
        }

        $urls = [];
        foreach ($index as $url => $count) {
            $urls[] = [
                'url' => $url,
                'total_clicks' => $count,
            ];
        }

        usort($urls, fn (array $a, array $b): int => $b['total_clicks'] <=> $a['total_clicks']);

        return $urls;
    }

    /**
     * Clear heatmap data for a specific URL.
     */
    public function clearUrl(string $url): bool
    {
        $normalizedUrl = $this->normalizeUrl($url);
        $cacheKey = $this->cachePrefix . md5($normalizedUrl);

        return $this->cache->forget($cacheKey);
    }

    /**
     * Get summary statistics across all tracked URLs.
     *
     * @return array{total_urls: int, total_clicks: int, avg_clicks_per_url: float|null, top_element_types: array<string, int>}
     */
    public function getSummary(): array
    {
        $urls = $this->getTrackedUrls();
        $totalClicks = array_sum(array_column($urls, 'total_clicks'));

        return [
            'total_urls' => count($urls),
            'total_clicks' => $totalClicks,
            'avg_clicks_per_url' => count($urls) > 0 ? round($totalClicks / count($urls), 2) : null,
        ];
    }

    /**
     * Normalize a URL for consistent cache key generation.
     */
    private function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);

        $path = $parsed['path'] ?? '/';
        $query = $parsed['query'] ?? '';

        // Include query params in the key (they affect page content)
        return $query !== '' ? "{$path}?{$query}" : $path;
    }

    /**
     * Bucket coordinates into grid cells for privacy.
     *
     * Returns "x:y" string where x and y are the top-left corner of the grid cell.
     */
    private function bucketCoordinates(int $x, int $y): string
    {
        $gridX = (int) floor($x / $this->gridSize) * $this->gridSize;
        $gridY = (int) floor($y / $this->gridSize) * $this->gridSize;

        return "{$gridX}:{$gridY}";
    }

    /**
     * Normalize an HTML element to its tag name.
     */
    private function normalizeElement(string $element): string
    {
        // Extract tag name from CSS selector or tag
        if (str_starts_with($element, '<')) {
            preg_match('/<(\w+)/', $element, $matches);

            return $matches[1] ?? 'unknown';
        }

        // Extract first part of CSS selector
        $parts = preg_split('/[\s>+~]/', $element, 2);

        return $parts[0] ?? 'unknown';
    }
}
