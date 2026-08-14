<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\EventPriorityCalculator;
use ZeroBoiler\Analytics\Services\EventPriorityGate;
use ZeroBoiler\Analytics\Services\ProviderFallbackService;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventPriority;

beforeEach(function (): void {
    $this->calculator = new EventPriorityCalculator;
    $this->cache = app('cache');
    $this->config = app('config');
});

// ── ProviderFallbackService: Extended Valid Providers ─────────────────────

describe('ProviderFallbackService v95.0.0', function (): void {
    test('validate accepts mixpanel, amplitude, tiktok, linkedin as valid providers', function (): void {
        $service = new ProviderFallbackService($this->cache, $this->config);

        $result = $service->validate();

        // No validation errors for the built-in providers
        expect($result['valid'])->toBe(true);
        expect($result['errors'])->toBeEmpty();
    });

    test('validate rejects unknown provider in chain', function (): void {
        $this->config->set('zeroboiler.analytics.fallback', [
            'enabled' => true,
            'max_depth' => 3,
            'cache_prefix' => 'zb_fallback_test_',
            'chains' => [
                'unknown_provider' => ['ga4'],
            ],
        ]);

        $service = new ProviderFallbackService($this->cache, $this->config);

        $result = $service->validate();

        expect($result['valid'])->toBe(false);
        expect($result['errors'])->toContain("Invalid primary provider 'unknown_provider' in fallback chain");
    });

    test('validate accepts mixpanel in fallback chain', function (): void {
        $this->config->set('zeroboiler.analytics.fallback', [
            'enabled' => true,
            'max_depth' => 3,
            'cache_prefix' => 'zb_fallback_test_',
            'chains' => [
                'mixpanel' => ['amplitude', 'posthog'],
                'amplitude' => ['mixpanel', 'ga4'],
                'tiktok' => ['linkedin', 'meta'],
                'linkedin' => ['tiktok', 'ga4'],
            ],
        ]);

        $service = new ProviderFallbackService($this->cache, $this->config);

        $result = $service->validate();

        expect($result['valid'])->toBe(true);
    });

    test('healthSummary includes all 10 providers', function (): void {
        $service = new ProviderFallbackService($this->cache, $this->config);

        $summary = $service->healthSummary([]);

        $providers = array_keys($summary['providers']);
        expect($providers)->toHaveCount(10);
        expect($providers)->toContain('ga4');
        expect($providers)->toContain('mixpanel');
        expect($providers)->toContain('amplitude');
        expect($providers)->toContain('tiktok');
        expect($providers)->toContain('linkedin');
        expect($providers)->toContain('gtm');
        expect($providers)->toContain('meta');
        expect($providers)->toContain('posthog');
        expect($providers)->toContain('plausible');
        expect($providers)->toContain('webhook');
    });

    test('resolveProvider falls back through extended chain', function (): void {
        $this->config->set('zeroboiler.analytics.fallback', [
            'enabled' => true,
            'max_depth' => 3,
            'cache_prefix' => 'zb_fallback_test_',
            'chains' => [
                'mixpanel' => ['amplitude', 'posthog'],
            ],
        ]);

        $service = new ProviderFallbackService($this->cache, $this->config);

        // Mixpanel is open, amplitude is closed, posthog is closed
        $resolved = $service->resolveProvider('mixpanel', [
            'mixpanel' => 'open',
            'amplitude' => 'open',
            'posthog' => 'open',
        ]);

        expect($resolved)->toBe('amplitude');
    });
});

// ── EventPriorityGate: Security, Uptime, Infrastructure Categories ─────────

describe('EventPriorityGate v95.0.0 category extensions', function (): void {
    test('security events resolve to Normal priority', function (): void {
        $gate = new EventPriorityGate($this->cache, $this->config);

        $event = new AnalyticsEvent(name: 'login_attempt', params: []);
        $priority = $gate->resolvePriority($event);

        expect($priority)->toBe(EventPriority::Normal);
    });

    test('uptime events resolve to Normal priority', function (): void {
        $gate = new EventPriorityGate($this->cache, $this->config);

        $event = new AnalyticsEvent(name: 'service_down', params: []);
        $priority = $gate->resolvePriority($event);

        expect($priority)->toBe(EventPriority::Normal);
    });

    test('infrastructure events resolve to Normal priority', function (): void {
        $gate = new EventPriorityGate($this->cache, $this->config);

        $event = new AnalyticsEvent(name: 'deployment', params: []);
        $priority = $gate->resolvePriority($event);

        expect($priority)->toBe(EventPriority::Normal);
    });

    test('engagement events still resolve to Low priority', function (): void {
        $gate = new EventPriorityGate($this->cache, $this->config);

        $event = new AnalyticsEvent(name: 'scroll_depth', params: []);
        $priority = $gate->resolvePriority($event);

        expect($priority)->toBe(EventPriority::Low);
    });

    test('critical events always resolve to Critical priority regardless of category', function (): void {
        $gate = new EventPriorityGate($this->cache, $this->config);

        $event = new AnalyticsEvent(name: 'purchase', params: []);
        $priority = $gate->resolvePriority($event);

        expect($priority)->toBe(EventPriority::Critical);
    });
});

// ── EventPriorityCalculator: Extended AARRR Classifications ────────────────

