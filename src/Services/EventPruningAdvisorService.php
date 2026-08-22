<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\EventPruningRecommendation;
use ZeroBoiler\Analytics\DTO\EventSNRResult;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

/**
 * Event Pruning Advisor — recommends event removal, reduction, or consolidation.
 *
 * Analyzes SNR results from EventSNRCalculatorService and generates actionable
 * pruning recommendations for events that are consuming resources without
 * providing proportional insight value. Each recommendation includes:
 *
 * - **Action type**: remove, reduce_frequency, merge_with, or sample_only
 * - **Rationale**: Why this event should be pruned
 * - **Estimated savings**: Monthly cost reduction from the action
 * - **Alternatives**: Events that provide similar or better signal
 * - **Priority**: high (immediate savings), medium, or low (marginal savings)
 *
 * The advisor also identifies consolidation opportunities where multiple
 * low-SNR events can be merged into a single higher-value event.
 *
 * Inspired by Segment's Event Cleanup Guide, Amplitude's Event Governance,
 * and Mixpanel's Project Health recommendations.
 *
 * Configuration: `zeroboiler.analytics.event_snr`
 *
 * @phpstan-type PruningReport array{total_recommendations: int, high_priority: int, medium_priority: int, low_priority: int, estimated_monthly_savings: float, action_breakdown: array{remove: int, reduce_frequency: int, merge_with: int, sample_only: int}, recommendations: list<EventPruningRecommendation>, consolidation_opportunities: list<array{events: list<string>, suggested_name: string, combined_snr: float, estimated_savings: float}>, noise_ratio: float, computed_at: string}
 *
 * @since 220.0.0
 *
 * @see \ZeroBoiler\Analytics\DTO\EventPruningRecommendation
 * @see \ZeroBoiler\Analytics\Services\EventSNRCalculatorService
 */
final class EventPruningAdvisorService
{
    private const CACHE_PREFIX = 'zb_prune_advisor_';

    /**
     * Events that should NEVER be pruned (critical business events).
     *
     * @var list<string>
     */
    private const PROTECTED_EVENTS = [
        'purchase',
        'sign_up',
        'subscribe',
        'cancellation',
        'start_trial',
        'trial_converted',
        'plan_upgrade',
    ];

    /**
     * Suggested merge targets for low-SNR events.
     * Maps low-value events to higher-value events they could consolidate into.
     *
     * @var array<string, string>
     */
    private const MERGE_SUGGESTIONS = [
        'view_cart' => 'add_to_cart',
        'select_promotion' => 'view_promotion',
        'select_item' => 'view_item',
        'time_on_page' => 'page_view',
        'logout' => 'login',
        'session_end' => 'session_start',
        'copy_text' => 'click',
        'hover' => 'click',
        'element_visibility' => 'scroll_depth',
    ];

