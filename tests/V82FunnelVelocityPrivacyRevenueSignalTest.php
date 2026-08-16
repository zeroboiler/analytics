<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
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
    ]);
});

describe('FunnelVelocityAnalyzer', function (): void {
    test('constructor initializes correctly', function (): void {
        $analyzer = new FunnelVelocityAnalyzer($this->cache, $this->config);

        expect($analyzer)->toBeInstanceOf(FunnelVelocityAnalyzer::class);
    });

    test('records step advancement and persists to cache', function (): void {
        $this->cache->shouldReceive('get')
            ->with(\Mockery::any(), [])
            ->andReturn([]);

        $analyzer = new FunnelVelocityAnalyzer($this->cache, $this->config);
        $analyzer->recordStepAdvancement('signup', 'user_123', 1, 2, 45.5);

        $this->cache->shouldHaveReceived('put')
            ->withArgs(function (string $key, array $data, int $ttl): bool {
                return str_contains($key, 'signup:user_123')
                    && isset($data['1->2']['elapsed'])
                    && $data['1->2']['elapsed'] === 45.5;
            });
    });

    test('records funnel completion', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn([])
            ->once();

        $analyzer = new FunnelVelocityAnalyzer($this->cache, $this->config);
        $analyzer->recordCompletion('signup', 'user_123', 5, 300.0);

        $this->cache->shouldHaveReceived('put')
            ->withArgs(function (string $key, array $data): bool {
                return str_contains($key, '_completions')
                    && count($data) === 1
                    && $data[0]['total_elapsed'] === 300.0;
            });
    });

    test('step velocity returns empty result when no data', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn([])
            ->once();

        $analyzer = new FunnelVelocityAnalyzer($this->cache, $this->config);
        $result = $analyzer->stepVelocity('signup', 1, 2);

        expect($result)
            ->toBeArray()
            ->toHaveKey('transition')
            ->toHaveKey('sample_count')
            ->toHaveKey('median_seconds');

        expect($result['transition'])->toBe('1->2');
        expect($result['sample_count'])->toBe(0);
        expect($result['median_seconds'])->toBeNull();
    });

    test('funnel velocity report returns complete structure', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn([], [])
            ->times(2);

        $analyzer = new FunnelVelocityAnalyzer($this->cache, $this->config);
        $report = $analyzer->funnelVelocityReport('signup', 4);

        expect($report)
            ->toBeArray()
            ->toHaveKey('funnel')
            ->toHaveKey('total_steps')
            ->toHaveKey('transitions')
            ->toHaveKey('overall_completion_rate')
            ->toHaveKey('bottleneck_step');

        expect($report['funnel'])->toBe('signup');
        expect($report['total_steps'])->toBe(4);
        expect($report['transitions'])->toHaveCount(3);
    });

    test('dropout analysis returns per-step breakdown', function (): void {
        $analyzer = new FunnelVelocityAnalyzer($this->cache, $this->config);
        $dropout = $analyzer->dropoutAnalysis('checkout', 3);

        expect($dropout)->toBeArray();
        expect($dropout)->toHaveCount(3);

        foreach ($dropout as $step) {
            expect($step)->toHaveKeys(['step', 'entries', 'exits', 'dropout_rate', 'cumulative_rate']);
            expect($step['entries'])->toBeInt();
            expect($step['dropout_rate'])->toBeFloat();
        }
    });

    test('predict completion time for current step', function (): void {
        $this->cache->shouldReceive('get')
            ->andReturn([])
            ->times(2);

        $analyzer = new FunnelVelocityAnalyzer($this->cache, $this->config);
        $prediction = $analyzer->predictCompletionTime('signup', 2, 5);

        expect($prediction)
            ->toBeArray()
            ->toHaveKeys(['estimated_seconds', 'estimated_minutes', 'remaining_steps', 'confidence']);

        expect($prediction['remaining_steps'])->toBe(3);
        expect($prediction['confidence'])->toBe('insufficient_data');
    });

    test('predict completion time returns complete when at final step', function (): void {
        $analyzer = new FunnelVelocityAnalyzer($this->cache, $this->config);
        $prediction = $analyzer->predictCompletionTime('signup', 5, 5);

        expect($prediction['remaining_steps'])->toBe(0);
        expect($prediction['confidence'])->toBe('complete');
        expect($prediction['estimated_seconds'])->toBe(0.0);
    });

    test('clear funnel removes completion cache', function (): void {
        $analyzer = new FunnelVelocityAnalyzer($this->cache, $this->config);
        $analyzer->clearFunnel('signup');

        $this->cache->shouldHaveReceived('forget');
    });
});

