<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

/**
 * SaaS analytics instrumentation completeness validator.
 *
 * Evaluates an application's analytics instrumentation coverage against
 * industry-standard SaaS event taxonomies. Provides actionable recommendations
 * for instrumentation gaps.
 *
 * Scoring tiers:
 * - **Starter** (0-40): Basic page views and auth events
 * - **Growth** (41-70): Core SaaS lifecycle events covered
 * - **Advanced** (71-90): Full conversion funnel + engagement events
 * - **Enterprise** (91-100): Comprehensive coverage with revenue attribution
 *
 * @since 88.0.0
 */
final class SaaSStarterValidationService
{
    /** Scoring tiers */
    public const TIER_STARTER = 'starter';
    public const TIER_GROWTH = 'growth';
    public const TIER_ADVANCED = 'advanced';
    public const TIER_ENTERPRISE = 'enterprise';

    /** Event categories required for each tier */
    private const STARTER_EVENTS = [
        'page_view', 'sign_up', 'login', 'purchase',
    ];

    private const GROWTH_EVENTS = [
        'start_trial', 'subscription', 'plan_upgrade', 'cancellation',
        'add_to_cart', 'view_item', 'search', 'form_submit',
    ];

    private const ADVANCED_EVENTS = [
        'scroll_depth', 'share', 'error', 'trial_end',
        'refund', 'plan_downgrade', 'team_created',
        'consent_granted', 'consent_withdrawn',
    ];

    private const ENTERPRISE_EVENTS = [
        'feature_used', 'invite_sent', 'integration_connected',
        'billing_retry', 'subscription_renewal',
        'checkout_step', 'view_cart', 'remove_from_cart',
    ];

    /** @var array<string, list<string>> All required events by tier */
    private const ALL_REQUIRED = [
        self::TIER_STARTER => self::STARTER_EVENTS,
        self::TIER_GROWTH => self::GROWTH_EVENTS,
        self::TIER_ADVANCED => self::ADVANCED_EVENTS,
        self::TIER_ENTERPRISE => self::ENTERPRISE_EVENTS,
    ];

    /** Provider coverage requirements per tier */
    private const PROVIDER_REQUIREMENTS = [
        self::TIER_STARTER => 1,
        self::TIER_GROWTH => 2,
        self::TIER_ADVANCED => 3,
        self::TIER_ENTERPRISE => 4,
    ];

    /** @var AnalyticsManager */
    private AnalyticsManager $manager;

    /** @var array<string, bool> Event implementation tracking */
    private array $implementedEvents = [];

    /**
     * @param  AnalyticsManager  $manager
     */
    public function __construct(AnalyticsManager $manager){
        $this->manager = $manager;

        // Build implementation map from all catalogs
        $this->buildImplementationMap();
    }

    /**
     * Build a map of which events are implemented across all catalogs.
     */
    private function buildImplementationMap(): void
    {
        foreach (EcommerceEvents::names() as $name) {
            $this->implementedEvents[$name] = true;
        }
        foreach (SaaSEvents::names() as $name) {
            $this->implementedEvents[$name] = true;
        }
        foreach (EngagementEvents::names() as $name) {
            $this->implementedEvents[$name] = true;
        }

        // Also check the full EventCatalog for any additional events
        foreach (EventCatalog::names() as $name) {
            $this->implementedEvents[$name] = true;
        }
    }

    /**
     * Validate instrumentation completeness and return a detailed report.
     *
     * @param  string|null  $targetTier  Target tier to validate against (null = auto-detect)
     * @return array{score: float, tier: string, target_tier: string, gaps: list<string>, covered: list<string>, provider_count: int, recommendations: list<string>}
     */
    public function validate(?string $targetTier = null): array
    {
        $detectedTier = $this->detectTier();
        $target = $targetTier ?? $detectedTier;

        $allRequiredForTarget = $this->getRequiredEventsForTier($target);
        $gaps = [];
        $covered = [];

        foreach ($allRequiredForTarget as $eventName) {
            if (isset($this->implementedEvents[$eventName])) {
                $covered[] = $eventName;
            } else {
                $gaps[] = $eventName;
            }
        }

        $totalRequired = count($allRequiredForTarget);
        $score = $totalRequired > 0 ? round((count($covered) / $totalRequired) * 100, 1) : 0.0;

        return [
            'score' => $score,
            'tier' => $detectedTier,
            'target_tier' => $target,
            'gaps' => $gaps,
            'covered' => $covered,
            'provider_count' => $this->countEnabledProviders(),
            'provider_requirement' => self::PROVIDER_REQUIREMENTS[$target] ?? 1,
            'recommendations' => $this->generateRecommendations($gaps, $score),
        ];
    }

    /**
     * Auto-detect the current instrumentation tier.
     *
     * Returns the highest tier whose events are fully covered.
     */
    public function detectTier(): string
    {
        foreach ([self::TIER_ENTERPRISE, self::TIER_ADVANCED, self::TIER_GROWTH, self::TIER_STARTER] as $tier) {
            $required = $this->getRequiredEventsForTier($tier);
            $allCovered = count(array_filter($required, fn (string $e): bool => isset($this->implementedEvents[$e]))) === count($required);

            if ($allCovered) {
                return $tier;
            }
        }

        return self::TIER_STARTER;
    }

