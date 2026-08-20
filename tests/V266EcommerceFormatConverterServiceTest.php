<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\Services\EcommerceFormatConverter;

/**
 * Unit tests for the OOP EcommerceFormatConverter service (v262.0.0).
 *
 * Validates bidirectional GA4 ↔ Meta format conversion,
 * item normalization, reverse name resolution, and edge cases.
 *
 * @since 266.0.0
 */
final class V266EcommerceFormatConverterServiceTest extends TestCase
{
    private EcommerceFormatConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new EcommerceFormatConverter;
    }

    // ── toGa4 ──────────────────────────────────────────────────────

    public function test_to_ga4_purchase(): void
    {
        $result = $this->converter->toGa4('purchase', [
            'transaction_id' => 'TXN-001',
            'value' => 99.99,
            'currency' => 'USD',
            'tax' => 8.0,
            'shipping' => 5.0,
            'coupon' => 'SAVE10',
            'items' => [
                ['item_id' => 'SKU-001', 'item_name' => 'Widget', 'price' => 49.99, 'quantity' => 2],
            ],
        ]);

        $this->assertSame('purchase', $result['name']);
        $this->assertSame('TXN-001', $result['params']['transaction_id']);
        $this->assertSame(99.99, $result['params']['value']);
        $this->assertSame('USD', $result['params']['currency']);
        $this->assertSame(8.0, $result['params']['tax']);
        $this->assertSame(5.0, $result['params']['shipping']);
        $this->assertSame('SAVE10', $result['params']['coupon']);
        $this->assertCount(1, $result['params']['items']);
        $this->assertSame('SKU-001', $result['params']['items'][0]['item_id']);
    }

    public function test_to_ga4_maps_canonical_name(): void
    {
        $result = $this->converter->toGa4('view_item', [
            'item_id' => 'SKU-001',
            'price' => 29.99,
            'currency' => 'EUR',
        ]);

        $this->assertSame('view_item', $result['name']);
        $this->assertSame('EUR', $result['params']['currency']);
    }

    public function test_to_ga4_strips_internal_fields(): void
    {
        $result = $this->converter->toGa4('purchase', [
            'value' => 10.0,
            'user_data' => ['email' => 'test@example.com'],
            'custom_properties' => ['foo' => 'bar'],
        ]);

        $this->assertArrayNotHasKey('user_data', $result['params']);
        $this->assertArrayNotHasKey('custom_properties', $result['params']);
    }

    // ── toMeta ─────────────────────────────────────────────────────

    public function test_to_meta_purchase(): void
    {
        $result = $this->converter->toMeta('purchase', [
            'transaction_id' => 'TXN-001',
            'value' => 99.99,
            'currency' => 'USD',
            'items' => [
                ['item_id' => 'SKU-001', 'price' => 49.99, 'quantity' => 2],
            ],
        ]);

        $this->assertSame('Purchase', $result['event']);
        $this->assertSame(99.99, $result['custom_data']['value']);
        $this->assertSame('USD', $result['custom_data']['currency']);
        $this->assertSame(['SKU-001'], $result['custom_data']['content_ids']);
        $this->assertSame('product', $result['custom_data']['content_type']);
        $this->assertCount(1, $result['custom_data']['contents']);
    }

    public function test_to_meta_single_item_shorthand(): void
    {
        $result = $this->converter->toMeta('add_to_cart', [
            'item_id' => 'SKU-002',
            'price' => 19.99,
            'quantity' => 3,
        ]);

        $this->assertSame('AddToCart', $result['event']);
        $this->assertSame(['SKU-002'], $result['custom_data']['content_ids']);
        $this->assertSame(3, $result['custom_data']['contents'][0]['quantity']);
    }

    public function test_to_meta_preserves_extra_params_as_custom_data(): void
    {
        $result = $this->converter->toMeta('purchase', [
            'transaction_id' => 'TXN-001',
            'value' => 99.99,
            'currency' => 'USD',
            'custom_note' => 'expedited',
        ]);

        $this->assertSame('Purchase', $result['event']);
        $this->assertSame(99.99, $result['custom_data']['value']);
        $this->assertSame('USD', $result['custom_data']['currency']);
        // Non-standard params pass through to custom_data
        $this->assertSame('expedited', $result['custom_data']['custom_note']);
        // transaction_id is mapped to content_name by the converter
        $this->assertSame('TXN-001', $result['custom_data']['content_name']);
    }

    // ── Bidirectional Conversion ────────────────────────────────────

    public function test_ga4_to_meta_conversion(): void
    {
        $ga4 = [
            'name' => 'purchase',
            'params' => [
                'transaction_id' => 'TXN-100',
                'value' => 199.99,
                'currency' => 'GBP',
                'items' => [
                    ['item_id' => 'SKU-A', 'price' => 99.99, 'quantity' => 2],
                ],
            ],
        ];

        $meta = $this->converter->ga4ToMeta($ga4);

        $this->assertSame('Purchase', $meta['event']);
        $this->assertSame(199.99, $meta['custom_data']['value']);
        $this->assertSame('GBP', $meta['custom_data']['currency']);
        $this->assertSame(['SKU-A'], $meta['custom_data']['content_ids']);
    }

    public function test_meta_to_ga4_conversion(): void
    {
        $meta = [
            'event' => 'AddToCart',
            'custom_data' => [
                'content_ids' => ['SKU-003'],
                'content_type' => 'product',
                'value' => 14.99,
                'currency' => 'EUR',
            ],
        ];

        $ga4 = $this->converter->metaToGa4($meta);

        $this->assertSame('add_to_cart', $ga4['name']);
        $this->assertSame(14.99, $ga4['params']['value']);
        $this->assertSame('EUR', $ga4['params']['currency']);
    }

    // ── Item Field Mapping ──────────────────────────────────────────

    public function test_ga4_item_field_aliasing(): void
    {
        $result = $this->converter->toGa4('view_item', [
            'currency' => 'USD',
            'items' => [
                ['id' => 'X1', 'name' => 'Thing', 'category' => 'Stuff', 'price' => 9.99, 'quantity' => 1],
            ],
        ]);

        $item = $result['params']['items'][0];
        $this->assertSame('X1', $item['item_id']);
        $this->assertSame('Thing', $item['item_name']);
        $this->assertSame('Stuff', $item['item_category']);
        $this->assertSame(9.99, $item['price']);
        $this->assertSame(1, $item['quantity']);
        $this->assertSame(0, $item['index']); // Auto-added
    }

    // ── Edge Cases ──────────────────────────────────────────────────

    public function test_to_ga4_skips_non_array_items(): void
    {
        $result = $this->converter->toGa4('purchase', [
            'value' => 10.0,
            'items' => ['not-an-array', ['item_id' => 'OK']],
        ]);

        $this->assertCount(1, $result['params']['items']);
        $this->assertSame('OK', $result['params']['items'][0]['item_id']);
    }

    public function test_to_meta_skips_non_array_items(): void
    {
        $result = $this->converter->toMeta('purchase', [
            'value' => 10.0,
            'items' => ['not-an-item'],
        ]);

        // No content_ids since no valid items
        $this->assertArrayNotHasKey('content_ids', $result['custom_data']);
    }

    public function test_to_meta_includes_user_data_when_present(): void
    {
        $result = $this->converter->toMeta('purchase', [
            'value' => 50.0,
            'user_data' => ['em' => 'hashed@email.com'],
        ]);

        $this->assertSame(['em' => 'hashed@email.com'], $result['user_data']);
    }
}
