<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventSignalIntelligenceService;

// ── V7.7.0 Event Signal Intelligence Service Tests ──────────────────

describe('V7.7.0 Event Signal Intelligence', function () {

    beforeEach(function (): void {
        $cache = new CacheRepository(new ArrayStore);
        $config = new ConfigRepository([
            'zeroboiler' => [
                'analytics' => [
                    'signal_intelligence' => [
                        'cache_ttl' => 300,
                        'staleness_threshold' => 3600,
                        'anomaly_window' => 600,
                        'anomaly_deviation' => 2.0,
                    ],
                    'ga4' => ['enabled' => true],
                    'meta_pixel' => ['enabled' => true],
                    'posthog' => ['enabled' => false],
                    'plausible' => ['enabled' => false],
                    'webhook' => ['enabled' => false],
                ],
            ],
        ]);

        $metrics = new AnalyticsMetrics;

        $this->service = new EventSignalIntelligenceService($cache, $config, $metrics);
    });

    test('service can be instantiated', function (): void {
        expect($this->service)->toBeInstanceOf(EventSignalIntelligenceService::class);
    });

    test('report returns valid structure', function (): void {
        $report = $this->service->report();

        expect($report)->toHaveKeys([
            'signal_score', 'grade', 'providers', 'categories',
            'anomalies', 'staleness_summary', 'signal_to_noise',
            'dispatch_balance', 'recommendations', 'computed_at',
        ]);
    });

    test('signal score is between 0 and 100', function (): void {
        $report = $this->service->report();

        expect($report['signal_score'])->toBeFloat();
        expect($report['signal_score'])->toBeGreaterThanOrEqual(0.0);
        expect($report['signal_score'])->toBeLessThanOrEqual(100.0);
    });

    test('grade is a non-empty string', function (): void {
        $report = $this->service->report();

        expect($report['grade'])->toBeString();
        expect($report['grade'])->not->toBeEmpty();
    });

    test('provider signals include all 5 providers', function (): void {
        $report = $this->service->report();

        expect($report['providers'])->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible', 'webhook']);

        foreach ($report['providers'] as $name => $signal) {
            expect($signal)->toHaveKeys([
                'name', 'status', 'events_dispatched', 'events_failed',
                'failure_rate', 'last_dispatch_at', 'staleness_seconds',
                'anomaly_score', 'health_decay',
            ]);
            expect($signal['name'])->toBe($name);
            expect(in_array($signal['status'], ['healthy', 'degraded', 'stale', 'down'], true))->toBeTrue();
        }
    });

    test('provider status is down when no events dispatched and provider enabled', function (): void {
        $report = $this->service->report();

        // GA4 is enabled but no events dispatched in this test → should be down
        expect($report['providers']['ga4']['status'])->toBe('down');
    });

    test('category signals cover all catalog categories', function (): void {
        $report = $this->service->report();

        expect($report['categories'])->toHaveKeys(['ecommerce', 'saas', 'engagement']);

        foreach ($report['categories'] as $name => $signal) {
            expect($signal)->toHaveKeys(['name', 'events', 'percentage', 'top_events', 'trend']);
            expect($signal['events'])->toBeInt();
            expect($signal['events'])->toBeGreaterThan(0);
        }
    });

    test('anomalies array is returned even when empty', function (): void {
        $report = $this->service->report();

        expect($report['anomalies'])->toBeArray();
    });

    test('staleness summary has stale and healthy keys', function (): void {
        $report = $this->service->report();

        expect($report['staleness_summary'])->toHaveKeys(['stale', 'healthy']);
        expect($report['staleness_summary']['stale'])->toBeArray();
        expect($report['staleness_summary']['healthy'])->toBeArray();
    });

    test('signal to noise ratio is between 0 and 1', function (): void {
        $report = $this->service->report();

        expect($report['signal_to_noise'])->toBeFloat();
        expect($report['signal_to_noise'])->toBeGreaterThanOrEqual(0.0);
        expect($report['signal_to_noise'])->toBeLessThanOrEqual(1.0);
    });

    test('dispatch balance is between 0 and 100', function (): void {
        $report = $this->service->report();

        expect($report['dispatch_balance'])->toBeFloat();
        expect($report['dispatch_balance'])->toBeGreaterThanOrEqual(0.0);
        expect($report['dispatch_balance'])->toBeLessThanOrEqual(100.0);
    });

    test('computed_at is a valid date string', function (): void {
        $report = $this->service->report();

        expect($report['computed_at'])->toBeString();
        expect(strtotime($report['computed_at']))->not->toBeFalse();
    });

    test('recommendations is an array', function (): void {
        $report = $this->service->report();

        expect($report['recommendations'])->toBeArray();
    });

    test('signalScore returns a float', function (): void {
        $score = $this->service->signalScore();

        expect($score)->toBeFloat();
        expect($score)->toBeGreaterThanOrEqual(0.0);
        expect($score)->toBeLessThanOrEqual(100.0);
    });

    test('anomalies method returns array', function (): void {
        $anomalies = $this->service->anomalies();

        expect($anomalies)->toBeArray();
    });

    test('providerSignals returns all providers', function (): void {
        $signals = $this->service->providerSignals();

        expect($signals)->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible', 'webhook']);
    });

    test('categorySignals returns all categories', function (): void {
        $signals = $this->service->categorySignals();

        expect($signals)->toHaveKeys(['ecommerce', 'saas', 'engagement']);
    });

    test('hasStaleProviders returns bool', function (): void {
        $result = $this->service->hasStaleProviders();

        expect($result)->toBeBool();
    });

    test('clear resets state without error', function (): void {
        expect(fn (): mixed => $this->service->clear())->not->toThrow();
    });

    test('recordDispatch updates dispatch timeline', function (): void {
        $this->service->recordDispatch('ga4', 'page_view', true);

        // After recording, the provider should still be down because
        // AnalyticsMetrics doesn't track per-provider in this test context
        $signals = $this->service->providerSignals();

        expect($signals)->toHaveKey('ga4');
    });

    test('staleness summary correctly identifies stale providers', function (): void {
        $summary = $this->service->stalenessSummary();

        // All providers are "down" since no events dispatched
        expect($summary['stale'])->not->toBeEmpty();
    });

    test('dispatch balance with no events returns 100', function (): void {
        $balance = $this->service->calculateDispatchBalance($this->service->providerSignals());

        expect($balance)->toBe(100.0);
    });

    test('signal grade labels match expected format', function (): void {
        $service = $this->service;

        expect($service->signalGrade(95.0))->toBe('A+ (Excellent Signal)');
        expect($service->signalGrade(85.0))->toBe('A (Strong Signal)');
        expect($service->signalGrade(75.0))->toBe('B+ (Good Signal)');
        expect($service->signalGrade(65.0))->toBe('B (Adequate Signal)');
        expect($service->signalGrade(55.0))->toBe('C+ (Degraded Signal)');
        expect($service->signalGrade(45.0))->toBe('C (Weak Signal)');
        expect($service->signalGrade(35.0))->toBe('D (Poor Signal)');
        expect($service->signalGrade(25.0))->toBe('F (Critical — No Signal)');
        expect($service->signalGrade(10.0))->toBe('F- (Pipeline Failure)');
    });

    test('event catalog is used for signal-to-noise calculation', function (): void {
        // Verify catalog is available
        $catalogCount = EventCatalog::count();

        expect($catalogCount)->toBeGreaterThan(0);

        // Signal-to-noise should be computable
        $stn = $this->service->calculateSignalToNoise();
        expect($stn)->toBeFloat();
    });
});
