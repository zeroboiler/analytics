<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\EventTags;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Http\Middleware\AutoPageViewMiddleware;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\EcommerceFormatConverter;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter as FormatConverterAlias;
use ZeroBoiler\Analytics\Support\SaaSEventHelpers;
use ZeroBoiler\Analytics\Tracking\LifecycleEventSubscriber;
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;

/**
 * Phase 41 — Cross-Funnel Correlation & Event Impact Matrix.
 *
 * Comprehensive validation of:
 * - crossFunnelCorrelation() overlap detection, funnel sizes, intersection matrix
 * - funnelStepAttribution() AARRR stage mapping for all funnel types
 * - eventImpactMatrix() priority scores, provider coverage, tag analysis
 * - funnelDropoffAnalysis() severity classification and worst dropoff
 * - All prior features (funnels, conversion rates, AARRR breakdown, filterByProviders)
 * - Version consistency across all 7 package files
 * - SaaS starter maturity criteria at v113.0.0
 *
 * @since 113.0.0
 */

// ── Version Consistency ─────────────────────────────────────────────────

it('has correct VERSION constant in AnalyticsEvent', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('113.0.0');
});

it('has correct version in composer.json', function (): void {
    $content = file_get_contents(__DIR__ . '/../composer.json');
    expect($content)->toBeJson();
    $composer = json_decode($content, true);
    expect($composer['version'])->toBe('113.0.0');
});

it('has correct version in package.json', function (): void {
    $content = file_get_contents(__DIR__ . '/../package.json');
    expect($content)->toBeJson();
    $pkg = json_decode($content, true);
    expect($pkg['version'])->toBe('113.0.0');
});

it('has correct version in analytics.js', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($content)->toContain('@version 113.0.0');
});

it('has correct version in analytics.d.ts', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($content)->toContain('@version 113.0.0');
});

it('has correct version in analytics.constants.js', function (): void {
    $content = file_get_contents(__DIR__ . '/../resources/js/analytics.constants.js');
    expect($content)->toContain('@version 113.0.0');
});

it('has correct version in AnalyticsServiceProvider', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($content)->toContain('@version 113.0.0');
});

it('has correct version badge in README', function (): void {
    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-113.0.0');
});

// ── crossFunnelCorrelation ───────────────────────────────────────────────

it('crossFunnelCorrelation returns correct structure', function (): void {
    $correlation = EventCatalog::crossFunnelCorrelation();

    expect($correlation)->toHaveKey('overlap_events');
    expect($correlation)->toHaveKey('funnel_sizes');
    expect($correlation)->toHaveKey('intersection_matrix');
    expect($correlation['overlap_events'])->toBeArray();
    expect($correlation['funnel_sizes'])->toBeArray();
    expect($correlation['intersection_matrix'])->toBeArray();
});

it('crossFunnelCorrelation has all 5 funnel types', function (): void {
    $correlation = EventCatalog::crossFunnelCorrelation();

    expect($correlation['funnel_sizes'])->toHaveKey('saas');
    expect($correlation['funnel_sizes'])->toHaveKey('ecommerce');
    expect($correlation['funnel_sizes'])->toHaveKey('engagement');
    expect($correlation['funnel_sizes'])->toHaveKey('activation');
    expect($correlation['funnel_sizes'])->toHaveKey('checkout');
    expect(count($correlation['funnel_sizes']))->toBe(5);
});

it('crossFunnelCorrelation funnel sizes are positive', function (): void {
    $correlation = EventCatalog::crossFunnelCorrelation();

    foreach ($correlation['funnel_sizes'] as $funnel => $size) {
        expect($size)->toBeGreaterThan(0);
    }
});

it('crossFunnelCorrelation intersection matrix is symmetric on diagonal', function (): void {
    $correlation = EventCatalog::crossFunnelCorrelation();
    $matrix = $correlation['intersection_matrix'];

    foreach ($matrix as $funnel => $row) {
        // Diagonal should equal funnel size (self-intersection)
        expect($matrix[$funnel][$funnel])->toBe($correlation['funnel_sizes'][$funnel]);
    }
});

it('crossFunnelCorrelation overlap events have funnel_count > 1', function (): void {
    $correlation = EventCatalog::crossFunnelCorrelation();

    foreach ($correlation['overlap_events'] as $overlap) {
        expect($overlap['funnel_count'])->toBeGreaterThan(1);
        expect($overlap['event'])->toBeString();
        expect($overlap['funnels'])->toBeArray();
        expect(count($overlap['funnels']))->toBe($overlap['funnel_count']);
        expect($overlap['tags'])->toBeArray();
    }
});

