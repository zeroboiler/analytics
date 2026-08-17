<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\EventSNRResult;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Events\Marketing\MarketingEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Events\Webhook\WebhookEvents;

/**
 * Event Signal-to-Noise Ratio (SNR) Calculator — measure per-event analytics value.
 *
 * Calculates a Signal-to-Noise Ratio (0-100) for each event in the catalog,
 * measuring how much actionable insight an event provides relative to its
 * operational cost. Events are classified as:
 *
 * - **Signal (SNR ≥ 70)**: High-value events that drive insights — keep tracking
 * - **Moderate (40 ≤ SNR < 70)**: Useful but could be optimized
 * - **Noise Candidate (20 ≤ SNR < 40)**: Low-value events to review for pruning
 * - **Noise (SNR < 20)**: Pure noise — remove or sample aggressively
 *
 * SNR is computed from 4 weighted dimensions:
 * 1. **Actionability** (35%): Can downstream systems act on this event?
 * 2. **Correlation** (30%): Does this event correlate with conversion/retention?
 * 3. **Uniqueness** (20%): Does this event provide unique info not available elsewhere?
 * 4. **Cost Efficiency** (15%): Is the insight per dispatch cost high?
 *
 * Results are cached and used by EventPruningAdvisorService to generate
 * removal/reduction/merge recommendations.
 *
 * Inspired by Segment's Event Value Analysis, Amplitude's Event Scoring,
 * and information theory's signal-to-noise ratio concept.
 *
 * Configuration: `zeroboiler.analytics.event_snr`
 *
 * @phpstan-type SNRReport array{total_events: int, signal_count: int, moderate_count: int, noise_candidate_count: int, noise_count: int, average_snr: float, median_snr: float, weighted_snr: float, total_monthly_cost: float, top_signal_events: list<string>, top_noise_events: list<string>, events: array<string, EventSNRResult>, grades: array<string, int>, category_summary: array<string, array{count: int, avg_snr: float, total_cost: float}>, computed_at: string}
 *
 * @since 220.0.0
 *
 * @see \ZeroBoiler\Analytics\DTO\EventSNRResult
 * @see \ZeroBoiler\Analytics\Services\EventPruningAdvisorService
 */
final class EventSNRCalculatorService
{
    private const CACHE_PREFIX = 'zb_event_snr_';

    /**
     * Built-in actionability scores for known events.
     * Higher = more actionable by downstream systems (funnel, cohort, attribution).
     *
     * @var array<string, float>
     */
    private const ACTIONABILITY_MAP = [
        // E-commerce (high actionability — direct revenue signals)
        'purchase' => 98.0,
        'add_to_cart' => 92.0,
        'begin_checkout' => 90.0,
        'add_payment_info' => 88.0,
        'view_item' => 80.0,
        'refund' => 85.0,
        'remove_from_cart' => 70.0,
        'view_cart' => 60.0,
        'add_to_wishlist' => 65.0,
        'select_item' => 72.0,
        'select_promotion' => 68.0,
        'view_promotion' => 55.0,
        'checkout_step' => 82.0,
        'abandoned_cart' => 78.0,
        'checkout_abandon' => 75.0,

        // SaaS lifecycle (high actionability — product metrics)
        'sign_up' => 97.0,
        'login' => 45.0,
        'logout' => 15.0,
        'start_trial' => 96.0,
        'trial_end' => 88.0,
        'subscribe' => 95.0,
        'plan_upgrade' => 93.0,
        'plan_downgrade' => 85.0,
        'cancellation' => 92.0,
        'feature_used' => 75.0,
        'revenue_tracked' => 90.0,
        'trial_converted' => 94.0,

        // Engagement (moderate actionability — behavioral signals)
        'page_view' => 35.0,
        'scroll_depth' => 42.0,
        'click' => 50.0,
        'form_start' => 72.0,
        'form_submit' => 85.0,
        'search' => 70.0,
        'share' => 65.0,
        'error' => 80.0,
        'time_on_page' => 38.0,
        'session_start' => 55.0,
        'session_end' => 25.0,
        'file_download' => 60.0,
        'video_play' => 58.0,
        'web_vitals' => 70.0,
        'js_error' => 78.0,
        'content_engagement' => 62.0,
        'onboarding_step' => 80.0,
        'onboarding_completed' => 90.0,
        'performance_score' => 68.0,

        // Default for unknown events
        '__default__' => 50.0,
    ];

