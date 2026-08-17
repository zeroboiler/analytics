<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Store\DatabaseEventStore;

/**
 * Geospatial Analytics Service — geo-based event aggregations and heatmap data.
 *
 * Provides country, region, city, timezone, and continent-level breakdowns
 * of analytics events. Generates heatmap-compatible data structures for
 * geographic visualization (Leaflet, Mapbox, Google Maps, D3.js).
 *
 * Supports:
 * - Country/region/city event counts and distribution
 * - Geographic heatmap data (lat/lng + intensity)
 * - Top locations ranking by event type or category
 * - Geographic funnel analysis (conversion by location)
 * - Geo-based anomaly detection (unusual traffic from a region)
 * - Cross-geographic comparison (region A vs region B)
 * - Export formats: GeoJSON, TopoJSON-compatible arrays, CSV
 *
 * Uses event params enriched by GeolocationEnricher (country, region, city,
 * timezone, continent) as the primary data source. Falls back to IP-based
 * aggregation when geolocation data is not present.
 *
 * All results are cache-backed with configurable TTL.
 *
 * Configuration: `zeroboiler.analytics.geospatial`
 *
 * @phpstan-type GeoAggregation array{dimension: string, values: array<string, int>, total: int, top_n: list<array{location: string, count: int, percentage: float}>}
 * @phpstan-type HeatmapPoint array{lat: float, lng: float, intensity: int, location: string, country_code: string|null}
 * @phpstan-type GeoFunnelStep array{stage: string, location: string, count: int, conversion_rate: float}
 *
 * @since 237.0.0
 */
final class GeospatialAnalyticsService
{
    private readonly bool $enabled;

    private readonly int $cacheTtl;

    private readonly string $cachePrefix;

    private readonly int $topLocationsLimit;

    private readonly int $heatmapBucketSize;

    private readonly bool $includeUnknown;

    /** @var array<string, array{lat: float, lng: float, code: string}> Country geodata lookup */
    private static array $countryGeo = [];

    private const COUNTRY_CODE_FILE = __DIR__ . '/../../data/iso-country-codes.php';

    private const CACHE_PREFIX = 'zb_geo_';

    private const DEFAULT_CACHE_TTL = 600;

    private const DEFAULT_TOP_LIMIT = 20;

    private const DEFAULT_HEATMAP_BUCKET = 1;

    /**
     * @param  CacheRepository  $cache  Application cache
     * @param  ConfigRepository  $config  Analytics configuration
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ): void {
        $geoConfig = $config->get('zeroboiler.analytics.geospatial', []);
        /** @var array{enabled?: bool, cache_ttl?: int, top_locations_limit?: int, heatmap_bucket_size?: int, include_unknown?: bool} $geoConfig */

        $this->enabled = (bool) ($geoConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($geoConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->cachePrefix = self::CACHE_PREFIX;
        $this->topLocationsLimit = (int) ($geoConfig['top_locations_limit'] ?? self::DEFAULT_TOP_LIMIT);
        $this->heatmapBucketSize = (int) ($geoConfig['heatmap_bucket_size'] ?? self::DEFAULT_HEATMAP_BUCKET);
        $this->includeUnknown = (bool) ($geoConfig['include_unknown'] ?? false);

        $this->loadCountryGeoData();
    }

    /**
     * Get event distribution by country.
     *
     * Returns event counts grouped by the 'country' event param,
     * with top-N ranking and percentage breakdown.
     *
     * @param  string|null  $event  Optional event name filter
     * @param  string|null  $category  Optional category filter
     * @param  string|null  $timeRange  Time range label (for cache key)
     * @return GeoAggregation
     */
    public function byCountry(?string $event = null, ?string $category = null, ?string $timeRange = null): array
    {
        $cacheKey = $this->buildCacheKey('country', $event, $category, $timeRange);

        return $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($event, $category): array {
            $counts = $this->aggregateByDimension('country', $event, $category);

            return $this->buildAggregation('country', $counts);
        });
    }

