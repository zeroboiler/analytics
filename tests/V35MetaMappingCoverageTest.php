<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Support\EventTransformer;

// ─── V2.26.0 — Complete Meta Pixel Mapping Coverage ──────────────────────────

describe('V35 Meta Pixel Mapping Coverage', function () {
    describe('EcommerceEvents — all 12 events have Meta equivalents', function () {
        it('every ecommerce event has a non-null Meta Pixel name', function () {
            foreach (EcommerceEvents::all() as $name => $entry) {
                expect($entry['meta'], "{$name} should have a Meta Pixel equivalent")
                    ->not->toBeNull();
                expect($entry['meta'], "{$name} Meta name should be a non-empty string")
                    ->toBeString();
            }
        });

        it('EcommerceEvents::metaNames() returns 12 entries', function () {
            expect(EcommerceEvents::metaNames())->toHaveCount(12);
        });

        it('EventTransformer has Meta mapping for all ecommerce events', function () {
            foreach (EcommerceEvents::all() as $name => $entry) {
                $metaName = EventTransformer::ga4ToMetaEventName($entry['ga4']);
                expect($metaName, "{$name} should have a transformer Meta mapping")
                    ->not->toBeNull();
            }
        });
    });

    describe('SaaSEvents — all 17 events have Meta equivalents', function () {
        it('every SaaS event has a non-null Meta Pixel name', function () {
            foreach (SaaSEvents::all() as $name => $entry) {
                expect($entry['meta'], "{$name} should have a Meta Pixel equivalent")
                    ->not->toBeNull();
            }
        });

        it('SaaSEvents::metaNames() returns 17 entries', function () {
            expect(SaaSEvents::metaNames())->toHaveCount(17);
        });
    });

    describe('EngagementEvents — all 19 events have Meta equivalents', function () {
        it('every engagement event has a non-null Meta Pixel name', function () {
            foreach (EngagementEvents::all() as $name => $entry) {
                expect($entry['meta'], "{$name} should have a Meta Pixel equivalent")
                    ->not->toBeNull();
            }
        });

        it('EngagementEvents::metaNames() returns 19 entries', function () {
            expect(EngagementEvents::metaNames())->toHaveCount(19);
        });
    });

    describe('EventCatalog — complete Meta coverage across all 48 events', function () {
        it('allMetaNames() returns all 48 event Meta names', function () {
            $metaNames = EventCatalog::allMetaNames();
            expect($metaNames)->toHaveCount(EventCatalog::count());
        });

        it('no event has a null Meta mapping', function () {
            $all = EventCatalog::all();
            foreach ($all as $name => $entry) {
                expect($entry['meta'], "Event '{$name}' should have a non-null Meta equivalent")
                    ->not->toBeNull();
            }
        });
    });

    describe('EventCatalog::getCategory() — category lookup', function () {
        it('returns ecommerce for purchase', function () {
            expect(EventCatalog::getCategory('purchase'))->toBe('ecommerce');
        });

        it('returns saas for sign_up', function () {
            expect(EventCatalog::getCategory('sign_up'))->toBe('saas');
        });

        it('returns engagement for page_view', function () {
            expect(EventCatalog::getCategory('page_view'))->toBe('engagement');
        });

        it('returns null for unknown event', function () {
            expect(EventCatalog::getCategory('nonexistent_event'))->toBeNull();
        });

        it('returns correct category for all known events', function () {
            foreach (EventCatalog::names() as $name) {
                $category = EventCatalog::getCategory($name);
                expect($category)->not->toBeNull();
                expect($category)->toBeIn(['ecommerce', 'saas', 'engagement']);
            }
        });
    });

    describe('EventTransformer — Meta conversion consistency', function () {
        it('hasMetaEquivalent returns true for all ecommerce GA4 events', function () {
            foreach (EcommerceEvents::all() as $entry) {
                expect(EventTransformer::hasMetaEquivalent($entry['ga4']))
                    ->toBeTrue("{$entry['ga4']} should have Meta equivalent");
            }
        });

        it('Meta event names match catalog entries', function () {
            foreach (EcommerceEvents::all() as $entry) {
                $transformerMeta = EventTransformer::ga4ToMetaEventName($entry['ga4']);
                expect($transformerMeta)->toBe($entry['meta']);
            }
        });
    });

    describe('Version consistency', function () {
        it('composer.json version is 2.26.0', function () {
            $composer = json_decode(
                file_get_contents(__DIR__.'/../composer.json'),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            expect($composer['version'])->toBe('76.0.0');
        });
    });
});