    /**
     * Built-in correlation scores for known events.
     * Higher = stronger correlation with conversion/retention outcomes.
     *
     * @var array<string, float>
     */
    private const CORRELATION_MAP = [
        'purchase' => 98.0,
        'subscribe' => 96.0,
        'trial_converted' => 95.0,
        'begin_checkout' => 90.0,
        'add_to_cart' => 88.0,
        'plan_upgrade' => 85.0,
        'form_submit' => 82.0,
        'sign_up' => 80.0,
        'start_trial' => 78.0,
        'cancellation' => 75.0,
        'feature_used' => 72.0,
        'search' => 68.0,
        'page_view' => 40.0,
        'scroll_depth' => 45.0,
        'click' => 35.0,
        'session_start' => 30.0,
        'login' => 25.0,
        'logout' => 10.0,
        'time_on_page' => 30.0,
        'error' => 20.0,
        '__default__' => 40.0,
    ];

    /**
     * Built-in uniqueness scores for known events.
     * Higher = this event provides info not available from other events.
     *
     * @var array<string, float>
     */
    private const UNIQUENESS_MAP = [
        'purchase' => 95.0,
        'refund' => 92.0,
        'cancellation' => 90.0,
        'plan_upgrade' => 88.0,
        'subscribe' => 85.0,
        'sign_up' => 82.0,
        'start_trial' => 80.0,
        'error' => 78.0,
        'web_vitals' => 85.0,
        'js_error' => 82.0,
        'search' => 75.0,
        'form_submit' => 70.0,
        'page_view' => 20.0,
        'session_start' => 25.0,
        'login' => 15.0,
        'logout' => 10.0,
        'scroll_depth' => 50.0,
        'time_on_page' => 30.0,
        'click' => 35.0,
        'view_cart' => 40.0,
        '__default__' => 45.0,
    ];

    /** @var array<string, float> Per-category dispatch volume share assumptions */
    private const CATEGORY_VOLUME_WEIGHTS = [
        'ecommerce' => 0.15,
        'saas' => 0.20,
        'engagement' => 0.55,
        'security' => 0.02,
        'uptime' => 0.02,
        'infrastructure' => 0.02,
        'marketing' => 0.03,
        'customer_success' => 0.01,
        'webhook' => 0.00,
    ];

    /** SNR threshold constants */
    private const SIGNAL_THRESHOLD = 70.0;

    private const MODERATE_THRESHOLD = 40.0;

    private const NOISE_CANDIDATE_THRESHOLD = 20.0;

    /** Dimension weights (must sum to 1.0) */
    private const WEIGHT_ACTIONABILITY = 0.35;

    private const WEIGHT_CORRELATION = 0.30;

    private const WEIGHT_UNIQUENESS = 0.20;

    private const WEIGHT_COST_EFFICIENCY = 0.15;

    /** Grade thresholds */
    private const GRADE_A_PLUS = 90.0;

    private const GRADE_A = 80.0;

    private const GRADE_B = 70.0;

    private const GRADE_C = 55.0;

