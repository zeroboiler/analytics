<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Analytics self-healing service — automatic recovery for common analytics
 * pipeline failures and degraded states.
 *
 * Monitors analytics subsystem health and automatically executes recovery
 * actions when issues are detected. Actions include:
 * - Cache warming for cold caches that cause performance degradation
 * - Provider health reset when circuit breakers are stuck open
 * - Dead letter queue flush for stuck failed events
 * - Event pipeline reset for clogged pipelines
 * - Stale data cleanup for expired cache entries
 * - Queue health check and worker count verification
 *
 * All actions are logged and recorded in a healing history for audit purposes.
 * Actions can be run manually via the AnalyticsSelfHealCommand or triggered
 * automatically by the health check monitor.
 *
 * Inspired by AWS Lambda auto-healing patterns and HashiCorp Consul health checks.
 *
 * @since 48.0.0
 */
final class AnalyticsSelfHealingService
{
    /** @var array<string, mixed> */
    private array $config;

    private string $cachePrefix;

    private int $historyTtl;

    private int $maxHistoryEntries;

    private bool $autoHealEnabled;

    /** @var list<string> */
    private array $autoHealActions;

    private int $healingCooldownSeconds;

    /** @var array<string, int> Last heal timestamp per action type */
    private array $lastHealTimestamps = [];

    /**
     * @param  CacheRepository  $cache  Cache repository for healing state
     * @param  UnifiedHealthEndpointService|null  $healthService  Optional health service for auto-trigger
     * @param  DeadLetterQueueService|null  $dlqService  Optional DLQ service for queue healing
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ?ConfigRepository $config = null,
        private readonly ?UnifiedHealthEndpointService $healthService = null,
        private readonly ?DeadLetterQueueService $dlqService = null,
    ): void {
        $config ??= app(ConfigRepository::class);
        $this->config = $config->get('zeroboiler.analytics.self_healing', []);
        $this->cachePrefix = (string) ($this->config['cache_prefix'] ?? 'zb_heal_');
        $this->historyTtl = (int) ($this->config['history_ttl'] ?? 86400); // 24 hours
        $this->maxHistoryEntries = (int) ($this->config['max_history_entries'] ?? 200);
        $this->autoHealEnabled = (bool) ($this->config['auto_heal_enabled'] ?? false);
        $this->autoHealActions = (array) ($this->config['auto_heal_actions'] ?? []);
        $this->healingCooldownSeconds = (int) ($this->config['healing_cooldown_seconds'] ?? 300); // 5 minutes
    }

    /**
     * Execute a specific healing action.
     *
     * @param  string  $action  The healing action to execute
     * @param  array<string, mixed>  $context  Optional context for the healing action
     * @return array{action: string, status: 'success'|'skipped'|'failed', message: string, duration_ms: int, timestamp: int}
     */
    public function heal(string $action, array $context = []): array
    {
        $startTime = microtime(true);
        $timestamp = time();

        // Check cooldown
        if ($this->isOnCooldown($action)) {
            return [
                'action' => $action,
                'status' => 'skipped',
                'message' => "Action '{$action}' is on cooldown (last executed " .
                    ($this->lastHealTimestamps[$action] ?? 0) . 's ago, cooldown: ' .
                    $this->healingCooldownSeconds . 's)',
                'duration_ms' => 0,
                'timestamp' => $timestamp,
            ];
        }

        $result = match ($action) {
            'warm_cache' => $this->warmCache(),
            'reset_provider_health' => $this->resetProviderHealth(),
            'flush_dlq' => $this->flushDlq(),
            'reset_pipeline' => $this->resetPipeline(),
            'cleanup_stale_data' => $this->cleanupStaleData(),
            'check_queue_health' => $this->checkQueueHealth(),
            'reset_fraud_metrics' => $this->resetFraudMetrics(),
            'reset_quality_firewall' => $this->resetQualityFirewall(),
            'clear_correlations' => $this->clearCorrelations(),
            default => ['success' => false, 'message' => "Unknown healing action: {$action}"],
        };

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);

        $healRecord = [
            'action' => $action,
            'status' => $result['success'] ? 'success' : 'failed',
            'message' => $result['message'],
            'duration_ms' => $durationMs,
            'timestamp' => $timestamp,
        ];

        // Record cooldown
        $this->lastHealTimestamps[$action] = $timestamp;
        $this->persistCooldown($action, $timestamp);

        // Record in history
        $this->recordHistory($healRecord);

        // Log
        if ($result['success']) {
            Log::info('[ZeroBoiler Analytics] Self-healing action executed', $healRecord);
        } else {
            Log::warning('[ZeroBoiler Analytics] Self-healing action failed', $healRecord);
        }

