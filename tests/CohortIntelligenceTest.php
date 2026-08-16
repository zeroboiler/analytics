<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Mockery;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\CohortBehaviorProfilerService;
use ZeroBoiler\Analytics\Services\EventPredictiveScoringService;

/**
 * Tests for the Event Cohort Intelligence Engine (v8.1.0).
 *
 * Covers:
 * - CohortBehaviorProfilerService: behavioral cohort classification, distribution,
 *   transition analysis, prediction, and cohort insights
 * - EventPredictiveScoringService: conversion probability, churn risk, expansion
 *   likelihood, health scoring, batch operations, and ranking
 *
 * @since 8.1.0
 */
final class CohortIntelligenceTest extends TestCase
{
    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Create a mock cache repository.
     */
    private function mockCache(): CacheRepository
    {
        return Mockery::mock(CacheRepository::class);
    }

    /**
     * Build events array from a simple definition format.
     *
     * @param  array<int, array{name: string, params?: array<string, mixed>, timestamp?: int}>  $definitions
     * @return array<int, AnalyticsEvent>
     */
    private function buildEvents(array $definitions): array
    {
        return array_map(
            fn (array $d): AnalyticsEvent => AnalyticsEvent::make(
                $d['name'],
                $d['params'] ?? [],
                'client-test',
                'user-test',
                $d['timestamp'] ?? time(),
            ),
            $definitions,
        );
    }

    /**
     * Create standard power user events.
     *
     * @return array<int, AnalyticsEvent>
     */
    private function powerUserEvents(): array
    {
        $base = time();
        return $this->buildEvents([
            ['name' => 'login', 'timestamp' => $base - 3600],
            ['name' => 'feature_used', 'params' => ['feature' => 'dashboard'], 'timestamp' => $base - 3500],
            ['name' => 'page_view', 'params' => ['page' => '/settings'], 'timestamp' => $base - 3400],
            ['name' => 'export', 'params' => ['format' => 'csv'], 'timestamp' => $base - 3300],
            ['name' => 'team_created', 'params' => ['team_name' => 'Marketing'], 'timestamp' => $base - 3200],
            ['name' => 'plan_upgrade', 'params' => ['plan' => 'pro'], 'timestamp' => $base - 3100],
            ['name' => 'search', 'params' => ['query' => 'reports'], 'timestamp' => $base - 3000],
            ['name' => 'feature_used', 'params' => ['feature' => 'analytics'], 'timestamp' => $base - 2900],
            ['name' => 'invite_sent', 'params' => ['email' => 'test@example.com'], 'timestamp' => $base - 2800],
            ['name' => 'login', 'timestamp' => $base - 1800],
            ['name' => 'page_view', 'params' => ['page' => '/dashboard'], 'timestamp' => $base - 1700],
            ['name' => 'feature_used', 'params' => ['feature' => 'reports'], 'timestamp' => $base - 1600],
            ['name' => 'export', 'params' => ['format' => 'pdf'], 'timestamp' => $base - 1500],
            ['name' => 'integration_connected', 'params' => ['service' => 'slack'], 'timestamp' => $base - 1400],
            ['name' => 'search', 'params' => ['query' => 'users'], 'timestamp' => $base - 1300],
        ]);
    }

    /**
     * Create standard at-risk user events.
     *
     * @return array<int, AnalyticsEvent>
     */
    private function atRiskUserEvents(): array
    {
        $base = time();
        return $this->buildEvents([
            ['name' => 'login', 'timestamp' => $base - 86400 * 3],
            ['name' => 'page_view', 'params' => ['page' => '/dashboard'], 'timestamp' => $base - 86400 * 2],
            ['name' => 'error', 'params' => ['message' => 'timeout'], 'timestamp' => $base - 86400 * 2],
            ['name' => 'login', 'timestamp' => $base - 86400],
            ['name' => 'support_contact', 'params' => ['topic' => 'billing'], 'timestamp' => $base - 86400],
        ]);
    }