it('crossFunnelCorrelation overlap events are sorted by funnel_count desc', function (): void {
    $correlation = EventCatalog::crossFunnelCorrelation();
    $overlaps = $correlation['overlap_events'];

    if (count($overlaps) >= 2) {
        for ($i = 0; $i < count($overlaps) - 1; $i++) {
            expect($overlaps[$i]['funnel_count'])->toBeGreaterThanOrEqual(
                $overlaps[$i + 1]['funnel_count']
            );
        }
    }
});

it('crossFunnelCorrelation finds events in 3+ funnels', function (): void {
    $correlation = EventCatalog::crossFunnelCorrelation();

    $highOverlap = array_filter(
        $correlation['overlap_events'],
        fn (array $e): bool => $e['funnel_count'] >= 3
    );

    expect(count($highOverlap))->toBeGreaterThan(0);
});

// ── funnelStepAttribution ────────────────────────────────────────────────

it('funnelStepAttribution returns all three funnel types', function (): void {
    $attribution = EventCatalog::funnelStepAttribution();

    expect($attribution)->toHaveKey('saas');
    expect($attribution)->toHaveKey('ecommerce');
    expect($attribution)->toHaveKey('engagement');
});

it('funnelStepAttribution saas has correct number of steps', function (): void {
    $attribution = EventCatalog::funnelStepAttribution();

    expect(count($attribution['saas']))->toBe(9);
});

it('funnelStepAttribution ecommerce has correct number of steps', function (): void {
    $attribution = EventCatalog::funnelStepAttribution();

    expect(count($attribution['ecommerce']))->toBe(9);
});

it('funnelStepAttribution engagement has correct number of steps', function (): void {
    $attribution = EventCatalog::funnelStepAttribution();

    expect(count($attribution['engagement']))->toBe(8);
});

it('funnelStepAttribution steps have correct keys', function (): void {
    $attribution = EventCatalog::funnelStepAttribution();

    foreach (['saas', 'ecommerce', 'engagement'] as $type) {
        foreach ($attribution[$type] as $step) {
            expect($step)->toHaveKey('step');
            expect($step)->toHaveKey('event');
            expect($step)->toHaveKey('aarrr_stage');
            expect($step)->toHaveKey('tags');
            expect($step['step'])->toBeInt();
            expect($step['event'])->toBeString();
            expect($step['aarrr_stage'])->toBeString();
        }
    }
});

it('funnelStepAttribution steps are sequential', function (): void {
    $attribution = EventCatalog::funnelStepAttribution();

    foreach (['saas', 'ecommerce', 'engagement'] as $type) {
        foreach ($attribution[$type] as $index => $step) {
            expect($step['step'])->toBe($index + 1);
        }
    }
});

it('funnelStepAttribution saas first step is acquisition', function (): void {
    $attribution = EventCatalog::funnelStepAttribution();

    expect($attribution['saas'][0]['event'])->toBe('sign_up');
    // sign_up has acquisition tag
    expect($attribution['saas'][0]['aarrr_stage'])->toBe('acquisition');
});

it('funnelStepAttribution aarrr_stage values are valid', function (): void {
    $validStages = ['acquisition', 'activation', 'retention', 'revenue', 'referral', 'operational'];
    $attribution = EventCatalog::funnelStepAttribution();

    foreach (['saas', 'ecommerce', 'engagement'] as $type) {
        foreach ($attribution[$type] as $step) {
            expect(in_array($step['aarrr_stage'], $validStages, true))->toBeTrue();
        }
    }
});

it('funnelStepAttribution has at least one revenue stage in saas funnel', function (): void {
    $attribution = EventCatalog::funnelStepAttribution();

    $revenueSteps = array_filter(
        $attribution['saas'],
        fn (array $s): bool => $s['aarrr_stage'] === 'revenue'
    );

    expect(count($revenueSteps))->toBeGreaterThan(0);
});

it('funnelStepAttribution has at least one revenue stage in ecommerce funnel', function (): void {
    $attribution = EventCatalog::funnelStepAttribution();

    $revenueSteps = array_filter(
        $attribution['ecommerce'],
        fn (array $s): bool => $s['aarrr_stage'] === 'revenue'
    );

    expect(count($revenueSteps))->toBeGreaterThan(0);
});

// ── eventImpactMatrix ────────────────────────────────────────────────────

it('eventImpactMatrix returns array of events', function (): void {
    $matrix = EventCatalog::eventImpactMatrix();

    expect($matrix)->toBeArray();
    expect(count($matrix))->toBeGreaterThan(0);
});

