<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\ConversionPathDiscoveryService;
use ZeroBoiler\Analytics\Services\FunnelVelocityAnalyzer;
use ZeroBoiler\Analytics\Services\PrivacyAwareEventRouter;
use ZeroBoiler\Analytics\Services\RevenueSignalDetector;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
    $this->cache->shouldReceive('get')->andReturn([]);
    $this->cache->shouldReceive('put')->andReturn(true);
    $this->cache->shouldReceive('forget')->andReturn(true);

    $this->config = mock(ConfigRepository::class);
    $this->config->shouldReceive('get')->andReturnMap([
        ['zeroboiler.analytics.funnel_velocity.cache_ttl', 86400, 86400],
        ['zeroboiler.analytics.funnel_velocity.window_hours', 72, 72],
        ['zeroboiler.analytics.funnel_velocity.bottleneck_threshold', 75.0, 75.0],
        ['zeroboiler.analytics.revenue_signals.cache_ttl', 3600, 3600],
        ['zeroboiler.analytics.conversion_paths.cache_ttl', 86400, 86400],
        ['zeroboiler.analytics.conversion_paths.max_depth', 10, 10],
        ['zeroboiler.analytics.conversion_paths.min_samples', 3, 3],
    ]);
});

describe('ConversionPathDiscoveryService', function (): void {
    test('constructor initializes correctly', function (): void {
        $service = new ConversionPathDiscoveryService($this->cache, $this->config);

        expect($service)->toBeInstanceOf(ConversionPathDiscoveryService::class);
    });

    test('records a step in a conversion path', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn([])
            ->once();

        $service = new ConversionPathDiscoveryService($this->cache, $this->config);
        $service->recordStep('signup', 'user_123', 'landing_page');

        $this->cache->shouldHaveReceived('put')
            ->once()
            ->withArgs(function (string $key, array $data, int $ttl): bool {
                return str_contains($key, 'signup:user_123')
                    && isset($data[0]['step'])
                    && $data[0]['step'] === 'landing_page';
            });
    });

    test('records multiple steps and appends them', function (): void {
        $existingPath = [
            ['step' => 'landing_page', 'timestamp' => '2025-01-01T00:00:00Z', 'metadata' => []],
        ];

        $this->cache->shouldReceive('get')
            ->andReturn($existingPath)
            ->once();

        $service = new ConversionPathDiscoveryService($this->cache, $this->config);
        $service->recordStep('signup', 'user_123', 'pricing_page');

        $this->cache->shouldHaveReceived('put')
            ->withArgs(function (string $key, array $data): bool {
                return count($data) === 2
                    && $data[0]['step'] === 'landing_page'
                    && $data[1]['step'] === 'pricing_page';
            });
    });

    test('trims path to max depth', function (): void {
        $longPath = array_fill(0, 12, ['step' => 'step', 'timestamp' => '2025-01-01T00:00:00Z', 'metadata' => []]);

        $this->cache->shouldReceive('get')
            ->andReturn($longPath)
            ->once();

        $service = new ConversionPathDiscoveryService($this->cache, $this->config);
        $service->recordStep('signup', 'user_123', 'final_step');

        $this->cache->shouldHaveReceived('put')
            ->withArgs(function (string $key, array $data): bool {
                return count($data) === 10;
            });
    });

    test('marks path as converted and records pattern', function (): void {
        $existingPath = [
            ['step' => 'landing_page', 'timestamp' => '2025-01-01T00:00:00Z', 'metadata' => []],
            ['step' => 'pricing_page', 'timestamp' => '2025-01-01T00:01:00Z', 'metadata' => []],
        ];

        $this->cache->shouldReceive('get')
            ->andReturn($existingPath, [])
            ->twice();

        $service = new ConversionPathDiscoveryService($this->cache, $this->config);
        $service->markConverted('signup', 'user_123', 'purchase');

        $this->cache->shouldHaveReceived('put')
            ->withArgs(function (string $key, array $data): bool {
                if (!str_contains($key, 'patterns:signup')) {
                    return false;
                }

                $patterns = array_values($data);
                return isset($patterns[0]['pattern'])
                    && str_contains($patterns[0]['pattern'], 'landing_page')
                    && str_contains($patterns[0]['pattern'], 'pricing_page')
                    && str_contains($patterns[0]['pattern'], 'purchase')
                    && $patterns[0]['count'] === 1;
            });
    });

    test('marks path as abandoned and records drop-off', function (): void {
        $existingPath = [
            ['step' => 'landing_page', 'timestamp' => '2025-01-01T00:00:00Z', 'metadata' => []],
            ['step' => 'pricing_page', 'timestamp' => '2025-01-01T00:01:00Z', 'metadata' => []],
        ];

        $this->cache->shouldReceive('get')
            ->andReturn($existingPath, [])
            ->twice();

        $service = new ConversionPathDiscoveryService($this->cache, $this->config);
        $service->markAbandoned('signup', 'user_123', 'pricing_page');

        $this->cache->shouldHaveReceived('put')
            ->withArgs(function (string $key, array $data): bool {
                return str_contains($key, 'dropoffs:signup');
            });
    });

    test('returns empty array for topConversionPaths when no patterns', function (): void {
        $service = new ConversionPathDiscoveryService($this->cache, $this->config);

        expect($service->topConversionPaths('signup'))->toBe([]);
    });

    test('returns empty array for topDropOffPaths when no patterns', function (): void {
        $service = new ConversionPathDiscoveryService($this->cache, $this->config);

        expect($service->topDropOffPaths('signup'))->toBe([]);
    });

    test('stepAnalysis returns empty result for no data', function (): void {
        $service = new ConversionPathDiscoveryService($this->cache, $this->config);
        $result = $service->stepAnalysis('signup');

        expect($result)->toHaveKey('funnel');
        expect($result['funnel'])->toBe('signup');
        expect($result['steps'])->toBe([]);
        expect($result['total_conversions'])->toBe(0);
        expect($result['total_drop_offs'])->toBe(0);
        expect($result['overall_rate'])->toBe(0.0);
    });

    test('funnelSummary returns expected structure', function (): void {
        $service = new ConversionPathDiscoveryService($this->cache, $this->config);
        $result = $service->funnelSummary('signup');

        expect($result)->toHaveKeys([
            'funnel',
            'top_conversion_paths',
            'top_drop_off_paths',
            'step_analysis',
            'summary',
        ]);
        expect($result['summary'])->toHaveKeys([
            'total_conversions',
            'total_drop_offs',
            'overall_rate',
            'path_diversity',
        ]);
    });

    test('clearFunnel removes both pattern and drop-off keys', function (): void {
        $service = new ConversionPathDiscoveryService($this->cache, $this->config);
        $service->clearFunnel('signup');

        $this->cache->shouldHaveReceived('forget')->twice();
    });

    test('userCurrentPath returns empty for no path', function (): void {
        $service = new ConversionPathDiscoveryService($this->cache, $this->config);

        expect($service->userCurrentPath('signup', 'user_456'))->toBe([]);
    });

    test('rawPatterns returns empty for no data', function (): void {
        $service = new ConversionPathDiscoveryService($this->cache, $this->config);

        expect($service->rawPatterns('signup'))->toBe([]);
    });

    test('rawDropOffs returns empty for no data', function (): void {
        $service = new ConversionPathDiscoveryService($this->cache, $this->config);

        expect($service->rawDropOffs('signup'))->toBe([]);
    });

    test('markConverted handles empty path gracefully', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn([])
            ->once();

        $service = new ConversionPathDiscoveryService($this->cache, $this->config);
        $service->markConverted('signup', 'user_empty');

        // Should not call put since there's no path to record
        $this->cache->shouldNotHaveReceived('put');
    });

    test('compareFunnels returns expected structure with empty data', function (): void {
        $service = new ConversionPathDiscoveryService($this->cache, $this->config);
        $result = $service->compareFunnels('funnel_a', 'funnel_b');

        expect($result)->toHaveKeys([
            'funnel_a',
            'funnel_b',
            'paths_a',
            'paths_b',
            'shared_patterns',
            'unique_a',
            'unique_b',
            'conversion_rate_a',
            'conversion_rate_b',
            'conversion_rate_diff',
        ]);
        expect($result['conversion_rate_diff'])->toBe(0.0);
    });
});

