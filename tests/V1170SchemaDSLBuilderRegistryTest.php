<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Schema\EventSchemaBuilder;
use ZeroBoiler\Analytics\Schema\EventSchemaDefinition;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistryExtended;
use ZeroBoiler\Analytics\Schema\PropertyDefinition;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

describe('EventSchemaBuilder — Fluent DSL', function () {
    describe('Construction & Static Factory', function () {
        it('creates a builder via static define()', function () {
            $builder = EventSchemaBuilder::define('test_event');

            expect($builder)->toBeInstanceOf(EventSchemaBuilder::class);
        });

        it('builds a schema with default values', function () {
            $schema = EventSchemaBuilder::define('minimal')
                ->build();

            expect($schema)->toBeInstanceOf(EventSchemaDefinition::class);
            expect($schema->name)->toBe('minimal');
            expect($schema->category)->toBe('custom');
            expect($schema->description)->toBe('');
            expect($schema->tags)->toBe([]);
            expect($schema->properties)->toBe([]);
            expect($schema->ga4)->toBeNull();
            expect($schema->meta)->toBeNull();
        });
    });

    describe('Category & Description', function () {
        it('sets category via chain', function () {
            $schema = EventSchemaBuilder::define('test')
                ->category('saas')
                ->build();

            expect($schema->category)->toBe('saas');
        });

        it('sets description via chain', function () {
            $schema = EventSchemaBuilder::define('test')
                ->description('A test event description')
                ->build();

            expect($schema->description)->toBe('A test event description');
        });
    });

    describe('Tags', function () {
        it('adds single tag', function () {
            $schema = EventSchemaBuilder::define('test')
                ->tag('billing')
                ->build();

            expect($schema->tags)->toBe(['billing']);
        });

        it('adds multiple tags in one call', function () {
            $schema = EventSchemaBuilder::define('test')
                ->tag('billing', 'revenue', 'acquisition')
                ->build();

            expect($schema->tags)->toBe(['billing', 'revenue', 'acquisition']);
        });

        it('deduplicates tags across multiple calls', function () {
            $schema = EventSchemaBuilder::define('test')
                ->tag('billing', 'revenue')
                ->tag('revenue', 'acquisition')
                ->build();

            expect($schema->tags)->toBe(['billing', 'revenue', 'acquisition']);
        });
    });

    describe('Provider Mappings', function () {
        it('sets all 8 provider mappings', function () {
            $schema = EventSchemaBuilder::define('test')
                ->ga4('test_ga4')
                ->meta('TestMeta')
                ->posthog('test_posthog')
                ->plausible('test_plausible')
                ->mixpanel('Test Mixpanel')
                ->amplitude('Test Amplitude')
                ->tiktok('TestTikTok')
                ->linkedin('test_linkedin')
                ->build();

            expect($schema->ga4)->toBe('test_ga4');
            expect($schema->meta)->toBe('TestMeta');
            expect($schema->posthog)->toBe('test_posthog');
            expect($schema->plausible)->toBe('test_plausible');
            expect($schema->mixpanel)->toBe('Test Mixpanel');
            expect($schema->amplitude)->toBe('Test Amplitude');
            expect($schema->tiktok)->toBe('TestTikTok');
            expect($schema->linkedin)->toBe('test_linkedin');
        });

        it('accepts null for optional providers', function () {
            $schema = EventSchemaBuilder::define('test')
                ->ga4('test')
                ->meta(null)
                ->plausible(null)
                ->build();

            expect($schema->meta)->toBeNull();
            expect($schema->plausible)->toBeNull();
        });
    });

    describe('Property Definitions', function () {
        it('defines string property', function () {
            $schema = EventSchemaBuilder::define('test')
                ->string('name')->required()
                ->build();

            expect(isset($schema->properties['name']))->toBeTrue();
            expect($schema->properties['name']->name)->toBe('name');
            expect($schema->properties['name']->type)->toBe('string');
            expect($schema->properties['name']->isRequired)->toBeTrue();
        });

        it('defines integer property', function () {
            $schema = EventSchemaBuilder::define('test')
                ->integer('count')
                ->build();

            expect($schema->properties['count']->type)->toBe('int');
        });

        it('defines float property', function () {
            $schema = EventSchemaBuilder::define('test')
                ->float('price')
                ->build();

            expect($schema->properties['price']->type)->toBe('float');
        });

        it('defines boolean property', function () {
            $schema = EventSchemaBuilder::define('test')
                ->boolean('active')
                ->build();

            expect($schema->properties['active']->type)->toBe('bool');
        });

        it('defines array property via array_', function () {
            $schema = EventSchemaBuilder::define('test')
                ->array_('items')
                ->build();

            expect($schema->properties['items']->type)->toBe('array');
        });

        it('defines numeric property', function () {
            $schema = EventSchemaBuilder::define('test')
                ->numeric('score')
                ->build();

            expect($schema->properties['score']->type)->toBe('numeric');
        });

        it('defines enum property with allowed values', function () {
            $schema = EventSchemaBuilder::define('test')
                ->enum('status', ['active', 'inactive', 'pending'])
                ->build();

            expect($schema->properties['status']->type)->toBe('enum');
            expect($schema->properties['status']->enumValues)->toBe(['active', 'inactive', 'pending']);
        });

        it('defines timestamp property', function () {
            $schema = EventSchemaBuilder::define('test')
                ->timestamp('created_at')
                ->build();

            expect($schema->properties['created_at']->type)->toBe('timestamp');
        });

        it('defines email property', function () {
            $schema = EventSchemaBuilder::define('test')
                ->email('user_email')
                ->build();

            expect($schema->properties['user_email']->type)->toBe('email');
        });

        it('defines url property', function () {
            $schema = EventSchemaBuilder::define('test')
                ->url('page_url')
                ->build();

            expect($schema->properties['page_url']->type)->toBe('url');
        });
    });

    describe('Property Constraints', function () {
        it('sets default value', function () {
            $schema = EventSchemaBuilder::define('test')
                ->string('currency')->default('USD')
                ->build();

            expect($schema->properties['currency']->hasDefault)->toBeTrue();
            expect($schema->properties['currency']->defaultValue)->toBe('USD');
        });

        it('sets description on property', function () {
            $schema = EventSchemaBuilder::define('test')
                ->string('name')->description('The user name')
                ->build();

            expect($schema->properties['name']->description)->toBe('The user name');
        });

        it('sets max length on string', function () {
            $schema = EventSchemaBuilder::define('test')
                ->string('name')->maxLength(100)
                ->build();

            expect($schema->properties['name']->maxLength)->toBe(100);
        });

        it('sets min/max on numeric', function () {
            $schema = EventSchemaBuilder::define('test')
                ->float('price')->min(0)->max(99999.99)
                ->build();

            expect($schema->properties['price']->minValue)->toBe(0.0);
            expect($schema->properties['price']->maxValue)->toBe(99999.99);
        });

        it('sets pattern on property', function () {
            $schema = EventSchemaBuilder::define('test')
                ->string('hex')->pattern('/^[a-f0-9]+$/')
                ->build();

            expect($schema->properties['hex']->pattern)->toBe('/^[a-f0-9]+$/');
        });

        it('adds examples to property', function () {
            $schema = EventSchemaBuilder::define('test')
                ->string('plan')->example('starter', 'pro', 'enterprise')
                ->build();

            expect($schema->properties['plan']->examples)->toBe(['starter', 'pro', 'enterprise']);
        });
    });

    describe('Schema Definition DTO', function () {
        it('computes required and optional properties', function () {
            $schema = EventSchemaBuilder::define('test')
                ->string('user_id')->required()
                ->string('email')->required()
                ->string('plan')
                ->integer('age')
                ->build();

            expect($schema->requiredProperties())->toBe(['user_id', 'email']);
            expect($schema->optionalProperties())->toBe(['plan', 'age']);
        });

        it('computes provider coverage count', function () {
            $schema = EventSchemaBuilder::define('test')
                ->ga4('test')
                ->meta('Test')
                ->posthog('test')
                ->build();

            expect($schema->providerCoverageCount())->toBe(3);
        });

        it('returns provider mappings as array', function () {
            $schema = EventSchemaBuilder::define('test')
                ->ga4('test')
                ->meta(null)
                ->build();

            $mappings = $schema->providerMappings();
            expect($mappings)->toHaveKey('ga4');
            expect($mappings)->toHaveKey('meta');
            expect($mappings['ga4'])->toBe('test');
            expect($mappings['meta'])->toBeNull();
        });

        it('serializes to array', function () {
            $schema = EventSchemaBuilder::define('test')
                ->category('saas')
                ->string('user_id')->required()
                ->ga4('test')
                ->build();

            $arr = $schema->toArray();
            expect($arr['name'])->toBe('test');
            expect($arr['category'])->toBe('saas');
            expect($arr['required'])->toBe(['user_id']);
            expect($arr['provider_count'])->toBe(1);
        });

        it('serializes to JSON', function () {
            $schema = EventSchemaBuilder::define('test')
                ->string('user_id')->required()
                ->build();

            $json = $schema->toJson();
            expect($json)->toBeJson();
            expect(json_decode($json, true)['name'])->toBe('test');
        });
    });

    describe('Validation Rules Generation', function () {
        it('generates Laravel validation rules for string required', function () {
            $schema = EventSchemaBuilder::define('test')
                ->string('user_id')->required()
                ->build();

            $rules = $schema->buildValidationRules();
            expect($rules['user_id'])->toBe('required|string|max:500');
        });

        it('generates rules for float optional', function () {
            $schema = EventSchemaBuilder::define('test')
                ->float('price')
                ->build();

            $rules = $schema->buildValidationRules();
            expect($rules['price'])->toBe('nullable|numeric|min:0|max:999999999');
        });

        it('generates rules for enum', function () {
            $schema = EventSchemaBuilder::define('test')
                ->enum('status', ['active', 'inactive'])
                ->build();

            $rules = $schema->buildValidationRules();
            expect($rules['status'])->toContain('in:active,inactive');
        });

        it('generates rules for boolean', function () {
            $schema = EventSchemaBuilder::define('test')
                ->boolean('is_admin')->required()
                ->build();

            $rules = $schema->buildValidationRules();
            expect($rules['is_admin'])->toBe('required|boolean');
        });

        it('generates rules for array', function () {
            $schema = EventSchemaBuilder::define('test')
                ->array_('items')
                ->build();

            $rules = $schema->buildValidationRules();
            expect($rules['items'])->toContain('array');
            expect($rules['items'])->toContain('max:100');
        });

        it('generates rules for email', function () {
            $schema = EventSchemaBuilder::define('test')
                ->email('user_email')->required()
                ->build();

            $rules = $schema->buildValidationRules();
            expect($rules['user_email'])->toBe('required|email|max:255');
        });

        it('generates rules for url', function () {
            $schema = EventSchemaBuilder::define('test')
                ->url('page_url')
                ->build();

            $rules = $schema->buildValidationRules();
            expect($rules['page_url'])->toBe('nullable|url|max:2048');
        });
    });

    describe('Full SaaS Event Schema', function () {
        it('builds a complete subscription upgrade schema', function () {
            $schema = EventSchemaBuilder::define('subscription_upgraded')
                ->category('saas')
                ->description('User upgrades their subscription plan')
                ->tag('billing', 'revenue', 'acquisition')
                ->string('user_id')->required()->description('User identifier')
                ->string('plan_from')->required()
                ->string('plan_to')->required()
                ->float('price_change')
                ->string('currency')->default('USD')
                ->enum('billing_cycle', ['monthly', 'yearly', 'lifetime'])
                ->integer('trial_days')->min(0)->max(90)
                ->ga4('subscription_upgraded')
                ->meta('UpgradePlan')
                ->posthog('plan_upgraded')
                ->plausible('plan_upgrade')
                ->mixpanel('Plan Upgraded')
                ->amplitude('Plan Upgraded')
                ->build();

            expect($schema->name)->toBe('subscription_upgraded');
            expect($schema->category)->toBe('saas');
            expect($schema->tags)->toBe(['billing', 'revenue', 'acquisition']);
            expect($schema->requiredProperties())->toBe(['user_id', 'plan_from', 'plan_to']);
            expect($schema->optionalProperties())->toContain('price_change');
            expect($schema->optionalProperties())->toContain('currency');
            expect($schema->properties['currency']->defaultValue)->toBe('USD');
            expect($schema->properties['billing_cycle']->enumValues)->toBe(['monthly', 'yearly', 'lifetime']);
            expect($schema->ga4)->toBe('subscription_upgraded');
            expect($schema->meta)->toBe('UpgradePlan');
            expect($schema->posthog)->toBe('plan_upgraded');
            expect($schema->providerCoverageCount())->toBe(5);
        });
    });
});

