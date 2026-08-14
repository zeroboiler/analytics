<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\EventTags;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEventSubCategories;

describe('EventTags', function () {
    describe('::for()', function () {
        it('returns tags for purchase event', function () {
            $tags = EventTags::for('purchase');

            expect($tags)->toContain('ecommerce', 'revenue', 'conversion', 'critical', 'pii');
        });

        it('returns tags for sign_up event', function () {
            $tags = EventTags::for('sign_up');

            expect($tags)->toContain('acquisition', 'conversion', 'pii', 'critical', 'gdpr', 'funnel', 'onboarding');
        });

        it('returns tags for page_view event', function () {
            $tags = EventTags::for('page_view');

            expect($tags)->toContain('engagement', 'privacy_safe', 'samplable', 'session', 'funnel');
        });

        it('returns empty array for unknown event', function () {
            expect(EventTags::for('nonexistent_event_xyz'))->toBe([]);
        });
    });

    describe('::has()', function () {
        it('returns true for existing tag', function () {
            expect(EventTags::has('purchase', 'revenue'))->toBeTrue();
        });

        it('returns false for non-existing tag', function () {
            expect(EventTags::has('purchase', 'bogus_tag'))->toBeFalse();
        });

        it('returns false for unknown event', function () {
            expect(EventTags::has('nonexistent', 'revenue'))->toBeFalse();
        });
    });

    describe('::tagged()', function () {
        it('returns all revenue events', function () {
            $events = EventTags::tagged('revenue');

            expect($events)->toContain('purchase', 'subscribe', 'add_to_cart', 'refund', 'payment_succeeded');
        });

        it('returns all critical events', function () {
            $events = EventTags::tagged('critical');

            expect($events)->toContain('sign_up', 'login', 'subscribe', 'purchase', 'cancellation');
        });

        it('returns all pii events', function () {
            $events = EventTags::tagged('pii');

            expect($events)->toContain('sign_up', 'login', 'add_payment_info', 'password_changed');
        });

        it('returns all privacy_safe events', function () {
            $events = EventTags::tagged('privacy_safe');

            expect($events)->toContain('page_view', 'scroll_depth', 'click', 'search');
        });

        it('returns empty array for unknown tag', function () {
            expect(EventTags::tagged('nonexistent_tag_xyz'))->toBe([]);
        });
    });

    describe('::allTags()', function () {
        it('returns all unique tags', function () {
            $tags = EventTags::allTags();

            expect($tags)->toContain(
                'revenue', 'pii', 'critical', 'conversion', 'retention',
                'engagement', 'acquisition', 'authentication', 'billing',
                'gdpr', 'privacy_safe', 'samplable', 'funnel', 'b2b',
                'onboarding', 'ecommerce', 'plg', 'enterprise',
                'performance', 'session', 'consent', 'cohort',
            );
        });

        it('has more than 15 unique tags', function () {
            expect(count(EventTags::allTags()))->toBeGreaterThan(15);
        });
    });

    describe('::groupedByTag()', function () {
        it('groups events by tag', function () {
            $grouped = EventTags::groupedByTag();

            expect(isset($grouped['revenue']))->toBeTrue();
            expect($grouped['revenue'])->toContain('purchase', 'subscribe', 'add_to_cart');
        });

        it('sorts events within each group alphabetically', function () {
            $grouped = EventTags::groupedByTag();

            $revenueEvents = $grouped['revenue'];
            $sorted = $revenueEvents;
            sort($sorted);

            expect($revenueEvents)->toBe($sorted);
        });
    });

    describe('::whereAll() — AND logic', function () {
        it('returns events matching all tags', function () {
            $events = EventTags::whereAll(['revenue', 'conversion', 'critical']);

            expect($events)->toContain('add_to_cart', 'begin_checkout', 'purchase');
        });

        it('returns empty if no events match all tags', function () {
            $events = EventTags::whereAll(['revenue', 'session']);

            expect($events)->toBe([]);
        });

        it('returns empty for empty tag list', function () {
            expect(EventTags::whereAll([]))->toBe([]);
        });
    });

    describe('::whereAny() — OR logic', function () {
        it('returns events matching any tag', function () {
            $events = EventTags::whereAny(['billing', 'session']);

            expect($events)->toContain('subscribe', 'payment_succeeded', 'login', 'session_start', 'session_end');
        });

        it('returns empty for empty tag list', function () {
            expect(EventTags::whereAny([]))->toBe([]);
        });
    });

    describe('::stats()', function () {
        it('returns tag counts', function () {
            $stats = EventTags::stats();

            expect(isset($stats['revenue']))->toBeTrue();
            expect($stats['revenue'])->toBeGreaterThan(5);
            expect(isset($stats['critical']))->toBeTrue();
            expect($stats['critical'])->toBeGreaterThan(5);
        });
    });

    describe('::tagCount()', function () {
        it('returns number of tags for an event', function () {
            expect(EventTags::tagCount('purchase'))->toBe(5);
            expect(EventTags::tagCount('nonexistent'))->toBe(0);
        });
    });

    describe('::addTag() — runtime tag mutation', function () {
        it('adds a tag to an event at runtime', function () {
            EventTags::addTag('custom_test_event', 'custom_tag');

            expect(EventTags::has('custom_test_event', 'custom_tag'))->toBeTrue();
            expect(EventTags::for('custom_test_event'))->toContain('custom_tag');

            // Cleanup
            EventTags::addTag('custom_test_event', ''); // no-op for cleanup test
        });

        it('does not add duplicate tags', function () {
            EventTags::addTag('purchase', 'revenue');

            $count = count(array_filter(EventTags::for('purchase'), fn ($t) => $t === 'revenue'));

            expect($count)->toBe(1);
        });
    });
});

