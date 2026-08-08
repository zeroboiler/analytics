<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * SaaS event priority and funnel classification calculator.
 *
 * Classifies events into the AARRR (Pirate Metrics) framework categories:
 * Acquisition, Activation, Retention, Revenue, Referral.
 *
 * Provides funnel conversion scoring, stage detection, and SaaS maturity
 * grading to help teams understand which events matter most and where
 * gaps exist in their analytics instrumentation.
 *
 * @see https://en.wikipedia.org/wiki/AARRR
 */
final class EventPriorityCalculator
{
    /**
     * AARRR framework category constants.
     */
    public const CATEGORY_ACQUISITION = 'acquisition';
    public const CATEGORY_ACTIVATION = 'activation';
    public const CATEGORY_RETENTION = 'retention';
    public const CATEGORY_REVENUE = 'revenue';
    public const CATEGORY_REFERRAL = 'referral';
    public const CATEGORY_OPERATIONAL = 'operational';

    /**
     * Event name → AARRR category classification.
     *
     * @var array<string, string>
     */
    private const CATEGORY_MAP = [
        // Acquisition — events that bring users to the product
        'sign_up' => self::CATEGORY_ACQUISITION,
        'email_verified' => self::CATEGORY_ACQUISITION,
        'campaign_attribution' => self::CATEGORY_ACQUISITION,
        'ad_click' => self::CATEGORY_ACQUISITION,
        'outbound_click' => self::CATEGORY_ACQUISITION,

        // Activation — events that show user reached first value
        'login' => self::CATEGORY_ACTIVATION,
        'onboarding_step' => self::CATEGORY_ACTIVATION,
        'feature_used' => self::CATEGORY_ACTIVATION,
        'feature_request' => self::CATEGORY_ACTIVATION,
        'start_trial' => self::CATEGORY_ACTIVATION,
        'trial_converted' => self::CATEGORY_ACTIVATION,
        'page_view' => self::CATEGORY_ACTIVATION,
        'search' => self::CATEGORY_ACTIVATION,
        'content_engagement' => self::CATEGORY_ACTIVATION,
        'file_download' => self::CATEGORY_ACTIVATION,

        // Retention — events that keep users coming back
        'session_start' => self::CATEGORY_RETENTION,
        'session_end' => self::CATEGORY_RETENTION,
        'scroll_depth' => self::CATEGORY_RETENTION,
        'time_on_page' => self::CATEGORY_RETENTION,
        'milestone_reached' => self::CATEGORY_RETENTION,
        'form_start' => self::CATEGORY_RETENTION,
        'form_submit' => self::CATEGORY_RETENTION,
        'notification' => self::CATEGORY_RETENTION,
        'screen_view' => self::CATEGORY_RETENTION,
        'profile_updated' => self::CATEGORY_RETENTION,
        'password_changed' => self::CATEGORY_RETENTION,
        'password_reset' => self::CATEGORY_RETENTION,
        'team_member_joined' => self::CATEGORY_RETENTION,
        'integration_connected' => self::CATEGORY_RETENTION,

        // Revenue — events that directly impact MRR/ARR
        'subscribe' => self::CATEGORY_REVENUE,
        'plan_upgrade' => self::CATEGORY_REVENUE,
        'plan_downgrade' => self::CATEGORY_REVENUE,
        'cancellation' => self::CATEGORY_REVENUE,
        'subscription_renewal' => self::CATEGORY_REVENUE,
        'subscription_resumed' => self::CATEGORY_REVENUE,
        'subscription_paused' => self::CATEGORY_REVENUE,
        'subscription_value_changed' => self::CATEGORY_REVENUE,
        'trial_end' => self::CATEGORY_REVENUE,
        'payment_succeeded' => self::CATEGORY_REVENUE,
        'payment_failed' => self::CATEGORY_REVENUE,
        'billing_retry' => self::CATEGORY_REVENUE,
        'payment_method_added' => self::CATEGORY_REVENUE,
        'invoice_generated' => self::CATEGORY_REVENUE,
        'credit_applied' => self::CATEGORY_REVENUE,
        'revenue_tracked' => self::CATEGORY_REVENUE,
        'purchase' => self::CATEGORY_REVENUE,
        'add_to_cart' => self::CATEGORY_REVENUE,
        'begin_checkout' => self::CATEGORY_REVENUE,
        'add_payment_info' => self::CATEGORY_REVENUE,
        'remove_from_cart' => self::CATEGORY_REVENUE,
        'view_cart' => self::CATEGORY_REVENUE,
        'add_to_wishlist' => self::CATEGORY_REVENUE,
        'view_item' => self::CATEGORY_REVENUE,
        'select_item' => self::CATEGORY_REVENUE,
        'select_promotion' => self::CATEGORY_REVENUE,
        'view_promotion' => self::CATEGORY_REVENUE,
        'refund' => self::CATEGORY_REVENUE,

        // Referral — events that drive viral/organic growth
        'share' => self::CATEGORY_REFERRAL,
        'team_created' => self::CATEGORY_REFERRAL,
        'workspace_created' => self::CATEGORY_REFERRAL,
        'invite_sent' => self::CATEGORY_REFERRAL,
        'feature_impression' => self::CATEGORY_REFERRAL,
        'feature_adopted' => self::CATEGORY_REFERRAL,
        'expansion_revenue' => self::CATEGORY_REFERRAL,

        // Operational — infrastructure, compliance, system health
        'account_activated' => self::CATEGORY_OPERATIONAL,
        'account_deactivated' => self::CATEGORY_OPERATIONAL,
        'error' => self::CATEGORY_OPERATIONAL,
        'js_error' => self::CATEGORY_OPERATIONAL,
        'web_vitals' => self::CATEGORY_OPERATIONAL,
        'timing' => self::CATEGORY_OPERATIONAL,
        'video_play' => self::CATEGORY_OPERATIONAL,
        'ab_test_exposure' => self::CATEGORY_OPERATIONAL,
        'click' => self::CATEGORY_OPERATIONAL,
        'feature_limit_reached' => self::CATEGORY_OPERATIONAL,
        'usage_quota_reached' => self::CATEGORY_OPERATIONAL,
        'role_changed' => self::CATEGORY_OPERATIONAL,
        'team_member_removed' => self::CATEGORY_OPERATIONAL,
        'logout' => self::CATEGORY_OPERATIONAL,
    ];

