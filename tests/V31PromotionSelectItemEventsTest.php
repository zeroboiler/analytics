<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\Ecommerce\SelectItemEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\SelectPromotionEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\ViewPromotionEvent;

describe('SelectItemEvent', function () {
    it('creates with items and list info', function () {
        $event = new SelectItemEvent(
            items: [['item_id' => 'SKU-001', 'item_name' => 'Widget']],
            itemListId: 'related_products',
            itemListName: 'Related Products',
            currency: 'USD',
        );

        expect($event->name)->toBe('select_item');
        expect($event->params['item_list_id'])->toBe('related_products');
        expect($event->params['item_list_name'])->toBe('Related Products');
        expect($event->params['currency'])->toBe('USD');
        expect($event->params['items'])->toHaveCount(1);
    });

    it('creates with empty defaults', function () {
        $event = new SelectItemEvent();

        expect($event->name)->toBe('select_item');
        expect($event->params['items'])->toBe([]);
        expect($event->params['item_list_id'])->toBeNull();
    });
});

describe('SelectPromotionEvent', function () {
    it('creates with all promotion fields', function () {
        $event = new SelectPromotionEvent(
            promotionId: 'PROMO-001',
            promotionName: 'Summer Sale',
            creativeName: 'hero_banner',
            creativeSlot: 'homepage_top',
            locationId: 'hero_banner_1',
        );

        expect($event->name)->toBe('select_promotion');
        expect($event->params['promotion_id'])->toBe('PROMO-001');
        expect($event->params['promotion_name'])->toBe('Summer Sale');
        expect($event->params['creative_name'])->toBe('hero_banner');
        expect($event->params['creative_slot'])->toBe('homepage_top');
        expect($event->params['location_id'])->toBe('hero_banner_1');
    });

    it('filters out null values', function () {
        $event = new SelectPromotionEvent(promotionId: 'PROMO-001');

        expect($event->name)->toBe('select_promotion');
        expect($event->params)->toHaveKey('promotion_id');
        expect($event->params)->not->toHaveKey('promotion_name');
    });
});

describe('ViewPromotionEvent', function () {
    it('creates with all promotion fields', function () {
        $event = new ViewPromotionEvent(
            promotionId: 'PROMO-002',
            promotionName: 'Flash Sale',
            creativeName: 'sidebar_banner',
            creativeSlot: 'sidebar',
        );

        expect($event->name)->toBe('view_promotion');
        expect($event->params['promotion_id'])->toBe('PROMO-002');
        expect($event->params['promotion_name'])->toBe('Flash Sale');
        expect($event->params['creative_slot'])->toBe('sidebar');
    });

    it('creates with empty defaults', function () {
        $event = new ViewPromotionEvent();

        expect($event->name)->toBe('view_promotion');
        expect($event->params)->toBeEmpty();
    });
});
