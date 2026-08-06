<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Schema\EventParam;
use ZeroBoiler\Analytics\Schema\EventSchema;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;

describe('EventSchema', function () {
    describe('validation', function () {
        it('validates required params are present', function () {
            $schema = new EventSchema(
                name: 'purchase',
                category: 'ecommerce',
                requiredParams: [
                    'transaction_id' => new EventParam(type: 'string', maxLength: 100),
                    'value' => new EventParam(type: 'float', min: 0),
                ],
            );

            $result = $schema->validate(['value' => 29.99]);

            expect($result['valid'])->toBeFalse();
            expect($result['errors'])->toContain('Missing required parameter: transaction_id');
        });

        it('validates param types', function () {
            $schema = new EventSchema(
                name: 'test',
                category: 'test',
                requiredParams: [
                    'count' => new EventParam(type: 'int'),
                ],
            );

            $result = $schema->validate(['count' => 'not_a_number']);

            expect($result['valid'])->toBeFalse();
            expect($result['errors'])->not()->toBeEmpty();
        });

        it('passes valid params', function () {
            $schema = new EventSchema(
                name: 'purchase',
                category: 'ecommerce',
                requiredParams: [
                    'transaction_id' => new EventParam(type: 'string'),
                    'value' => new EventParam(type: 'float'),
                ],
                optionalParams: [
                    'currency' => new EventParam(type: 'string', maxLength: 3),
                ],
            );

            $result = $schema->validate([
                'transaction_id' => 'TXN-123',
                'value' => 99.99,
                'currency' => 'USD',
            ]);

            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toBeEmpty();
            expect($result['sanitized']['transaction_id'])->toBe('TXN-123');
        });

        it('passes through extra params not in schema', function () {
            $schema = new EventSchema(
                name: 'test',
                category: 'test',
            );

            $result = $schema->validate(['custom_field' => 'value']);

            expect($result['valid'])->toBeTrue();
            expect($result['sanitized']['custom_field'])->toBe('value');
        });

        it('sanitizes string values with max length', function () {
            $schema = new EventSchema(
                name: 'test',
                category: 'test',
                optionalParams: [
                    'name' => new EventParam(type: 'string', maxLength: 5),
                ],
            );

            $result = $schema->validate(['name' => 'Very Long Name']);

            expect($result['valid'])->toBeTrue();
            expect($result['sanitized']['name'])->toBe('Very L');
        });

        it('sanitizes int values with min/max', function () {
            $schema = new EventSchema(
                name: 'test',
                category: 'test',
                optionalParams: [
                    'age' => new EventParam(type: 'int', min: 0, max: 150),
                ],
            );

            $result = $schema->validate(['age' => -5]);

            expect($result['valid'])->toBeTrue();
            expect($result['sanitized']['age'])->toBe(0);

            $resultHigh = $schema->validate(['age' => 999]);
            expect($resultHigh['sanitized']['age'])->toBe(150);
        });

        it('strips control characters from strings', function () {
            $param = new EventParam(type: 'string');

            $result = $param->sanitize("hello\x00world");

            expect($result)->toBe('helloworld');
        });

        it('handles null optional params gracefully', function () {
            $schema = new EventSchema(
                name: 'test',
                category: 'test',
                optionalParams: [
                    'optional_field' => new EventParam(type: 'string'),
                ],
            );

            $result = $schema->validate([]);

            expect($result['valid'])->toBeTrue();
            expect($result['sanitized'])->not()->toHaveKey('optional_field');
        });
    });

    describe('provider mapping', function () {
        it('returns mapped event name for provider', function () {
            $schema = new EventSchema(
                name: 'add_to_cart',
                category: 'ecommerce',
                providerMapping: [
                    'ga4' => 'add_to_cart',
                    'meta' => 'AddToCart',
                ],
            );

            expect($schema->getProviderEventName('meta'))->toBe('AddToCart');
            expect($schema->getProviderEventName('ga4'))->toBe('add_to_cart');
            expect($schema->getProviderEventName('unknown'))->toBe('add_to_cart');
        });
    });

    describe('param names', function () {
        it('returns all param names', function () {
            $schema = new EventSchema(
                name: 'test',
                category: 'test',
                requiredParams: [
                    'id' => new EventParam(type: 'string'),
                ],
                optionalParams: [
                    'name' => new EventParam(type: 'string'),
                    'value' => new EventParam(type: 'float'),
                ],
            );

            $names = $schema->getAllParamNames();

            expect($names)->toContain('id', 'name', 'value');
            expect(count($names))->toBe(3);
        });
    });
});

describe('EventParam', function () {
    it('validates string type', function () {
        $param = new EventParam(type: 'string');
        expect($param->validateType('hello'))->toBeNull();
        expect($param->validateType(123))->not()->toBeNull();
    });

    it('validates int type (accepts numeric strings)', function () {
        $param = new EventParam(type: 'int');
        expect($param->validateType(42))->toBeNull();
        expect($param->validateType('42'))->toBeNull();
        expect($param->validateType('abc'))->not()->toBeNull();
    });

    it('validates float type (accepts int and numeric strings)', function () {
        $param = new EventParam(type: 'float');
        expect($param->validateType(3.14))->toBeNull();
        expect($param->validateType(42))->toBeNull();
        expect($param->validateType('3.14'))->toBeNull();
        expect($param->validateType('hello'))->not()->toBeNull();
    });

    it('validates bool type', function () {
        $param = new EventParam(type: 'bool');
        expect($param->validateType(true))->toBeNull();
        expect($param->validateType(false))->toBeNull();
        expect($param->validateType(1))->not()->toBeNull();
    });

    it('validates array type', function () {
        $param = new EventParam(type: 'array');
        expect($param->validateType([]))->toBeNull();
        expect($param->validateType(['a', 'b']))->toBeNull();
        expect($param->validateType('string'))->not()->toBeNull();
    });

    it('sanitizes float with clamping', function () {
        $param = new EventParam(type: 'float', min: 0.0, max: 100.0);
        expect($param->sanitize(-5.0))->toBe(0.0);
        expect($param->sanitize(200.0))->toBe(100.0);
        expect($param->sanitize(50.0))->toBe(50.0);
    });

    it('sanitizes bool by casting', function () {
        $param = new EventParam(type: 'bool');
        expect($param->sanitize(1))->toBeTrue();
        expect($param->sanitize(0))->toBeFalse();
        expect($param->sanitize('yes'))->toBeTrue();
    });

    it('sanitizes array (returns empty array for non-array)', function () {
        $param = new EventParam(type: 'array');
        expect($param->sanitize('not_array'))->toBe([]);
        expect($param->sanitize(['key' => 'value']))->toBe(['key' => 'value']);
    });
});