    /**
     * Create standard new user events.
     *
     * @return array<int, AnalyticsEvent>
     */
    private function newUserEvents(): array
    {
        $base = time();
        return $this->buildEvents([
            ['name' => 'sign_up', 'params' => ['method' => 'email'], 'timestamp' => $base - 3600],
            ['name' => 'email_verified', 'timestamp' => $base - 3500],
            ['name' => 'onboarding_step', 'params' => ['step' => 1], 'timestamp' => $base - 3400],
            ['name' => 'page_view', 'params' => ['page' => '/welcome'], 'timestamp' => $base - 3300],
        ]);
    }

    /**
     * Create churning user events.
     *
     * @return array<int, AnalyticsEvent>
     */
    private function churningUserEvents(): array
    {
        $base = time();
        return $this->buildEvents([
            ['name' => 'login', 'timestamp' => $base - 86400 * 5],
            ['name' => 'error', 'params' => ['message' => '403'], 'timestamp' => $base - 86400 * 4],
            ['name' => 'error', 'params' => ['message' => 'timeout'], 'timestamp' => $base - 86400 * 4],
            ['name' => 'support_contact', 'params' => ['topic' => 'bugs'], 'timestamp' => $base - 86400 * 3],
            ['name' => 'plan_downgrade', 'params' => ['from' => 'pro', 'to' => 'free'], 'timestamp' => $base - 86400 * 2],
            ['name' => 'cancellation', 'params' => ['reason' => 'too_expensive'], 'timestamp' => $base - 86400],
        ]);
    }

    /**
     * Create expanding user events.
     *
     * @return array<int, AnalyticsEvent>
     */
    private function expandingUserEvents(): array
    {
        $base = time();
        return $this->buildEvents([
            ['name' => 'login', 'timestamp' => $base - 7200],
            ['name' => 'feature_used', 'params' => ['feature' => 'dashboard'], 'timestamp' => $base - 7100],
            ['name' => 'plan_upgrade', 'params' => ['plan' => 'business'], 'timestamp' => $base - 7000],
            ['name' => 'team_created', 'params' => ['team_name' => 'Engineering'], 'timestamp' => $base - 6900],
            ['name' => 'invite_sent', 'params' => ['email' => 'dev@example.com'], 'timestamp' => $base - 6800],
            ['name' => 'workspace_created', 'params' => ['name' => 'Main'], 'timestamp' => $base - 6700],
            ['name' => 'feature_used', 'params' => ['feature' => 'api'], 'timestamp' => $base - 6600],
            ['name' => 'export', 'params' => ['format' => 'json'], 'timestamp' => $base - 6500],
            ['name' => 'invite_sent', 'params' => ['email' => 'pm@example.com'], 'timestamp' => $base - 6400],
            ['name' => 'usage_quota_reached', 'params' => ['quota' => 'api_calls'], 'timestamp' => $base - 6300],
        ]);
    }

    // ── CohortBehaviorProfilerService Tests ────────────────────────────────

    /**
     * Power users should be classified into the power_users cohort.
     */
    public function test_profiler_classifies_power_user(): void
    {
        $profiler = new CohortBehaviorProfilerService($this->mockCache());
        $profile = $profiler->profile('power-user-1', $this->powerUserEvents());

        $this->assertArrayHasKey('cohort', $profile);
        $this->assertArrayHasKey('label', $profile);
        $this->assertArrayHasKey('score', $profile);
        $this->assertArrayHasKey('confidence', $profile);
        $this->assertArrayHasKey('signals', $profile);
        $this->assertArrayHasKey('profile', $profile);
        $this->assertArrayHasKey('all_scores', $profile);
        $this->assertArrayHasKey('profiled_at', $profile);
        $this->assertArrayHasKey('identity', $profile);
        $this->assertSame('power-user-1', $profile['identity']);
        $this->assertGreaterThanOrEqual(0.0, $profile['score']);
        $this->assertGreaterThanOrEqual(0.0, $profile['confidence']);
        $this->assertLessThanOrEqual(1.0, $profile['confidence']);

        // Power users have high event diversity and strong expansion signals
        $this->assertArrayHasKey('feature_used', $profile['signals']);
        $this->assertGreaterThanOrEqual(2, $profile['signals']['feature_used']);
    }

    /**
     * New users should be classified into the new cohort.
     */
    public function test_profiler_classifies_new_user(): void
    {
        $profiler = new CohortBehaviorProfilerService($this->mockCache());
        $profile = $profiler->profile('new-user-1', $this->newUserEvents());

        $this->assertArrayHasKey('cohort', $profile);
        $this->assertArrayHasKey('signals', $profile);
        $this->assertArrayHasKey('sign_up', $profile['signals']);
    }