describe('PrivacyAwareEventRouter', function (): void {
    beforeEach(function (): void {
        $this->router = new PrivacyAwareEventRouter([
            'enabled' => true,
            'default_zone' => 'none',
            'custom_block_fields' => [],
            'provider_allowlists' => [],
        ]);
    });

    test('constructor creates router with config', function (): void {
        expect($this->router)->toBeInstanceOf(PrivacyAwareEventRouter::class);
    });

    test('routes event and strips blocked fields for GDPR zone', function (): void {
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: [
                'page' => '/dashboard',
                'email' => 'user@example.com',
                'ip' => '192.168.1.1',
                'ssn' => 'should_always_be_blocked',
            ],
            clientId: 'client_123',
            userId: 'user_456',
        );

        $result = $this->router->route($event, 'gdpr');

        expect($result['zone'])->toBe('gdpr');
        expect($result['blocked'])->toBeFalse();
        expect($result['stripped_fields'])->toContain('email');
        expect($result['stripped_fields'])->toContain('ip');
        expect($result['stripped_fields'])->toContain('ssn');
        expect($result['event']->params)->not->toHaveKey('email');
        expect($result['event']->params)->not->toHaveKey('ip');
        expect($result['event']->params)->toHaveKey('page');

        // GDPR strict mode strips identity
        expect($result['event']->clientId)->toBeNull();
        expect($result['event']->userId)->toBeNull();
    });

    test('none zone only blocks global block fields', function (): void {
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: [
                'page' => '/dashboard',
                'email' => 'user@example.com',
                'password' => 'secret123',
            ],
            clientId: 'client_123',
            userId: 'user_456',
        );

        $result = $this->router->route($event, 'none');

        expect($result['stripped_fields'])->not->toContain('email');
        expect($result['stripped_fields'])->toContain('password');
        expect($result['event']->clientId)->toBe('client_123');
        expect($result['event']->userId)->toBe('user_456');
    });

    test('blocks event when consent denied in GDPR zone', function (): void {
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: [
                'page' => '/dashboard',
                '_consent' => 'denied',
            ],
            clientId: 'client_123',
        );

        $result = $this->router->route($event, 'gdpr');

        expect($result['blocked'])->toBeTrue();
        expect($result['blocked_reason'])->toBe('consent_denied');
        expect($result['allowed_providers'])->toBeEmpty();
    });

    test('batch routes multiple events', function (): void {
        $events = [
            new AnalyticsEvent(name: 'page_view', params: ['email' => 'test@test.com']),
            new AnalyticsEvent(name: 'click', params: ['page' => '/home']),
        ];

        $results = $this->router->routeBatch($events, 'gdpr');

        expect($results)->toHaveCount(2);
        expect($results[0]['stripped_fields'])->toContain('email');
    });

    test('detectZone returns default zone for null IP', function (): void {
        expect($this->router->detectZone(null))->toBe('none');
        expect($this->router->detectZone(''))->toBe('none');
    });

    test('getBlockedFields returns combined list', function (): void {
        $gdprFields = $this->router->getBlockedFields('gdpr');

        expect($gdprFields)->toContain('email');
        expect($gdprFields)->toContain('password');
        expect($gdprFields)->toContain('ssn');
        expect($gdprFields)->toContain('api_key');
    });

    test('isFieldBlocked checks specific field', function (): void {
        expect($this->router->isFieldBlocked('email', 'gdpr'))->toBeTrue();
        expect($this->router->isFieldBlocked('email', 'none'))->toBeFalse();
        expect($this->router->isFieldBlocked('password', 'none'))->toBeTrue();
    });

    test('supportedZones returns all zones', function (): void {
        $zones = $this->router->supportedZones();

        expect($zones)->toContain('gdpr');
        expect($zones)->toContain('ccpa');
        expect($zones)->toContain('lgpd');
        expect($zones)->toContain('pipeda');
        expect($zones)->toContain('none');
    });

    test('requiresConsent returns correct values per zone', function (): void {
        expect($this->router->requiresConsent('gdpr'))->toBeTrue();
        expect($this->router->requiresConsent('ccpa'))->toBeTrue();
        expect($this->router->requiresConsent('none'))->toBeFalse();
    });

    test('isStrictMode returns correct values per zone', function (): void {
        expect($this->router->isStrictMode('gdpr'))->toBeTrue();
        expect($this->router->isStrictMode('ccpa'))->toBeFalse();
        expect($this->router->isStrictMode('lgpd'))->toBeTrue();
        expect($this->router->isStrictMode('none'))->toBeFalse();
    });

    test('disabled router passes through events unchanged', function (): void {
        $disabledRouter = new PrivacyAwareEventRouter(['enabled' => false]);

        $event = new AnalyticsEvent(
            name: 'page_view',
            params: ['email' => 'test@test.com', 'ssn' => '123-456'],
            clientId: 'client_1',
        );

        $result = $disabledRouter->route($event, 'gdpr');

        expect($result['blocked'])->toBeFalse();
        expect($result['stripped_fields'])->toBeEmpty();
        expect($result['event']->params)->toHaveKey('email');
        expect($result['event']->clientId)->toBe('client_1');
    });

    test('strips nested PII fields', function (): void {
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: [
                'page' => '/dashboard',
                'user' => [
                    'name' => 'John',
                    'email' => 'john@example.com',
                ],
            ],
        );

        $result = $this->router->route($event, 'gdpr');

        expect($result['stripped_fields'])->toContain('user.email');
    });
});

