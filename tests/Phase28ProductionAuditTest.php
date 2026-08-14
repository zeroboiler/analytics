<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Exceptions\AnalyticsException;
use ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;
use ZeroBoiler\Analytics\Trackers\AmplitudeTracker;
use ZeroBoiler\Analytics\Trackers\MixpanelTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\LinkedInTracker;
use ZeroBoiler\Analytics\Trackers\TikTokTracker;
use ZeroBoiler\Analytics\Trackers\WebhookTracker;

// ─── Source File Strict Types Verification ─────────────────────────────────

test('all source files declare strict_types=1', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $violations = [];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') { continue; }
        $content = file_get_contents($file->getRealPath());
        if ($content === false || ! str_contains($content, 'declare(strict_types=1)')) {
            $violations[] = $file->getFilename();
        }
    }
    expect($violations)->toBeEmpty('Files missing declare(strict_types=1): '.implode(', ', $violations));
});

// ─── No TODO/FIXME ───────────────────────────────────────────────────────

test('no TODO or FIXME markers in source code', function (): void {
    $srcDir = __DIR__.'/../src';
    $content = '';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            $c = file_get_contents($file->getRealPath());
            if ($c !== false) { $content .= $c."\n"; }
        }
    }
    expect($content)->not->toContain('TODO');
    expect($content)->not->toContain('FIXME');
});

// ─── Exception Hierarchy ──────────────────────────────────────────────────

test('exception hierarchy is correct', function (): void {
    expect((new ReflectionClass(AnalyticsException::class))->isAbstract())->toBeTrue();
    expect((new ReflectionClass(InvalidAnalyticsArgumentException::class))->isSubclassOf(AnalyticsException::class))->toBeTrue();
    expect((new ReflectionClass(AnalyticsRuntimeException::class))->isSubclassOf(AnalyticsException::class))->toBeTrue();
});

test('leaf exceptions are final', function (): void {
    expect((new ReflectionClass(InvalidAnalyticsArgumentException::class))->isFinal())->toBeTrue();
    expect((new ReflectionClass(AnalyticsRuntimeException::class))->isFinal())->toBeTrue();
});

// ─── Tracker Interface Compliance ───────────────────────────────────────

test('all tracker classes implement TrackerInterface', function (): void {
    $trackers = [
        GA4Tracker::class,
        GTMTracker::class,
        MetaPixelTracker::class,
        AmplitudeTracker::class,
        MixpanelTracker::class,
        PosthogTracker::class,
        PlausibleTracker::class,
        LinkedInTracker::class,
        TikTokTracker::class,
        WebhookTracker::class,
    ];
    foreach ($trackers as $class) {
        expect((new ReflectionClass($class))->implementsInterface(TrackerInterface::class))
            ->toBeTrue("{$class} should implement TrackerInterface");
    }
});

// ─── Facade ────────────────────────────────────────────────────────────

test('facade has @method docblocks', function (): void {
    $ref = new ReflectionClass(Analytics::class);
    $doc = $ref->getDocComment();
    expect($doc)->not->toBeFalse();
    expect($doc)->toContain('@method static');
});

// ─── Composer.json Integrity ────────────────────────────────────────────

test('composer.json has correct namespace and PHP requirement', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    expect($composer['autoload']['psr-4']['ZeroBoiler\\Analytics\\'])->toBe('src/');
    expect($composer['require']['php'])->toBe('^8.5');
    expect($composer['type'])->toBe('library');
    expect($composer['license'])->toBe('MIT');
    expect($composer['version'])->toMatch('/^\d+\.\d+\.\d+$/');
});

// ─── Version Consistency ────────────────────────────────────────────────

test('composer.json version matches README badge', function (): void {
    $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
    $readme = file_get_contents(__DIR__.'/../README.md');
    expect($readme)->toContain("version-{$composer['version']}-blue");
});

// ─── Source File Count ──────────────────────────────────────────────────

test('source file count is 680', function (): void {
    $srcDir = __DIR__.'/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    $phpFiles = 0;
    foreach ($files as $file) {
        if ($file->getExtension() === 'php') { $phpFiles++; }
    }
    expect($phpFiles)->toBe(680, "Expected 680 source files, found {$phpFiles}");
});

// ─── Test File Count ────────────────────────────────────────────────────

test('test file count is 321', function (): void {
    $testsDir = __DIR__;
    $files = glob($testsDir.'/*Test.php');
    expect($files)->not()->toBeEmpty();
    expect(count($files))->toBe(321, "Expected 321 test files, found ".count($files));
});

// ─── phpstan.neon.dist ──────────────────────────────────────────────────

test('phpstan.neon.dist exists', function (): void {
    expect(file_exists(__DIR__.'/../phpstan.neon.dist'))->toBeTrue();
});

// ─── CHANGELOG ──────────────────────────────────────────────────────────

test('CHANGELOG.md exists', function (): void {
    expect(file_exists(__DIR__.'/../CHANGELOG.md'))->toBeTrue();
});

// ─── LICENSE ──────────────────────────────────────────────────────────────

test('MIT license file exists', function (): void {
    expect(file_exists(__DIR__.'/../LICENSE'))->toBeTrue();
});

// ─── README Sections ────────────────────────────────────────────────────

test('README contains required sections', function (): void {
    $readme = file_get_contents(__DIR__.'/../README.md');
    expect($readme)->toContain('## Installation');
    expect($readme)->toContain('## Testing');
    expect($readme)->toContain('PHP 8.5');
});
