<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;

describe('EventCatalog Summary — Security and Uptime Categories', function () {
    it('summary includes security and uptime category counts', function () {
        $summary = EventCatalog::summary();

        expect($summary)->toHaveKey('security');
        expect($summary)->toHaveKey('uptime');
        expect($summary['security'])->toBeInt()->toBeGreaterThan(0);
        expect($summary['uptime'])->toBeInt()->toBeGreaterThan(0);
        expect($summary['total'])->toBe(
            $summary['ecommerce']
            + $summary['saas']
            + $summary['engagement']
            + $summary['security']
            + $summary['uptime'],
        );
    });

    it('summary total matches sum of all categories', function () {
        $summary = EventCatalog::summary();

        expect($summary['total'])->toBe(EventCatalog::count());
    });
});

describe('EventCatalog Billing Events — Expanded Coverage', function () {
    it('includes plan_changed in billing events', function () {
        $billing = EventCatalog::billingEvents();
        $names = array_column($billing, 'name');

        expect($names)->toContain('plan_changed');
    });

    it('includes subscription lifecycle events in billing events', function () {
        $billing = EventCatalog::billingEvents();
        $names = array_column($billing, 'name');

        expect($names)->toContain('subscription_resumed');
        expect($names)->toContain('subscription_paused');
        expect($names)->toContain('subscription_created');
        expect($names)->toContain('subscription_cancelled');
        expect($names)->toContain('plan_upgrade');
        expect($names)->toContain('plan_downgrade');
        expect($names)->toContain('cancellation');
    });

    it('includes core billing events', function () {
        $billing = EventCatalog::billingEvents();
        $names = array_column($billing, 'name');

        expect($names)->toContain('payment_succeeded');
        expect($names)->toContain('payment_failed');
        expect($names)->toContain('invoice_generated');
        expect($names)->toContain('credit_applied');
        expect($names)->toContain('billing_retry');
    });

    it('all billing events have valid catalog entries', function () {
        $billing = EventCatalog::billingEvents();

        foreach ($billing as $event) {
            expect($event)->toHaveKey('name');
            expect($event)->toHaveKey('class');
            expect($event)->toHaveKey('ga4');
            expect($event['class'])->toBeString();
            expect($event['ga4'])->toBeString();
        }
    });
});

describe('EventCatalog Category Coverage', function () {
    it('security category has valid events', function () {
        $security = EventCatalog::category('security');
        expect($security)->not->toBeEmpty();

        foreach ($security as $event) {
            expect($event['category'])->toBe('security');
        }
    });

    it('uptime category has valid events', function () {
        $uptime = EventCatalog::category('uptime');
        expect($uptime)->not->toBeEmpty();

        foreach ($uptime as $event) {
            expect($event['category'])->toBe('uptime');
        }
    });

    it('all categories have non-zero events', function () {
        $byCategory = EventCatalog::byCategory();

        expect($byCategory['ecommerce'])->not->toBeEmpty();
        expect($byCategory['saas'])->not->toBeEmpty();
        expect($byCategory['engagement'])->not->toBeEmpty();
        expect($byCategory['security'])->not->toBeEmpty();
        expect($byCategory['uptime'])->not->toBeEmpty();
    });

    it('getCategory returns correct category for each domain', function () {
        expect(EventCatalog::getCategory('view_item'))->toBe('ecommerce');
        expect(EventCatalog::getCategory('sign_up'))->toBe('saas');
        expect(EventCatalog::getCategory('page_view'))->toBe('engagement');
        expect(EventCatalog::getCategory('login_attempt'))->toBe('security');
        expect(EventCatalog::getCategory('service_down'))->toBe('uptime');
    });

    it('getCategory returns null for unknown events', function () {
        expect(EventCatalog::getCategory('nonexistent_event_xyz'))->toBeNull();
    });
});

describe('EventCatalog Provider Coverage for All Categories', function () {
    it('all security events have GA4 mapping', function () {
        $security = EventCatalog::category('security');

        foreach ($security as $event) {
            expect($event['ga4'])->toBeString()->not->toBeEmpty();
        }
    });

    it('all uptime events have GA4 mapping', function () {
        $uptime = EventCatalog::category('uptime');

        foreach ($uptime as $event) {
            expect($event['ga4'])->toBeString()->not->toBeEmpty();
        }
    });

    it('provider coverage includes all 4 providers', function () {
        $coverage = EventCatalog::providerCoverage();

        expect($coverage)->toHaveKey('ga4');
        expect($coverage)->toHaveKey('meta');
        expect($coverage)->toHaveKey('posthog');
        expect($coverage)->toHaveKey('plausible');
        expect($coverage)->toHaveKey('counts');

        expect($coverage['counts']['ga4'])->toBeGreaterThan(0);
        expect($coverage['counts']['posthog'])->toBeGreaterThan(0);
    });
});
