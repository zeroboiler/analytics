<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

test('ga4 to meta contents conversion', function (): void {
    $items = [
        ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2, 'item_category' => 'Gadgets'],
        ['item_id' => 'SKU-002', 'item_name' => 'Gadget', 'price' => 29.99, 'quantity' => 1, 'item_category' => 'Tools'],
    ];

    $result = EcommerceFormatConverter::ga4ToMetaContents($items);

    expect($result)
        ->toHaveKey('content_ids')
        ->toHaveKey('contents')
        ->toHaveKey('num_items')
        ->toHaveKey('value');

    expect($result['content_ids'])->toBe(['SKU-001', 'SKU-002']);
    expect($result['num_items'])->toBe(2);
    expect($result['value'])->toBe(49.99 * 2 + 29.99); // 129.97
    expect($result['contents'])->toHaveCount(2);
    expect($result['contents'][0]['id'])->toBe('SKU-001');
    expect($result['contents'][0]['quantity'])->toBe(2);
    expect($result['contents'][0]['item_price'])->toBe(49.99);
});

test('meta to ga4 items reverse conversion', function (): void {
    $contents = [
        ['id' => 'SKU-001', 'quantity' => 2, 'item_price' => 49.99, 'item_name' => 'Widget', 'category' => 'Gadgets'],
        ['id' => 'SKU-002', 'quantity' => 1, 'item_price' => 29.99, 'item_name' => 'Gadget', 'category' => 'Tools'],
    ];

    $result = EcommerceFormatConverter::metaToGa4Items($contents);

    expect($result)->toHaveCount(2);
    expect($result[0]['item_id'])->toBe('SKU-001');
    expect($result[0]['price'])->toBe(49.99);
    expect($result[0]['quantity'])->toBe(2);
    expect($result[1]['item_id'])->toBe('SKU-002');
});

test('ga4 to meta purchase conversion', function (): void {
    $ga4Params = [
        'transaction_id' => 'TXN-12345',
        'value' => 129.97,
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
        ],
    ];

    $result = EcommerceFormatConverter::ga4ToMetaPurchase($ga4Params);

    expect($result['value'])->toBe(129.97);
    expect($result['currency'])->toBe('USD');
    expect($result['content_ids'])->toBe(['SKU-001']);
    expect($result['num_items'])->toBe(1);
    expect($result['content_type'])->toBe('product');
});

test('meta to ga4 purchase reverse conversion', function (): void {
    $metaParams = [
        'content_ids' => ['SKU-001'],
        'contents' => [
            ['id' => 'SKU-001', 'quantity' => 2, 'item_price' => 49.99, 'item_name' => 'Widget'],
        ],
        'value' => 99.98,
        'currency' => 'USD',
    ];

    $result = EcommerceFormatConverter::metaToGa4Purchase($metaParams);

    expect($result['transaction_id'])->toBe('SKU-001');
    expect($result['value'])->toBe(99.98);
    expect($result['currency'])->toBe('USD');
    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['item_id'])->toBe('SKU-001');
});

test('build ga4 purchase params', function (): void {
    $result = EcommerceFormatConverter::buildGa4Purchase(
        'TXN-12345',
        99.98,
        'USD',
        [
            ['item_id' => 'SKU-001', 'price' => 49.99, 'quantity' => 2],
        ],
        ['tax' => 8.00, 'shipping' => 5.00, 'coupon' => 'SAVE10'],
    );

    expect($result['transaction_id'])->toBe('TXN-12345');
    expect($result['value'])->toBe(99.98);
    expect($result['currency'])->toBe('USD');
    expect($result['tax'])->toBe(8.0);
    expect($result['shipping'])->toBe(5.0);
    expect($result['coupon'])->toBe('SAVE10');
    expect($result['items'])->toHaveCount(1);
});

test('build ga4 refund params', function (): void {
    $result = EcommerceFormatConverter::buildGa4Refund('TXN-12345', 99.98, 'USD');

    expect($result['transaction_id'])->toBe('TXN-12345');
    expect($result['value'])->toBe(99.98);
    expect($result['currency'])->toBe('USD');

    // Full refund with items
    $resultWithItems = EcommerceFormatConverter::buildGa4Refund('TXN-12345', 99.98, 'USD', [
        ['item_id' => 'SKU-001', 'price' => 49.99, 'quantity' => 2],
    ]);

    expect($resultWithItems)->toHaveKey('items');
    expect($resultWithItems['items'])->toHaveCount(1);
});

test('build ga4 add to cart params', function (): void {
    $result = EcommerceFormatConverter::buildGa4AddToCart(
        ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
        'USD',
        'Product Page',
    );

    expect($result['currency'])->toBe('USD');
    expect($result['value'])->toBe(99.98);
    expect($result['items'])->toHaveCount(1);
    expect($result['item_list_name'])->toBe('Product Page');
});

