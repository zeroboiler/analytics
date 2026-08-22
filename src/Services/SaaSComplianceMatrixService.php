<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

/**
 * Industry-standard SaaS Analytics Compliance Matrix.
 *
 * Validates analytics instrumentation against the most widely-adopted
 * SaaS analytics frameworks and metric taxonomies:
 *
 *   1. **AARRR Pirate Metrics** (Acquisition, Activation, Retention, Referral, Revenue)
 *   2. **North Star Metric Framework** — Single key metric alignment
 *   3. **CAC / LTV Tracking** — Unit economics event coverage
 *   4. **Activation Funnel** — Signup → First Value → Aha Moment
 *   5. **Retention Cohort** — Day 1/7/30 retention event signals
 *   6. **Revenue Attribution** — Purchase, subscription, expansion, contraction
 *   7. **Product-Led Growth (PLG)** — Self-serve activation signals
 *   8. **GTM Alignment** — Marketing → Sales → Customer Success handoffs
 *
 * Each framework defines required events and optional enrichment events.
 * The service returns a compliance score per framework (0-100) plus
 * an overall SaaS analytics maturity grade.
 *
 * @since 181.0.0
 */
final class SaaSComplianceMatrixService
{
    /** @var AnalyticsManager */
    private AnalyticsManager $manager;

    /** @var array<string, bool> Flat map of all implemented events */
    private array $implementedEvents = [];

    /**
     * Framework definitions with required and optional events.
     *
     * @var array<string, array{label: string, required: list<string>, optional: list<string>, description: string}>
     */
    private const FRAMEWORKS = [
        'aarrr_acquisition' => [
            'label' => 'AARRR — Acquisition',
            'description' => 'Tracks how users discover and arrive at the product',
            'required' => ['page_view', 'sign_up'],
            'optional' => ['click', 'search', 'share', 'campaign_attribution'],
        ],
        'aarrr_activation' => [
            'label' => 'AARRR — Activation',
            'description' => 'Measures the moment a user experiences the product\'s core value',
            'required' => ['sign_up', 'login', 'feature_used'],
            'optional' => ['first_value', 'onboarding_started', 'onboarding_completed'],
        ],
        'aarrr_retention' => [
            'label' => 'AARRR — Retention',
            'description' => 'Tracks ongoing engagement and return usage patterns',
            'required' => ['login', 'page_view'],
            'optional' => ['session_start', 'session_end', 'feature_used', 'scroll_depth', 'search'],
        ],
        'aarrr_referral' => [
            'label' => 'AARRR — Referral',
            'description' => 'Measures organic growth through user-driven sharing and invitations',
            'required' => ['share'],
            'optional' => ['invite_sent', 'invite_accepted', 'referral_conversion', 'social_share'],
        ],
        'aarrr_revenue' => [
            'label' => 'AARRR — Revenue',
            'description' => 'Tracks monetization events across the customer lifecycle',
            'required' => ['purchase', 'subscription'],
            'optional' => ['plan_upgrade', 'plan_downgrade', 'cancellation', 'refund', 'expansion_revenue', 'contraction_revenue', 'billing_retry'],
        ],
        'north_star' => [
            'label' => 'North Star Metric',
            'description' => 'Core product value metric tracking alignment',
            'required' => ['page_view', 'login', 'feature_used'],
            'optional' => ['first_value', 'search', 'form_submit', 'share'],
        ],
        'cac_ltv' => [
            'label' => 'CAC / LTV Tracking',
            'description' => 'Unit economics: customer acquisition cost and lifetime value events',
            'required' => ['sign_up', 'purchase'],
            'optional' => ['start_trial', 'trial_converted', 'subscription', 'cancellation', 'mrr_movement', 'payback_period'],
        ],
        'activation_funnel' => [
            'label' => 'Activation Funnel',
            'description' => 'Signup → First Value → Aha Moment conversion tracking',
            'required' => ['sign_up', 'login'],
            'optional' => ['first_value', 'onboarding_started', 'onboarding_completed', 'feature_used', 'activation'],
        ],
        'retention_cohort' => [
            'label' => 'Retention Cohort',
            'description' => 'Day 1/7/30 retention signal events',
            'required' => ['login', 'page_view'],
            'optional' => ['session_start', 'feature_used', 'session_end', 'time_on_page'],
        ],
        'revenue_attribution' => [
            'label' => 'Revenue Attribution',
            'description' => 'Full-funnel revenue tracking from acquisition to renewal',
            'required' => ['purchase', 'view_item'],
            'optional' => ['add_to_cart', 'begin_checkout', 'refund', 'subscription', 'plan_upgrade', 'renewal_reminder'],
        ],
        'plg_signals' => [
            'label' => 'Product-Led Growth',
            'description' => 'Self-serve activation and organic expansion signals',
            'required' => ['sign_up', 'login'],
            'optional' => ['feature_used', 'invite_sent', 'share', 'first_value', 'workspace_created', 'team_created'],
        ],
        'gtm_alignment' => [
            'label' => 'GTM Alignment',
            'description' => 'Marketing → Sales → Customer Success handoff tracking',
            'required' => ['sign_up', 'login'],
            'optional' => ['lead_captured', 'lead_qualified', 'campaign_response', 'email_opened', 'email_clicked', 'webinar_registered', 'webinar_attended'],
        ],
    ];

