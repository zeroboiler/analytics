<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaSStarterEvents;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;

it('PosthogTracker has trackBatch method', function (): void {
    expect(method_exists(PosthogTracker::class, 'trackBatch'))->toBeTrue();
});

it('PosthogTracker trackBatch uses host + capturePath (not baseUrl)', function (): void {
    $ref = new ReflectionClass(PosthogTracker::class);
    $method = $ref->getMethod('trackBatch');
    expect($method)->not->toBeFalse();

    $file = file_get_contents($ref->getFileName());
    expect($file)->not->toBeFalse();

    // Must use $this->host and $this->capturePath
    expect(str_contains($file, 'this->host'))->toBeTrue();
    expect(str_contains($file, 'this->capturePath'))->toBeTrue();

    // Must NOT reference non-existent baseUrl property
    expect(str_contains($file, 'this->baseUrl'))->toBeFalse();
});

it('PosthogTracker trackBatch uses correct $lib identifier', function (): void {
    $ref = new ReflectionClass(PosthogTracker::class);
    $file = file_get_contents($ref->getFileName());
    expect($file)->not->toBeFalse();

    // The batch method should use zeroboiler-analytics-server, not zeroboiler-php
    // Check all occurrences of $lib in trackBatch
    preg_match('/public function trackBatch.*?public function \w+\(/s', $file, $matches);
    $trackBatchBody = $matches[0] ?? '';

    // In the batch method body, should contain zeroboiler-analytics-server
    expect(str_contains($trackBatchBody, "'zeroboiler-analytics-server'"))->toBeTrue();
    expect(str_contains($trackBatchBody, "'zeroboiler-php'"))->toBeFalse();
});

it('PosthogTracker trackBatch checks consent before dispatching', function (): void {
    $ref = new ReflectionClass(PosthogTracker::class);
    $file = file_get_contents($ref->getFileName());
    expect($file)->not->toBeFalse();

    preg_match('/public function trackBatch.*?public function \w+\(/s', $file, $matches);
    $trackBatchBody = $matches[0] ?? '';

    expect(str_contains($trackBatchBody, 'isAnalyticsDenied'))->toBeTrue();
});

it('EventCatalog EventEntry type includes all 8 provider fields', function (): void {
    $ref = new ReflectionClass(EventCatalog::class);
    $file = file_get_contents($ref->getFileName());
    expect($file)->not->toBeFalse();

    // The phpstan type annotation should include all providers
    $typePattern = '/@phpstan-type EventEntry array\{[^}]+\}/';
    preg_match($typePattern, $file, $matches);
    expect($matches[0])->not->toBeEmpty();

    $typeDef = $matches[0];
    expect($typeDef)->toContain('ga4:');
    expect($typeDef)->toContain('meta:');
    expect($typeDef)->toContain('posthog:');
    expect($typeDef)->toContain('plausible:');
    expect($typeDef)->toContain('mixpanel:');
    expect($typeDef)->toContain('amplitude:');
    expect($typeDef)->toContain('tiktok:');
    expect($typeDef)->toContain('linkedin:');
    expect($typeDef)->toContain('category:');
});

it('SaaSStarterEvents providerCoverage handles null catalog entry safely', function (): void {
    // All 20 starter events should exist in the catalog, but the method
    // must handle the null case gracefully
    $coverage = SaaSStarterEvents::providerCoverage();

    expect($coverage)->toBeArray();
    expect(count($coverage))->toBe(20);

    // Each entry should have the required keys
    foreach ($coverage as $name => $entry) {
        expect($entry)->toHaveKeys([
            'event',
            'label',
            'category',
            'providers',
            'covered_count',
            'total_providers',
            'coverage_pct',
            'fully_covered',
        ]);

        expect($entry['total_providers'])->toBe(8);
        expect($entry['coverage_pct'])->toBeFloat();
        expect($entry['fully_covered'])->toBeBool();
        expect($entry['providers'])->toBeArray();
        expect(count($entry['providers']))->toBe(8);
    }
});

it('SaaSStarterEvents providerCoverageSummary returns valid structure', function (): void {
    $summary = SaaSStarterEvents::providerCoverageSummary();

    expect($summary)->toHaveKeys([
        'providers',
        'overall_pct',
        'fully_covered_events',
        'total_events',
    ]);

    expect($summary['providers'])->toBeArray();
    expect(count($summary['providers']))->toBe(8);

    foreach ($summary['providers'] as $provider => $data) {
        expect($data)->toHaveKeys(['covered', 'total', 'pct', 'uncovered_events']);
        expect($data['covered'])->toBeInt();
        expect($data['total'])->toBe(20);
        expect($data['pct'])->toBeFloat();
        expect($data['uncovered_events'])->toBeArray();
    }

    expect($summary['overall_pct'])->toBeFloat();
    expect($summary['fully_covered_events'])->toBeInt();
    expect($summary['total_events'])->toBe(20);
});

it('phpstan.neon uses includes format (not PHP initializer)', function (): void {
    $content = file_get_contents(__DIR__ . '/../phpstan.neon');
    expect($content)->not->toBeFalse();

    // Must be the traditional NEON includes format, not PHP
    expect(str_starts_with(trim($content), 'includes:'))->toBeTrue();
    expect(str_contains($content, 'phpstan.neon.dist'))->toBeTrue();

    // Must NOT be a PHP file
    expect(str_contains($content, '<?php'))->toBeFalse();
    expect(str_contains($content, 'PHPStan\\Config\\Initiator'))->toBeFalse();
});

it('version consistency across entry points', function (): void {
    $phpVersion = \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION;
    $jsContent = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($jsContent)->not->toBeFalse();

    preg_match("/@version\s+(\d+\.\d+\.\d+)/", $jsContent, $jsMatch);
    expect($jsMatch[1] ?? '')->toBe($phpVersion);

    // Also check getVersion export
    preg_match("/return '(\d+\.\d+\.\d+)'/", $jsContent, $fnMatch);
    expect($fnMatch[1] ?? '')->toBe($phpVersion);
});

it('all starter events exist in the full EventCatalog', function (): void {
    $missing = SaaSStarterEvents::missingFromCatalog();
    expect($missing)->toBe([]);
    expect(SaaSStarterEvents::coveragePercent())->toBe(100.0);
});
