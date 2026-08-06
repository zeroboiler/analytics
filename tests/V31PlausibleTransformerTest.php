<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Support\EventTransformer;

describe('EventTransformer Plausible Support', function () {
    it('maps page_view to pageview for Plausible', function () {
        expect(EventTransformer::toPlausibleEventName('page_view'))->toBe('pageview');
    });

    it('returns null for unsupported Plausible events', function () {
        expect(EventTransformer::toPlausibleEventName('scroll_depth'))->toBeNull();
        expect(EventTransformer::toPlausibleEventName('session_start'))->toBeNull();
        expect(EventTransformer::toPlausibleEventName('form_start'))->toBeNull();
    });

    it('passes through custom events unchanged', function () {
        expect(EventTransformer::toPlausibleEventName('purchase'))->toBe('purchase');
        expect(EventTransformer::toPlausibleEventName('sign_up'))->toBe('sign_up');
    });

    it('includes select events in Plausible map', function () {
        $map = EventTransformer::toPlausibleEventMap();
        expect(isset($map['scroll_depth']))->toBeTrue();
        expect(isset($map['page_view']))->toBeTrue();
    });
});

describe('EventTransformer Expanded Ecommerce', function () {
    it('includes select_item in Meta event map', function () {
        $hasMeta = EventTransformer::hasMetaEquivalent('select_item');
        // select_item has no Meta equivalent
        expect($hasMeta)->toBeFalse();
    });

    it('includes view_promotion in Meta event map', function () {
        $hasMeta = EventTransformer::hasMetaEquivalent('view_promotion');
        expect($hasMeta)->toBeFalse();
    });

    it('add_to_wishlist still has Meta equivalent', function () {
        expect(EventTransformer::hasMetaEquivalent('add_to_wishlist'))->toBeTrue();
        expect(EventTransformer::ga4ToMetaEventName('add_to_wishlist'))->toBe('AddToWishlist');
    });

    it('transforms purchase correctly for Meta', function () {
        $metaName = EventTransformer::ga4ToMetaEventName('purchase');
        expect($metaName)->toBe('Purchase');
    });
});
