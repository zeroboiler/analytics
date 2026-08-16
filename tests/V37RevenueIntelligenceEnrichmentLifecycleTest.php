<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventEnrichmentService;
use ZeroBoiler\Analytics\Services\SubscriptionLifecycleService;
use ZeroBoiler\Analytics\Services\RevenueIntelligenceService;

beforeEach(function (): void {
    $this->config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
});

describe('EventEnrichmentService', function (): void {
    it('extracts request context with server prefix keys', function (): void {
        $config = $this->config;
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr', [])
            ->andReturn(['anonymize_ip' => false]);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.enrichment', [])
            ->andReturn(['enabled' => true]);

        $service = new EventEnrichmentService($config);

        $request = Mockery::mock(Illuminate\Http\Request::class);
        $request->shouldReceive('ip')->andReturn('192.168.1.100');
        $request->shouldReceive('userAgent')->andReturn('Mozilla/5.0 Test Browser');
        $request->shouldReceive('getLocale')->andReturn('en');
        $request->shouldReceive('header')
            ->with('referer', '')
            ->andReturn('https://example.com/page');
        $request->shouldReceive('fullUrl')->andReturn('https://example.com/test');
        $request->shouldReceive('method')->andReturn('POST');
        $request->shouldReceive('header')
            ->with('content-type', '')
            ->andReturn('application/json');
        $request->shouldReceive('header')
            ->with('accept-language', '')
            ->andReturn('en-US,en;q=0.9');
        $session = Mockery::mock(stdClass::class);
        $session->shouldReceive('getId')->andReturn('session_abc123');
        $request->shouldReceive('session')->andReturn($session);

        $context = $service->extractContext($request);

        expect($context)->toHaveKey('_server_ip');
        expect($context['_server_ip'])->toBe('192.168.1.100');
        expect($context)->toHaveKey('_server_user_agent');
        expect($context)->toHaveKey('_server_locale');
        expect($context['_server_locale'])->toBe('en');
        expect($context)->toHaveKey('_server_referrer');
        expect($context)->toHaveKey('_server_source');
        expect($context['_server_source'])->toBe('api');
        expect($context)->toHaveKey('_server_session_id');
        expect($context)->toHaveKey('_server_accept_language');
        expect($context)->toHaveKey('_server_timestamp');
    });

    it('does not overwrite existing client params when enriching', function (): void {
        $config = $this->config;
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr', [])
            ->andReturn(['anonymize_ip' => false]);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.enrichment', [])
            ->andReturn(['enabled' => true]);

        $service = new EventEnrichmentService($config);

        $request = Mockery::mock(Illuminate\Http\Request::class);
        $request->shouldReceive('ip')->andReturn('10.0.0.1');
        $request->shouldReceive('userAgent')->andReturn('Test');
        $request->shouldReceive('getLocale')->andReturn('en');
        $request->shouldReceive('header')->andReturn('');
        $request->shouldReceive('fullUrl')->andReturn('https://example.com');
        $request->shouldReceive('method')->andReturn('POST');
        $session = Mockery::mock(stdClass::class);
        $session->shouldReceive('getId')->andReturn('');
        $request->shouldReceive('session')->andReturn($session);

        $original = ['button' => 'buy_now', 'page' => '/products'];
        $enriched = $service->enrich($original, $request);

        expect($enriched['button'])->toBe('buy_now');
        expect($enriched['page'])->toBe('/products');
        expect($enriched)->toHaveKey('_server_ip');
    });

    it('anonymizes IPv4 addresses when GDPR is enabled', function (): void {
        $config = $this->config;
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr', [])
            ->andReturn(['anonymize_ip' => true, 'ip_mask_v4' => 2]);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.enrichment', [])
            ->andReturn(['enabled' => true]);

        $service = new EventEnrichmentService($config);

        $request = Mockery::mock(Illuminate\Http\Request::class);
        $request->shouldReceive('ip')->andReturn('192.168.1.100');
        $request->shouldReceive('userAgent')->andReturn('Test');
        $request->shouldReceive('getLocale')->andReturn('en');
        $request->shouldReceive('header')->andReturn('');
        $request->shouldReceive('fullUrl')->andReturn('https://example.com');
        $request->shouldReceive('method')->andReturn('GET');
        $session = Mockery::mock(stdClass::class);
        $session->shouldReceive('getId')->andReturn('');
        $request->shouldReceive('session')->andReturn($session);

        $context = $service->extractContext($request);

        expect($context['_server_ip'])->toBe('192.168.0.0');
    });

    it('returns empty string for null IP', function (): void {
        $config = $this->config;
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr', [])
            ->andReturn(['anonymize_ip' => true, 'ip_mask_v4' => 2]);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.enrichment', [])
            ->andReturn(['enabled' => true]);

        $service = new EventEnrichmentService($config);

        $request = Mockery::mock(Illuminate\Http\Request::class);
        $request->shouldReceive('ip')->andReturn(null);
        $request->shouldReceive('userAgent')->andReturn('Test');
        $request->shouldReceive('getLocale')->andReturn('en');
        $request->shouldReceive('header')->andReturn('');
        $request->shouldReceive('fullUrl')->andReturn('https://example.com');
        $request->shouldReceive('method')->andReturn('GET');
        $session = Mockery::mock(stdClass::class);
        $session->shouldReceive('getId')->andReturn('');
        $request->shouldReceive('session')->andReturn($session);

        $context = $service->extractContext($request);

        expect($context['_server_ip'])->toBe('0.0.0.0');
    });

    it('reports diagnostics correctly', function (): void {
        $config = $this->config;
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr', [])
            ->andReturn(['anonymize_ip' => true, 'ip_mask_v4' => 2, 'ip_mask_v6' => 48]);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.enrichment', [])
            ->andReturn(['enabled' => true]);

        $service = new EventEnrichmentService($config);

        $diag = $service->diagnostics();

        expect($diag['enabled'])->toBeTrue();
        expect($diag['gdpr_anonymize_ip'])->toBeTrue();
        expect($diag['ip_mask_v4'])->toBe(2);
        expect($diag['ip_mask_v6'])->toBe(48);
    });
});