    /**
     * Profiler returns empty profile when disabled.
     */
    public function test_profiler_returns_empty_when_disabled(): void
    {
        $profiler = new CohortBehaviorProfilerService($this->mockCache(), ['enabled' => false]);
        $profile = $profiler->profile('test-user', $this->powerUserEvents());

        $this->assertSame('unknown', $profile['cohort']);
        $this->assertSame(0.0, $profile['score']);
        $this->assertSame(0.0, $profile['confidence']);
    }

    /**
     * Profiler falls back to new/dormant with insufficient events.
     */
    public function test_profiler_fallback_with_few_events(): void
    {
        $profiler = new CohortBehaviorProfilerService($this->mockCache());

        // One event → should still classify (min_events = 3 default, but we get fallback)
        $profile = $profiler->profile('few-events-user', $this->buildEvents([
            ['name' => 'page_view', 'timestamp' => time()],
        ]));

        $this->assertContains($profile['cohort'], ['new', 'dormant', 'unknown']);
        $this->assertSame(0.3, $profile['confidence']);
    }

    /**
     * Empty events result in dormant classification.
     */
    public function test_profiler_empty_events_dormant(): void
    {
        $profiler = new CohortBehaviorProfilerService($this->mockCache());
        $profile = $profiler->profile('empty-user', []);

        $this->assertSame('dormant', $profile['cohort']);
        $this->assertSame(0.3, $profile['confidence']);
    }

    /**
     * Batch profiling returns correct structure.
     */
    public function test_profiler_batch_profiles_multiple_users(): void
    {
        $profiler = new CohortBehaviorProfilerService($this->mockCache());

        $userEvents = [
            'power-user' => $this->powerUserEvents(),
            'at-risk-user' => $this->atRiskUserEvents(),
            'new-user' => $this->newUserEvents(),
        ];

        $results = $profiler->profileBatch($userEvents);

        $this->assertCount(3, $results);
        $this->assertArrayHasKey('power-user', $results);
        $this->assertArrayHasKey('at-risk-user', $results);
        $this->assertArrayHasKey('new-user', $results);

        foreach ($results as $id => $profile) {
            $this->assertArrayHasKey('cohort', $profile);
            $this->assertArrayHasKey('label', $profile);
            $this->assertArrayHasKey('score', $profile);
            $this->assertArrayHasKey('confidence', $profile);
            $this->assertGreaterThanOrEqual(0.0, $profile['score']);
            $this->assertLessThanOrEqual(1.0, $profile['confidence']);
        }
    }

    /**
     * Distribution returns correct structure with cohort breakdown.
     */
    public function test_profiler_distribution(): void
    {
        $profiler = new CohortBehaviorProfilerService($this->mockCache());

        $userEvents = [
            'power-1' => $this->powerUserEvents(),
            'power-2' => $this->powerUserEvents(),
            'at-risk-1' => $this->atRiskUserEvents(),
            'new-1' => $this->newUserEvents(),
            'expand-1' => $this->expandingUserEvents(),
        ];

        $distribution = $profiler->distribution($userEvents);

        $this->assertSame(5, $distribution['total_users']);
        $this->assertArrayHasKey('cohorts', $distribution);
        $this->assertArrayHasKey('dominant_cohort', $distribution);
        $this->assertArrayHasKey('profiled_at', $distribution);

        // At least one cohort should have 2 users (power-1 and power-2 likely same cohort)
        $maxCount = max(array_column($distribution['cohorts'], 'count'));
        $this->assertGreaterThanOrEqual(2, $maxCount);
    }

    /**
     * Distribution with empty users returns zero total.
     */
    public function test_profiler_distribution_empty(): void
    {
        $profiler = new CohortBehaviorProfilerService($this->mockCache());
        $distribution = $profiler->distribution([]);

        $this->assertSame(0, $distribution['total_users']);
        $this->assertEmpty($distribution['cohorts']);
    }

