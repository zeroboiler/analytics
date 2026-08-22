<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;

/**
 * Analytics Event Lineage Tracker Service.
 *
 * Tracks the complete lifecycle path of analytics events through the pipeline:
 * source origin → enrichment stages → provider dispatch → delivery confirmation.
 * Each tracked event gets a lineage ID that links all stages together, enabling
 * end-to-end tracing, debugging, and compliance reporting.
 *
 * Lineage entries record:
 * - Source: Where the event originated (api, server, client, webhook, replay, batch, lifecycle)
 * - Enrichment stages: Which pipeline enrichers processed the event (UTM, metadata, PII, schema, etc.)
 * - Provider dispatch: Which providers received the event and their response status
 * - Delivery: Whether the event was confirmed delivered or failed
 * - Timing: Duration of each pipeline stage
 *
 * Cache-backed with configurable retention. Designed for operational dashboards,
 * debugging tools, and GDPR Article 30 compliance reporting.
 *
 * Configuration is read from `zeroboiler.analytics.event_lineage`.
 *
 * @since 49.0.0
 */
final class EventLineageTrackerService
{
    /** @var string Cache key prefix for lineage entries */
    private string $cachePrefix;

    /** @var int Retention TTL in seconds for lineage entries */
    private int $retentionTtl;

    /** @var int Maximum number of lineage entries to retain */
    private int $maxEntries;

    /** @var bool Whether lineage tracking is enabled */
    private bool $enabled;

    /** @var bool Whether to automatically track all dispatched events */
    private bool $autoTrack;

    /** @var bool Whether to track enrichment pipeline stages */
    private bool $trackEnrichment;

    /** @var bool Whether to track provider-level dispatch details */
    private bool $trackProviders;

    /** @var list<string> Stages to skip in lineage recording (performance optimization) */
    private array $skipStages;

    private CacheRepository $cache;

    /**
     * Create a new EventLineageTrackerService instance.
     *
     * @param  CacheRepository  $cache  Cache repository instance
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $lineageConfig = $config->get('zeroboiler.analytics.event_lineage', []);
        /** @var array{enabled?: bool, cache_prefix?: string, retention_ttl?: int, max_entries?: int, auto_track?: bool, track_enrichment?: bool, track_providers?: bool, skip_stages?: list<string>} $lineageConfig */

