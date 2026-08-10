<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Services\TrackingGuardRailsService;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;
use ZeroBoiler\Analytics\Trackers\WebhookTracker;

beforeEach(function (): void {
    $this->manager = new AnalyticsManager(null);
});

describe('TrackingGuardRailsService', function (): void {
    test('can be instantiated with dependencies', function (): void {
        $cache = app('cache');
        $config = app('config');
        $manager = $this->manager;

        $service = new TrackingGuardRailsService($cache, $config, $manager);

        expect($service->isEnabled())->toBeTrue();
    });

    test('returns disabled report when service is disabled', function (): void {
        $cache = mock(Cache::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        // Temporarily override config
        $config = app('config');
        $config->set('zeroboiler.analytics.guard_rails.enabled', false);

        $service = new TrackingGuardRailsService($cache, $config, $this->manager);

        $report = $service->check();

        expect($report)
            ->toHaveKey('score')
            ->toHaveKey('grade')
            ->toHaveKey('dimensions')
            ->toHaveKey('violations')
            ->toHaveKey('recommendations');

        expect($report['score'])->toBe(0);
        expect($report['grade'])->toBe('N/A');
        expect($report['dimensions'])->toBeEmpty();
    });

    test('computes full guard rails check with empty metrics', function (): void {
        $cache = mock(Cache::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        $config = app('config');
        $service = new TrackingGuardRailsService($cache, $config, $this->manager);

        $report = $service->check([]);

        expect($report)
            ->toHaveKey('score')
            ->toHaveKey('grade')
            ->toHaveKey('dimensions')
            ->toHaveKey('coverage')
            ->toHaveKey('naming')
            ->toHaveKey('violations')
            ->toHaveKey('recommendations');

        // With no providers enabled, provider coverage should be 0
        expect($report['dimensions'])->toHaveKey('provider_coverage');
        expect($report['dimensions']['provider_coverage']['score'])->toBe(0);
    });

    test('provider coverage score reflects enabled providers', function (): void {
        $cache = mock(Cache::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        $config = app('config');
        $config->set('zeroboiler.analytics.ga4.enabled', true);
        $config->set('zeroboiler.analytics.ga4.measurement_id', 'G-TEST123');

        $service = new TrackingGuardRailsService($cache, $config, $this->manager);

        $report = $service->check([
            'total_events' => 500,
            'tracked_event_names' => ['sign_up', 'login', 'page_view', 'purchase'],
        ]);

        // With 1 provider enabled, score should be 60
        expect($report['dimensions']['provider_coverage']['score'])->toBe(60);
    });

    test('coverage completeness reflects tracked core events', function (): void {
        $cache = mock(Cache::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        $config = app('config');
        $service = new TrackingGuardRailsService($cache, $config, $this->manager);

        $report = $service->check([
            'total_events' => 500,
            'tracked_event_names' => ['sign_up', 'login', 'page_view'],
        ]);

        $coverage = $report['coverage'];

        expect($coverage['core_tracked'])
            ->toContain('sign_up')
            ->toContain('login')
            ->toContain('page_view');

        expect($coverage['core_missing'])
            ->toContain('start_trial')
            ->toContain('purchase')
            ->not()->toContain('sign_up');
    });

    test('naming convention validates snake_case', function (): void {
        $cache = mock(Cache::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        $config = app('config');
        $service = new TrackingGuardRailsService($cache, $config, $this->manager);

        $report = $service->check([
            'total_events' => 500,
            'tracked_event_names' => ['sign_up', 'user_login', 'page_view', 'MyCustomEvent', 'buttonClick'],
        ]);

        $naming = $report['naming'];
        expect($naming['total'])->toBe(5);
        expect($naming['compliant'])->toBe(3); // sign_up, user_login, page_view
        expect($naming['violations'])->toContain('MyCustomEvent');
        expect($naming['violations'])->toContain('buttonClick');
    });

    test('validateEventName checks naming conventions', function (): void {
        $cache = app('cache');
        $config = app('config');
        $service = new TrackingGuardRailsService($cache, $config, $this->manager);

        // Valid name
        $result = $service->validateEventName('user_signed_up');
        expect($result['valid'])->toBeTrue();
        expect($result['issues'])->toBeEmpty();

        // Invalid: camelCase
        $result = $service->validateEventName('userSignedUp');
        expect($result['valid'])->toBeFalse();
        expect($result['issues'])->not()->toBeEmpty();
        expect($result['suggestion'])->toBe('user_signed_up');

        // Invalid: too short
        $result = $service->validateEventName('a');
        expect($result['valid'])->toBeFalse();
    });

    test('coreEventCoverage returns correct completeness', function (): void {
        $cache = app('cache');
        $config = app('config');
        $service = new TrackingGuardRailsService($cache, $config, $this->manager);

        $coverage = $service->coreEventCoverage(['sign_up', 'login', 'page_view', 'purchase', 'start_trial']);

        expect($coverage['required'])->toHaveCount(11);
        expect($coverage['tracked'])->toHaveCount(5);
        expect($coverage['missing'])->not()->toBeEmpty();
        expect($coverage['completeness'])->toBeGreaterThan(0.0)
            ->toBeLessThan(100.0);
    });

    test('quickScore returns compact response', function (): void {
        $cache = mock(Cache::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        $config = app('config');
        $service = new TrackingGuardRailsService($cache, $config, $this->manager);

        $score = $service->quickScore([]);

        expect($score)->toHaveKeys(['score', 'grade', 'label', 'generated_at']);
        expect($score['grade'])->toBeIn(['A', 'B', 'C', 'D', 'F']);
    });

    test('violations filter by severity', function (): void {
        $cache = mock(Cache::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        $config = app('config');
        $service = new TrackingGuardRailsService($cache, $config, $this->manager);

        // No events tracked — should have critical violations
        $allViolations = $service->violations([], 'info');
        $criticalViolations = $service->violations([], 'critical');

        expect(count($criticalViolations))->toBeLessThanOrEqual(count($allViolations));
    });

    test('consent compliance scores higher with GDPR-safe defaults', function (): void {
        $cache = mock(Cache::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        $config = app('config');

        // Test with GDPR-safe defaults (denied)
        $config->set('zeroboiler.analytics.consent.default', 'denied');
        $config->set('zeroboiler.analytics.consent.log_enabled', true);

        $service = new TrackingGuardRailsService($cache, $config, $this->manager);

        $report = $service->check(['total_events' => 500, 'tracked_event_names' => ['sign_up', 'login']]);

        $consentScore = $report['dimensions']['consent_compliance']['score'];
        expect($consentScore)->toBeGreaterThanOrEqual(80);

        // Test with non-GDPR defaults (granted)
        $cache2 = mock(Cache::class);
        $cache2->shouldReceive('get')->andReturn(null);
        $cache2->shouldReceive('put')->andReturn(true);
        $config->set('zeroboiler.analytics.consent.default', 'granted');
        $config->set('zeroboiler.analytics.consent.log_enabled', false);

        $service2 = new TrackingGuardRailsService($cache2, $config, $this->manager);

        $report2 = $service2->check(['total_events' => 500, 'tracked_event_names' => ['sign_up', 'login']]);

        $consentScore2 = $report2['dimensions']['consent_compliance']['score'];
        expect($consentScore2)->toBeLessThan($consentScore);
    });

    test('composite score is weighted average of dimensions', function (): void {
        $cache = mock(Cache::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        $config = app('config');
        $service = new TrackingGuardRailsService($cache, $config, $this->manager);

        $report = $service->check([
            'total_events' => 500,
            'tracked_event_names' => ['sign_up', 'login', 'page_view', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation', 'logout', 'purchase', 'start_trial', 'trial_converted'],
        ]);

        $score = $report['score'];
        expect($score)->toBeGreaterThanOrEqual(0)
            ->toBeLessThanOrEqual(100);
        expect($report['grade'])->toBeIn(['A', 'B', 'C', 'D', 'F']);

        // Verify all dimensions have weight and score
        foreach ($report['dimensions'] as $dim) {
            expect($dim)->toHaveKey('score');
            expect($dim)->toHaveKey('weight');
            expect($dim)->toHaveKey('label');
            expect($dim)->toHaveKey('status');
            expect($dim['weight'])->toBeGreaterThan(0);
            expect($dim['score'])->toBeGreaterThanOrEqual(0);
            expect($dim['score'])->toBeLessThanOrEqual(100);
        }
    });

    test('below minimum events shows deferred message', function (): void {
        $cache = mock(Cache::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(true);

        $config = app('config');
        $service = new TrackingGuardRailsService($cache, $config, $this->manager);

        $report = $service->check([
            'total_events' => 50, // Below default threshold of 100
            'tracked_event_names' => ['sign_up', 'login'],
        ]);

        $coverageDim = $report['dimensions']['coverage_completeness'];
        expect($coverageDim['score'])->toBe(50); // Neutral during ramp-up
        expect($coverageDim['details']['message'])->toContain('Below minimum');
    });

    test('clearCache clears the cached result', function (): void {
        $cache = app('cache');
        $config = app('config');
        $service = new TrackingGuardRailsService($cache, $config, $this->manager);

        // Should not throw
        $service->clearCache();
        expect(true)->toBeTrue();
    });
});