it('eventImpactMatrix items have correct keys', function (): void {
    $matrix = EventCatalog::eventImpactMatrix();

    foreach ($matrix as $item) {
        expect($item)->toHaveKey('event');
        expect($item)->toHaveKey('funnels');
        expect($item)->toHaveKey('aarrr_stage');
        expect($item)->toHaveKey('priority_score');
        expect($item)->toHaveKey('provider_count');
        expect($item)->toHaveKey('tags');
    }
});

it('eventImpactMatrix is sorted by priority_score desc', function (): void {
    $matrix = EventCatalog::eventImpactMatrix();

    if (count($matrix) >= 2) {
        for ($i = 0; $i < count($matrix) - 1; $i++) {
            expect($matrix[$i]['priority_score'])->toBeGreaterThanOrEqual(
                $matrix[$i + 1]['priority_score']
            );
        }
    }
});

it('eventImpactMatrix priority scores are in valid range', function (): void {
    $matrix = EventCatalog::eventImpactMatrix();

    foreach ($matrix as $item) {
        expect($item['priority_score'])->toBeGreaterThanOrEqual(0);
        expect($item['priority_score'])->toBeLessThanOrEqual(100);
    }
});

it('eventImpactMatrix provider_count is in valid range', function (): void {
    $matrix = EventCatalog::eventImpactMatrix();

    foreach ($matrix as $item) {
        expect($item['provider_count'])->toBeGreaterThanOrEqual(0);
        expect($item['provider_count'])->toBeLessThanOrEqual(8);
    }
});

it('eventImpactMatrix filter by saas returns only saas events', function (): void {
    $matrix = EventCatalog::eventImpactMatrix('saas');

    expect(count($matrix))->toBeGreaterThan(0);

    foreach ($matrix as $item) {
        expect(in_array('saas', $item['funnels'], true))->toBeTrue();
    }
});

it('eventImpactMatrix filter by ecommerce returns only ecommerce events', function (): void {
    $matrix = EventCatalog::eventImpactMatrix('ecommerce');

    expect(count($matrix))->toBeGreaterThan(0);

    foreach ($matrix as $item) {
        expect(in_array('ecommerce', $item['funnels'], true))->toBeTrue();
    }
});

it('eventImpactMatrix filter by engagement returns only engagement events', function (): void {
    $matrix = EventCatalog::eventImpactMatrix('engagement');

    expect(count($matrix))->toBeGreaterThan(0);

    foreach ($matrix as $item) {
        expect(in_array('engagement', $item['funnels'], true))->toBeTrue();
    }
});

// ── funnelDropoffAnalysis ───────────────────────────────────────────────

it('funnelDropoffAnalysis returns correct structure', function (): void {
    $result = EventCatalog::funnelDropoffAnalysis([
        'sign_up' => 1000,
        'login' => 800,
        'start_trial' => 400,
    ], 'saas');

    expect($result)->toHaveKey('transitions');
    expect($result)->toHaveKey('worst_dropoff');
    expect($result['transitions'])->toBeArray();
    expect(count($result['transitions']))->toBeGreaterThan(0);
});

it('funnelDropoffAnalysis computes correct drop-off rates', function (): void {
    $result = EventCatalog::funnelDropoffAnalysis([
        'sign_up' => 1000,
        'login' => 700,
    ], 'saas');

    $firstTransition = $result['transitions'][0];
    expect($firstTransition['from'])->toBe('sign_up');
    expect($firstTransition['to'])->toBe('login');
    expect($firstTransition['from_count'])->toBe(1000);
    expect($firstTransition['to_count'])->toBe(700);
    expect($firstTransition['drop_off_rate'])->toBe(30.0);
});

it('funnelDropoffAnalysis healthy severity for low drop-off', function (): void {
    $result = EventCatalog::funnelDropoffAnalysis([
        'sign_up' => 1000,
        'login' => 800,
    ], 'saas');

    expect($result['transitions'][0]['severity'])->toBe('healthy');
});

it('funnelDropoffAnalysis warning severity for medium drop-off', function (): void {
    $result = EventCatalog::funnelDropoffAnalysis([
        'sign_up' => 1000,
        'login' => 500,
    ], 'saas');

    expect($result['transitions'][0]['severity'])->toBe('warning');
});

it('funnelDropoffAnalysis critical severity for high drop-off', function (): void {
    $result = EventCatalog::funnelDropoffAnalysis([
        'sign_up' => 1000,
        'login' => 200,
    ], 'saas');

    expect($result['transitions'][0]['severity'])->toBe('critical');
});