        $this->cache = $cache;
        $this->enabled = (bool) ($lineageConfig['enabled'] ?? true);
        $this->cachePrefix = (string) ($lineageConfig['cache_prefix'] ?? 'zb_lineage_');
        $this->retentionTtl = (int) ($lineageConfig['retention_ttl'] ?? 604800); // 7 days
        $this->maxEntries = (int) ($lineageConfig['max_entries'] ?? 10000);
        $this->autoTrack = (bool) ($lineageConfig['auto_track'] ?? false);
        $this->trackEnrichment = (bool) ($lineageConfig['track_enrichment'] ?? true);
        $this->trackProviders = (bool) ($lineageConfig['track_providers'] ?? true);
        $this->skipStages = (array) ($lineageConfig['skip_stages'] ?? []);
    }

    /**
     * Check if lineage tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if auto-tracking is enabled (all dispatched events).
     */
    public function isAutoTrackEnabled(): bool
    {
        return $this->enabled && $this->autoTrack;
    }

    /**
     * Generate a unique lineage ID for tracking an event's lifecycle.
     *
     * @return string 12-character hex string
     */
    public function generateLineageId(): string
    {
        return substr(Str::uuid()->toString(), 0, 12);
    }

    /**
     * Record the source origin of an event.
     *
     * Called when an event first enters the analytics pipeline.
     * Records the source type, event name, client ID, user ID, and timestamp.
     *
     * @param  string  $lineageId  Unique lineage identifier
     * @param  string  $eventName  Name of the event being tracked
     * @param  string  $source  Origin source: api, server, client, webhook, replay, batch, lifecycle
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     * @param  array<string, mixed>  $context  Additional context (request_id, ip, etc.)
     * @return bool True if recorded successfully
     */
    public function recordSource(
        string $lineageId,
        string $eventName,
        string $source,
        ?string $clientId = null,
        ?string $userId = null,
        array $context = [],
    ): bool {
        if (! $this->enabled) {
            return false;
        }

        $entry = $this->getOrCreateEntry($lineageId);
        $entry['event_name'] = $eventName;
        $entry['source'] = $source;
        $entry['client_id'] = $clientId;
        $entry['user_id'] = $userId;
        $entry['source_timestamp'] = microtime(true);
        $entry['source_context'] = $context;
        $entry['status'] = 'in_progress';

        return $this->storeEntry($lineageId, $entry);
    }

    /**
     * Record an enrichment stage that processed the event.
     *
     * Called by pipeline enrichers (UTM, metadata, PII, schema, etc.) to record
     * which transformations were applied to the event.
     *
     * @param  string  $lineageId  Unique lineage identifier
     * @param  string  $stage  Enrichment stage name (e.g., 'utm', 'metadata', 'pii', 'schema', 'timestamp')
     * @param  bool  $modified  Whether the enricher modified the event
     * @param  array<string, mixed>  $details  Stage-specific details
     * @param  float|null  $durationMs  Stage execution time in milliseconds
     * @return bool True if recorded successfully
     */
    public function recordEnrichmentStage(
        string $lineageId,
        string $stage,
        bool $modified,
        array $details = [],
        ?float $durationMs = null,
    ): bool {
        if (! $this->enabled || ! $this->trackEnrichment) {
            return false;
        }

        if (in_array($stage, $this->skipStages, true)) {
            return true;
        }

        $entry = $this->getOrCreateEntry($lineageId);

        $entry['enrichment_stages'][] = [
            'stage' => $stage,
            'modified' => $modified,
            'details' => $details,
            'duration_ms' => $durationMs,
            'timestamp' => microtime(true),
        ];

        return $this->storeEntry($lineageId, $entry);
    }

    /**
     * Record provider-level dispatch results.
     *
     * Called after the event is dispatched to each provider. Records
     * success/failure, response time, and any error messages.
     *
     * @param  string  $lineageId  Unique lineage identifier
     * @param  string  $provider  Provider name (ga4, gtm, meta, plausible, posthog, etc.)
     * @param  bool  $success  Whether dispatch succeeded
     * @param  float|null  $durationMs  Dispatch time in milliseconds
     * @param  string|null  $error  Error message if dispatch failed
     * @return bool True if recorded successfully
     */
    public function recordProviderDispatch(
        string $lineageId,
        string $provider,
        bool $success,
        ?float $durationMs = null,
        ?string $error = null,
    ): bool {
        if (! $this->enabled || ! $this->trackProviders) {
            return false;
        }

        $entry = $this->getOrCreateEntry($lineageId);

        $entry['provider_dispatches'][] = [
            'provider' => $provider,
            'success' => $success,
            'duration_ms' => $durationMs,
            'error' => $error,
            'timestamp' => microtime(true),
        ];

        return $this->storeEntry($lineageId, $entry);
    }

    /**
     * Record final delivery status for the lineage.
     *
     * Called at the end of the event lifecycle to mark the lineage as complete.
     *
     * @param  string  $lineageId  Unique lineage identifier
     * @param  string  $status  Final status: 'delivered', 'partial', 'failed', 'filtered'
     * @param  array<string, mixed>  $summary  Final summary data
     * @return bool True if recorded successfully
     */
    public function recordCompletion(
        string $lineageId,
        string $status,
        array $summary = [],
    ): bool {
        if (! $this->enabled) {
            return false;
        }

        $entry = $this->getOrCreateEntry($lineageId);
        $entry['status'] = $status;
        $entry['completed_at'] = microtime(true);
        $entry['total_duration_ms'] = isset($entry['source_timestamp'])
            ? round((microtime(true) - $entry['source_timestamp']) * 1000, 2)
            : null;
        $entry['summary'] = $summary;

        return $this->storeEntry($lineageId, $entry);
    }

    /**
     * Get a specific lineage entry by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getLineage(string $lineageId): ?array
    {
        /** @var array<string, mixed>|null $entry */
        $entry = $this->cache->get($this->cachePrefix . $lineageId);

        return is_array($entry) ? $entry : null;
    }

    /**
     * Get recent lineage entries, sorted by most recent first.
     *
     * @param  int  $limit  Maximum entries to return
     * @param  string|null  $eventName  Filter by event name (optional)
     * @param  string|null  $source  Filter by source (optional)
     * @param  string|null  $status  Filter by status (optional)
     * @return list<array<string, mixed>>
     */
    public function getRecentLineages(
        int $limit = 50,
        ?string $eventName = null,
        ?string $source = null,
        ?string $status = null,
    ): array {
        $index = $this->getLineageIndex();
        $results = [];
        $count = 0;

        // Iterate from newest to oldest
        $reversed = array_reverse($index, true);

        foreach ($reversed as $lid => $_) {
            if ($count >= $limit) {
                break;
            }

            $entry = $this->getLineage($lid);
            if ($entry === null) {
                continue;
            }

            // Apply filters
            if ($eventName !== null && ($entry['event_name'] ?? null) !== $eventName) {
                continue;
            }

            if ($source !== null && ($entry['source'] ?? null) !== $source) {
                continue;
            }

            if ($status !== null && ($entry['status'] ?? null) !== $status) {
                continue;
            }

            $results[] = $entry;
            $count++;
        }

        return $results;
    }

    /**
     * Get lineage entries for a specific client ID.
     *
     * @param  string  $clientId  Client tracking ID
     * @param  int  $limit  Maximum entries to return
     * @return list<array<string, mixed>>
     */
    public function getLineagesByClient(string $clientId, int $limit = 50): array
    {
        // Full client-based filtering requires a secondary index for performance.
        // Current implementation scans recent entries in-memory.
        $recent = $this->getRecentLineages(limit: $this->maxEntries);
        $results = [];

        foreach ($recent as $entry) {
            if (($entry['client_id'] ?? null) === $clientId) {
                $results[] = $entry;
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * Get lineage entries for a specific user ID.
     *
     * @param  string  $userId  Authenticated user ID
     * @param  int  $limit  Maximum entries to return
     * @return list<array<string, mixed>>
     */
    public function getLineagesByUser(string $userId, int $limit = 50): array
    {
        $recent = $this->getRecentLineages(limit: $this->maxEntries);
        $results = [];

        foreach ($recent as $entry) {
            if (($entry['user_id'] ?? null) === $userId) {
                $results[] = $entry;
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * Get lineage statistics.
     *
     * @return array{total_tracked: int, in_progress: int, delivered: int, partial: int, failed: int, filtered: int, avg_duration_ms: float|null, by_source: array<string, int>, by_provider_success: array<string, int>, by_provider_failure: array<string, int>, enrichment_stages_used: list<string>}
     */
    public function getStats(): array
    {
        $index = $this->getLineageIndex();
        $stats = [
            'total_tracked' => count($index),
            'in_progress' => 0,
            'delivered' => 0,
            'partial' => 0,
            'failed' => 0,
            'filtered' => 0,
            'avg_duration_ms' => null,
            'by_source' => [],
            'by_provider_success' => [],
            'by_provider_failure' => [],
            'enrichment_stages_used' => [],
            'total_durations' => [],
        ];

        $durations = [];

        foreach ($index as $lid => $_) {
            $entry = $this->getLineage($lid);
            if ($entry === null) {
                continue;
            }

            // Count by status
            $status = $entry['status'] ?? 'unknown';
            if (isset($stats[$status])) {
                $stats[$status]++;
            }

            // Count by source
            $source = $entry['source'] ?? 'unknown';
            $stats['by_source'][$source] = ($stats['by_source'][$source] ?? 0) + 1;

            // Track durations
            if (isset($entry['total_duration_ms']) && is_numeric($entry['total_duration_ms'])) {
                $durations[] = (float) $entry['total_duration_ms'];
            }

            // Count provider dispatches
            if (isset($entry['provider_dispatches']) && is_array($entry['provider_dispatches'])) {
                foreach ($entry['provider_dispatches'] as $dispatch) {
                    $provider = $dispatch['provider'] ?? 'unknown';
                    if (($dispatch['success'] ?? false) === true) {
                        $stats['by_provider_success'][$provider] = ($stats['by_provider_success'][$provider] ?? 0) + 1;
                    } else {
                        $stats['by_provider_failure'][$provider] = ($stats['by_provider_failure'][$provider] ?? 0) + 1;
                    }
                }
            }

            // Track enrichment stages
            if (isset($entry['enrichment_stages']) && is_array($entry['enrichment_stages'])) {
                foreach ($entry['enrichment_stages'] as $stage) {
                    $stageName = $stage['stage'] ?? 'unknown';
                    if (! in_array($stageName, $stats['enrichment_stages_used'], true)) {
                        $stats['enrichment_stages_used'][] = $stageName;
                    }
                }
            }
        }

        if (count($durations) > 0) {
            $stats['avg_duration_ms'] = round(array_sum($durations) / count($durations), 2);
        }

        unset($stats['total_durations']);

        return $stats;
    }

    /**
     * Get the most common failure patterns across lineage entries.
     *
     * Useful for identifying systematic pipeline issues.
     *
     * @param  int  $limit  Maximum patterns to return
     * @return list<array{pattern: string, count: int, last_seen: float|null}>
     */
    public function getFailurePatterns(int $limit = 10): array
    {
        $patterns = [];
        $recent = $this->getRecentLineages(limit: $this->maxEntries);

        foreach ($recent as $entry) {
            if (($entry['status'] ?? null) !== 'failed') {
                continue;
            }

            $dispatches = $entry['provider_dispatches'] ?? [];
            $failedProviders = [];

            foreach ($dispatches as $dispatch) {
                if (($dispatch['success'] ?? false) === false) {
                    $provider = $dispatch['provider'] ?? 'unknown';
                    $error = $dispatch['error'] ?? 'unknown_error';
                    $key = $provider . ':' . $error;
                    $failedProviders[$key] = ($failedProviders[$key] ?? 0) + 1;
                }
            }

            foreach ($failedProviders as $key => $count) {
                if (! isset($patterns[$key])) {
                    $patterns[$key] = ['pattern' => $key, 'count' => 0, 'last_seen' => null];
                }
                $patterns[$key]['count'] += $count;
                $ts = $entry['completed_at'] ?? $entry['source_timestamp'] ?? null;
                if ($ts !== null && ($patterns[$key]['last_seen'] ?? 0) < $ts) {
                    $patterns[$key]['last_seen'] = $ts;
                }
            }
        }

        usort($patterns, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return array_slice($patterns, 0, $limit);
    }

    /**
     * Get average pipeline duration by enrichment stage.
     *
     * @return array<string, array{avg_ms: float, count: int, min_ms: float|null, max_ms: float|null}>
     */
    public function getStagePerformanceStats(): array
    {
        $stats = [];
        $recent = $this->getRecentLineages(limit: $this->maxEntries);

        foreach ($recent as $entry) {
            $stages = $entry['enrichment_stages'] ?? [];

            foreach ($stages as $stage) {
                $name = $stage['stage'] ?? 'unknown';
                $duration = $stage['duration_ms'] ?? null;

                if (! isset($stats[$name])) {
                    $stats[$name] = ['total_ms' => 0.0, 'count' => 0, 'min_ms' => null, 'max_ms' => null];
                }

                $stats[$name]['count']++;

                if ($duration !== null && is_numeric($duration)) {
                    $d = (float) $duration;
                    $stats[$name]['total_ms'] += $d;

                    if ($stats[$name]['min_ms'] === null || $d < $stats[$name]['min_ms']) {
                        $stats[$name]['min_ms'] = $d;
                    }

                    if ($stats[$name]['max_ms'] === null || $d > $stats[$name]['max_ms']) {
                        $stats[$name]['max_ms'] = $d;
                    }
                }
            }
        }

        $result = [];
        foreach ($stats as $name => $data) {
            $avg = $data['count'] > 0 ? round($data['total_ms'] / $data['count'], 2) : 0.0;
            $result[$name] = [
                'avg_ms' => $avg,
                'count' => $data['count'],
                'min_ms' => $data['min_ms'],
                'max_ms' => $data['max_ms'],
            ];
        }

        return $result;
    }

    /**
     * Get provider dispatch reliability stats.
     *
     * @return array<string, array{total: int, success: int, failure: int, success_rate: float, avg_duration_ms: float|null}>
     */
    public function getProviderReliabilityStats(): array
    {
        $providerStats = [];
        $recent = $this->getRecentLineages(limit: $this->maxEntries);

        foreach ($recent as $entry) {
            $dispatches = $entry['provider_dispatches'] ?? [];

            foreach ($dispatches as $dispatch) {
                $provider = $dispatch['provider'] ?? 'unknown';

                if (! isset($providerStats[$provider])) {
                    $providerStats[$provider] = ['total' => 0, 'success' => 0, 'total_ms' => 0.0, 'count_ms' => 0];
                }

                $providerStats[$provider]['total']++;

                if (($dispatch['success'] ?? false) === true) {
                    $providerStats[$provider]['success']++;
                }

                $duration = $dispatch['duration_ms'] ?? null;
                if ($duration !== null && is_numeric($duration)) {
                    $providerStats[$provider]['total_ms'] += (float) $duration;
                    $providerStats[$provider]['count_ms']++;
                }
            }
        }

        $result = [];
        foreach ($providerStats as $provider => $data) {
            $failure = $data['total'] - $data['success'];
            $successRate = $data['total'] > 0
                ? round(($data['success'] / $data['total']) * 100, 2)
                : 0.0;
            $avgDuration = $data['count_ms'] > 0
                ? round($data['total_ms'] / $data['count_ms'], 2)
                : null;

            $result[$provider] = [
                'total' => $data['total'],
                'success' => $data['success'],
                'failure' => $failure,
                'success_rate' => $successRate,
                'avg_duration_ms' => $avgDuration,
            ];
        }

        return $result;
    }

    /**
     * Purge all lineage entries.
     *
     * @return int Number of entries purged
     */
    public function purge(): int
    {
        $index = $this->getLineageIndex();
        $count = count($index);

        foreach (array_keys($index) as $lid) {
            $this->cache->forget($this->cachePrefix . $lid);
        }

        $this->cache->forget($this->cachePrefix . 'index');
        $this->cache->forget($this->cachePrefix . 'counter');

        return $count;
    }

    /**
     * Purge lineage entries older than a given timestamp.
     *
     * @param  float  $beforeTimestamp  Unix timestamp (microtime)
     * @return int Number of entries purged
     */
    public function purgeBefore(float $beforeTimestamp): int
    {
        $index = $this->getLineageIndex();
        $purged = 0;

        foreach (array_keys($index) as $lid) {
            $entry = $this->getLineage($lid);
            if ($entry === null) {
                continue;
            }

            $ts = $entry['source_timestamp'] ?? $entry['completed_at'] ?? 0.0;
            if ($ts < $beforeTimestamp) {
                $this->cache->forget($this->cachePrefix . $lid);
                unset($index[$lid]);
                $purged++;
            }
        }

        $this->cache->put($this->cachePrefix . 'index', $index, $this->retentionTtl);

        return $purged;
    }

    /**
     * Get the total number of tracked lineage entries.
     */
    public function count(): int
    {
        return count($this->getLineageIndex());
    }

    /**
     * Check if a specific lineage entry exists.
     */
    public function has(string $lineageId): bool
    {
        return $this->cache->has($this->cachePrefix . $lineageId);
    }

    /**
     * Export all lineage entries for compliance reporting.
     *
     * @return array{entries: list<array<string, mixed>>, exported_at: string, total: int}
     */
    public function exportForCompliance(): array
    {
        $index = $this->getLineageIndex();
        $entries = [];

        foreach (array_keys($index) as $lid) {
            $entry = $this->getLineage($lid);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return [
            'entries' => $entries,
            'exported_at' => date('c'),
            'total' => count($entries),
        ];
    }

    /**
     * Get or create a lineage entry.
     *
     * @return array<string, mixed>
     */
    private function getOrCreateEntry(string $lineageId): array
    {
        $existing = $this->getLineage($lineageId);

        if ($existing !== null) {
            return $existing;
        }

        return [
            'lineage_id' => $lineageId,
            'event_name' => null,
            'source' => null,
            'client_id' => null,
            'user_id' => null,
            'source_timestamp' => null,
            'source_context' => [],
            'enrichment_stages' => [],
            'provider_dispatches' => [],
            'status' => 'pending',
            'completed_at' => null,
            'total_duration_ms' => null,
            'summary' => [],
        ];
    }

    /**
     * Store a lineage entry and update the index.
     *
     * @param  string  $lineageId
     * @param  array<string, mixed>  $entry
     * @return bool
     */
    private function storeEntry(string $lineageId, array $entry): bool
    {
        // Enforce max entries limit — remove oldest if needed
        $index = $this->getLineageIndex();

        if (! isset($index[$lineageId]) && count($index) >= $this->maxEntries) {
            $oldestKey = array_key_first($index);
            if ($oldestKey !== null) {
                $this->cache->forget($this->cachePrefix . $oldestKey);
                unset($index[$oldestKey]);
            }
        }

        $index[$lineageId] = true;

        $this->cache->put($this->cachePrefix . $lineageId, $entry, $this->retentionTtl);
        $this->cache->put($this->cachePrefix . 'index', $index, $this->retentionTtl);

        return true;
    }

    /**
     * Get the lineage index (mapping of lineage IDs to boolean).
     *
     * @return array<string, bool>
     */
    private function getLineageIndex(): array
    {
        /** @var array<string, bool>|null $index */
        $index = $this->cache->get($this->cachePrefix . 'index');

        return is_array($index) ? $index : [];
    }
}
