<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\AnalyticsFake;

beforeEach(function (): void {
    $this->fake = new AnalyticsFake;
});

// ─── AnalyticsTestCommand Construction ──────────────────────────────────────

it('constructs with AnalyticsManager instance', function (): void {
    $manager = new AnalyticsManager;
    $command = new AnalyticsTestCommand($manager);

    expect($command)->toBeInstanceOf(AnalyticsTestCommand::class);
});

// ─── Version Consistency (12 markers) ────────────────────────────────────

it('has consistent version across all 12 markers', function (): void {
    $expected = '33.0.0';

    // 1. AnalyticsEvent::VERSION
    expect(AnalyticsEvent::VERSION)->toBe($expected);

    // 2. composer.json
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe($expected);

    // 3. package.json
    $pkg = json_decode(file_get_contents(__DIR__ . '/../package.json'), true);
    expect($pkg['version'])->toBe($expected);

    // 4. Integrity command
    $integrityFile = __DIR__ . '/../src/Console/Commands/AnalyticsIntegrityCommand.php';
    $integrityContent = file_get_contents($integrityFile);
    expect($integrityContent)->toContain("EXPECTED_VERSION = '{$expected}'");

    // 5. ServiceProvider docblock
    $spFile = __DIR__ . '/../src/AnalyticsServiceProvider.php';
    $spContent = file_get_contents($spFile);
    expect($spContent)->toContain("@version {$expected}");

    // 6. JS getVersion()
    $jsFile = __DIR__ . '/../resources/js/analytics.js';
    $jsContent = file_get_contents($jsFile);
    expect($jsContent)->toContain("@version {$expected}");
    expect($jsContent)->toContain("return '{$expected}'");

    // 7. Svelte useAnalytics
    $svelteA = file_get_contents(__DIR__ . '/../resources/js/useAnalytics.svelte.js');
    expect($svelteA)->toContain("@version {$expected}");

    // 8. Svelte useAnalyticsConfig
    $svelteC = file_get_contents(__DIR__ . '/../resources/js/useAnalyticsConfig.svelte.js');
    expect($svelteC)->toContain("@version {$expected}");

    // 9. Svelte usePerformanceTracker
    $svelteP = file_get_contents(__DIR__ . '/../resources/js/usePerformanceTracker.svelte.js');
    expect($svelteP)->toContain("@version {$expected}");

    // 10. TypeScript definitions
    $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
    expect($dts)->toContain("@version {$expected}");

    // 11. README badge
    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain("version-{$expected}");

    // 12. CHANGELOG entry exists
    $changelog = file_get_contents(__DIR__ . '/../CHANGELOG.md');
    expect($changelog)->toContain("[{$expected}]");
});

// ─── File Integrity ───────────────────────────────────────────────────────

it('all core PHP files use strict types', function (): void {
    $files = [
        'src/AnalyticsManager.php',
        'src/DTO/AnalyticsEvent.php',
        'src/AnalyticsServiceProvider.php',
        'src/Console/Commands/AnalyticsTestCommand.php',
        'src/Console/Commands/AnalyticsIntegrityCommand.php',
        'src/Console/Commands/AnalyticsOverviewCommand.php',
        'src/Console/Commands/AnalyticsSmokeRunnerCommand.php',
        'src/Events/EventCatalog.php',
        'src/Events/Ecommerce/EcommerceEvents.php',
        'src/Events/SaaS/SaaSEvents.php',
        'src/Events/Engagement/EngagementEvents.php',
        'src/Http/Controllers/AnalyticsEventController.php',
        'src/Services/LifecycleEventMapper.php',
        'src/Inertia/HandleInertiaAnalytics.php',
    ];

    foreach ($files as $file) {
        $path = __DIR__ . '/../' . $file;
        expect(file_exists($path))->toBeTrue("Missing file: {$file}");
        $content = file_get_contents($path);
        expect($content)->toContain('declare(strict_types=1)', "Missing strict types in: {$file}");
    }
});