describe('EventSchemaRegistry', function () {
    let($registry) {
        $r = new EventSchemaRegistry;
        $r->register(new EventSchema(
            name: 'custom_event',
            category: 'custom',
            description: 'A custom event',
            optionalParams: [
                'custom_param' => new EventParam(type: 'string'),
            ],
        ));

        return $r;
    }

    it('has built-in ecommerce schemas', function () {
        $r = new EventSchemaRegistry;

        expect($r->has('purchase'))->toBeTrue();
        expect($r->has('add_to_cart'))->toBeTrue();
        expect($r->has('view_item'))->toBeTrue();
        expect($r->has('refund'))->toBeTrue();
        expect($r->has('begin_checkout'))->toBeTrue();
        expect($r->has('add_payment_info'))->toBeTrue();
        expect($r->has('remove_from_cart'))->toBeTrue();
        expect($r->has('view_cart'))->toBeTrue();
    });

    it('has built-in SaaS schemas', function () {
        $r = new EventSchemaRegistry;

        expect($r->has('sign_up'))->toBeTrue();
        expect($r->has('login'))->toBeTrue();
        expect($r->has('subscribe'))->toBeTrue();
        expect($r->has('plan_upgrade'))->toBeTrue();
        expect($r->has('cancellation'))->toBeTrue();
        expect($r->has('feature_used'))->toBeTrue();
        expect($r->has('revenue_tracked'))->toBeTrue();
    });

    it('has built-in engagement schemas', function () {
        $r = new EventSchemaRegistry;

        expect($r->has('page_view'))->toBeTrue();
        expect($r->has('scroll_depth'))->toBeTrue();
        expect($r->has('click'))->toBeTrue();
        expect($r->has('form_start'))->toBeTrue();
        expect($r->has('form_submit'))->toBeTrue();
        expect($r->has('search'))->toBeTrue();
        expect($r->has('share'))->toBeTrue();
        expect($r->has('error'))->toBeTrue();
        expect($r->has('time_on_page'))->toBeTrue();
        expect($r->has('campaign_attribution'))->toBeTrue();
    });

    it('has built-in core schemas', function () {
        $r = new EventSchemaRegistry;

        expect($r->has('identify'))->toBeTrue();
        expect($r->has('session_start'))->toBeTrue();
        expect($r->has('session_end'))->toBeTrue();
        expect($r->has('funnel_step'))->toBeTrue();
        expect($r->has('funnel_complete'))->toBeTrue();
        expect($r->has('funnel_abandon'))->toBeTrue();
    });

    it('validates events against schemas', function () {
        $r = new EventSchemaRegistry;

        // Purchase with missing required param
        $result = $r->validate('purchase', []);
        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->not()->toBeEmpty();

        // Purchase with all required params
        $result = $r->validate('purchase', [
            'transaction_id' => 'TXN-123',
            'value' => 99.99,
        ]);
        expect($result['valid'])->toBeTrue();
    });

    it('returns permissive for unknown event names', function () {
        $r = new EventSchemaRegistry;

        $result = $r->validate('unknown_custom_event', ['foo' => 'bar']);
        expect($result['valid'])->toBeTrue();
        expect($result['sanitized']['foo'])->toBe('bar');
    });

    it('allows registering custom schemas', function () use ($registry) {
        expect($registry->has('custom_event'))->toBeTrue();

        $schema = $registry->get('custom_event');
        expect($schema)->not()->toBeNull();
        expect($schema->category)->toBe('custom');
    });

    it('allows unregistering schemas', function () use ($registry) {
        $registry->unregister('custom_event');

        expect($registry->has('custom_event'))->toBeFalse();
    });

    it('groups events by category', function () {
        $r = new EventSchemaRegistry;
        $grouped = $r->getSchemasByCategory();

        expect($grouped)->toHaveKey('ecommerce');
        expect($grouped)->toHaveKey('saas');
        expect($grouped)->toHaveKey('engagement');
        expect($grouped)->toHaveKey('core');
        expect(count($grouped['ecommerce']))->toBeGreaterThanOrEqual(8);
    });

    it('filters events by category', function () {
        $r = new EventSchemaRegistry;

        $ecommerce = $r->getEventsByCategory('ecommerce');
        expect($ecommerce)->toContain('purchase', 'add_to_cart', 'view_item');
        expect($ecommerce)->not()->toContain('sign_up', 'page_view');
    });

    it('returns all event names', function () {
        $r = new EventSchemaRegistry;

        $names = $r->getEventNames();
        expect(count($names))->toBeGreaterThanOrEqual(30);
        expect(in_array('purchase', $names, true))->toBeTrue();
    });

    it('reports correct count', function () use ($registry) {
        // Built-in schemas + 1 custom
        expect($registry->count())->toBeGreaterThan(30);
    });
});