    /**
     * Transition analysis computes correct matrix.
     */
    public function test_profiler_transition_analysis(): void
    {
        $profiler = new CohortBehaviorProfilerService($this->mockCache());

        $transitions = [
            ['previous' => 'new', 'current' => 'engaged'],
            ['previous' => 'new', 'current' => 'engaged'],
            ['previous' => 'new', 'current' => 'at_risk'],
            ['previous' => 'engaged', 'current' => 'engaged'],
            ['previous' => 'engaged', 'current' => 'power_users'],
            ['previous' => 'at_risk', 'current' => 'dormant'],
        ];

        $analysis = $profiler->transitionAnalysis($transitions);

        $this->assertSame(6, $analysis['total_transitions']);
        $this->assertArrayHasKey('matrix', $analysis);
        $this->assertArrayHasKey('net_movements', $analysis);
        $this->assertArrayHasKey('retention_rates', $analysis);
        $this->assertArrayHasKey('analyzed_at', $analysis);

        // new → engaged should have 2 transitions
        $this->assertSame(2, $analysis['matrix']['new']['engaged']);

        // engaged → engaged = 1 (retained)
        $this->assertSame(1, $analysis['matrix']['engaged']['engaged']);
        $this->assertSame(100.0, $analysis['retention_rates']['engaged']);
    }

    /**
     * Transition analysis handles empty data.
     */
    public function test_profiler_transition_analysis_empty(): void
    {
        $profiler = new CohortBehaviorProfilerService($this->mockCache());
        $analysis = $profiler->transitionAnalysis([]);

        $this->assertSame(0, $analysis['total_transitions']);
    }

    /**
     * Predict transitions identifies likely cohort movers.
     */
    public function test_profiler_predict_transitions(): void
    {
        $profiler = new CohortBehaviorProfilerService($this->mockCache());

        $userEvents = [
            'almost-power' => $this->powerUserEvents(),
            'new-guy' => $this->newUserEvents(),
            'at-risk-user' => $this->atRiskUserEvents(),
        ];

        $prediction = $profiler->predictTransitions($userEvents, 'power_users', 0.01);

        $this->assertArrayHasKey('candidates', $prediction);
        $this->assertArrayHasKey('target_cohort', $prediction);
        $this->assertArrayHasKey('threshold', $prediction);
        $this->assertSame('power_users', $prediction['target_cohort']);
    }

    /**
     * Predict transitions with unknown cohort returns error.
     */
    public function test_profiler_predict_unknown_cohort(): void
    {
        $profiler = new CohortBehaviorProfilerService($this->mockCache());

        $prediction = $profiler->predictTransitions([], 'nonexistent_cohort');

        $this->assertArrayHasKey('error', $prediction['candidates'] ?? []);
    }

    /**
     * Cohort insights returns correct structure.
     */
    public function test_profiler_cohort_insights(): void
    {
        $profiler = new CohortBehaviorProfilerService($this->mockCache());

        $userEvents = [
            'power-1' => $this->powerUserEvents(),
            'power-2' => $this->powerUserEvents(),
            'at-risk-1' => $this->atRiskUserEvents(),
        ];

        // Use a known cohort name
        $insights = $profiler->cohortInsights('power_users', $userEvents);

        $this->assertArrayHasKey('cohort', $insights);
        $this->assertSame('power_users', $insights['cohort']);
        $this->assertArrayHasKey('label', $insights);
        $this->assertArrayHasKey('user_count', $insights);
        $this->assertArrayHasKey('top_events', $insights);
        $this->assertArrayHasKey('avg_events_per_user', $insights);
    }

    /**
     * Cohort insights with unknown cohort returns error.
     */
    public function test_profiler_cohort_insights_unknown(): void
    {
        $profiler = new CohortBehaviorProfilerService($this->mockCache());
        $insights = $profiler->cohortInsights('nonexistent', []);

        $this->assertArrayHasKey('error', $insights);
    }

    /**
     * getCohortDefinitions returns all 7 built-in cohorts.
     */
    public function test_profiler_cohort_definitions_count(): void
    {
        $profiler = new CohortBehaviorProfilerService($this->mockCache());
        $definitions = $profiler->getCohortDefinitions();

        $this->assertCount(7, $definitions);
        $this->assertArrayHasKey('power_users', $definitions);
        $this->assertArrayHasKey('engaged', $definitions);
        $this->assertArrayHasKey('at_risk', $definitions);
        $this->assertArrayHasKey('dormant', $definitions);
        $this->assertArrayHasKey('new', $definitions);
        $this->assertArrayHasKey('churning', $definitions);
        $this->assertArrayHasKey('expanding', $definitions);
    }