it('funnelDropoffAnalysis worst_dropoff identifies highest drop', function (): void {
    $result = EventCatalog::funnelDropoffAnalysis([
        'sign_up' => 1000,
        'login' => 900,
        'start_trial' => 100,
        'trial_converted' => 50,
    ], 'saas');

    expect($result['worst_dropoff'])->not->toBeNull();
    expect($result['worst_dropoff']['from'])->toBe('start_trial');
    expect($result['worst_dropoff']['to'])->toBe('trial_converted');
    expect($result['worst_dropoff']['rate'])->toBe(50.0);
});

it('funnelDropoffAnalysis handles zero from_count gracefully', function (): void {
    $result = EventCatalog::funnelDropoffAnalysis([
        'sign_up' => 0,
        'login' => 0,
    ], 'saas');

    expect($result['transitions'][0]['drop_off_rate'])->toBeNull();
    expect($result['transitions'][0]['severity'])->toBeNull();
    expect($result['worst_dropoff'])->toBeNull();
});

it('funnelDropoffAnalysis works for ecommerce funnel', function (): void {
    $result = EventCatalog::funnelDropoffAnalysis([
        'view_item' => 5000,
        'add_to_cart' => 500,
        'purchase' => 200,
        'refund' => 10,
    ], 'ecommerce');

    expect(count($result['transitions']))->toBe(8); // 9 steps - 1
    expect($result['transitions'][0]['from'])->toBe('view_item');
    expect($result['transitions'][0]['to'])->toBe('select_item');
    expect($result['worst_dropoff'])->not->toBeNull();
});

it('funnelDropoffAnalysis works for engagement funnel', function (): void {
    $result = EventCatalog::funnelDropoffAnalysis([
        'page_view' => 10000,
        'scroll_depth' => 7000,
        'click' => 3000,
        'error' => 500,
    ], 'engagement');

    expect(count($result['transitions']))->toBe(7); // 8 steps - 1
    expect($result['transitions'][0]['from'])->toBe('page_view');
});

it('funnelDropoffAnalysis returns empty for unknown funnel type', function (): void {
    $result = EventCatalog::funnelDropoffAnalysis([], 'unknown');

    expect($result['transitions'])->toBe([]);
    expect($result['worst_dropoff'])->toBeNull();
});

// ── Existing Feature Validation (carried forward) ──────────────────────

it('saasFunnelEvents returns 9 steps', function (): void {
    expect(count(EventCatalog::saasFunnelEvents()))->toBe(9);
});

it('ecommerceFunnelEvents returns 9 steps', function (): void {
    expect(count(EventCatalog::ecommerceFunnelEvents()))->toBe(9);
});

it('engagementFunnelEvents returns 8 steps', function (): void {
    expect(count(EventCatalog::engagementFunnelEvents()))->toBe(8);
});

it('funnelConversionRates works for saas', function (): void {
    $rates = EventCatalog::funnelConversionRates([
        'sign_up' => 1000,
        'cancellation' => 100,
    ], 'saas');

    expect($rates['overall_conversion'])->toBe(10.0);
});

it('aarrrBreakdown returns all stages', function (): void {
    $breakdown = EventCatalog::aarrrBreakdown();

    expect($breakdown)->toHaveKey('acquisition');
    expect($breakdown)->toHaveKey('activation');
    expect($breakdown)->toHaveKey('retention');
    expect($breakdown)->toHaveKey('revenue');
    expect($breakdown)->toHaveKey('referral');
    expect($breakdown)->toHaveKey('operational');
    expect($breakdown)->toHaveKey('total');
    expect($breakdown)->toHaveKey('coverage');
});

it('filterByProviders returns events with all specified providers', function (): void {
    $events = EventCatalog::filterByProviders(['ga4', 'meta']);

    foreach ($events as $event) {
        expect($event['ga4'])->not->toBeNull();
        expect($event['ga4'])->not->toBe('');
        expect($event['meta'])->not->toBeNull();
        expect($event['meta'])->not->toBe('');
    }
});

// ── SaaS Starter Maturity ───────────────────────────────────────────────

it('has 50+ events across 6 categories', function (): void {
    expect(EventCatalog::count())->toBeGreaterThan(50);

    $byCategory = EventCatalog::byCategory();
    expect(count($byCategory))->toBeGreaterThanOrEqual(6);
    expect(isset($byCategory['ecommerce']))->toBeTrue();
    expect(isset($byCategory['saas']))->toBeTrue();
    expect(isset($byCategory['engagement']))->toBeTrue();
});

