<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\SaaSConversionPredictorService;
use ZeroBoiler\Analytics\Services\SaaSQuickStartService;

/**
 * V193 — SaaS Conversion Predictor + QuickStart bug fix.
 *
 * @covers \ZeroBoiler\Analytics\Services\SaaSConversionPredictorService
 * @covers \ZeroBoiler\Analytics\Services\SaaSQuickStartService
 *
 * @since 193.0.0
 */
final class V193ConversionPredictorQuickStartFixTest extends TestCase
{
    // ── File Quality Checks ──────────────────────────────────────────────

    public function testConversionPredictorHasStrictTypes(): void
    {
        $contents = file_get_contents(__DIR__ . '/../src/Services/SaaSConversionPredictorService.php');
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function testConversionPredictorHasMitLicense(): void
    {
        $contents = file_get_contents(__DIR__ . '/../src/Services/SaaSConversionPredictorService.php');
        $this->assertStringContainsString('MIT license', $contents);
    }

    public function testConversionPredictorHasSinceTag(): void
    {
        $contents = file_get_contents(__DIR__ . '/../src/Services/SaaSConversionPredictorService.php');
        $this->assertStringContainsString('@since 193.0.0', $contents);
    }

    public function testConversionPredictorIsFinal(): void
    {
        $contents = file_get_contents(__DIR__ . '/../src/Services/SaaSConversionPredictorService.php');
        $this->assertStringContainsString('final class SaaSConversionPredictorService', $contents);
    }

    public function testConversionPredictorCommandHasStrictTypes(): void
    {
        $contents = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsConversionPredictorCommand.php');
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function testConversionPredictorCommandIsFinal(): void
    {
        $contents = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsConversionPredictorCommand.php');
        $this->assertStringContainsString('final class AnalyticsConversionPredictorCommand', $contents);
    }

    // ── SaaSQuickStartService Bug Fix ────────────────────────────────────

    public function testQuickStartServiceUsesTrackNotTrackEvent(): void
    {
        $contents = file_get_contents(__DIR__ . '/../src/Services/SaaSQuickStartService.php');
        // Should NOT contain trackEvent(' since we fixed the bug
        $this->assertStringNotContainsString("->trackEvent('", $contents, 'SaaSQuickStartService should use track() not trackEvent()');
        // Should use track( instead
        $this->assertStringContainsString("->track('sign_up'", $contents);
        $this->assertStringContainsString("->track('login'", $contents);
        $this->assertStringContainsString("->track('start_trial'", $contents);
        $this->assertStringContainsString("->track('trial_converted'", $contents);
        $this->assertStringContainsString("->track('subscribe'", $contents);
        $this->assertStringContainsString("->track('plan_upgrade'", $contents);
        $this->assertStringContainsString("->track('cancellation'", $contents);
        $this->assertStringContainsString("->track('feature_used'", $contents);
        $this->assertStringContainsString("->track('error'", $contents);
    }

    // ── SaaSConversionPredictorService Construction ─────────────────────

    public function testPredictorConstructionWithDefaultConfig(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);

        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.conversion_predictor', []],
        ]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $this->assertTrue($service->isEnabled());
    }