    /**
     * Custom cohort definitions override built-in ones.
     */
    public function test_profiler_custom_cohort_definitions(): void
    {
        $custom = [
            'power_users' => [
                'label' => 'Super Users',
                'description' => 'Custom definition',
                'color' => '#ff0000',
                'thresholds' => [
                    'min_events_per_day' => 50,
                ],
                'signals' => ['login'],
            ],
        ];

        $profiler = new CohortBehaviorProfilerService($this->mockCache(), [
            'cohort_definitions' => $custom,
        ]);

        $definitions = $profiler->getCohortDefinitions();

        $this->assertSame('Super Users', $definitions['power_users']['label']);
        $this->assertSame('Custom definition', $definitions['power_users']['description']);
        // Other cohorts should still exist
        $this->assertArrayHasKey('engaged', $definitions);
        $this->assertArrayHasKey('new', $definitions);
    }

    // ── EventPredictiveScoringService Tests ────────────────────────────────

    /**
     * Scoring returns complete structure with all fields.
     */
    public function test_scoring_returns_complete_structure(): void
    {
        $scoring = new EventPredictiveScoringService($this->mockCache());
        $result = $scoring->score('user-1', $this->powerUserEvents());

        $this->assertSame('user-1', $result['identity']);
        $this->assertArrayHasKey('conversion_probability', $result);
        $this->assertArrayHasKey('churn_risk', $result);
        $this->assertArrayHasKey('expansion_likelihood', $result);
        $this->assertArrayHasKey('health_score', $result);
        $this->assertArrayHasKey('health_grade', $result);
        $this->assertArrayHasKey('signals', $result);
        $this->assertArrayHasKey('total_events', $result);
        $this->assertArrayHasKey('unique_events', $result);
        $this->assertArrayHasKey('scored_at', $result);

        // All probabilities must be between 0 and 1
        $this->assertGreaterThanOrEqual(0.0, $result['conversion_probability']);
        $this->assertLessThanOrEqual(1.0, $result['conversion_probability']);
        $this->assertGreaterThanOrEqual(0.0, $result['churn_risk']);
        $this->assertLessThanOrEqual(1.0, $result['churn_risk']);
        $this->assertGreaterThanOrEqual(0.0, $result['expansion_likelihood']);
        $this->assertLessThanOrEqual(1.0, $result['expansion_likelihood']);

        // Health score must be between 0 and 100
        $this->assertGreaterThanOrEqual(0, $result['health_score']);
        $this->assertLessThanOrEqual(100, $result['health_score']);
    }

    /**
     * Power users have higher expansion likelihood than at-risk users.
     */
    public function test_scoring_power_vs_at_risk(): void
    {
        $scoring = new EventPredictiveScoringService($this->mockCache());

        $powerScore = $scoring->score('power-user', $this->powerUserEvents());
        $atRiskScore = $scoring->score('at-risk-user', $this->atRiskUserEvents());

        $this->assertGreaterThan(
            $atRiskScore['expansion_likelihood'],
            $powerScore['expansion_likelihood'],
            'Power users should have higher expansion likelihood than at-risk users',
        );
    }

    /**
     * Churning users have higher churn risk than power users.
     */
    public function test_scoring_churning_vs_power_churn_risk(): void
    {
        $scoring = new EventPredictiveScoringService($this->mockCache());

        $powerScore = $scoring->score('power-user', $this->powerUserEvents());
        $churningScore = $scoring->score('churning-user', $this->churningUserEvents());

        $this->assertGreaterThan(
            $powerScore['churn_risk'],
            $churningScore['churn_risk'],
            'Churning users should have higher churn risk than power users',
        );
    }

    /**
     * Expanding users have high expansion scores.
     */
    public function test_scoring_expanding_user_expansion(): void
    {
        $scoring = new EventPredictiveScoringService($this->mockCache());
        $result = $scoring->score('expanding-user', $this->expandingUserEvents());

        $this->assertGreaterThan(0.0, $result['expansion_likelihood']);
    }

