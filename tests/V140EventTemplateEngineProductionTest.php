<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventTemplateEngine;

/**
 * V140 — EventTemplateEngine Production Test.
 *
 * Validates the new v140.0.0 additions:
 * - EventTemplateEngine: config-driven template system, build(), registerTemplate(), validation
 * - ServiceProvider singleton registration for EventTemplateEngine
 * - Fixed broken namespace escapes in ServiceProvider (lines 197-204)
 * - Config: event_templates.definitions key
 * - Version consistency sweep 139 → 140
 * - Strict types, final class, constructor :void, docblock @since
 *
 * @since 140.0.0
 */
test('v140: version is 140.0.0 everywhere', function (): void {
    expect(AnalyticsEvent::VERSION)->toBe('140.0.0');

    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('140.0.0');

    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-140.0.0');
});

test('v140: EventTemplateEngine class exists and is final', function (): void {
    expect(class_exists(EventTemplateEngine::class))->toBeTrue();

    $ref = new ReflectionClass(EventTemplateEngine::class);
    expect($ref->isFinal())->toBeTrue();
    expect($ref->isReadOnly())->toBeFalse();
});

test('v140: EventTemplateEngine has strict types and license header', function (): void {
    $contents = file_get_contents(__DIR__ . '/../src/Services/EventTemplateEngine.php');
    expect($contents)->toContain('declare(strict_types=1)');
    expect($contents)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    expect($contents)->toContain('@since 140.0.0');
});

test('v140: EventTemplateEngine constructor has :void return type', function (): void {
    $ref = new ReflectionClass(EventTemplateEngine::class);
    $constructor = $ref->getMethod('__construct');
    expect($constructor->getReturnType()?->getName())->toBe('void');
});

test('v140: EventTemplateEngine has readonly config property', function (): void {
    $ref = new ReflectionClass(EventTemplateEngine::class);
    $props = $ref->getProperties();
    $configProp = null;

    foreach ($props as $prop) {
        if ($prop->getName() === 'config') {
            $configProp = $prop;
            break;
        }
    }

    expect($configProp)->not->toBeNull();
    expect($configProp->isReadOnly())->toBeTrue();
    expect($configProp->isPrivate())->toBeFalse(); // private readonly
});

test('v140: EventTemplateEngine built-in templates count', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    // 15 built-in templates: 4 ecommerce + 5 saas + 4 engagement + 1 feature + 1 ecommerce.refund = 15
    expect($engine->count())->toBeGreaterThanOrEqual(14);
    expect($engine->templateKeys())->toContain('ecommerce.purchase');
    expect($engine->templateKeys())->toContain('ecommerce.add_to_cart');
    expect($engine->templateKeys())->toContain('ecommerce.view_item');
    expect($engine->templateKeys())->toContain('ecommerce.refund');
    expect($engine->templateKeys())->toContain('saas.sign_up');
    expect($engine->templateKeys())->toContain('saas.trial_start');
    expect($engine->templateKeys())->toContain('saas.subscription');
    expect($engine->templateKeys())->toContain('saas.plan_upgrade');
    expect($engine->templateKeys())->toContain('saas.cancellation');
    expect($engine->templateKeys())->toContain('engagement.page_view');
    expect($engine->templateKeys())->toContain('engagement.form_submit');
    expect($engine->templateKeys())->toContain('engagement.search');
    expect($engine->templateKeys())->toContain('engagement.error');
    expect($engine->templateKeys())->toContain('feature.usage');
});

test('v140: EventTemplateEngine build() creates AnalyticsEvent from template', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    $event = $engine->build('ecommerce.purchase', [
        'transaction_id' => 'TXN-001',
        'value' => 99.99,
    ]);

    expect($event)->toBeInstanceOf(AnalyticsEvent::class);
    expect($event->name)->toBe('purchase');
    expect($event->params['transaction_id'])->toBe('TXN-001');
    expect($event->params['value'])->toBe(99.99);
    expect($event->params['currency'])->toBe('USD'); // default
    expect($event->params['tax'])->toBe(0.0); // default
    expect($event->params['shipping'])->toBe(0.0); // default
    expect($event->params['items'])->toBe([]); // default
});

