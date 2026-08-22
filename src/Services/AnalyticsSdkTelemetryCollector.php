<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Analytics SDK Telemetry Collector — Client SDK health and performance monitoring.
 *
 * Collects, aggregates, and reports client-side SDK telemetry data
 * (SDK version, platform, page load time, connection quality, events per session,
 * error rates, battery status, memory usage) sent by the JS client.
 *
 * Unlike ProviderDispatchTelemetry (which tracks server→provider dispatch metrics),
 * this service focuses on client→server SDK health signals for operational monitoring.
 *
 * Telemetry data is aggregated per SDK version and per client, stored in cache
 * with configurable TTL, and exposed via diagnostic commands and API endpoints.
 *
 * Configuration: `zeroboiler.analytics.sdk_telemetry`
 *
 * @see \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController
 * @see \ZeroBoiler\Analytics\Services\ProviderDispatchTelemetry
 *
 * @since 122.0.0
 */
final class AnalyticsSdkTelemetryCollector
{
    /** Cache key prefix */
    private const CACHE_PREFIX = 'zb_sdk_telemetry_';

    /** Cache key for aggregate summary */
    private const CACHE_SUMMARY_KEY = 'zb_sdk_telemetry_summary';

    /** Maximum number of recent telemetry entries per client */
    private const MAX_ENTRIES_PER_CLIENT = 100;

    /** Maximum number of SDK versions tracked */
    private const MAX_VERSIONS_TRACKED = 50;

    /** Telemetry severity thresholds */
    private const SLOW_LOAD_THRESHOLD_MS = 3000;       // 3 seconds
    private const HIGH_ERROR_RATE_THRESHOLD = 0.1;      // 10%
    private const LOW_EVENT_RATE_THRESHOLD = 0.5;       // <1 event per second
    private const HIGH_MEMORY_USAGE_MB = 100;          // 100 MB JS heap

    private CacheRepository $cache;

    private bool $enabled;

    private int $cacheTtl;

    private int $aggregationWindow;

    private bool $collectPageLoad;

    private bool $collectConnectionType;

    private bool $collectMemoryUsage;

    private bool $collectBatteryStatus;

    private bool $collectErrorRates;

    /** @var array<string, mixed> Runtime aggregation buffer */
    private array $buffer = [];

    /** @var int Number of telemetry points collected in current request */
    private int $bufferCount = 0;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Application config repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $telemetryConfig = $config->get('zeroboiler.analytics.sdk_telemetry', []);
        /** @var array{enabled?: bool, cache_ttl?: int, aggregation_window?: int, collect_page_load?: bool, collect_connection_type?: bool, collect_memory_usage?: bool, collect_battery_status?: bool, collect_error_rates?: bool} $telemetryConfig */