it('AnalyticsTestCommand has final class modifier', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsTestCommand.php');

    expect($content)->toContain('final class AnalyticsTestCommand');
});

// ─── AnalyticsTestCommand Provider Coverage ────────────────────────────────

it('AnalyticsTestCommand source references all 10 providers', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsTestCommand.php');

    $providers = [
        'GA4', 'GTM', 'Meta Pixel', 'Plausible', 'PostHog',
        'Mixpanel', 'Amplitude', 'Webhook', 'TikTok', 'LinkedIn',
    ];

    foreach ($providers as $provider) {
        expect($content)->toContain("testProvider('{$provider}'", "Missing provider test: {$provider}");
    }
});

it('AnalyticsTestCommand supports --dry-run flag', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsTestCommand.php');

    expect($content)->toContain('--dry-run');
    expect($content)->toContain('dry-run');
});

it('AnalyticsTestCommand supports --json flag', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsTestCommand.php');

    expect($content)->toContain('--json');
    expect($content)->toContain('JSON_PRETTY_PRINT');
});

it('AnalyticsTestCommand supports --validate flag', function (): void {
    $content = file_get_contents(__DIR__ . '/../src/Console/Commands/AnalyticsTestCommand.php');

    expect($content)->toContain('--validate');
    expect($content)->toContain('validate');
});

// ─── Event Catalog Integrity ───────────────────────────────────────────────

it('event catalog is valid with no errors', function (): void {
    $validation = EventCatalog::validate();

    expect($validation['valid'])->toBeTrue("Catalog validation failed: " . implode('; ', $validation['errors']));
    expect($validation['errors'])->toBeEmpty();
});

it('event catalog has all 5 required categories', function (): void {
    $categories = EventCatalog::byCategory();

    expect($categories)->toHaveKeys([
        'ecommerce', 'saas', 'engagement', 'security', 'uptime',
    ]);
});

it('event catalog has entries for all categories', function (): void {
    $byCategory = EventCatalog::byCategory();

    foreach ($byCategory as $category => $events) {
        expect(count($events))->toBeGreaterThan(0, "Category '{$category}' has no events");
    }
});

it('event catalog has no duplicate names', function (): void {
    $names = EventCatalog::names();
    $uniqueNames = array_unique($names);

    expect(count($names))->toBe(count($uniqueNames), 'Duplicate event names detected in catalog');
});

// ─── EcommerceEvents Integrity ─────────────────────────────────────────────

it('EcommerceEvents has all required fields per entry', function (): void {
    $required = ['name', 'class', 'ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude'];
    $all = \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::all();

    foreach ($all as $eventName => $entry) {
        foreach ($required as $key) {
            expect(array_key_exists($key, $entry))->toBeTrue(
                "Ecommerce event '{$eventName}' missing key '{$key}'"
            );
        }
    }
});

it('EcommerceEvents has purchase and refund events', function (): void {
    $names = \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::names();

    expect($names)->toContain('purchase');
    expect($names)->toContain('refund');
    expect($names)->toContain('add_to_cart');
    expect($names)->toContain('view_item');
});

// ─── SaaSEvents Integrity ────────────────────────────────────────────────

it('SaaSEvents has signup, login, trial, subscription events', function (): void {
    $names = \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::names();

    expect($names)->toContain('sign_up');
    expect($names)->toContain('login');
    expect($names)->toContain('trial_start');
    expect($names)->toContain('subscription');
    expect($names)->toContain('cancellation');
});

// ─── EngagementEvents Integrity ────────────────────────────────────────────

it('EngagementEvents has page_view, scroll_depth, click, form events', function (): void {
    $names = \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::names();

    expect($names)->toContain('page_view');
    expect($names)->toContain('scroll_depth');
    expect($names)->toContain('click');
    expect($names)->toContain('form_start');
    expect($names)->toContain('form_submit');
    expect($names)->toContain('search');
});

// ─── Provider Count ────────────────────────────────────────────────────────

it('readme reflects 10 providers', function (): void {
    $readme = file_get_contents(__DIR__ . '/../README.md');

    expect($readme)->toContain('**10 providers**');
});