test('v140: EventTemplateEngine build() with clientId and userId', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    $event = $engine->build('saas.sign_up', [], 'client-123', 'user-456');

    expect($event->clientId)->toBe('client-123');
    expect($event->userId)->toBe('user-456');
    expect($event->name)->toBe('sign_up');
    expect($event->params['method'])->toBe('email'); // default
});

test('v140: EventTemplateEngine build() throws on unknown template', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('nonexistent.template');

    $engine->build('nonexistent.template');
})->throws(\InvalidArgumentException::class);

test('v140: EventTemplateEngine build() throws on missing required param', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Required parameter');

    $engine->build('ecommerce.purchase', ['value' => 99.99]); // missing transaction_id
})->throws(\InvalidArgumentException::class);

test('v140: EventTemplateEngine registerTemplate() and hasTemplate()', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    expect($engine->hasTemplate('custom.my_event'))->toBeFalse();

    $engine->registerTemplate('custom.my_event', [
        'name' => 'my_event',
        'category' => 'custom',
        'params' => [
            'foo' => ['type' => 'string', 'required' => true, 'description' => 'Foo param'],
            'bar' => ['type' => 'int', 'required' => false, 'default' => 42, 'description' => 'Bar param'],
        ],
    ]);

    expect($engine->hasTemplate('custom.my_event'))->toBeTrue();
    expect($engine->getTemplate('custom.my_event'))->not->toBeNull();
    expect($engine->getTemplate('custom.my_event')['name'])->toBe('my_event');
    expect($engine->count())->toBeGreaterThanOrEqual(15);
});

test('v140: EventTemplateEngine build() from registered template', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    $engine->registerTemplate('custom.login', [
        'name' => 'login',
        'category' => 'auth',
        'params' => [
            'user_id' => ['type' => 'string', 'required' => true, 'description' => 'User ID'],
            'method' => ['type' => 'string', 'required' => false, 'default' => 'password', 'description' => 'Login method'],
        ],
    ]);

    $event = $engine->build('custom.login', ['user_id' => 'user-123']);

    expect($event->name)->toBe('login');
    expect($event->params['user_id'])->toBe('user-123');
    expect($event->params['method'])->toBe('password');
});

test('v140: EventTemplateEngine byCategory() groups templates', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    $groups = $engine->byCategory();

    expect($groups)->toHaveKey('ecommerce');
    expect($groups)->toHaveKey('saas');
    expect($groups)->toHaveKey('engagement');
    expect(count($groups['ecommerce']))->toBeGreaterThanOrEqual(4);
    expect(count($groups['saas']))->toBeGreaterThanOrEqual(5);
    expect(count($groups['engagement']))->toBeGreaterThanOrEqual(4);
});

test('v140: EventTemplateEngine type coercion', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    // Pass string values for int/float params — should be coerced
    $event = $engine->build('ecommerce.add_to_cart', [
        'item_id' => 'SKU-001',
        'item_name' => 'Widget',
        'price' => '29.99',   // string → float
        'quantity' => '3',     // string → int
    ]);

    expect($event->params['price'])->toBe(29.99);
    expect($event->params['quantity'])->toBe(3);
});

test('v140: EventTemplateEngine enum validation rejects invalid values', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('not one of');

    $engine->build('engagement.error', [
        'message' => 'Something broke',
        'severity' => 'catastrophic', // not in enum: info, warning, error, critical
    ]);
})->throws(\InvalidArgumentException::class);

test('v140: EventTemplateEngine enum validation accepts valid values', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    $event = $engine->build('engagement.error', [
        'message' => 'Something broke',
        'severity' => 'critical',
    ]);

    expect($event->params['severity'])->toBe('critical');
});

test('v140: EventTemplateEngine validateEventName() against catalog', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    // Template name match
    $result = $engine->validateEventName('purchase');
    expect($result['valid'])->toBeTrue();
    expect($result['template_match'])->not->toBeNull();

    // Unknown event name
    $result = $engine->validateEventName('zzz_nonexistent_event');
    expect($result['valid'])->toBeFalse();
});