        $this->enabled = (bool) ($telemetryConfig['enabled'] ?? false);
        $this->cacheTtl = (int) ($telemetryConfig['cache_ttl'] ?? 86400); // 24 hours
        $this->aggregationWindow = (int) ($telemetryConfig['aggregation_window'] ?? 3600); // 1 hour
        $this->collectPageLoad = (bool) ($telemetryConfig['collect_page_load'] ?? true);
        $this->collectConnectionType = (bool) ($telemetryConfig['collect_connection_type'] ?? true);
        $this->collectMemoryUsage = (bool) ($telemetryConfig['collect_memory_usage'] ?? true);
        $this->collectBatteryStatus = (bool) ($telemetryConfig['collect_battery_status'] ?? false);
        $this->collectErrorRates = (bool) ($telemetryConfig['collect_error_rates'] ?? true);
    }

    /**
     * Collect a telemetry data point from a client SDK.
     *
     * Accepts a structured telemetry payload from the JS client and stores
     * it for aggregation and diagnostic reporting.
     *
     * @param  array<string, mixed>  $telemetry  Client telemetry data
     * @return bool True if collected successfully
     *
     * @phpstan-param TelemetryPayload $telemetry
     */
    public function collect(array $telemetry): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $validated = $this->validateTelemetry($telemetry);

        if ($validated === null) {
            return false;
        }

        $clientId = $validated['client_id'] ?? 'unknown';
        $sdkVersion = $validated['sdk_version'] ?? 'unknown';

        // Store per-client telemetry entry
        $this->storeClientEntry($clientId, $validated);

        // Update per-version aggregation
        $this->updateVersionAggregation($sdkVersion, $validated);

        // Update global summary
        $this->updateSummary($validated);

        // Buffer for in-request aggregation
        $this->bufferCount++;

        return true;
    }

    /**
     * Collect multiple telemetry data points in batch.
     *
     * @param  list<array<string, mixed>>  $telemetryBatch  Batch of client telemetry data
     * @return array{collected: int, rejected: int} Counts of collected vs rejected points
     */
    public function collectBatch(array $telemetryBatch): array
    {
        $collected = 0;
        $rejected = 0;

        foreach ($telemetryBatch as $telemetry) {
            if ($this->collect($telemetry)) {
                $collected++;
            } else {
                $rejected++;
            }
        }

        return ['collected' => $collected, 'rejected' => $rejected];
    }

    /**
     * Get telemetry summary aggregated across all clients.
     *
     * Returns top SDK versions, average performance metrics,
     * error rates, and health indicators.
     *
     * @return array{total_clients: int, sdk_versions: array<string, array{count: int, percentage: float}>, performance: array{avg_page_load_ms: float|null, p50_page_load_ms: float|null, p95_page_load_ms: float|null, avg_events_per_session: float|null}, health: array{slow_load_percentage: float, high_error_rate_clients: int, low_memory_clients: int}, platforms: array<string, int>, connection_types: array<string, int>}
     */
    public function summary(): array
    {
        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get(self::CACHE_SUMMARY_KEY);

        if ($cached !== null && \is_array($cached)) {
            return $cached;
        }

        return $this->buildFreshSummary();
    }

    /**
     * Get telemetry data for a specific client.
     *
     * @param  string  $clientId  Client tracking ID
     * @return array{entries: list<array<string, mixed>>, summary: array{total_entries: int, first_seen: string|null, last_seen: string|null, sdk_version: string|null, avg_page_load_ms: float|null, error_rate: float|null}}
     */
    public function clientHistory(string $clientId): array
    {
        $cacheKey = self::CACHE_PREFIX . 'client_' . $clientId;

        /** @var list<array<string, mixed>>|null $entries */
        $entries = $this->cache->get($cacheKey);

        if ($entries === null) {
            return [
                'entries' => [],
                'summary' => [
                    'total_entries' => 0,
                    'first_seen' => null,
                    'last_seen' => null,
                    'sdk_version' => null,
                    'avg_page_load_ms' => null,
                    'error_rate' => null,
                ],
            ];
        }

        $pageLoads = \array_filter(
            \array_column($entries, 'page_load_ms'),
            static fn ($v): bool => $v !== null && \is_numeric($v)
        );

        $errors = \array_column($entries, 'errors_count', []);
        $totalErrors = \array_sum(\array_filter($errors, static fn ($v): bool => \is_numeric($v)));
        $totalSessions = \count($entries);

        return [
            'entries' => \array_slice($entries, -20), // Last 20 entries
            'summary' => [
                'total_entries' => \count($entries),
                'first_seen' => $entries[0]['timestamp'] ?? null,
                'last_seen' => $entries[\count($entries) - 1]['timestamp'] ?? null,
                'sdk_version' => $entries[\count($entries) - 1]['sdk_version'] ?? null,
                'avg_page_load_ms' => $pageLoads !== []
                    ? \round(\array_sum($pageLoads) / \count($pageLoads), 2)
                    : null,
                'error_rate' => $totalSessions > 0
                    ? \round((float) $totalErrors / (float) $totalSessions, 4)
                    : null,
            ],
        ];
    }

    /**
     * Get SDK version distribution analytics.
     *
     * Useful for tracking adoption of new SDK versions
     * and identifying outdated clients that need upgrade prompts.
     *
     * @return array{versions: array<string, array{count: int, percentage: float, clients: int, avg_page_load_ms: float|null}>, latest_version: string|null, outdated_clients: int}
     */
    public function versionDistribution(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'version_dist';

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null && \is_array($cached)) {
            return $cached;
        }

        // Build fresh from individual version keys
        $versions = [];
        $latestVersion = null;

        for ($i = 0; $i < self::MAX_VERSIONS_TRACKED; $i++) {
            $versionKey = self::CACHE_PREFIX . 'version_' . $i;
            /** @var array<string, mixed>|null $versionData */
            $versionData = $this->cache->get($versionKey);

            if ($versionData === null || ! \is_array($versionData)) {
                continue;
            }

            $version = (string) ($versionData['version'] ?? "v{$i}");
            $versions[$version] = $versionData;

            if ($latestVersion === null || \version_compare($version, $latestVersion, '>')) {
                $latestVersion = $version;
            }
        }

        $totalClients = \array_sum(\array_map(static fn (array $v): int => $v['clients'] ?? 0, $versions));
        $outdatedClients = 0;

        foreach ($versions as $version => $data) {
            if ($latestVersion !== null && \version_compare($version, $latestVersion, '<')) {
                $outdatedClients += (int) ($data['clients'] ?? 0);
            }
        }

        $result = [
            'versions' => $versions,
            'latest_version' => $latestVersion,
            'outdated_clients' => $outdatedClients,
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Get health issues detected from SDK telemetry.
     *
     * Scans telemetry data for clients with performance problems,
     * high error rates, or other health concerns.
     *
     * @return list<array{severity: string, type: string, message: string, client_id: string|null, sdk_version: string|null}>
     */
    public function healthIssues(): array
    {
        if (! $this->enabled) {
            return [];
        }

        $issues = [];
        $summary = $this->summary();

        // Check slow page loads
        if (isset($summary['health']['slow_load_percentage']) && $summary['health']['slow_load_percentage'] > 20.0) {
            $issues[] = [
                'severity' => $summary['health']['slow_load_percentage'] > 50.0 ? 'critical' : 'warning',
                'type' => 'slow_page_load',
                'message' => \sprintf(
                    '%.1f%% of clients experience slow page loads (>3s)',
                    $summary['health']['slow_load_percentage']
                ),
                'client_id' => null,
                'sdk_version' => null,
            ];
        }

        // Check high error rates
        if (($summary['health']['high_error_rate_clients'] ?? 0) > 5) {
            $issues[] = [
                'severity' => 'warning',
                'type' => 'high_error_rate',
                'message' => \sprintf(
                    '%d clients have error rates exceeding 10%%',
                    $summary['health']['high_error_rate_clients']
                ),
                'client_id' => null,
                'sdk_version' => null,
            ];
        }

        // Check for many outdated SDK versions
        $versionDist = $this->versionDistribution();

        if (($versionDist['outdated_clients'] ?? 0) > 10) {
            $issues[] = [
                'severity' => 'info',
                'type' => 'outdated_sdk',
                'message' => \sprintf(
                    '%d clients are using outdated SDK versions (latest: %s)',
                    $versionDist['outdated_clients'],
                    $versionDist['latest_version'] ?? 'unknown'
                ),
                'client_id' => null,
                'sdk_version' => $versionDist['latest_version'],
            ];
        }

        return $issues;
    }

    /**
     * Clear all collected telemetry data.
     *
     * @return int Number of cache keys cleared
     */
    public function clearAll(): int
    {
        $cleared = 0;

        // Clear summary
        if ($this->cache->forget(self::CACHE_SUMMARY_KEY)) {
            $cleared++;
        }

        // Clear version distribution cache
        if ($this->cache->forget(self::CACHE_PREFIX . 'version_dist')) {
            $cleared++;
        }

        // Clear individual version keys
        for ($i = 0; $i < self::MAX_VERSIONS_TRACKED; $i++) {
            if ($this->cache->forget(self::CACHE_PREFIX . 'version_' . $i)) {
                $cleared++;
            }
        }

        // Note: Individual client keys are not cleared here
        // as there could be thousands. Use clearClientHistory() for specific clients.

        return $cleared;
    }

    /**
     * Clear telemetry data for a specific client.
     *
     * @param  string  $clientId  Client tracking ID
     * @return bool True if data was found and cleared
     */
    public function clearClientHistory(string $clientId): bool
    {
        $cacheKey = self::CACHE_PREFIX . 'client_' . $clientId;

        return $this->cache->forget($cacheKey);
    }

    /**
     * Check if telemetry collection is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the number of telemetry points collected in the current request.
     */
    public function getBufferCount(): int
    {
        return $this->bufferCount;
    }

    /**
     * Get collector configuration for diagnostics.
     *
     * @return array{enabled: bool, cache_ttl: int, aggregation_window: int, collect_page_load: bool, collect_connection_type: bool, collect_memory_usage: bool, collect_battery_status: bool, collect_error_rates: bool}
     */
    public function getConfig(): array
    {
        return [
            'enabled' => $this->enabled,
            'cache_ttl' => $this->cacheTtl,
            'aggregation_window' => $this->aggregationWindow,
            'collect_page_load' => $this->collectPageLoad,
            'collect_connection_type' => $this->collectConnectionType,
            'collect_memory_usage' => $this->collectMemoryUsage,
            'collect_battery_status' => $this->collectBatteryStatus,
            'collect_error_rates' => $this->collectErrorRates,
        ];
    }

    // ── Private Methods ──────────────────────────────────────────────────

    /**
     * Validate and sanitize a telemetry payload.
     *
     * @param  array<string, mixed>  $telemetry  Raw telemetry data
     * @return array<string, mixed>|null Validated telemetry or null if invalid
     *
     * @phpstan-return TelemetryPayload|null
     */
    private function validateTelemetry(array $telemetry): ?array
    {
        if ($telemetry === []) {
            return null;
        }

        $validated = [
            'timestamp' => (string) ($telemetry['timestamp'] ?? \date('c')),
            'sdk_version' => isset($telemetry['sdk_version']) && \is_string($telemetry['sdk_version'])
                ? $telemetry['sdk_version']
                : null,
            'client_id' => isset($telemetry['client_id']) && \is_string($telemetry['client_id'])
                ? \substr($telemetry['client_id'], 0, 64)
                : null,
            'platform' => isset($telemetry['platform']) && \is_string($telemetry['platform'])
                ? $telemetry['platform']
                : null,
            'user_agent' => isset($telemetry['user_agent']) && \is_string($telemetry['user_agent'])
                ? \substr($telemetry['user_agent'], 0, 255)
                : null,
            'page_url' => isset($telemetry['page_url']) && \is_string($telemetry['page_url'])
                ? \substr($telemetry['page_url'], 0, 2048)
                : null,
        ];

        // Page load time
        if ($this->collectPageLoad && isset($telemetry['page_load_ms'])) {
            $validated['page_load_ms'] = \is_numeric($telemetry['page_load_ms'])
                ? (float) $telemetry['page_load_ms']
                : null;
        }

        // Connection type
        if ($this->collectConnectionType && isset($telemetry['connection_type'])) {
            $validated['connection_type'] = \is_string($telemetry['connection_type'])
                ? $telemetry['connection_type']
                : null;
        }

        // Memory usage
        if ($this->collectMemoryUsage && isset($telemetry['memory_usage_mb'])) {
            $validated['memory_usage_mb'] = \is_numeric($telemetry['memory_usage_mb'])
                ? (float) $telemetry['memory_usage_mb']
                : null;
        }

        // Battery status
        if ($this->collectBatteryStatus && isset($telemetry['battery_level'])) {
            $validated['battery_level'] = \is_numeric($telemetry['battery_level'])
                ? (float) $telemetry['battery_level']
                : null;
        }

        // Error rates
        if ($this->collectErrorRates && isset($telemetry['errors_count'])) {
            $validated['errors_count'] = \is_numeric($telemetry['errors_count'])
                ? (int) $telemetry['errors_count']
                : 0;
        }

        // Events per session
        if (isset($telemetry['events_count'])) {
            $validated['events_count'] = \is_numeric($telemetry['events_count'])
                ? (int) $telemetry['events_count']
                : 0;
        }

        // Session duration
        if (isset($telemetry['session_duration_ms'])) {
            $validated['session_duration_ms'] = \is_numeric($telemetry['session_duration_ms'])
                ? (float) $telemetry['session_duration_ms']
                : null;
        }

        return $validated;
    }

    /**
     * Store a telemetry entry for a specific client.
     */
    private function storeClientEntry(string $clientId, array $validated): void
    {
        $cacheKey = self::CACHE_PREFIX . 'client_' . $clientId;

        /** @var list<array<string, mixed>> $entries */
        $entries = $this->cache->get($cacheKey, []);

        $entries[] = $validated;

        // Keep only the most recent entries
        if (\count($entries) > self::MAX_ENTRIES_PER_CLIENT) {
            $entries = \array_slice($entries, -self::MAX_ENTRIES_PER_CLIENT);
        }

        $this->cache->put($cacheKey, $entries, $this->cacheTtl);
    }

    /**
     * Update per-SDK-version aggregation counters.
     */
    private function updateVersionAggregation(string $sdkVersion, array $validated): void
    {
        if ($sdkVersion === 'unknown' || $sdkVersion === '') {
            return;
        }

        $versionKey = self::CACHE_PREFIX . 'version_' . \crc32($sdkVersion);

        /** @var array{version: string, count: int, clients: int, page_loads: list<float>, errors: int} $data */
        $data = $this->cache->get($versionKey, [
            'version' => $sdkVersion,
            'count' => 0,
            'clients' => 0,
            'page_loads' => [],
            'errors' => 0,
        ]);

        $data['version'] = $sdkVersion;
        $data['count']++;
        $data['clients'] = 1; // Approximate (actual dedup would require more complex logic)

        if (isset($validated['page_load_ms']) && $validated['page_load_ms'] !== null) {
            $data['page_loads'][] = (float) $validated['page_load_ms'];

            // Keep only last 100 page load samples per version
            if (\count($data['page_loads']) > 100) {
                $data['page_loads'] = \array_slice($data['page_loads'], -100);
            }
        }

        if (isset($validated['errors_count'])) {
            $data['errors'] += (int) $validated['errors_count'];
        }

        $this->cache->put($versionKey, $data, $this->cacheTtl);
    }

    /**
     * Update the global telemetry summary.
     */
    private function updateSummary(array $validated): void
    {
        /** @var array{total_clients: int, sdk_versions: array<string, int>, page_loads: list<float>, platforms: array<string, int>, connection_types: array<string, int>, slow_loads: int, high_error_clients: int, low_memory_clients: int} $summary */
        $summary = $this->cache->get(self::CACHE_SUMMARY_KEY, [
            'total_clients' => 0,
            'sdk_versions' => [],
            'page_loads' => [],
            'platforms' => [],
            'connection_types' => [],
            'slow_loads' => 0,
            'high_error_clients' => 0,
            'low_memory_clients' => 0,
        ]);

        $summary['total_clients']++;

        // Track SDK version counts
        $sdkVersion = $validated['sdk_version'] ?? 'unknown';
        $summary['sdk_versions'][$sdkVersion] = ($summary['sdk_versions'][$sdkVersion] ?? 0) + 1;

        // Track page loads
        if (isset($validated['page_load_ms']) && $validated['page_load_ms'] !== null) {
            $summary['page_loads'][] = (float) $validated['page_load_ms'];

            if (\count($summary['page_loads']) > 1000) {
                $summary['page_loads'] = \array_slice($summary['page_loads'], -1000);
            }

            if ((float) $validated['page_load_ms'] > self::SLOW_LOAD_THRESHOLD_MS) {
                $summary['slow_loads']++;
            }
        }

        // Track platform
        if (isset($validated['platform']) && \is_string($validated['platform'])) {
            $summary['platforms'][$validated['platform']] = ($summary['platforms'][$validated['platform']] ?? 0) + 1;
        }

        // Track connection type
        if (isset($validated['connection_type']) && \is_string($validated['connection_type'])) {
            $summary['connection_types'][$validated['connection_type']] = ($summary['connection_types'][$validated['connection_type']] ?? 0) + 1;
        }

        // Track memory issues
        if (isset($validated['memory_usage_mb']) && (float) $validated['memory_usage_mb'] > self::HIGH_MEMORY_USAGE_MB) {
            $summary['low_memory_clients']++;
        }

        // Track error rates
        if (isset($validated['errors_count']) && (int) $validated['errors_count'] > 5) {
            $summary['high_error_clients']++;
        }

        $this->cache->put(self::CACHE_SUMMARY_KEY, $summary, $this->cacheTtl);
    }

    /**
     * Build a fresh summary from aggregated data.
     *
     * @return array{total_clients: int, sdk_versions: array<string, array{count: int, percentage: float}>, performance: array{avg_page_load_ms: float|null, p50_page_load_ms: float|null, p95_page_load_ms: float|null, avg_events_per_session: float|null}, health: array{slow_load_percentage: float, high_error_rate_clients: int, low_memory_clients: int}, platforms: array<string, int>, connection_types: array<string, int>}
     */
    private function buildFreshSummary(): array
    {
        /** @var array<string, mixed>|null $raw */
        $raw = $this->cache->get(self::CACHE_SUMMARY_KEY);

        if ($raw === null || ! \is_array($raw)) {
            return $this->emptySummary();
        }

        $pageLoads = $raw['page_loads'] ?? [];
        $totalLoads = \count($pageLoads);

        return [
            'total_clients' => (int) ($raw['total_clients'] ?? 0),
            'sdk_versions' => $this->formatVersionDistribution($raw['sdk_versions'] ?? []),
            'performance' => [
                'avg_page_load_ms' => $totalLoads > 0
                    ? \round(\array_sum($pageLoads) / $totalLoads, 2)
                    : null,
                'p50_page_load_ms' => $totalLoads > 0
                    ? \round($this->percentile($pageLoads, 50), 2)
                    : null,
                'p95_page_load_ms' => $totalLoads > 0
                    ? \round($this->percentile($pageLoads, 95), 2)
                    : null,
                'avg_events_per_session' => null, // Computed from session events if available
            ],
            'health' => [
                'slow_load_percentage' => $totalLoads > 0
                    ? \round((float) ($raw['slow_loads'] ?? 0) / (float) $totalLoads * 100, 2)
                    : 0.0,
                'high_error_rate_clients' => (int) ($raw['high_error_clients'] ?? 0),
                'low_memory_clients' => (int) ($raw['low_memory_clients'] ?? 0),
            ],
            'platforms' => (array) ($raw['platforms'] ?? []),
            'connection_types' => (array) ($raw['connection_types'] ?? []),
        ];
    }

    /**
     * Format version distribution with percentages.
     *
     * @param  array<string, int>  $versions  Raw version counts
     * @return array<string, array{count: int, percentage: float}>
     */
    private function formatVersionDistribution(array $versions): array
    {
        $total = \array_sum($versions);
        $formatted = [];

        \arsort($versions);

        foreach ($versions as $version => $count) {
            $formatted[$version] = [
                'count' => $count,
                'percentage' => $total > 0 ? \round((float) $count / (float) $total * 100, 2) : 0.0,
            ];
        }

        return $formatted;
    }

    /**
     * Calculate percentile from a list of numeric values.
     *
     * @param  list<float>  $values  Sorted numeric values
     * @param  int  $percentile  Percentile to calculate (0-100)
     */
    private function percentile(array $values, int $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        \sort($values);

        $index = (int) \ceil((float) $percentile / 100.0 * (float) \count($values)) - 1;

        return (float) ($values[\max(0, $index)] ?? 0.0);
    }

    /**
     * Get an empty summary structure.
     *
     * @return array{total_clients: int, sdk_versions: array<string, array{count: int, percentage: float}>, performance: array{avg_page_load_ms: float|null, p50_page_load_ms: float|null, p95_page_load_ms: float|null, avg_events_per_session: float|null}, health: array{slow_load_percentage: float, high_error_rate_clients: int, low_memory_clients: int}, platforms: array<string, int>, connection_types: array<string, int>}
     */
    private function emptySummary(): array
    {
        return [
            'total_clients' => 0,
            'sdk_versions' => [],
            'performance' => [
                'avg_page_load_ms' => null,
                'p50_page_load_ms' => null,
                'p95_page_load_ms' => null,
                'avg_events_per_session' => null,
            ],
            'health' => [
                'slow_load_percentage' => 0.0,
                'high_error_rate_clients' => 0,
                'low_memory_clients' => 0,
            ],
            'platforms' => [],
            'connection_types' => [],
        ];
    }
}
