<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventCatalogExplorerService;
use ZeroBoiler\Analytics\Services\ProviderDispatchOrderService;

/**
 * V190 — Provider Dispatch Order + Event Catalog Explorer — Industry-Standard SaaS Analytics Upgrade.
 *
 * Tests for:
 * - ProviderDispatchOrderService: dispatch plan, ordered providers, score breakdown, summary, stats
 * - EventCatalogExplorerService: search, fuzzy matching, recommendations, similar events, provider coverage, tag overview
 * - Source quality checks (strict_types, final, return types, MIT headers, @since)
 * - Version consistency across entry points
 * - File count minimums
 */
test('v190 provider dispatch order: service file quality', function (): void {
    $path = __DIR__ . '/../src/Services/ProviderDispatchOrderService.php';
    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    expect($content)->toContain('declare(strict_types=1)');
    expect($content)->toContain('final class ProviderDispatchOrderService');
    expect($content)->toContain('public function __construct');
    expect($content)->toContain('): void');
    expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    expect($content)->toContain('@since 190.0.0');
});

test('v190 provider dispatch order: construction and defaults', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ProviderDispatchOrderService($cache, $config);
    expect($service->isEnabled())->toBeTrue();
    expect($service->getMinScore())->toBeFloat();
    expect($service->getExcludedProviders())->toBeArray();
    expect($service->stats())->toHaveKeys(['enabled', 'min_score', 'provider_count', 'scoring_factor_count', 'excluded_count']);
    expect($service->stats()['provider_count'])->toBe(10);
    expect($service->stats()['scoring_factor_count'])->toBe(6);
});

test('v190 provider dispatch order: dispatchPlan returns correct structure', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ProviderDispatchOrderService($cache, $config);
    $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99], category: 'ecommerce');
    $plan = $service->dispatchPlan($event);

    expect($plan)->toHaveKeys(['providers', 'event', 'category', 'total_considered', 'total_selected', 'computed_at']);
    expect($plan['event'])->toBe('purchase');
    expect($plan['category'])->toBe('ecommerce');
    expect($plan['providers'])->toBeArray();
    expect($plan['total_considered'])->toBeGreaterThanOrEqual(0);
    expect($plan['computed_at'])->toBeInt();

    // Each provider entry should have required keys
    foreach ($plan['providers'] as $provider) {
        expect($provider)->toHaveKeys(['name', 'score', 'factors', 'excluded', 'reasons']);
        expect($provider['name'])->toBeString();
        expect($provider['score'])->toBeFloat();
        expect($provider['excluded'])->toBeBool();
        expect($provider['reasons'])->toBeArray();
    }
});

test('v190 provider dispatch order: orderedProviders returns list of strings', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ProviderDispatchOrderService($cache, $config);
    $event = new AnalyticsEvent(name: 'page_view', category: 'engagement');
    $ordered = $service->orderedProviders($event);

    expect($ordered)->toBeArray();
    // All elements should be strings
    foreach ($ordered as $name) {
        expect($name)->toBeString();
    }
});

test('v190 provider dispatch order: providerScoreBreakdown returns correct structure', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ProviderDispatchOrderService($cache, $config);
    $event = new AnalyticsEvent(name: 'sign_up', category: 'saas');
    $breakdown = $service->providerScoreBreakdown('ga4', $event);

    expect($breakdown)->toHaveKeys(['provider', 'event', 'composite_score', 'factors', 'excluded', 'reasons']);
    expect($breakdown['provider'])->toBe('ga4');
    expect($breakdown['event'])->toBe('sign_up');
    expect($breakdown['composite_score'])->toBeFloat();
    expect($breakdown['factors'])->toHaveKeys(['health', 'sla', 'budget', 'coverage', 'cost', 'consent']);
});

test('v190 provider dispatch order: summary returns provider stats', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ProviderDispatchOrderService($cache, $config);
    $summary = $service->summary();

    expect($summary)->toHaveKeys(['enabled', 'min_score', 'providers', 'scoring_factors']);
    expect($summary['enabled'])->toBeBool();
    expect($summary['providers'])->toBeArray();
    expect($summary['scoring_factors'])->toBeArray();

    // Scoring factors should have weight and description
    foreach ($summary['scoring_factors'] as $factor => $def) {
        expect($def)->toHaveKeys(['weight', 'description']);
        expect($def['weight'])->toBeFloat();
    }
});

test('v190 provider dispatch order: scoringFactors returns factor definitions', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ProviderDispatchOrderService($cache, $config);
    $factors = $service->scoringFactors();

    expect($factors)->toBeArray();
    expect($factors)->toHaveKey('health');
    expect($factors['health'])->toHaveKeys(['weight', 'description']);
    expect($factors['health']['weight'])->toBe(0.25);
});

test('v190 provider dispatch order: clearCache no-throw', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new ProviderDispatchOrderService($cache, $config);
    expect(fn (): mixed => $service->clearCache())->not->toThrow();
});

test('v190 catalog explorer: service file quality', function (): void {
    $path = __DIR__ . '/../src/Services/EventCatalogExplorerService.php';
    expect(file_exists($path))->toBeTrue();

    $content = file_get_contents($path);
    expect($content)->toContain('declare(strict_types=1)');
    expect($content)->toContain('final class EventCatalogExplorerService');
    expect($content)->toContain('public function __construct');
    expect($content)->toContain('): void');
    expect($content)->toContain('This file is part of ZeroBoiler, licensed under the MIT license');
    expect($content)->toContain('@since 190.0.0');
});