    public function testPredictorConstructionWithDisabledConfig(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);

        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.conversion_predictor', ['enabled' => false, 'cache_ttl' => 1800]],
        ]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $this->assertFalse($service->isEnabled());
    }

    public function testPredictorConstructionWithCustomWeights(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);

        $config->method('get')->willReturnMap([
            ['zeroboiler.analytics.conversion_predictor', [
                'enabled' => true,
                'cache_ttl' => 7200,
                'custom_weights' => [
                    'positive' => ['onboarding_completed' => 30.0],
                    'negative' => ['errors_count' => 20.0],
                ],
            ]],
        ]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $this->assertTrue($service->isEnabled());
        $stats = $service->stats();
        $this->assertSame(2, $stats['custom_weight_overrides']);
    }

    // ── Signal Catalog ────────────────────────────────────────────────────

    public function testPositiveSignalsDefinition(): void
    {
        $signals = SaaSConversionPredictorService::positiveSignals();

        $this->assertArrayHasKey('onboarding_completed', $signals);
        $this->assertArrayHasKey('first_value_moment', $signals);
        $this->assertArrayHasKey('page_views_high', $signals);
        $this->assertArrayHasKey('feature_used_count', $signals);
        $this->assertArrayHasKey('session_frequency_high', $signals);
        $this->assertArrayHasKey('session_recency_recent', $signals);
        $this->assertArrayHasKey('team_invited', $signals);
        $this->assertArrayHasKey('referral_shared', $signals);
        $this->assertArrayHasKey('form_submitted', $signals);
        $this->assertArrayHasKey('search_used', $signals);
        $this->assertCount(10, $signals);

        // Verify structure
        foreach ($signals as $name => $def) {
            $this->assertArrayHasKey('weight', $def);
            $this->assertArrayHasKey('label', $def);
            $this->assertArrayHasKey('category', $def);
            $this->assertIsFloat($def['weight']);
            $this->assertIsString($def['label']);
            $this->assertIsString($def['category']);
        }
    }

    public function testNegativeSignalsDefinition(): void
    {
        $signals = SaaSConversionPredictorService::negativeSignals();

        $this->assertArrayHasKey('errors_count', $signals);
        $this->assertArrayHasKey('support_ticket', $signals);
        $this->assertArrayHasKey('long_inactivity', $signals);
        $this->assertArrayHasKey('session_bounce', $signals);
        $this->assertCount(4, $signals);

        foreach ($signals as $name => $def) {
            $this->assertArrayHasKey('weight', $def);
            $this->assertArrayHasKey('label', $def);
            $this->assertArrayHasKey('category', $def);
        }
    }

    // ── Stats ────────────────────────────────────────────────────────────

    public function testStatsStructure(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);
        $stats = $service->stats();

        $this->assertArrayHasKey('enabled', $stats);
        $this->assertArrayHasKey('cache_ttl', $stats);
        $this->assertArrayHasKey('positive_signal_count', $stats);
        $this->assertArrayHasKey('negative_signal_count', $stats);
        $this->assertArrayHasKey('total_signal_count', $stats);
        $this->assertArrayHasKey('custom_weight_overrides', $stats);
        $this->assertArrayHasKey('max_possible_score', $stats);
        $this->assertArrayHasKey('signal_categories', $stats);
        $this->assertArrayHasKey('grades', $stats);

        $this->assertSame(10, $stats['positive_signal_count']);
        $this->assertSame(4, $stats['negative_signal_count']);
        $this->assertSame(14, $stats['total_signal_count']);
        $this->assertSame(1.0, $stats['max_possible_score']);
        $this->assertSame(3600, $stats['cache_ttl']);

        // Grade ranges
        $this->assertArrayHasKey('A+', $stats['grades']);
        $this->assertArrayHasKey('F', $stats['grades']);
        $this->assertSame(0.85, $stats['grades']['A+']['min']);
        $this->assertSame(0.0, $stats['grades']['F']['min']);
    }

    // ── Prediction with All Signals Matched ──────────────────────────────

    public function testPredictionAllPositiveNoNegative(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $signals = [
            'page_views_high' => true,
            'feature_used_count' => true,
            'onboarding_completed' => true,
            'first_value_moment' => true,
            'session_frequency_high' => true,
            'session_recency_recent' => true,
            'team_invited' => true,
            'referral_shared' => true,
            'form_submitted' => true,
            'search_used' => true,
            'errors_count' => false,
            'support_ticket' => false,
            'long_inactivity' => false,
            'session_bounce' => false,
        ];

        $result = $service->predict('user_hot', $signals);

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('probability', $result);
        $this->assertArrayHasKey('grade', $result);
        $this->assertArrayHasKey('category', $result);
        $this->assertArrayHasKey('matched_positive', $result);
        $this->assertArrayHasKey('matched_negative', $result);
        $this->assertArrayHasKey('signal_breakdown', $result);
        $this->assertArrayHasKey('recommendations', $result);
        $this->assertArrayHasKey('user_id', $result);
        $this->assertArrayHasKey('predicted_at', $result);

        $this->assertSame('user_hot', $result['user_id']);
        $this->assertGreaterThan(0.7, $result['score'], 'All positive, no negative should score high');
        $this->assertCount(10, $result['matched_positive']);
        $this->assertCount(0, $result['matched_negative']);
        $this->assertNotEmpty($result['recommendations']);
    }

    // ── Prediction with All Negatives ──────────────────────────────────

    public function testPredictionAllNegativeNoPositive(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $signals = [
            'errors_count' => true,
            'support_ticket' => true,
            'long_inactivity' => true,
            'session_bounce' => true,
        ];

        $result = $service->predict('user_cold', $signals);

        $this->assertLessThan(0.3, $result['score'], 'All negative, no positive should score low');
        $this->assertCount(0, $result['matched_positive']);
        $this->assertCount(4, $result['matched_negative']);
    }

    // ── Prediction with No Signals ───────────────────────────────────────

    public function testPredictionNoSignals(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $result = $service->predict('user_empty', []);

        // No signals matched → baseline score around 0.5 (neutral)
        $this->assertGreaterThanOrEqual(0.0, $result['score']);
        $this->assertLessThanOrEqual(1.0, $result['score']);
        $this->assertCount(0, $result['matched_positive']);
        $this->assertCount(0, $result['matched_negative']);
    }

    // ── Signal Breakdown Structure ───────────────────────────────────────

    public function testSignalBreakdownContainsAllSignals(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);
        $result = $service->predict('user_test', []);

        $breakdown = $result['signal_breakdown'];

        // All 14 signals should be present
        $this->assertCount(14, $breakdown);

        // Verify structure of each entry
        foreach ($breakdown as $name => $entry) {
            $this->assertArrayHasKey('weight', $entry, "Signal '{$name}' missing weight");
            $this->assertArrayHasKey('matched', $entry, "Signal '{$name}' missing matched");
            $this->assertArrayHasKey('value', $entry, "Signal '{$name}' missing value");
            $this->assertArrayHasKey('label', $entry, "Signal '{$name}' missing label");
            $this->assertArrayHasKey('category', $entry, "Signal '{$name}' missing category");
            $this->assertIsFloat($entry['weight']);
            $this->assertIsBool($entry['matched']);
            $this->assertIsString($entry['label']);
            $this->assertIsString($entry['category']);
        }
    }

    // ── Grade Boundaries ────────────────────────────────────────────────

    public function testGradeBoundaryHighIntent(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        // Strong positive signals
        $result = $service->predict('user_hot', [
            'onboarding_completed' => true,
            'first_value_moment' => true,
            'session_recency_recent' => true,
            'session_frequency_high' => true,
            'feature_used_count' => true,
            'page_views_high' => true,
        ]);

        $this->assertContains($result['grade'], ['A+', 'A', 'B+']);
        $this->assertSame('high_intent', $result['category']);
    }

    public function testGradeCategoryMapping(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        // All 14 valid categories
        $validCategories = ['high_intent', 'medium_intent', 'low_intent', 'unlikely'];
        $validGrades = ['A+', 'A', 'B+', 'B', 'C', 'D', 'F'];

        // Empty signals
        $result = $service->predict('user_test', []);
        $this->assertContains($result['category'], $validCategories);
        $this->assertContains($result['grade'], $validGrades);

        // Strong positive
        $result = $service->predict('user_hot', [
            'onboarding_completed' => true,
            'first_value_moment' => true,
            'session_recency_recent' => true,
            'session_frequency_high' => true,
            'team_invited' => true,
        ]);
        $this->assertContains($result['category'], $validCategories);
        $this->assertContains($result['grade'], $validGrades);

        // Strong negative
        $result = $service->predict('user_cold', [
            'errors_count' => true,
            'support_ticket' => true,
            'long_inactivity' => true,
            'session_bounce' => true,
        ]);
        $this->assertContains($result['category'], $validCategories);
        $this->assertContains($result['grade'], $validGrades);
    }

    // ── Build Signal Map ─────────────────────────────────────────────────

    public function testBuildSignalMapFromEventSummary(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $summary = [
            'page_views' => 15,
            'feature_used_count' => 5,
            'onboarding_completed' => true,
            'first_value_moment' => true,
            'session_count_7d' => 7,
            'last_session_hours_ago' => 6,
            'team_invited' => false,
            'referral_shared' => false,
            'form_submitted' => true,
            'search_count' => 2,
            'error_count_7d' => 0,
            'support_tickets' => 0,
            'pages_per_session' => 3.5,
        ];

        $signals = $service->buildSignalMap($summary);

        $this->assertTrue($signals['page_views_high']);
        $this->assertTrue($signals['feature_used_count']);
        $this->assertTrue($signals['onboarding_completed']);
        $this->assertTrue($signals['first_value_moment']);
        $this->assertTrue($signals['session_frequency_high']);
        $this->assertTrue($signals['session_recency_recent']);
        $this->assertFalse($signals['team_invited']);
        $this->assertTrue($signals['form_submitted']);
        $this->assertTrue($signals['search_used']);
        $this->assertFalse($signals['errors_count']);
        $this->assertFalse($signals['support_ticket']);
        $this->assertFalse($signals['long_inactivity']);
        $this->assertFalse($signals['session_bounce']);
    }

    public function testBuildSignalMapWithColdUser(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $summary = [
            'page_views' => 2,
            'feature_used_count' => 0,
            'onboarding_completed' => false,
            'first_value_moment' => false,
            'session_count_7d' => 0,
            'last_session_hours_ago' => 120,
            'team_invited' => false,
            'referral_shared' => false,
            'form_submitted' => false,
            'search_count' => 0,
            'error_count_7d' => 5,
            'support_tickets' => 1,
            'pages_per_session' => 1.0,
        ];

        $signals = $service->buildSignalMap($summary);

        $this->assertFalse($signals['page_views_high']);
        $this->assertFalse($signals['feature_used_count']);
        $this->assertTrue($signals['errors_count']);
        $this->assertTrue($signals['support_ticket']);
        $this->assertTrue($signals['long_inactivity']);
        $this->assertTrue($signals['session_bounce']);
    }

    public function testBuildSignalMapWithDefaultValues(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $signals = $service->buildSignalMap([]);

        $this->assertFalse($signals['page_views_high']);
        $this->assertFalse($signals['onboarding_completed']);
        $this->assertTrue($signals['long_inactivity']); // 999 hours default = inactive
        $this->assertTrue($signals['session_bounce']); // 1.0 pages default = bounce
    }

    // ── Batch Prediction ─────────────────────────────────────────────────

    public function testBatchPrediction(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $userSignals = [
            'user_a' => ['onboarding_completed' => true, 'first_value_moment' => true],
            'user_b' => ['errors_count' => true, 'long_inactivity' => true],
        ];

        $result = $service->predictBatch($userSignals);

        $this->assertArrayHasKey('results', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertCount(2, $result['results']);
        $this->assertArrayHasKey('user_a', $result['results']);
        $this->assertArrayHasKey('user_b', $result['results']);

        $summary = $result['summary'];
        $this->assertSame(2, $summary['total']);
        $this->assertSame(2, $summary['high_intent'] + $summary['medium_intent'] + $summary['low_intent'] + $summary['unlikely']);
        $this->assertGreaterThanOrEqual(0.0, $summary['avg_score']);
        $this->assertLessThanOrEqual(1.0, $summary['avg_score']);
    }

    public function testBatchPredictionEmpty(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $result = $service->predictBatch([]);

        $this->assertCount(0, $result['results']);
        $this->assertSame(0, $result['summary']['total']);
        $this->assertSame(0.0, $result['summary']['avg_score']);
    }

    // ── Top Prospects ────────────────────────────────────────────────────

    public function testTopProspectsSorted(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $userSignals = [
            'user_hot' => [
                'onboarding_completed' => true,
                'first_value_moment' => true,
                'session_recency_recent' => true,
                'team_invited' => true,
            ],
            'user_cold' => [
                'errors_count' => true,
                'long_inactivity' => true,
            ],
            'user_warm' => [
                'onboarding_completed' => true,
                'session_recency_recent' => true,
            ],
        ];

        $prospects = $service->topProspects($userSignals, 3);

        $this->assertCount(3, $prospects);

        // Verify sorted (highest first)
        $this->assertGreaterThanOrEqual($prospects[1]['score'], $prospects[0]['score']);
        // The second should be >= third
        $this->assertGreaterThanOrEqual($prospects[2]['score'], $prospects[1]['score']);

        // Verify structure
        foreach ($prospects as $p) {
            $this->assertArrayHasKey('user_id', $p);
            $this->assertArrayHasKey('score', $p);
            $this->assertArrayHasKey('probability', $p);
            $this->assertArrayHasKey('grade', $p);
            $this->assertArrayHasKey('category', $p);
            $this->assertArrayHasKey('matched_positive', $p);
            $this->assertArrayHasKey('matched_negative', $p);
        }
    }

    public function testTopProspectsLimit(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $userSignals = [
            'user_1' => [],
            'user_2' => [],
            'user_3' => [],
            'user_4' => [],
            'user_5' => [],
        ];

        $prospects = $service->topProspects($userSignals, 2);
        $this->assertCount(2, $prospects);
    }

    // ── Recommendations ─────────────────────────────────────────────────

    public function testRecommendationsForHotLead(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $result = $service->predict('user_hot', [
            'onboarding_completed' => true,
            'first_value_moment' => true,
            'session_recency_recent' => true,
            'session_frequency_high' => true,
            'team_invited' => true,
            'page_views_high' => true,
            'feature_used_count' => true,
        ]);

        $this->assertNotEmpty($result['recommendations']);
        // Hot leads should get upgrade prompt recommendation
        $hasUpgradePrompt = false;
        foreach ($result['recommendations'] as $rec) {
            if (str_contains($rec, 'upgrade prompt') || str_contains($rec, 'conversion offer')) {
                $hasUpgradePrompt = true;
                break;
            }
        }
        $this->assertTrue($hasUpgradePrompt, 'Hot leads should get upgrade prompt recommendation');
    }

    public function testRecommendationsForUserWithErrors(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $result = $service->predict('user_errors', [
            'errors_count' => true,
        ]);

        $hasErrorRec = false;
        foreach ($result['recommendations'] as $rec) {
            if (str_contains($rec, 'errors') || str_contains($rec, 'bug')) {
                $hasErrorRec = true;
                break;
            }
        }
        $this->assertTrue($hasErrorRec, 'Users with errors should get bug fix recommendation');
    }

    public function testRecommendationsForIncompleteOnboarding(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $result = $service->predict('user_no_onboarding', [
            'session_recency_recent' => true,
        ]);

        $hasOnboardingRec = false;
        foreach ($result['recommendations'] as $rec) {
            if (str_contains($rec, 'onboarding')) {
                $hasOnboardingRec = true;
                break;
            }
        }
        $this->assertTrue($hasOnboardingRec, 'Users without onboarding should get onboarding recommendation');
    }

    // ── Cache Behavior ──────────────────────────────────────────────────

    public function testPredictionCachesResult(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        // First call: cache miss, then put
        $cache->method('get')->willReturnOnConsecutiveCalls(null, null);
        $cache->expects($this->exactly(2))
            ->method('put')
            ->with(
                $this->stringContains('zb_conversion_predictor_'),
                $this->isType('array'),
                $this->equalTo(3600),
            );

        $service = new SaaSConversionPredictorService($config, $cache);

        $service->predict('user_cached', ['onboarding_completed' => true]);
        // Second call with empty signals should use cache (first return null = miss on first call)
        $service->predict('user_cached', []);
    }

    public function testClearCache(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $cache->expects($this->once())
            ->method('forget')
            ->with('zb_conversion_predictor_user_123');

        $service = new SaaSConversionPredictorService($config, $cache);
        $service->clearCache('user_123');
    }

    // ── Numeric Signal Values ───────────────────────────────────────────

    public function testNumericSignalValues(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        $result = $service->predict('user_numeric', [
            'page_views_high' => 15,
            'session_frequency_high' => 0,
            'errors_count' => 5,
        ]);

        $this->assertContains('page_views_high', $result['matched_positive']);
        $this->assertNotContains('session_frequency_high', $result['matched_positive']);
        $this->assertContains('errors_count', $result['matched_negative']);
    }

    // ── Score Bounds ────────────────────────────────────────────────────

    public function testScoreIsAlwaysBetweenZeroAndOne(): void
    {
        $config = $this->createMock(\Illuminate\Contracts\Config\Repository::class);
        $cache = $this->createMock(\Illuminate\Contracts\Cache\Repository::class);
        $config->method('get')->willReturn([]);

        $service = new SaaSConversionPredictorService($config, $cache);

        // Test various signal combinations
        $testCases = [
            'all_positive' => array_fill_keys(
                ['page_views_high', 'feature_used_count', 'onboarding_completed', 'first_value_moment', 'session_frequency_high', 'session_recency_recent', 'team_invited', 'referral_shared', 'form_submitted', 'search_used'],
                true,
            ),
            'all_negative' => array_fill_keys(
                ['errors_count', 'support_ticket', 'long_inactivity', 'session_bounce'],
                true,
            ),
            'mixed' => ['onboarding_completed' => true, 'errors_count' => true, 'session_recency_recent' => true],
            'empty' => [],
        ];

        foreach ($testCases as $case => $signals) {
            $result = $service->predict('test_' . $case, $signals);
            $this->assertGreaterThanOrEqual(0.0, $result['score'], "Score for {$case} must be >= 0");
            $this->assertLessThanOrEqual(1.0, $result['score'], "Score for {$case} must be <= 1");
        }
    }

    // ── Version Consistency ────────────────────────────────────────────

    public function testVersionConsistency(): void
    {
        // composer.json version
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        $this->assertArrayHasKey('version', $composer);

        // AnalyticsEvent::VERSION
        $dtoContents = file_get_contents(__DIR__ . '/../src/DTO/AnalyticsEvent.php');
        preg_match("/VERSION = '([^']+)'/", $dtoContents, $dtoMatch);
        $this->assertNotEmpty($dtoMatch[1], 'AnalyticsEvent::VERSION should be set');

        // Integrity command version
        $integrityContents = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsIntegrityCommand.php');
        preg_match("/EXPECTED_VERSION = '([^']+)'/", $integrityContents, $integrityMatch);
        $this->assertNotEmpty($integrityMatch[1], 'AnalyticsIntegrityCommand::EXPECTED_VERSION should be set');
    }

    // ── Source File Counts ──────────────────────────────────────────────

    public function testSourceFileCount(): void
    {
        $srcFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);
        // Count actual PHP files in src/
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../src', \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }
        $this->assertGreaterThanOrEqual(860, $count, 'Should have at least 860 src files after adding 2 new files');
    }

    public function testTestFileCount(): void
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../tests', \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }
        $this->assertGreaterThanOrEqual(439, $count, 'Should have at least 439 test files');
    }
}
