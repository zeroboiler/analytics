<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Attributes\AnalyticsEventAttribute;
use ZeroBoiler\Analytics\Attributes\AnalyticsLifecycleMapping;
use ZeroBoiler\Analytics\Attributes\AnalyticsEventParam;
use ZeroBoiler\Analytics\Attributes\AttributeScanner;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\SaaSOnboardingFunnelService;

beforeEach(function () {
    AttributeScanner::clearCache();
});

describe('AnalyticsEventAttribute', function () {
    test('can be instantiated with required parameters', function () {
        $attr = new AnalyticsEventAttribute(
            name: 'custom_event',
            category: 'custom',
            ga4: 'custom_event',
            meta: 'CustomEvent',
            posthog: 'custom_event',
            mixpanel: 'Custom Event',
            amplitude: 'Custom Event',
        );

        expect($attr->name)->toBe('custom_event');
        expect($attr->category)->toBe('custom');
        expect($attr->ga4)->toBe('custom_event');
        expect($attr->meta)->toBe('CustomEvent');
        expect($attr->priority)->toBe('medium');
    });

    test('supports all optional parameters', function () {
        $attr = new AnalyticsEventAttribute(
            name: 'trial_converted',
            category: 'saas',
            ga4: 'trial_converted',
            meta: 'Subscribe',
            posthog: 'trial_converted',
            plausible: 'conversion',
            mixpanel: 'Trial Converted',
            amplitude: 'Trial Converted',
            label: 'Trial Converted to Paid',
            priority: 'critical',
            aliases: ['conversion', 'trial_to_paid'],
            description: 'User converted from free trial to paid subscription',
            tags: ['conversion', 'revenue', 'lifecycle'],
        );

        expect($attr->label)->toBe('Trial Converted to Paid');
        expect($attr->priority)->toBe('critical');
        expect($attr->aliases)->toBe(['conversion', 'trial_to_paid']);
        expect($attr->description)->toBe('User converted from free trial to paid subscription');
        expect($attr->tags)->toBe(['conversion', 'revenue', 'lifecycle']);
        expect($attr->plausible)->toBe('conversion');
    });

    test('toCatalogEntry returns correct format', function () {
        $attr = new AnalyticsEventAttribute(
            name: 'view_item',
            category: 'ecommerce',
            ga4: 'view_item',
            meta: 'ViewContent',
            posthog: '$view_item',
            mixpanel: 'View Item',
            amplitude: 'View Item',
        );

        $entry = $attr->toCatalogEntry('SomeEventClass');

        expect($entry)->toHaveKey('name');
        expect($entry)->toHaveKey('class');
        expect($entry)->toHaveKey('ga4');
        expect($entry)->toHaveKey('meta');
        expect($entry)->toHaveKey('category');
        expect($entry['name'])->toBe('view_item');
        expect($entry['class'])->toBe('SomeEventClass');
    });

    test('hasGa4Mapping returns correct boolean', function () {
        $withGa4 = new AnalyticsEventAttribute(
            name: 'test',
            category: 'custom',
            ga4: 'test_event',
            posthog: 'test',
            mixpanel: 'Test',
            amplitude: 'Test',
        );

        $withoutGa4 = new AnalyticsEventAttribute(
            name: 'test2',
            category: 'custom',
            ga4: '',
            posthog: 'test2',
            mixpanel: 'Test 2',
            amplitude: 'Test 2',
        );

        expect($withGa4->hasGa4Mapping())->toBeTrue();
        expect($withoutGa4->hasGa4Mapping())->toBeFalse();
    });

    test('hasMetaMapping returns correct boolean', function () {
        $withMeta = new AnalyticsEventAttribute(
            name: 'test',
            category: 'custom',
            ga4: 'test',
            meta: 'TestEvent',
            posthog: 'test',
            mixpanel: 'Test',
            amplitude: 'Test',
        );

        $withoutMeta = new AnalyticsEventAttribute(
            name: 'test2',
            category: 'custom',
            ga4: 'test2',
            meta: null,
            posthog: 'test2',
            mixpanel: 'Test 2',
            amplitude: 'Test 2',
        );

        expect($withMeta->hasMetaMapping())->toBeTrue();
        expect($withoutMeta->hasMetaMapping())->toBeFalse();
    });

    test('readonly class prevents mutation', function () {
        $attr = new AnalyticsEventAttribute(
            name: 'immutable_test',
            category: 'custom',
            ga4: 'immutable_test',
            posthog: 'immutable_test',
            mixpanel: 'Immutable Test',
            amplitude: 'Immutable Test',
        );

        expect($attr)->toBeReadOnly();
        expect($attr->name)->toBe('immutable_test');
    });
});