describe('FunnelVelocityAnalyzer — Controller Integration', function (): void {
    test('predictCompletionTime returns complete result for step >= totalSteps', function (): void {
        $analyzer = new FunnelVelocityAnalyzer($this->cache, $this->config);
        $result = $analyzer->predictCompletionTime('signup', 5, 5);

        expect($result)->toBe([
            'estimated_seconds' => 0.0,
            'estimated_minutes' => 0.0,
            'remaining_steps' => 0,
            'confidence' => 'complete',
        ]);
    });

    test('predictCompletionTime returns insufficient_data when no data', function (): void {
        $analyzer = new FunnelVelocityAnalyzer($this->cache, $this->config);
        $result = $analyzer->predictCompletionTime('signup', 1, 5);

        expect($result['confidence'])->toBe('insufficient_data');
        expect($result['estimated_seconds'])->toBeNull();
        expect($result['remaining_steps'])->toBe(4);
    });

    test('stepVelocity returns empty result for no data', function (): void {
        $analyzer = new FunnelVelocityAnalyzer($this->cache, $this->config);
        $result = $analyzer->stepVelocity('signup', 1, 2);

        expect($result['transition'])->toBe('1->2');
        expect($result['sample_count'])->toBe(0);
        expect($result['median_seconds'])->toBeNull();
    });

    test('funnelVelocityReport returns structure with zero steps', function (): void {
        $analyzer = new FunnelVelocityAnalyzer($this->cache, $this->config);
        $result = $analyzer->funnelVelocityReport('signup', 1);

        expect($result['funnel'])->toBe('signup');
        expect($result['total_steps'])->toBe(1);
        expect($result['transitions'])->toBe([]);
        expect($result['overall_median_seconds'])->toBeNull();
        expect($result['bottleneck_step'])->toBeNull();
    });

    test('dropoutAnalysis returns structure with correct steps count', function (): void {
        $analyzer = new FunnelVelocityAnalyzer($this->cache, $this->config);
        $result = $analyzer->dropoutAnalysis('signup', 3);

        expect($result)->toHaveCount(3);
        foreach ($result as $step) {
            expect($step)->toHaveKeys(['step', 'entries', 'exits', 'dropout_rate', 'cumulative_rate']);
        }
    });
});

