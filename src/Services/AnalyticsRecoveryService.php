<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;

/**
 * Analytics Recovery Service — DLQ recovery with retry budget tracking and health assessment.
 *
 * Provides advanced dead letter queue management:
 * - Batch recovery with configurable batch sizes
 * - Retry budget tracking (max recoveries per period)
 * - Recovery history with success/failure statistics
 * - Health assessment of the recovery pipeline
 * - Automatic recovery eligibility scoring for DLQ events
 *
 * Wraps DeadLetterQueueService with enterprise-grade recovery management.
 */
final class AnalyticsRecoveryService
{
    private bool $enabled;

    private int $cacheTtl;

    private int $maxRecoveriesPerHour;

    private int $batchSize;

    private CacheRepository $cache;

    private ?DeadLetterQueueService $dlqService;

    private ?AnalyticsManager $manager;

    /**
     * Create a new AnalyticsRecoveryService instance.
     *
     * @param  AnalyticsManager  $manager  Analytics manager for dispatching recovered events
     * @param  ConfigRepository  $config  Application config
     * @param  DeadLetterQueueService|null  $dlqService  DLQ service (optional for testing)
     * @param  CacheRepository|null  $cache  Cache driver (optional)
     */
    public function __construct(
        AnalyticsManager $manager,
        ConfigRepository $config,
        ?DeadLetterQueueService $dlqService = null,
        ?CacheRepository $cache = null,
    ): void {
        $recoveryConfig = $config->get('zeroboiler.analytics.recovery', []);
        /** @var array{enabled?: bool, cache_ttl?: int, max_recoveries_per_hour?: int, batch_size?: int} $recoveryConfig */

        $this->enabled = (bool) ($recoveryConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($recoveryConfig['cache_ttl'] ?? 300);
        $this->maxRecoveriesPerHour = (int) ($recoveryConfig['max_recoveries_per_hour'] ?? 100);
        $this->batchSize = (int) ($recoveryConfig['batch_size'] ?? 10);
        $this->manager = $manager;
        $this->dlqService = $dlqService;
        $this->cache = $cache ?? app('cache')->driver();
    }

    /**
     * Get the recovery budget — how many recoveries are remaining this hour.
     *
     * @return array{remaining: int, max: int, used: int, resets_at: string}
     */
    public function getBudget(): array
    {
        $cacheKey = 'zb_recovery_budget';
        $now = now();
        $windowKey = $cacheKey . '_window_' . $now->format('Y-m-d-H');

        $used = (int) $this->cache->get($windowKey, 0);

        return [
            'remaining' => max(0, $this->maxRecoveriesPerHour - $used),
            'max' => $this->maxRecoveriesPerHour,
            'used' => $used,
            'resets_at' => $now->endOfHour()->toIso8601String(),
        ];
    }

    /**
     * Record a recovery attempt (increments budget counter).
     */
    public function recordRecovery(): void
    {
        $cacheKey = 'zb_recovery_budget';
        $now = now();
        $windowKey = $cacheKey . '_window_' . $now->format('Y-m-d-H');

        $this->cache->increment($windowKey);
        $this->cache->put($windowKey, (int) $this->cache->get($windowKey, 0), 3600);
    }

    /**
     * Check if recovery is within budget.
     */
    public function hasBudgetRemaining(): bool
    {
        $budget = $this->getBudget();

        return $budget['remaining'] > 0;
    }

    /**
     * Perform a batch recovery of DLQ events.
     *
     * Recovers up to `$count` events from the DLQ, dispatching each
     * through the analytics manager. Records successes and failures.
     *
     * @param  int  $count  Number of events to recover (max: batch_size)
     * @return array{recovered: int, failed: int, details: list<array{offset: int, success: bool, error?: string}>}
     */
    public function batchRecover(int $count = 0): array
    {
        if ($this->dlqService === null) {
            return ['recovered' => 0, 'failed' => 0, 'details' => []];
        }

        $count = $count > 0 ? min($count, $this->batchSize) : $this->batchSize;

        if (! $this->hasBudgetRemaining()) {
            return ['recovered' => 0, 'failed' => 0, 'details' => [['offset' => 0, 'success' => false, 'error' => 'recovery_budget_exceeded']]];
        }

        $dlqSummary = $this->dlqService->summary();
        $totalEvents = (int) ($dlqSummary['count'] ?? 0);
        $toRecover = min($count, $totalEvents);

        $recovered = 0;
        $failed = 0;
        $details = [];

        for ($i = 0; $i < $toRecover; $i++) {
            try {
                $event = $this->dlqService->replaySingle($i);
                $this->manager->trackEvent($event);
                $this->recordRecovery();
                $recovered++;
                $details[] = ['offset' => $i, 'success' => true];
            } catch (\Throwable $e) {
                $failed++;
                $details[] = ['offset' => $i, 'success' => false, 'error' => $e->getMessage()];
            }
        }

        return [
            'recovered' => $recovered,
            'failed' => $failed,
            'details' => $details,
        ];
    }

    /**
     * Get recovery history summary.
     *
     * Tracks recovery attempts over the past 24 hours.
     *
     * @return array{total_recovered_24h: int, total_failed_24h: int, last_recovery: string|null, budget: array}
     */
    public function getHistory(): array
    {
        $historyKey = 'zb_recovery_history';
        $history = $this->cache->get($historyKey);

        if (! is_array($history)) {
            $history = [
                'recovered_24h' => 0,
                'failed_24h' => 0,
                'last_recovery_at' => null,
            ];
        }

        return [
            'total_recovered_24h' => (int) ($history['recovered_24h'] ?? 0),
            'total_failed_24h' => (int) ($history['failed_24h'] ?? 0),
            'last_recovery' => $history['last_recovery_at'],
            'budget' => $this->getBudget(),
        ];
    }

    /**
     * Record recovery results in history.
     *
     * @param  int  $recovered  Number of successfully recovered events
     * @param  int  $failed  Number of failed recovery attempts
     */
    public function recordHistory(int $recovered, int $failed): void
    {
        $historyKey = 'zb_recovery_history';
        $history = $this->cache->get($historyKey);

        if (! is_array($history)) {
            $history = ['recovered_24h' => 0, 'failed_24h' => 0, 'last_recovery_at' => null];
        }

        $history['recovered_24h'] = ($history['recovered_24h'] ?? 0) + $recovered;
        $history['failed_24h'] = ($history['failed_24h'] ?? 0) + $failed;
        $history['last_recovery_at'] = now()->toIso8601String();

        $this->cache->put($historyKey, $history, 86400);
    }

    /**
     * Assess the health of the recovery pipeline.
     *
     * @return array{status: string, dlq_size: int, budget_remaining: int, recovery_rate_24h: float|null, health_score: int}
     */
    public function assessHealth(): array
    {
        $dlqSize = 0;
        if ($this->dlqService !== null) {
            $summary = $this->dlqService->summary();
            $dlqSize = (int) ($summary['count'] ?? 0);
        }

        $budget = $this->getBudget();
        $history = $this->getHistory();

        $totalAttempts = $history['total_recovered_24h'] + $history['total_failed_24h'];
        $recoveryRate = $totalAttempts > 0
            ? round(($history['total_recovered_24h'] / $totalAttempts) * 100, 1)
            : null;

        // Health score: 0-100
        $score = 100;
        $score -= min(30, $dlqSize); // Penalize large DLQ
        $score -= ($budget['remaining'] === 0) ? 30 : 0; // Penalize exhausted budget
        $score -= ($recoveryRate !== null && $recoveryRate < 50) ? 20 : 0; // Penalize low recovery rate
        $score = max(0, (int) round($score));

        $status = $score >= 80 ? 'healthy' : ($score >= 50 ? 'degraded' : 'critical');

        return [
            'status' => $status,
            'dlq_size' => $dlqSize,
            'budget_remaining' => $budget['remaining'],
            'recovery_rate_24h' => $recoveryRate,
            'health_score' => $score,
        ];
    }

    /**
     * Get a summary of the recovery service.
     *
     * @return array{enabled: bool, batch_size: int, max_recoveries_per_hour: int, dlq_size: int, health: array}
     */
    public function summary(): array
    {
        $dlqSize = 0;
        if ($this->dlqService !== null) {
            $summary = $this->dlqService->summary();
            $dlqSize = (int) ($summary['count'] ?? 0);
        }

        return [
            'enabled' => $this->enabled,
            'batch_size' => $this->batchSize,
            'max_recoveries_per_hour' => $this->maxRecoveriesPerHour,
            'dlq_size' => $dlqSize,
            'health' => $this->assessHealth(),
        ];
    }

    /**
     * Check if the recovery service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
