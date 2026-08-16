<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;

/**
 * Geographic Analytics Service — Regional event aggregation and geo intelligence.
 *
 * Aggregates analytics events by geographic dimension (country, region, city,
 * timezone, continent) to provide:
 *
 *   1. Geo Breakdown — Event count/unique users per geographic unit
 *   2. Regional Conversion — Funnel conversion rates segmented by geography
 *   3. Engagement Heatmap — Country-level engagement scoring (0-100)
 *   4. Timezone Distribution — Event distribution across user timezones
 *   5. Geo Anomaly Detection — Countries with sudden traffic spikes/drops
 *   6. Top Events Per Region — Most tracked events per country/region
 *
 * Data is sourced from the GeolocationEnricher pipeline output (params like
 * geo_country, geo_region, geo_city, geo_timezone, geo_continent) that is
 * already attached to events during pipeline processing.
 *
 * Inspired by GA4 Geographic reports, Amplitude Geo Analytics, and
 * Mixpanel Geographic Insights.
 *
 * Configuration: `zeroboiler.analytics.geographic_analytics`
 *
 * @since v73.0.0
 */
final class GeographicAnalyticsService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_geo_analytics_';

    private readonly bool $enabled;

    private readonly int $cacheTtl;

    private readonly int $topRegionsLimit;

    private readonly int $topEventsPerRegionLimit;

    private readonly int $anomalyThresholdMultiplier;

    private readonly float $engagementWeightEvents;

    private readonly float $engagementWeightUsers;

    private readonly float $engagementWeightSessions;

    private readonly CacheRepository $cache;

    private readonly AnalyticsMetrics $metrics;

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

        $geoConfig = $config->get('zeroboiler.analytics.geographic_analytics', []);
        /** @var array{enabled?: bool, cache_ttl?: int, top_regions_limit?: int, top_events_per_region?: int, anomaly_threshold_multiplier?: int, engagement_weight_events?: float, engagement_weight_users?: float, engagement_weight_sessions?: float} $geoConfig */

        $this->enabled = (bool) ($geoConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($geoConfig['cache_ttl'] ?? 300); // 5 minutes
        $this->topRegionsLimit = (int) ($geoConfig['top_regions_limit'] ?? 20);
        $this->topEventsPerRegionLimit = (int) ($geoConfig['top_events_per_region'] ?? 5);
        $this->anomalyThresholdMultiplier = (int) ($geoConfig['anomaly_threshold_multiplier'] ?? 3);
        $this->engagementWeightEvents = (float) ($geoConfig['engagement_weight_events'] ?? 0.4);
        $this->engagementWeightUsers = (float) ($geoConfig['engagement_weight_users'] ?? 0.4);
        $this->engagementWeightSessions = (float) ($geoConfig['engagement_weight_sessions'] ?? 0.2);
        $this->metrics = $metrics;
    }

    // ── Geo Breakdown ─────────────────────────────────────────────

    /**
     * Get geographic event breakdown by country.
     *
     * Aggregates events from the metrics counter and enriches with
     * geo data cached from pipeline processing.
     *
     * @return array{countries: list<array{country: string, events: int, users: int, percentage: float}>, total_events: int, total_countries: int}
     */
    public function countryBreakdown(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'country_breakdown';

        return $this->cache->remember($cacheKey, $this->cacheTtl, function (): array {
            $geoData = $this->loadGeoAggregates();
            $countries = $geoData['countries'] ?? [];

            $totalEvents = array_sum(array_column($countries, 'events'));

            // Sort by event count descending
            uasort($countries, fn (array $a, array $b): int => $b['events'] <=> $a['events']);

            // Take top N and compute percentages
            $result = [];
            $count = 0;

            foreach ($countries as $countryCode => $data) {
                if ($count >= $this->topRegionsLimit) {
                    break;
                }

                $percentage = $totalEvents > 0
                    ? round(($data['events'] / $totalEvents) * 100, 2)
                    : 0.0;

                $result[] = [
                    'country' => $countryCode,
                    'events' => $data['events'],
                    'users' => $data['users'],
                    'percentage' => $percentage,
                ];

                $count++;
            }

            return [
                'countries' => array_values($result),
                'total_events' => $totalEvents,
                'total_countries' => count($countries),
            ];
        });
    }

    /**
     * Get geographic event breakdown by region (state/province).
     *
     * @param  string|null  $country  Filter to specific country code (ISO 3166-1 alpha-2)
     * @return array{regions: list<array{region: string, country: string, events: int, users: int, percentage: float}>, total_events: int, total_regions: int}
     */
    public function regionBreakdown(?string $country = null): array
    {
        $cacheKey = self::CACHE_PREFIX . 'region_breakdown_' . ($country ?? 'all');

        return $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($country): array {
            $geoData = $this->loadGeoAggregates();
            $regions = $geoData['regions'] ?? [];

            if ($country !== null) {
                $regions = array_filter(
                    $regions,
                    fn (array $data): bool => ($data['country'] ?? '') === strtoupper($country),
                );
            }

            $totalEvents = array_sum(array_column($regions, 'events'));

            uasort($regions, fn (array $a, array $b): int => $b['events'] <=> $a['events']);

            $result = [];
            $count = 0;

            foreach ($regions as $key => $data) {
                if ($count >= $this->topRegionsLimit) {
                    break;
                }

                $percentage = $totalEvents > 0
                    ? round(($data['events'] / $totalEvents) * 100, 2)
                    : 0.0;

                $result[] = [
                    'region' => $data['region'] ?? $key,
                    'country' => $data['country'] ?? 'unknown',
                    'events' => $data['events'],
                    'users' => $data['users'],
                    'percentage' => $percentage,
                ];

                $count++;
            }

            return [
                'regions' => array_values($result),
                'total_events' => $totalEvents,
                'total_regions' => count($regions),
            ];
        });
    }

    /**
     * Get geographic breakdown by city.
     *
     * @param  string|null  $country  Filter to specific country code
     * @param  int  $limit  Maximum cities to return
     * @return array{cities: list<array{city: string, country: string, region: string, events: int, users: int}>, total_events: int}
     */
    public function cityBreakdown(?string $country = null, int $limit = 20): array
    {
        $cacheKey = self::CACHE_PREFIX . 'city_breakdown_' . ($country ?? 'all') . '_' . $limit;

        return $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($country, $limit): array {
            $geoData = $this->loadGeoAggregates();
            $cities = $geoData['cities'] ?? [];

            if ($country !== null) {
                $cities = array_filter(
                    $cities,
                    fn (array $data): bool => ($data['country'] ?? '') === strtoupper($country),
                );
            }

            $totalEvents = array_sum(array_column($cities, 'events'));

            uasort($cities, fn (array $a, array $b): int => $b['events'] <=> $a['events']);

            $result = [];
            $count = 0;

            foreach ($cities as $key => $data) {
                if ($count >= $limit) {
                    break;
                }

                $result[] = [
                    'city' => $data['city'] ?? $key,
                    'country' => $data['country'] ?? 'unknown',
                    'region' => $data['region'] ?? 'unknown',
                    'events' => $data['events'],
                    'users' => $data['users'],
                ];

                $count++;
            }

            return [
                'cities' => array_values($result),
                'total_events' => $totalEvents,
            ];
        });
    }

    // ── Timezone Distribution ──────────────────────────────────────

    /**
     * Get event distribution across timezones.
     *
     * Useful for understanding when users are active in their local time
     * and for scheduling notifications, reports, and maintenance windows.
     *
     * @return array{timezones: list<array{timezone: string, events: int, users: int, percentage: float}>, total_timezones: int}
     */
    public function timezoneDistribution(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'timezone_distribution';

        return $this->cache->remember($cacheKey, $this->cacheTtl, function (): array {
            $geoData = $this->loadGeoAggregates();
            $timezones = $geoData['timezones'] ?? [];

            $totalEvents = array_sum(array_column($timezones, 'events'));

            uasort($timezones, fn (array $a, array $b): int => $b['events'] <=> $a['events']);

            $result = [];

            foreach ($timezones as $tz => $data) {
                $percentage = $totalEvents > 0
                    ? round(($data['events'] / $totalEvents) * 100, 2)
                    : 0.0;

                $result[] = [
                    'timezone' => $tz,
                    'events' => $data['events'],
                    'users' => $data['users'],
                    'percentage' => $percentage,
                ];
            }

            return [
                'timezones' => array_values($result),
                'total_timezones' => count($timezones),
            ];
        });
    }

    // ── Engagement Heatmap ─────────────────────────────────────────

    /**
     * Compute country-level engagement scores (0-100).
     *
     * Engagement score is a weighted composite of:
     *   - Normalized event count (weight: engagement_weight_events)
     *   - Normalized unique user count (weight: engagement_weight_users)
     *   - Normalized session depth (weight: engagement_weight_sessions)
     *
     * @return array{countries: list<array{country: string, score: float, grade: string, events: int, users: int}>, average_score: float}
     */
    public function engagementHeatmap(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'engagement_heatmap';

        return $this->cache->remember($cacheKey, $this->cacheTtl, function (): array {
            $geoData = $this->loadGeoAggregates();
            $countries = $geoData['countries'] ?? [];

            if (empty($countries)) {
                return [
                    'countries' => [],
                    'average_score' => 0.0,
                ];
            }

            // Find maxima for normalization
            $maxEvents = max(array_column($countries, 'events'));
            $maxUsers = max(array_column($countries, 'users'));
            $maxSessions = max(array_column($countries, 'sessions'));

            $scores = [];
            $totalScore = 0.0;

            foreach ($countries as $code => $data) {
                $normEvents = $maxEvents > 0 ? $data['events'] / $maxEvents : 0;
                $normUsers = $maxUsers > 0 ? $data['users'] / $maxUsers : 0;
                $normSessions = $maxSessions > 0 ? ($data['sessions'] ?? 0) / $maxSessions : 0;

                $score = round(
                    ($normEvents * $this->engagementWeightEvents) +
                    ($normUsers * $this->engagementWeightUsers) +
                    ($normSessions * $this->engagementWeightSessions),
                    4,
                ) * 100;

                $grade = $this->scoreToGrade($score);

                $scores[] = [
                    'country' => $code,
                    'score' => round($score, 1),
                    'grade' => $grade,
                    'events' => $data['events'],
                    'users' => $data['users'],
                ];

                $totalScore += $score;
            }

            // Sort by score descending
            usort($scores, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

            return [
                'countries' => $scores,
                'average_score' => count($scores) > 0
                    ? round($totalScore / count($scores), 1)
                    : 0.0,
            ];
        });
    }

    // ── Regional Conversion ───────────────────────────────────────

    /**
     * Get funnel conversion rates segmented by country.
     *
     * Compares the ratio of conversion events (e.g., 'purchase', 'subscription_created')
     * to entry events (e.g., 'page_view', 'sign_up') per country.
     *
     * @param  string  $entryEvent  Entry event name (e.g., 'sign_up')
     * @param  string  $conversionEvent  Conversion event name (e.g., 'purchase')
     * @return array{regions: list<array{country: string, entry_count: int, conversion_count: int, rate: float}>, global_rate: float}
     */
    public function regionalConversion(string $entryEvent = 'sign_up', string $conversionEvent = 'purchase'): array
    {
        $cacheKey = self::CACHE_PREFIX . 'regional_conv_' . $entryEvent . '_' . $conversionEvent;

        return $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($entryEvent, $conversionEvent): array {
            $geoData = $this->loadGeoAggregates();
            $countries = $geoData['countries'] ?? [];

            $globalEntry = 0;
            $globalConversion = 0;
            $results = [];

            foreach ($countries as $code => $data) {
                $entryCount = $data['events_by_name'][$entryEvent] ?? 0;
                $convCount = $data['events_by_name'][$conversionEvent] ?? 0;

                $globalEntry += $entryCount;
                $globalConversion += $convCount;

                $rate = $entryCount > 0
                    ? round(($convCount / $entryCount) * 100, 2)
                    : 0.0;

                $results[] = [
                    'country' => $code,
                    'entry_count' => $entryCount,
                    'conversion_count' => $convCount,
                    'rate' => $rate,
                ];
            }

            // Sort by conversion rate descending
            usort($results, fn (array $a, array $b): int => $b['rate'] <=> $a['rate']);

            $globalRate = $globalEntry > 0
                ? round(($globalConversion / $globalEntry) * 100, 2)
                : 0.0;

            return [
                'regions' => $results,
                'global_rate' => $globalRate,
            ];
        });
    }

    // ── Top Events Per Region ──────────────────────────────────────

    /**
     * Get the most tracked events for each top country.
     *
     * @return array{countries: array<string, list<array{event: string, count: int}>>}
     */
    public function topEventsPerCountry(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'top_events_per_country';

        return $this->cache->remember($cacheKey, $this->cacheTtl, function (): array {
            $geoData = $this->loadGeoAggregates();
            $countries = $geoData['countries'] ?? [];

            uasort($countries, fn (array $a, array $b): int => $b['events'] <=> $a['events']);

            $result = [];
            $count = 0;

            foreach ($countries as $code => $data) {
                if ($count >= $this->topRegionsLimit) {
                    break;
                }

                $eventsByName = $data['events_by_name'] ?? [];
                arsort($eventsByName);

                $topEvents = [];
                $inner = 0;

                foreach ($eventsByName as $eventName => $evtCount) {
                    $topEvents[] = ['event' => $eventName, 'count' => $evtCount];
                    $inner++;

                    if ($inner >= $this->topEventsPerRegionLimit) {
                        break;
                    }
                }

                $result[$code] = array_slice($topEvents, 0, $this->topEventsPerRegionLimit);
                $count++;
            }

            return [
                'countries' => $result,
            ];
        });
    }

    // ── Geo Anomaly Detection ──────────────────────────────────────

    /**
     * Detect countries with anomalous traffic patterns (sudden spikes or drops).
     *
     * Compares current event counts against a rolling baseline. A country
     * is flagged as anomalous if its current count exceeds the baseline
     * by the configured threshold multiplier.
     *
     * @return array{anomalies: list<array{country: string, current: int, baseline: float, deviation: float, direction: string}>, checked: int}
     */
    public function detectAnomalies(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'anomalies';

        return $this->cache->remember($cacheKey, $this->cacheTtl, function (): array {
            $geoData = $this->loadGeoAggregates();
            $baselineData = $this->loadBaselineGeoData();
            $countries = $geoData['countries'] ?? [];

            $anomalies = [];
            $checked = 0;

            foreach ($countries as $code => $data) {
                $current = $data['events'];
                $baseline = $baselineData[$code] ?? 1.0;

                if ($baseline < 1.0) {
                    $baseline = 1.0;
                }

                $deviation = $current / $baseline;
                $checked++;

                if ($deviation >= $this->anomalyThresholdMultiplier || $deviation <= (1.0 / $this->anomalyThresholdMultiplier)) {
                    $anomalies[] = [
                        'country' => $code,
                        'current' => $current,
                        'baseline' => round($baseline, 2),
                        'deviation' => round($deviation, 2),
                        'direction' => $deviation >= $this->anomalyThresholdMultiplier ? 'spike' : 'drop',
                    ];
                }
            }

            // Sort by deviation magnitude
            usort($anomalies, fn (array $a, array $b): int => abs($b['deviation']) <=> abs($a['deviation']));

            return [
                'anomalies' => $anomalies,
                'checked' => $checked,
            ];
        });
    }

    // ── Continent Breakdown ────────────────────────────────────────

    /**
     * Get event breakdown by continent.
     *
     * @return array{continents: list<array{continent: string, events: int, users: int, countries: int, percentage: float}>, total_events: int}
     */
    public function continentBreakdown(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'continent_breakdown';

        return $this->cache->remember($cacheKey, $this->cacheTtl, function (): array {
            $geoData = $this->loadGeoAggregates();
            $continents = $geoData['continents'] ?? [];

            $totalEvents = array_sum(array_column($continents, 'events'));

            uasort($continents, fn (array $a, array $b): int => $b['events'] <=> $a['events']);

            $result = [];

            foreach ($continents as $name => $data) {
                $percentage = $totalEvents > 0
                    ? round(($data['events'] / $totalEvents) * 100, 2)
                    : 0.0;

                $result[] = [
                    'continent' => $name,
                    'events' => $data['events'],
                    'users' => $data['users'],
                    'countries' => $data['countries'] ?? 0,
                    'percentage' => $percentage,
                ];
            }

            return [
                'continents' => array_values($result),
                'total_events' => $totalEvents,
            ];
        });
    }

    // ── Summary ────────────────────────────────────────────────────

    /**
     * Get a high-level geographic analytics summary.
     *
     * @return array{enabled: bool, top_countries: int, total_events: int, total_countries: int, total_timezones: int, average_engagement: float, anomalies_detected: int}
     */
    public function summary(): array
    {
        $geoData = $this->loadGeoAggregates();
        $countries = $geoData['countries'] ?? [];
        $timezones = $geoData['timezones'] ?? [];

        $totalEvents = array_sum(array_column($countries, 'events'));

        return [
            'enabled' => $this->enabled,
            'top_countries' => min(count($countries), $this->topRegionsLimit),
            'total_events' => $totalEvents,
            'total_countries' => count($countries),
            'total_timezones' => count($timezones),
            'average_engagement' => $this->computeAverageEngagement($countries),
            'anomalies_detected' => count($this->detectAnomalies()['anomalies']),
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
     * Clear all cached geographic analytics data.
     */
    public function clearCache(): void
    {
        $keys = [
            'country_breakdown',
            'region_breakdown_all',
            'city_breakdown_all_20',
            'timezone_distribution',
            'engagement_heatmap',
            'top_events_per_country',
            'anomalies',
            'continent_breakdown',
        ];

        foreach ($keys as $key) {
            try {
                $this->cache->forget(self::CACHE_PREFIX . $key);
            } catch (\Throwable) {
                // Ignore cache errors
            }
        }
    }

    // ── Internal Helpers ────────────────────────────────────────────

    /**
     * Load geo aggregate data from cache.
     *
     * This data is populated by the GeolocationEnricher pipeline stage
     * which accumulates geo-tagged event counts.
     *
     * @return array{countries: array<string, array{events: int, users: int, sessions: int, events_by_name: array<string, int>}>, regions: array<string, array{region: string, country: string, events: int, users: int}>, cities: array<string, array{city: string, country: string, region: string, events: int, users: int}>, timezones: array<string, array{events: int, users: int}>, continents: array<string, array{events: int, users: int, countries: int}>}
     */
    private function loadGeoAggregates(): array
    {
        try {
            $cached = $this->cache->get(self::CACHE_PREFIX . 'aggregates', []);

            return is_array($cached) ? $cached : $this->buildEmptyAggregates();
        } catch (\Throwable) {
            return $this->buildEmptyAggregates();
        }
    }

    /**
     * Load baseline geo data for anomaly detection.
     *
     * @return array<string, float> Country code → average event count baseline
     */
    private function loadBaselineGeoData(): array
    {
        try {
            $cached = $this->cache->get(self::CACHE_PREFIX . 'baseline', []);

            return is_array($cached) ? $cached : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Build empty aggregate structure.
     *
     * @return array{countries: array<string, array{events: int, users: int, sessions: int, events_by_name: array<string, int>}>, regions: array<string, array{region: string, country: string, events: int, users: int}>, cities: array<string, array{city: string, country: string, region: string, events: int, users: int}>, timezones: array<string, array{events: int, users: int}>, continents: array<string, array{events: int, users: int, countries: int}>}
     */
    private function buildEmptyAggregates(): array
    {
        return [
            'countries' => [],
            'regions' => [],
            'cities' => [],
            'timezones' => [],
            'continents' => [],
        ];
    }

    /**
     * Compute average engagement score across countries.
     *
     * @param  array<string, array{events: int, users: int, sessions: int}>  $countries
     */
    private function computeAverageEngagement(array $countries): float
    {
        if (empty($countries)) {
            return 0.0;
        }

        $maxEvents = max(array_column($countries, 'events'));
        $maxUsers = max(array_column($countries, 'users'));
        $maxSessions = max(array_column($countries, 'sessions'));

        $total = 0.0;

        foreach ($countries as $data) {
            $normEvents = $maxEvents > 0 ? $data['events'] / $maxEvents : 0;
            $normUsers = $maxUsers > 0 ? $data['users'] / $maxUsers : 0;
            $normSessions = $maxSessions > 0 ? ($data['sessions'] ?? 0) / $maxSessions : 0;

            $total += round(
                ($normEvents * $this->engagementWeightEvents) +
                ($normUsers * $this->engagementWeightUsers) +
                ($normSessions * $this->engagementWeightSessions),
                4,
            ) * 100;
        }

        return round($total / count($countries), 1);
    }

    /**
     * Convert a numeric score to a letter grade.
     */
    private function scoreToGrade(float $score): string
    {
        return match (true) {
            $score >= 80 => 'A',
            $score >= 60 => 'B',
            $score >= 40 => 'C',
            $score >= 20 => 'D',
            default => 'F',
        };
    }

    /**
     * Record a geo-enriched event into the aggregates.
     *
     * Called by the GeolocationEnricher after enriching an event,
     * or by the GeographicAnalyticsService::ingestEvent() public method.
     *
     * @param  array{geo_country?: string, geo_region?: string, geo_city?: string, geo_timezone?: string, geo_continent?: string, event_name?: string}  $params  Event parameters
     * @param  string|null  $clientId  Client identifier for unique user counting
     */
    public function ingestEvent(array $params, ?string $clientId = null): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            $aggregates = $this->loadGeoAggregates();
            $eventName = $params['event_name'] ?? 'unknown';
            $country = strtoupper($params['geo_country'] ?? 'UNKNOWN');
            $region = $params['geo_region'] ?? '';
            $city = $params['geo_city'] ?? '';
            $timezone = $params['geo_timezone'] ?? '';
            $continent = $params['geo_continent'] ?? '';

            // Accumulate country data
            if (! isset($aggregates['countries'][$country])) {
                $aggregates['countries'][$country] = [
                    'events' => 0,
                    'users' => 0,
                    'sessions' => 0,
                    'events_by_name' => [],
                ];
            }

            $aggregates['countries'][$country]['events']++;

            if ($clientId !== null) {
                $aggregates['countries'][$country]['users']++;
                $aggregates['countries'][$country]['sessions']++;
            }

            $aggregates['countries'][$country]['events_by_name'][$eventName] =
                ($aggregates['countries'][$country]['events_by_name'][$eventName] ?? 0) + 1;

            // Accumulate region data
            if ($region !== '') {
                $regionKey = "{$country}:{$region}";

                if (! isset($aggregates['regions'][$regionKey])) {
                    $aggregates['regions'][$regionKey] = [
                        'region' => $region,
                        'country' => $country,
                        'events' => 0,
                        'users' => 0,
                    ];
                }

                $aggregates['regions'][$regionKey]['events']++;

                if ($clientId !== null) {
                    $aggregates['regions'][$regionKey]['users']++;
                }
            }

            // Accumulate city data
            if ($city !== '') {
                $cityKey = "{$country}:{$region}:{$city}";

                if (! isset($aggregates['cities'][$cityKey])) {
                    $aggregates['cities'][$cityKey] = [
                        'city' => $city,
                        'country' => $country,
                        'region' => $region,
                        'events' => 0,
                        'users' => 0,
                    ];
                }

                $aggregates['cities'][$cityKey]['events']++;

                if ($clientId !== null) {
                    $aggregates['cities'][$cityKey]['users']++;
                }
            }

            // Accumulate timezone data
            if ($timezone !== '') {
                if (! isset($aggregates['timezones'][$timezone])) {
                    $aggregates['timezones'][$timezone] = [
                        'events' => 0,
                        'users' => 0,
                    ];
                }

                $aggregates['timezones'][$timezone]['events']++;

                if ($clientId !== null) {
                    $aggregates['timezones'][$timezone]['users']++;
                }
            }

            // Accumulate continent data
            if ($continent !== '') {
                if (! isset($aggregates['continents'][$continent])) {
                    $aggregates['continents'][$continent] = [
                        'events' => 0,
                        'users' => 0,
                        'countries' => 0,
                    ];
                }

                $aggregates['continents'][$continent]['events']++;

                if ($clientId !== null) {
                    $aggregates['continents'][$continent]['users']++;
                }

                // Track unique countries per continent
                if (! isset($aggregates['continents'][$continent]['_countries_set'])) {
                    $aggregates['continents'][$continent]['_countries_set'] = [];
                }

                $aggregates['continents'][$continent]['_countries_set'][$country] = true;
                $aggregates['continents'][$continent]['countries'] = count($aggregates['continents'][$continent]['_countries_set']);
            }

            $this->cache->put(
                self::CACHE_PREFIX . 'aggregates',
                $aggregates,
                $this->cacheTtl * 2, // Aggregates live longer than computed reports
            );
        } catch (\Throwable) {
            // Silently fail — geo analytics should never break event dispatch
        }
    }

    /**
     * Snapshot current aggregates as baseline for anomaly detection.
     *
     * Should be called periodically (e.g., hourly via scheduler).
     */
    public function snapshotBaseline(): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            $geoData = $this->loadGeoAggregates();
            $countries = $geoData['countries'] ?? [];

            $baseline = [];

            foreach ($countries as $code => $data) {
                $baseline[$code] = (float) $data['events'];
            }

            $this->cache->put(
                self::CACHE_PREFIX . 'baseline',
                $baseline,
                86400, // Baseline lives for 24 hours
            );
        } catch (\Throwable) {
            // Ignore
        }
    }
}