    /**
     * Minimum required events per AARRR category for "industry standard" compliance.
     *
     * A SaaS product is considered "industry standard" when it tracks at least
     * these many events in each category.
     *
     * @var array<string, int>
     */
    private const MINIMUM_EVENT_COUNTS = [
        self::CATEGORY_ACQUISITION => 3,
        self::CATEGORY_ACTIVATION => 5,
        self::CATEGORY_RETENTION => 4,
        self::CATEGORY_REVENUE => 8,
        self::CATEGORY_REFERRAL => 2,
    ];

    /**
     * Critical events that every SaaS product should track.
     *
     * These are the "non-negotiable" events for any analytics-instrumented
     * SaaS product. Missing any of these is a significant gap.
     *
     * @var list<string>
     */
    private const CRITICAL_SAAS_EVENTS = [
        'sign_up',
        'login',
        'start_trial',
        'subscribe',
        'plan_upgrade',
        'cancellation',
        'page_view',
        'purchase',
    ];

    /**
     * Get the AARRR category for a given event name.
     *
     * @return string One of: acquisition, activation, retention, revenue, referral, operational
     */
    public function classify(string $eventName): string
    {
        return self::CATEGORY_MAP[$eventName] ?? self::CATEGORY_OPERATIONAL;
    }

    /**
     * Classify all events in the catalog by AARRR category.
     *
     * @return array{acquisition: list<string>, activation: list<string>, retention: list<string>, revenue: list<string>, referral: list<string>, operational: list<string>}
     */
    public function classifyAll(): array
    {
        $classified = [
            self::CATEGORY_ACQUISITION => [],
            self::CATEGORY_ACTIVATION => [],
            self::CATEGORY_RETENTION => [],
            self::CATEGORY_REVENUE => [],
            self::CATEGORY_REFERRAL => [],
            self::CATEGORY_OPERATIONAL => [],
        ];

        foreach (EventCatalog::names() as $name) {
            $category = $this->classify($name);
            $classified[$category][] = $name;
        }

        return $classified;
    }

    /**
     * Get a breakdown of event counts per AARRR category.
     *
     * @return array{acquisition: int, activation: int, retention: int, revenue: int, referral: int, operational: int, total: int}
     */
    public function categoryCounts(): array
    {
        $classified = $this->classifyAll();
        $total = 0;

        $counts = [];
        foreach ($classified as $category => $events) {
            $counts[$category] = count($events);
            $total += count($events);
        }
        $counts['total'] = $total;

        return $counts;
    }