test('v190 catalog explorer: construction and defaults', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventCatalogExplorerService($cache, $config);
    expect($service->isEnabled())->toBeTrue();
    expect($service->stats())->toHaveKeys(['enabled', 'total_events', 'total_categories', 'total_tags', 'use_cases_supported', 'max_results', 'fuzzy_sensitivity']);
    expect($service->stats()['total_events'])->toBeGreaterThan(0);
    expect($service->stats()['use_cases_supported'])->toBeGreaterThan(0);
});

test('v190 catalog explorer: search with exact match', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventCatalogExplorerService($cache, $config);
    $result = $service->search('purchase');

    expect($result)->toHaveKeys(['query', 'results', 'total', 'filters']);
    expect($result['query'])->toBe('purchase');
    expect($result['results'])->toBeArray();
    expect($result['total'])->toBeGreaterThanOrEqual(1);

    // First result should be exact match
    $first = $result['results'][0];
    expect($first['name'])->toBe('purchase');
    expect($first['match_type'])->toBe('exact');
    expect($first['similarity'])->toBe(100.0);
});

test('v190 catalog explorer: search with category filter', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventCatalogExplorerService($cache, $config);
    $result = $service->search('purchase', ['category' => 'ecommerce']);

    expect($result['filters']['category'])->toBe('ecommerce');
    foreach ($result['results'] as $r) {
        expect($r['category'])->toBe('ecommerce');
    }
});

test('v190 catalog explorer: search with empty query returns nothing', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventCatalogExplorerService($cache, $config);
    $result = $service->search('zzzz_nonexistent_event_xyz');

    expect($result['total'])->toBe(0);
    expect($result['results'])->toBeEmpty();
});

test('v190 catalog explorer: recommend returns relevant events', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventCatalogExplorerService($cache, $config);
    $result = $service->recommend('signup');

    expect($result)->toHaveKeys(['use_case', 'events', 'matched_keywords']);
    expect($result['use_case'])->toBe('signup');
    expect($result['events'])->toBeArray();
    expect($result['matched_keywords'])->toBeArray();

    // Should find sign_up as primary
    $hasSignUp = false;
    foreach ($result['events'] as $e) {
        if ($e['name'] === 'sign_up' && $e['relevance'] === 'primary') {
            $hasSignUp = true;
            break;
        }
    }
    expect($hasSignUp)->toBeTrue();
});

test('v190 catalog explorer: similar events returns related items', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventCatalogExplorerService($cache, $config);
    $result = $service->similar('purchase');

    expect($result)->toHaveKeys(['event', 'category', 'similar']);
    expect($result['event'])->toBe('purchase');
    expect($result['category'])->toBe('ecommerce');
    expect($result['similar'])->toBeArray();

    // Should find related ecommerce events
    $names = array_column($result['similar'], 'name');
    expect($names)->not->toBeEmpty();
});

test('v190 catalog explorer: similar with unknown event returns empty', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventCatalogExplorerService($cache, $config);
    $result = $service->similar('nonexistent_xyz_event');

    expect($result['event'])->toBe('nonexistent_xyz_event');
    expect($result['category'])->toBe('unknown');
    expect($result['similar'])->toBeEmpty();
});

test('v190 catalog explorer: providerCoverage returns analysis', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventCatalogExplorerService($cache, $config);
    $result = $service->providerCoverage('ga4');

    expect($result)->toHaveKeys(['provider', 'total_mapped', 'total_events', 'coverage_percent', 'by_category', 'unmapped_events']);
    expect($result['provider'])->toBe('ga4');
    expect($result['total_mapped'])->toBeGreaterThan(0);
    expect($result['total_events'])->toBeGreaterThan(0);
    expect($result['coverage_percent'])->toBeGreaterThan(0.0);
    expect($result['by_category'])->toBeArray();
});

test('v190 catalog explorer: tagOverview returns tag groups', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventCatalogExplorerService($cache, $config);
    $result = $service->tagOverview();

    expect($result)->toHaveKeys(['tags', 'total_tags', 'most_common', 'least_common']);
    expect($result['total_tags'])->toBeGreaterThan(0);
    expect($result['most_common'])->toBeArray();
    expect($result['least_common'])->toBeArray();
});

test('v190 catalog explorer: clearCache no-throw', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventCatalogExplorerService($cache, $config);
    expect(fn (): mixed => $service->clearCache())->not->toThrow();
});

test('v190 catalog explorer: fuzzy search with typo', function (): void {
    $cache = app('cache');
    $config = app('config');

    $service = new EventCatalogExplorerService($cache, $config);
    $result = $service->search('purchas', ['min_similarity' => 50]);

    // "purchas" is close to "purchase" — should find it via prefix match
    $names = array_column($result['results'], 'name');
    expect($names)->toContain('purchase');
});

test('v190 version consistency: all entry points synced to 190.0.0', function (): void {
    // composer.json
    $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
    expect($composer['version'])->toBe('190.0.0');

    // AnalyticsEvent::VERSION
    $dtoContent = file_get_contents(__DIR__ . '/../src/DTO/AnalyticsEvent.php');
    expect($dtoContent)->toContain("public const VERSION = '190.0.0';");

    // README badge
    $readme = file_get_contents(__DIR__ . '/../README.md');
    expect($readme)->toContain('version-190.0.0');
});

test('v190 file count minimums: src and test counts', function (): void {
    $srcFiles = glob(__DIR__ . '/../src/**/*.php');
    $srcCount = count($srcFiles);
    expect($srcCount)->toBeGreaterThanOrEqual(857); // 855 + 2 new services

    $testFiles = glob(__DIR__ . '/../tests/**/*.php');
    expect(count($testFiles))->toBeGreaterThanOrEqual(436); // 435 + 1 new test
});

test('v190 service count minimum', function (): void {
    $serviceFiles = glob(__DIR__ . '/../src/Services/*.php');
    expect(count($serviceFiles))->toBeGreaterThanOrEqual(394); // 392 + 2 new
});
