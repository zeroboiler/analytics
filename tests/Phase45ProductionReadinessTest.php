<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;

/**
 * Phase 45 Production Readiness Test
 *
 * Comprehensive audit of the analytics package (857 source files).
 * Validates structural invariants across the full source tree.
 */
describe('Phase 45 Production Readiness', function (): void {
    describe('Source File Inventory', function (): void {
        it('has 857 source files', function (): void {
            $srcDir = __DIR__ . '/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $phpFiles = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $phpFiles[] = $file->getRealPath();
                }
            }
            expect(count($phpFiles))->toBe(857);
        });

        it('has strict_types in all source files', function (): void {
            $srcDir = __DIR__ . '/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $missing = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $content = file_get_contents($file->getRealPath());
                    if (! str_contains($content, 'declare(strict_types=1)')) {
                        $missing[] = $file->getFilename();
                    }
                }
            }
            expect($missing)->toBeEmpty();
        });

        it('has license header in all source files', function (): void {
            $srcDir = __DIR__ . '/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $missing = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $content = file_get_contents($file->getRealPath());
                    if (! str_contains($content, 'This file is part of ZeroBoiler')) {
                        $missing[] = $file->getFilename();
                    }
                }
            }
            expect($missing)->toBeEmpty();
        });

        it('has zero TODO/FIXME markers', function (): void {
            $srcDir = __DIR__ . '/../src';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            $markers = [];
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $content = file_get_contents($file->getRealPath());
                    foreach (['TODO', 'FIXME', 'HACK', 'XXX'] as $marker) {
                        if (str_contains($content, $marker)) {
                            $markers[] = $file->getFilename() . ':' . $marker;
                        }
                    }
                }
            }
            expect($markers)->toBeEmpty();
        });
    });

    describe('Exception Hierarchy', function (): void {
        it('AnalyticsException is abstract', function (): void {
            expect((new ReflectionClass(AnalyticsException::class))->isAbstract())->toBeTrue();
        });

        it('AnalyticsException has :void constructor', function (): void {
            $ctor = (new ReflectionClass(AnalyticsException::class))->getConstructor();
            expect($ctor->getReturnType()->getName())->toBe('void');
        });

        it('has 2 final leaf exceptions', function (): void {
            foreach ([InvalidAnalyticsArgumentException::class, AnalyticsRuntimeException::class] as $class) {
                $ref = new ReflectionClass($class);
                expect($ref->isFinal())->toBeTrue($class . ' must be final');
                expect($ref->isSubclassOf(AnalyticsException::class))->toBeTrue();
            }
        });
    });

    describe('ServiceProvider', function (): void {
        it('is final', function (): void {
            expect((new ReflectionClass(AnalyticsServiceProvider::class))->isFinal())->toBeTrue();
        });
    });

    describe('Composer Metadata', function (): void {
        it('requires PHP ^8.5', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['require']['php'])->toBe('^8.5');
        });

        it('has correct namespace', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['autoload']['psr-4']['ZeroBoiler\\Analytics\\'])->toBe('src/');
        });

        it('registers provider', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['extra']['laravel']['providers'])->toContain(
                'ZeroBoiler\\Analytics\\AnalyticsServiceProvider'
            );
        });

        it('has quality scripts', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['scripts'])->toHaveKey('test');
            expect($composer['scripts'])->toHaveKey('analyse');
            expect($composer['scripts'])->toHaveKey('lint');
            expect($composer['scripts'])->toHaveKey('rector');
            expect($composer['scripts'])->toHaveKey('quality');
        });

        it('has MIT license', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['license'])->toBe('MIT');
        });
    });

    describe('Version Consistency', function (): void {
        it('composer.json has version 191.0.0', function (): void {
            $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
            expect($composer['version'])->toBe('191.0.0');
        });

        it('README shows version badge', function (): void {
            $readme = file_get_contents(__DIR__ . '/../README.md');
            expect($readme)->toContain('version-191.0.0');
        });
    });

    describe('Project Structure Files', function (): void {
        $requiredFiles = [
            'README.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'LICENSE',
            'composer.json', 'phpstan.neon.dist', 'rector.php', 'pint.json', 'pest.xml',
        ];

        it('has all ' . count($requiredFiles) . ' required files', function () use ($requiredFiles): void {
            foreach ($requiredFiles as $file) {
                expect(file_exists(__DIR__ . '/../' . $file))->toBeTrue("Missing {$file}");
            }
        });
    });
});
