<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Blueprints\EventBlueprint;
use ZeroBoiler\Analytics\Blueprints\EventBlueprintBuilderService;
use ZeroBoiler\Analytics\Blueprints\EventBlueprintRegistry;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

describe('EventBlueprintBuilderService', function () {

    /** @var EventBlueprintRegistry */
    $registry;
    /** @var ConfigRepository */
    $config;
    /** @var EventBlueprintBuilderService */
    $builder;

    beforeEach(function () {
        $cache = mock(CacheRepository::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('has')->andReturn(false);
        $cache->shouldReceive('set')->andReturn(true);
        $cache->shouldReceive('forget')->andReturn(true);

        $registry = new EventBlueprintRegistry($cache);

        // Register a test blueprint
        $blueprint = new EventBlueprint(
            name: 'test.signup.completed',
            label: 'Test Signup Completed',
            description: 'Test blueprint',
            baseEvent: 'sign_up',
            category: 'saas',
            defaultParams: ['signup_method' => 'email', 'plan' => 'free'],
            requiredParams: ['user_id'],
            paramTypes: ['user_id' => 'string', 'age' => 'int', 'price' => 'float', 'active' => 'bool', 'tags' => 'array', 'quantity' => 'int'],
            priority: 'normal',
            version: '1.0.0',
        );

        // Use reflection to register the blueprint
        $ref = new ReflectionClass($registry);
        $prop = $ref->getProperty('blueprints');
        $prop->setValue($registry, ['test.signup.completed' => $blueprint]);

        $this->registry = $registry;

        $config = mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.blueprint_builder.pii_fields', [])
            ->andReturn([]);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.blueprint_builder.enabled', true)
            ->andReturn(true);

        $this->config = $config;
        $this->builder = new EventBlueprintBuilderService($registry, $config);
    });

    describe('fluent API', function () {
        it('returns self from all fluent methods', function () {
            $b = $this->builder;

            expect($b->from('test.signup.completed'))->toBe($b);
            expect($b->with(['user_id' => '1']))->toBe($b);
            expect($b->set('key', 'val'))->toBe($b);
            expect($b->compute(['value' => 'price * quantity']))->toBe($b);
            expect($b->clientId('cli_1'))->toBe($b);
            expect($b->userId('usr_1'))->toBe($b);
            expect($b->priority('high'))->toBe($b);
            expect($b->source('api'))->toBe($b);
            expect($b->sessionId('sess_1'))->toBe($b);
            expect($b->redactFields(['email']))->toBe($b);
            expect($b->autoCoerce(false))->toBe($b);
        });

        it('resets state when from() is called', function () {
            $report1 = $this->builder->from('test.signup.completed')
                ->with(['user_id' => '1'])
                ->buildReport();

            // Call from() again — should reset
            $report2 = $this->builder->from('test.signup.completed')
                ->buildReport();

            // report2 should have default params but no user_id
            expect($report2['params'])->not->toHaveKey('user_id');
            expect($report2['errors'])->toContain('Missing required parameter(s): user_id');
        });
    });

    describe('build()', function () {
        it('builds an event from a blueprint with required params', function () {
            $event = $this->builder->from('test.signup.completed')
                ->with(['user_id' => 'usr_123'])
                ->build();

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('sign_up');
            expect($event->params['user_id'])->toBe('usr_123');
            expect($event->params['signup_method'])->toBe('email');
            expect($event->params['plan'])->toBe('free');
            expect($event->priority)->toBe('normal');
            expect($event->category)->toBe('saas');
        });

        it('throws when required params are missing', function () {
            $this->builder->from('test.signup.completed')->build();
        })->throws(InvalidArgumentException::class, "Blueprint 'test.signup.completed' validation failed");

        it('throws when no blueprint selected', function () {
            // Create a fresh builder without calling from()
            new EventBlueprintBuilderService($this->registry, $this->config)->build();
        })->throws(LogicException::class, 'No blueprint selected');

        it('throws when blueprint not found', function () {
            $this->builder->from('nonexistent.blueprint')->build();
        })->throws(InvalidArgumentException::class, "Blueprint 'nonexistent.blueprint' not found in registry");

        it('sets clientId, userId, source, sessionId, priority overrides', function () {
            $event = $this->builder->from('test.signup.completed')
                ->with(['user_id' => 'u1'])
                ->clientId('cli_99')
                ->userId('usr_99')
                ->source('webhook')
                ->sessionId('sess_99')
                ->priority('critical')
                ->build();

            expect($event->clientId)->toBe('cli_99');
            expect($event->userId)->toBe('usr_99');
            expect($event->source)->toBe('webhook');
            expect($event->sessionId)->toBe('sess_99');
            expect($event->priority)->toBe('critical');
        });
    });

    describe('buildReport()', function () {
        it('returns detailed report with event, errors, warnings, coerced', function () {
            $report = $this->builder->from('test.signup.completed')
                ->with(['user_id' => '123', 'age' => '25'])
                ->buildReport();

            expect($report)->toHaveKeys(['event', 'errors', 'warnings', 'coerced', 'blueprint', 'params']);
            expect($report['event'])->toBeInstanceOf(AnalyticsEvent::class);
            expect($report['blueprint'])->toBe('test.signup.completed');
            expect($report['coerced'])->toContain('age: string → int');
            expect($report['errors'])->toBe([]);
        });

        it('returns errors for missing required params', function () {
            $report = $this->builder->from('test.signup.completed')->buildReport();

            expect($report['errors'])->not->toBeEmpty();
            expect($report['errors'][0])->toContain('Missing required parameter');
        });

        it('returns errors for nonexistent blueprint', function () {
            $report = $this->builder->from('ghost.event')->buildReport();

            expect($report['errors'])->toContain("Blueprint 'ghost.event' not found");
            expect($report['event']->name)->toBe('ghost.event');
        });
    });

    describe('type coercion', function () {
        it('coerces string to int', function () {
            $report = $this->builder->from('test.signup.completed')
                ->with(['user_id' => '1', 'age' => '30'])
                ->buildReport();

            expect($report['params']['age'])->toBe(30);
            expect($report['coerced'])->toContain('age: string → int');
        });

        it('coerces string to float', function () {
            $report = $this->builder->from('test.signup.completed')
                ->with(['user_id' => '1', 'price' => '9.99'])
                ->buildReport();

            expect($report['params']['price'])->toBe(9.99);
            expect($report['coerced'])->toContain('price: string → float');
        });

        it('coerces string to bool', function () {
            $report = $this->builder->from('test.signup.completed')
                ->with(['user_id' => '1', 'active' => 'yes'])
                ->buildReport();

            expect($report['params']['active'])->toBe(true);
            expect($report['coerced'])->toContain('active: string → bool');
        });

        it('coerces scalar to array', function () {
            $report = $this->builder->from('test.signup.completed')
                ->with(['user_id' => '1', 'tags' => 'tag1'])
                ->buildReport();

            expect($report['params']['tags'])->toBe(['tag1']);
            expect($report['coerced'])->toContain('tags: string → array');
        });

        it('skips coercion when autoCoerce is disabled', function () {
            $report = $this->builder->from('test.signup.completed')
                ->with(['user_id' => '1', 'age' => '30'])
                ->autoCoerce(false)
                ->buildReport();

            expect($report['params']['age'])->toBe('30');
            expect($report['coerced'])->toBe([]);
        });
    });

    describe('computed params', function () {
        it('evaluates arithmetic expressions', function () {
            $report = $this->builder->from('test.signup.completed')
                ->with(['user_id' => '1', 'price' => '10.0', 'quantity' => '3'])
                ->compute(['total_value' => 'price * quantity'])
                ->buildReport();

            expect($report['params']['total_value'])->toBe(30.0);
        });

        it('evaluates count() function', function () {
            $report = $this->builder->from('test.signup.completed')
                ->with(['user_id' => '1', 'tags' => ['a', 'b', 'c']])
                ->compute(['tag_count' => 'count(tags)'])
                ->buildReport();

            expect($report['params']['tag_count'])->toBe(3);
        });

        it('evaluates upper()/lower() functions', function () {
            $report = $this->builder->from('test.signup.completed')
                ->with(['user_id' => '1', 'signup_method' => 'email'])
                ->compute(['method_upper' => 'upper(signup_method)', 'method_lower' => 'lower(signup_method)'])
                ->buildReport();

            expect($report['params']['method_upper'])->toBe('EMAIL');
            expect($report['params']['method_lower'])->toBe('email');
        });

        it('resolves plain variable references', function () {
            $report = $this->builder->from('test.signup.completed')
                ->with(['user_id' => '1', 'plan' => 'pro'])
                ->compute(['plan_copy' => 'plan'])
                ->buildReport();

            expect($report['params']['plan_copy'])->toBe('pro');
        });

        it('returns null for division by zero', function () {
            $report = $this->builder->from('test.signup.completed')
                ->with(['user_id' => '1', 'quantity' => '0', 'price' => '10'])
                ->compute(['ratio' => 'price / quantity'])
                ->buildReport();

            expect($report['params'])->not->toHaveKey('ratio');
        });
    });

    describe('toProviderPayloads()', function () {
        it('returns ga4, meta, posthog, and raw payloads', function () {
            $payloads = $this->builder->from('test.signup.completed')
                ->with(['user_id' => 'usr_1'])
                ->clientId('cli_1')
                ->toProviderPayloads();

            expect($payloads)->toHaveKeys(['ga4', 'meta', 'posthog', 'raw']);
            expect($payloads['ga4'])->toHaveKey('event_name');
            expect($payloads['ga4']['client_id'])->toBe('cli_1');
            expect($payloads['posthog'])->toHaveKey('event');
            expect($payloads['posthog']['distinct_id'])->toBe('cli_1');
        });

        it('redacts PII fields from payloads', function () {
            // Config with custom PII fields
            $config = mock(ConfigRepository::class);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.blueprint_builder.pii_fields', [])
                ->andReturn(['email', 'secret']);
            $config->shouldReceive('get')
                ->with('zeroboiler.analytics.blueprint_builder.enabled', true)
                ->andReturn(true);

            $builder = new EventBlueprintBuilderService($this->registry, $config);

            $payloads = $builder->from('test.signup.completed')
                ->with(['user_id' => '1', 'user_email' => 'test@example.com', 'secret_key' => 'abc'])
                ->toProviderPayloads();

            expect($payloads['raw']['user_email'])->toBe('[REDACTED]');
            expect($payloads['raw']['secret_key'])->toBe('[REDACTED]');
            expect($payloads['raw']['user_id'])->toBe('1');
        });
    });

    describe('buildBatch()', function () {
        it('builds multiple events from param variations', function () {
            $results = $this->builder->from('test.signup.completed')
                ->with(['signup_method' => 'google'])
                ->buildBatch([
                    ['user_id' => 'u1'],
                    ['user_id' => 'u2'],
                    ['user_id' => 'u3'],
                ]);

            expect($results)->toHaveCount(3);
            expect($results[0]['event']->params['user_id'])->toBe('u1');
            expect($results[1]['event']->params['user_id'])->toBe('u2');
            expect($results[2]['event']->params['user_id'])->toBe('u3');
            // Base params should persist
            expect($results[0]['event']->params['signup_method'])->toBe('google');
        });

        it('restores base params after batch', function () {
            $this->builder->from('test.signup.completed')
                ->with(['user_id' => 'base'])
                ->buildBatch([
                    ['user_id' => 'var1'],
                ]);

            // Builder state should still have base params
            $report = $this->builder->buildReport();
            expect($report['params']['user_id'])->toBe('base');
        });
    });

    describe('schema()', function () {
        it('returns blueprint schema as array', function () {
            $schema = $this->builder->schema('test.signup.completed');

            expect($schema)->not->toBeNull();
            expect($schema)->toHaveKeys(['name', 'label', 'category', 'base_event', 'required', 'optional', 'types', 'defaults']);
            expect($schema['name'])->toBe('test.signup.completed');
            expect($schema['required'])->toContain('user_id');
            expect($schema['types']['user_id'])->toBe('string');
            expect($schema['defaults']['signup_method'])->toBe('email');
        });

        it('returns null for unknown blueprint', function () {
            expect($this->builder->schema('nonexistent'))->toBeNull();
        });

        it('returns null when no blueprint selected and no name given', function () {
            expect($this->builder->schema(null))->toBeNull();
        });
    });

    describe('allSchemas()', function () {
        it('returns list of all blueprint summaries', function () {
            $schemas = $this->builder->allSchemas();

            expect($schemas)->not->toBeEmpty();
            $first = $schemas[0];
            expect($first)->toHaveKeys(['name', 'label', 'category', 'base_event', 'param_count', 'required_count']);
            expect($first['name'])->toBe('test.signup.completed');
        });
    });

    describe('diagnostics', function () {
        it('returns errors and warnings from last build', function () {
            $this->builder->from('test.signup.completed')->buildReport();

            expect($this->builder->getErrors())->not->toBeEmpty();
            expect($this->builder->getWarnings())->toBe([]);
        });

        it('returns coercion log from last build', function () {
            $this->builder->from('test.signup.completed')
                ->with(['user_id' => '1', 'age' => '25'])
                ->buildReport();

            expect($this->builder->getCoercionLog())->toContain('age: string → int');
        });

        it('getConfig returns service configuration', function () {
            $config = $this->builder->getConfig();

            expect($config)->toHaveKeys(['auto_coerce', 'pii_fields', 'registry_total']);
            expect($config['auto_coerce'])->toBe(true);
            expect($config['registry_total'])->toBe(1);
        });

        it('isEnabled returns config value', function () {
            expect($this->builder->isEnabled())->toBe(true);
        });
    });

    describe('production readiness', function () {
        it('is declared final', function () {
            $ref = new ReflectionClass(EventBlueprintBuilderService::class);

            expect($ref->isFinal())->toBeTrue();
        });

        it('has strict_types declaration', function () {
            $file = (string) file_get_contents((string) (new ReflectionClass(EventBlueprintBuilderService::class))->getFileName());

            expect($file)->toContain('declare(strict_types=1)');
        });

        it('has MIT license header', function () {
            $file = (string) file_get_contents((string) (new ReflectionClass(EventBlueprintBuilderService::class))->getFileName());

            expect(str_starts_with($file, '<?php'))->toBeTrue();
            expect($file)->toContain('part of ZeroBoiler, licensed under the MIT license');
        });

        it('has @since annotation', function () {
            $doc = (string) (new ReflectionClass(EventBlueprintBuilderService::class))->getDocComment();

            expect($doc)->toContain('@since');
        });

        it('constructor has :void return type', function () {
            $ctor = (new ReflectionClass(EventBlueprintBuilderService::class))->getConstructor();

            expect($ctor)->not->toBeNull();
            expect($ctor->getReturnType()?->getName())->toBe('void');
        });

        it('all public methods have return type declarations', function () {
            $ref = new ReflectionClass(EventBlueprintBuilderService::class);

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== EventBlueprintBuilderService::class) {
                    continue;
                }

                expect($method->getReturnType())->not->toBeNull(
                    "Method {$method->getName()}() must have a return type declaration",
                );
            }
        });

        it('has no TODO or FIXME comments', function () {
            $file = (string) file_get_contents((string) (new ReflectionClass(EventBlueprintBuilderService::class))->getFileName());

            expect($file)->not->toContain('TODO');
            expect($file)->not->toContain('FIXME');
        });
    });
});