    /**
     * Calculate SaaS analytics maturity score (0-100).
     *
     * Scoring is based on:
     * - Critical events coverage (40 points)
     * - AARRR category minimums (30 points)
     * - Provider coverage breadth (20 points)
     * - Catalog size bonus (10 points)
     *
     * @return array{score: int, grade: string, details: array<string, mixed>}
     */
    public function maturityScore(): array
    {
        $score = 0;
        $details = [];

        // ── Critical events coverage (40 points) ──
        $criticalPresent = 0;
        $criticalMissing = [];
        foreach (self::CRITICAL_SAAS_EVENTS as $event) {
            if (EventCatalog::has($event)) {
                $criticalPresent++;
            } else {
                $criticalMissing[] = $event;
            }
        }
        $criticalTotal = count(self::CRITICAL_SAAS_EVENTS);
        $criticalScore = (int) round(($criticalPresent / $criticalTotal) * 40);
        $score += $criticalScore;
        $details['critical_events'] = [
            'present' => $criticalPresent,
            'total' => $criticalTotal,
            'score' => $criticalScore,
            'max_score' => 40,
            'missing' => $criticalMissing,
        ];

        // ── AARRR category minimums (30 points, 6 each) ──
        $categoryScores = [];
        $classified = $this->classifyAll();
        foreach (self::MINIMUM_EVENT_COUNTS as $category => $minimum) {
            $actual = count($classified[$category] ?? []);
            $met = $actual >= $minimum;
            $categoryScores[$category] = [
                'count' => $actual,
                'minimum' => $minimum,
                'met' => $met,
            ];
            if ($met) {
                $score += 6;
            }
        }
        $details['aarr_categories'] = $categoryScores;
        $details['aarr_score'] = $score;

        // ── Provider coverage breadth (20 points) ──
        $coverage = EventCatalog::providerCoverage();
        $providerScores = 0;
        $providerMax = 0;
        $providerDetails = [];

        foreach (['ga4', 'meta', 'posthog', 'plausible'] as $provider) {
            $count = $coverage['counts'][$provider] ?? 0;
            $ratio = min($count / max(EventCatalog::count(), 1), 1.0);
            $providerMax++;
            if ($ratio > 0.8) {
                $providerScores += 5;
                $providerDetails[$provider] = ['status' => 'excellent', 'count' => $count];
            } elseif ($ratio > 0.5) {
                $providerScores += 3;
                $providerDetails[$provider] = ['status' => 'good', 'count' => $count];
            } elseif ($ratio > 0) {
                $providerScores += 1;
                $providerDetails[$provider] = ['status' => 'partial', 'count' => $count];
            } else {
                $providerDetails[$provider] = ['status' => 'none', 'count' => 0];
            }
        }
        $score += $providerScores;
        $details['providers'] = $providerDetails;
        $details['provider_score'] = $providerScores;

        // ── Catalog size bonus (10 points) ──
        $totalEvents = EventCatalog::count();
        $sizeBonus = min((int) round($totalEvents / 10), 10);
        $score += $sizeBonus;
        $details['catalog_size'] = [
            'count' => $totalEvents,
            'bonus' => $sizeBonus,
        ];

        // ── Grade ──
        $grade = match (true) {
            $score >= 90 => 'A+ (Industry Leading)',
            $score >= 80 => 'A (Industry Standard)',
            $score >= 70 => 'B+ (Advanced)',
            $score >= 60 => 'B (Good)',
            $score >= 50 => 'C+ (Developing)',
            $score >= 40 => 'C (Basic)',
            $score >= 30 => 'D (Needs Improvement)',
            default => 'F (Critical Gaps)',
        };

        return [
            'score' => $score,
            'grade' => $grade,
            'details' => $details,
        ];
    }

