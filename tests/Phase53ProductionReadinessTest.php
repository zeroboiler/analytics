<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use ZeroBoiler\Analytics\Facades\Analytics as AnalyticsFacade;

/**
 * Phase 53 production readiness — exception hierarchy integrity,
 * contract verification, and infrastructure audit for analytics package.
 */
test('Phase 53: AnalyticsException is abstract', function (): void {
    $ref = new \ReflectionClass(AnalyticsException::class);
    expect($ref->isAbstract())->toBeTrue();
    expect($ref->isSubclassOf(\Exception::class))->toBeTrue();
});

test('Phase 53: all leaf exceptions are final', function (): void {
    $leaves = [
        AnalyticsRuntimeException::class,
        InvalidAnalyticsArgumentException::class,
    ];

    foreach ($leaves as $fqcn) {
        $ref = new \ReflectionClass($fqcn);
        expect($ref->isFinal())->toBeTrue("{$fqcn} must be final");
        expect($ref->isSubclassOf(AnalyticsException::class))->toBeTrue();
    }
});

test('Phase 53: exception hierarchy FQCN cross-references', function (): void {
    $doc = (new \ReflectionClass(AnalyticsException::class))->getDocComment();
    expect($doc)->toContain(AnalyticsRuntimeException::class);
    expect($doc)->toContain(InvalidAnalyticsArgumentException::class);
});

test('Phase 53: Facade has Override on getFacadeAccessor', function (): void {
    $ref = new \ReflectionClass(AnalyticsFacade::class);
    $accessor = $ref->getMethod('getFacadeAccessor');
    expect($accessor->getAttributes(\Override::class))->not->toBeEmpty();
});

test('Phase 53: composer.json metadata integrity', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);

    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['autoload']['psr-4']['ZeroBoiler\\Analytics\\'])->toBe('src/');
    expect($composer['scripts'])->toHaveKeys(['test', 'test:coverage', 'lint', 'lint:fix', 'analyse', 'rector', 'quality']);
});

test('Phase 53: phpstan.neon.dist has required checks', function (): void {
    $content = file_get_contents(__DIR__.'/../phpstan.neon.dist');

    expect($content)->toContain('level: 9');
    expect($content)->toContain('checkUnusedParameters: true');
    expect($content)->toContain('checkUninitializedProperties: true');
    expect($content)->toContain('treatPhpDocTypesAsCertain: false');
});

test('Phase 53: all source files have strict_types and license headers', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
    );

    $count = 0;
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $count++;
        $content = file_get_contents($file->getRealPath());
        expect($content)->toContain('declare(strict_types=1)');
        expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license.');
    }

    expect($count)->toBe(945);
});

test('Phase 53: zero TODO or FIXME in source files', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
    );

    $checked = 0;
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $checked++;
        $content = file_get_contents($file->getRealPath());
        expect($content)->not->toContain('TODO');
        expect($content)->not->toContain('FIXME');
    }

    expect($checked)->toBe(945);
});

test('Phase 53: version consistency', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');

    expect($readme)->toContain("version-{$composer['version']}");
});

test('Phase 53: project structure files exist', function (): void {
    $required = ['README.md', 'CHANGELOG.md', 'phpstan.neon.dist', 'rector.php', 'composer.json'];

    foreach ($required as $file) {
        expect(file_exists(__DIR__.'/../'.$file))->toBeTrue("Missing: {$file}");
    }
});
