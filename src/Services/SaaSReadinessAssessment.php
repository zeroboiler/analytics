<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
/**
 * SaaS Analytics Readiness Assessment service.
 *
 * Provides a comprehensive evaluation of how well a SaaS application's
 * analytics instrumentation covers industry-standard requirements.
 * Generates actionable recommendations and scores across multiple
 * dimensions.
 *
 * Assessment dimensions:
 * - **Event Coverage**: Are the right events being tracked?
 * - **Provider Coverage**: Are events mapped to all enabled providers?
 * - **Funnel Readiness**: Are critical funnels fully instrumented?
 * - **AARRR Coverage**: Are all five AARRR pillars represented?
 * - **Identity Tracking**: Is user identity properly linked?
 * - **E-commerce Readiness**: Are purchase/refund flows tracked?
 * - **Configuration Quality**: Is the config properly set up?
 *
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 * @see \ZeroBoiler\Analytics\Services\SaaSFunnelDefinitions
 *
 * @since 101.0.0
 */
final class SaaSReadinessAssessment
{
    /**
     * @phpstan-type DimensionScore array{name: string, score: float, max: float, percent: float, status: 'excellent'|'good'|'fair'|'poor'|'missing', findings: list<string>, recommendations: list<string>}
     * @phpstan-type AssessmentReport array{overall_score: float, overall_grade: string, dimensions: array<string, DimensionScore>, tracked_events: list<string>, total_catalog_events: int, tracked_count: int, coverage_percent: float, generated_at: string, version: string}
     */

    /** @var list<string> Events that are considered tracked (from app instrumentation) */
    private array $trackedEvents;

    /** @var array<string, bool> Enabled providers */
    private array $enabledProviders;

    /** @var array{identity?: bool, queue?: bool, auto_track?: bool, ecommerce?: bool, api?: bool, lifecycle?: bool, consent?: bool} Configuration flags */
    private array $configFlags;

    /**
     * @param  list<string>  $trackedEvents  Event names currently tracked by the application
     * @param  array<string, bool>  $enabledProviders  Provider names enabled in config
     * @param  array<string, bool>  $configFlags  Configuration feature flags
     */
    public function __construct(
        array $trackedEvents = [],
        array $enabledProviders = [],
        array $configFlags = [],
    ){
        $this->trackedEvents = $trackedEvents;
        $this->enabledProviders = $enabledProviders;
        $this->configFlags = $configFlags;
    }

    /**
     * Run the full readiness assessment.
     *
     * Evaluates all dimensions and returns a comprehensive report
     * with scores, findings, and actionable recommendations.
     *
     * @return AssessmentReport
     */
    public function assess(): array
    {
        $trackedSet = array_flip($this->trackedEvents);

        $dimensions = [
            'event_coverage' => $this->assessEventCoverage($trackedSet),
            'provider_coverage' => $this->assessProviderCoverage($trackedSet),
            'funnel_readiness' => $this->assessFunnelReadiness($trackedSet),
            'aarrr_coverage' => $this->assessAarrrCoverage($trackedSet),
            'identity_tracking' => $this->assessIdentityTracking(),
            'ecommerce_readiness' => $this->assessEcommerceReadiness($trackedSet),
            'configuration_quality' => $this->assessConfigurationQuality(),
        ];

        $weights = [
            'event_coverage' => 0.25,
            'provider_coverage' => 0.15,
            'funnel_readiness' => 0.20,
            'aarrr_coverage' => 0.15,
            'identity_tracking' => 0.10,
            'ecommerce_readiness' => 0.05,
            'configuration_quality' => 0.10,
        ];

        $overallScore = 0.0;
        foreach ($dimensions as $key => $dimension) {
            $overallScore += $dimension['percent'] * ($weights[$key] ?? 0.1);
        }
        $overallScore = min(100.0, round($overallScore, 1));

        $totalCatalog = EventCatalog::count();
        $trackedCount = count($this->trackedEvents);
        $coveragePercent = $totalCatalog > 0 ? round(($trackedCount / $totalCatalog) * 100, 1) : 0.0;

        return [
            'overall_score' => $overallScore,
            'overall_grade' => $this->calculateGrade($overallScore),
            'dimensions' => $dimensions,
            'tracked_events' => $this->trackedEvents,
            'total_catalog_events' => $totalCatalog,
            'tracked_count' => $trackedCount,
            'coverage_percent' => $coveragePercent,
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'version' => '101.0.0',
        ];
    }