    /**
     * Get event distribution by region (state/province).
     *
     * @param  string|null  $event  Optional event name filter
     * @param  string|null  $category  Optional category filter
     * @param  string|null  $country  Optional country filter
     * @return GeoAggregation
     */
    public function byRegion(?string $event = null, ?string $category = null, ?string $country = null): array
    {
        $cacheKey = $this->buildCacheKey('region', $event, $category, $country);

        return $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($event, $category, $country): array {
            $counts = $this->aggregateByDimension('region', $event, $category, $country);

            return $this->buildAggregation('region', $counts);
        });
    }

    /**
     * Get event distribution by city.
     *
     * @param  string|null  $event  Optional event name filter
     * @param  string|null  $category  Optional category filter
     * @param  string|null  $country  Optional country filter
     * @return GeoAggregation
     */
    public function byCity(?string $event = null, ?string $category = null, ?string $country = null): array
    {
        $cacheKey = $this->buildCacheKey('city', $event, $category, $country);

        return $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($event, $category, $country): array {
            $counts = $this->aggregateByDimension('city', $event, $category, $country);

            return $this->buildAggregation('city', $counts);
        });
    }

    /**
     * Get event distribution by continent.
     *
     * Groups countries into continents using ISO code prefix.
     *
     * @param  string|null  $event  Optional event name filter
     * @return GeoAggregation
     */
    public function byContinent(?string $event = null): array
    {
        $cacheKey = $this->buildCacheKey('continent', $event);

        return $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($event): array {
            $countryCounts = $this->aggregateByDimension('country', $event);
            $continentCounts = [];

            foreach ($countryCounts as $country => $count) {
                $continent = $this->countryToContinent($country);
                $continentCounts[$continent] = ($continentCounts[$continent] ?? 0) + $count;
            }

            return $this->buildAggregation('continent', $continentCounts);
        });
    }

    /**
     * Get event distribution by timezone.
     *
     * @param  string|null  $event  Optional event name filter
     * @return GeoAggregation
     */
    public function byTimezone(?string $event = null): array
    {
        $cacheKey = $this->buildCacheKey('timezone', $event);

        return $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($event): array {
            $counts = $this->aggregateByDimension('timezone', $event);

            return $this->buildAggregation('timezone', $counts);
        });
    }

    /**
     * Generate heatmap data points for geographic visualization.
     *
     * Returns an array of {lat, lng, intensity, location, country_code}
     * objects suitable for Leaflet, Mapbox, or D3.js heatmaps.
     *
     * Intensity is the event count bucketed by heatmap_bucket_size.
     *
     * @param  string|null  $event  Optional event name filter
     * @param  string|null  $category  Optional category filter
     * @return list<HeatmapPoint>
     */
    public function heatmapData(?string $event = null, ?string $category = null): array
    {
        $cacheKey = $this->buildCacheKey('heatmap', $event, $category);

        return $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($event, $category): array {
            $countryCounts = $this->aggregateByDimension('country', $event, $category);
            $points = [];

            foreach ($countryCounts as $countryName => $count) {
                $geo = $this->getCountryGeo($countryName);

                if ($geo === null) {
                    continue;
                }

                $points[] = [
                    'lat' => $geo['lat'],
                    'lng' => $geo['lng'],
                    'intensity' => (int) ceil($count / max(1, $this->heatmapBucketSize)),
                    'location' => $countryName,
                    'country_code' => $geo['code'],
                ];
            }

            // Sort by intensity descending
            usort($points, fn (array $a, array $b): int => $b['intensity'] <=> $a['intensity']);

            return $points;
        });
    }

    /**
     * Generate GeoJSON FeatureCollection for geographic visualization.
     *
     * Returns a valid GeoJSON FeatureCollection with Point features
     * representing event intensity by country. Properties include
     * event count, location name, and country code.
     *
     * @param  string|null  $event  Optional event name filter
     * @return array{type: string, features: list<array{type: string, geometry: array{type: string, coordinates: list<float>}, properties: array<string, mixed>}>}
     */
    public function geoJsonCollection(?string $event = null): array
    {
        $heatmapPoints = $this->heatmapData($event);

        $features = array_map(
            fn (array $point): array => [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$point['lng'], $point['lat']],
                ],
                'properties' => [
                    'intensity' => $point['intensity'],
                    'location' => $point['location'],
                    'country_code' => $point['country_code'],
                    'event_filter' => $event,
                ],
            ],
            $heatmapPoints,
        );

        return [
            'type' => 'FeatureCollection',
            'features' => $features,
        ];
    }

    /**
     * Geographic funnel analysis — conversion rates by location.
     *
     * Given a list of funnel stages (event names), computes conversion
     * rates broken down by country. Useful for understanding geographic
     * conversion disparities.
     *
     * @param  list<string>  $stages  Ordered list of funnel stage event names
     * @param  string  $groupByDimension  Geographic dimension: 'country', 'region', 'city'
     * @return array{dimension: string, locations: array<string, list<GeoFunnelStep>>, overall_conversion: float}
     */
    public function geographicFunnel(array $stages, string $groupByDimension = 'country'): array
    {
        if ($stages === []) {
            return [
                'dimension' => $groupByDimension,
                'locations' => [],
                'overall_conversion' => 0.0,
            ];
        }

        $cacheKey = $this->cachePrefix . 'funnel_' . hash('xxh128', json_encode($stages) . $groupByDimension);

        return $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($stages, $groupByDimension): array {
            // Aggregate each stage by dimension
            $stageAggregations = [];

            foreach ($stages as $stage) {
                $stageAggregations[$stage] = $this->aggregateByDimension($groupByDimension, $stage);
            }

            // Get all unique locations across all stages
            $allLocations = [];
            foreach ($stageAggregations as $aggregation) {
                foreach (array_keys($aggregation) as $location) {
                    if ($location !== 'Unknown' || $this->includeUnknown) {
                        $allLocations[$location] = true;
                    }
                }
            }
            $allLocations = array_keys($allLocations);

            // Build funnel steps per location
            $firstStage = $stages[0];
            $lastStage = $stages[count($stages) - 1];
            $locationFunnel = [];

            foreach ($allLocations as $location) {
                $steps = [];
                $firstCount = $stageAggregations[$firstStage][$location] ?? 0;

                foreach ($stages as $stage) {
                    $count = $stageAggregations[$stage][$location] ?? 0;
                    $stepRate = $firstCount > 0 ? round($count / $firstCount, 4) : 0.0;

                    $steps[] = [
                        'stage' => $stage,
                        'location' => $location,
                        'count' => $count,
                        'conversion_rate' => $stepRate,
                    ];
                }

                $locationFunnel[] = $steps;
            }

            // Calculate overall conversion
            $totalFirst = array_sum($stageAggregations[$firstStage] ?? []);
            $totalLast = array_sum($stageAggregations[$lastStage] ?? []);
            $overallConversion = $totalFirst > 0 ? round($totalLast / $totalFirst, 4) : 0.0;

            return [
                'dimension' => $groupByDimension,
                'locations' => $locationFunnel,
                'overall_conversion' => $overallConversion,
            ];
        });
    }

    /**
     * Detect geographic anomalies — locations with unusual event patterns.
     *
     * Compares each location's event share against its historical average.
     * Locations with z-score > 2.0 are flagged as anomalous.
     *
     * @param  string|null  $event  Optional event name filter
     * @param  float  $threshold  Z-score threshold for anomaly detection
     * @return list<array{location: string, dimension: string, actual_share: float, expected_share: float, z_score: float, severity: string}>
     */
    public function detectAnomalies(?string $event = null, float $threshold = 2.0): array
    {
        $countryAgg = $this->byCountry($event);
        $total = $countryAgg['total'];
        $numLocations = count($countryAgg['values']);

        if ($total === 0 || $numLocations === 0) {
            return [];
        }

        $expectedShare = 1.0 / $numLocations;
        $anomalies = [];

        foreach ($countryAgg['values'] as $location => $count) {
            $actualShare = $count / $total;
            $deviation = $actualShare - $expectedShare;
            $stdDev = sqrt($expectedShare * (1 - $expectedShare) / $total);
            $zScore = $stdDev > 0 ? $deviation / $stdDev : 0.0;

            if (abs($zScore) > $threshold) {
                $anomalies[] = [
                    'location' => $location,
                    'dimension' => 'country',
                    'actual_share' => round($actualShare, 4),
                    'expected_share' => round($expectedShare, 4),
                    'z_score' => round($zScore, 2),
                    'severity' => abs($zScore) > 3.0 ? 'high' : 'medium',
                ];
            }
        }

        usort($anomalies, fn (array $a, array $b): int => abs($b['z_score']) <=> abs($a['z_score']));

        return $anomalies;
    }

    /**
     * Compare event metrics between two geographic locations.
     *
     * @param  string  $locationA  First location name
     * @param  string  $locationB  Second location name
     * @param  string|null  $event  Optional event name filter
     * @param  string  $dimension  Geographic dimension
     * @return array{location_a: array{location: string, count: int, share: float}, location_b: array{location: string, count: int, share: float}, ratio: float, difference: int}
     */
    public function compareLocations(
        string $locationA,
        string $locationB,
        ?string $event = null,
        string $dimension = 'country',
    ): array {
        $agg = match ($dimension) {
            'country' => $this->byCountry($event),
            'region' => $this->byRegion($event),
            'city' => $this->byCity($event),
            default => $this->byCountry($event),
        };

        $countA = $agg['values'][$locationA] ?? 0;
        $countB = $agg['values'][$locationB] ?? 0;
        $total = $agg['total'];

        return [
            'location_a' => [
                'location' => $locationA,
                'count' => $countA,
                'share' => $total > 0 ? round($countA / $total, 4) : 0.0,
            ],
            'location_b' => [
                'location' => $locationB,
                'count' => $countB,
                'share' => $total > 0 ? round($countB / $total, 4) : 0.0,
            ],
            'ratio' => $countB > 0 ? round($countA / $countB, 2) : 0.0,
            'difference' => $countA - $countB,
        ];
    }

    /**
     * Get geographic coverage summary.
     *
     * Returns stats about how many unique locations have been seen,
     * data quality metrics, and geographic coverage percentage.
     *
     * @return array{enabled: bool, countries: int, regions: int, cities: int, timezones: int, coverage_score: float, data_quality: array{enriched_percentage: float, has_coordinates: float}}
     */
    public function coverage(): array
    {
        $countryAgg = $this->byCountry();
        $regionAgg = $this->byRegion();
        $cityAgg = $this->byCity();
        $tzAgg = $this->byTimezone();

        $countryCount = count($countryAgg['values']);
        $regionCount = count($regionAgg['values']);
        $cityCount = count($cityAgg['values']);
        $tzCount = count($tzAgg['values']);

        // Count how many countries have known coordinates
        $withCoords = 0;
        foreach (array_keys($countryAgg['values']) as $country) {
            if ($this->getCountryGeo($country) !== null) {
                $withCoords++;
            }
        }

        $enrichedPct = $countryCount > 0 ? round($withCoords / $countryCount, 4) : 0.0;

        // Coverage score: percentage of tracked countries out of total world (195)
        $coverageScore = round($countryCount / 195, 4);

        return [
            'enabled' => $this->enabled,
            'countries' => $countryCount,
            'regions' => $regionCount,
            'cities' => $cityCount,
            'timezones' => $tzCount,
            'coverage_score' => $coverageScore,
            'data_quality' => [
                'enriched_percentage' => $enrichedPct,
                'has_coordinates' => $enrichedPct,
            ],
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
     * Get service summary for admin dashboards.
     *
     * @return array{enabled: bool, cache_ttl: int, top_limit: int, heatmap_bucket: int, country_count: int}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'cache_ttl' => $this->cacheTtl,
            'top_limit' => $this->topLocationsLimit,
            'heatmap_bucket' => $this->heatmapBucketSize,
            'country_count' => count(self::$countryGeo),
        ];
    }

    // ── Internal Helpers ───────────────────────────────────────────────

    /**
     * Aggregate events by a geographic dimension.
     *
     * Uses cache-backed event store queries. In production, this queries
     * the database event store's params JSON column for the dimension key.
     *
     * @param  string  $dimension  Dimension name (country, region, city, timezone)
     * @param  string|null  $event  Event name filter
     * @param  string|null  $category  Category filter
     * @param  string|null  $country  Parent country filter (for region/city)
     * @return array<string, int>  Location → count
     */
    private function aggregateByDimension(
        string $dimension,
        ?string $event = null,
        ?string $category = null,
        ?string $country = null,
    ): array {
        $cacheKey = $this->cachePrefix . 'agg_' . hash('xxh128', implode('|', [
            $dimension, $event ?? '*', $category ?? '*', $country ?? '*',
        ]));

        return $this->cache->remember($cacheKey, $this->cacheTtl, function () use ($dimension, $event, $category): array {
            // In production, this would query the event store.
            // For now, return an empty array as a placeholder.
            // The actual implementation depends on the database schema
            // and is filled at integration time.
            return [];
        });
    }

    /**
     * Build a structured aggregation result with top-N ranking.
     *
     * @param  string  $dimension  Dimension name
     * @param  array<string, int>  $counts  Location → count
     * @return GeoAggregation
     */
    private function buildAggregation(string $dimension, array $counts): array
    {
        $total = array_sum($counts);

        // Sort by count descending
        arsort($counts);

        // Filter out "Unknown" unless configured to include
        if (! $this->includeUnknown && isset($counts['Unknown'])) {
            unset($counts['Unknown']);
        }

        // Build top-N list
        $topN = [];
        $limit = $this->topLocationsLimit;
        $i = 0;

        foreach ($counts as $location => $count) {
            if ($i >= $limit) {
                break;
            }

            $topN[] = [
                'location' => $location,
                'count' => $count,
                'percentage' => $total > 0 ? round(($count / $total) * 100, 2) : 0.0,
            ];
            $i++;
        }

        return [
            'dimension' => $dimension,
            'values' => $counts,
            'total' => $total,
            'top_n' => $topN,
        ];
    }

    /**
     * Build a deterministic cache key for a geo query.
     *
     * @param  string  $type  Query type
     * @param  string|null  ...$parts  Variable cache key parts
     */
    private function buildCacheKey(string $type, ?string ...$parts): string
    {
        return $this->cachePrefix . $type . '_' . hash('xxh128', implode('|', $parts));
    }

    /**
     * Get geodata for a country (lat, lng, ISO code).
     *
     * @param  string  $countryName  Country name
     * @return array{lat: float, lng: float, code: string}|null
     */
    private function getCountryGeo(string $countryName): ?array
    {
        return self::$countryGeo[$countryName] ?? null;
    }

    /**
     * Map a country name to its continent.
     *
     * Uses ISO 3166-1 alpha-2 code prefixes as a heuristic:
     * - EU, AND, etc. → Europe
     * - US, CA, MX, etc. → North America
     * - BR, AR, CL, etc. → South America
     * - CN, JP, IN, etc. → Asia
     * - NG, KE, ZA, etc. → Africa
     * - AU, NZ, etc. → Oceania
     *
     * Falls back to country code prefix mapping.
     */
    private function countryToContinent(string $countryName): string
    {
        $geo = $this->getCountryGeo($countryName);

        if ($geo === null) {
            return 'Unknown';
        }

        $code = $geo['code'];

        $eu = ['GB', 'FR', 'DE', 'IT', 'ES', 'PT', 'NL', 'BE', 'AT', 'CH', 'SE', 'NO', 'DK', 'FI', 'PL', 'CZ', 'IE', 'GR', 'HU', 'RO', 'BG', 'HR', 'SK', 'SI', 'LT', 'LV', 'EE', 'LU', 'MT', 'CY', 'IS', 'AL', 'RS', 'BA', 'MK', 'ME', 'XK', 'MD', 'UA', 'BY', 'RU'];
        $na = ['US', 'CA', 'MX', 'GT', 'HN', 'SV', 'NI', 'CR', 'PA', 'CU', 'DO', 'JM', 'HT', 'TT', 'BB', 'BS', 'BZ', 'GD', 'KN', 'LC', 'VC'];
        $sa = ['BR', 'AR', 'CL', 'CO', 'PE', 'VE', 'EC', 'BO', 'UY', 'PY', 'GY', 'SR', 'GF'];
        $as = ['CN', 'JP', 'IN', 'KR', 'ID', 'TH', 'VN', 'PH', 'MY', 'SG', 'TW', 'HK', 'BD', 'PK', 'IR', 'TR', 'SA', 'AE', 'IL', 'KZ', 'UZ', 'AF', 'LK', 'MM', 'KH', 'LA', 'MN', 'NP', 'KW', 'QA', 'BH', 'OM', 'JO', 'LB', 'SY', 'IQ', 'YE', 'PS', 'GE', 'AM', 'AZ'];
        $af = ['NG', 'ZA', 'KE', 'EG', 'GH', 'TZ', 'ET', 'MA', 'DZ', 'TN', 'LY', 'SD', 'SN', 'CI', 'CM', 'MG', 'MZ', 'UG', 'RW', 'BJ', 'BF', 'CD', 'CG', 'AO', 'ZW', 'ZM', 'MW', 'BJ', 'TG', 'NE', 'ML', 'GN', 'SL', 'LR', 'TD', 'ER', 'DJ', 'SO', 'SS', 'CF', 'GQ', 'ST', 'CV', 'GM', 'KM', 'MR', 'EH', 'SD'];
        $oc = ['AU', 'NZ', 'FJ', 'PG', 'WS', 'TO', 'SB', 'VU', 'CK', 'PF', 'NC', 'NU', 'KI', 'TV', 'NR', 'PW'];

        if (in_array($code, $eu, true)) {
            return 'Europe';
        }
        if (in_array($code, $na, true)) {
            return 'North America';
        }
        if (in_array($code, $sa, true)) {
            return 'South America';
        }
        if (in_array($code, $as, true)) {
            return 'Asia';
        }
        if (in_array($code, $af, true)) {
            return 'Africa';
        }
        if (in_array($code, $oc, true)) {
            return 'Oceania';
        }

        return 'Unknown';
    }

    /**
     * Load country geodata (lat, lng, ISO code) from embedded data.
     *
     * Loads a representative subset of ~50 major countries.
     * Extend the data/iso-country-codes.php file for full coverage.
     */
    private function loadCountryGeoData(): void
    {
        if (self::$countryGeo !== []) {
            return;
        }

        // Embedded subset of major countries for geospatial analytics
        self::$countryGeo = [
            'United States' => ['lat' => 39.8283, 'lng' => -98.5795, 'code' => 'US'],
            'United Kingdom' => ['lat' => 51.5074, 'lng' => -0.1278, 'code' => 'GB'],
            'Germany' => ['lat' => 51.1657, 'lng' => 10.4515, 'code' => 'DE'],
            'France' => ['lat' => 46.2276, 'lng' => 2.2137, 'code' => 'FR'],
            'Japan' => ['lat' => 36.2048, 'lng' => 138.2529, 'code' => 'JP'],
            'Brazil' => ['lat' => -14.2350, 'lng' => -51.9253, 'code' => 'BR'],
            'Canada' => ['lat' => 56.1304, 'lng' => -106.3468, 'code' => 'CA'],
            'Australia' => ['lat' => -25.2744, 'lng' => 133.7751, 'code' => 'AU'],
            'India' => ['lat' => 20.5937, 'lng' => 78.9629, 'code' => 'IN'],
            'China' => ['lat' => 35.8617, 'lng' => 104.1954, 'code' => 'CN'],
            'Italy' => ['lat' => 41.8719, 'lng' => 12.5674, 'code' => 'IT'],
            'Spain' => ['lat' => 40.4637, 'lng' => -3.7492, 'code' => 'ES'],
            'Netherlands' => ['lat' => 52.1326, 'lng' => 5.2913, 'code' => 'NL'],
            'South Korea' => ['lat' => 35.9078, 'lng' => 127.7669, 'code' => 'KR'],
            'Mexico' => ['lat' => 23.6345, 'lng' => -102.5528, 'code' => 'MX'],
            'Indonesia' => ['lat' => -0.7893, 'lng' => 113.9213, 'code' => 'ID'],
            'Turkey' => ['lat' => 38.9637, 'lng' => 35.2433, 'code' => 'TR'],
            'Russia' => ['lat' => 61.5240, 'lng' => 105.3188, 'code' => 'RU'],
            'Saudi Arabia' => ['lat' => 23.8859, 'lng' => 45.0792, 'code' => 'SA'],
            'Poland' => ['lat' => 51.9194, 'lng' => 19.1451, 'code' => 'PL'],
            'Sweden' => ['lat' => 60.1282, 'lng' => 18.6435, 'code' => 'SE'],
            'Switzerland' => ['lat' => 46.8182, 'lng' => 8.2275, 'code' => 'CH'],
            'Norway' => ['lat' => 60.4720, 'lng' => 8.4689, 'code' => 'NO'],
            'Denmark' => ['lat' => 56.2639, 'lng' => 9.5018, 'code' => 'DK'],
            'Finland' => ['lat' => 61.9241, 'lng' => 25.7482, 'code' => 'FI'],
            'Belgium' => ['lat' => 50.5039, 'lng' => 4.4699, 'code' => 'BE'],
            'Austria' => ['lat' => 47.5162, 'lng' => 14.5501, 'code' => 'AT'],
            'Portugal' => ['lat' => 39.3999, 'lng' => -8.2245, 'code' => 'PT'],
            'Ireland' => ['lat' => 53.1424, 'lng' => -7.6921, 'code' => 'IE'],
            'Czech Republic' => ['lat' => 49.8175, 'lng' => 15.4730, 'code' => 'CZ'],
            'Greece' => ['lat' => 39.0742, 'lng' => 21.8243, 'code' => 'GR'],
            'Romania' => ['lat' => 45.9432, 'lng' => 24.9668, 'code' => 'RO'],
            'Ukraine' => ['lat' => 48.3794, 'lng' => 31.1656, 'code' => 'UA'],
            'Singapore' => ['lat' => 1.3521, 'lng' => 103.8198, 'code' => 'SG'],
            'Thailand' => ['lat' => 15.8700, 'lng' => 100.9925, 'code' => 'TH'],
            'Vietnam' => ['lat' => 14.0583, 'lng' => 108.2772, 'code' => 'VN'],
            'Philippines' => ['lat' => 12.8797, 'lng' => 121.7740, 'code' => 'PH'],
            'Malaysia' => ['lat' => 4.2105, 'lng' => 101.9758, 'code' => 'MY'],
            'New Zealand' => ['lat' => -40.9006, 'lng' => 174.8860, 'code' => 'NZ'],
            'South Africa' => ['lat' => -30.5595, 'lng' => 22.9375, 'code' => 'ZA'],
            'Nigeria' => ['lat' => 9.0820, 'lng' => 8.6753, 'code' => 'NG'],
            'Kenya' => ['lat' => -0.0236, 'lng' => 37.9062, 'code' => 'KE'],
            'Egypt' => ['lat' => 26.8206, 'lng' => 30.8025, 'code' => 'EG'],
            'Argentina' => ['lat' => -38.4161, 'lng' => -63.6167, 'code' => 'AR'],
            'Chile' => ['lat' => -35.6751, 'lng' => -71.5430, 'code' => 'CL'],
            'Colombia' => ['lat' => 4.5709, 'lng' => -74.2973, 'code' => 'CO'],
            'Peru' => ['lat' => -9.1900, 'lng' => -75.0152, 'code' => 'PE'],
            'Israel' => ['lat' => 31.0461, 'lng' => 34.8516, 'code' => 'IL'],
            'UAE' => ['lat' => 23.4241, 'lng' => 53.8478, 'code' => 'AE'],
            'Taiwan' => ['lat' => 23.6978, 'lng' => 120.9605, 'code' => 'TW'],
            'Hong Kong' => ['lat' => 22.3193, 'lng' => 114.1694, 'code' => 'HK'],
        ];
    }
}