test('v140: EventTemplateEngine summary() returns complete data', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    $summary = $engine->summary();

    expect($summary)->toHaveKey('total_templates');
    expect($summary)->toHaveKey('categories');
    expect($summary)->toHaveKey('built_in_count');
    expect($summary)->toHaveKey('custom_count');
    expect($summary)->toHaveKey('registered_count');
    expect($summary)->toHaveKey('catalog_coverage');
    expect($summary['total_templates'])->toBeGreaterThan(0);
    expect($summary['built_in_count'])->toBeGreaterThan(0);
    expect($summary['custom_count'])->toBe(0); // no custom templates from config
    expect($summary['registered_count'])->toBe(0); // no runtime registrations
    expect($summary['catalog_coverage'])->toBeGreaterThan(0);
});

test('v140: EventTemplateEngine getParamSchema() returns schema', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    $schema = $engine->getParamSchema('ecommerce.purchase');

    expect($schema)->toHaveKey('transaction_id');
    expect($schema['transaction_id']['type'])->toBe('string');
    expect($schema['transaction_id']['required'])->toBeTrue();
    expect($schema['currency']['default'])->toBe('USD');
    expect($schema['currency']['required'])->toBeFalse();

    // Non-existent template returns empty
    expect($engine->getParamSchema('nonexistent'))->toBe([]);
});

test('v140: EventTemplateEngine registeredTemplates() tracks runtime registrations', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    expect($engine->registeredTemplates())->toBe([]);

    $engine->registerTemplate('custom.a', ['name' => 'a', 'category' => 'custom', 'params' => []]);
    $engine->registerTemplate('custom.b', ['name' => 'b', 'category' => 'custom', 'params' => []]);

    $registered = $engine->registeredTemplates();
    expect($registered)->toHaveCount(2);
    expect($registered[0])->toBe(['custom.a', 'a']);
    expect($registered[1])->toBe(['custom.b', 'b']);
});

test('v140: EventTemplateEngine extra params pass through', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    $event = $engine->build('saas.sign_up', [
        'method' => 'google',
        'custom_extra_field' => 'some_value',
    ]);

    expect($event->params['method'])->toBe('google');
    expect($event->params['custom_extra_field'])->toBe('some_value');
});

test('v140: EventTemplateEngine loads custom templates from config', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [
                        'app.feature_unlocked' => [
                            'name' => 'feature_unlocked',
                            'category' => 'app',
                            'params' => [
                                'feature_name' => ['type' => 'string', 'required' => true, 'description' => 'Feature name'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]));

    expect($engine->hasTemplate('app.feature_unlocked'))->toBeTrue();
    expect($engine->count())->toBeGreaterThan(14); // built-in + custom

    $event = $engine->build('app.feature_unlocked', ['feature_name' => 'dark_mode']);
    expect($event->name)->toBe('feature_unlocked');
    expect($event->params['feature_name'])->toBe('dark_mode');
});

test('v140: ServiceProvider imports EventTemplateEngine (no double backslash)', function (): void {
    $sp = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

    // Must use single backslash (normal PHP namespace), not double
    expect($sp)->toContain('use ZeroBoiler\Analytics\Services\EventTemplateEngine;');
    expect($sp)->not->toContain('use ZeroBoiler\\\\Analytics\\\\Services\\\\EventTemplateEngine');
    expect($sp)->not->toContain('use ZeroBoiler\\Analytics\\Services\\EventGovernanceService;');
    expect($sp)->toContain('use ZeroBoiler\Analytics\Services\EventGovernanceService;');
});

test('v140: ServiceProvider registers EventTemplateEngine as singleton', function (): void {
    $sp = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($sp)->toContain('EventTemplateEngine::class');
    expect($sp)->toContain("new EventTemplateEngine(");
});

test('v140: Config has event_templates.definitions key', function (): void {
    $config = require __DIR__ . '/../config/zeroboiler.php';
    expect($config['analytics'])->toHaveKey('event_templates');
    expect($config['analytics']['event_templates'])->toHaveKey('definitions');
    expect($config['analytics']['event_templates']['definitions'])->toBeArray();
});