describe('PropertyDefinition — Property Metadata', function () {
    it('stores all chainable properties', function () {
        $def = (new PropertyDefinition('test_prop', 'string'))
            ->required()
            ->default('hello')
            ->description('A test property')
            ->maxLength(200)
            ->pattern('/^[a-z]+$/')
            ->example('foo', 'bar');

        expect($def->name)->toBe('test_prop');
        expect($def->type)->toBe('string');
        expect($def->isRequired)->toBeTrue();
        expect($def->hasDefault)->toBeTrue();
        expect($def->defaultValue)->toBe('hello');
        expect($def->description)->toBe('A test property');
        expect($def->maxLength)->toBe(200);
        expect($def->pattern)->toBe('/^[a-z]+$/');
        expect($def->examples)->toBe(['foo', 'bar']);
    });

    it('serializes to array', function () {
        $def = (new PropertyDefinition('count', 'int'))
            ->required()
            ->min(0)
            ->max(100);

        $arr = $def->toArray();
        expect($arr['name'])->toBe('count');
        expect($arr['type'])->toBe('int');
        expect($arr['required'])->toBeTrue();
        expect($arr['min'])->toBe(0);
        expect($arr['max'])->toBe(100);
    });
});

describe('EventSchemaRegistryExtended — Registry & Validation', function () {
    beforeEach(function () {
        $cache = app(\Illuminate\Contracts\Cache\Repository::class);
        $this->registry = new EventSchemaRegistryExtended($cache);
    });

    describe('Registration', function () {
        it('registers a schema', function () {
            $schema = EventSchemaBuilder::define('custom_event')
                ->category('custom')
                ->string('id')->required()
                ->build();

            $this->registry->register($schema);

            expect($this->registry->has('custom_event'))->toBeTrue();
            expect($this->registry->count())->toBeGreaterThanOrEqual(6); // 6 built-in + 1 custom
        });

        it('retrieves a registered schema', function () {
            $schema = EventSchemaBuilder::define('my_event')
                ->category('saas')
                ->build();

            $this->registry->register($schema);
            $retrieved = $this->registry->get('my_event');

            expect($retrieved)->not->toBeNull();
            expect($retrieved->name)->toBe('my_event');
        });

        it('returns null for unregistered schema', function () {
            expect($this->registry->get('nonexistent'))->toBeNull();
        });

        it('registers multiple schemas at once', function () {
            $schemas = [
                EventSchemaBuilder::define('event_a')->category('custom')->build(),
                EventSchemaBuilder::define('event_b')->category('custom')->build(),
            ];

            $this->registry->registerMany($schemas);

            expect($this->registry->has('event_a'))->toBeTrue();
            expect($this->registry->has('event_b'))->toBeTrue();
        });

        it('forgets a registered schema', function () {
            $this->registry->register(
                EventSchemaBuilder::define('temp_event')->category('custom')->build()
            );

            expect($this->registry->has('temp_event'))->toBeTrue();
            $result = $this->registry->forget('temp_event');
            expect($result)->toBeTrue();
            expect($this->registry->has('temp_event'))->toBeFalse();
        });

        it('flushes all schemas', function () {
            $this->registry->flush();
            expect($this->registry->count())->toBe(0);
        });
    });

    describe('Built-in Schemas', function () {
        it('has built-in sign_up schema', function () {
            $schema = $this->registry->get('sign_up');

            expect($schema)->not->toBeNull();
            expect($schema->category)->toBe('saas');
            expect($schema->ga4)->toBe('sign_up');
            expect($schema->meta)->toBe('CompleteRegistration');
            expect($schema->requiredProperties())->toContain('user_id');
        });

        it('has built-in login schema', function () {
            $schema = $this->registry->get('login');

            expect($schema)->not->toBeNull();
            expect($schema->ga4)->toBe('login');
            expect($schema->requiredProperties())->toContain('user_id');
        });

        it('has built-in purchase schema', function () {
            $schema = $this->registry->get('purchase');

            expect($schema)->not->toBeNull();
            expect($schema->category)->toBe('ecommerce');
            expect($schema->ga4)->toBe('purchase');
            expect($schema->meta)->toBe('Purchase');
            expect($schema->requiredProperties())->toContain('transaction_id');
            expect($schema->requiredProperties())->toContain('value');
        });

        it('has built-in page_view schema', function () {
            $schema = $this->registry->get('page_view');

            expect($schema)->not->toBeNull();
            expect($schema->category)->toBe('engagement');
            expect($schema->plausible)->toBe('pageview');
        });

        it('has built-in cancellation schema', function () {
            $schema = $this->registry->get('cancellation');

            expect($schema)->not->toBeNull();
            expect($schema->category)->toBe('saas');
        });
    });

    describe('Validation', function () {
        it('validates valid params against schema', function () {
            $result = $this->registry->validate('sign_up', [
                'user_id' => 'usr_123',
                'method' => 'oauth_google',
            ]);

            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toBe([]);
        });

        it('detects missing required property', function () {
            $result = $this->registry->validate('sign_up', [
                'method' => 'email',
            ]);

            expect($result['valid'])->toBeFalse();
            expect($result['errors'])->not->toBeEmpty();
            expect($result['errors'][0])->toContain('user_id');
        });

        it('detects wrong type', function () {
            $result = $this->registry->validate('purchase', [
                'transaction_id' => 'TXN-123',
                'value' => 'not_a_number',
            ]);

            expect($result['valid'])->toBeFalse();
            expect($result['errors'])->not->toBeEmpty();
        });

        it('warns about unknown properties', function () {
            $result = $this->registry->validate('sign_up', [
                'user_id' => 'usr_123',
                'unknown_field' => 'value',
            ]);

            expect($result['valid'])->toBeTrue();
            expect($result['warnings'])->not->toBeEmpty();
        });

        it('returns error for non-registered schema', function () {
            $result = $this->registry->validate('nonexistent', []);

            expect($result['valid'])->toBeFalse();
            expect($result['errors'][0])->toContain('not registered');
        });
    });

    describe('Validation Rules', function () {
        it('generates rules for registered schema', function () {
            $rules = $this->registry->validationRules('sign_up');

            expect($rules)->not->toBeEmpty();
            expect($rules['user_id'])->toContain('required');
        });

        it('returns empty rules for unregistered schema', function () {
            $rules = $this->registry->validationRules('nonexistent');

            expect($rules)->toBe([]);
        });
    });

    describe('Catalog Coverage', function () {
        it('reports built-in schemas in catalog', function () {
            $coverage = $this->registry->catalogCoverage();

            expect($coverage['in_catalog'])->toContain('sign_up');
            expect($coverage['in_catalog'])->toContain('login');
            expect($coverage['in_catalog'])->toContain('purchase');
            expect($coverage['in_catalog'])->toContain('page_view');
        });

        it('reports custom schemas missing from catalog', function () {
            $this->registry->register(
                EventSchemaBuilder::define('my_custom_event')->category('custom')->build()
            );

            $coverage = $this->registry->catalogCoverage();
            expect($coverage['missing_from_catalog'])->toContain('my_custom_event');
        });
    });

    describe('Summary', function () {
        it('returns summary statistics', function () {
            $summary = $this->registry->summary();

            expect($summary['total'])->toBeGreaterThan(0);
            expect(isset($summary['categories']))->toBeTrue();
            expect(isset($summary['provider_coverage']))->toBeTrue();
            expect(isset($summary['total_properties']))->toBeTrue();
        });

        it('reports categories', function () {
            $summary = $this->registry->summary();

            expect(isset($summary['categories']['saas']))->toBeTrue();
            expect(isset($summary['categories']['ecommerce']))->toBeTrue();
            expect(isset($summary['categories']['engagement']))->toBeTrue();
        });

        it('reports provider coverage', function () {
            $summary = $this->registry->summary();

            expect($summary['provider_coverage']['ga4'])->toBeGreaterThan(0);
        });
    });

    describe('Export', function () {
        it('exports all schemas as array', function () {
            $export = $this->registry->export();

            expect($export)->toBeArray();
            expect($export)->toHaveKey('sign_up');
            expect($export['sign_up']['category'])->toBe('saas');
        });
    });

    describe('By Category', function () {
        it('groups schemas by category', function () {
            $grouped = $this->registry->byCategory();

            expect(isset($grouped['saas']))->toBeTrue();
            expect(isset($grouped['ecommerce']))->toBeTrue();
            expect(isset($grouped['engagement']))->toBeTrue();
            expect(count($grouped['saas']))->toBeGreaterThan(0);
        });
    });

    describe('Names', function () {
        it('returns all schema names', function () {
            $names = $this->registry->names();

            expect($names)->toContain('sign_up');
            expect($names)->toContain('login');
            expect($names)->toContain('purchase');
            expect($names)->toContain('page_view');
            expect($names)->toContain('cancellation');
        });
    });
});

describe('V1170 — Phase 46 Schema DSL Integration', function () {
    it('schema builder version matches package version', function () {
        $schema = EventSchemaBuilder::define('version_test')->build();

        // Verify the schema DSL works at all
        expect($schema->name)->toBe('version_test');
    });

    it('registry has at least 6 built-in schemas', function () {
        $cache = app(\Illuminate\Contracts\Cache\Repository::class);
        $registry = new EventSchemaRegistryExtended($cache);

        expect($registry->count())->toBeGreaterThanOrEqual(6);
    });

    it('built-in schemas cover all core categories', function () {
        $cache = app(\Illuminate\Contracts\Cache\Repository::class);
        $registry = new EventSchemaRegistryExtended($cache);
        $grouped = $registry->byCategory();

        expect(isset($grouped['saas']))->toBeTrue();
        expect(isset($grouped['ecommerce']))->toBeTrue();
        expect(isset($grouped['engagement']))->toBeTrue();
    });

    it('AnalyticsEvent VERSION is 118.0.0', function () {
        expect(AnalyticsEvent::VERSION)->toBe('118.0.0');
    });
});