    /**
     * Get a quick readiness summary without full details.
     *
     * Returns only scores and grades for dashboard display.
     *
     * @return array{score: float, grade: string, tracked: int, total: int, percent: float, funnel_coverage: float, aarrr_coverage: float}
     */
    public function quickSummary(): array
    {
        $full = $this->assess();

        return [
            'score' => $full['overall_score'],
            'grade' => $full['overall_grade'],
            'tracked' => $full['tracked_count'],
            'total' => $full['total_catalog_events'],
            'percent' => $full['coverage_percent'],
            'funnel_coverage' => $full['dimensions']['funnel_readiness']['percent'],
            'aarrr_coverage' => $full['dimensions']['aarrr_coverage']['percent'],
        ];
    }

    /**
     * Get the top priority recommendations for improving readiness.
     *
     * Returns actionable items sorted by impact, limited to the most
     * important changes that would improve the score the most.
     *
     * @param  int  $limit  Maximum recommendations to return
     * @return list<array{action: string, impact: 'high'|'medium'|'low', dimension: string, description: string}>
     */
    public function topRecommendations(int $limit = 5): array
    {
        $report = $this->assess();
        $recommendations = [];

        foreach ($report['dimensions'] as $key => $dimension) {
            foreach ($dimension['recommendations'] as $rec) {
                $recommendations[] = [
                    'action' => $rec,
                    'impact' => $dimension['status'] === 'poor' || $dimension['status'] === 'missing'
                        ? 'high'
                        : ($dimension['status'] === 'fair' ? 'medium' : 'low'),
                    'dimension' => $key,
                    'description' => $dimension['name'],
                ];
            }
        }

        usort($recommendations, function (array $a, array $b): int {
            $priority = ['high' => 0, 'medium' => 1, 'low' => 2];
            return ($priority[$a['impact']] ?? 2) - ($priority[$b['impact']] ?? 2);
        });

        return array_slice($recommendations, 0, $limit);
    }

    // ─── Dimension Assessments ─────────────────────────────────────

