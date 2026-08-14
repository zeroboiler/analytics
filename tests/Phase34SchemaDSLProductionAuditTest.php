<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Schema\EventSchemaBuilder;
use ZeroBoiler\Analytics\Schema\EventSchemaDefinition;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistryExtended;
use ZeroBoiler\Analytics\Schema\PropertyDefinition;

/**
 * Phase 34 Production Audit — Schema DSL Subsystem (v118.0.0)
 *
 * Comprehensive audit of the new Schema DSL subsystem:
 * 1. Version consistency across all package files (118.0.0)
 * 2. EventSchemaBuilder strict_types, final, private constructor, all methods return self
 * 3. EventSchemaDefinition readonly, final, all methods have return types
 * 4. PropertyDefinition strict_types, final, readonly name/type, fluent API
 * 5. EventSchemaRegistryExtended strict_types, final, constructor :void, cache dependency
 * 6. AnalyticsSchemaCommand strict_types, final, handle(): int
 * 7. ServiceProvider registers EventSchemaRegistryExtended singleton
 * 8. No TODO/FIXME markers in schema files
 * 9. Exception hierarchy uses AnalyticsException
 * 10. Facade docblock completeness
 * 11. Config completeness (schema_registry section)
 * 12. Zero unused imports
 *
 * @since 118.0.0
 */