describe('SaaSEventSubCategories', function () {
    describe('::names()', function () {
        it('returns all sub-category names', function () {
            $names = SaaSEventSubCategories::names();

            expect($names)->toContain('auth', 'subscription', 'trial', 'billing', 'team', 'account', 'growth');
        });

        it('returns more than 10 sub-categories', function () {
            expect(count(SaaSEventSubCategories::names()))->toBeGreaterThan(10);
        });
    });

    describe('::events()', function () {
        it('returns auth events', function () {
            $events = SaaSEventSubCategories::events('auth');

            expect($events)->toContain('sign_up', 'login', 'logout', 'email_verified');
        });

        it('returns billing events', function () {
            $events = SaaSEventSubCategories::events('billing');

            expect($events)->toContain('payment_succeeded', 'payment_failed', 'invoice_generated');
        });

        it('returns empty array for unknown sub-category', function () {
            expect(SaaSEventSubCategories::events('nonexistent_xyz'))->toBe([]);
        });
    });

    describe('::subcategoryFor()', function () {
        it('returns sub-category for sign_up', function () {
            expect(SaaSEventSubCategories::subcategoryFor('sign_up'))->toBe('auth');
        });

        it('returns sub-category for payment_succeeded', function () {
            expect(SaaSEventSubCategories::subcategoryFor('payment_succeeded'))->toBe('billing');
        });

        it('returns null for unknown event', function () {
            expect(SaaSEventSubCategories::subcategoryFor('nonexistent_xyz'))->toBeNull();
        });
    });

    describe('::belongsTo()', function () {
        it('returns true for correct sub-category', function () {
            expect(SaaSEventSubCategories::belongsTo('login', 'auth'))->toBeTrue();
        });

        it('returns false for wrong sub-category', function () {
            expect(SaaSEventSubCategories::belongsTo('login', 'billing'))->toBeFalse();
        });
    });

    describe('::allEventNames()', function () {
        it('returns all SaaS events as flat list', function () {
            $allNames = SaaSEventSubCategories::allEventNames();

            expect($allNames)->toContain('sign_up', 'subscribe', 'payment_succeeded', 'team_created');
            expect(count($allNames))->toBeGreaterThan(40);
        });
    });

    describe('::counts()', function () {
        it('returns event counts per sub-category', function () {
            $counts = SaaSEventSubCategories::counts();

            expect(isset($counts['auth']))->toBeTrue();
            expect($counts['auth'])->toBe(6);
            expect($counts['subscription'])->toBeGreaterThan(8);
        });
    });

    describe('::catalogEntries()', function () {
        it('returns full catalog entries for a sub-category', function () {
            $entries = SaaSEventSubCategories::catalogEntries('auth');

            expect(count($entries))->toBeGreaterThan(0);

            foreach ($entries as $entry) {
                expect(isset($entry['name']))->toBeTrue();
                expect(isset($entry['class']))->toBeTrue();
                expect($entry['subcategory'])->toBe('auth');
            }
        });

        it('returns empty for unknown sub-category', function () {
            expect(SaaSEventSubCategories::catalogEntries('nonexistent_xyz'))->toBe([]);
        });
    });

    describe('::grouped()', function () {
        it('returns all sub-categories with entries', function () {
            $grouped = SaaSEventSubCategories::grouped();

            expect(isset($grouped['auth']))->toBeTrue();
            expect(isset($grouped['billing']))->toBeTrue();
            expect(isset($grouped['team']))->toBeTrue();
            expect(count($grouped))->toBe(count(SaaSEventSubCategories::names()));
        });
    });
});