describe('EventPriorityCalculator v95.0.0 AARRR expansion', function (): void {
    test('security events classify as operational', function (): void {
        expect($this->calculator->classify('login_attempt'))->toBe('operational');
        expect($this->calculator->classify('mfa_challenge'))->toBe('operational');
        expect($this->calculator->classify('suspicious_activity'))->toBe('operational');
        expect($this->calculator->classify('data_access_audit'))->toBe('operational');
        expect($this->calculator->classify('ai_agent_access'))->toBe('operational');
        expect($this->calculator->classify('rate_limit_exceeded'))->toBe('operational');
    });

    test('uptime events classify as operational', function (): void {
        expect($this->calculator->classify('api_latency'))->toBe('operational');
        expect($this->calculator->classify('deployment'))->toBe('operational');
        expect($this->calculator->classify('error_spike'))->toBe('operational');
        expect($this->calculator->classify('service_down'))->toBe('operational');
        expect($this->calculator->classify('service_up'))->toBe('operational');
        expect($this->calculator->classify('pipeline_failure'))->toBe('operational');
        expect($this->calculator->classify('slo_breach'))->toBe('operational');
        expect($this->calculator->classify('incident_started'))->toBe('operational');
        expect($this->calculator->classify('incident_resolved'))->toBe('operational');
        expect($this->calculator->classify('maintenance_started'))->toBe('operational');
        expect($this->calculator->classify('maintenance_ended'))->toBe('operational');
        expect($this->calculator->classify('error_budget_burned'))->toBe('operational');
    });

    test('experiment_exposed classifies as activation', function (): void {
        expect($this->calculator->classify('experiment_exposed'))->toBe('activation');
    });

    test('feature_flag_evaluated classifies as retention', function (): void {
        expect($this->calculator->classify('feature_flag_evaluated'))->toBe('retention');
    });

    test('abandoned_cart and checkout_abandon classify as revenue', function (): void {
        expect($this->calculator->classify('abandoned_cart'))->toBe('revenue');
        expect($this->calculator->classify('checkout_abandon'))->toBe('revenue');
    });

    test('retention_cohort classifies as retention', function (): void {
        expect($this->calculator->classify('retention_cohort'))->toBe('retention');
    });

    test('activation event classifies as activation', function (): void {
        expect($this->calculator->classify('activation'))->toBe('activation');
    });

    test('client_error classifies as operational', function (): void {
        expect($this->calculator->classify('client_error'))->toBe('operational');
    });

    test('hover, copy_text, element_visibility classify as retention', function (): void {
        expect($this->calculator->classify('hover'))->toBe('retention');
        expect($this->calculator->classify('copy_text'))->toBe('retention');
        expect($this->calculator->classify('element_visibility'))->toBe('retention');
    });

    test('onboarding_completed classifies as activation', function (): void {
        expect($this->calculator->classify('onboarding_completed'))->toBe('activation');
    });

    test('performance_score classifies as operational', function (): void {
        expect($this->calculator->classify('performance_score'))->toBe('operational');
    });

    test('consent events classify as operational', function (): void {
        expect($this->calculator->classify('consent_granted'))->toBe('operational');
        expect($this->calculator->classify('consent_withdrawn'))->toBe('operational');
    });

    test('outbound_click classifies as acquisition', function (): void {
        expect($this->calculator->classify('outbound_click'))->toBe('acquisition');
    });

    test('critical events include retention_cohort', function (): void {
        $priority = $this->calculator->getEventPriority('retention_cohort');

        expect($priority)->toBe('critical');
    });

    test('classifyAll includes new events in categories', function (): void {
        $classified = $this->calculator->classifyAll();

        // Security events should be in operational
        expect($classified['operational'])->toContain('login_attempt');
        expect($classified['operational'])->toContain('slo_breach');

        // New revenue events
        expect($classified['revenue'])->toContain('abandoned_cart');
        expect($classified['revenue'])->toContain('checkout_abandon');

        // New retention events
        expect($classified['retention'])->toContain('retention_cohort');
        expect($classified['retention'])->toContain('hover');

        // New activation events
        expect($classified['activation'])->toContain('activation');
        expect($classified['activation'])->toContain('onboarding_completed');
        expect($classified['activation'])->toContain('experiment_exposed');

        // Acquisition
        expect($classified['acquisition'])->toContain('outbound_click');
    });

    test('maturityScore reflects expanded catalog', function (): void {
        $maturity = $this->calculator->maturityScore();

        expect($maturity)->toHaveKey('score');
        expect($maturity)->toHaveKey('grade');
        expect($maturity['score'])->toBeGreaterThanOrEqual(0);
        expect($maturity['score'])->toBeLessThanOrEqual(100);
    });

    test('categoryCounts includes all six categories', function (): void {
        $counts = $this->calculator->categoryCounts();

        expect($counts)->toHaveKey('acquisition');
        expect($counts)->toHaveKey('activation');
        expect($counts)->toHaveKey('retention');
        expect($counts)->toHaveKey('revenue');
        expect($counts)->toHaveKey('referral');
        expect($counts)->toHaveKey('operational');
        expect($counts)->toHaveKey('total');
        expect($counts['total'])->toBeGreaterThan(0);
    });
});
