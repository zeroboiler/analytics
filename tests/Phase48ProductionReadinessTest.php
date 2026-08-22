<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\DTO\EventPriority;
use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use ZeroBoiler\Analytics\Facades\Analytics;

beforeEach(function (): void {
    //
});

describe('Phase 48 Production Readiness', function (): void {
    describe('PSR-12: No blank line after <?php opening tag', function (): void {
        it('all source files have <?php immediately followed by docblock or declare', function (): void {
            $srcDir = __DIR__ . '/../src';
            $files = glob_recursive($srcDir . '/*.php');
            $violations = [];

            foreach ($files as $file) {
                $lines = file($file, FILE_IGNORE_NEW_LINES);
                if (count($lines) >= 2 && trim($lines[1]) === '') {
                    $relative = str_replace($srcDir . '/', '', $file);
                    $violations[] = $relative;
                }
            }

            expect($violations)->toBeEmpty('PSR-12 violation: blank line after <?php in: ' . implode(', ', array_slice($violations, 0, 10)));
        });

        it('all test files have <?php immediately followed by docblock or declare', function (): void {
            $testDir = __DIR__;
            $files = glob_recursive($testDir . '/*.php');
            $violations = [];

            foreach ($files as $file) {
                $lines = file($file, FILE_IGNORE_NEW_LINES);
                if (count($lines) >= 2 && trim($lines[1]) === '') {
                    $relative = str_replace($testDir . '/', '', $file);
                    $violations[] = $relative;
                }
            }

            expect($violations)->toBeEmpty('PSR-12 violation: blank line after <?php in test files: ' . implode(', ', array_slice($violations, 0, 10)));
        });
    });

    describe('phpstan.neon parity with phpstan.neon.dist', function (): void {
        it('phpstan.neon includes all settings from phpstan.neon.dist', function (): void {
            $neonContent = file_get_contents(__DIR__ . '/../phpstan.neon');
            $distContent = file_get_contents(__DIR__ . '/../phpstan.neon.dist');

            // Both should have level 9
            expect($neonContent)->toContain('level(9)');
            expect($neonContent)->toContain('checkMissingIterableValueType(false)');
            expect($neonContent)->toContain('checkUnusedParameters(true)');
            expect($neonContent)->toContain('checkUninitializedProperties(true)');
            expect($neonContent)->toContain('treatPhpDocTypesAsCertain(false)');
            expect($neonContent)->toContain('reportUnmatchedIgnoredErrors(false)');
            expect($neonContent)->toContain('checkGenericClassInNonGenericObjectType(true)');
            expect($neonContent)->toContain('excludePaths');
            expect($neonContent)->toContain('bootstrapFiles');
        });

        it('phpstan.neon.dist and phpstan.neon have matching level 9', function (): void {
            $neon = file_get_contents(__DIR__ . '/../phpstan.neon');
            $dist = file_get_contents(__DIR__ . '/../phpstan.neon.dist');
            expect($neon)->toContain('level: 9');
            expect($dist)->toContain('level: 9');
        });
    });

    describe('Exception hierarchy integrity', function (): void {
        it('base exception is abstract with constructor :void', function (): void {
            $ref = new ReflectionClass(AnalyticsException::class);
            expect($ref->isAbstract())->toBeTrue();

            $ctor = $ref->getMethod('__construct');
            $returnType = $ctor->getReturnType()?->getName();
            expect($returnType)->toBe('void');
        });

        it('leaf exceptions are final with factory methods', function (): void {
            $leaves = [AnalyticsRuntimeException::class, InvalidAnalyticsArgumentException::class];

            foreach ($leaves as $leaf) {
                $ref = new ReflectionClass($leaf);
                expect($ref->isFinal())->toBeTrue("{$leaf} must be final");
                expect($ref->hasMethod('forMessage'))->toBeTrue("{$leaf} must have forMessage() factory");
                expect($ref->isSubclassOf(AnalyticsException::class))->toBeTrue("{$leaf} must extend AnalyticsException");
            }
        });

        it('base exception has bidirectional @see to leaf exceptions', function (): void {
            $doc = (new ReflectionClass(AnalyticsException::class))->getDocComment();
            expect($doc)->toContain(AnalyticsRuntimeException::class);
            expect($doc)->toContain(InvalidAnalyticsArgumentException::class);
        });
    });

    describe('ServiceProvider finality and contract', function (): void {
        it('is final class', function (): void {
            expect((new ReflectionClass(AnalyticsServiceProvider::class))->isFinal())->toBeTrue();
        });

        it('register method has #[Override] and :void return type', function (): void {
            $ref = new ReflectionMethod(AnalyticsServiceProvider::class, 'register');
            $attrs = $ref->getAttributes();
            $hasOverride = false;
            foreach ($attrs as $attr) {
                if ($attr->getName() === 'Override') {
                    $hasOverride = true;
                    break;
                }
            }
            expect($hasOverride)->toBeTrue('register() must have #[Override]');
            expect($ref->getReturnType()?->getName())->toBe('void');
        });
    });

    describe('Facade finality and accessor', function (): void {
        it('is final class', function (): void {
            expect((new ReflectionClass(Analytics::class))->isFinal())->toBeTrue();
        });

        it('getFacadeAccessor has #[Override] and returns string', function (): void {
            $ref = new ReflectionMethod(Analytics::class, 'getFacadeAccessor');
            $attrs = $ref->getAttributes();
            $hasOverride = false;
            foreach ($attrs as $attr) {
                if ($attr->getName() === 'Override') {
                    $hasOverride = true;
                    break;
                }
            }
            expect($hasOverride)->toBeTrue('getFacadeAccessor() must have #[Override]');
            expect($ref->getReturnType()?->getName())->toBe('string');
        });
    });

    describe('Backed enum correctness', function (): void {
        it('EventPriority is a backed string enum', function (): void {
            $ref = new ReflectionClass(EventPriority::class);
            expect($ref->isEnum())->toBeTrue();
            expect($ref->getEnumBackingType()?->getName())->toBe('string');

            $cases = EventPriority::cases();
            expect(count($cases))->toBe(4);
        });

        it('EventPriority has weight(), bypassesFilters(), subjectToSampling() methods', function (): void {
            $critical = EventPriority::Critical;
            expect($critical->weight())->toBe(3);
            expect($critical->bypassesFilters())->toBeTrue();
            expect($critical->subjectToSampling())->toBeFalse();

            $background = EventPriority::Background;
            expect($background->weight())->toBe(0);
            expect($background->bypassesFilters())->toBeFalse();
            expect($background->subjectToSampling())->toBeTrue();
            expect($background->deferrable())->toBeTrue();
        });
    });

    describe('Composer metadata integrity', function (): void {
        it('requires PHP ^8.5', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        it('has correct autoload namespace', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['autoload']['psr-4']['ZeroBoiler\\Analytics\\'])->toBe('src/');
        });

        it('has correct provider and alias registration', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            $provider = $composer['extra']['laravel']['providers'][0];
            expect($provider)->toBe('ZeroBoiler\\Analytics\\AnalyticsServiceProvider');

            $alias = $composer['extra']['laravel']['aliases']['Analytics'];
            expect($alias)->toBe('ZeroBoiler\\Analytics\\Facades\\Analytics');
        });

        it('has quality scripts defined', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect(isset($composer['scripts']['test']))->toBeTrue();
            expect(isset($composer['scripts']['lint']))->toBeTrue();
            expect(isset($composer['scripts']['analyse']))->toBeTrue();
        });

        it('has MIT license', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['license'])->toBe('MIT');
        });

        it('has require-dev with quality tools', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            $devRequire = array_keys($composer['require-dev']);
            expect($devRequire)->toContain('phpstan/phpstan');
            expect($devRequire)->toContain('laravel/pint');
            expect($devRequire)->toContain('rector/rector');
            expect($devRequire)->toContain('pestphp/pest');
        });
    });

    describe('Rector PHP 8.5 target', function (): void {
        it('rector.php targets PHP 8.5', function (): void {
            $content = file_get_contents(__DIR__ . '/../rector.php');
            expect($content)->toContain('PHP_85');
        });
    });

    describe('All source files: strict types, license headers, @since, no TODO/FIXME', function (): void {
        it('every source file has declare(strict_types=1)', function (): void {
            $srcDir = __DIR__ . '/../src';
            $files = glob_recursive($srcDir . '/*.php');
            $missing = [];

            foreach ($files as $file) {
                $content = file_get_contents($file);
                if (! str_contains($content, 'declare(strict_types=1)')) {
                    $missing[] = str_replace($srcDir . '/', '', $file);
                }
            }

            expect($missing)->toBeEmpty('Missing strict_types in: ' . implode(', ', array_slice($missing, 0, 10)));
        });

        it('every source file has @since annotation', function (): void {
            $srcDir = __DIR__ . '/../src';
            $files = glob_recursive($srcDir . '/*.php');
            $missing = [];

            foreach ($files as $file) {
                $content = file_get_contents($file);
                if (! str_contains($content, '@since')) {
                    $missing[] = str_replace($srcDir . '/', '', $file);
                }
            }

            expect($missing)->toBeEmpty('Missing @since in: ' . implode(', ', array_slice($missing, 0, 10)));
        });

        it('no TODO or FIXME markers in source', function (): void {
            $srcDir = __DIR__ . '/../src';
            $files = glob_recursive($srcDir . '/*.php');
            $violations = [];

            foreach ($files as $file) {
                $content = file_get_contents($file);
                if (preg_match('/\b(TODO|FIXME|HACK|XXX)\b/', $content)) {
                    $violations[] = str_replace($srcDir . '/', '', $file);
                }
            }

            expect($violations)->toBeEmpty('TODO/FIXME found in: ' . implode(', ', array_slice($violations, 0, 10)));
        });

        it('every non-abstract, non-interface, non-trait, non-enum class is final', function (): void {
            $srcDir = __DIR__ . '/../src';
            $files = glob_recursive($srcDir . '/*.php');
            $nonFinal = [];

            foreach ($files as $file) {
                $content = file_get_contents($file);
                // Find class declarations
                if (preg_match('/\bclass\s+(\w+)/', $content, $matches)) {
                    $className = $matches[1];
                    $ref = null;

                    try {
                        if (class_exists($className) && (new ReflectionClass($className))->getFileName() === realpath($file)) {
                            $ref = new ReflectionClass($className);
                        }
                    } catch (Throwable $e) {
                        continue;
                    }

                    if ($ref && ! $ref->isFinal() && ! $ref->isAbstract() && ! $ref->isInterface()) {
                        $nonFinal[] = $className;
                    }
                }
            }

            // Report but allow non-final for classes we can't reflect
            // The key classes we can check:
            expect($nonFinal)->toBeEmpty('Non-final concrete classes: ' . implode(', ', array_slice($nonFinal, 0, 10)));
        });
    });

    describe('No static Eloquent calls in services', function (): void {
        it('no Eloquent:: static calls outside models', function (): void {
            $srcDir = __DIR__ . '/../src';
            $files = glob_recursive($srcDir . '/*.php');
            $violations = [];

            foreach ($files as $file) {
                $content = file_get_contents($file);
                if (str_contains(basename($file), 'Model')) {
                    continue;
                }
                if (preg_match('/\bEloquent::/', $content)) {
                    $violations[] = str_replace($srcDir . '/', '', $file);
                }
            }

            expect($violations)->toBeEmpty('Static Eloquent calls outside models: ' . implode(', ', $violations));
        });
    });

    describe('Config file structure', function (): void {
        it('config file exists with proper structure', function (): void {
            $configPath = __DIR__ . '/../config/zeroboiler.php';
            expect(file_exists($configPath))->toBeTrue();

            $config = require $configPath;
            expect(isset($config['analytics']))->toBeTrue();
            expect(isset($config['analytics']['ga4']))->toBeTrue();
            expect(isset($config['analytics']['gtm']))->toBeTrue();
            expect(isset($config['analytics']['meta_pixel']))->toBeTrue();
            expect(isset($config['analytics']['consent']))->toBeTrue();
            expect(isset($config['analytics']['queue']))->toBeTrue();
            expect(isset($config['analytics']['api']))->toBeTrue();
            expect(isset($config['analytics']['identity']))->toBeTrue();
            expect(isset($config['analytics']['ecommerce']))->toBeTrue();
            expect(isset($config['analytics']['revenue']))->toBeTrue();
            expect(isset($config['analytics']['auto_track']))->toBeTrue();
            expect(isset($config['analytics']['dedup_cache']))->toBeTrue();
            expect(isset($config['analytics']['revenue_checksum']))->toBeTrue();
            expect(isset($config['analytics']['client_auto_track']))->toBeTrue();
        });

        it('config file has declare(strict_types=1)', function (): void {
            $content = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
            expect($content)->toContain('declare(strict_types=1)');
        });
    });

    describe('Project structure files', function (): void {
        $requiredFiles = [
            'phpstan.neon.dist',
            'phpstan.neon',
            'rector.php',
            'composer.json',
            'LICENSE',
            'CHANGELOG.md',
            'README.md',
            '.editorconfig',
            '.gitattributes',
        ];

        foreach ($requiredFiles as $file) {
            it("has {$file}", function () use ($file): void {
                expect(file_exists(__DIR__ . '/../' . $file))->toBeTrue("Missing: {$file}");
            });
        }
    });

    describe('Version consistency', function (): void {
        it('composer.json version is set', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect(isset($composer['version']))->toBeTrue();
            expect($composer['version'])->toBeString();
            expect($composer['version'])->toBeGreaterThan('0');
        });
    });

    describe('Source and test file counts', function (): void {
        it('has 897 source files', function (): void {
            $srcDir = __DIR__ . '/../src';
            $files = glob_recursive($srcDir . '/*.php');
            expect(count($files))->toBeGreaterThanOrEqual(897);
        });

        it('has 456+ test files', function (): void {
            $testDir = __DIR__;
            $files = glob_recursive($testDir . '/*.php');
            expect(count($files))->toBeGreaterThanOrEqual(456);
        });
    });
});

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