    /** Provider names for cross-reference checks */
    private const PROVIDERS = [
        'ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog',
        'mixpanel', 'amplitude', 'tiktok', 'linkedin', 'webhook',
    ];

    /**
     * @param  AnalyticsManager  $manager
     */
    public function __construct(AnalyticsManager $manager){
        $this->manager = $manager;
        $this->buildImplementationMap();
    }

    /**
     * Build flat map of all implemented events across all catalogs.
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
        foreach (EventCatalog::names() as $name) {
            $this->implementedEvents[$name] = true;
        }
    }

    /**
     * Run the full compliance matrix audit.
     *
     * @return array{overall_score: float, grade: string, frameworks: array<string, array{label: string, description: string, score: float, required_covered: int, required_total: int, optional_covered: int, optional_total: int, gaps: list<string>, warnings: list<string>}>, provider_count: int, event_count: int, version: string, timestamp: string}
     */
    public function audit(): array
    {
        $frameworkResults = [];
        $totalScore = 0.0;
        $frameworkCount = 0;

        foreach (self::FRAMEWORKS as $key => $framework) {
            $result = $this->auditFramework($key, $framework);
            $frameworkResults[$key] = $result;
            $totalScore += $result['score'];
            $frameworkCount++;
        }

        $overallScore = $frameworkCount > 0 ? $totalScore / $frameworkCount : 0.0;

        return [
            'overall_score' => round($overallScore, 1),
            'grade' => $this->calculateGrade($overallScore),
            'frameworks' => $frameworkResults,
            'provider_count' => $this->countEnabledProviders(),
            'event_count' => count($this->implementedEvents),
            'version' => AnalyticsEvent::VERSION,
            'timestamp' => (new \DateTimeImmutable())->format('c'),
        ];
    }

    /**
     * Audit a single framework.
     *
     * @param  string  $key
     * @param  array{label: string, required: list<string>, optional: list<string>, description: string}  $framework
     * @return array{label: string, description: string, score: float, required_covered: int, required_total: int, optional_covered: int, optional_total: int, gaps: list<string>, warnings: list<string>}
     */
    private function auditFramework(string $key, array $framework): array
    {
        $requiredGaps = [];
        $optionalGaps = [];

        foreach ($framework['required'] as $event) {
            if (! isset($this->implementedEvents[$event])) {
                $requiredGaps[] = $event;
            }
        }

        foreach ($framework['optional'] as $event) {
            if (! isset($this->implementedEvents[$event])) {
                $optionalGaps[] = $event;
            }
        }

        $requiredTotal = count($framework['required']);
        $requiredCovered = $requiredTotal - count($requiredGaps);
        $optionalTotal = count($framework['optional']);
        $optionalCovered = $optionalTotal - count($optionalGaps);

        // Weighted score: required events = 70%, optional = 30%
        $requiredScore = $requiredTotal > 0 ? ($requiredCovered / $requiredTotal) * 70 : 0.0;
        $optionalScore = $optionalTotal > 0 ? ($optionalCovered / $optionalTotal) * 30 : 15.0; // If no optional, give partial credit

        $score = $requiredScore + $optionalScore;

        return [
            'label' => $framework['label'],
            'description' => $framework['description'],
            'score' => round($score, 1),
            'required_covered' => $requiredCovered,
            'required_total' => $requiredTotal,
            'optional_covered' => $optionalCovered,
            'optional_total' => $optionalTotal,
            'gaps' => $requiredGaps,
            'warnings' => $optionalGaps,
        ];
    }