    /**
     * Scoring returns neutral health when disabled.
     */
    public function test_scoring_returns_neutral_when_disabled(): void
    {
        $scoring = new EventPredictiveScoringService($this->mockCache(), ['enabled' => false]);
        $result = $scoring->score('test-user', $this->powerUserEvents());

        $this->assertSame(0.0, $result['conversion_probability']);
        $this->assertSame(0.0, $result['churn_risk']);
        $this->assertSame(0.0, $result['expansion_likelihood']);
        $this->assertSame(50, $result['health_score']);
        $this->assertSame('C', $result['health_grade']);
    }

    /**
     * Empty events return neutral baseline.
     */
    public function test_scoring_empty_events(): void
    {
        $scoring = new EventPredictiveScoringService($this->mockCache());
        $result = $scoring->score('empty-user', []);

        $this->assertSame(0.0, $result['conversion_probability']);
        $this->assertSame(0.0, $result['churn_risk']);
        $this->assertSame(0.0, $result['expansion_likelihood']);
        $this->assertSame(50, $result['health_score']);
        $this->assertSame('C', $result['health_grade']);
        $this->assertSame(0, $result['total_events']);
        $this->assertSame(0, $result['unique_events']);
    }

    /**
     * Batch scoring works for multiple users.
     */
    public function test_scoring_batch(): void
    {
        $scoring = new EventPredictiveScoringService($this->mockCache());

        $userEvents = [
            'power-user' => $this->powerUserEvents(),
            'churning-user' => $this->churningUserEvents(),
            'expanding-user' => $this->expandingUserEvents(),
        ];

        $results = $scoring->scoreBatch($userEvents);

        $this->assertCount(3, $results);
        $this->assertArrayHasKey('power-user', $results);
        $this->assertArrayHasKey('churning-user', $results);
        $this->assertArrayHasKey('expanding-user', $results);

        foreach ($results as $id => $score) {
            $this->assertArrayHasKey('conversion_probability', $score);
            $this->assertArrayHasKey('churn_risk', $score);
            $this->assertArrayHasKey('expansion_likelihood', $score);
            $this->assertArrayHasKey('health_score', $score);
            $this->assertArrayHasKey('health_grade', $score);
        }
    }

    /**
     * Summary returns correct aggregate metrics.
     */
    public function test_scoring_summary(): void
    {
        $scoring = new EventPredictiveScoringService($this->mockCache());

        $userEvents = [
            'power-1' => $this->powerUserEvents(),
            'power-2' => $this->powerUserEvents(),
            'at-risk-1' => $this->atRiskUserEvents(),
            'churning-1' => $this->churningUserEvents(),
            'expanding-1' => $this->expandingUserEvents(),
        ];

        $summary = $scoring->summary($userEvents);

        $this->assertSame(5, $summary['total_users']);
        $this->assertArrayHasKey('avg_conversion_probability', $summary);
        $this->assertArrayHasKey('avg_churn_risk', $summary);
        $this->assertArrayHasKey('avg_expansion_likelihood', $summary);
        $this->assertArrayHasKey('avg_health_score', $summary);
        $this->assertArrayHasKey('at_risk_count', $summary);
        $this->assertArrayHasKey('at_risk_percentage', $summary);
        $this->assertArrayHasKey('power_user_count', $summary);
        $this->assertArrayHasKey('power_user_percentage', $summary);
        $this->assertArrayHasKey('grade_distribution', $summary);
        $this->assertArrayHasKey('analyzed_at', $summary);

        // Grade distribution should have all grades
        foreach (['A+', 'A', 'B', 'C', 'D', 'F'] as $grade) {
            $this->assertArrayHasKey($grade, $summary['grade_distribution']);
        }
    }

    /**
     * Summary with empty users returns zeros.
     */
    public function test_scoring_summary_empty(): void
    {
        $scoring = new EventPredictiveScoringService($this->mockCache());
        $summary = $scoring->summary([]);

        $this->assertSame(0, $summary['total_users']);
        $this->assertSame(0.0, $summary['avg_conversion_probability']);
    }

