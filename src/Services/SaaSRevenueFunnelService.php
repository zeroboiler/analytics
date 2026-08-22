<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * SaaS Revenue Funnel Analytics Engine.
 *
 * Models the full SaaS revenue lifecycle as a multi-stage funnel:
 * Visit → Signup → Trial Start → Activation → Trial Convert → Subscription → Expansion → Renewal.
 *
 * For each stage transition, computes:
 * - **Conversion rate** (stage N → stage N+1)
 * - **Drop-off rate** (users who exit between stages)
 * - **Median time-to-convert** (days between stages)
 * - **Cohort-based conversion** (per sign-up cohort)
 *
 * Provides aggregate and per-cohort funnel snapshots, bottleneck detection,
 * and actionable recommendations for funnel optimization.
 *
 * Configuration: `zeroboiler.analytics.revenue_funnel`
 *
 * @phpstan-type FunnelStage array{key: string, name: string, event: string, order: int}
 * @phpstan-type StageMetrics array{count: int, conversion_rate: float|null, drop_off_rate: float|null, median_days: float|null, cohort_counts: array<string, int>}
 * @phpstan-type FunnelSnapshot array{stages: array<string, StageMetrics>, total_entered: int, overall_conversion: float|null, bottlenecks: list<string>, computed_at: string, period: string}
 *
 * @since 184.0.0
 */
final class SaaSRevenueFunnelService
{
    private const CACHE_PREFIX = 'zb_rev_funnel_';

    private const DEFAULT_TTL = 3600; // 1 hour

    /** @var array<string, FunnelStage> Default funnel stages */
    private const DEFAULT_STAGES = [
        'visit' => ['key' => 'visit', 'name' => 'Visit', 'event' => 'page_view', 'order' => 0],
        'signup' => ['key' => 'signup', 'name' => 'Sign Up', 'event' => 'sign_up', 'order' => 1],
        'trial_start' => ['key' => 'trial_start', 'name' => 'Trial Start', 'event' => 'start_trial', 'order' => 2],
        'activation' => ['key' => 'activation', 'name' => 'Activation', 'event' => 'feature_used', 'order' => 3],
        'trial_convert' => ['key' => 'trial_convert', 'name' => 'Trial Convert', 'event' => 'subscription.created', 'order' => 4],
        'expansion' => ['key' => 'expansion', 'name' => 'Expansion', 'event' => 'subscription.upgraded', 'order' => 5],
        'renewal' => ['key' => 'renewal', 'name' => 'Renewal', 'event' => 'subscription.renewal', 'order' => 6],
    ];

    private CacheRepository $cache;

    private AnalyticsManager $manager;

    private bool $enabled;

    private int $cacheTtl;

    /** @var array<string, FunnelStage> */
    private array $stages;

    private string $cohortGranularity;

    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->manager = $manager;
        $this->cache = $cache;