    /**
     * Get the quick-start checklist for reaching a target tier.
     *
     * Returns prioritized list of events to instrument next.
     *
     * @param  string|null  $targetTier
     * @return array{current_tier: string, target_tier: string, events_to_add: list<array{name: string, category: string, priority: string}>, estimated_effort: string}
     */
    public function quickStartChecklist(?string $targetTier = null): array
    {
        $validation = $this->validate($targetTier);
        $current = $validation['tier'];
        $target = $validation['target_tier'];

        // Determine which tiers' events need to be added
        $tiers = [self::TIER_STARTER, self::TIER_GROWTH, self::TIER_ADVANCED, self::TIER_ENTERPRISE];
        $currentIdx = array_search($current, $tiers, true);
        $targetIdx = array_search($target, $tiers, true);

        $eventsToAdd = [];
        $tierIdx = max($currentIdx, 0);

        while ($tierIdx <= $targetIdx) {
            $tier = $tiers[$tierIdx];
            foreach (self::ALL_REQUIRED[$tier] as $eventName) {
                if (! isset($this->implementedEvents[$eventName])) {
                    $eventsToAdd[] = [
                        'name' => $eventName,
                        'category' => $this->categorizeEvent($eventName),
                        'priority' => $tier === self::TIER_STARTER ? 'critical' : ($tier === self::TIER_GROWTH ? 'high' : ($tier === self::TIER_ADVANCED ? 'medium' : 'low')),
                    ];
                }
            }
            $tierIdx++;
        }

        $effort = count($eventsToAdd) <= 4 ? 'minimal' : (count($eventsToAdd) <= 12 ? 'moderate' : 'significant');

        return [
            'current_tier' => $current,
            'target_tier' => $target,
            'events_to_add' => $eventsToAdd,
            'estimated_effort' => $effort,
        ];
    }

    /**
     * Get required events for a tier (cumulative: includes all lower tiers).
     *
     * @param  string  $tier
     * @return list<string>
     */
    private function getRequiredEventsForTier(string $tier): array
    {
        $tiers = [self::TIER_STARTER, self::TIER_GROWTH, self::TIER_ADVANCED, self::TIER_ENTERPRISE];
        $targetIdx = array_search($tier, $tiers, true);

        if ($targetIdx === false) {
            $targetIdx = 0;
        }

        $events = [];
        for ($i = 0; $i <= $targetIdx; $i++) {
            foreach (self::ALL_REQUIRED[$tiers[$i]] as $event) {
                $events[] = $event;
            }
        }

        return array_values(array_unique($events));
    }

    /**
     * Count the number of enabled analytics providers.
     */
    private function countEnabledProviders(): int
    {
        $count = 0;

        if ($this->manager->ga4()->isEnabled()) {
            $count++;
        }
        if ($this->manager->gtm()->isEnabled()) {
            $count++;
        }
        if ($this->manager->meta()->isEnabled()) {
            $count++;
        }
        if ($this->manager->plausible()->isEnabled()) {
            $count++;
        }
        if ($this->manager->posthog()->isEnabled()) {
            $count++;
        }
        if ($this->manager->mixpanel()->isEnabled()) {
            $count++;
        }
        if ($this->manager->amplitude()->isEnabled()) {
            $count++;
        }

        return $count;
    }

    /**
     * Categorize an event by its catalog origin.
     *
     * @param  string  $eventName
     * @return string
     */
    private function categorizeEvent(string $eventName): string
    {
        if (EcommerceEvents::has($eventName)) {
            return 'ecommerce';
        }
        if (SaaSEvents::has($eventName)) {
            return 'saas';
        }
        if (EngagementEvents::has($eventName)) {
            return 'engagement';
        }

        return 'custom';
    }

    /**
     * Generate actionable recommendations based on gaps and score.
     *
     * @param  list<string>  $gaps  Missing event names
     * @param  float  $score  Current score
     * @return list<string>
     */
    private function generateRecommendations(array $gaps, float $score): array
    {
        $recommendations = [];

        if ($score >= 90) {
            $recommendations[] = 'Excellent instrumentation coverage. Consider adding custom events for product-specific metrics.';
        } elseif ($score >= 70) {
            $recommendations[] = 'Good coverage. Focus on filling remaining gaps for advanced funnel analysis.';
        } elseif ($score >= 40) {
            $recommendations[] = 'Core events are instrumented. Add SaaS lifecycle events for better conversion insights.';
        } else {
            $recommendations[] = 'Basic instrumentation detected. Prioritize sign_up, login, and purchase events.';
        }

        if (count($gaps) > 0) {
            $topGaps = array_slice($gaps, 0, 5);
            $recommendations[] = 'Top missing events: ' . implode(', ', $topGaps);
        }

        if ($score < 100) {
            $recommendations[] = 'Use Analytics::track() for server-side tracking or the JS client for client-side events.';
        }

        return $recommendations;
    }

    /**
     * Get tier-specific score thresholds.
     *
     * @return array{starter: array{min: int, max: int}, growth: array{min: int, max: int}, advanced: array{min: int, max: int}, enterprise: array{min: int, max: int}}
     */
    public static function tierThresholds(): array
    {
        return [
            self::TIER_STARTER => ['min' => 0, 'max' => 40],
            self::TIER_GROWTH => ['min' => 41, 'max' => 70],
            self::TIER_ADVANCED => ['min' => 71, 'max' => 90],
            self::TIER_ENTERPRISE => ['min' => 91, 'max' => 100],
        ];
    }
}
