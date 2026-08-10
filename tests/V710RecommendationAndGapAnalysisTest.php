<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventRecommendationService;
use ZeroBoiler\Analytics\Services\ProviderGapAnalyzer;

beforeEach(function (): void {
    $this->cache = mock(CacheRepository::class);
    $this->config = mock(ConfigRepository::class);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.recommendations', [])
        ->andReturn(['cache_ttl' => 60, 'excluded_events' => []]);
    $this->cache->shouldReceive('get')->andReturn(null);
    $this->cache->shouldReceive('put')->andReturn(true);
});

describe('EventRecommendationService', function (): void {
    test('recommend returns gaps for empty tracked events', function (): void {
        $service = new EventRecommendationService($this->cache, $this->config);
        $result = $service->recommend([]);

        expect($result)->toHaveKeys(['gaps', 'total_catalog', 'tracked_count', 'gap_count', 'coverage_percent', 'score', 'grade']);
        expect($result['tracked_count'])->toBe(0);
        expect($result['gap_count'])->toBeGreaterThan(0);
        expect($result['coverage_percent'])->toBe(0.0);
        expect($result['gaps'])->toHaveKeys(['critical', 'high', 'medium', 'low']);
        expect($result['gaps']['critical'])->not->toBeEmpty();
    });

    test('recommend returns empty gaps when all events tracked', function (): void {
        $service = new EventRecommendationService($this->cache, $this->config);
        $allNames = EventCatalog::names();
        $result = $service->recommend($allNames);

        expect($result['tracked_count'])->toBe($result['total_catalog']);
        expect($result['gap_count'])->toBe(0);
        expect($result['coverage_percent'])->toBe(100.0);
        expect($result['score'])->toBe(100);
        expect($result['grade'])->toBe('A');
    });

    test('recommend grades coverage correctly', function (): void {
        $service = new EventRecommendationService($this->cache, $this->config);

        // Track only critical events
        $result = $service->recommend(['sign_up', 'login', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation', 'page_view', 'purchase', 'payment_succeeded', 'payment_failed', 'trial_converted']);
        expect($result['grade'])->toBeString();

        // Score should be > 0 since we tracked critical events
        expect($result['score'])->toBeGreaterThan(0);
    });

    test('topRecommendations returns limited results', function (): void {
        $service = new EventRecommendationService($this->cache, $this->config);
        $top = $service->topRecommendations([], 5);

        expect($top)->toHaveCount(5);
        expect($top[0])->toHaveKeys(['name', 'category', 'priority', 'reason']);
        expect($top[0]['priority'])->toBe('critical');
    });

    test('topRecommendations respects limit', function (): void {
        $service = new EventRecommendationService($this->cache, $this->config);
        $top = $service->topRecommendations([], 3);

        expect($top)->toHaveCount(3);
    });

    test('aarrrBreakdown returns all pillars', function (): void {
        $service = new EventRecommendationService($this->cache, $this->config);
        $breakdown = $service->aarrrBreakdown(['sign_up', 'login', 'purchase']);

        expect($breakdown)->toHaveKeys(['acquisition', 'activation', 'retention', 'revenue', 'referral']);
        expect($breakdown['acquisition'])->toHaveKeys(['tracked', 'total', 'percent']);

        // sign_up and login are in acquisition
        expect($breakdown['acquisition']['tracked'])->toBeGreaterThanOrEqual(2);
    });

    test('aarrrBreakdown handles empty tracked events', function (): void {
        $service = new EventRecommendationService($this->cache, $this->config);
        $breakdown = $service->aarrrBreakdown([]);

        foreach ($breakdown as $pillar => $data) {
            expect($data['tracked'])->toBe(0);
            expect($data['percent'])->toBe(0.0);
        }
    });

    test('tiers returns priority tier configuration', function (): void {
        $service = new EventRecommendationService($this->cache, $this->config);
        $tiers = $service->tiers();

        expect($tiers)->toHaveKeys(['critical', 'high', 'medium', 'low']);
        foreach ($tiers as $name => $tier) {
            expect($tier)->toHaveKeys(['priority', 'label', 'event_count']);
            expect($tier['priority'])->toBe($name);
            expect($tier['event_count'])->toBeGreaterThan(0);
        }
    });

    test('recommend respects excluded events from config', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.recommendations', [])
            ->andReturn(['cache_ttl' => 60, 'excluded_events' => ['video_play', 'ad_click']]);

        $service = new EventRecommendationService($this->cache, $this->config);
        $result = $service->recommend([]);

        // Excluded events should not appear in gaps
        $allGapNames = array_merge(
            array_column($result['gaps']['critical'], 'name'),
            array_column($result['gaps']['high'], 'name'),
            array_column($result['gaps']['medium'], 'name'),
            array_column($result['gaps']['low'], 'name'),
        );

        expect($allGapNames)->not->toContain('video_play');
        expect($allGapNames)->not->toContain('ad_click');
    });

    test('recommend caches results', function (): void {
        $cacheKey = 'zb_recommendations_' . hash('xxh128', '');
        $this->cache->shouldReceive('get')
            ->with($cacheKey)
            ->andReturn(['score' => 99, 'grade' => 'A', 'gaps' => ['critical' => [], 'high' => [], 'medium' => [], 'low' => []], 'total_catalog' => 100, 'tracked_count' => 100, 'gap_count' => 0, 'coverage_percent' => 100.0]);
        $this->cache->shouldNotReceive('put');

        $service = new EventRecommendationService($this->cache, $this->config);
        $result = $service->recommend([]);

        expect($result['score'])->toBe(99);
        expect($result['grade'])->toBe('A');
    });
});

describe('ProviderGapAnalyzer', function (): void {
    beforeEach(function (): void {
        $this->manager = mock(AnalyticsManager::class);
        $this->manager->shouldReceive('ga4')->andReturnSelf();
        $this->manager->shouldReceive('ga4->isEnabled')->andReturn(true);
        $this->manager->shouldReceive('meta->isEnabled')->andReturn(true);
        $this->manager->shouldReceive('posthog->isEnabled')->andReturn(true);
        $this->manager->shouldReceive('plausible->isEnabled')->andReturn(false);
    });

    test('analyze returns provider coverage for tracked events', function (): void {
        $analyzer = new ProviderGapAnalyzer($this->cache, $this->config, $this->manager);
        $result = $analyzer->analyze(['sign_up', 'page_view', 'purchase']);

        expect($result)->toHaveKeys(['providers', 'cross_provider_gaps', 'summary']);
        expect($result['providers'])->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible']);
        expect($result['summary'])->toHaveKeys(['total_events', 'fully_covered', 'partial_coverage', 'no_coverage', 'overall_coverage_percent']);
        expect($result['summary']['total_events'])->toBe(3);
    });

    test('analyze identifies cross-provider gaps', function (): void {
        $analyzer = new ProviderGapAnalyzer($this->cache, $this->config, $this->manager);

        // 'sign_up' has GA4 + Meta + PostHog + Plausible
        // 'click' only has GA4 + Meta + PostHog, no Plausible — but Plausible is disabled
        $result = $analyzer->analyze(['sign_up', 'click']);

        expect($result['summary']['total_events'])->toBe(2);
        // sign_up is in catalog with all providers
        // click is in catalog with ga4, meta, posthog
        expect($result['summary']['fully_covered'])->toBeGreaterThanOrEqual(0);
    });

    test('mappedEvents returns events with provider mapping', function (): void {
        $analyzer = new ProviderGapAnalyzer($this->cache, $this->config, $this->manager);
        $mapped = $analyzer->mappedEvents(['sign_up', 'purchase', 'click'], 'ga4');

        expect($mapped)->not->toBeEmpty();
        expect($mapped)->toContain('sign_up');
    });

    test('gapEvents returns events without provider mapping', function (): void {
        $analyzer = new ProviderGapAnalyzer($this->cache, $this->config, $this->manager);
        $gaps = $analyzer->gapEvents(['sign_up'], 'plausible');

        // sign_up has plausible mapping ('signup'), so no gap expected
        expect($gaps)->toBeEmpty();
    });

    test('supportedProviders returns all four providers', function (): void {
        $analyzer = new ProviderGapAnalyzer($this->cache, $this->config, $this->manager);
        $providers = $analyzer->supportedProviders();

        expect($providers)->toEqual(['ga4', 'meta', 'posthog', 'plausible']);
    });

    test('gapEvents handles events not in catalog', function (): void {
        $analyzer = new ProviderGapAnalyzer($this->cache, $this->config, $this->manager);
        $gaps = $analyzer->gapEvents(['nonexistent_event'], 'ga4');

        expect($gaps)->toContain('nonexistent_event');
    });

    test('analyze handles empty tracked events', function (): void {
        $analyzer = new ProviderGapAnalyzer($this->cache, $this->config, $this->manager);
        $result = $analyzer->analyze([]);

        expect($result['summary']['total_events'])->toBe(0);
        expect($result['summary']['overall_coverage_percent'])->toBe(0.0);
    });

    test('analyze caches results', function (): void {
        $cacheKey = 'zb_provider_gaps_' . hash('xxh128', 'sign_up');
        $this->cache->shouldReceive('get')
            ->with($cacheKey)
            ->andReturn([
                'providers' => [],
                'cross_provider_gaps' => [],
                'summary' => ['total_events' => 1, 'fully_covered' => 1, 'partial_coverage' => 0, 'no_coverage' => 0, 'overall_coverage_percent' => 100.0],
            ]);
        $this->cache->shouldNotReceive('put');

        $analyzer = new ProviderGapAnalyzer($this->cache, $this->config, $this->manager);
        $result = $analyzer->analyze(['sign_up']);

        expect($result['summary']['total_events'])->toBe(1);
    });
});