test('build ga4 view item params', function (): void {
    $result = EcommerceFormatConverter::buildGa4ViewItem(
        ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99],
        'EUR',
    );

    expect($result['currency'])->toBe('EUR');
    expect($result['value'])->toBe(49.99);
    expect($result['items'])->toHaveCount(1);
});

test('build meta purchase params', function (): void {
    $result = EcommerceFormatConverter::buildMetaPurchase(
        'Widget Bundle',
        'Gadgets',
        [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
        ],
        'USD',
    );

    expect($result['content_name'])->toBe('Widget Bundle');
    expect($result['content_category'])->toBe('Gadgets');
    expect($result['value'])->toBe(99.98);
    expect($result['currency'])->toBe('USD');
});

test('build meta add to cart params', function (): void {
    $result = EcommerceFormatConverter::buildMetaAddToCart(
        'Widget',
        'Gadgets',
        ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 1],
    );

    expect($result['content_name'])->toBe('Widget');
    expect($result['value'])->toBe(49.99);
    expect($result['content_ids'])->toBe(['SKU-001']);
});

test('build cross-provider purchase event', function (): void {
    $items = [
        ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
    ];

    // GA4 purchase event
    $ga4Event = EcommerceFormatConverter::buildPurchaseEvent('ga4', 'TXN-12345', 99.98, 'USD', $items);
    expect($ga4Event)->toBeInstanceOf(AnalyticsEvent::class);
    expect($ga4Event->name)->toBe('purchase');
    expect($ga4Event->params['transaction_id'])->toBe('TXN-12345');

    // Meta purchase event
    $metaEvent = EcommerceFormatConverter::buildPurchaseEvent('meta', 'TXN-12345', 99.98, 'USD', $items, [
        'content_name' => 'Widget Bundle',
        'content_category' => 'Gadgets',
    ]);
    expect($metaEvent)->toBeInstanceOf(AnalyticsEvent::class);
    expect($metaEvent->name)->toBe('Purchase');
    expect($metaEvent->params['content_name'])->toBe('Widget Bundle');
});

test('calculate total value from items', function (): void {
    $items = [
        ['price' => 49.99, 'quantity' => 2],
        ['price' => 29.99, 'quantity' => 1],
    ];

    expect(EcommerceFormatConverter::calculateTotalValue($items))->toBe(129.97);
    expect(EcommerceFormatConverter::calculateTotalValue([]))->toBe(0.0);
});

test('normalize ga4 item ensures required fields', function (): void {
    $raw = ['item_id' => 'SKU-001', 'name' => 'Widget', 'category' => 'Gadgets', 'price' => '49.99', 'quantity' => '3'];

    $result = EcommerceFormatConverter::normalizeGa4Item($raw);

    expect($result['item_id'])->toBe('SKU-001');
    expect($result['item_name'])->toBe('Widget');
    expect($result['item_category'])->toBe('Gadgets');
    expect($result['price'])->toBe(49.99);
    expect($result['quantity'])->toBe(3);
});

test('normalize ga4 item handles missing fields gracefully', function (): void {
    $raw = ['id' => 'SKU-001'];

    $result = EcommerceFormatConverter::normalizeGa4Item($raw);

    expect($result['item_id'])->toBe('SKU-001');
    expect($result['item_name'])->toBe('');
    expect($result['price'])->toBe(0.0);
    expect($result['quantity'])->toBe(1);
});

test('normalize ga4 items batch', function (): void {
    $rawItems = [
        ['id' => 'SKU-001', 'name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
        ['item_id' => 'SKU-002'],
    ];

    $result = EcommerceFormatConverter::normalizeGa4Items($rawItems);

    expect($result)->toHaveCount(2);
    expect($result[0]['item_id'])->toBe('SKU-001');
    expect($result[0]['item_name'])->toBe('Widget');
    expect($result[1]['item_id'])->toBe('SKU-002');
    expect($result[1]['quantity'])->toBe(1);
});

test('ga4 to meta refund conversion', function (): void {
    $ga4Params = [
        'transaction_id' => 'TXN-12345',
        'value' => 99.98,
        'currency' => 'USD',
        'items' => [
            ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
        ],
    ];

    $result = EcommerceFormatConverter::ga4ToMetaRefund($ga4Params);

    expect($result['value'])->toBe(99.98);
    expect($result['currency'])->toBe('USD');
    expect($result['content_ids'])->toBe(['SKU-001']);
    expect($result['content_type'])->toBe('product');
});

test('empty items array returns empty conversion', function (): void {
    $result = EcommerceFormatConverter::ga4ToMetaContents([]);

    expect($result['content_ids'])->toBe([]);
    expect($result['contents'])->toBe([]);
    expect($result['num_items'])->toBe(0);
    expect($result['value'])->toBe(0.0);
});