    /**
     * @param  EventSNRCalculatorService  $snrCalculator
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly EventSNRCalculatorService $snrCalculator,
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ){}

    /**
     * Generate a full pruning report.
     *
     * @param  bool  $fresh  Force recalculation (bypass cache)
     * @return PruningReport
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

        $snrReport = $this->snrCalculator->report($fresh);
        $recommendations = $this->generateRecommendations($snrReport['events']);
        $consolidation = $this->findConsolidationOpportunities($snrReport['events']);

        $highCount = 0;
        $mediumCount = 0;
        $lowCount = 0;
        $totalSavings = 0.0;
        $actionBreakdown = ['remove' => 0, 'reduce_frequency' => 0, 'merge_with' => 0, 'sample_only' => 0];

        foreach ($recommendations as $rec) {
            $totalSavings += $rec->estimatedSavings;
            match ($rec->priority) {
                'high' => $highCount++,
                'medium' => $mediumCount++,
                'low' => $lowCount++,
                default => null,
            };
            $actionBreakdown[$rec->action] = ($actionBreakdown[$rec->action] ?? 0) + 1;
        }

        $noiseCount = $snrReport['noise_count'] + $snrReport['noise_candidate_count'];
        $totalEvents = $snrReport['total_events'];
        $noiseRatio = $totalEvents > 0 ? round(($noiseCount / $totalEvents) * 100.0, 1) : 0.0;

        $result = [
            'total_recommendations' => count($recommendations),
            'high_priority' => $highCount,
            'medium_priority' => $mediumCount,
            'low_priority' => $lowCount,
            'estimated_monthly_savings' => round($totalSavings, 4),
            'action_breakdown' => $actionBreakdown,
            'recommendations' => $recommendations,
            'consolidation_opportunities' => $consolidation,
            'noise_ratio' => $noiseRatio,
            'computed_at' => date('c'),
        ];

        $cacheTtl = $this->getCacheTtl();
        $this->cache->put($cacheKey, $result, $cacheTtl);

        return $result;
    }

    /**
     * Get high-priority pruning recommendations only.
     *
     * @return list<EventPruningRecommendation>
     */
    public function highPriorityRecommendations(): array
    {
        $report = $this->report();

        return array_filter(
            $report['recommendations'],
            fn (EventPruningRecommendation $r): bool => $r->isHighPriority(),
        );
    }

    /**
     * Get recommendations grouped by action type.
     *
     * @return array{remove: list<EventPruningRecommendation>, reduce_frequency: list<EventPruningRecommendation>, merge_with: list<EventPruningRecommendation>, sample_only: list<EventPruningRecommendation>}
     */
    public function groupedByAction(): array
    {
        $report = $this->report();
        $groups = [
            'remove' => [],
            'reduce_frequency' => [],
            'merge_with' => [],
            'sample_only' => [],
        ];

        foreach ($report['recommendations'] as $rec) {
            $groups[$rec->action][] = $rec;
        }

        return $groups;
    }

    /**
     * Get consolidation opportunities.
     *
     * @return list<array{events: list<string>, suggested_name: string, combined_snr: float, estimated_savings: float}>
     */
    public function consolidationOpportunities(): array
    {
        return $this->report()['consolidation_opportunities'];
    }

    /**
     * Get the noise ratio (percentage of events classified as noise/noise_candidate).
     */
    public function noiseRatio(): float
    {
        return $this->report()['noise_ratio'];
    }

    /**
     * Get estimated monthly savings from all recommendations.
     */
    public function estimatedSavings(): float
    {
        return $this->report()['estimated_monthly_savings'];
    }

    /**
     * Generate recommendations for a specific event.
     *
     * @param  string  $eventName
     * @return EventPruningRecommendation|null
     */
    public function recommendationFor(string $eventName): ?EventPruningRecommendation
    {
        $report = $this->report();

        foreach ($report['recommendations'] as $rec) {
            if ($rec->eventName === $eventName) {
                return $rec;
            }
        }

        return null;
    }

    /**
     * Check if an event is protected from pruning.
     */
    public function isProtected(string $eventName): bool
    {
        return in_array($eventName, self::PROTECTED_EVENTS, true);
    }

    /**
     * Get the list of protected event names.
     *
     * @return list<string>
     */
    public function protectedEvents(): array
    {
        return self::PROTECTED_EVENTS;
    }