    /**
     * Assess event catalog coverage.
     *
     * Measures what percentage of industry-standard events are tracked
     * by the application. Prioritizes critical and high-priority events.
     *
     * @param  array<string, bool>  $trackedSet
     * @return DimensionScore
     */
    private function assessEventCoverage(array $trackedSet): array
    {
        $industry = EventCatalog::industryStandard();
        $findings = [];
        $recommendations = [];

        $criticalCovered = 0;
        $criticalTotal = count($industry['critical']);
        foreach ($industry['critical'] as $entry) {
            if (isset($trackedSet[$entry['name']])) {
                $criticalCovered++;
            }
        }

        $highCovered = 0;
        $highTotal = count($industry['high']);
        foreach ($industry['high'] as $entry) {
            if (isset($trackedSet[$entry['name']])) {
                $highCovered++;
            }
        }

        $mediumCovered = 0;
        $mediumTotal = count($industry['medium']);
        foreach ($industry['medium'] as $entry) {
            if (isset($trackedSet[$entry['name']])) {
                $mediumCovered++;
            }
        }

        $missingCritical = [];
        foreach ($industry['critical'] as $entry) {
            if (! isset($trackedSet[$entry['name']])) {
                $missingCritical[] = $entry['name'];
            }
        }

        if ($missingCritical !== []) {
            $findings[] = count($missingCritical) . ' critical events missing: ' . implode(', ', $missingCritical);
            $recommendations[] = 'Track critical events first: ' . implode(', ', array_slice($missingCritical, 0, 5));
        }

        $missingHigh = [];
        foreach ($industry['high'] as $entry) {
            if (! isset($trackedSet[$entry['name']])) {
                $missingHigh[] = $entry['name'];
            }
        }

        if ($missingHigh !== []) {
            $findings[] = count($missingHigh) . ' high-priority events missing';
            $recommendations[] = 'Add high-priority event tracking: ' . implode(', ', array_slice($missingHigh, 0, 3));
        }

        if ($criticalCovered === $criticalTotal) {
            $findings[] = 'All critical events are tracked';
        }

        if ($criticalCovered === $criticalTotal && $highCovered === $highTotal) {
            $findings[] = 'All critical and high-priority events covered';
            $recommendations[] = 'Consider adding medium-priority events for deeper insights';
        }

        $totalImportant = $criticalTotal + $highTotal + ($mediumTotal * 0.5);
        $coveredImportant = $criticalCovered + $highCovered + ($mediumCovered * 0.5);
        $score = $totalImportant > 0 ? round(($coveredImportant / $totalImportant) * 100, 1) : 0.0;

        return [
            'name' => 'Event Coverage',
            'score' => round($coveredImportant, 1),
            'max' => round($totalImportant, 1),
            'percent' => $score,
            'status' => $this->statusFromScore($score),
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess provider event mapping coverage.
     *
     * Measures whether tracked events have mappings to all enabled
     * analytics providers (GA4, Meta, PostHog, etc.).
     *
     * @param  array<string, bool>  $trackedSet
     * @return DimensionScore
     */
    private function assessProviderCoverage(array $trackedSet): array
    {
        $findings = [];
        $recommendations = [];
        $allCatalog = EventCatalog::all();

        $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];
        $activeProviders = array_filter($providers, fn (string $p): bool => ($this->enabledProviders[$p] ?? false) === true);

        if ($activeProviders === []) {
            $findings[] = 'No providers configured — using all providers for assessment';
            $activeProviders = $providers;
        }

        $totalChecks = 0;
        $coveredChecks = 0;

        foreach ($this->trackedEvents as $eventName) {
            $entry = $allCatalog[$eventName] ?? null;
            if ($entry === null) {
                continue;
            }

            foreach ($activeProviders as $provider) {
                $totalChecks++;
                $mapped = $entry[$provider] ?? null;
                if ($mapped !== null && $mapped !== '') {
                    $coveredChecks++;
                }
            }
        }

        $poorCoverageEvents = [];
        foreach ($this->trackedEvents as $eventName) {
            $entry = $allCatalog[$eventName] ?? null;
            if ($entry === null) {
                continue;
            }

            $mappedCount = 0;
            foreach ($activeProviders as $provider) {
                $mapped = $entry[$provider] ?? null;
                if ($mapped !== null && $mapped !== '') {
                    $mappedCount++;
                }
            }

            if ($mappedCount === 0) {
                $poorCoverageEvents[] = $eventName;
            }
        }

        if ($poorCoverageEvents !== []) {
            $findings[] = count($poorCoverageEvents) . ' events have no provider mappings';
            $recommendations[] = 'Add provider mappings for: ' . implode(', ', array_slice($poorCoverageEvents, 0, 3));
        }

        $score = $totalChecks > 0 ? round(($coveredChecks / $totalChecks) * 100, 1) : 0.0;

        return [
            'name' => 'Provider Coverage',
            'score' => (float) $coveredChecks,
            'max' => (float) $totalChecks,
            'percent' => $score,
            'status' => $this->statusFromScore($score),
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess funnel readiness.
     *
     * Checks whether industry-standard SaaS funnels are fully instrumented
     * using the SaaSFunnelDefinitions templates.
     *
     * @param  array<string, bool>  $trackedSet
     * @return DimensionScore
     */
    private function assessFunnelReadiness(array $trackedSet): array
    {
        $findings = [];
        $recommendations = [];
        $coverage = SaaSFunnelDefinitions::coverageReport($this->trackedEvents);

        $totalSteps = 0;
        $totalCovered = 0;
        $completeFunnels = 0;
        $partialFunnels = 0;

        foreach ($coverage as $key => $funnel) {
            $totalSteps += $funnel['total_steps'];
            $totalCovered += $funnel['covered_steps'];

            if ($funnel['status'] === 'complete') {
                $completeFunnels++;
                $findings[] = "Funnel '{$funnel['funnel']}' is fully instrumented";
            } elseif ($funnel['status'] === 'partial') {
                $partialFunnels++;
                $missing = $funnel['missing_events'];
                $findings[] = "Funnel '{$funnel['funnel']}' is partially instrumented ({$funnel['coverage_percent']}%)";
                $recommendations[] = "Complete '{$funnel['funnel']}' by tracking: " . implode(', ', $missing);
            } else {
                $missing = $funnel['missing_events'];
                $recommendations[] = "Start instrumenting '{$funnel['funnel']}': " . implode(', ', array_slice($missing, 0, 3));
            }
        }

        $totalFunnels = SaaSFunnelDefinitions::count();
        $findings[] = "{$completeFunnels}/{$totalFunnels} funnels fully instrumented, {$partialFunnels} partial";

        $score = $totalSteps > 0 ? round(($totalCovered / $totalSteps) * 100, 1) : 0.0;

        return [
            'name' => 'Funnel Readiness',
            'score' => (float) $totalCovered,
            'max' => (float) $totalSteps,
            'percent' => $score,
            'status' => $this->statusFromScore($score),
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess AARRR (Pirate Metrics) pillar coverage.
     *
     * Checks whether events exist for each of the five AARRR pillars:
     * Acquisition, Activation, Retention, Revenue, and Referral.
     *
     * @param  array<string, bool>  $trackedSet
     * @return DimensionScore
     */
    private function assessAarrrCoverage(array $trackedSet): array
    {
        $findings = [];
        $recommendations = [];

        $pillarEvents = [
            'acquisition' => ['sign_up', 'page_view', 'campaign_attribution', 'ad_click'],
            'activation' => ['email_verified', 'onboarding_step', 'feature_used', 'activation'],
            'retention' => ['login', 'feature_used', 'content_engagement', 'session_start'],
            'revenue' => ['purchase', 'subscribe', 'plan_upgrade', 'payment_succeeded', 'trial_converted'],
            'referral' => ['share', 'invite_sent', 'team_member_joined', 'team_created'],
        ];

        $totalPillars = count($pillarEvents);
        $coveredPillars = 0;
        $totalEvents = 0;
        $coveredEvents = 0;

        foreach ($pillarEvents as $pillar => $events) {
            $pillarCovered = 0;
            $pillarTotal = count($events);
            $missingForPillar = [];

            foreach ($events as $event) {
                $totalEvents++;
                if (isset($trackedSet[$event])) {
                    $coveredEvents++;
                    $pillarCovered++;
                } else {
                    $missingForPillar[] = $event;
                }
            }

            // A pillar is "covered" if at least half its events are tracked
            if ($pillarCovered >= ceil($pillarTotal / 2)) {
                $coveredPillars++;
                $findings[] = ucfirst($pillar) . " pillar: {$pillarCovered}/{$pillarTotal} events tracked";
            } else {
                $findings[] = ucfirst($pillar) . " pillar: weak ({$pillarCovered}/{$pillarTotal})";
                $recommendations[] = "Strengthen {$pillar} by tracking: " . implode(', ', array_slice($missingForPillar, 0, 3));
            }
        }

        $findings[] = "{$coveredPillars}/{$totalPillars} AARRR pillars adequately covered";

        $score = $totalEvents > 0 ? round(($coveredEvents / $totalEvents) * 100, 1) : 0.0;

        return [
            'name' => 'AARRR Coverage',
            'score' => (float) $coveredEvents,
            'max' => (float) $totalEvents,
            'percent' => $score,
            'status' => $this->statusFromScore($score),
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess identity tracking configuration.
     *
     * Checks whether user identity linking is properly configured
     * for cross-device tracking and user attribution.
     *
     * @return DimensionScore
     */
    private function assessIdentityTracking(): array
    {
        $findings = [];
        $recommendations = [];
        $score = 0.0;

        $hasIdentity = ($this->configFlags['identity'] ?? false) === true;
        $hasApi = ($this->configFlags['api'] ?? false) === true;
        $hasLifecycle = ($this->configFlags['lifecycle'] ?? false) === true;

        if ($hasIdentity) {
            $score += 40.0;
            $findings[] = 'Identity tracking is enabled';
        } else {
            $recommendations[] = 'Enable identity tracking (client_id ↔ user_id linking)';
        }

        if ($hasApi) {
            $score += 30.0;
            $findings[] = 'Analytics API endpoints are available';
        } else {
            $recommendations[] = 'Enable the analytics API for client-side event tracking';
        }

        if ($hasLifecycle) {
            $score += 30.0;
            $findings[] = 'Lifecycle event mapping is configured';
        } else {
            $recommendations[] = 'Enable lifecycle event mapping for server-side auto-tracking';
        }

        $score = min(100.0, $score);

        return [
            'name' => 'Identity Tracking',
            'score' => $score,
            'max' => 100.0,
            'percent' => $score,
            'status' => $this->statusFromScore($score),
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess e-commerce event readiness.
     *
     * Checks whether the core e-commerce purchase flow events
     * are tracked for revenue analytics.
     *
     * @param  array<string, bool>  $trackedSet
     * @return DimensionScore
     */
    private function assessEcommerceReadiness(array $trackedSet): array
    {
        $findings = [];
        $recommendations = [];

        $ecommerceEvents = EcommerceEvents::names();
        $trackedEcommerce = array_filter($ecommerceEvents, fn (string $e): bool => isset($trackedSet[$e]));
        $trackedCount = count($trackedEcommerce);
        $totalCount = count($ecommerceEvents);

        // Core e-commerce events that should always be tracked
        $coreEcommerce = ['view_item', 'add_to_cart', 'begin_checkout', 'purchase'];
        $coreCovered = count(array_filter($coreEcommerce, fn (string $e): bool => isset($trackedSet[$e])));

        $findings[] = "E-commerce: {$trackedCount}/{$totalCount} events tracked ({$coreCovered}/" . count($coreEcommerce) . ' core)';

        $missingCore = array_filter($coreEcommerce, fn (string $e): bool => ! isset($trackedSet[$e]));
        if ($missingCore !== []) {
            $recommendations[] = 'Track core e-commerce events: ' . implode(', ', $missingCore);
        }

        if ($coreCovered === count($coreEcommerce)) {
            $findings[] = 'All core e-commerce events are tracked';
        }

        $hasEcommerceConfig = ($this->configFlags['ecommerce'] ?? false) === true;
        if ($hasEcommerceConfig) {
            $findings[] = 'E-commerce configuration is present';
        }

        $score = $totalCount > 0 ? round(($trackedCount / $totalCount) * 100, 1) : 0.0;

        return [
            'name' => 'E-commerce Readiness',
            'score' => (float) $trackedCount,
            'max' => (float) $totalCount,
            'percent' => $score,
            'status' => $this->statusFromScore($score),
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Assess configuration quality.
     *
     * Checks whether essential configuration features are enabled
     * for production-grade analytics.
     *
     * @return DimensionScore
     */
    private function assessConfigurationQuality(): array
    {
        $findings = [];
        $recommendations = [];
        $score = 0.0;

        $checks = [
            'queue' => ['Queue dispatch', 20.0, 'Enable async queue for non-blocking event dispatch'],
            'auto_track' => ['Auto-tracking', 20.0, 'Enable auto-tracking for common Laravel events'],
            'consent' => ['Consent management', 20.0, 'Configure consent management for GDPR compliance'],
            'ecommerce' => ['E-commerce config', 15.0, 'Set e-commerce defaults (currency, brand)'],
            'api' => ['API endpoints', 15.0, 'Enable API endpoints for client-side tracking'],
            'lifecycle' => ['Lifecycle mapping', 10.0, 'Configure lifecycle event → analytics mapping'],
        ];

        foreach ($checks as $key => [$label, $points, $rec]) {
            if (($this->configFlags[$key] ?? false) === true) {
                $score += $points;
                $findings[] = "{$label} is configured";
            } else {
                $recommendations[] = $rec;
            }
        }

        $score = min(100.0, round($score, 1));

        return [
            'name' => 'Configuration Quality',
            'score' => $score,
            'max' => 100.0,
            'percent' => $score,
            'status' => $this->statusFromScore($score),
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * Calculate a letter grade from a score.
     *
     * @return 'A'|'B'|'C'|'D'|'F'
     */
    private function calculateGrade(float $score): string
    {
        return match (true) {
            $score >= 90.0 => 'A',
            $score >= 75.0 => 'B',
            $score >= 60.0 => 'C',
            $score >= 40.0 => 'D',
            default => 'F',
        };
    }

    /**
     * Get a status label from a percentage score.
     *
     * @return 'excellent'|'good'|'fair'|'poor'|'missing'
     */
    private function statusFromScore(float $score): string
    {
        return match (true) {
            $score >= 90.0 => 'excellent',
            $score >= 70.0 => 'good',
            $score >= 50.0 => 'fair',
            $score >= 25.0 => 'poor',
            default => 'missing',
        };
    }
}