describe('SubscriptionLifecycleService', function (): void {
    beforeEach(function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue.currency', 'USD')
            ->andReturn('USD');
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.ecommerce.currency', 'USD')
            ->andReturn('USD');
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue.billing_cycle_default', 'monthly')
            ->andReturn('monthly');
    });

    it('creates a trial_started event', function (): void {
        $service = new SubscriptionLifecycleService($this->config);
        $event = $service->trialStarted('user_123', 'pro', 14);

        expect($event)->toBeInstanceOf(AnalyticsEvent::class);
        expect($event->name)->toBe('trial_started');
        expect($event->params['user_id'])->toBe('user_123');
        expect($event->params['plan'])->toBe('pro');
        expect($event->params['trial_days'])->toBe(14);
        expect($event->params['currency'])->toBe('USD');
        expect($event->userId)->toBe('user_123');
    });

    it('creates a subscription_created event', function (): void {
        $service = new SubscriptionLifecycleService($this->config);
        $event = $service->subscriptionCreated('user_456', 'sub_789', 'enterprise', 199.00, 'yearly');

        expect($event->name)->toBe('subscription_created');
        expect($event->params['subscription_id'])->toBe('sub_789');
        expect($event->params['plan'])->toBe('enterprise');
        expect($event->params['amount'])->toBe(199.0);
        expect($event->params['billing_cycle'])->toBe('yearly');
        expect($event->params['revenue_type'])->toBe('new');
    });

    it('creates a plan_upgraded event with expansion amount', function (): void {
        $service = new SubscriptionLifecycleService($this->config);
        $event = $service->planUpgraded('user_123', 'starter', 'pro', 19.00, 49.00);

        expect($event->name)->toBe('plan_upgraded');
        expect($event->params['from_plan'])->toBe('starter');
        expect($event->params['to_plan'])->toBe('pro');
        expect($event->params['expansion_amount'])->toBe(30.0);
        expect($event->params['revenue_type'])->toBe('expansion');
    });

    it('creates a subscription_cancelled event with reason', function (): void {
        $service = new SubscriptionLifecycleService($this->config);
        $event = $service->subscriptionCancelled('user_123', 'sub_999', 'pro', 49.00, 'too_expensive');

        expect($event->name)->toBe('subscription_cancelled');
        expect($event->params['lost_mrr'])->toBe(49.0);
        expect($event->params['cancellation_reason'])->toBe('too_expensive');
        expect($event->params['revenue_type'])->toBe('churn');
    });

    it('creates a payment_failed event with attempt details', function (): void {
        $service = new SubscriptionLifecycleService($this->config);
        $event = $service->paymentFailed('user_123', 'sub_789', 49.00, 2, 'card_declined');

        expect($event->name)->toBe('payment_failed');
        expect($event->params['attempt_number'])->toBe(2);
        expect($event->params['failure_reason'])->toBe('card_declined');
    });

    it('merges extra params into events', function (): void {
        $service = new SubscriptionLifecycleService($this->config);
        $event = $service->trialStarted('user_123', 'pro', 14, ['source' => 'landing_page', 'campaign' => 'spring_sale']);

        expect($event->params['source'])->toBe('landing_page');
        expect($event->params['campaign'])->toBe('spring_sale');
    });
});