    /**
     * Invalidate the cached pruning report.
     */
    public function invalidateCache(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'report');
        $this->snrCalculator->invalidateCache();
    }

    /**
     * Generate pruning recommendations from SNR results.
     *
     * @param  array<string, EventSNRResult>  $snrResults
     * @return list<EventPruningRecommendation>
     */
    private function generateRecommendations(array $snrResults): array
    {
        $recommendations = [];

        // Sort by SNR ascending (worst first)
        $sorted = $snrResults;
        uasort($sorted, fn (EventSNRResult $a, EventSNRResult $b): int => $a->snr <=> $b->snr);

        foreach ($sorted as $result) {
            // Skip protected events
            if ($this->isProtected($result->eventName)) {
                continue;
            }

            // Only recommend pruning for noise and noise candidates
            if ($result->verdict === 'signal' || $result->verdict === 'moderate') {
                continue;
            }

            $recommendation = $this->buildRecommendation($result);
            if ($recommendation !== null) {
                $recommendations[] = $recommendation;
            }
        }

        // Sort recommendations by priority then estimated savings
        usort($recommendations, function (EventPruningRecommendation $a, EventPruningRecommendation $b): int {
            $priorityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
            $pa = $priorityOrder[$a->priority] ?? 1;
            $pb = $priorityOrder[$b->priority] ?? 1;

            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return $b->estimatedSavings <=> $a->estimatedSavings;
        });

        return $recommendations;
    }

    /**
     * Build a single pruning recommendation for an event.
     */
    private function buildRecommendation(EventSNRResult $result): ?EventPruningRecommendation
    {
        $eventName = $result->eventName;
        $currentCost = $result->totalCost;

        // Determine action and rationale
        if ($result->isNoise()) {
            // Pure noise — recommend removal
            return $this->createRemovalRecommendation($result);
        }

        if ($result->isNoiseCandidate()) {
            // Check for merge opportunity
            if (isset(self::MERGE_SUGGESTIONS[$eventName])) {
                return $this->createMergeRecommendation($result, self::MERGE_SUGGESTIONS[$eventName]);
            }

            // Check if high-volume event could benefit from sampling
            if ($result->dispatchCount > 1000) {
                return $this->createSamplingRecommendation($result);
            }

            // Default: reduce frequency
            return $this->createFrequencyReductionRecommendation($result);
        }

        return null;
    }

    /**
     * Create a removal recommendation.
     */
    private function createRemovalRecommendation(EventSNRResult $result): EventPruningRecommendation
    {
        $alternatives = $this->findAlternativeEvents($result->eventName, $result->category);

        $priority = $result->currentCost > 10.0 ? 'high' : ($result->currentCost > 1.0 ? 'medium' : 'low');

        return new EventPruningRecommendation(
            eventName: $result->eventName,
            category: $result->category,
            action: 'remove',
            rationale: sprintf(
                'Event "%s" has SNR of %.1f (grade %s) — classified as noise. It consumes $%.4f/month but provides minimal actionable insight. Consider removing it from all trackers.',
                $result->eventName,
                $result->snr,
                $result->grade,
                $result->totalCost,
            ),
            currentCost: $result->totalCost,
            estimatedSavings: $result->totalCost,
            snr: $result->snr,
            priority: $priority,
            alternatives: $alternatives,
        );
    }

    /**
     * Create a merge recommendation.
     */
    private function createMergeRecommendation(EventSNRResult $result, string $mergeTarget): EventPruningRecommendation
    {
        $alternatives = [$mergeTarget];
        $savings = $result->totalCost * 0.80; // Assume 80% savings from merge

        $priority = $result->currentCost > 5.0 ? 'high' : 'medium';

        return new EventPruningRecommendation(
            eventName: $result->eventName,
            category: $result->category,
            action: 'merge_with',
            rationale: sprintf(
                'Event "%s" (SNR %.1f) can be merged with "%s" which provides overlapping signal. This reduces event count while preserving the essential data point.',
                $result->eventName,
                $result->snr,
                $mergeTarget,
            ),
            currentCost: $result->totalCost,
            estimatedSavings: $savings,
            snr: $result->snr,
            mergeTarget: $mergeTarget,
            priority: $priority,
            alternatives: $alternatives,
        );
    }

    /**
     * Create a sampling recommendation.
     */
    private function createSamplingRecommendation(EventSNRResult $result): EventPruningRecommendation
    {
        $sampleRate = $result->snr < 25 ? 5 : 10;
        $savings = $result->totalCost * ((100 - $sampleRate) / 100);

        $priority = $savings > 5.0 ? 'high' : ($savings > 1.0 ? 'medium' : 'low');

        return new EventPruningRecommendation(
            eventName: $result->eventName,
            category: $result->category,
            action: 'sample_only',
            rationale: sprintf(
                'Event "%s" (SNR %.1f) has high volume (%d dispatches) but low signal value. Sampling at %d%% preserves trend data while reducing cost by ~%.0f%%.',
                $result->eventName,
                $result->snr,
                $result->dispatchCount,
                $sampleRate,
                (100 - $sampleRate),
            ),
            currentCost: $result->totalCost,
            estimatedSavings: $savings,
            snr: $result->snr,
            suggestedSampleRate: $sampleRate,
            priority: $priority,
            alternatives: [],
        );
    }

    /**
     * Create a frequency reduction recommendation.
     */
    private function createFrequencyReductionRecommendation(EventSNRResult $result): EventPruningRecommendation
    {
        $alternatives = $this->findAlternativeEvents($result->eventName, $result->category);
        $savings = $result->totalCost * 0.50;

        $priority = 'low';

        return new EventPruningRecommendation(
            eventName: $result->eventName,
            category: $result->category,
            action: 'reduce_frequency',
            rationale: sprintf(
                'Event "%s" (SNR %.1f) provides marginal value. Consider reducing dispatch frequency (e.g., throttle client-side tracking) to cut costs by ~50%% while retaining basic coverage.',
                $result->eventName,
                $result->snr,
            ),
            currentCost: $result->totalCost,
            estimatedSavings: $savings,
            snr: $result->snr,
            priority: $priority,
            alternatives: $alternatives,
        );
    }

    /**
     * Find alternative events in the same category that provide better signal.
     *
     * @return list<string>
     */
    private function findAlternativeEvents(string $eventName, string $category): array
    {
        $alternatives = [];

        // Get events from the same category
        $categoryEvents = match ($category) {
            'ecommerce' => EcommerceEvents::names(),
            'saas' => SaaSEvents::names(),
            'engagement' => EngagementEvents::names(),
            default => [],
        };

        foreach ($categoryEvents as $candidateName) {
            if ($candidateName === $eventName) {
                continue;
            }

            $candidate = $this->snrCalculator->calculate($candidateName);
            if ($candidate->snr > 50.0 && $candidate->verdict !== 'noise') {
                $alternatives[] = $candidateName;
            }

            // Limit to 3 alternatives
            if (count($alternatives) >= 3) {
                break;
            }
        }

        return $alternatives;
    }

    /**
     * Find consolidation opportunities — groups of low-SNR events that could merge.
     *
     * @param  array<string, EventSNRResult>  $snrResults
     * @return list<array{events: list<string>, suggested_name: string, combined_snr: float, estimated_savings: float}>
     */
    private function findConsolidationOpportunities(array $snrResults): array
    {
        $opportunities = [];
        $processed = [];

        foreach (self::MERGE_SUGGESTIONS as $sourceEvent => $targetEvent) {
            if (isset($processed[$sourceEvent])) {
                continue;
            }

            $sourceResult = $snrResults[$sourceEvent] ?? null;
            $targetResult = $snrResults[$targetEvent] ?? null;

            if ($sourceResult === null || $targetResult === null) {
                continue;
            }

            // Only suggest consolidation if source has low SNR
            if ($sourceResult->snr >= 40.0) {
                continue;
            }

            $combinedSNR = max($sourceResult->snr, $targetResult->snr) * 1.05; // Slight boost from consolidated data
            $savings = $sourceResult->totalCost * 0.80;

            $opportunities[] = [
                'events' => [$sourceEvent, $targetEvent],
                'suggested_name' => $targetEvent,
                'combined_snr' => round(min(100.0, $combinedSNR), 2),
                'estimated_savings' => round($savings, 4),
            ];

            $processed[$sourceEvent] = true;
            $processed[$targetEvent] = true;
        }

        return $opportunities;
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