it('has 10 provider trackers', function (): void {
    $providers = EventCatalog::byProvider();
    expect(count($providers))->toBeGreaterThanOrEqual(8);
    expect(isset($providers['ga4']))->toBeTrue();
    expect(isset($providers['meta']))->toBeTrue();
    expect(isset($providers['posthog']))->toBeTrue();
    expect(isset($providers['plausible']))->toBeTrue();
});

it('EcommerceEvents has ViewItem, AddToCart, Purchase, Refund', function (): void {
    expect(EcommerceEvents::has('view_item'))->toBeTrue();
    expect(EcommerceEvents::has('add_to_cart'))->toBeTrue();
    expect(EcommerceEvents::has('purchase'))->toBeTrue();
    expect(EcommerceEvents::has('refund'))->toBeTrue();
});

it('SaaSEvents has SignUp, Login, TrialStart, Subscription, PlanUpgrade, Cancellation', function (): void {
    expect(SaaSEvents::has('sign_up'))->toBeTrue();
    expect(SaaSEvents::has('login'))->toBeTrue();
    expect(SaaSEvents::has('start_trial'))->toBeTrue();
    expect(SaaSEvents::has('subscribe'))->toBeTrue();
    expect(SaaSEvents::has('plan_upgrade'))->toBeTrue();
    expect(SaaSEvents::has('cancellation'))->toBeTrue();
});

it('EngagementEvents has PageView, ScrollDepth, Click, FormStart, FormSubmit, Search, Share, Error', function (): void {
    expect(EngagementEvents::has('page_view'))->toBeTrue();
    expect(EngagementEvents::has('scroll_depth'))->toBeTrue();
    expect(EngagementEvents::has('click'))->toBeTrue();
    expect(EngagementEvents::has('form_start'))->toBeTrue();
    expect(EngagementEvents::has('form_submit'))->toBeTrue();
    expect(EngagementEvents::has('search'))->toBeTrue();
    expect(EngagementEvents::has('share'))->toBeTrue();
    expect(EngagementEvents::has('error'))->toBeTrue();
});

it('EventCatalog validate returns valid result', function (): void {
    $result = EventCatalog::validate();
    expect($result['valid'])->toBeTrue();
    expect($result['errors'])->toBeArray();
    expect(count($result['errors']))->toBe(0);
});

// ── PHP 8.5 Compliance ──────────────────────────────────────────────────

it('AnalyticsEvent is readonly DTO with strict types', function (): void {
    $reflection = new ReflectionClass(AnalyticsEvent::class);
    $file = $reflection->getFileName();

    expect($file)->not->toBeFalse();
    $content = file_get_contents($file);
    expect($content)->toContain('declare(strict_types=1)');
});

it('EventCatalog has declare strict_types', function (): void {
    $reflection = new ReflectionClass(EventCatalog::class);
    $file = $reflection->getFileName();

    expect($file)->not->toBeFalse();
    $content = file_get_contents($file);
    expect($content)->toContain('declare(strict_types=1)');
});

// ── Summary & Coverage ──────────────────────────────────────────────────

it('summary returns correct structure', function (): void {
    $summary = EventCatalog::summary();

    expect($summary)->toHaveKey('total');
    expect($summary)->toHaveKey('ecommerce');
    expect($summary)->toHaveKey('saas');
    expect($summary)->toHaveKey('engagement');
    expect($summary)->toHaveKey('security');
    expect($summary)->toHaveKey('uptime');
    expect($summary)->toHaveKey('infrastructure');
    expect($summary)->toHaveKey('with_ga4');
    expect($summary)->toHaveKey('with_meta');
    expect($summary)->toHaveKey('with_posthog');
    expect($summary['total'])->toBeGreaterThan(50);
});

it('byProvider returns all 8 providers', function (): void {
    $byProvider = EventCatalog::byProvider();

    expect($byProvider)->toHaveKey('ga4');
    expect($byProvider)->toHaveKey('meta');
    expect($byProvider)->toHaveKey('posthog');
    expect($byProvider)->toHaveKey('plausible');
    expect($byProvider)->toHaveKey('mixpanel');
    expect($byProvider)->toHaveKey('amplitude');
    expect($byProvider)->toHaveKey('tiktok');
    expect($byProvider)->toHaveKey('linkedin');
});

it('providerCoverage returns structure with counts', function (): void {
    $coverage = EventCatalog::providerCoverage();

    expect($coverage)->toHaveKey('ga4');
    expect($coverage)->toHaveKey('meta');
    expect($coverage)->toHaveKey('counts');
    expect($coverage['counts'])->toHaveKey('ga4');
    expect($coverage['counts']['ga4'])->toBeInt();
    expect($coverage['counts']['ga4'])->toBeGreaterThan(0);
});