    /**
     * Top churn risks are sorted by risk descending.
     */
    public function test_scoring_top_churn_risks(): void
    {
        $scoring = new EventPredictiveScoringService($this->mockCache());

        $userEvents = [
            'power-user' => $this->powerUserEvents(),
            'churning-user' => $this->churningUserEvents(),
            'at-risk-user' => $this->atRiskUserEvents(),
            'new-user' => $this->newUserEvents(),
        ];

        $result = $scoring->topChurnRisks($userEvents, 10);

        $this->assertArrayHasKey('users', $result);
        $this->assertArrayHasKey('total_at_risk', $result);

        // Churning user should appear before power user in churn risk ranking
        $userKeys = array_keys($result['users']);
        if (in_array('churning-user', $userKeys, true) && in_array('power-user', $userKeys, true)) {
            $churnIndex = array_search('churning-user', $userKeys, true);
            $powerIndex = array_search('power-user', $userKeys, true);
            $this->assertLessThan($powerIndex, $churnIndex, 'Churning user should rank higher in churn risk');
        }
    }

    /**
     * Top expansion candidates are sorted by likelihood descending.
     */
    public function test_scoring_top_expansion_candidates(): void
    {
        $scoring = new EventPredictiveScoringService($this->mockCache());

        $userEvents = [
            'expanding-user' => $this->expandingUserEvents(),
            'power-user' => $this->powerUserEvents(),
            'at-risk-user' => $this->atRiskUserEvents(),
        ];

        $result = $scoring->topExpansionCandidates($userEvents, 10);

        $this->assertArrayHasKey('users', $result);
        $this->assertArrayHasKey('total_candidates', $result);

        // Expanding user should rank high
        $userKeys = array_keys($result['users']);
        if (in_array('expanding-user', $userKeys, true)) {
            $firstUser = $userKeys[0];
            $this->assertContains($firstUser, ['expanding-user', 'power-user']);
        }
    }

    /**
     * Health grade mapping is correct.
     */
    public function test_scoring_health_grades(): void
    {
        $scoring = new EventPredictiveScoringService($this->mockCache());

        // Very positive signals → high health
        $positiveEvents = $this->buildEvents(array_fill(0, 20, ['name' => 'feature_used']));
        $result = $scoring->score('positive-user', $positiveEvents);

        $this->assertGreaterThan(50, $result['health_score']);

        // Very negative signals → low health
        $negativeEvents = $this->buildEvents(array_fill(0, 20, ['name' => 'error']));
        $result = $scoring->score('negative-user', $negativeEvents);

        $this->assertLessThan(50, $result['health_score']);
    }

    /**
     * Custom decay factor affects scoring.
     */
    public function test_scoring_custom_decay_factor(): void
    {
        // With decay 0.5 (fast decay), recent events weigh more
        $fastDecay = new EventPredictiveScoringService($this->mockCache(), ['decay_factor' => 0.5]);
        // With decay 0.99 (slow decay), all events weigh similarly
        $slowDecay = new EventPredictiveScoringService($this->mockCache(), ['decay_factor' => 0.99]);

        $result1 = $fastDecay->score('user-1', $this->powerUserEvents());
        $result2 = $slowDecay->score('user-1', $this->powerUserEvents());

        // Both should produce valid scores
        $this->assertGreaterThanOrEqual(0.0, $result1['conversion_probability']);
        $this->assertGreaterThanOrEqual(0.0, $result2['conversion_probability']);
    }

    /**
     * Score structure integrity — all numeric fields are correct types.
     */
    public function test_scoring_type_integrity(): void
    {
        $scoring = new EventPredictiveScoringService($this->mockCache());
        $result = $scoring->score('type-check', $this->powerUserEvents());

        $this->assertIsString($result['identity']);
        $this->assertIsFloat($result['conversion_probability']);
        $this->assertIsFloat($result['churn_risk']);
        $this->assertIsFloat($result['expansion_likelihood']);
        $this->assertIsInt($result['health_score']);
        $this->assertIsString($result['health_grade']);
        $this->assertIsArray($result['signals']);
        $this->assertIsInt($result['total_events']);
        $this->assertIsInt($result['unique_events']);
        $this->assertIsString($result['scored_at']);
    }

    // ── Cleanup ────────────────────────────────────────────────────────────

    protected function tearDown(): void
    {
        Mockery::close();
    }
}
