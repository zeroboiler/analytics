<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventTransformationRule;
use ZeroBoiler\Analytics\DTO\ProviderEventMapping;
use ZeroBoiler\Analytics\DTO\TransformedPayload;
use ZeroBoiler\Analytics\Services\EventTransformationEngine;

beforeEach(function (): void {
    $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->with('zeroboiler.analytics.transformation.enabled', true)->andReturn(true);
    $config->shouldReceive('get')->with('zeroboiler.analytics.transformation.cache_ttl', 3600)->andReturn(3600);
    $config->shouldReceive('get')->with('zeroboiler.analytics.transformation.mappings', [])->andReturn([]);

    $cache = Mockery::mock(Illuminate\Contracts\Cache\Repository::class);
    $cache->shouldReceive('get')->andReturnNull();
    $cache->shouldReceive('put')->andReturnTrue();

    $this->engine = new EventTransformationEngine($config, $cache);
});

afterEach(function (): void {
    Mockery::close();
});

describe('EventTransformationRule', function (): void {
    it('creates rename rule', function (): void {
        $rule = EventTransformationRule::rename('item_id', 'content_id');

        expect($rule->sourceField)->toBe('item_id');
        expect($rule->targetField)->toBe('content_id');
        expect($rule->dropAlways)->toBeFalse();
    });

    it('creates drop rule', function (): void {
        $rule = EventTransformationRule::drop('internal_score');

        expect($rule->sourceField)->toBe('internal_score');
        expect($rule->dropAlways)->toBeTrue();
    });

    it('creates cast rule', function (): void {
        $rule = EventTransformationRule::cast('value', 'float');

        expect($rule->castTo)->toBe('float');
        expect($rule->sourceField)->toBe('value');
    });

    it('creates default rule', function (): void {
        $rule = EventTransformationRule::default('currency', 'USD');

        expect($rule->defaultValue)->toBe('USD');
        expect($rule->dropIfMissing)->toBeFalse();
    });

    it('serializes to array', function (): void {
        $rule = EventTransformationRule::rename('old_name', 'new_name');
        $array = $rule->toArray();

        expect($array)->toHaveKey('source_field');
        expect($array['source_field'])->toBe('old_name');
        expect($array['target_field'])->toBe('new_name');
    });

    it('creates from array', function (): void {
        $rule = EventTransformationRule::fromArray([
            'source_field' => 'price',
            'cast_to' => 'float',
            'default_value' => 0.0,
        ]);

        expect($rule->sourceField)->toBe('price');
        expect($rule->castTo)->toBe('float');
        expect($rule->defaultValue)->toBe(0.0);
    });
});

describe('ProviderEventMapping', function (): void {
    it('creates mapping with rules', function (): void {
        $mapping = new ProviderEventMapping(
            eventName: 'purchase',
            provider: 'meta',
            rules: [
                EventTransformationRule::rename('transaction_id', 'order_id'),
                EventTransformationRule::drop('internal_score'),
            ],
            staticOverrides: ['event_source' => 'server'],
            eventNameOverride: 'Purchase',
        );

        expect($mapping->eventName)->toBe('purchase');
        expect($mapping->provider)->toBe('meta');
        expect($mapping->eventNameOverride)->toBe('Purchase');
        expect($mapping->staticOverrides)->toBe(['event_source' => 'server']);
        expect(count($mapping->rules))->toBe(2);
    });

    it('generates key', function (): void {
        $mapping = new ProviderEventMapping(eventName: 'sign_up', provider: 'ga4');

        expect($mapping->key())->toBe('sign_up:ga4');
    });

    it('serializes to array', function (): void {
        $mapping = new ProviderEventMapping(
            eventName: 'page_view',
            provider: 'plausible',
            allowOnly: ['url', 'referrer'],
        );
        $array = $mapping->toArray();

        expect($array['event'])->toBe('page_view');
        expect($array['provider'])->toBe('plausible');
        expect($array['allow_only'])->toBe(['url', 'referrer']);
    });

    it('creates from config array', function (): void {
        $mapping = ProviderEventMapping::fromArray([
            'event' => 'purchase',
            'provider' => 'meta',
            'event_name_override' => 'Purchase',
            'rules' => [
                ['source_field' => 'transaction_id', 'target_field' => 'order_id'],
            ],
            'static_overrides' => ['source' => 'server'],
        ]);

        expect($mapping->eventName)->toBe('purchase');
        expect($mapping->provider)->toBe('meta');
        expect(count($mapping->rules))->toBe(1);
    });
});