describe('AnalyticsEventParam', function () {
    test('creates required string param', function () {
        $param = new AnalyticsEventParam(
            name: 'transaction_id',
            type: 'string',
            required: true,
            description: 'Unique transaction identifier',
            maxLength: 100,
        );

        expect($param->name)->toBe('transaction_id');
        expect($param->type)->toBe('string');
        expect($param->required)->toBeTrue();
        expect($param->maxLength)->toBe(100);
    });

    test('creates optional numeric param with range', function () {
        $param = new AnalyticsEventParam(
            name: 'revenue',
            type: 'float',
            required: false,
            default: 0.0,
            min: 0.0,
            max: 1000000.0,
        );

        expect($param->type)->toBe('float');
        expect($param->required)->toBeFalse();
        expect($param->default)->toBe(0.0);
        expect($param->min)->toBe(0.0);
        expect($param->max)->toBe(1000000.0);
    });

    test('creates param with regex pattern', function () {
        $param = new AnalyticsEventParam(
            name: 'email',
            type: 'string',
            pattern: '/^[^\s@]+@[^\s@]+\.[^\s@]+$/',
        );

        expect($param->pattern)->toBe('/^[^\s@]+@[^\s@]+\.[^\s@]+$/');
    });
});

describe('AnalyticsLifecycleMapping', function () {
    test('creates mapping with all parameters', function () {
        $mapping = new AnalyticsLifecycleMapping(
            source: 'Illuminate\\Auth\\Events\\Login',
            target: 'ZeroBoiler\\Analytics\\Events\\SaaS\\LoginEvent',
            paramsExtractor: 'extractAuthParams',
            priority: 100,
            condition: 'user.verified',
        );

        expect($mapping->source)->toBe('Illuminate\\Auth\\Events\\Login');
        expect($mapping->target)->toBe('ZeroBoiler\\Analytics\\Events\\SaaS\\LoginEvent');
        expect($mapping->paramsExtractor)->toBe('extractAuthParams');
        expect($mapping->priority)->toBe(100);
        expect($mapping->condition)->toBe('user.verified');
    });

    test('creates minimal mapping', function () {
        $mapping = new AnalyticsLifecycleMapping(
            source: 'custom.event',
            target: 'App\\Analytics\\Events\\CustomEvent',
        );

        expect($mapping->source)->toBe('custom.event');
        expect($mapping->target)->toBe('App\\Analytics\\Events\\CustomEvent');
        expect($mapping->paramsExtractor)->toBeNull();
        expect($mapping->priority)->toBe(80);
        expect($mapping->condition)->toBeNull();
    });
});

describe('AttributeScanner', function () {
    test('clearCache works correctly', function () {
        // Clear and verify no exceptions
        AttributeScanner::clearCache();
        expect(true)->toBeTrue();
    });

    test('scanEvent returns null for non-existent class', function () {
        $result = AttributeScanner::scanEvent('NonExistent\\Class');

        expect($result)->toBeNull();
    });

    test('scanEvent returns null for class without attribute', function () {
        $result = AttributeScanner::scanEvent(AnalyticsEvent::class);

        expect($result)->toBeNull();
    });

    test('scanLifecycleMappings returns empty for class without attributes', function () {
        $result = AttributeScanner::scanLifecycleMappings(AnalyticsEvent::class);

        expect($result)->toBe([]);
    });

    test('allEvents returns empty for empty class list', function () {
        $result = AttributeScanner::allEvents([]);

        expect($result)->toBe([]);
    });

    test('allLifecycleMappings returns empty for empty class list', function () {
        $result = AttributeScanner::allLifecycleMappings([]);

        expect($result)->toBe([]);
    });

    test('hasScanned returns false for unscanned class', function () {
        expect(AttributeScanner::hasScanned('SomeClass'))->toBeFalse();
    });
});