    /**
     * Get a quick summary of compliance status per framework.
     *
     * @return array{compliant: list<string>, partial: list<string>, non_compliant: list<string>, overall: float, grade: string}
     */
    public function quickSummary(): array
    {
        $audit = $this->audit();
        $compliant = [];
        $partial = [];
        $nonCompliant = [];

        foreach ($audit['frameworks'] as $key => $framework) {
            if ($framework['score'] >= 90) {
                $compliant[] = $framework['label'];
            } elseif ($framework['score'] >= 50) {
                $partial[] = $framework['label'];
            } else {
                $nonCompliant[] = $framework['label'];
            }
        }

        return [
            'compliant' => $compliant,
            'partial' => $partial,
            'non_compliant' => $nonCompliant,
            'overall' => $audit['overall_score'],
            'grade' => $audit['grade'],
        ];
    }

    /**
     * Get actionable recommendations for reaching full compliance.
     *
     * @return list<array{framework: string, priority: string, event: string, type: string}>
     */
    public function recommendations(): array
    {
        $audit = $this->audit();
        $recommendations = [];

        foreach ($audit['frameworks'] as $key => $framework) {
            foreach ($framework['gaps'] as $event) {
                $recommendations[] = [
                    'framework' => $framework['label'],
                    'priority' => 'critical',
                    'event' => $event,
                    'type' => 'required',
                ];
            }
            foreach ($framework['warnings'] as $event) {
                $recommendations[] = [
                    'framework' => $framework['label'],
                    'priority' => 'recommended',
                    'event' => $event,
                    'type' => 'optional',
                ];
            }
        }

        usort($recommendations, function (array $a, array $b): int {
            $priorityOrder = ['critical' => 0, 'recommended' => 1];
            $aOrder = $priorityOrder[$a['priority']] ?? 99;
            $bOrder = $priorityOrder[$b['priority']] ?? 99;

            return $aOrder <=> $bOrder;
        });

        return $recommendations;
    }

    /**
     * Count enabled analytics providers.
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
     * Calculate a maturity grade from score.
     *
     * @param  float  $score
     * @return string
     */
    private function calculateGrade(float $score): string
    {
        return match (true) {
            $score >= 95 => 'A+',
            $score >= 90 => 'A',
            $score >= 85 => 'B+',
            $score >= 80 => 'B',
            $score >= 70 => 'C+',
            $score >= 60 => 'C',
            $score >= 50 => 'D+',
            default => 'D',
        };
    }

    /**
     * Get the list of supported framework keys.
     *
     * @return list<string>
     */
    public static function frameworkKeys(): array
    {
        return array_keys(self::FRAMEWORKS);
    }

    /**
     * Get the list of supported provider names.
     *
     * @return list<string>
     */
    public static function providerNames(): array
    {
        return self::PROVIDERS;
    }

    /**
     * Check if a specific event exists in the implementation map.
     */
    public function hasEvent(string $eventName): bool
    {
        return isset($this->implementedEvents[$eventName]);
    }

    /**
     * Get the total number of implemented events.
     */
    public function eventCount(): int
    {
        return count($this->implementedEvents);
    }
}