    private const GRADE_D = 40.0;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     * @param  AnalyticsMetrics  $metrics
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
        private readonly AnalyticsMetrics $metrics,
    ) {}

    /**
     * Calculate SNR for a single event.
     *
     * @param  string  $eventName  Canonical event name
     * @param  array{actionability?: float, correlation?: float, uniqueness?: float, dispatch_count?: int, dispatch_share?: float, cost_per_dispatch?: float}  $overrides  Optional score/count overrides for testing
     * @return EventSNRResult
     */
    public function calculate(string $eventName, array $overrides = []): EventSNRResult
    {
        $catalogEntry = EventCatalog::get($eventName);
        $category = $catalogEntry !== null ? ($catalogEntry['category'] ?? 'custom') : 'custom';
        $dispatchCount = $overrides['dispatch_count'] ?? $this->estimateDispatchCount($eventName);
        $totalDispatch = $overrides['dispatch_count'] ?? $this->estimateTotalDispatches();
        $dispatchShare = $overrides['dispatch_share'] ?? $this->dispatchShare($eventName, $dispatchCount, $totalDispatch);

        $actionability = $overrides['actionability'] ?? $this->actionabilityScore($eventName);
        $correlation = $overrides['correlation'] ?? $this->correlationScore($eventName);
        $uniqueness = $overrides['uniqueness'] ?? $this->uniquenessScore($eventName);

        $costPerDispatch = $overrides['cost_per_dispatch'] ?? $this->costPerDispatch();
        $totalCost = (float) $dispatchCount * $costPerDispatch;

        $costEfficiency = $this->costEfficiencyScore($eventName, $dispatchCount, $costPerDispatch);

        $snr = $this->computeSNR($actionability, $correlation, $uniqueness, $costEfficiency);
        $grade = $this->gradeFromSNR($snr);
        $verdict = $this->verdictFromSNR($snr);

        return new EventSNRResult(
            eventName: $eventName,
            category: $category,
            dispatchCount: $dispatchCount,
            dispatchShare: $dispatchShare,
            actionabilityScore: $actionability,
            correlationScore: $correlation,
            uniquenessScore: $uniqueness,
            costPerDispatch: $costPerDispatch,
            totalCost: $totalCost,
            snr: $snr,
            grade: $grade,
            verdict: $verdict,
        );
    }

    /**
     * Generate a full SNR report for all catalog events.
     *
     * @param  bool  $fresh  Force recalculation (bypass cache)
     * @return SNRReport
     */
    public function report(bool $fresh = false): array
    {
        $cacheKey = self::CACHE_PREFIX . 'report';

        if (! $fresh) {
            $cached = $this->cache->get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $events = $this->calculateAllEvents();
        $report = $this->buildReport($events);

        $cacheTtl = $this->getCacheTtl();
        $this->cache->put($cacheKey, $report, $cacheTtl);

        return $report;
    }

    /**
     * Get SNR results for all catalog events.
     *
     * @return array<string, EventSNRResult>
     */
    public function allResults(): array
    {
        $report = $this->report();

        return $report['events'];
    }

    /**
     * Get only the top signal events (SNR ≥ threshold).
     *
     * @param  float  $threshold  Minimum SNR (default: 70)
     * @param  int  $limit  Maximum results
     * @return list<EventSNRResult>
     */
    public function topSignalEvents(float $threshold = self::SIGNAL_THRESHOLD, int $limit = 20): array
    {
        $all = $this->allResults();

        $signal = array_filter(
            $all,
            fn (EventSNRResult $r): bool => $r->snr >= $threshold,
        );

        usort($signal, fn (EventSNRResult $a, EventSNRResult $b): int => $b->snr <=> $a->snr);

        return array_slice($signal, 0, $limit);
    }

    /**
     * Get only the noise events (SNR < threshold).
     *
     * @param  float  $threshold  Maximum SNR for noise (default: 20)
     * @param  int  $limit  Maximum results
     * @return list<EventSNRResult>
     */
    public function noiseEvents(float $threshold = self::NOISE_CANDIDATE_THRESHOLD, int $limit = 20): array
    {
        $all = $this->allResults();

        $noise = array_filter(
            $all,
            fn (EventSNRResult $r): bool => $r->snr < $threshold,
        );

        usort($noise, fn (EventSNRResult $a, EventSNRResult $b): int => $a->snr <=> $b->snr);

        return array_slice($noise, 0, $limit);
    }

    /**
     * Get events grouped by verdict.
     *
     * @return array{signal: list<EventSNRResult>, moderate: list<EventSNRResult>, noise_candidate: list<EventSNRResult>, noise: list<EventSNRResult>}
     */
    public function groupedByVerdict(): array
    {
        $all = $this->allResults();
        $groups = [
            'signal' => [],
            'moderate' => [],
            'noise_candidate' => [],
            'noise' => [],
        ];

        foreach ($all as $result) {
            $groups[$result->verdict][] = $result;
        }

        // Sort each group by SNR descending
        foreach ($groups as &$group) {
            usort($group, fn (EventSNRResult $a, EventSNRResult $b): int => $b->snr <=> $a->snr);
        }

        return $groups;
    }

    /**
     * Get category-level SNR summary.
     *
     * @return array<string, array{count: int, avg_snr: float, total_cost: float, signal_count: int, noise_count: int}>
     */
    public function categorySummary(): array
    {
        $report = $this->report();

        return $report['category_summary'];
    }

    /**
     * Get the overall average SNR across all events.
     */
    public function averageSNR(): float
    {
        return $this->report()['average_snr'];
    }

    /**
     * Invalidate the cached SNR report.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'report');
    }

    /**
     * Get the current configuration.
     *
     * @return array{enabled: bool, cache_ttl: int, cost_per_dispatch: float, weights: array<string, float>, thresholds: array<string, float>}
     */
    public function getConfig(): array
    {
        $snrConfig = $this->config->get('zeroboiler.analytics.event_snr', []);

        return [
            'enabled' => (bool) ($snrConfig['enabled'] ?? true),
            'cache_ttl' => $this->getCacheTtl(),
            'cost_per_dispatch' => $this->costPerDispatch(),
            'weights' => [
                'actionability' => self::WEIGHT_ACTIONABILITY,
                'correlation' => self::WEIGHT_CORRELATION,
                'uniqueness' => self::WEIGHT_UNIQUENESS,
                'cost_efficiency' => self::WEIGHT_COST_EFFICIENCY,
            ],
            'thresholds' => [
                'signal' => self::SIGNAL_THRESHOLD,
                'moderate' => self::MODERATE_THRESHOLD,
                'noise_candidate' => self::NOISE_CANDIDATE_THRESHOLD,
            ],
        ];
    }

    /**
     * Check if the SNR calculator is enabled.
     */
    public function isEnabled(): bool
    {
        $snrConfig = $this->config->get('zeroboiler.analytics.event_snr', []);

        return (bool) ($snrConfig['enabled'] ?? true);
    }

    /**
     * Get the actionability score for an event.
     */
    private function actionabilityScore(string $eventName): float
    {
        return self::ACTIONABILITY_MAP[$eventName] ?? self::ACTIONABILITY_MAP['__default__'];
    }

    /**
     * Get the correlation score for an event.
     */
    private function correlationScore(string $eventName): float
    {
        return self::CORRELATION_MAP[$eventName] ?? self::CORRELATION_MAP['__default__'];
    }

    /**
     * Get the uniqueness score for an event.
     */
    private function uniquenessScore(string $eventName): float
    {
        return self::UNIQUENESS_MAP[$eventName] ?? self::UNIQUENESS_MAP['__default__'];
    }

    /**
     * Estimate dispatch count based on category volume assumptions and metrics.
     */
    private function estimateDispatchCount(string $eventName): int
    {
        $catalogEntry = EventCatalog::get($eventName);
        $category = $catalogEntry !== null ? ($catalogEntry['category'] ?? 'custom') : 'custom';

        // Use category weight to distribute estimated total volume
        $categoryWeight = self::CATEGORY_VOLUME_WEIGHTS[$category] ?? 0.01;

        // Get actual metrics if available
        $totalEvents = $this->metrics->totalEvents() ?? 10000;
        $categoryCount = $this->categoryEventCount($category);

        if ($categoryCount === 0) {
            return (int) round($totalEvents * $categoryWeight * 0.1);
        }

        // Distribute evenly within category (simplified — real data would use actual counts)
        return (int) max(1, round($totalEvents * $categoryWeight / $categoryCount));
    }

    /**
     * Estimate total dispatches across all events.
     */
    private function estimateTotalDispatches(): int
    {
        return max(1, $this->metrics->totalEvents() ?? 10000);
    }

    /**
     * Calculate dispatch share percentage.
     */
    private function dispatchShare(string $eventName, int $count, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return ($count / $total) * 100.0;
    }

    /**
     * Get cost per dispatch from config or default.
     */
    private function costPerDispatch(): float
    {
        $snrConfig = $this->config->get('zeroboiler.analytics.event_snr', []);

        return (float) ($snrConfig['cost_per_dispatch'] ?? 0.0001);
    }

    /**
     * Calculate cost efficiency score (0-100).
     *
     * Higher score = lower cost per unit of insight.
     */
    private function costEfficiencyScore(string $eventName, int $dispatchCount, float $costPerDispatch): float
    {
        $totalCost = (float) $dispatchCount * $costPerDispatch;

        // Events with very high volume and low actionability get penalized
        if ($totalCost <= 0.0) {
            return 100.0;
        }

        // Normalize: assume max reasonable monthly cost per event is $50
        $maxCostPerEvent = 50.0;
        $costRatio = min(1.0, $totalCost / $maxCostPerEvent);

        // Invert: low cost = high efficiency
        return (1.0 - $costRatio) * 100.0;
    }

    /**
     * Compute the composite SNR from dimension scores.
     */
    private function computeSNR(
        float $actionability,
        float $correlation,
        float $uniqueness,
        float $costEfficiency,
    ): float {
        $snr = (
            ($actionability * self::WEIGHT_ACTIONABILITY) +
            ($correlation * self::WEIGHT_CORRELATION) +
            ($uniqueness * self::WEIGHT_UNIQUENESS) +
            ($costEfficiency * self::WEIGHT_COST_EFFICIENCY)
        );

        return round(min(100.0, max(0.0, $snr)), 2);
    }

    /**
     * Convert SNR to letter grade.
     */
    private function gradeFromSNR(float $snr): string
    {
        return match (true) {
            $snr >= self::GRADE_A_PLUS => 'A+',
            $snr >= self::GRADE_A => 'A',
            $snr >= self::GRADE_B => 'B',
            $snr >= self::GRADE_C => 'C',
            $snr >= self::GRADE_D => 'D',
            default => 'F',
        };
    }

    /**
     * Classify event by SNR into a verdict.
     */
    private function verdictFromSNR(float $snr): string
    {
        return match (true) {
            $snr >= self::SIGNAL_THRESHOLD => 'signal',
            $snr >= self::MODERATE_THRESHOLD => 'moderate',
            $snr >= self::NOISE_CANDIDATE_THRESHOLD => 'noise_candidate',
            default => 'noise',
        };
    }

    /**
     * Calculate SNR for all catalog events.
     *
     * @return array<string, EventSNRResult>
     */
    private function calculateAllEvents(): array
    {
        $allEvents = EventCatalog::all();
        $results = [];

        foreach ($allEvents as $name => $entry) {
            $results[$name] = $this->calculate($name);
        }

        return $results;
    }

    /**
     * Build the full SNR report from individual results.
     *
     * @param  array<string, EventSNRResult>  $results
     * @return SNRReport
     */
    private function buildReport(array $results): array
    {
        $signalCount = 0;
        $moderateCount = 0;
        $noiseCandidateCount = 0;
        $noiseCount = 0;
        $snrValues = [];
        $totalCost = 0.0;
        $grades = ['A+' => 0, 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
        $categoryData = [];

        foreach ($results as $result) {
            // Verdict counts
            match ($result->verdict) {
                'signal' => $signalCount++,
                'moderate' => $moderateCount++,
                'noise_candidate' => $noiseCandidateCount++,
                'noise' => $noiseCount++,
                default => null,
            };

            $snrValues[] = $result->snr;
            $totalCost += $result->totalCost;
            $grades[$result->grade] = ($grades[$result->grade] ?? 0) + 1;

            // Category aggregation
            $cat = $result->category;
            if (! isset($categoryData[$cat])) {
                $categoryData[$cat] = [
                    'count' => 0,
                    'snr_sum' => 0.0,
                    'cost_sum' => 0.0,
                    'signal_count' => 0,
                    'noise_count' => 0,
                ];
            }
            $categoryData[$cat]['count']++;
            $categoryData[$cat]['snr_sum'] += $result->snr;
            $categoryData[$cat]['cost_sum'] += $result->totalCost;
            if ($result->verdict === 'signal') {
                $categoryData[$cat]['signal_count']++;
            }
            if ($result->verdict === 'noise' || $result->verdict === 'noise_candidate') {
                $categoryData[$cat]['noise_count']++;
            }
        }

        // Category summary
        $categorySummary = [];
        foreach ($categoryData as $cat => $data) {
            $categorySummary[$cat] = [
                'count' => $data['count'],
                'avg_snr' => $data['count'] > 0 ? round($data['snr_sum'] / $data['count'], 2) : 0.0,
                'total_cost' => round($data['cost_sum'], 4),
                'signal_count' => $data['signal_count'],
                'noise_count' => $data['noise_count'],
            ];
        }

        // Top signal and noise events
        $sorted = $results;
        usort($sorted, fn (EventSNRResult $a, EventSNRResult $b): int => $b->snr <=> $a->snr);

        $topSignal = array_slice(
            array_map(fn (EventSNRResult $r): string => $r->eventName, $sorted),
            0,
            10,
        );

        $topNoise = array_slice(
            array_map(fn (EventSNRResult $r): string => $r->eventName, array_reverse($sorted)),
            0,
            10,
        );

        // Average and median SNR
        sort($snrValues);
        $count = count($snrValues);
        $averageSNR = $count > 0 ? round(array_sum($snrValues) / $count, 2) : 0.0;
        $medianSNR = $count > 0 ? round($snrValues[(int) floor($count / 2)], 2) : 0.0;

        // Weighted SNR (weighted by dispatch volume)
        $weightedSum = 0.0;
        $totalDispatch = 0;
        foreach ($results as $result) {
            $weightedSum += $result->snr * $result->dispatchCount;
            $totalDispatch += $result->dispatchCount;
        }
        $weightedSNR = $totalDispatch > 0 ? round($weightedSum / $totalDispatch, 2) : 0.0;

        return [
            'total_events' => $count,
            'signal_count' => $signalCount,
            'moderate_count' => $moderateCount,
            'noise_candidate_count' => $noiseCandidateCount,
            'noise_count' => $noiseCount,
            'average_snr' => $averageSNR,
            'median_snr' => $medianSNR,
            'weighted_snr' => $weightedSNR,
            'total_monthly_cost' => round($totalCost, 4),
            'top_signal_events' => $topSignal,
            'top_noise_events' => $topNoise,
            'events' => $results,
            'grades' => $grades,
            'category_summary' => $categorySummary,
            'computed_at' => date('c'),
        ];
    }

    /**
     * Get the number of events in a category.
     */
    private function categoryEventCount(string $category): int
    {
        return match ($category) {
            'ecommerce' => EcommerceEvents::count(),
            'saas' => SaaSEvents::count(),
            'engagement' => EngagementEvents::count(),
            'security' => SecurityEvents::count(),
            'uptime' => UptimeEvents::count(),
            'infrastructure' => InfrastructureEvents::count(),
            'marketing' => MarketingEvents::count(),
            'webhook' => WebhookEvents::count(),
            default => 0,
        };
    }

    /**
     * Get cache TTL from config.
     */
    private function getCacheTtl(): int
    {
        $snrConfig = $this->config->get('zeroboiler.analytics.event_snr', []);

        return (int) ($snrConfig['cache_ttl'] ?? 3600);
    }
}