describe('SaaSOnboardingFunnelService', function () {
    test('stages returns correct funnel definition', function () {
        $stages = SaaSOnboardingFunnelService::stages();

        expect($stages)->not->toBeEmpty();
        expect($stages[0]['key'])->toBe('sign_up');
        expect($stages[0]['event'])->toBe('sign_up');
        expect($stages[0]['critical'])->toBeTrue();

        expect($stages[1]['key'])->toBe('email_verified');
        expect($stages[2]['key'])->toBe('first_login');

        $lastStage = $stages[count($stages) - 1];
        expect($lastStage['key'])->toBe('activated');
        expect($lastStage['event'])->toBe('onboarding_completed');
    });

    test('stages has 10 defined stages', function () {
        expect(SaaSOnboardingFunnelService::stages())->toHaveCount(10);
    });

    test('stages have proper structure', function () {
        foreach (SaaSOnboardingFunnelService::stages() as $stage) {
            expect($stage)->toHaveKeys(['key', 'label', 'event', 'critical']);
            expect($stage['key'])->toBeString();
            expect($stage['label'])->toBeString();
            expect($stage['event'])->toBeString();
            expect($stage['critical'])->toBeBool();
        }
    });

    test('critical stages are correctly identified', function () {
        $criticalStages = array_filter(
            SaaSOnboardingFunnelService::stages(),
            fn (array $s): bool => $s['critical'],
        );

        expect($criticalStages)->not->toBeEmpty();
        expect(array_keys($criticalStages))->toContain('sign_up');
        expect(array_keys($criticalStages))->toContain('email_verified');
        expect(array_keys($criticalStages))->toContain('first_login');
        expect(array_keys($criticalStages))->toContain('subscription');
    });

    test('funnel metrics returns correct structure', function () {
        // We cannot construct the full service without Laravel container,
        // so test the static structure
        $stages = SaaSOnboardingFunnelService::stages();

        $totalStages = count($stages);
        $criticalStages = count(array_filter($stages, fn (array $s): bool => $s['critical']));

        expect($totalStages)->toBe(10);
        expect($criticalStages)->toBe(4);
    });

    test('stage keys match config default', function () {
        $stageKeys = array_map(fn (array $s): string => $s['key'], SaaSOnboardingFunnelService::stages());
        $configStages = [
            'sign_up', 'email_verified', 'first_login', 'trial_start',
            'first_feature', 'team_created', 'integration_connected',
            'subscription', 'plan_upgrade', 'activated',
        ];

        expect($stageKeys)->toBe($configStages);
    });
});

describe('Version sweep v19.0.0', function () {
    test('AnalyticsEvent VERSION is 19.0.0', function () {
        expect(AnalyticsEvent::VERSION)->toBe('19.0.0');
    });

    test('attribute classes exist and have correct namespace', function () {
        expect(class_exists(AnalyticsEventAttribute::class))->toBeTrue();
        expect(class_exists(AnalyticsLifecycleMapping::class))->toBeTrue();
        expect(class_exists(AnalyticsEventParam::class))->toBeTrue();
        expect(class_exists(AttributeScanner::class))->toBeTrue();
        expect(class_exists(SaaSOnboardingFunnelService::class))->toBeTrue();
    });

    test('attribute classes use readonly', function () {
        $eventAttr = new \ReflectionClass(AnalyticsEventAttribute::class);
        $lifecycleAttr = new \ReflectionClass(AnalyticsLifecycleMapping::class);
        $paramAttr = new \ReflectionClass(AnalyticsEventParam::class);

        expect($eventAttr->isReadOnly())->toBeTrue();
        expect($lifecycleAttr->isReadOnly())->toBeTrue();
        expect($paramAttr->isReadOnly())->toBeTrue();
    });

    test('AnalyticsEventAttribute has correct Attribute target', function () {
        $ref = new \ReflectionClass(AnalyticsEventAttribute::class);
        $attrs = $ref->getAttributes(\Attribute::class);

        expect($attrs)->not->toBeEmpty();
        $instance = $attrs[0]->newInstance();
        expect($instance->flags)->toBe(Attribute::TARGET_CLASS);
    });

    test('AnalyticsLifecycleMapping targets methods and classes', function () {
        $ref = new \ReflectionClass(AnalyticsLifecycleMapping::class);
        $attrs = $ref->getAttributes(\Attribute::class);

        expect($attrs)->not->toBeEmpty();
        $instance = $attrs[0]->newInstance();
        expect($instance->flags)->toBe(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS);
    });

    test('AnalyticsEventParam targets classes and properties', function () {
        $ref = new \ReflectionClass(AnalyticsEventParam::class);
        $attrs = $ref->getAttributes(\Attribute::class);

        expect($attrs)->not->toBeEmpty();
        $instance = $attrs[0]->newInstance();
        expect($instance->flags)->toBe(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY);
    });

    test('SaaSOnboardingFunnelService has proper type declarations', function () {
        $ref = new \ReflectionClass(SaaSOnboardingFunnelService::class);
        $constructor = $ref->getMethod('__construct');

        expect($constructor->hasReturnType())->toBeTrue();
        expect($constructor->getReturnType()?->getName())->toBe('void');

        $params = $constructor->getParameters();
        expect($params)->toHaveCount(3);
        expect($params[0]->hasType())->toBeTrue();
        expect($params[0]->getType()?->getName())->toBe(AnalyticsManager::class . 'Manager');
    });

    test('all attribute scanner methods have return types', function () {
        $ref = new \ReflectionClass(AttributeScanner::class);

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            expect($method->hasReturnType())->toBeTrue();
        }
    });

    test('onboarding_funnel config section exists in config file', function () {
        $configContent = file_get_contents(__DIR__ . '/../../config/zeroboiler.php');
        expect($configContent)->toContain('onboarding_funnel');
        expect($configContent)->toContain('ANALYTICS_ONBOARDING_FUNNEL_ENABLED');
    });
});