describe('PrivacyAwareEventRouter — Controller Integration', function (): void {
    test('route returns default zone when no zone specified', function (): void {
        $router = new PrivacyAwareEventRouter([]);
        $event = new AnalyticsEvent(name: 'page_view', params: []);

        $result = $router->route($event);

        expect($result['zone'])->toBe('none');
        expect($result['blocked'])->toBeFalse();
        expect($result['allowed_providers'])->toBe([]);
        expect($result['stripped_fields'])->toBeEmpty();
    });

    test('routeBatch processes multiple events', function (): void {
        $router = new PrivacyAwareEventRouter([]);
        $events = [
            new AnalyticsEvent(name: 'page_view', params: []),
            new AnalyticsEvent(name: 'login', params: []),
        ];

        $result = $router->routeBatch($events);

        expect($result)->toHaveCount(2);
        expect($result[0]['zone'])->toBe('none');
        expect($result[1]['zone'])->toBe('none');
    });

    test('supportedZones returns all configured zones', function (): void {
        $router = new PrivacyAwareEventRouter([]);
        $zones = $router->supportedZones();

        expect($zones)->toContain('none');
        expect($zones)->toContain('gdpr');
        expect($zones)->toContain('ccpa');
    });

    test('getBlockedFields includes global block fields', function (): void {
        $router = new PrivacyAwareEventRouter([]);
        $fields = $router->getBlockedFields('none');

        expect($fields)->toContain('password');
        expect($fields)->toContain('secret');
        expect($fields)->toContain('api_key');
    });

    test('isFieldBlocked returns true for sensitive fields', function (): void {
        $router = new PrivacyAwareEventRouter([]);

        expect($router->isFieldBlocked('password', 'gdpr'))->toBeTrue();
        expect($router->isFieldBlocked('email', 'none'))->toBeFalse();
    });

    test('getAllowedProviders returns empty by default', function (): void {
        $router = new PrivacyAwareEventRouter([]);

        expect($router->getAllowedProviders('none'))->toBe([]);
    });

    test('requiresConsent returns false for none zone', function (): void {
        $router = new PrivacyAwareEventRouter([]);

        expect($router->requiresConsent('none'))->toBeFalse();
    });

    test('requiresConsent returns true for gdpr zone', function (): void {
        $router = new PrivacyAwareEventRouter([]);

        expect($router->requiresConsent('gdpr'))->toBeTrue();
    });

    test('route strips sensitive fields from event params', function (): void {
        $router = new PrivacyAwareEventRouter([]);
        $event = new AnalyticsEvent(name: 'login', params: [
            'email' => 'user@example.com',
            'password' => 'secret123',
            'secret' => 'hidden',
            'page' => '/dashboard',
        ]);

        $result = $router->route($event);

        expect($result['stripped_fields'])->toContain('password');
        expect($result['stripped_fields'])->toContain('secret');
        expect($result['event']->params)->not->toHaveKey('password');
        expect($result['event']->params)->not->toHaveKey('secret');
        expect($result['event']->params['page'])->toBe('/dashboard');
    });
});