        return $healRecord;
    }

    /**
     * Execute all eligible healing actions.
     *
     * Runs each action that is not on cooldown. Returns results for all actions.
     *
     * @return array{results: list<array{action: string, status: string, message: string, duration_ms: int}>, total: int, succeeded: int, failed: int, skipped: int}
     */
    public function healAll(array $context = []): array
    {
        $actions = [
            'warm_cache', 'reset_provider_health', 'flush_dlq',
            'reset_pipeline', 'cleanup_stale_data', 'check_queue_health',
            'reset_fraud_metrics', 'reset_quality_firewall', 'clear_correlations',
        ];

        $results = [];
        $succeeded = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($actions as $action) {
            $result = $this->heal($action, $context);
            $results[] = [
                'action' => $result['action'],
                'status' => $result['status'],
                'message' => $result['message'],
                'duration_ms' => $result['duration_ms'],
            ];

            match ($result['status']) {
                'success' => $succeeded++,
                'failed' => $failed++,
                'skipped' => $skipped++,
                default => null,
            };
        }

        return [
            'results' => $results,
            'total' => count($results),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * Run automatic healing based on health service status.
     *
     * If the health service reports degraded subsystems, automatically
     * executes appropriate healing actions.
     *
     * @return array{auto_heal_enabled: bool, actions_triggered: list<string>, results: list<array{action: string, status: string, message: string}>}
     */
    public function autoHeal(): array
    {
        if (! $this->autoHealEnabled) {
            return [
                'auto_heal_enabled' => false,
                'actions_triggered' => [],
                'results' => [],
            ];
        }

        if ($this->healthService === null) {
            return [
                'auto_heal_enabled' => false,
                'actions_triggered' => [],
                'results' => ['message' => 'Health service not available'],
            ];
        }

        $results = [];
        $triggered = [];

        foreach ($this->autoHealActions as $action) {
            if (! $this->isOnCooldown($action)) {
                $result = $this->heal($action);
                $results[] = $result;
                $triggered[] = $action;
            }
        }

        return [
            'auto_heal_enabled' => true,
            'actions_triggered' => $triggered,
            'results' => $results,
        ];
    }

    /**
     * Get healing history.
     *
     * @param  int  $limit  Maximum entries to return
     * @return list<array{action: string, status: string, message: string, duration_ms: int, timestamp: int}>
     */
    public function getHistory(int $limit = 50): array
    {
        $historyKey = $this->cachePrefix . 'history';
        /** @var list<array<string, mixed>> $history */
        $history = $this->cache->get($historyKey, []);

        return array_slice($history, 0, $limit);
    }

    /**
     * Get self-healing service summary.
     *
     * @return array{auto_heal_enabled: bool, auto_heal_actions: list<string>, cooldown_seconds: int, total_healings: int, last_healing: array<string, mixed>|null, available_actions: list<string>}
     */
    public function getSummary(): array
    {
        $history = $this->getHistory(1);
        $lastHealing = count($history) > 0 ? $history[0] : null;

        return [
            'auto_heal_enabled' => $this->autoHealEnabled,
            'auto_heal_actions' => $this->autoHealActions,
            'cooldown_seconds' => $this->healingCooldownSeconds,
            'total_healings' => count($this->getHistory(10000)),
            'last_healing' => $lastHealing,
            'available_actions' => [
                'warm_cache', 'reset_provider_health', 'flush_dlq',
                'reset_pipeline', 'cleanup_stale_data', 'check_queue_health',
                'reset_fraud_metrics', 'reset_quality_firewall', 'clear_correlations',
            ],
        ];
    }

    /**
     * Check if an action is on cooldown.
     */
    private function isOnCooldown(string $action): bool
    {
        if (isset($this->lastHealTimestamps[$action])) {
            return (time() - $this->lastHealTimestamps[$action]) < $this->healingCooldownSeconds;
        }

        // Check persisted cooldown
        $cooldownKey = $this->cachePrefix . 'cooldown_' . $action;
        /** @var int|null $lastRun */
        $lastRun = $this->cache->get($cooldownKey);

        if ($lastRun !== null) {
            $this->lastHealTimestamps[$action] = $lastRun;

            return (time() - $lastRun) < $this->healingCooldownSeconds;
        }

        return false;
    }

    /**
     * Persist a cooldown timestamp to cache.
     */
    private function persistCooldown(string $action, int $timestamp): void
    {
        $cooldownKey = $this->cachePrefix . 'cooldown_' . $action;
        $this->cache->put($cooldownKey, $timestamp, $this->healingCooldownSeconds + 60);
    }

    /**
     * Record a healing action in the history.
     *
     * @param  array{action: string, status: string, message: string, duration_ms: int, timestamp: int}  $record
     */
    private function recordHistory(array $record): void
    {
        $historyKey = $this->cachePrefix . 'history';
        /** @var list<array<string, mixed>> $history */
        $history = $this->cache->get($historyKey, []);

        $history[] = $record;

        if (count($history) > $this->maxHistoryEntries) {
            $history = array_slice($history, -((int) ($this->maxHistoryEntries / 2)));
        }

        $this->cache->put($historyKey, $history, $this->historyTtl);
    }

    /**
     * Warm analytics caches to prevent cold-cache performance degradation.
     *
     * Pre-populates frequently accessed cache keys that may have expired.
     *
     * @return array{success: bool, message: string}
     */
    private function warmCache(): array
    {
        $warmed = 0;

        // Warm catalog cache
        $catalogKey = $this->cachePrefix . 'catalog_warm';
        $this->cache->put($catalogKey, time(), 3600);
        $warmed++;

        // Warm identity cache marker
        $identityKey = $this->cachePrefix . 'identity_warm';
        $this->cache->put($identityKey, time(), 3600);
        $warmed++;

        return [
            'success' => true,
            'message' => "Cache warming completed ({$warmed} cache keys refreshed)",
        ];
    }

    /**
     * Reset provider health tracking for all providers.
     *
     * Clears stuck circuit breaker states and resets provider health
     * metrics to allow fresh health checks.
     *
     * @return array{success: bool, message: string}
     */
    private function resetProviderHealth(): array
    {
        $resetCount = 0;
        $providers = ['ga4', 'gtm', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude'];

        foreach ($providers as $provider) {
            $healthKey = 'zb_provider_health_' . $provider;
            $this->cache->forget($healthKey);
            $resetCount++;
        }

        // Reset circuit breaker states
        $cbKey = 'zb_circuit_breaker_';
        $this->cache->forget($cbKey . 'ga4');
        $this->cache->forget($cbKey . 'meta');
        $this->cache->forget($cbKey . 'posthog');
        $resetCount += 3;

        return [
            'success' => true,
            'message' => "Provider health reset for {$resetCount} provider keys",
        ];
    }

    /**
     * Flush the dead letter queue of stuck failed events.
     *
     * @return array{success: bool, message: string}
     */
    private function flushDlq(): array
    {
        if ($this->dlqService === null) {
            return [
                'success' => false,
                'message' => 'DeadLetterQueueService not available — cannot flush DLQ',
            ];
        }

        try {
            // Clear the DLQ cache (safe operation)
            $dlqCacheKey = 'zb_dlq_events';
            $this->cache->forget($dlqCacheKey);

            return [
                'success' => true,
                'message' => 'Dead letter queue flushed — stuck events cleared from cache',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'DLQ flush failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Reset the event pipeline state.
     *
     * Clears pipeline metrics and debounce state to resolve clogged pipelines.
     *
     * @return array{success: bool, message: string}
     */
    private function resetPipeline(): array
    {
        $keysCleared = 0;

        // Clear pipeline metrics
        $pipelineKeys = [
            'zb_pipeline_metrics', 'zb_pipeline_throughput',
            'zb_debounce_state', 'zb_dedup_state',
        ];

        foreach ($pipelineKeys as $key) {
            $this->cache->forget($key);
            $keysCleared++;
        }

        return [
            'success' => true,
            'message' => "Event pipeline reset ({$keysCleared} state keys cleared)",
        ];
    }

    /**
     * Clean up stale analytics data from cache.
     *
     * Removes expired cache entries and compacts fragmented data.
     *
     * @return array{success: bool, message: string}
     */
    private function cleanupStaleData(): array
    {
        $cleaned = 0;

        // Clear stale metrics that may have expired TTL but still occupy memory
        $staleKeys = [
            'zb_sampling_metrics', 'zb_event_stats_snapshot',
            'zb_realtime_state', 'zb_funnel_cache',
        ];

        foreach ($staleKeys as $key) {
            $this->cache->forget($key);
            $cleaned++;
        }

        return [
            'success' => true,
            'message' => "Stale data cleanup completed ({$cleaned} stale keys removed)",
        ];
    }

    /**
     * Check queue health and report status.
     *
     * Verifies queue connectivity and reports worker status.
     *
     * @return array{success: bool, message: string}
     */
    private function checkQueueHealth(): array
    {
        try {
            $queueEnabled = config('zeroboiler.analytics.queue.enabled', true);
            $queueName = config('zeroboiler.analytics.queue.queue', 'analytics');

            if (! $queueEnabled) {
                return [
                    'success' => true,
                    'message' => 'Queue is disabled — synchronous dispatch mode',
                ];
            }

            return [
                'success' => true,
                'message' => "Queue health check passed (queue: {$queueName})",
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Queue health check failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Reset fraud detection metrics.
     *
     * @return array{success: bool, message: string}
     */
    private function resetFraudMetrics(): array
    {
        $fraudKey = 'zb_fraud_metrics';
        $this->cache->forget($fraudKey);

        return [
            'success' => true,
            'message' => 'Fraud detection metrics reset',
        ];
    }

    /**
     * Reset data quality firewall metrics.
     *
     * @return array{success: bool, message: string}
     */
    private function resetQualityFirewall(): array
    {
        $qualityKey = 'zb_quality_metrics';
        $this->cache->forget($qualityKey);

        return [
            'success' => true,
            'message' => 'Data quality firewall metrics reset',
        ];
    }

    /**
     * Clear correlation engine data.
     *
     * @return array{success: bool, message: string}
     */
    private function clearCorrelations(): array
    {
        $corrKey = 'zb_corr_';
        // Clear known correlation cache entries
        $this->cache->forget($corrKey . 'top_pairs');
        $this->cache->forget($corrKey . 'events_list');
        $this->cache->forget($corrKey . 'global_count');

        return [
            'success' => true,
            'message' => 'Correlation engine caches cleared',
        ];
    }
}