describe('RevenueIntelligenceService', function (): void {
    it('generates a full revenue intelligence report', function (): void {
        $cache = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->once();

        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue_intelligence', [])
            ->andReturn(['enabled' => true, 'cache_ttl' => 300]);

        $service = new RevenueIntelligenceService($cache, $config);
        $report = $service->report([
            'mrr' => 10000,
            'active_subscribers' => 200,
            'churn_rate' => 0.03,
            'arpu' => 50,
            'trial_conversion_rate' => 0.25,
            'new_mrr_last_month' => 2000,
            'expansion_mrr_last_month' => 800,
            'churned_mrr_last_month' => 300,
        ]);

        expect($report)->toHaveKey('revenue');
        expect($report)->toHaveKey('health');
        expect($report)->toHaveKey('churn');
        expect($report)->toHaveKey('forecast');
        expect($report)->toHaveKey('unit_economics');
        expect($report)->toHaveKey('movement');
        expect($report)->toHaveKey('signals');
        expect($report)->toHaveKey('recommendations');
        expect($report)->toHaveKey('generated_at');
        expect($report['revenue']['mrr'])->toBe(10000.0);
        expect($report['health']['score'])->toBeGreaterThanOrEqual(0);
        expect($report['health']['score'])->toBeLessThanOrEqual(100);
    });

    it('generates quick summary', function (): void {
        $cache = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue_intelligence', [])
            ->andReturn(['enabled' => true, 'cache_ttl' => 300]);

        $service = new RevenueIntelligenceService($cache, $config);
        $summary = $service->quickSummary([
            'mrr' => 5000,
            'active_subscribers' => 100,
            'churn_rate' => 0.02,
        ]);

        expect($summary['mrr'])->toBe(5000.0);
        expect($summary['arr'])->toBe(60000.0);
        expect($summary['mrr_growth_label'])->toBe('healthy');
    });

    it('detects churn signals and produces recommendations', function (): void {
        $cache = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue_intelligence', [])
            ->andReturn(['enabled' => true, 'cache_ttl' => 300]);

        $service = new RevenueIntelligenceService($cache, $config);
        $result = $service->signals([
            'mrr' => 10000,
            'churn_rate' => 0.12,
            'arpu' => 15,
            'trial_conversion_rate' => 0.10,
        ]);

        expect($result['signals'])->toBeNonEmpty();
        expect($result['recommendations'])->toBeNonEmpty();

        $criticalSignals = array_filter($result['signals'], fn (array $s): bool => $s['severity'] === 'critical');
        expect($criticalSignals)->not->toBeEmpty();
    });

    it('detects positive signals when metrics are healthy', function (): void {
        $cache = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue_intelligence', [])
            ->andReturn(['enabled' => true, 'cache_ttl' => 300]);

        $service = new RevenueIntelligenceService($cache, $config);
        $result = $service->signals([
            'mrr' => 50000,
            'churn_rate' => 0.01,
            'arpu' => 150,
            'trial_conversion_rate' => 0.45,
        ]);

        $positiveSignals = array_filter($result['signals'], fn (array $s): bool => $s['severity'] === 'positive');
        expect($positiveSignals)->not->toBeEmpty();
    });

    it('returns disabled report when service is disabled', function (): void {
        $cache = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.revenue_intelligence', [])
            ->andReturn(['enabled' => false, 'cache_ttl' => 300]);

        $service = new RevenueIntelligenceService($cache, $config);
        $report = $service->report();

        expect($report['health']['grade'])->toBe('N/A');
        expect($report['signals'])->toBeEmpty();
    });
});

afterEach(function (): void {
    Mockery::close();
});
