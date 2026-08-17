<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\DTO\EventPriority;
use ZeroBoiler\Analytics\EventInterceptorRegistry;
use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Http\HttpMiddlewareContract;
use ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareInterface;
use ZeroBoiler\Analytics\Pipeline\Validation\ValidationStageInterface;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;

beforeEach(function (): void {
    //
});

describe('Phase 225 Production Readiness — Analytics Package', function (): void {
    // ── 1. Exception Hierarchy ──────────────────────────────────────
    describe('Exception hierarchy integrity', function (): void {
        it('base exception is abstract AnalyticsException with :void constructor', function (): void {
            $ref = new ReflectionClass(AnalyticsException::class);
            expect($ref->isAbstract())->toBeTrue();
            expect($ref->isSubclassOf(\Exception::class))->toBeTrue();

            $ctor = $ref->getMethod('__construct');
            expect($ctor->getReturnType()?->getName())->toBe('void');
            expect($ctor->getParameters())->toHaveCount(3); // message, code, previous
        });

        it('AnalyticsRuntimeException is final with forMessage factory', function (): void {
            $ref = new ReflectionClass(AnalyticsRuntimeException::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isSubclassOf(AnalyticsException::class))->toBeTrue();
            expect($ref->hasMethod('forMessage'))->toBeTrue();

            $factory = $ref->getMethod('forMessage');
            expect($factory->isStatic())->toBeTrue();
            expect($factory->getReturnType()?->getName())->toBe(self::class); // self
        });

        it('InvalidAnalyticsArgumentException is final with forMessage factory', function (): void {
            $ref = new ReflectionClass(InvalidAnalyticsArgumentException::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isSubclassOf(AnalyticsException::class))->toBeTrue();
            expect($ref->hasMethod('forMessage'))->toBeTrue();

            $factory = $ref->getMethod('forMessage');
            expect($factory->isStatic())->toBeTrue();
            expect($factory->getReturnType()?->getName())->toBe(self::class);
        });

        it('base exception has FQCN @see to both leaves', function (): void {
            $doc = (new ReflectionClass(AnalyticsException::class))->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('\\' . AnalyticsRuntimeException::class);
            expect($doc)->toContain('\\' . InvalidAnalyticsArgumentException::class);
        });

        it('AnalyticsRuntimeException @see references base and sibling', function (): void {
            $doc = (new ReflectionClass(AnalyticsRuntimeException::class))->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('\\' . AnalyticsException::class);
            expect($doc)->toContain('\\' . InvalidAnalyticsArgumentException::class);
        });

        it('InvalidAnalyticsArgumentException @see references base and sibling', function (): void {
            $doc = (new ReflectionClass(InvalidAnalyticsArgumentException::class))->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('\\' . AnalyticsException::class);
            expect($doc)->toContain('\\' . AnalyticsRuntimeException::class);
        });

        it('AnalyticsRuntimeException constructor defaults match base', function (): void {
            $ref = new ReflectionClass(AnalyticsRuntimeException::class);
            $ctor = $ref->getMethod('__construct');
            $params = $ctor->getParameters();
            // $code default = 0, $previous default = null
            expect($params[1]->isDefaultValueAvailable())->toBeTrue();
            expect($params[1]->getDefaultValue())->toBe(0);
            expect($params[2]->isDefaultValueAvailable())->toBeTrue();
            expect($params[2]->getDefaultValue())->toBeNull();
        });

        it('InvalidAnalyticsArgumentException constructor defaults match base', function (): void {
            $ref = new ReflectionClass(InvalidAnalyticsArgumentException::class);
            $ctor = $ref->getMethod('__construct');
            $params = $ctor->getParameters();
            expect($params[1]->isDefaultValueAvailable())->toBeTrue();
            expect($params[1]->getDefaultValue())->toBe(0);
            expect($params[2]->isDefaultValueAvailable())->toBeTrue();
            expect($params[2]->getDefaultValue())->toBeNull();
        });

        it('forMessage factory works on AnalyticsRuntimeException', function (): void {
            $exc = AnalyticsRuntimeException::forMessage('test error');
            expect($exc)->toBeInstanceOf(AnalyticsRuntimeException::class);
            expect($exc->getMessage())->toBe('test error');
            expect($exc->getCode())->toBe(0);
            expect($exc->getPrevious())->toBeNull();
        });

        it('forMessage factory works on InvalidAnalyticsArgumentException', function (): void {
            $exc = InvalidAnalyticsArgumentException::forMessage('bad arg', 42);
            expect($exc)->toBeInstanceOf(InvalidAnalyticsArgumentException::class);
            expect($exc->getMessage())->toBe('bad arg');
            expect($exc->getCode())->toBe(42);
        });
    });

    // ── 2. ServiceProvider ──────────────────────────────────────────
    describe('ServiceProvider deep audit', function (): void {
        it('is final class', function (): void {
            expect((new ReflectionClass(AnalyticsServiceProvider::class))->isFinal())->toBeTrue();
        });

        it('register() has #[Override] and :void return type', function (): void {
            $method = new ReflectionMethod(AnalyticsServiceProvider::class, 'register');
            expect(hasAttribute($method, 'Override'))->toBeTrue();
            expect($method->getReturnType()?->getName())->toBe('void');
        });

        it('boot() has #[Override] and :void return type', function (): void {
            $method = new ReflectionMethod(AnalyticsServiceProvider::class, 'boot');
            expect(hasAttribute($method, 'Override'))->toBeTrue();
            expect($method->getReturnType()?->getName())->toBe('void');
        });

        it('provides() has #[Override] and returns array', function (): void {
            $method = new ReflectionMethod(AnalyticsServiceProvider::class, 'provides');
            expect(hasAttribute($method, 'Override'))->toBeTrue();
            expect($method->getReturnType()?->getName())->toBe('array');
        });

        it('provides() returns at least 4 bindings', function (): void {
            $provider = new ReflectionMethod(AnalyticsServiceProvider::class, 'provides');
            $instance = new AnalyticsServiceProvider(app());
            $result = $instance->provides();
            expect(count($result))->toBeGreaterThanOrEqual(4);
            expect($result)->toContain('zeroboiler.analytics');
            expect($result)->toContain(AnalyticsManager::class);
        });

        it('has @since annotation', function (): void {
            $doc = (new ReflectionClass(AnalyticsServiceProvider::class))->getDocComment();
            expect($doc)->toContain('@since');
        });
    });

    // ── 3. Facade ────────────────────────────────────────────────────
    describe('Facade audit', function (): void {
        it('is final class', function (): void {
            expect((new ReflectionClass(Analytics::class))->isFinal())->toBeTrue();
        });

        it('getFacadeAccessor() has #[Override] and returns string', function (): void {
            $method = new ReflectionMethod(Analytics::class, 'getFacadeAccessor');
            expect(hasAttribute($method, 'Override'))->toBeTrue();
            expect($method->getReturnType()?->getName())->toBe('string');
            expect($method->getReturnType()?->allowsNull())->toBeFalse();
        });

        it('getFacadeAccessor() returns zeroboiler.analytics', function (): void {
            $method = new ReflectionMethod(Analytics::class, 'getFacadeAccessor');
            $result = $method->invoke(null);
            expect($result)->toBe('zeroboiler.analytics');
        });

        it('Facade @see references AnalyticsManager and AnalyticsFake', function (): void {
            $doc = (new ReflectionClass(Analytics::class))->getDocComment();
            expect($doc)->not->toBeFalse();
            expect($doc)->toContain('\\' . AnalyticsManager::class);
            expect($doc)->toContain('AnalyticsFake');
        });

        it('Facade has @since annotation', function (): void {
            $doc = (new ReflectionClass(Analytics::class))->getDocComment();
            expect($doc)->toContain('@since');
        });
    });

    // ── 4. AnalyticsManager ─────────────────────────────────────────
    describe('AnalyticsManager API surface', function (): void {
        it('is final class', function (): void {
            expect((new ReflectionClass(AnalyticsManager::class))->isFinal())->toBeTrue();
        });

        it('constructor has :void return type', function (): void {
            $ctor = (new ReflectionClass(AnalyticsManager::class))->getMethod('__construct');
            expect($ctor->getReturnType()?->getName())->toBe('void');
        });

        it('has track() method with correct signature', function (): void {
            $method = new ReflectionMethod(AnalyticsManager::class, 'track');
            expect($method->getReturnType()?->getName())->toBe('void');
            expect($method->getParameters())->toHaveCount(2);
        });

        it('has trackEvent() method returning void', function (): void {
            $method = new ReflectionMethod(AnalyticsManager::class, 'trackEvent');
            expect($method->getReturnType()?->getName())->toBe('void');
        });

        it('has directDispatch() method returning bool', function (): void {
            $method = new ReflectionMethod(AnalyticsManager::class, 'directDispatch');
            expect($method->getReturnType()?->getName())->toBe('bool');
        });

        it('has purchase() method returning void', function (): void {
            $method = new ReflectionMethod(AnalyticsManager::class, 'purchase');
            expect($method->getReturnType()?->getName())->toBe('void');
        });

        it('has identify() method returning void', function (): void {
            $method = new ReflectionMethod(AnalyticsManager::class, 'identify');
            expect($method->getReturnType()?->getName())->toBe('void');
        });

        it('has pageView() method returning void', function (): void {
            $method = new ReflectionMethod(AnalyticsManager::class, 'pageView');
            expect($method->getReturnType()?->getName())->toBe('void');
        });

        it('has headScripts() method returning string', function (): void {
            $method = new ReflectionMethod(AnalyticsManager::class, 'headScripts');
            expect($method->getReturnType()?->getName())->toBe('string');
        });

        it('has @since annotation', function (): void {
            $doc = (new ReflectionClass(AnalyticsManager::class))->getDocComment();
            expect($doc)->toContain('@since');
        });
    });

    // ── 5. Key Interfaces ───────────────────────────────────────────
    describe('Interface contracts', function (): void {
        it('TrackerInterface has track() method', function (): void {
            $ref = new ReflectionClass(TrackerInterface::class);
            expect($ref->isInterface())->toBeTrue();
            expect($ref->hasMethod('track'))->toBeTrue();
            expect($ref->hasMethod('isEnabled'))->toBeTrue();
            expect($ref->hasMethod('name'))->toBeTrue();
        });

        it('AnalyticsEventStoreInterface exists and is an interface', function (): void {
            expect((new ReflectionClass(AnalyticsEventStoreInterface::class))->isInterface())->toBeTrue();
        });

        it('ValidationStageInterface has process() method', function (): void {
            $ref = new ReflectionClass(ValidationStageInterface::class);
            expect($ref->isInterface())->toBeTrue();
            expect($ref->hasMethod('process'))->toBeTrue();
        });

        it('HttpMiddlewareContract exists and is an interface', function (): void {
            expect((new ReflectionClass(HttpMiddlewareContract::class))->isInterface())->toBeTrue();
        });

        it('AnalyticsMiddlewareInterface has handle() method', function (): void {
            $ref = new ReflectionClass(AnalyticsMiddlewareInterface::class);
            expect($ref->isInterface())->toBeTrue();
            expect($ref->hasMethod('handle'))->toBeTrue();
        });
    });

    // ── 6. EventPriority Enum ──────────────────────────────────────
    describe('EventPriority enum', function (): void {
        it('is a backed string enum with 4 cases', function (): void {
            $ref = new ReflectionClass(EventPriority::class);
            expect($ref->isEnum())->toBeTrue();
            expect($ref->getEnumBackingType()?->getName())->toBe('string');
            expect(count(EventPriority::cases()))->toBe(4);
        });

        it('Critical has weight 3, bypasses filters, not subject to sampling', function (): void {
            expect(EventPriority::Critical->weight())->toBe(3);
            expect(EventPriority::Critical->bypassesFilters())->toBeTrue();
            expect(EventPriority::Critical->subjectToSampling())->toBeFalse();
        });

        it('Background has weight 0, subject to sampling, deferrable', function (): void {
            expect(EventPriority::Background->weight())->toBe(0);
            expect(EventPriority::Background->bypassesFilters())->toBeFalse();
            expect(EventPriority::Background->subjectToSampling())->toBeTrue();
            expect(EventPriority::Background->deferrable())->toBeTrue();
        });

        it('Normal has weight 1', function (): void {
            expect(EventPriority::Normal->weight())->toBe(1);
        });

        it('High has weight 2', function (): void {
            expect(EventPriority::High->weight())->toBe(2);
        });
    });

    // ── 7. DTO: AnalyticsEvent ──────────────────────────────────────
    describe('AnalyticsEvent DTO', function (): void {
        it('has VERSION constant', function (): void {
            expect(defined(AnalyticsEvent::class . '::VERSION'))->toBeTrue();
        });

        it('has constructor with named parameters', function (): void {
            $ref = new ReflectionClass(AnalyticsEvent::class);
            $ctor = $ref->getConstructor();
            expect($ctor)->not->toBeNull();
            $params = $ctor->getParameters();
            expect(count($params))->toBeGreaterThanOrEqual(2); // name, params at minimum
        });

        it('has toArray() method', function (): void {
            $ref = new ReflectionClass(AnalyticsEvent::class);
            expect($ref->hasMethod('toArray'))->toBeTrue();
        });
    });

    // ── 8. DTO: ConsentState ────────────────────────────────────────
    describe('ConsentState DTO', function (): void {
        it('has granted() and denied() factory methods', function (): void {
            $ref = new ReflectionClass(ConsentState::class);
            expect($ref->hasMethod('granted'))->toBeTrue();
            expect($ref->hasMethod('denied'))->toBeTrue();
            expect($ref->hasMethod('isGranted'))->toBeTrue();
        });

        it('granted() returns ConsentState with isGranted true', function (): void {
            $state = ConsentState::granted();
            expect($state->isGranted())->toBeTrue();
        });

        it('denied() returns ConsentState with isGranted false', function (): void {
            $state = ConsentState::denied();
            expect($state->isGranted())->toBeFalse();
        });
    });

    // ── 9. Composer metadata ────────────────────────────────────────
    describe('Composer metadata integrity', function (): void {
        it('requires PHP ^8.5', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        it('requires illuminate/contracts ^13.0', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
        });

        it('has correct autoload namespace', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['autoload']['psr-4']['ZeroBoiler\\Analytics\\'])->toBe('src/');
        });

        it('autoload-dev has tests namespace', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['autoload-dev']['psr-4']['ZeroBoiler\\Analytics\\Tests\\'])->toBe('tests/');
        });

        it('has correct provider registration', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['extra']['laravel']['providers'][0])
                ->toBe('ZeroBoiler\\Analytics\\AnalyticsServiceProvider');
        });

        it('has correct facade alias', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['extra']['laravel']['aliases']['Analytics'])
                ->toBe('ZeroBoiler\\Analytics\\Facades\\Analytics');
        });

        it('has MIT license', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['license'])->toBe('MIT');
        });

        it('has quality scripts', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect(isset($composer['scripts']['test']))->toBeTrue();
            expect(isset($composer['scripts']['lint']))->toBeTrue();
            expect(isset($composer['scripts']['analyse']))->toBeTrue();
        });

        it('has require-dev quality tools', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            $dev = array_keys($composer['require-dev']);
            expect($dev)->toContain('phpstan/phpstan');
            expect($dev)->toContain('laravel/pint');
            expect($dev)->toContain('rector/rector');
            expect($dev)->toContain('pestphp/pest');
        });

        it('version is set and is 225.0.0', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['version'])->toBe('225.0.0');
        });
    });

    // ── 10. phpstan + rector ─────────────────────────────────────────
    describe('Static analysis config', function (): void {
        it('phpstan.neon has level 9', function (): void {
            $content = file_get_contents(__DIR__ . '/../phpstan.neon');
            expect($content)->toContain('level(9)');
        });

        it('phpstan.neon.dist has level: 9', function (): void {
            $content = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
            expect($content)->toContain('level: 9');
        });

        it('phpstan.neon checks: checkMissingIterableValueType(false)', function (): void {
            expect(file_get_contents(__DIR__ . '/../phpstan.neon'))
                ->toContain('checkMissingIterableValueType(false)');
        });

        it('phpstan.neon checks: checkGenericClassInNonGenericObjectType(true)', function (): void {
            expect(file_get_contents(__DIR__ . '/../phpstan.neon'))
                ->toContain('checkGenericClassInNonGenericObjectType(true)');
        });

        it('phpstan.neon checks: treatPhpDocTypesAsCertain(false)', function (): void {
            expect(file_get_contents(__DIR__ . '/../phpstan.neon'))
                ->toContain('treatPhpDocTypesAsCertain(false)');
        });

        it('phpstan.neon checks: reportUnmatchedIgnoredErrors(false)', function (): void {
            expect(file_get_contents(__DIR__ . '/../phpstan.neon'))
                ->toContain('reportUnmatchedIgnoredErrors(false)');
        });

        it('phpstan.neon has excludePaths', function (): void {
            expect(file_get_contents(__DIR__ . '/../phpstan.neon'))->toContain('excludePaths');
        });

        it('phpstan.neon has bootstrapFiles', function (): void {
            expect(file_get_contents(__DIR__ . '/../phpstan.neon'))->toContain('bootstrapFiles');
        });

        it('phpstan.neon.dist has ignoreErrors section', function (): void {
            expect(file_get_contents(__DIR__ . '/../phpstan.neon.dist'))->toContain('ignoreErrors');
        });

        it('rector targets PHP 8.5', function (): void {
            expect(file_get_contents(__DIR__ . '/../rector.php'))->toContain('PHP_85');
        });

        it('rector scans src/ and tests/', function (): void {
            $content = file_get_contents(__DIR__ . '/../rector.php');
            expect($content)->toContain("'src'");
            expect($content)->toContain("'tests'");
        });
    });

    // ── 11. Config integrity ────────────────────────────────────────
    describe('Config file structure', function (): void {
        it('config file exists and has strict_types', function (): void {
            $path = __DIR__ . '/../config/zeroboiler.php';
            expect(file_exists($path))->toBeTrue();
            expect(file_get_contents($path))->toContain('declare(strict_types=1)');
        });

        it('config has ga4 section', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            expect(isset($config['analytics']['ga4']))->toBeTrue();
            expect(isset($config['analytics']['ga4']['enabled']))->toBeTrue();
            expect(isset($config['analytics']['ga4']['measurement_id']))->toBeTrue();
            expect(isset($config['analytics']['ga4']['api_secret']))->toBeTrue();
        });

        it('config has gtm section', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            expect(isset($config['analytics']['gtm']))->toBeTrue();
            expect(isset($config['analytics']['gtm']['enabled']))->toBeTrue();
            expect(isset($config['analytics']['gtm']['container_id']))->toBeTrue();
        });

        it('config has meta_pixel section', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            expect(isset($config['analytics']['meta_pixel']))->toBeTrue();
        });

        it('config has consent section with GDPR fields', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            expect(isset($config['analytics']['consent']['default']))->toBeTrue();
            expect(isset($config['analytics']['consent']['purposes']))->toBeTrue();
        });

        it('config has queue section', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            expect(isset($config['analytics']['queue']))->toBeTrue();
            expect(isset($config['analytics']['queue']['enabled']))->toBeTrue();
        });

        it('config has api section', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            expect(isset($config['analytics']['api']))->toBeTrue();
        });

        it('config has ecommerce section', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            expect(isset($config['analytics']['ecommerce']))->toBeTrue();
        });

        it('config has revenue section', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            expect(isset($config['analytics']['revenue']))->toBeTrue();
        });

        it('config has identity section', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            expect(isset($config['analytics']['identity']))->toBeTrue();
        });

        it('config has auto_track section', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            expect(isset($config['analytics']['auto_track']))->toBeTrue();
        });

        it('config has dedup_cache section', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            expect(isset($config['analytics']['dedup_cache']))->toBeTrue();
        });

        it('config has revenue_checksum section', function (): void {
            $config = require __DIR__ . '/../config/zeroboiler.php';
            expect(isset($config['analytics']['revenue_checksum']))->toBeTrue();
        });
    });

    // ── 12. All source files quality ────────────────────────────────
    describe('Source file quality baseline', function (): void {
        it('every source file has declare(strict_types=1)', function (): void {
            $srcDir = __DIR__ . '/../src';
            $files = glob_recursive($srcDir . '/*.php');
            $missing = [];

            foreach ($files as $file) {
                if (! str_contains(file_get_contents($file), 'declare(strict_types=1)')) {
                    $missing[] = str_replace($srcDir . '/', '', $file);
                }
            }

            expect($missing)->toBeEmpty('Missing strict_types: ' . implode(', ', array_slice($missing, 0, 10)));
        });

        it('every source file has @since annotation', function (): void {
            $srcDir = __DIR__ . '/../src';
            $files = glob_recursive($srcDir . '/*.php');
            $missing = [];

            foreach ($files as $file) {
                if (! str_contains(file_get_contents($file), '@since')) {
                    $missing[] = str_replace($srcDir . '/', '', $file);
                }
            }

            expect($missing)->toBeEmpty('Missing @since: ' . implode(', ', array_slice($missing, 0, 10)));
        });

        it('no TODO or FIXME markers in source', function (): void {
            $srcDir = __DIR__ . '/../src';
            $files = glob_recursive($srcDir . '/*.php');
            $violations = [];

            foreach ($files as $file) {
                if (preg_match('/\b(TODO|FIXME|HACK|XXX)\b/', file_get_contents($file))) {
                    $violations[] = str_replace($srcDir . '/', '', $file);
                }
            }

            expect($violations)->toBeEmpty('TODO/FIXME found: ' . implode(', ', array_slice($violations, 0, 10)));
        });

        it('no static Eloquent calls outside models', function (): void {
            $srcDir = __DIR__ . '/../src';
            $files = glob_recursive($srcDir . '/*.php');
            $violations = [];

            foreach ($files as $file) {
                if (str_contains(basename($file), 'Model')) {
                    continue;
                }
                if (preg_match('/\bEloquent::/', file_get_contents($file))) {
                    $violations[] = str_replace($srcDir . '/', '', $file);
                }
            }

            expect($violations)->toBeEmpty('Static Eloquent: ' . implode(', ', $violations));
        });
    });

    // ── 13. Project structure ─────────────────────────────────────────
    describe('Project structure files', function (): void {
        $required = [
            'phpstan.neon.dist', 'phpstan.neon', 'rector.php',
            'composer.json', 'LICENSE', 'CHANGELOG.md', 'README.md',
            '.editorconfig', '.gitattributes',
        ];

        foreach ($required as $file) {
            it("has {$file}", function () use ($file): void {
                expect(file_exists(__DIR__ . '/../' . $file))->toBeTrue("Missing: {$file}");
            });
        }
    });

    // ── 14. File counts ─────────────────────────────────────────────
    describe('File count baselines', function (): void {
        it('has 944+ source files', function (): void {
            $files = glob_recursive(__DIR__ . '/../src/*.php');
            expect(count($files))->toBeGreaterThanOrEqual(944);
        });

        it('has 474+ test files', function (): void {
            $files = glob_recursive(__DIR__ . '/*.php');
            expect(count($files))->toBeGreaterThanOrEqual(474);
        });
    });

    // ── 15. EventInterceptorRegistry ────────────────────────────────
    describe('EventInterceptorRegistry', function (): void {
        it('exists and has runBefore/runAfter methods', function (): void {
            $ref = new ReflectionClass(EventInterceptorRegistry::class);
            expect($ref->hasMethod('runBefore'))->toBeTrue();
            expect($ref->hasMethod('runAfter'))->toBeTrue();
        });
    });

    // ── 16. AnalyticsMetrics ────────────────────────────────────────
    describe('AnalyticsMetrics', function (): void {
        it('has recordDispatch and recordFailure methods', function (): void {
            $ref = new ReflectionClass(AnalyticsMetrics::class);
            expect($ref->hasMethod('recordDispatch'))->toBeTrue();
            expect($ref->hasMethod('recordFailure'))->toBeTrue();
        });
    });

    // ── 17. Version consistency ──────────────────────────────────────
    describe('Version consistency', function (): void {
        it('composer.json version matches AnalyticsEvent::VERSION', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['version'])->toBe(AnalyticsEvent::VERSION);
        });
    });
});

// ── Helpers ─────────────────────────────────────────────────────────

if (! function_exists('glob_recursive')) {
    function glob_recursive(string $pattern, int $flags = 0): array
    {
        $files = glob($pattern, $flags);

        foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = array_merge($files, glob_recursive($dir . '/' . basename($pattern), $flags));
        }

        return $files;
    }
}

function hasAttribute(ReflectionMethod $method, string $name): bool
{
    foreach ($method->getAttributes() as $attr) {
        if ($attr->getName() === $name) {
            return true;
        }
    }

    return false;
}