        $funnelConfig = $config->get('zeroboiler.analytics.revenue_funnel', []);
        /** @var array{enabled?: bool, cache_ttl?: int, stages?: array<string, FunnelStage>, cohort_granularity?: string} $funnelConfig */
        $this->enabled = (bool) ($funnelConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($funnelConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->stages = (array) ($funnelConfig['stages'] ?? self::DEFAULT_STAGES);
        $this->cohortGranularity = (string) ($funnelConfig['cohort_granularity'] ?? 'daily');
    }

    /**
     * Record a user entering a specific funnel stage.
     *
     * @param  string  $userId  User identifier
     * @param  string  $stage  Funnel stage key (e.g. 'signup', 'trial_start')
     * @param  array<string, mixed>  $context  Additional context (plan, source, etc.)
     */
    public function recordStageEntry(string $userId, string $stage, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        if (! isset($this->stages[$stage])) {
            return;
        }

        $event = new AnalyticsEvent(
            name: 'revenue_funnel_stage',
            params: array_merge($context, [
                'funnel_stage' => $stage,
                'funnel_stage_name' => $this->stages[$stage]['name'],
                'funnel_stage_order' => $this->stages[$stage]['order'],
                'user_id' => $userId,
            ]),
        );

        $this->manager->trackEvent($event);

        // Update stage entry counts in cache
        $cacheKey = self::CACHE_PREFIX . 'stage_' . $stage;
        $current = (int) $this->cache->get($cacheKey, 0);
        $this->cache->put($cacheKey, $current + 1, $this->cacheTtl);

        // Update cohort counts
        $cohortKey = $this->getCohortKey();
        $cohortCacheKey = self::CACHE_PREFIX . 'cohort_' . $cohortKey . '_' . $stage;
        $cohortCurrent = (int) $this->cache->get($cohortCacheKey, 0);
        $this->cache->put($cohortCacheKey, $cohortCurrent + 1, $this->cacheTtl * 24 * 7); // 7 days for cohort data

        // Record stage transition timing
        $prevStages = $this->getOrderedPreviousStages($stage);
        foreach ($prevStages as $prevStage) {
            $timingKey = self::CACHE_PREFIX . 'timing_' . $prevStage . '_' . $stage;
            $timings = (array) $this->cache->get($timingKey, []);
            $timings[] = now()->getTimestamp();
            // Keep last 1000 timing entries
            if (count($timings) > 1000) {
                $timings = array_slice($timings, -1000);
            }
            $this->cache->put($timingKey, $timings, $this->cacheTtl);
        }
    }

    /**
     * Get the current funnel snapshot with conversion rates.
     *
     * @param  string|null  $period  Period scope ('1h', '24h', '7d', '30d')
     * @return FunnelSnapshot
     */
    public function getSnapshot(?string $period = null): array
    {
        $cacheKey = self::CACHE_PREFIX . 'snapshot_' . ($period ?? 'all');
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null && is_array($cached)) {
            /** @var FunnelSnapshot $cached */
            return $cached;
        }

        $snapshot = $this->computeSnapshot($period);
        $this->cache->put($cacheKey, $snapshot, $this->cacheTtl);

        return $snapshot;
    }

    /**
     * Get conversion rate between two specific stages.
     *
     * @param  string  $fromStage  Source stage key
     * @param  string  $toStage  Destination stage key
     * @return float|null Conversion rate as decimal (0.0–1.0), null if insufficient data
     */
    public function getConversionRate(string $fromStage, string $toStage): ?float
    {
        $fromCount = (int) $this->cache->get(self::CACHE_PREFIX . 'stage_' . $fromStage, 0);
        $toCount = (int) $this->cache->get(self::CACHE_PREFIX . 'stage_' . $toStage, 0);

        if ($fromCount === 0) {
            return null;
        }

        return min(1.0, $toCount / $fromCount);
    }

    /**
     * Get median time-to-convert between two stages (in days).
     *
     * @param  string  $fromStage  Source stage key
     * @param  string  $toStage  Destination stage key
     * @return float|null Median days, null if insufficient data
     */
    public function getMedianTimeToConvert(string $fromStage, string $toStage): ?float
    {
        $timingKey = self::CACHE_PREFIX . 'timing_' . $fromStage . '_' . $toStage;
        $timings = (array) $this->cache->get($timingKey, []);

        if (count($timings) < 2) {
            return null;
        }

        $sorted = array_values($timings);
        sort($sorted);
        $count = count($sorted);
        $mid = (int) floor($count / 2);

        if ($count % 2 === 0) {
            return (($sorted[$mid - 1] + $sorted[$mid]) / 2) / 86400.0;
        }

        return $sorted[$mid] / 86400.0;
    }