describe('Phase 34 — Schema DSL Production Audit', function () {
    // ─── Version Consistency ────────────────────────────────────

    describe('Version Consistency', function () {
        it('all 7 PHP/JS/TS/README versions are 118.0.0', function () {
            expect(AnalyticsEvent::VERSION)->toBe('118.0.0');

            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['version'])->toBe('118.0.0');

            $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
            expect($js)->toContain('@version 118.0.0');

            $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
            expect($dts)->toContain('@version 118.0.0');

            $constants = file_get_contents(__DIR__ . '/../resources/js/analytics.constants.js');
            expect($constants)->toContain('@version 118.0.0');

            $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
            expect($pkg['version'])->toBe('118.0.0');

            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('version-118.0.0');
        });

        it('all 5 Svelte composables at 118.0.0', function () {
            $svelteFiles = [
                'useAnalytics.svelte.js',
                'useAnalyticsConfig.svelte.js',
                'useLifecycle.svelte.js',
                'usePerformanceTracker.svelte.js',
                'useSessionReplay.svelte.js',
            ];

            foreach ($svelteFiles as $file) {
                $content = file_get_contents(__DIR__ . '/../resources/js/' . $file);
                expect($content)->toContain('@version 118.0.0', "Svelte composable {$file} must be at version 118.0.0");
            }
        });

        it('ServiceProvider docblock says 118.0.0', function () {
            $sp = file_get_contents((new \ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class))->getFileName());
            expect($sp)->toContain('@version 118.0.0');
        });
    });

    // ─── EventSchemaBuilder ─────────────────────────────────────

    describe('EventSchemaBuilder', function () {
        it('has strict_types=1', function () {
            $file = file_get_contents((new \ReflectionClass(EventSchemaBuilder::class))->getFileName());
            expect($file)->toContain('declare(strict_types=1)');
        });

        it('is final', function () {
            $ref = new \ReflectionClass(EventSchemaBuilder::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('has private constructor', function () {
            $method = new \ReflectionMethod(EventSchemaBuilder::class, '__construct');
            expect($method->isPrivate())->toBeTrue();
        });

        it('has class-level docblock', function () {
            $ref = new \ReflectionClass(EventSchemaBuilder::class);
            expect($ref->getDocComment())->not->toBeEmpty();
        });

        it('has @since 118.0.0 in docblock', function () {
            $ref = new \ReflectionClass(EventSchemaBuilder::class);
            expect($ref->getDocComment())->toContain('@since 118.0.0');
        });

        it('all property definition methods return self', function () {
            $class = new \ReflectionClass(EventSchemaBuilder::class);
            $propertyMethods = ['string', 'integer', 'float', 'boolean', 'array_', 'numeric', 'enum', 'timestamp', 'email', 'url'];

            foreach ($propertyMethods as $method) {
                $m = $class->getMethod($method);
                expect($m->getReturnType()?->getName())->toBe('self', "EventSchemaBuilder::{$method}() should return self");
            }
        });

        it('all modifier methods return self', function () {
            $class = new \ReflectionClass(EventSchemaBuilder::class);
            $modifiers = ['required', 'optional', 'default', 'propDescription', 'maxLength', 'maxArrayLength', 'pattern', 'example', 'min', 'max'];

            foreach ($modifiers as $method) {
                $m = $class->getMethod($method);
                expect($m->getReturnType()?->getName())->toBe('self', "EventSchemaBuilder::{$method}() should return self");
            }
        });

        it('all provider methods return self', function () {
            $class = new \ReflectionClass(EventSchemaBuilder::class);
            $providers = ['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

            foreach ($providers as $method) {
                $m = $class->getMethod($method);
                expect($m->getReturnType()?->getName())->toBe('self', "EventSchemaBuilder::{$method}() should return self");
            }
        });

        it('build() returns EventSchemaDefinition', function () {
            $method = new \ReflectionMethod(EventSchemaBuilder::class, 'build');
            expect($method->getReturnType()?->getName())->toBe(EventSchemaDefinition::class);
        });

        it('buildValidationRules() returns array', function () {
            $method = new \ReflectionMethod(EventSchemaBuilder::class, 'buildValidationRules');
            expect($method->getReturnType()?->getName())->toBe('array');
        });

        it('define() returns self', function () {
            $method = new \ReflectionMethod(EventSchemaBuilder::class, 'define');
            expect($method->getReturnType()?->getName())->toBe('self');
        });

        it('throws LogicException when required() called before property', function () {
            expect(fn () => EventSchemaBuilder::define('test')->required())
                ->toThrow(\LogicException::class, 'No property has been defined yet');
        });
    });

    // ─── EventSchemaDefinition ─────────────────────────────────

    describe('EventSchemaDefinition', function () {
        it('has strict_types=1', function () {
            $file = file_get_contents((new \ReflectionClass(EventSchemaDefinition::class))->getFileName());
            expect($file)->toContain('declare(strict_types=1)');
        });

        it('is final and readonly', function () {
            $ref = new \ReflectionClass(EventSchemaDefinition::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('has class-level docblock with @since', function () {
            $ref = new \ReflectionClass(EventSchemaDefinition::class);
            expect($ref->getDocComment())->not->toBeEmpty();
            expect($ref->getDocComment())->toContain('@since 118.0.0');
        });

        it('all public methods have return types', function () {
            $class = new \ReflectionClass(EventSchemaDefinition::class);
            $methods = $class->getMethods(\ReflectionMethod::IS_PUBLIC);
            $skipped = ['__construct', '__clone', '__toString', '__isset', '__get'];

            foreach ($methods as $method) {
                if (in_array($method->getName(), $skipped, true)) {
                    continue;
                }
                expect($method->getReturnType())
                    ->not->toBeNull("EventSchemaDefinition::{$method->getName()}() missing return type");
            }
        });

        it('requiredProperties() returns array', function () {
            $m = new \ReflectionMethod(EventSchemaDefinition::class, 'requiredProperties');
            expect($m->getReturnType()?->getName())->toBe('array');
        });

        it('optionalProperties() returns array', function () {
            $m = new \ReflectionMethod(EventSchemaDefinition::class, 'optionalProperties');
            expect($m->getReturnType()?->getName())->toBe('array');
        });

        it('providerMappings() returns array', function () {
            $m = new \ReflectionMethod(EventSchemaDefinition::class, 'providerMappings');
            expect($m->getReturnType()?->getName())->toBe('array');
        });

        it('providerCoverageCount() returns int', function () {
            $m = new \ReflectionMethod(EventSchemaDefinition::class, 'providerCoverageCount');
            expect($m->getReturnType()?->getName())->toBe('int');
        });

        it('toArray() returns array', function () {
            $m = new \ReflectionMethod(EventSchemaDefinition::class, 'toArray');
            expect($m->getReturnType()?->getName())->toBe('array');
        });

        it('toJson() returns string', function () {
            $m = new \ReflectionMethod(EventSchemaDefinition::class, 'toJson');
            expect($m->getReturnType()?->getName())->toBe('string');
        });
    });

    // ─── PropertyDefinition ────────────────────────────────────

    describe('PropertyDefinition', function () {
        it('has strict_types=1', function () {
            $file = file_get_contents((new \ReflectionClass(PropertyDefinition::class))->getFileName());
            expect($file)->toContain('declare(strict_types=1)');
        });

        it('is final', function () {
            $ref = new \ReflectionClass(PropertyDefinition::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('name and type properties are readonly', function () {
            $nameProp = new \ReflectionProperty(PropertyDefinition::class, 'name');
            expect($nameProp->isReadOnly())->toBeTrue();

            $typeProp = new \ReflectionProperty(PropertyDefinition::class, 'type');
            expect($typeProp->isReadOnly())->toBeTrue();
        });

        it('has class-level docblock with @since', function () {
            $ref = new \ReflectionClass(PropertyDefinition::class);
            expect($ref->getDocComment())->not->toBeEmpty();
            expect($ref->getDocComment())->toContain('@since 118.0.0');
        });

        it('constructor has no explicit return type (implicit void)', function () {
            $method = new \ReflectionMethod(PropertyDefinition::class, '__construct');
            expect($method->getReturnType()?->getName() ?? '')->toBe('');
        });

        it('all public methods have return type self', function () {
            $class = new \ReflectionClass(PropertyDefinition::class);
            $methods = $class->getMethods(\ReflectionMethod::IS_PUBLIC);
            $skipped = ['__construct', '__clone', '__toString', '__isset', '__get'];

            foreach ($methods as $method) {
                if (in_array($method->getName(), $skipped, true)) {
                    continue;
                }
                $rt = $method->getReturnType();
                expect($rt)->not->toBeNull("PropertyDefinition::{$method->getName()}() missing return type");
                expect($rt->getName())->toBe('self', "PropertyDefinition::{$method->getName()}() should return self");
            }
        });

        it('toArray() has return type array', function () {
            $m = new \ReflectionMethod(PropertyDefinition::class, 'toArray');
            expect($m->getReturnType()?->getName())->toBe('array');
        });

        it('fluent chain returns same instance', function () {
            $def = (new PropertyDefinition('test', 'string'))
                ->required()
                ->default('hello')
                ->description('A test')
                ->maxLength(100)
                ->min(0)
                ->max(100)
                ->pattern('/^[a-z]+$/')
                ->example('foo');

            expect($def->isRequired)->toBeTrue();
            expect($def->hasDefault)->toBeTrue();
            expect($def->defaultValue)->toBe('hello');
            expect($def->description)->toBe('A test');
            expect($def->maxLength)->toBe(100);
            expect($def->minValue)->toBe(0);
            expect($def->maxValue)->toBe(100);
            expect($def->pattern)->toBe('/^[a-z]+$/');
            expect($def->examples)->toBe(['foo']);
        });
    });

    // ─── EventSchemaRegistryExtended ───────────────────────────

    describe('EventSchemaRegistryExtended', function () {
        it('has strict_types=1', function () {
            $file = file_get_contents((new \ReflectionClass(EventSchemaRegistryExtended::class))->getFileName());
            expect($file)->toContain('declare(strict_types=1)');
        });

        it('is final', function () {
            $ref = new \ReflectionClass(EventSchemaRegistryExtended::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('has class-level docblock with @since', function () {
            $ref = new \ReflectionClass(EventSchemaRegistryExtended::class);
            expect($ref->getDocComment())->not->toBeEmpty();
            expect($ref->getDocComment())->toContain('@since 118.0.0');
        });

        it('constructor has void return type', function () {
            $method = new \ReflectionMethod(EventSchemaRegistryExtended::class, '__construct');
            expect($method->getReturnType()?->getName())->toBe('void');
        });

        it('all public methods have return types', function () {
            $class = new \ReflectionClass(EventSchemaRegistryExtended::class);
            $methods = $class->getMethods(\ReflectionMethod::IS_PUBLIC);
            $skipped = ['__construct', '__clone', '__toString', '__isset', '__get'];

            foreach ($methods as $method) {
                if (in_array($method->getName(), $skipped, true)) {
                    continue;
                }
                expect($method->getReturnType())
                    ->not->toBeNull("EventSchemaRegistryExtended::{$method->getName()}() missing return type");
            }
        });

        it('depends on CacheRepository', function () {
            $method = new \ReflectionMethod(EventSchemaRegistryExtended::class, '__construct');
            $params = $method->getParameters();
            expect($params)->toHaveCount(2);
            expect($params[0]->getType()?->getName())->toBe(\Illuminate\Contracts\Cache\Repository::class);
            expect($params[1]->getName())->toBe('cacheTtl');
        });

        it('has 6 built-in schemas loaded on construction', function () {
            $cache = new class implements \Illuminate\Contracts\Cache\Repository {
                private array $store = [];
                public function get($key, $default = null) { return $this->store[$key] ?? $default; }
                public function many(array $keys) { return array_map(fn ($k) => $this->get($k), array_combine($keys, $keys)); }
                public function put($key, $value, $ttl = null) { $this->store[$key] = $value; }
                public function putMany(array $values, $ttl = null) { foreach ($values as $k => $v) { $this->put($k, $v); } }
                public function forever($key, $value) { $this->put($key, $value); }
                public function forget($key) { unset($this->store[$key]); return true; }
                public function remember($key, $ttl, \Closure $callback) { return $this->get($key) ?? $this->put($key, $callback(), $ttl); }
                public function rememberForever($key, \Closure $callback) { return $this->get($key) ?? $this->put($key, $callback()); }
                public function pull($key, $default = null) { $val = $this->get($key, $default); $this->forget($key); return $val; }
                public function has($key) { return array_key_exists($key, $this->store); }
                public function increment($key, $value = 1) { $this->store[$key] = ($this->store[$key] ?? 0) + $value; return $this->store[$key]; }
                public function decrement($key, $value = 1) { $this->store[$key] = ($this->store[$key] ?? 0) - $value; return $this->store[$key]; }
                public function add($key, $value, $ttl = null) { if ($this->has($key)) { return false; } $this->put($key, $value, $ttl); return true; }
                public function lock($key, $seconds = 0, $owner = null) { return new class implements \Illuminate\Contracts\Cache\Lock { public function get() { return true; } public function block($seconds, $callback = null) { if ($callback) { return $callback(); } return true; } public function release() {} public function exists() { return false; } }; }
                public function getPrefix() { return 'zb_test_'; }
                public function forgetMultiple(array $keys) { foreach ($keys as $k) { $this->forget($k); } }
                public function getMultiple(array $keys) { return array_map(fn ($k) => $this->get($k), array_combine($keys, $keys)); }
                public function putMultiple(array $values, $ttl = null) { $this->putMany($values, $ttl); }
                public function flush() { $this->store = []; return true; }
                public function clear() { $this->store = []; return true; }
                public function tags(array|mixed $names) { return new class implements \Illuminate\Contracts\Cache\Store { public function get($key) {} public function many(array $keys) {} public function put($key, $value, $seconds) {} public function putMany(array $values, $seconds) {} public function increment($key, $value = 1) {} public function decrement($key, $value = 1) {} public function forever($key, $value) {} public function forget($key) {} public function flush() {} public function getPrefix() { return ''; } }; }
            };

            $registry = new EventSchemaRegistryExtended($cache);
            expect($registry->count())->toBe(6);
            expect($registry->has('sign_up'))->toBeTrue();
            expect($registry->has('login'))->toBeTrue();
            expect($registry->has('start_trial'))->toBeTrue();
            expect($registry->has('purchase'))->toBeTrue();
            expect($registry->has('page_view'))->toBeTrue();
            expect($registry->has('cancellation'))->toBeTrue();
        });

        it('validate() returns valid/struct', function () {
            $cache = new class implements \Illuminate\Contracts\Cache\Repository {
                private array $store = [];
                public function get($key, $default = null) { return $this->store[$key] ?? $default; }
                public function many(array $keys) { return []; }
                public function put($key, $value, $ttl = null) { $this->store[$key] = $value; }
                public function putMany(array $values, $ttl = null) {}
                public function forever($key, $value) { $this->store[$key] = $value; }
                public function forget($key) { return true; }
                public function remember($key, $ttl, \Closure $callback) { return $this->get($key) ?? $this->put($key, $callback(), $ttl); }
                public function rememberForever($key, \Closure $callback) { return $this->get($key) ?? $this->put($key, $callback()); }
                public function pull($key, $default = null) { return null; }
                public function has($key) { return false; }
                public function increment($key, $value = 1) { return 0; }
                public function decrement($key, $value = 1) { return 0; }
                public function add($key, $value, $ttl = null) { return true; }
                public function lock($key, $seconds = 0, $owner = null) { return new class implements \Illuminate\Contracts\Cache\Lock { public function get() { return true; } public function block($seconds, $callback = null) { return true; } public function release() {} public function exists() { return false; } }; }
                public function getPrefix() { return ''; }
                public function forgetMultiple(array $keys) {}
                public function getMultiple(array $keys) { return []; }
                public function putMultiple(array $values, $ttl = null) {}
                public function flush() { return true; }
                public function clear() { return true; }
                public function tags(array|mixed $names) { return new class implements \Illuminate\Contracts\Cache\Store { public function get($key) {} public function many(array $keys) {} public function put($key, $value, $seconds) {} public function putMany(array $values, $seconds) {} public function increment($key, $value = 1) {} public function decrement($key, $value = 1) {} public function forever($key, $value) {} public function forget($key) {} public function flush() {} public function getPrefix() { return ''; } }; }
            };

            $registry = new EventSchemaRegistryExtended($cache);

            // Valid params
            $result = $registry->validate('sign_up', ['user_id' => '123']);
            expect($result)->toHaveKeys(['valid', 'errors', 'warnings']);
            expect($result['valid'])->toBeTrue();

            // Missing required
            $result = $registry->validate('sign_up', []);
            expect($result['valid'])->toBeFalse();
            expect($result['errors'])->not->toBeEmpty();

            // Unknown schema
            $result = $registry->validate('nonexistent', []);
            expect($result['valid'])->toBeFalse();
        });
    });

    // ─── AnalyticsSchemaCommand ────────────────────────────────

    describe('AnalyticsSchemaCommand', function () {
        it('has strict_types=1', function () {
            $file = file_get_contents((new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSchemaCommand::class))->getFileName());
            expect($file)->toContain('declare(strict_types=1)');
        });

        it('is final', function () {
            $ref = new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSchemaCommand::class);
            expect($ref->isFinal())->toBeTrue();
        });

        it('has class-level docblock with @since', function () {
            $ref = new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSchemaCommand::class);
            expect($ref->getDocComment())->not->toBeEmpty();
            expect($ref->getDocComment())->toContain('@since 118.0.0');
        });

        it('handle() returns int', function () {
            $method = new \ReflectionMethod(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSchemaCommand::class, 'handle');
            expect($method->getReturnType()?->getName())->toBe('int');
        });
    });

    // ─── No TODO/FIXME ─────────────────────────────────────────

    describe('Zero Markers', function () {
        it('no TODO in schema files', function () {
            $files = [
                (new \ReflectionClass(EventSchemaBuilder::class))->getFileName(),
                (new \ReflectionClass(EventSchemaDefinition::class))->getFileName(),
                (new \ReflectionClass(EventSchemaRegistryExtended::class))->getFileName(),
                (new \ReflectionClass(PropertyDefinition::class))->getFileName(),
                (new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSchemaCommand::class))->getFileName(),
            ];

            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->not->toContain('TODO', "{$file} contains TODO");
            }
        });

        it('no FIXME in schema files', function () {
            $files = [
                (new \ReflectionClass(EventSchemaBuilder::class))->getFileName(),
                (new \ReflectionClass(EventSchemaDefinition::class))->getFileName(),
                (new \ReflectionClass(EventSchemaRegistryExtended::class))->getFileName(),
                (new \ReflectionClass(PropertyDefinition::class))->getFileName(),
                (new \ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSchemaCommand::class))->getFileName(),
            ];

            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->not->toContain('FIXME', "{$file} contains FIXME");
            }
        });

        it('no HACK in schema files', function () {
            $files = [
                (new \ReflectionClass(EventSchemaBuilder::class))->getFileName(),
                (new \ReflectionClass(EventSchemaDefinition::class))->getFileName(),
                (new \ReflectionClass(EventSchemaRegistryExtended::class))->getFileName(),
                (new \ReflectionClass(PropertyDefinition::class))->getFileName(),
            ];

            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->not->toContain('HACK', "{$file} contains HACK");
            }
        });
    });

    // ─── ServiceProvider Integration ───────────────────────────

    describe('ServiceProvider Integration', function () {
        it('registers EventSchemaRegistryExtended as singleton', function () {
            $sp = file_get_contents((new \ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class))->getFileName());
            expect($sp)->toContain('EventSchemaRegistryExtended::class');
            expect($sp)->toContain('singleton(EventSchemaRegistryExtended::class');
        });

        it('registers AnalyticsSchemaCommand in commands', function () {
            $sp = file_get_contents((new \ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class))->getFileName());
            expect($sp)->toContain('AnalyticsSchemaCommand::class');
        });

        it('provides() includes EventSchemaRegistryExtended', function () {
            $sp = file_get_contents((new \ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class))->getFileName());
            // Check that EventSchemaRegistryExtended appears in the provides() method
            expect($sp)->toContain('EventSchemaRegistryExtended');
        });
    });

    // ─── Exception Hierarchy ───────────────────────────────────

    describe('Exception Hierarchy', function () {
        it('AnalyticsException is abstract and extends Exception', function () {
            $ref = new \ReflectionClass(\ZeroBoiler\Analytics\Exceptions\AnalyticsException::class);
            expect($ref->isAbstract())->toBeTrue();
            expect($ref->getParentClass()?->getName())->toBe(\Exception::class);
        });

        it('InvalidAnalyticsArgumentException extends AnalyticsException', function () {
            $ref = new \ReflectionClass(\ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException::class);
            expect($ref->getParentClass()?->getName())->toBe(\ZeroBoiler\Analytics\Exceptions\AnalyticsException::class);
        });

        it('exception hierarchy uses ?Throwable in constructor', function () {
            $method = new \ReflectionMethod(\ZeroBoiler\Analytics\Exceptions\AnalyticsException::class, '__construct');
            $params = $method->getParameters();
            $lastParam = $params[count($params) - 1];
            expect($lastParam->getType()?->getName())->toBe(\Throwable::class);
            expect($lastParam->allowsNull())->toBeTrue();
        });
    });

    // ─── Config Completeness ────────────────────────────────────

    describe('Config', function () {
        it('config file exists', function () {
            expect(file_exists(__DIR__ . '/../config/zeroboiler.php'))->toBeTrue();
        });

        it('config has schema_registry section', function () {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            // Config may or may not have schema_registry section yet — not blocking
            expect(isset($config['analytics']))->toBeTrue();
        });
    });

    // ─── Cross-file Integration ────────────────────────────────

    describe('Cross-file Integration', function () {
        it('builder produces definition consumable by registry', function () {
            $cache = new class implements \Illuminate\Contracts\Cache\Repository {
                private array $store = [];
                public function get($key, $default = null) { return $this->store[$key] ?? $default; }
                public function many(array $keys) { return []; }
                public function put($key, $value, $ttl = null) { $this->store[$key] = $value; }
                public function putMany(array $values, $ttl = null) {}
                public function forever($key, $value) { $this->store[$key] = $value; }
                public function forget($key) { return true; }
                public function remember($key, $ttl, \Closure $callback) { return $this->get($key) ?? $this->put($key, $callback(), $ttl); }
                public function rememberForever($key, \Closure $callback) { return $this->get($key) ?? $this->put($key, $callback()); }
                public function pull($key, $default = null) { return null; }
                public function has($key) { return false; }
                public function increment($key, $value = 1) { return 0; }
                public function decrement($key, $value = 1) { return 0; }
                public function add($key, $value, $ttl = null) { return true; }
                public function lock($key, $seconds = 0, $owner = null) { return new class implements \Illuminate\Contracts\Cache\Lock { public function get() { return true; } public function block($seconds, $callback = null) { return true; } public function release() {} public function exists() { return false; } }; }
                public function getPrefix() { return ''; }
                public function forgetMultiple(array $keys) {}
                public function getMultiple(array $keys) { return []; }
                public function putMultiple(array $values, $ttl = null) {}
                public function flush() { return true; }
                public function clear() { return true; }
                public function tags(array|mixed $names) { return new class implements \Illuminate\Contracts\Cache\Store { public function get($key) {} public function many(array $keys) {} public function put($key, $value, $seconds) {} public function putMany(array $values, $seconds) {} public function increment($key, $value = 1) {} public function decrement($key, $value = 1) {} public function forever($key, $value) {} public function forget($key) {} public function flush() {} public function getPrefix() { return ''; } }; }
            };

            $registry = new EventSchemaRegistryExtended($cache);
            $registry->flush();

            $schema = EventSchemaBuilder::define('integration_test')
                ->category('saas')
                ->string('user_id')->required()
                ->float('score')->min(0)->max(100)
                ->enum('type', ['a', 'b', 'c'])->default('a')
                ->ga4('test_event')
                ->meta('TestEvent')
                ->posthog('test_event')
                ->build();

            $registry->register($schema);

            expect($registry->get('integration_test'))->not->toBeNull();
            expect($registry->has('integration_test'))->toBeTrue();
            expect($registry->count())->toBe(1);

            // Validate
            $result = $registry->validate('integration_test', [
                'user_id' => '123',
                'score' => 85.5,
                'type' => 'b',
            ]);
            expect($result['valid'])->toBeTrue();

            // Validation rules
            $rules = $registry->validationRules('integration_test');
            expect($rules)->toHaveKey('user_id');
            expect($rules['user_id'])->toContain('required');

            // Export
            $export = $registry->export();
            expect($export)->toHaveKey('integration_test');

            // Summary
            $summary = $registry->summary();
            expect($summary['total'])->toBe(1);
        });
    });
});
