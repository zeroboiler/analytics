<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;

describe('Ecommerce Catalog Expansion', function () {
    it('includes select_item in catalog', function () {
        expect(EcommerceEvents::has('select_item'))->toBeTrue();
        expect(EcommerceEvents::count())->toBeGreaterThan(9);
    });

    it('includes select_promotion in catalog', function () {
        expect(EcommerceEvents::has('select_promotion'))->toBeTrue();
    });

    it('includes view_promotion in catalog', function () {
        expect(EcommerceEvents::has('view_promotion'))->toBeTrue();
    });

    it('has correct ga4 names for new events', function () {
        $selectItem = EcommerceEvents::get('select_item');
        expect($selectItem['ga4'])->toBe('select_item');

        $selectPromo = EcommerceEvents::get('select_promotion');
        expect($selectPromo['ga4'])->toBe('select_promotion');

        $viewPromo = EcommerceEvents::get('view_promotion');
        expect($viewPromo['ga4'])->toBe('view_promotion');
    });

    it('new events have correct classes', function () {
        expect(EcommerceEvents::classFor('select_item'))->toBe(\ZeroBoiler\Analytics\Events\Ecommerce\SelectItemEvent::class);
        expect(EcommerceEvents::classFor('select_promotion'))->toBe(\ZeroBoiler\Analytics\Events\Ecommerce\SelectPromotionEvent::class);
        expect(EcommerceEvents::classFor('view_promotion'))->toBe(\ZeroBoiler\Analytics\Events\Ecommerce\ViewPromotionEvent::class);
    });

    it('unified EventCatalog includes new events', function () {
        expect(EventCatalog::has('select_item'))->toBeTrue();
        expect(EventCatalog::has('select_promotion'))->toBeTrue();
        expect(EventCatalog::has('view_promotion'))->toBeTrue();

        $entry = EventCatalog::get('select_item');
        expect($entry['category'])->toBe('ecommerce');
    });

    it('total event count reflects new events', function () {
        // Original: 9 ecommerce + 17 saas + 20 engagement = 46
        // Now: 12 ecommerce + 17 saas + 20 engagement = 49
        expect(EventCatalog::count())->toBeGreaterThanOrEqual(49);
        expect(EcommerceEvents::count())->toBe(12);
    });
});