    /**
     * Detect bottleneck stages in the funnel (highest drop-off).
     *
     * @return list<array{stage: string, name: string, drop_off_rate: float|null, recommendation: string}>
     */
    public function detectBottlenecks(): array
    {
        $snapshot = $this->getSnapshot();

        if (empty($snapshot['bottlenecks'])) {
            return [];
        }

        $result = [];
        foreach ($snapshot['bottlenecks'] as $stageKey) {
            $stage = $this->stages[$stageKey] ?? null;
            if ($stage === null) {
                continue;
            }
            $metrics = $snapshot['stages'][$stageKey] ?? null;
            $result[] = [
                'stage' => $stageKey,
                'name' => $stage['name'],
                'drop_off_rate' => $metrics['drop_off_rate'] ?? null,
                'recommendation' => $this->getRecommendation($stageKey, $metrics['drop_off_rate'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * Get cohort-based funnel conversion rates.
     *
     * @param  string  $cohortKey  Cohort identifier (e.g. '2026-08-16' for daily)
     * @return array<string, StageMetrics>
     */
    public function getCohortFunnel(string $cohortKey): array
    {
        $stages = [];
        $orderedStages = $this->getOrderedStages();

        foreach ($orderedStages as $stage) {
            $cacheKey = self::CACHE_PREFIX . 'cohort_' . $cohortKey . '_' . $stage['key'];
            $count = (int) $this->cache->get($cacheKey, 0);

            $stages[$stage['key']] = [
                'count' => $count,
                'conversion_rate' => null,
                'drop_off_rate' => null,
                'median_days' => null,
                'cohort_counts' => [],
            ];
        }

        // Calculate conversion rates between consecutive stages
        $prevCount = null;
        foreach ($stages as $key => &$metrics) {
            if ($prevCount !== null && $prevCount > 0) {
                $metrics['conversion_rate'] = round($metrics['count'] / $prevCount, 4);
                $metrics['drop_off_rate'] = round(1.0 - $metrics['count'] / $prevCount, 4);
            }
            $prevCount = $metrics['count'];
        }

        return $stages;
    }

    /**
     * Get the funnel stage definitions.
     *
     * @return array<string, FunnelStage>
     */
    public function getStages(): array
    {
        return $this->stages;
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Clear all cached funnel data.
     */
    public function clearCache(): void
    {
        // Clear is not available on all cache drivers; use forget on known keys
        foreach ($this->stages as $stageKey => $_stage) {
            $this->cache->forget(self::CACHE_PREFIX . 'stage_' . $stageKey);
        }

        // Clear timing keys
        $orderedStages = $this->getOrderedStages();
        foreach ($orderedStages as $stage) {
            $prevStages = $this->getOrderedPreviousStages($stage['key']);
            foreach ($prevStages as $prevStage) {
                $this->cache->forget(self::CACHE_PREFIX . 'timing_' . $prevStage . '_' . $stage['key']);
            }
        }

        $this->cache->forget(self::CACHE_PREFIX . 'snapshot_all');
    }

    /**
     * Compute the funnel snapshot from cached stage counts.
     *
     * @param  string|null  $period  Period scope
     * @return FunnelSnapshot
     */
    private function computeSnapshot(?string $period = null): array
    {
        $orderedStages = $this->getOrderedStages();
        $stages = [];
        $bottlenecks = [];
        $maxDropOff = 0.0;

        $prevCount = null;
        foreach ($orderedStages as $stage) {
            $count = (int) $this->cache->get(self::CACHE_PREFIX . 'stage_' . $stage['key'], 0);
            $conversionRate = null;
            $dropOffRate = null;

            if ($prevCount !== null && $prevCount > 0) {
                $conversionRate = round($count / $prevCount, 4);
                $dropOffRate = round(1.0 - $conversionRate, 4);

                if ($dropOffRate > $maxDropOff && $dropOffRate > 0.5) {
                    $maxDropOff = $dropOffRate;
                }
            }

            if ($dropOffRate !== null && $dropOffRate > 0.5 && count($bottlenecks) < 3) {
                $bottlenecks[] = $stage['key'];
            }

            $stages[$stage['key']] = [
                'count' => $count,
                'conversion_rate' => $conversionRate,
                'drop_off_rate' => $dropOffRate,
                'median_days' => null,
                'cohort_counts' => [],
            ];

            // Add median time from previous stage
            if ($prevCount !== null) {
                $prevStageKey = $this->getPreviousStageKey($stage['key']);
                if ($prevStageKey !== null) {
                    $stages[$stage['key']]['median_days'] = $this->getMedianTimeToConvert($prevStageKey, $stage['key']);
                }
            }

            $prevCount = $count;
        }

        $firstStage = $orderedStages[0] ?? null;
        $lastStage = $orderedStages[array_key_last($orderedStages)] ?? null;
        $overallConversion = null;

        if ($firstStage !== null && $lastStage !== null) {
            $firstCount = (int) $this->cache->get(self::CACHE_PREFIX . 'stage_' . $firstStage['key'], 0);
            $lastCount = (int) $this->cache->get(self::CACHE_PREFIX . 'stage_' . $lastStage['key'], 0);
            if ($firstCount > 0) {
                $overallConversion = round($lastCount / $firstCount, 6);
            }
        }

        return [
            'stages' => $stages,
            'total_entered' => $stages[array_key_first($stages)]['count'] ?? 0,
            'overall_conversion' => $overallConversion,
            'bottlenecks' => $bottlenecks,
            'computed_at' => now()->toIso8601String(),
            'period' => $period ?? 'all',
        ];
    }

    /**
     * Get stages ordered by their `order` field.
     *
     * @return list<FunnelStage>
     */
    private function getOrderedStages(): array
    {
        $stages = array_values($this->stages);
        usort($stages, fn (array $a, array $b): int => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        return $stages;
    }

    /**
     * Get the key of the stage immediately before a given stage.
     */
    private function getPreviousStageKey(string $stageKey): ?string
    {
        $ordered = $this->getOrderedStages();

        for ($i = 1; $i < count($ordered); $i++) {
            if ($ordered[$i]['key'] === $stageKey) {
                return $ordered[$i - 1]['key'];
            }
        }

        return null;
    }

    /**
     * Get all stage keys that come before a given stage.
     *
     * @return list<string>
     */
    private function getOrderedPreviousStages(string $stageKey): array
    {
        $ordered = $this->getOrderedStages();
        $prev = [];

        foreach ($ordered as $s) {
            if ($s['key'] === $stageKey) {
                break;
            }
            $prev[] = $s['key'];
        }

        return $prev;
    }

    /**
     * Get a cohort key based on configured granularity.
     */
    private function getCohortKey(): string
    {
        return match ($this->cohortGranularity) {
            'weekly' => now()->startOfWeek()->format('Y-W'),
            'monthly' => now()->startOfMonth()->format('Y-m'),
            default => now()->format('Y-m-d'),
        };
    }

    /**
     * Generate an actionable recommendation for a bottleneck stage.
     *
     * @param  string  $stageKey  Bottleneck stage key
     * @param  float|null  $dropOffRate  Drop-off rate (0.0–1.0)
     * @return string  Human-readable recommendation
     */
    private function getRecommendation(string $stageKey, ?float $dropOffRate): string
    {
        $recommendations = [
            'visit' => 'Optimize landing page CTA, reduce friction in signup form, A/B test hero sections.',
            'signup' => 'Simplify registration flow, add social login options, reduce form fields.',
            'trial_start' => 'Add in-product prompts for trial, highlight key features, offer extended trial.',
            'activation' => 'Improve onboarding flow, add guided tours, surface key features faster.',
            'trial_convert' => 'Strengthen in-trial value demonstration, add upgrade nudges at activation milestones.',
            'expansion' => 'Identify and promote high-value features, add usage-based upgrade triggers.',
            'renewal' => 'Implement renewal reminders, highlight ROI metrics, offer loyalty discounts.',
        ];

        $base = $recommendations[$stageKey] ?? 'Investigate user drop-off at this stage with session replay and surveys.';

        if ($dropOffRate !== null && $dropOffRate > 0.8) {
            return '⚠️ CRITICAL: ' . $base;
        }

        if ($dropOffRate !== null && $dropOffRate > 0.6) {
            return '🔧 HIGH: ' . $base;
        }

        return $base;
    }
}