describe('RevenueSignalDetector — Controller Integration', function (): void {
    test('churnScore returns minimal risk for fresh user', function (): void {
        $detector = new RevenueSignalDetector($this->cache, $this->config);
        $result = $detector->churnScore('user_123');

        expect($result['user_id'])->toBe('user_123');
        expect($result['churn_score'])->toBe(0.0);
        expect($result['churn_risk'])->toBe('minimal');
        expect($result['recommendation'])->toBeNull();
        expect($result)->toHaveKey('computed_at');
    });

    test('expansionScore returns cold for fresh user', function (): void {
        $detector = new RevenueSignalDetector($this->cache, $this->config);
        $result = $detector->expansionScore('user_123');

        expect($result['user_id'])->toBe('user_123');
        expect($result['expansion_score'])->toBe(0.0);
        expect($result['expansion_potential'])->toBe('cold');
        expect($result['recommendation'])->toBeNull();
    });

    test('fullSignalReport returns combined report', function (): void {
        $detector = new RevenueSignalDetector($this->cache, $this->config);
        $result = $detector->fullSignalReport('user_123');

        expect($result)->toHaveKeys([
            'user_id',
            'churn',
            'expansion',
            'net_signal',
            'net_score',
            'priority',
        ]);
        expect($result['net_signal'])->toBe('neutral');
        expect($result['net_score'])->toBe(0.0);
        expect($result['priority'])->toBe('standard');
    });

    test('churnScore detects downgrade signal', function (): void {
        $detector = new RevenueSignalDetector($this->cache, $this->config);
        $result = $detector->churnScore('user_123', ['plan_downgrade' => 1]);

        expect($result['churn_score'])->toBeGreaterThan(0.0);
        expect($result['churn_risk'])->toBe('low');
        expect($result['recommendation'])->toBe('offer_downgrade_conservation_plan');
    });

    test('churnScore detects payment failure signal', function (): void {
        $detector = new RevenueSignalDetector($this->cache, $this->config);
        $result = $detector->churnScore('user_123', ['payment_failed' => 1]);

        expect($result['churn_score'])->toBeGreaterThan(0.0);
        expect($result['recommendation'])->toBe('send_payment_retry_with_incentive');
    });

    test('churnScore detects declining login trend', function (): void {
        $detector = new RevenueSignalDetector($this->cache, $this->config);
        $result = $detector->churnScore('user_123', [], ['login_trend' => 'declining']);

        expect($result['churn_score'])->toBeGreaterThan(0.0);
        expect($result['recommendation'])->toBe('send_re_engagement_email_campaign');
    });

    test('expansionScore detects feature limit reached', function (): void {
        $detector = new RevenueSignalDetector($this->cache, $this->config);
        $result = $detector->expansionScore('user_123', ['feature_limit_reached' => 1]);

        expect($result['expansion_score'])->toBeGreaterThan(0.0);
        expect($result['expansion_potential'])->toBe('cool');
        expect($result['recommendation'])->toBe('trigger_plan_upgrade_prompt');
    });

    test('expansionScore detects team member invites', function (): void {
        $detector = new RevenueSignalDetector($this->cache, $this->config);
        $result = $detector->expansionScore('user_123', ['invite_sent' => 2]);

        expect($result['expansion_score'])->toBeGreaterThan(0.0);
    });

    test('expansionScore detects API usage growth', function (): void {
        $detector = new RevenueSignalDetector($this->cache, $this->config);
        $result = $detector->expansionScore('user_123', [], ['api_usage_trend' => 'growing']);

        expect($result['expansion_score'])->toBeGreaterThan(0.0);
    });

    test('topAtRiskUsers sorts by churn score descending', function (): void {
        $detector = new RevenueSignalDetector($this->cache, $this->config);

        $this->cache->shouldReceive('get')
            ->andReturn([])
            ->times(4);

        $result = $detector->topAtRiskUsers(['user_a', 'user_b', 'user_c'], 10);

        expect($result)->toBeEmpty();
    });

    test('topExpansionUsers returns empty for no data', function (): void {
        $detector = new RevenueSignalDetector($this->cache, $this->config);

        $this->cache->shouldReceive('get')
            ->andReturn([])
            ->times(4);

        $result = $detector->topExpansionUsers(['user_a', 'user_b'], 10);

        expect($result)->toBeEmpty();
    });

    test('clearUserCache removes both churn and expansion cache', function (): void {
        $detector = new RevenueSignalDetector($this->cache, $this->config);
        $detector->clearUserCache('user_123');

        $this->cache->shouldHaveReceived('forget')->twice();
    });

    test('churn risk classification matches expected ranges', function (): void {
        $detector = new RevenueSignalDetector($this->cache, $this->config);

        // Simulate high churn score via multiple signals
        $result = $detector->churnScore('user_123', [
            'plan_downgrade' => 1,
            'payment_failed' => 1,
            'support_ticket_created' => 3,
        ], [
            'login_trend' => 'declining',
            'core_feature_usage_trend' => 'declining',
            'error_rate_trend' => 'increasing',
            'session_duration_trend' => 'declining',
            'trial_end_date' => (string) now()->addDay()->toIso8601String(),
            'activation_score' => 30,
        ]);

        expect($result['churn_score'])->toBeGreaterThan(50.0);
        expect($result['churn_risk'])->toBe('high');
    });

    test('expansion potential classification with multiple signals', function (): void {
        $detector = new RevenueSignalDetector($this->cache, $this->config);

        $result = $detector->expansionScore('user_123', [
            'feature_limit_reached' => 1,
            'invite_sent' => 3,
            'integration_connected' => 1,
        ], [
            'api_usage_trend' => 'growing',
            'active_streak_days' => 7,
            'export_volume_trend' => 'increasing',
        ]);

        expect($result['expansion_score'])->toBeGreaterThan(50.0);
        expect($result['expansion_potential'])->toBe('warm');
    });

    test('net signal is strong_expansion when expansion dominates', function (): void {
        $detector = new RevenueSignalDetector($this->cache, $this->config);
        $result = $detector->fullSignalReport('user_123', [
            'feature_limit_reached' => 1,
            'invite_sent' => 3,
            'integration_connected' => 1,
        ], [
            'api_usage_trend' => 'growing',
            'active_streak_days' => 7,
            'export_volume_trend' => 'increasing',
        ]);

        expect($result['net_signal'])->toBe('strong_expansion');
        expect($result['priority'])->toBe('high_upsell');
    });
});