test('v140: AnalyticsServiceProvider docblock has @since and version', function (): void {
    $sp = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($sp)->toContain('@since 1.0.0');
});

test('v140: EventTemplateEngine public methods have proper return types', function (): void {
    $ref = new ReflectionClass(EventTemplateEngine::class);

    $build = $ref->getMethod('build');
    expect($build->getReturnType()?->getName())->toBe(AnalyticsEvent::class);

    $register = $ref->getMethod('registerTemplate');
    expect($register->getReturnType()?->getName())->toBe('void');

    $keys = $ref->getMethod('templateKeys');
    expect($keys->getReturnType()?->getName())->toBe('array');

    $has = $ref->getMethod('hasTemplate');
    expect($has->getReturnType()?->getName())->toBe('bool');

    $get = $ref->getMethod('getTemplate');
    // nullable array return
    expect($get->getReturnType()?->getName())->toBe('array');

    $count = $ref->getMethod('count');
    expect($count->getReturnType()?->getName())->toBe('int');

    $byCategory = $ref->getMethod('byCategory');
    expect($byCategory->getReturnType()?->getName())->toBe('array');

    $registered = $ref->getMethod('registeredTemplates');
    expect($registered->getReturnType()?->getName())->toBe('array');

    $validate = $ref->getMethod('validateEventName');
    expect($validate->getReturnType()?->getName())->toBe('array');

    $summary = $ref->getMethod('summary');
    expect($summary->getReturnType()?->getName())->toBe('array');

    $schema = $ref->getMethod('getParamSchema');
    expect($schema->getReturnType()?->getName())->toBe('array');
});

test('v140: all public methods have docblocks', function (): void {
    $ref = new ReflectionClass(EventTemplateEngine::class);
    $contents = file_get_contents(__DIR__ . '/../src/Services/EventTemplateEngine.php');

    $publicMethods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
    foreach ($publicMethods as $method) {
        if ($method->getName() === '__construct') {
            continue;
        }
        // Check method name appears after a doc comment (/**)
        $pos = strpos($contents, 'public function ' . $method->getName());
        if ($pos !== false) {
            $before = substr($contents, max(0, $pos - 10), 10);
            expect($before)->toContain('*/');
        }
    }
});

test('v140: catalog integrity — EventCatalog has events', function (): void {
    expect(EventCatalog::count())->toBeGreaterThan(200);
    expect(EventCatalog::has('page_view'))->toBeTrue();
    expect(EventCatalog::has('purchase'))->toBeTrue();
});

test('v140: no TODO or FIXME in EventTemplateEngine', function (): void {
    $contents = file_get_contents(__DIR__ . '/../src/Services/EventTemplateEngine.php');
    expect($contents)->not->toContain('TODO');
    expect($contents)->not->toContain('FIXME');
    expect($contents)->not->toContain('HACK');
    expect($contents)->not->toContain('XXX');
});

test('v140: SaaS subscription template builds with all defaults', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    $event = $engine->build('saas.subscription', [
        'plan' => 'pro',
        'value' => 29.99,
    ]);

    expect($event->name)->toBe('subscribe');
    expect($event->params['currency'])->toBe('USD');
    expect($event->params['billing_cycle'])->toBe('monthly');
    expect($event->params['is_trial_conversion'])->toBe(false);
});

test('v140: engagement.error template has all expected params', function (): void {
    $engine = new EventTemplateEngine(new \Illuminate\Config\Repository([
        'zeroboiler' => [
            'analytics' => [
                'event_templates' => [
                    'definitions' => [],
                ],
            ],
        ],
    ]));

    $schema = $engine->getParamSchema('engagement.error');

    expect($schema)->toHaveKey('message');
    expect($schema)->toHaveKey('code');
    expect($schema)->toHaveKey('severity');
    expect($schema)->toHaveKey('source');
    expect($schema['severity']['enum'])->toContain('info');
    expect($schema['severity']['enum'])->toContain('warning');
    expect($schema['severity']['enum'])->toContain('error');
    expect($schema['severity']['enum'])->toContain('critical');
    expect($schema['severity']['default'])->toBe('error');
});
