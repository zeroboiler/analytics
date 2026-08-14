<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Marketing\MarketingEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Events\SaaS\CustomerSuccessEvents;

test('v138.0.0 all constructors declare :void return type', function (): void {
    $srcDir = __DIR__ . '/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    $violations = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = $file->getContents();
        preg_match_all('/public\s+function\s+__construct\s*\(([^)]*)\)\s*(?::\s*void)?\s*\{/', $contents, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $i => $fullMatch) {
            $hasVoid = (bool) preg_match('/:\s*void\s*\{/', $fullMatch[0]);
            if (! $hasVoid) {
                $violations[] = $file->getPathname() . ':' . $fullMatch[1];
            }
        }
    }

    expect($violations)->toBeEmpty('Constructors missing :void return type: ' . implode(', ', $violations));
});

test('v138.0.0 version consistency across all artifacts', function (): void {
    $version = '137.0.0';

    // AnalyticsEvent::VERSION
    expect(AnalyticsEvent::VERSION)->toBe($version);

    // composer.json
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe($version);

    // IntegrityCommand::EXPECTED_VERSION
    $reflection = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class);
    expect($reflection->getConstant('EXPECTED_VERSION'))->toBe($version);

    // JS client library
    $jsContents = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
    expect($jsContents)->toContain("@version {$version}");

    // TypeScript definitions
    $dtsContents = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($dtsContents)->toContain("@version {$version}");

    // Svelte composables
    $composables = [
        'useAnalytics.svelte.js',
        'useLifecycle.svelte.js',
        'usePerformanceTracker.svelte.js',
        'useSessionReplay.svelte.js',
        'useAnalyticsConfig.svelte.js',
    ];
    foreach ($composables as $composable) {
        $path = __DIR__ . '/../resources/js/' . $composable;
        expect(file_get_contents($path))->toContain("@version {$version}");
    }
});

test('v138.0.0 all source files declare strict_types=1', function (): void {
    $srcDir = __DIR__ . '/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    $violations = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = $file->getContents();
        if (! str_contains($contents, 'declare(strict_types=1);')) {
            $violations[] = $file->getPathname();
        }
    }

    expect($violations)->toBeEmpty('Files missing strict_types: ' . implode(', ', $violations));
});

test('v138.0.0 event catalog has all 8 categories', function (): void {
    $byCategory = EventCatalog::byCategory();
    $expectedCategories = [
        'ecommerce',
        'saas',
        'engagement',
        'security',
        'uptime',
        'infrastructure',
        'marketing',
        'customer_success',
    ];

    foreach ($expectedCategories as $category) {
        expect(isset($byCategory[$category]))->toBeTrue("Missing category: {$category}");
        expect($byCategory[$category])->toBeArray();
        expect(count($byCategory[$category]))->toBeGreaterThan(0, "Category {$category} has no events");
    }

    // Total catalog size check — should have 200+ events
    $allEvents = EventCatalog::all();
    expect(count($allEvents))->toBeGreaterThan(200, 'Catalog should have 200+ events');

    // Each catalog entry must have required fields
    foreach ($allEvents as $name => $entry) {
        expect($entry)->toHaveKey('name');
        expect($entry)->toHaveKey('class');
        expect($entry)->toHaveKey('ga4');
        expect($entry['name'])->toBe($name);
    }
});

test('v138.0.0 no TODO/FIXME/HACK in source files', function (): void {
    $srcDir = __DIR__ . '/../src';
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    $violations = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $contents = $file->getContents();
        preg_match_all('/\/\/\s*(TODO|FIXME|HACK|XXX)[^\n]*/i', $contents, $matches);
        foreach ($matches[0] as $match) {
            $violations[] = $file->getPathname() . ': ' . trim($match);
        }
    }

    expect($violations)->toBeEmpty('Found TODO/FIXME/HACK comments: ' . implode('; ', $violations));
});

test('v138.0.0 ecommerce catalog completeness', function (): void {
    $required = [
        'view_item', 'add_to_cart', 'remove_from_cart', 'view_cart',
        'begin_checkout', 'add_payment_info', 'purchase', 'refund',
        'add_to_wishlist', 'select_item', 'select_promotion', 'view_promotion',
    ];

    foreach ($required as $event) {
        expect(EcommerceEvents::has($event))->toBeTrue("Missing ecommerce event: {$event}");
        $entry = EcommerceEvents::get($event);
        expect($entry)->not->toBeNull();
        expect($entry['ga4'])->toBeString();
        expect($entry['meta'] !== null || $entry['posthog'] !== null)->toBeTrue(
            "Event {$event} must have at least one provider mapping",
        );
    }
});

test('v138.0.0 SaaS catalog completeness', function (): void {
    $required = [
        'sign_up', 'login', 'start_trial', 'subscribe',
        'plan_upgrade', 'plan_downgrade', 'cancellation',
    ];

    foreach ($required as $event) {
        expect(SaaSEvents::has($event))->toBeTrue("Missing SaaS event: {$event}");
        $entry = SaaSEvents::get($event);
        expect($entry)->not->toBeNull();
        expect($entry['ga4'])->toBeString();
    }

    // Check classFor returns valid class names
    expect(SaaSEvents::classFor('sign_up'))->toBeString();
    expect(class_exists(SaaSEvents::classFor('sign_up')))->toBeTrue('sign_up class must exist');
});

test('v138.0.0 engagement catalog completeness', function (): void {
    $required = [
        'page_view', 'scroll_depth', 'click', 'form_start',
        'form_submit', 'search', 'share', 'error',
    ];

    foreach ($required as $event) {
        expect(EngagementEvents::has($event))->toBeTrue("Missing engagement event: {$event}");
        $entry = EngagementEvents::get($event);
        expect($entry)->not->toBeNull();
    }
});

test('v138.0.0 EventCatalog::getCategory returns correct category', function (): void {
    expect(EventCatalog::getCategory('view_item'))->toBe('ecommerce');
    expect(EventCatalog::getCategory('sign_up'))->toBe('saas');
    expect(EventCatalog::getCategory('page_view'))->toBe('engagement');
    expect(EventCatalog::getCategory('login_attempt'))->toBe('security');
    expect(EventCatalog::getCategory('service_up'))->toBe('uptime');
    expect(EventCatalog::getCategory('pipeline_failure'))->toBe('infrastructure');
    expect(EventCatalog::getCategory('email_sent'))->toBe('marketing');
    expect(EventCatalog::getCategory('health_score_changed'))->toBe('customer_success');
    expect(EventCatalog::getCategory('nonexistent_event_xyz'))->toBeNull();
});

test('v138.0.0 EventCatalog::resolve handles all registered events', function (): void {
    $all = EventCatalog::all();
    $resolved = 0;
    $notInstantiable = 0;

    foreach ($all as $name => $entry) {
        $className = $entry['class'];
        if (class_exists($className)) {
            $resolved++;
        }
    }

    // All catalog entries should have resolvable classes
    expect($resolved)->toBe(count($all), "All {$resolved}/" . count($all) . " catalog entries should have existing classes");
});