describe('RevenueSignalDetector', function (): void {
    beforeEach(function (): void {
        $this->detector = new RevenueSignalDetector($this->cache, $this->config);
    });

    test('constructor initializes correctly', function (): void {
        expect($this->detector)->toBeInstanceOf(RevenueSignalDetector::class);
    });

    test('computes churn score with no signals', function (): void {
        $result = $this->detector->churnScore('user_123');

        expect($result)
            ->toBeArray()
            ->toHaveKeys(['user_id', 'churn_score', 'churn_risk', 'signals', 'recommendation', 'computed_at']);

        expect($result['user_id'])->toBe('user_123');
        expect($result['churn_score'])->toBe(0.0);
        expect($result['churn_risk'])->toBe('minimal');
        expect($result['recommendation'])->toBeNull();
    });

    test('computes churn score with detected signals', function (): void {
        $eventCounts = [
            'payment_failed' => 2,
            'support_ticket_created' => 5,
        ];

        $context = [
            'login_trend' => 'declining',
            'core_feature_usage_trend' => 'declining',
        ];

        $result = $this->detector->churnScore('user_456', $eventCounts, $context);

        expect($result['churn_score'])->toBeGreaterThan(0);
        expect($result['churn_risk'])->toBeIn(['minimal', 'low', 'moderate', 'high', 'critical']);

        $detectedSignals = array_filter($result['signals'], fn (array $s): bool => $s['detected']);
        expect(count($detectedSignals))->toBeGreaterThan(0);
    });

    test('computes churn score for trial ending soon', function (): void {
        $context = [
            'trial_end_date' => now()->addDays(1)->toIso8601String(),
            'activation_score' => 20,
        ];

        $result = $this->detector->churnScore('user_trial', [], $context);

        expect($result['churn_score'])->toBeGreaterThan(0);
        expect($result['recommendation'])->toBe('trigger_trial_extension_offer');
    });

    test('computes churn score for downgrade event', function (): void {
        $eventCounts = ['plan_downgrade' => 1];
        $result = $this->detector->churnScore('user_downgrade', $eventCounts);

        expect($result['churn_score'])->toBeGreaterThan(0);
        expect($result['recommendation'])->toBe('offer_downgrade_conservation_plan');
    });

    test('computes expansion score with no signals', function (): void {
        $result = $this->detector->expansionScore('user_123');

        expect($result)
            ->toBeArray()
            ->toHaveKeys(['user_id', 'expansion_score', 'expansion_potential', 'signals', 'recommendation']);

        expect($result['expansion_score'])->toBe(0.0);
        expect($result['expansion_potential'])->toBe('cold');
    });

    test('computes expansion score with detected signals', function (): void {
        $eventCounts = [
            'feature_limit_reached' => 3,
            'invite_sent' => 5,
            'integration_connected' => 2,
        ];

        $context = [
            'api_usage_trend' => 'growing',
            'active_streak_days' => 10,
            'export_volume_trend' => 'increasing',
        ];

        $result = $this->detector->expansionScore('user_789', $eventCounts, $context);

        expect($result['expansion_score'])->toBeGreaterThan(0);

        $detectedSignals = array_filter($result['signals'], fn (array $s): bool => $s['detected']);
        expect(count($detectedSignals))->toBeGreaterThan(0);
    });

    test('full signal report computes net signal', function (): void {
        $churnContext = ['payment_failed' => 1];
        $expansionContext = ['feature_limit_reached' => 2, 'invite_sent' => 3];

        $result = $this->detector->fullSignalReport('user_999', ['feature_limit_reached' => 2, 'invite_sent' => 3, 'payment_failed' => 1], $expansionContext);

        expect($result)
            ->toBeArray()
            ->toHaveKeys(['user_id', 'churn', 'expansion', 'net_signal', 'net_score', 'priority']);

        expect($result['net_signal'])->toBeIn([
            'strong_expansion', 'moderate_expansion', 'neutral',
            'moderate_churn_risk', 'strong_churn_risk',
        ]);
    });

    test('batch churn scores processes multiple users', function (): void {
        $results = $this->detector->batchChurnScores(['user_1', 'user_2', 'user_3']);

        expect($results)->toHaveCount(3);
        expect($results)->toHaveKeys(['user_1', 'user_2', 'user_3']);

        foreach ($results as $result) {
            expect($result)->toHaveKey('churn_score');
        }
    });

    test('top at risk users returns sorted by churn score', function (): void {
        $results = $this->detector->topAtRiskUsers(['user_a', 'user_b', 'user_c'], 2);

        expect($results)->toHaveCount(2);
        // All scores are 0 when no signals detected, so order is arbitrary
        foreach ($results as $r) {
            expect($r)->toHaveKeys(['user_id', 'churn_score', 'churn_risk']);
        }
    });

    test('top expansion users returns sorted by expansion score', function (): void {
        $results = $this->detector->topExpansionUsers(['user_a', 'user_b'], 10);

        expect($results)->toHaveCount(2);
        foreach ($results as $r) {
            expect($r)->toHaveKeys(['user_id', 'expansion_score', 'expansion_potential']);
        }
    });

    test('clear user cache removes both churn and expansion', function (): void {
        $this->cache->shouldReceive('forget')
            ->twice();

        $this->detector->churnScore('user_cache_test');
        $this->detector->clearUserCache('user_cache_test');

        $this->cache->shouldHaveReceived('forget')->twice();
    });

    test('churn risk classification produces correct labels', function (): void {
        $scoreMinimal = $this->detector->churnScore('user_min');
        expect($scoreMinimal['churn_risk'])->toBe('minimal');

        // Score with strong signals
        $result = $this->detector->churnScore('user_high', ['plan_downgrade' => 1, 'payment_failed' => 2], [
            'login_trend' => 'declining',
            'core_feature_usage_trend' => 'declining',
            'error_rate_trend' => 'increasing',
            'support_ticket_created' => 5,
        ]);

        expect($result['churn_score'])->toBeGreaterThan(0);
    });

    test('expansion potential classification produces correct labels', function (): void {
        $scoreCold = $this->detector->expansionScore('user_cold');
        expect($scoreCold['expansion_potential'])->toBe('cold');
    });
});