describe('TransformedPayload', function (): void {
    it('creates passthrough', function (): void {
        $event = new AnalyticsEvent(name: 'page_view', params: ['url' => '/test']);
        $result = TransformedPayload::passthrough($event, 'ga4');

        expect($result->eventName)->toBe('page_view');
        expect($result->params)->toBe(['url' => '/test']);
        expect($result->provider)->toBe('ga4');
        expect($result->dropped)->toBeFalse();
    });

    it('creates dropped result', function (): void {
        $result = TransformedPayload::dropped('purchase', 'plausible', 'event not supported');

        expect($result->dropped)->toBeTrue();
        expect($result->params)->toBe([]);
    });

    it('serializes to array', function (): void {
        $event = new AnalyticsEvent(name: 'test', params: []);
        $result = TransformedPayload::passthrough($event, 'ga4');
        $array = $result->toArray();

        expect($array)->toHaveKey('event_name');
        expect($array)->toHaveKey('params');
        expect($array)->toHaveKey('provider');
    });
});

describe('EventTransformationEngine', function (): void {
    it('returns passthrough when no mapping exists', function (): void {
        $event = new AnalyticsEvent(name: 'custom_event', params: ['key' => 'value']);
        $result = $this->engine->transform($event, 'ga4');

        expect($result->dropped)->toBeFalse();
        expect($result->eventName)->toBe('custom_event');
        expect($result->params)->toBe(['key' => 'value']);
    });

    it('applies rename rules', function (): void {
        $mapping = new ProviderEventMapping(
            eventName: 'purchase',
            provider: 'meta',
            rules: [
                EventTransformationRule::rename('transaction_id', 'order_id'),
            ],
        );

        $this->engine->registerMapping($mapping);

        $event = new AnalyticsEvent(name: 'purchase', params: [
            'transaction_id' => 'TXN-123',
            'value' => 99.99,
        ]);

        $result = $this->engine->transform($event, 'meta');

        expect($result->params)->toHaveKey('order_id');
        expect($result->params['order_id'])->toBe('TXN-123');
        expect($result->params)->not->toHaveKey('transaction_id');
        expect($result->applied)->not->toBeEmpty();
    });

    it('applies drop rules', function (): void {
        $mapping = new ProviderEventMapping(
            eventName: 'purchase',
            provider: 'plausible',
            rules: [
                EventTransformationRule::drop('internal_score'),
            ],
        );

        $this->engine->registerMapping($mapping);

        $event = new AnalyticsEvent(name: 'purchase', params: [
            'internal_score' => 42,
            'value' => 10.0,
        ]);

        $result = $this->engine->transform($event, 'plausible');

        expect($result->params)->not->toHaveKey('internal_score');
        expect($result->params['value'])->toBe(10.0);
    });

    it('applies cast rules', function (): void {
        $mapping = new ProviderEventMapping(
            eventName: 'purchase',
            provider: 'ga4',
            rules: [
                EventTransformationRule::cast('value', 'float'),
                EventTransformationRule::cast('quantity', 'int'),
            ],
        );

        $this->engine->registerMapping($mapping);

        $event = new AnalyticsEvent(name: 'purchase', params: [
            'value' => '99.50',
            'quantity' => '3',
        ]);

        $result = $this->engine->transform($event, 'ga4');

        expect($result->params['value'])->toBe(99.5);
        expect($result->params['quantity'])->toBe(3);
    });

    it('applies default value rules', function (): void {
        $mapping = new ProviderEventMapping(
            eventName: 'purchase',
            provider: 'meta',
            rules: [
                EventTransformationRule::default('currency', 'USD'),
            ],
        );

        $this->engine->registerMapping($mapping);

        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 10.0]);

        $result = $this->engine->transform($event, 'meta');

        expect($result->params['currency'])->toBe('USD');
    });

    it('applies event name override', function (): void {
        $mapping = new ProviderEventMapping(
            eventName: 'form_submit',
            provider: 'meta',
            eventNameOverride: 'Lead',
        );

        $this->engine->registerMapping($mapping);

        $event = new AnalyticsEvent(name: 'form_submit', params: ['form_name' => 'contact']);
        $result = $this->engine->transform($event, 'meta');

        expect($result->eventName)->toBe('Lead');
    });

    it('applies static overrides', function (): void {
        $mapping = new ProviderEventMapping(
            eventName: 'page_view',
            provider: 'ga4',
            staticOverrides: ['event_source' => 'server', 'tracking_version' => '70.0.0'],
        );

        $this->engine->registerMapping($mapping);

        $event = new AnalyticsEvent(name: 'page_view', params: ['url' => '/test']);
        $result = $this->engine->transform($event, 'ga4');

        expect($result->params['event_source'])->toBe('server');
        expect($result->params['tracking_version'])->toBe('70.0.0');
    });

    it('applies allow-only whitelist', function (): void {
        $mapping = new ProviderEventMapping(
            eventName: 'page_view',
            provider: 'plausible',
            allowOnly: ['url', 'referrer'],
        );

        $this->engine->registerMapping($mapping);

        $event = new AnalyticsEvent(name: 'page_view', params: [
            'url' => '/test',
            'referrer' => 'https://google.com',
            'title' => 'Test Page',
            'session_id' => 'abc123',
        ]);

        $result = $this->engine->transform($event, 'plausible');

        expect($result->params)->toHaveKey('url');
        expect($result->params)->toHaveKey('referrer');
        expect($result->params)->not->toHaveKey('title');
        expect($result->params)->not->toHaveKey('session_id');
    });

    it('transforms for all providers at once', function (): void {
        $this->engine->registerMapping(new ProviderEventMapping(
            eventName: 'purchase',
            provider: 'meta',
            eventNameOverride: 'Purchase',
            rules: [EventTransformationRule::rename('transaction_id', 'order_id')],
        ));

        $event = new AnalyticsEvent(name: 'purchase', params: [
            'transaction_id' => 'TXN-123',
            'value' => 99.99,
        ]);

        $results = $this->engine->transformForAll($event, ['ga4', 'meta', 'plausible']);

        expect($results)->toHaveKey('ga4');
        expect($results)->toHaveKey('meta');
        expect($results)->toHaveKey('plausible');

        // ga4 = passthrough
        expect($results['ga4']->eventName)->toBe('purchase');
        expect($results['ga4']->params)->toHaveKey('transaction_id');

        // meta = transformed
        expect($results['meta']->eventName)->toBe('Purchase');
        expect($results['meta']->params)->toHaveKey('order_id');
        expect($results['meta']->params)->not->toHaveKey('transaction_id');
    });

    it('validates mappings successfully', function (): void {
        $result = $this->engine->validateMappings();

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });

    it('detects invalid cast types', function (): void {
        $mapping = new ProviderEventMapping(
            eventName: 'test',
            provider: 'ga4',
            rules: [EventTransformationRule::fromArray([
                'source_field' => 'field',
                'cast_to' => 'invalid_type',
            ])],
        );

        $this->engine->registerMapping($mapping);
        $result = $this->engine->validateMappings();

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->not->toBeEmpty();
    });

    it('reports mapping count', function (): void {
        expect($this->engine->mappingCount())->toBe(0);

        $this->engine->registerMapping(new ProviderEventMapping(
            eventName: 'purchase',
            provider: 'meta',
        ));

        expect($this->engine->mappingCount())->toBe(1);
    });

    it('lists mappings by event', function (): void {
        $this->engine->registerMapping(new ProviderEventMapping(eventName: 'purchase', provider: 'ga4'));
        $this->engine->registerMapping(new ProviderEventMapping(eventName: 'purchase', provider: 'meta'));

        $mappings = $this->engine->mappingsForEvent('purchase');

        expect(count($mappings))->toBe(2);
    });

    it('lists mappings by provider', function (): void {
        $this->engine->registerMapping(new ProviderEventMapping(eventName: 'purchase', provider: 'meta'));
        $this->engine->registerMapping(new ProviderEventMapping(eventName: 'sign_up', provider: 'meta'));

        $mappings = $this->engine->mappingsForProvider('meta');

        expect(count($mappings))->toBe(2);
    });

    it('removes mappings', function (): void {
        $this->engine->registerMapping(new ProviderEventMapping(eventName: 'purchase', provider: 'meta'));
        expect($this->engine->mappingCount())->toBe(1);

        $this->engine->removeMapping('purchase', 'meta');
        expect($this->engine->mappingCount())->toBe(0);
    });

    it('exports mappings as arrays', function (): void {
        $this->engine->registerMapping(new ProviderEventMapping(
            eventName: 'purchase',
            provider: 'meta',
            eventNameOverride: 'Purchase',
        ));

        $exported = $this->engine->exportMappings();

        expect($exported)->toBeArray();
        expect(count($exported))->toBe(1);
        expect($exported[0]['event'])->toBe('purchase');
    });
});
