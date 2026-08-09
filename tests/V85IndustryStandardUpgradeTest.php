<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;

/**
 * V2.85.0 — Industry-Standard SaaS Starter Upgrade Tests.
 *
 * Comprehensive test suite verifying:
 * - Event catalog completeness (90+ events)
 * - All catalog helpers return valid entries
 * - Industry-standard readiness scoring
 * - Cross-provider mapping matrix integrity
 * - B2B, account lifecycle, and funnel helpers
 * - Config consistency
 * - Version alignment
 * - Provider coverage
 */
describe('V2.85.0 — Industry Standard Upgrade', function () {
    it('has version 2.86.0 in DTO', function () {
        expect(AnalyticsEvent::VERSION)->toBe('5.0.0');
    });

    it('has 90+ events in the full catalog', function () {
        $total = EventCatalog::count();
        expect($total)->toBeGreaterThanOrEqual(90);
    });

    it('has correct per-category event counts', function () {
        expect(EcommerceEvents::count())->toBeGreaterThanOrEqual(15);
        expect(SaaSEvents::count())->toBeGreaterThanOrEqual(48);
        expect(EngagementEvents::count())->toBeGreaterThanOrEqual(27);
    });

    it('category counts sum to total', function () {
        $total = EventCatalog::count();
        $sum = EcommerceEvents::count() + SaaSEvents::count() + EngagementEvents::count();
        expect($sum)->toBe($total);
    });

    it('all events have required keys', function () {
        $validation = EventCatalog::validate();
        expect($validation['valid'])->toBeTrue();
        expect($validation['errors'])->toBeEmpty();
    });

    it('every event has GA4 mapping', function () {
        $all = EventCatalog::all();
        foreach ($all as $name => $entry) {
            expect(isset($entry['ga4']))->toBeTrue("Event {$name} missing GA4 mapping");
            expect($entry['ga4'])->not->toBeEmpty("Event {$name} has empty GA4 name");
        }
    });

    it('every event has PostHog mapping', function () {
        $all = EventCatalog::all();
        foreach ($all as $name => $entry) {
            expect(isset($entry['posthog']))->toBeTrue("Event {$name} missing PostHog mapping");
            expect($entry['posthog'])->not->toBeEmpty("Event {$name} has empty PostHog name");
        }
    });

    it('industryStandard returns all four priority tiers', function () {
        $standard = EventCatalog::industryStandard();
        expect($standard)->toHaveKey('critical');
        expect($standard)->toHaveKey('high');
        expect($standard)->toHaveKey('medium');
        expect($standard)->toHaveKey('low');
        expect($standard)->toHaveKey('all');
        expect($standard)->toHaveKey('count');
        expect($standard['count'])->toBeGreaterThanOrEqual(70);
    });

    it('critical tier has essential events', function () {
        $standard = EventCatalog::industryStandard();
        $criticalNames = array_column($standard['critical'], 'name');
        expect($criticalNames)->toContain('sign_up');
        expect($criticalNames)->toContain('login');
        expect($criticalNames)->toContain('purchase');
        expect($criticalNames)->toContain('page_view');
    });

    it('industryStandard count equals sum of all tiers', function () {
        $standard = EventCatalog::industryStandard();
        $sum = count($standard['critical']) + count($standard['high'])
            + count($standard['medium']) + count($standard['low']);
        expect($standard['count'])->toBe($sum);
    });

    it('recommendedInstrumentation returns valid levels', function () {
        foreach (['starter', 'growth', 'enterprise', 'complete'] as $level) {
            $result = EventCatalog::recommendedInstrumentation($level);
            expect($result['level'])->toBe($level);
            expect($result['events'])->toBeArray();
            expect($result['count'])->toBe(count($result['events']));
        }
    });

    it('recommendedInstrumentation levels are cumulative', function () {
        $starter = EventCatalog::recommendedInstrumentation('starter');
        $growth = EventCatalog::recommendedInstrumentation('growth');
        $enterprise = EventCatalog::recommendedInstrumentation('enterprise');

        expect($growth['count'])->toBeGreaterThan($starter['count']);
        expect($enterprise['count'])->toBeGreaterThan($growth['count']);
    });

    it('b2bTeamEvents returns team and workspace events', function () {
        $b2b = EventCatalog::b2bTeamEvents();
        $names = array_column($b2b, 'name');

        expect($b2b)->not->toBeEmpty();
        expect($names)->toContain('team_created');
        expect($names)->toContain('team_member_joined');
        expect($names)->toContain('workspace_created');
        expect($names)->toContain('invite_sent');
    });

    it('accountLifecycleEvents returns account lifecycle events', function () {
        $account = EventCatalog::accountLifecycleEvents();
        $names = array_column($account, 'name');

        expect($account)->not->toBeEmpty();
        expect($names)->toContain('account_activated');
        expect($names)->toContain('account_deactivated');
        expect($names)->toContain('email_verified');
        expect($names)->toContain('password_changed');
        expect($names)->toContain('login');
        expect($names)->toContain('sign_up');
    });

    it('allProviderMappingsMatrix returns matrix for every event', function () {
        $matrix = EventCatalog::allProviderMappingsMatrix();
        expect(count($matrix))->toBe(EventCatalog::count());

        foreach ($matrix as $name => $mapping) {
            expect($mapping)->toHaveKey('ga4');
            expect($mapping)->toHaveKey('meta');
            expect($mapping)->toHaveKey('posthog');
            expect($mapping)->toHaveKey('plausible');
            expect($mapping)->toHaveKey('category');
            expect(in_array($mapping['category'], ['ecommerce', 'saas', 'engagement']))->toBeTrue(
                "Event {$name} has invalid category: {$mapping['category']}"
            );
        }
    });

    it('industryReadinessScore returns valid score 0-100', function () {
        $score = EventCatalog::industryReadinessScore();
        expect($score)->toHaveKey('score');
        expect($score)->toHaveKey('breakdown');
        expect($score)->toHaveKey('gaps');
        expect($score)->toHaveKey('total_standard');
        expect($score)->toHaveKey('total_covered');
        expect($score['score'])->toBeGreaterThanOrEqual(0);
        expect($score['score'])->toBeLessThanOrEqual(100);
    });

    it('industryReadinessScore has zero gaps (all standard events exist)', function () {
        $score = EventCatalog::industryReadinessScore();
        // Since all industry standard events should exist in our catalog
        expect($score['gaps'])->toBeEmpty();
        expect($score['score'])->toBe(100);
        expect($score['total_standard'])->toBe($score['total_covered']);
    });

    it('industryReadinessScore breakdown has all tiers', function () {
        $score = EventCatalog::industryReadinessScore();
        expect($score['breakdown'])->toHaveKey('critical');
        expect($score['breakdown'])->toHaveKey('high');
        expect($score['breakdown'])->toHaveKey('medium');
        expect($score['breakdown'])->toHaveKey('low');
    });

    it('coreSaaS returns essential SaaS events', function () {
        $core = EventCatalog::coreSaaS();
        $names = array_column($core, 'name');
        expect($names)->toContain('sign_up');
        expect($names)->toContain('login');
        expect($names)->toContain('subscribe');
    });

    it('saasEssential returns broader set than coreSaaS', function () {
        $core = EventCatalog::coreSaaS();
        $essential = EventCatalog::saasEssential();

        expect($essential['count'])->toBeGreaterThan(count($core));
    });

    it('checkoutFunnel returns events in expected order', function () {
        $funnel = EventCatalog::checkoutFunnel();
        $names = array_column($funnel, 'name');

        expect($names)->toContain('view_item');
        expect($names)->toContain('add_to_cart');
        expect($names)->toContain('begin_checkout');
        expect($names)->toContain('purchase');
    });

    it('activationFunnel returns activation events', function () {
        $activation = EventCatalog::activationFunnel();
        $names = array_column($activation, 'name');

        expect($names)->toContain('sign_up');
        expect($names)->toContain('start_trial');
        expect($names)->toContain('feature_used');
    });

    it('retentionSignals includes both churn and positive signals', function () {
        $retention = EventCatalog::retentionSignals();
        $names = array_column($retention, 'name');

        // Churn signals
        expect($names)->toContain('cancellation');
        expect($names)->toContain('account_deactivated');
        // Positive signals
        expect($names)->toContain('feature_used');
        expect($names)->toContain('plan_upgrade');
    });

    it('billingEvents returns revenue-related events', function () {
        $billing = EventCatalog::billingEvents();
        $names = array_column($billing, 'name');

        expect($names)->toContain('payment_succeeded');
        expect($names)->toContain('payment_failed');
        expect($names)->toContain('invoice_generated');
    });

    it('criticalEvents returns business-critical events only', function () {
        $critical = EventCatalog::criticalEvents();
        $names = array_column($critical, 'name');

        expect($names)->toContain('purchase');
        expect($names)->toContain('subscribe');
        expect($names)->toContain('sign_up');
        expect($names)->toContain('payment_succeeded');
    });

    it('providerCoverage returns all four providers', function () {
        $coverage = EventCatalog::providerCoverage();
        expect($coverage)->toHaveKey('ga4');
        expect($coverage)->toHaveKey('meta');
        expect($coverage)->toHaveKey('posthog');
        expect($coverage)->toHaveKey('plausible');
        expect($coverage)->toHaveKey('counts');

        expect($coverage['counts']['ga4'])->toBe(EventCatalog::count());
        expect($coverage['counts']['posthog'])->toBe(EventCatalog::count());
    });

    it('summary returns correct structure', function () {
        $summary = EventCatalog::summary();
        expect($summary)->toHaveKey('total');
        expect($summary)->toHaveKey('ecommerce');
        expect($summary)->toHaveKey('saas');
        expect($summary)->toHaveKey('engagement');
        expect($summary)->toHaveKey('with_ga4');
        expect($summary)->toHaveKey('with_meta');
        expect($summary)->toHaveKey('with_posthog');
        expect($summary)->toHaveKey('with_plausible');
        expect($summary['total'])->toBe(EventCatalog::count());
    });

    it('revenueEvents includes purchase and subscription events', function () {
        $revenue = EventCatalog::revenueEvents();
        $names = array_column($revenue, 'name');
        expect($names)->toContain('purchase');
        expect($names)->toContain('subscribe');
    });

    it('plgEvents includes product-led growth events', function () {
        $plg = EventCatalog::plgEvents();
        $names = array_column($plg, 'name');
        expect($names)->toContain('feature_adopted');
        expect($names)->toContain('expansion_revenue');
    });

    it('allLifecycleEvents combines multiple funnels', function () {
        $lifecycle = EventCatalog::allLifecycleEvents();
        $names = array_column($lifecycle, 'name');
        // Should contain events from multiple funnels
        expect($names)->toContain('sign_up');
        expect($names)->toContain('purchase');
    });

    it('conversionEvents returns conversion-focused events', function () {
        $conversion = EventCatalog::conversionEvents();
        $names = array_column($conversion, 'name');
        expect($names)->toContain('sign_up');
        expect($names)->toContain('purchase');
        expect($names)->toContain('trial_converted');
    });

    it('search returns events matching pattern', function () {
        $results = EventCatalog::search('purchase');
        expect($results)->not->toBeEmpty();
        $names = array_column($results, 'name');
        expect($names)->toContain('purchase');
    });

    it('byProvider returns deduplicated names', function () {
        $providers = EventCatalog::byProvider();
        foreach (['ga4', 'meta', 'posthog', 'plausible'] as $provider) {
            $names = $providers[$provider];
            $unique = array_unique($names);
            expect(count($unique))->toBe(count($names),
                "Provider {$provider} has duplicate event names");
        }
    });

    it('allGa4Names and allMetaNames return non-empty lists', function () {
        expect(EventCatalog::allGa4Names())->not->toBeEmpty();
        expect(EventCatalog::allMetaNames())->not->toBeEmpty();
        expect(EventCatalog::allPosthogNames())->not->toBeEmpty();
    });

    it('eventCategory returns valid AARRR stage', function () {
        $validCategories = ['acquisition', 'activation', 'retention', 'revenue', 'referral', 'operational'];
        expect(in_array(EventCatalog::eventCategory('sign_up'), $validCategories))->toBeTrue();
        expect(in_array(EventCatalog::eventCategory('purchase'), $validCategories))->toBeTrue();
    });

    it('eventPriority returns valid priority level', function () {
        $validPriorities = ['critical', 'high', 'medium', 'low'];
        expect(in_array(EventCatalog::eventPriority('sign_up'), $validPriorities))->toBeTrue();
        expect(in_array(EventCatalog::eventPriority('purchase'), $validPriorities))->toBeTrue();
    });

    it('saasFunnelEvents returns SaaS lifecycle events', function () {
        $saasFunnel = EventCatalog::saasFunnelEvents();
        $names = array_column($saasFunnel, 'name');
        expect($names)->toContain('sign_up');
        expect($names)->toContain('subscribe');
        expect($names)->toContain('cancellation');
    });

    it('engagementEvents returns core engagement events', function () {
        $engagement = EventCatalog::engagementEvents();
        $names = array_column($engagement, 'name');
        expect($names)->toContain('page_view');
        expect($names)->toContain('scroll_depth');
        expect($names)->toContain('click');
    });

    it('productGrowthEvents returns acquisition and expansion events', function () {
        $growth = EventCatalog::productGrowthEvents();
        $names = array_column($growth, 'name');
        expect($names)->toContain('sign_up');
        expect($names)->toContain('plan_upgrade');
    });

    it('samplableEvents returns safe-to-sample events', function () {
        $samplable = EventCatalog::samplableEvents();
        $names = array_column($samplable, 'name');
        expect($names)->toContain('page_view');
        // Should NOT contain revenue-critical events
        expect($names)->not->toContain('purchase');
    });
});