    /**
     * Generate an onboarding checklist for SaaS analytics instrumentation.
     *
     * Returns a prioritized list of events that should be instrumented,
     * grouped by AARRR category with completion status.
     *
     * @return array{checklist: array<string, array<string, array{event: string, tracked: bool, priority: string}>>, summary: array{total: int, tracked: int, completion: float, gaps: list<string>}}
     */
    public function onboardingChecklist(): array
    {
        $classified = $this->classifyAll();
        $checklist = [];
        $total = 0;
        $tracked = 0;
        $gaps = [];

        foreach ($classified as $category => $events) {
            if ($category === self::CATEGORY_OPERATIONAL) {
                continue; // Skip operational events in onboarding checklist
            }

            $checklist[$category] = [];
            foreach ($events as $event) {
                $isTracked = EventCatalog::has($event);
                $priority = $this->getEventPriority($event);

                $checklist[$category][] = [
                    'event' => $event,
                    'tracked' => $isTracked,
                    'priority' => $priority,
                ];

                $total++;
                if ($isTracked) {
                    $tracked++;
                }
            }
        }

        // Identify critical gaps
        foreach (self::CRITICAL_SAAS_EVENTS as $event) {
            if (! EventCatalog::has($event)) {
                $gaps[] = $event;
            }
        }

        return [
            'checklist' => $checklist,
            'summary' => [
                'total' => $total,
                'tracked' => $tracked,
                'completion' => $total > 0 ? round(($tracked / $total) * 100, 1) : 0.0,
                'gaps' => $gaps,
            ],
        ];
    }

    /**
     * Calculate funnel conversion readiness score.
     *
     * Evaluates how well-instrumented the key conversion funnels are:
     * - Signup funnel: page_view → sign_up → email_verified → login → onboarding_step → feature_used
     * - Purchase funnel: view_item → add_to_cart → begin_checkout → add_payment_info → purchase
     * - Subscription funnel: start_trial → trial_converted → subscribe → plan_upgrade
     *
     * @return array{signup_funnel: array{steps: list<string>, present: list<string>, missing: list<string>, score: float}, purchase_funnel: array{steps: list<string>, present: list<string>, missing: list<string>, score: float}, subscription_funnel: array{steps: list<string>, present: list<string>, missing: list<string>, score: float}, overall: float}
     */
    public function funnelReadiness(): array
    {
        $funnels = [
            'signup_funnel' => [
                'page_view', 'sign_up', 'email_verified', 'login', 'onboarding_step', 'feature_used',
            ],
            'purchase_funnel' => [
                'view_item', 'add_to_cart', 'begin_checkout', 'add_payment_info', 'purchase',
            ],
            'subscription_funnel' => [
                'start_trial', 'trial_converted', 'subscribe', 'plan_upgrade',
            ],
        ];

        $results = [];
        $overallSum = 0.0;

        foreach ($funnels as $funnelName => $steps) {
            $present = [];
            $missing = [];

            foreach ($steps as $step) {
                if (EventCatalog::has($step)) {
                    $present[] = $step;
                } else {
                    $missing[] = $step;
                }
            }

            $score = count($steps) > 0
                ? round((count($present) / count($steps)) * 100, 1)
                : 0.0;

            $results[$funnelName] = [
                'steps' => $steps,
                'present' => $present,
                'missing' => $missing,
                'score' => $score,
            ];

            $overallSum += $score;
        }

        return [
            ...$results,
            'overall' => round($overallSum / count($funnels), 1),
        ];
    }

    /**
     * Get the priority level of a specific event.
     *
     * @return 'critical'|'high'|'medium'|'low'
     */
    public function getEventPriority(string $eventName): string
    {
        if (in_array($eventName, self::CRITICAL_SAAS_EVENTS, true)) {
            return 'critical';
        }

        return match ($this->classify($eventName)) {
            self::CATEGORY_REVENUE => 'high',
            self::CATEGORY_ACQUISITION, self::CATEGORY_ACTIVATION => 'high',
            self::CATEGORY_RETENTION, self::CATEGORY_REFERRAL => 'medium',
            default => 'low',
        };
    }

    /**
     * Get all events in a specific AARRR category.
     *
     * @param  string  $category  One of: acquisition, activation, retention, revenue, referral, operational
     * @return list<string>
     */
    public function eventsByCategory(string $category): array
    {
        $classified = $this->classifyAll();

        return $classified[$category] ?? [];
    }

    /**
     * Detect which AARRR categories are under-instrumented.
     *
     * Returns categories where the event count is below the minimum
     * threshold for industry standard compliance.
     *
     * @return array{category: string, count: int, minimum: int, deficit: int}[]
     */
    public function underInstrumentedCategories(): array
    {
        $classified = $this->classifyAll();
        $deficits = [];

        foreach (self::MINIMUM_EVENT_COUNTS as $category => $minimum) {
            $count = count($classified[$category] ?? []);

            if ($count < $minimum) {
                $deficits[] = [
                    'category' => $category,
                    'count' => $count,
                    'minimum' => $minimum,
                    'deficit' => $minimum - $count,
                ];
            }
        }

        return $deficits;
    }
}
