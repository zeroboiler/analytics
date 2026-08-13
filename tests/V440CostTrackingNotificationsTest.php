<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventCostTracker;
use ZeroBoiler\Analytics\Services\NotificationWebhookService;

beforeEach(function (): void {
    $this->metrics = new \ZeroBoiler\Analytics\AnalyticsMetrics;
    $this->cache = app('cache');

    $this->config = mock(\Illuminate\Contracts\Config\Repository::class);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.cost_tracking', [])
        ->andReturn([
            'enabled' => true,
            'currency' => 'USD',
            'providers' => [
                'ga4' => ['enabled' => true],
                'posthog' => ['enabled' => true, 'unit_cost' => 0.0005, 'free_tier' => 500000],
                'plausible' => ['enabled' => true, 'unit_cost' => 0.01],
            ],
        ]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.notification_webhooks', [])
        ->andReturn([
            'enabled' => true,
            'rate_limit_seconds' => 0, // No rate limit in tests
            'webhooks' => [],
        ]);
    $this->config->shouldReceive('get')
        ->withArgs(fn (mixed $key): bool => str_starts_with($key, 'zeroboiler.analytics.ga4'))
        ->andReturn(['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'test']);
    $this->config->shouldReceive('get')
        ->withArgs(fn (mixed $key): bool => ! str_starts_with($key, 'zeroboiler.analytics.cost_tracking') && ! str_starts_with($key, 'zeroboiler.analytics.notification_webhooks') && ! str_starts_with($key, 'zeroboiler.analytics.ga4'))
        ->andReturn([]);

    $this->manager = new \ZeroBoiler\Analytics\AnalyticsManager($this->config);
    $this->costTracker = new EventCostTracker($this->manager, $this->metrics, $this->cache, $this->config);
    $this->notifService = new NotificationWebhookService($this->cache, $this->config);
});

describe('EventCostTracker', function (): void {
    it('is enabled when configured', function (): void {
        expect($this->costTracker->isEnabled())->toBeTrue();
    });

    it('generates a full cost report', function (): void {
        $report = $this->costTracker->report();

        expect($report)->toHaveKey('enabled');
        expect($report)->toHaveKey('currency');
        expect($report)->toHaveKey('providers');
        expect($report)->toHaveKey('total');
        expect($report)->toHaveKey('period');
        expect($report)->toHaveKey('generated_at');
        expect($report['currency'])->toBe('USD');
        expect($report['total'])->toHaveKeys(['cost', 'events', 'projected_monthly']);
    });

    it('includes all configured providers in report', function (): void {
        $report = $this->costTracker->report();

        expect($report['providers'])->toHaveKeys(['ga4', 'posthog', 'plausible']);
    });

    it('reports GA4 as free model with zero cost', function (): void {
        $report = $this->costTracker->report();

        expect($report['providers']['ga4']['model'])->toBe('free');
        expect($report['providers']['ga4']['cost'])->toBe(0.0);
        expect($report['providers']['ga4']['unit_cost'])->toBe(0.0);
    });

    it('calculates tiered cost for PostHog with free tier', function (): void {
        $report = $this->costTracker->report();

        expect($report['providers']['posthog']['model'])->toBe('per_event');
        expect($report['providers']['posthog']['free_tier'])->toBe(500000);
        expect($report['providers']['posthog']['unit_cost'])->toBe(0.0005);
    });

    it('reports per-provider cost details', function (): void {
        $ga4Cost = $this->costTracker->providerCost('ga4');

        expect($ga4Cost)->not->toBeNull();
        expect($ga4Cost)->toHaveKeys(['events', 'cost', 'projected_monthly', 'model', 'currency']);
        expect($ga4Cost['cost'])->toBe(0.0);
    });

    it('returns null for unknown provider', function (): void {
        expect($this->costTracker->providerCost('unknown_provider'))->toBeNull();
    });

    it('checks free tier status for GA4', function (): void {
        expect($this->costTracker->isWithinFreeTier('ga4'))->toBeTrue();
    });

    it('generates CLI summary', function (): void {
        $summary = $this->costTracker->cliSummary();

        expect($summary)->toBeArray();
        expect(count($summary))->toBeGreaterThanOrEqual(3);

        foreach ($summary as $row) {
            expect($row)->toHaveKeys(['provider', 'events', 'cost', 'projected', 'model']);
        }
    });

    it('provides provider pricing configuration', function (): void {
        $pricing = $this->costTracker->getProviderPricing();

        expect($pricing)->toHaveKeys(['ga4', 'posthog', 'plausible']);
        expect($pricing['ga4'])->toHaveKeys(['enabled', 'model', 'unit_cost', 'free_tier', 'currency']);
    });
});

describe('NotificationWebhookService', function (): void {
    it('is enabled when configured', function (): void {
        expect($this->notifService->isEnabled())->toBeTrue();
    });

    it('returns empty result when no webhooks configured', function (): void {
        $result = $this->notifService->sendAlert([
            'rule' => 'test_rule',
            'event' => 'purchase',
            'severity' => 'warning',
            'message' => 'Test alert',
            'triggered_at' => date('c'),
        ]);

        expect($result)->toBe([
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'results' => [],
        ]);
    });

    it('returns disabled when service is not enabled', function (): void {
        $disabledConfig = mock(\Illuminate\Contracts\Config\Repository::class);
        $disabledConfig->shouldReceive('get')
            ->with('zeroboiler.analytics.notification_webhooks', [])
            ->andReturn(['enabled' => false, 'webhooks' => []]);

        $disabledService = new NotificationWebhookService($this->cache, $disabledConfig);

        expect($disabledService->isEnabled())->toBeFalse();
        expect($disabledService->sendAlert(['rule' => 'test', 'severity' => 'info', 'message' => 'test', 'triggered_at' => date('c')]))
            ->toBe(['sent' => 0, 'failed' => 0, 'skipped' => 0, 'results' => []]);
    });

    it('returns delivery stats', function (): void {
        $stats = $this->notifService->deliveryStats();

        expect($stats)->toHaveKeys(['webhooks', 'total_sent', 'total_failed']);
        expect($stats['total_sent'])->toBe(0);
        expect($stats['total_failed'])->toBe(0);
    });

    it('returns webhook list', function (): void {
        $webhooks = $this->notifService->getWebhooks();

        expect($webhooks)->toBeArray();
        expect(count($webhooks))->toBe(0); // No webhooks configured in tests
    });

    it('returns not_found for custom send to unknown webhook', function (): void {
        $result = $this->notifService->sendCustom('nonexistent', 'Test message');

        expect($result['status'])->toBe('not_found');
    });

    it('returns not_found for test of unknown webhook', function (): void {
        $result = $this->notifService->testWebhook('nonexistent');

        expect($result['status'])->toBe('not_found');
    });
});

describe('v74.0.0 Version Consistency', function (): void {
    it('has correct version in AnalyticsEvent', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('74.0.0');
    });

    it('composer.json version matches', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);

        expect($composer['version'])->toBe('74.0.0');
    });
});