describe('EventCatalog tag integration', function () {
    it('resolves tags via EventCatalog::tagsFor()', function () {
        $tags = EventCatalog::tagsFor('purchase');

        expect($tags)->toContain('revenue', 'critical');
    });

    it('returns tagged events with full entries via EventCatalog::tagged()', function () {
        $entries = EventCatalog::tagged('revenue');

        expect(count($entries))->toBeGreaterThan(5);

        foreach ($entries as $entry) {
            expect(isset($entry['name']))->toBeTrue();
            expect(isset($entry['category']))->toBeTrue();
        }
    });

    it('supports AND logic via EventCatalog::taggedAll()', function () {
        $entries = EventCatalog::taggedAll(['revenue', 'critical']);

        expect(count($entries))->toBeGreaterThan(2);
    });

    it('supports OR logic via EventCatalog::taggedAny()', function () {
        $entries = EventCatalog::taggedAny(['gdpr', 'b2b']);

        expect(count($entries))->toBeGreaterThan(5);
    });

    it('checks tag membership via EventCatalog::hasTag()', function () {
        expect(EventCatalog::hasTag('purchase', 'revenue'))->toBeTrue();
        expect(EventCatalog::hasTag('purchase', 'b2b'))->toBeFalse();
    });

    it('returns all tags via EventCatalog::allTags()', function () {
        $tags = EventCatalog::allTags();

        expect(count($tags))->toBeGreaterThan(15);
    });

    it('groups events by tag via EventCatalog::groupedByTag()', function () {
        $grouped = EventCatalog::groupedByTag();

        expect(isset($grouped['revenue']))->toBeTrue();
        expect(isset($grouped['critical']))->toBeTrue();
    });
});

describe('EventCatalog SaaS sub-category integration', function () {
    it('returns SaaS sub-category names via EventCatalog::saasSubCategories()', function () {
        $names = EventCatalog::saasSubCategories();

        expect($names)->toContain('auth', 'subscription', 'billing', 'team');
    });

    it('returns SaaS sub-category entries via EventCatalog::saasSubCategory()', function () {
        $entries = EventCatalog::saasSubCategory('auth');

        expect(count($entries))->toBeGreaterThan(0);
    });

    it('returns SaaS grouped entries via EventCatalog::saasGrouped()', function () {
        $grouped = EventCatalog::saasGrouped();

        expect(isset($grouped['auth']))->toBeTrue();
        expect(isset($grouped['billing']))->toBeTrue();
    });

    it('resolves sub-category for SaaS event via EventCatalog::saasSubCategoryFor()', function () {
        expect(EventCatalog::saasSubCategoryFor('sign_up'))->toBe('auth');
        expect(EventCatalog::saasSubCategoryFor('payment_succeeded'))->toBe('billing');
        expect(EventCatalog::saasSubCategoryFor('team_created'))->toBe('team');
        expect(EventCatalog::saasSubCategoryFor('nonexistent_xyz'))->toBeNull();
    });
});

describe('V98 Version Consistency Sweep', function () {
    test('all source files reference version 98.0.0', function () {
        $files = [
            __DIR__ . '/../../composer.json',
            __DIR__ . '/../../src/DTO/AnalyticsEvent.php',
            __DIR__ . '/../../resources/js/analytics.js',
            __DIR__ . '/../../resources/js/analytics.d.ts',
            __DIR__ . '/../../resources/js/useAnalytics.svelte.js',
            __DIR__ . '/../../resources/js/useAnalyticsConfig.svelte.js',
            __DIR__ . '/../../resources/js/useLifecycle.svelte.js',
            __DIR__ . '/../../resources/js/usePerformanceTracker.svelte.js',
            __DIR__ . '/../../resources/js/useSessionReplay.svelte.js',
        ];

        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $content = file_get_contents($file);

            // Old versions should not be present
            expect(str_contains($content, '96.0.0'))->toBeFalse("Old version 96.0.0 still in: {$file}");
            expect(str_contains($content, '97.0.0'))->toBeFalse("Old version 97.0.0 still in: {$file}");

            // Check that 98.0.0 IS present (except analytics.d.ts which may not have version)
            if (! str_contains($file, 'analytics.d.ts')) {
                expect(str_contains($content, '98.0.0'))->toBeTrue("Version 98.0.0 missing in: {$file}");
            }
        }
    });

    test('AnalyticsEvent::VERSION matches composer.json version', function () {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        $dtoVersion = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION;

        expect($composer['version'])->toBe('98.0.0');
        expect($dtoVersion)->toBe('98.0.0');
        expect($composer['version'])->toBe($dtoVersion);
    });

    test('README version badge matches package version', function () {
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        expect(str_contains($readme, 'version-98.0.0'))->toBeTrue();
        expect(str_contains($readme, 'version-97.0.0'))->toBeFalse();
    });

    test('README contains v98.0.0 release notes', function () {
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        expect(str_contains($readme, 'What\'s New in v98.0.0'))->toBeTrue();
        expect(str_contains($readme, 'EventTags'))->toBeTrue();
        expect(str_contains($readme, 'SaaSEventSubCategories'))->toBeTrue();
    });
});
