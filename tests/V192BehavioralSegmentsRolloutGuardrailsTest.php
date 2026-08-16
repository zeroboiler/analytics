<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\BehavioralUserSegmentService;
use ZeroBoiler\Analytics\Services\FeatureFlagRolloutGuardrailService;

/**
 * Tests for Behavioral User Segmentation + Feature Flag Rollout Guardrails — v192.0.0.
 *
 * @covers \ZeroBoiler\Analytics\Services\BehavioralUserSegmentService
 * @covers \ZeroBoiler\Analytics\Services\FeatureFlagRolloutGuardrailService
 */
final class V192BehavioralSegmentsRolloutGuardrailsTest extends \PHPUnit\Framework\TestCase
{
    // ── Behavioral User Segment Service ───────────────────────────────

    public function test_segment_service_file_quality(): void
    {
        $path = __DIR__ . '/../src/Services/BehavioralUserSegmentService.php';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
        $this->assertStringContainsString('final class BehavioralUserSegmentService', $contents);
        $this->assertStringContainsString('MIT license', $contents);
        $this->assertStringContainsString('@since 192.0.0', $contents);
    }

    public function test_segment_service_construction_and_defaults(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);

        $this->assertTrue($service->isEnabled());
        $this->assertNotEmpty($service->segmentNames());
        $this->assertGreaterThanOrEqual(10, count($service->segmentNames()));
    }

    public function test_segment_service_built_in_segments(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);

        $names = $service->segmentNames();
        $this->assertContains('power_users', $names);
        $this->assertContains('new_users', $names);
        $this->assertContains('trial_users', $names);
        $this->assertContains('converted_users', $names);
        $this->assertContains('at_risk_users', $names);
        $this->assertContains('churned_users', $names);
        $this->assertContains('feature_adapters', $names);
        $this->assertContains('searchers', $names);
        $this->assertContains('ecommerce_browsers', $names);
        $this->assertContains('buyers', $names);
    }

    public function test_segment_service_stats(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);
        $stats = $service->stats();

        $this->assertTrue($stats['enabled']);
        $this->assertGreaterThanOrEqual(10, $stats['segments_defined']);
        $this->assertGreaterThanOrEqual(10, $stats['built_in_segments']);
        $this->assertIsInt($stats['cache_ttl']);
        $this->assertIsInt($stats['max_segment_size']);
        $this->assertIsArray($stats['segment_types']);
    }

    public function test_segment_service_summary(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);
        $summary = $service->summary();

        $this->assertArrayHasKey('power_users', $summary);
        $this->assertEquals('frequency', $summary['power_users']['type']);
        $this->assertNotEmpty($summary['power_users']['description']);
    }

    public function test_segment_service_define_and_undefine(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $cache->method('forget')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);

        $this->assertFalse($service->hasDefinition('custom_segment'));

        $service->define('custom_segment', 'event', ['must_have' => ['my_event']], 'My custom segment');
        $this->assertTrue($service->hasDefinition('custom_segment'));

        $def = $service->getDefinition('custom_segment');
        $this->assertNotNull($def);
        $this->assertEquals('event', $def['type']);
        $this->assertEquals(['must_have' => ['my_event']], $def['conditions']);
        $this->assertEquals('My custom segment', $def['description']);
        $this->assertNotNull($def['created_at']);

        $service->undefine('custom_segment');
        $this->assertFalse($service->hasDefinition('custom_segment'));
        $this->assertNull($service->getDefinition('custom_segment'));
    }

    public function test_evaluate_event_based_segment(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);
        $service->define('signup_only', 'event', ['must_have' => ['sign_up']]);

        $userEvents = [
            'user_1' => [['event' => 'page_view'], ['event' => 'sign_up']],
            'user_2' => [['event' => 'page_view'], ['event' => 'click']],
        ];

        $result = $service->evaluate('signup_only', $userEvents);

        $this->assertEquals('signup_only', $result['segment']);
        $this->assertEquals(1, $result['size']);
        $this->assertContains('user_1', $result['members']);
        $this->assertNotContains('user_2', $result['members']);
        $this->assertNotEmpty($result['evaluated_at']);
    }

    public function test_evaluate_must_not_have_segment(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);
        $service->define('browsers_no_purchase', 'event', [
            'must_have' => ['view_item'],
            'must_not_have' => ['purchase'],
        ]);

        $userEvents = [
            'user_1' => [['event' => 'view_item']],
            'user_2' => [['event' => 'view_item'], ['event' => 'purchase']],
        ];

        $result = $service->evaluate('browsers_no_purchase', $userEvents);

        $this->assertEquals(1, $result['size']);
        $this->assertContains('user_1', $result['members']);
    }

    public function test_evaluate_frequency_based_segment(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);
        $service->define('frequent_searchers', 'frequency', ['min_count' => ['search' => 3]]);

        $userEvents = [
            'user_1' => [['event' => 'search'], ['event' => 'search'], ['event' => 'search'], ['event' => 'search']],
            'user_2' => [['event' => 'search'], ['event' => 'search']],
        ];

        $result = $service->evaluate('frequent_searchers', $userEvents);

        $this->assertEquals(1, $result['size']);
        $this->assertContains('user_1', $result['members']);
    }

    public function test_evaluate_sequence_based_segment_strict(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);
        $service->define('strict_signup_trial', 'sequence', [
            'sequence' => ['sign_up', 'start_trial'],
            'strict' => true,
        ]);

        $userEvents = [
            'user_1' => [['event' => 'sign_up'], ['event' => 'start_trial']], // matches strict
            'user_2' => [['event' => 'sign_up'], ['event' => 'page_view'], ['event' => 'start_trial']], // has gap
        ];

        $result = $service->evaluate('strict_signup_trial', $userEvents);

        $this->assertEquals(1, $result['size']);
        $this->assertContains('user_1', $result['members']);
        $this->assertNotContains('user_2', $result['members']);
    }

    public function test_evaluate_sequence_based_segment_non_strict(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);
        $service->define('flex_signup_trial', 'sequence', [
            'sequence' => ['sign_up', 'start_trial'],
            'strict' => false,
        ]);

        $userEvents = [
            'user_1' => [['event' => 'sign_up'], ['event' => 'page_view'], ['event' => 'start_trial']],
        ];

        $result = $service->evaluate('flex_signup_trial', $userEvents);

        $this->assertEquals(1, $result['size']);
        $this->assertContains('user_1', $result['members']);
    }

    public function test_set_operations_intersect(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);
        $service->define('seg_a', 'event', ['must_have' => ['sign_up']]);
        $service->define('seg_b', 'event', ['must_have' => ['purchase']]);

        $userEvents = [
            'user_1' => [['event' => 'sign_up'], ['event' => 'purchase']],
            'user_2' => [['event' => 'sign_up']],
            'user_3' => [['event' => 'purchase']],
        ];

        $service->evaluate('seg_a', $userEvents);
        $service->evaluate('seg_b', $userEvents);

        $intersection = $service->intersect('seg_a', 'seg_b');
        $this->assertEquals(['user_1'], $intersection);
    }

    public function test_set_operations_union(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);
        $service->define('seg_a', 'event', ['must_have' => ['sign_up']]);
        $service->define('seg_b', 'event', ['must_have' => ['purchase']]);

        $userEvents = [
            'user_1' => [['event' => 'sign_up']],
            'user_2' => [['event' => 'purchase']],
        ];

        $service->evaluate('seg_a', $userEvents);
        $service->evaluate('seg_b', $userEvents);

        $union = $service->union('seg_a', 'seg_b');
        $this->assertContains('user_1', $union);
        $this->assertContains('user_2', $union);
    }

    public function test_set_operations_except(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);
        $service->define('seg_a', 'event', ['must_have' => ['sign_up']]);
        $service->define('seg_b', 'event', ['must_have' => ['purchase']]);

        $userEvents = [
            'user_1' => [['event' => 'sign_up'], ['event' => 'purchase']],
            'user_2' => [['event' => 'sign_up']],
        ];

        $service->evaluate('seg_a', $userEvents);
        $service->evaluate('seg_b', $userEvents);

        $except = $service->except('seg_a', 'seg_b');
        $this->assertContains('user_2', $except);
        $this->assertNotContains('user_1', $except);
    }

    public function test_trending_segments(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);

        $current = ['power_users' => 150, 'new_users' => 50, 'churned' => 30];
        $previous = ['power_users' => 100, 'new_users' => 60, 'churned' => 10];

        $trending = $service->trending($current, $previous, 10.0);

        $this->assertNotEmpty($trending);
        // Power users grew 50% (trending up)
        $powerUsers = array_filter($trending, fn (array $t): bool => $t['segment'] === 'power_users');
        $this->assertNotEmpty($powerUsers);
        $first = reset($powerUsers);
        $this->assertEquals('up', $first['direction']);
        $this->assertEquals(50.0, $first['change_percent']);
    }

    public function test_segment_comparison(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);

        $current = ['user_1', 'user_2', 'user_3'];
        $previous = ['user_1', 'user_4', 'user_5'];

        $comparison = $service->compare('test_seg', $current, $previous);

        $this->assertEquals('test_seg', $comparison['segment']);
        $this->assertEquals(3, $comparison['current_size']);
        $this->assertEquals(3, $comparison['previous_size']);
        $this->assertContains('user_1', $comparison['retained']);
        $this->assertContains('user_2', $comparison['added']);
        $this->assertContains('user_4', $comparison['removed']);
        $this->assertEqualsWithDelta(33.33, $comparison['retention_rate'], 0.5);
    }

    public function test_snapshot(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);

        $members = ['user_1', 'user_2', 'user_3'];
        $snapshot = $service->snapshot('power_users', $members, 'weekly_snapshot');

        $this->assertEquals('power_users', $snapshot['segment']);
        $this->assertEquals('weekly_snapshot', $snapshot['label']);
        $this->assertEquals(3, $snapshot['size']);
        $this->assertNotEmpty($snapshot['snapshot_id']);
        $this->assertNotEmpty($snapshot['created_at']);
    }

    public function test_evaluate_multiple_segments(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);

        $userEvents = [
            'user_1' => [['event' => 'sign_up']],
            'user_2' => [['event' => 'page_view']],
        ];

        $results = $service->evaluateMultiple(['new_users', 'buyers'], $userEvents);

        $this->assertArrayHasKey('new_users', $results);
        $this->assertArrayHasKey('buyers', $results);
        $this->assertEquals(1, $results['new_users']['size']);
        $this->assertEquals(0, $results['buyers']['size']);
    }

    public function test_unknown_segment_returns_empty(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);
        $result = $service->evaluate('nonexistent', []);

        $this->assertEquals(0, $result['size']);
        $this->assertEquals('nonexistent', $result['segment']);
    }

    public function test_composite_segment_and(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new BehavioralUserSegmentService($cache, $config);
        $service->define('signed_up_and_searched', 'composite', [
            'operator' => 'AND',
            'segments' => [
                ['type' => 'event', 'conditions' => ['must_have' => ['sign_up']]],
                ['type' => 'event', 'conditions' => ['must_have' => ['search']]],
            ],
        ]);

        $userEvents = [
            'user_1' => [['event' => 'sign_up'], ['event' => 'search']],
            'user_2' => [['event' => 'sign_up']],
        ];

        $result = $service->evaluate('signed_up_and_searched', $userEvents);

        $this->assertEquals(1, $result['size']);
        $this->assertContains('user_1', $result['members']);
    }

    // ── Feature Flag Rollout Guardrail Service ───────────────────────

    public function test_guardrail_service_file_quality(): void
    {
        $path = __DIR__ . '/../src/Services/FeatureFlagRolloutGuardrailService.php';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
        $this->assertStringContainsString('final class FeatureFlagRolloutGuardrailService', $contents);
        $this->assertStringContainsString('MIT license', $contents);
        $this->assertStringContainsString('@since 192.0.0', $contents);
    }

    public function test_guardrail_service_construction_and_defaults(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new FeatureFlagRolloutGuardrailService($cache, $config);

        $this->assertTrue($service->isEnabled());
        $this->assertNotEmpty($service->supportedMetrics());
        $this->assertNotEmpty($service->rolloutPhases());
    }

    public function test_guardrail_service_stats(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new FeatureFlagRolloutGuardrailService($cache, $config);
        $stats = $service->stats();

        $this->assertTrue($stats['enabled']);
        $this->assertIsInt($stats['cache_ttl']);
        $this->assertIsInt($stats['min_sample_size']);
        $this->assertIsFloat($stats['significance_alpha']);
        $this->assertTrue($stats['auto_rollback']);
        $this->assertIsArray($stats['metric_categories']);
        $this->assertContains('conversion', $stats['metric_categories']);
        $this->assertContains('revenue', $stats['metric_categories']);
        $this->assertContains('engagement', $stats['metric_categories']);
        $this->assertContains('performance', $stats['metric_categories']);
        $this->assertContains('retention', $stats['metric_categories']);
        $this->assertCount(10, $stats['supported_metrics']);
        $this->assertCount(4, $stats['phase_sensitivity']);
    }

    public function test_guardrail_supported_metrics(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new FeatureFlagRolloutGuardrailService($cache, $config);
        $metrics = $service->supportedMetrics();

        $this->assertContains('conversion_rate', $metrics);
        $this->assertContains('error_rate', $metrics);
        $this->assertContains('revenue_per_user', $metrics);
        $this->assertContains('page_load_time', $metrics);
    }

    public function test_guardrail_rollout_phases(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new FeatureFlagRolloutGuardrailService($cache, $config);
        $phases = $service->rolloutPhases();

        $this->assertEquals(['canary', 'early', 'broad', 'full'], $phases);
    }

    public function test_guardrail_evaluate_safe_rollout(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new FeatureFlagRolloutGuardrailService($cache, $config);

        $metricData = [
            'conversion_rate' => [
                'baseline' => 5.0,
                'current' => 5.1,
                'sample_size' => 1000,
                'baseline_size' => 1000,
            ],
            'error_rate' => [
                'baseline' => 1.0,
                'current' => 0.9,
                'sample_size' => 500,
                'baseline_size' => 500,
            ],
        ];

        $result = $service->evaluate('new_feature', 10.0, $metricData);

        $this->assertEquals('new_feature', $result['flag']);
        $this->assertEquals(10.0, $result['rollout_percentage']);
        $this->assertEquals('early', $result['phase']);
        $this->assertEquals('safe', $result['verdict']);
        $this->assertStringContainsString('PROCEED', $result['recommendation']);
        $this->assertCount(2, $result['guardrails']);
        $this->assertNotEmpty($result['evaluated_at']);
    }

    public function test_guardrail_evaluate_critical_regression(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new FeatureFlagRolloutGuardrailService($cache, $config);

        $metricData = [
            'conversion_rate' => [
                'baseline' => 5.0,
                'current' => 3.5, // 30% drop — exceeds critical threshold
                'sample_size' => 500,
                'baseline_size' => 500,
            ],
        ];

        $result = $service->evaluate('risky_feature', 5.0, $metricData);

        $this->assertEquals('canary', $result['phase']);
        $this->assertEquals('breached', $result['verdict']);
        $this->assertStringContainsString('CRITICAL', $result['recommendation']);
        $this->assertStringContainsString('Roll back', $result['recommendation']);

        $guardrail = $result['guardrails']['conversion_rate'];
        $this->assertEquals('critical', $guardrail['severity']);
        $this->assertEqualsWithDelta(-30.0, $guardrail['change_percent'], 0.1);
    }

    public function test_guardrail_evaluate_warning_state(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new FeatureFlagRolloutGuardrailService($cache, $config);

        $metricData = [
            'conversion_rate' => [
                'baseline' => 5.0,
                'current' => 4.6, // -8% drop — warning range
                'sample_size' => 500,
                'baseline_size' => 500,
            ],
        ];

        $result = $service->evaluate('caution_feature', 50.0, $metricData);

        $this->assertEquals('broad', $result['phase']);
        $this->assertEquals('warning', $result['verdict']);
        $this->assertStringContainsString('CAUTION', $result['recommendation']);
    }

    public function test_guardrail_error_rate_positive_threshold(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new FeatureFlagRolloutGuardrailService($cache, $config);

        // Error rate increasing (positive change = bad)
        $metricData = [
            'error_rate' => [
                'baseline' => 1.0,
                'current' => 1.5, // +50% increase
                'sample_size' => 500,
                'baseline_size' => 500,
            ],
        ];

        $result = $service->evaluate('buggy_feature', 10.0, $metricData);

        $guardrail = $result['guardrails']['error_rate'];
        $this->assertEquals('critical', $guardrail['severity']);
    }

    public function test_guardrail_phase_sensitivity(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new FeatureFlagRolloutGuardrailService($cache, $config);

        $metricData = [
            'conversion_rate' => [
                'baseline' => 5.0,
                'current' => 4.55, // -9% drop
                'sample_size' => 500,
                'baseline_size' => 500,
            ],
        ];

        // Canary phase: more sensitive → should be critical
        $canaryResult = $service->evaluate('test_flag', 2.0, $metricData);
        $this->assertEquals('canary', $canaryResult['phase']);
        $this->assertEquals('critical', $canaryResult['guardrails']['conversion_rate']['severity']);

        // Full rollout: less sensitive → should be warning
        $fullResult = $service->evaluate('test_flag', 90.0, $metricData);
        $this->assertEquals('full', $fullResult['phase']);
        $this->assertEquals('warning', $fullResult['guardrails']['conversion_rate']['severity']);
    }

    public function test_guardrail_baseline_capture_and_get(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $cache->method('get')->willReturnCallback(function (string $key) {
            if (str_contains($key, 'conversion_rate')) {
                return ['value' => 5.0, 'sample_size' => 1000, 'captured_at' => '2026-08-16T00:00:00+00:00'];
            }

            return null;
        });
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new FeatureFlagRolloutGuardrailService($cache, $config);

        $service->captureBaseline('new_feature', ['conversion_rate' => 5.0, 'error_rate' => 1.0], 1000);

        $baseline = $service->getBaseline('new_feature', 'conversion_rate');
        $this->assertNotNull($baseline);
        $this->assertEqualsWithDelta(5.0, $baseline['value'], 0.01);
        $this->assertEquals(1000, $baseline['sample_size']);
    }

    public function test_guardrail_rollout_velocity_safe(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new FeatureFlagRolloutGuardrailService($cache, $config);

        $result = $service->checkRolloutVelocity('test_flag', 15.0, 10.0);

        $this->assertTrue($result['safe']);
        $this->assertEqualsWithDelta(5.0, $result['jump'], 0.01);
        $this->assertNull($result['recommendation']);
    }

    public function test_guardrail_rollout_velocity_too_fast(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new FeatureFlagRolloutGuardrailService($cache, $config);

        // Jump from 5% to 50% — way too fast for early phase
        $result = $service->checkRolloutVelocity('test_flag', 50.0, 5.0);

        $this->assertFalse($result['safe']);
        $this->assertEqualsWithDelta(45.0, $result['jump'], 0.01);
        $this->assertNotNull($result['recommendation']);
        $this->assertStringContainsString('too fast', strtolower($result['recommendation']));
    }

    public function test_guardrail_health_check_healthy(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('get')->willReturnCallback(function (string $key) {
            return str_contains($key, 'baseline') ? ['value' => 5.0, 'sample_size' => 500, 'captured_at' => ''] : null;
        });
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new FeatureFlagRolloutGuardrailService($cache, $config);

        $result = $service->healthCheck('stable_flag', 50.0, ['conversion_rate' => 5.0]);

        $this->assertEquals('healthy', $result['status']);
        $this->assertEquals(1, $result['checked_metrics']);
        $this->assertEmpty($result['issues']);
    }

    public function test_guardrail_audit_log(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new FeatureFlagRolloutGuardrailService($cache, $config);

        $metricData = [
            'conversion_rate' => [
                'baseline' => 5.0,
                'current' => 5.0,
                'sample_size' => 500,
                'baseline_size' => 500,
            ],
        ];

        $service->evaluate('flag_a', 10.0, $metricData);
        $service->evaluate('flag_b', 50.0, $metricData);

        $log = $service->getAuditLog();
        $this->assertCount(2, $log);
        $this->assertEquals('flag_a', $log[0]['flag']);
        $this->assertEquals('flag_b', $log[1]['flag']);
    }

    public function test_guardrail_flag_history(): void
    {
        $cache = $this->createMock(CacheRepository::class);
        $cache->method('put')->willReturn(true);
        $config = $this->createMock(ConfigRepository::class);
        $config->method('get')->willReturn([]);

        $service = new FeatureFlagRolloutGuardrailService($cache, $config);

        $metricData = [
            'conversion_rate' => [
                'baseline' => 5.0,
                'current' => 5.0,
                'sample_size' => 500,
                'baseline_size' => 500,
            ],
        ];

        $service->evaluate('my_flag', 5.0, $metricData);
        $service->evaluate('other_flag', 50.0, $metricData);
        $service->evaluate('my_flag', 25.0, $metricData);

        $history = $service->getFlagHistory('my_flag');
        $this->assertCount(2, $history);
        $this->assertEquals(5.0, $history[0]['percentage']);
        $this->assertEquals(25.0, $history[1]['percentage']);
    }

    // ── Version Consistency & Cross-Cutting ───────────────────────────

    public function test_version_consistency(): void
    {
        $composerJson = json_decode(
            file_get_contents(__DIR__ . '/../composer.json'),
            true,
        );

        $dtoVersion = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION;

        $this->assertSame(
            $composerJson['version'] ?? $composerJson['version'],
            $dtoVersion,
            'Composer version and AnalyticsEvent::VERSION must match',
        );
    }

    public function test_src_file_count_minimum(): void
    {
        $srcFiles = glob(__DIR__ . '/../src/**/*.php', GLOB_BRACE);
        if ($srcFiles === false) {
            $srcFiles = [];
        }

        // Count PHP files recursively
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../src', \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }

        $this->assertGreaterThanOrEqual(857, $count, "Source file count must be ≥ 857 (got {$count})");
    }

    public function test_test_file_count_minimum(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__, \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }

        $this->assertGreaterThanOrEqual(437, $count, "Test file count must be ≥ 437 (got {$count})");
    }

    public function test_service_count_minimum(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../src/Services', \RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }

        $this->assertGreaterThanOrEqual(394, $count, "Service count must be ≥ 394 (got {$count})");
    }
}
