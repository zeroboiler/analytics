<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Cache\ArrayCacheStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\AARRRFrameworkService;

/**
 * @covers \ZeroBoiler\Analytics\Services\AARRRFrameworkService
 *
 * @since 6.2.0
 */
describe('AARRR Framework Service', function () {
    beforeEach(function () {
        $config = new ConfigRepository(['zeroboiler' => ['analytics' => [
            'ga4' => ['enabled' => false],
            'gtm' => ['enabled' => false],
            'meta_pixel' => ['enabled' => false],
            'plausible' => ['enabled' => false],
            'posthog' => ['enabled' => false],
            'webhook' => ['enabled' => false],
        ]]]);

        $container = new Container;
        $container->instance(ConfigRepository::class, $config);
        Container::setInstance($container);

        $manager = new AnalyticsManager($config);
        $store = new ArrayCacheStore;
        $cache = new CacheRepository($store);

        $this->service = new AARRRFrameworkService($manager, $cache, $config);
        $this->cache = $cache;
        $this->manager = $manager;
    });

    afterEach(function () {
        Container::setInstance(null);
    });

    // ── Pillar Definitions ──────────────────────────────────────

    it('returns all five AARRR pillars', function () {
        $pillars = $this->service->pillars();

        expect(array_keys($pillars))->toHaveKeys([
            'acquisition', 'activation', 'retention', 'revenue', 'referral',
        ]);
    });

    it('each pillar has required keys', function () {
        $pillars = $this->service->pillars();

        foreach ($pillars as $key => $pillar) {
            expect($pillar)->toHaveKeys([
                'label', 'events', 'weight', 'description', 'coverage', 'total_events',
            ]);
        }
    });

    it('pillar weights sum to 1.0', function () {
        $pillars = $this->service->pillars();
        $total = array_sum(array_map(fn (array $p): float => $p['weight'], $pillars));

        expect($total)->toBeBetween(0.99, 1.01);
    });

    it('returns a single pillar by key', function () {
        $acquisition = $this->service->pillar('acquisition');

        expect($acquisition)->not->toBeNull();
        expect($acquisition['label'])->toBe('Acquisition');
        expect($acquisition['events'])->toContain('sign_up');
    });

    it('returns null for invalid pillar key', function () {
        expect($this->service->pillar('nonexistent'))->toBeNull();
    });

    it('pillar coverage does not exceed total events', function () {
        $pillars = $this->service->pillars();

        foreach ($pillars as $key => $pillar) {
            expect($pillar['coverage'])->toBeLessThanOrEqual($pillar['total_events']);
        }
    });

    // ── Health Scoring ───────────────────────────────────────────

    it('health score returns valid structure', function () {
        $health = $this->service->healthScore();

        expect($health)->toHaveKeys(['score', 'grade', 'pillars', 'recommendations']);
        expect($health['score'])->toBeGreaterThanOrEqual(0.0);
        expect($health['score'])->toBeLessThanOrEqual(100.0);
    });

    it('health score pillars have valid grades', function () {
        $health = $this->service->healthScore();
        $validGrades = ['A+', 'A', 'B', 'C', 'D', 'F'];

        foreach ($health['pillars'] as $key => $pillar) {
            expect($pillar['grade'])->toBeIn($validGrades);
        }
    });

    it('health score is cached', function () {
        $this->service->healthScore();

        // Cache should have been populated
        expect($this->cache->has('zb_aarrr_health_score'))->toBeTrue();
    });

    it('invalidate cache clears health score', function () {
        $this->service->healthScore();
        expect($this->cache->has('zb_aarrr_health_score'))->toBeTrue();

        $this->service->invalidateCache();
        expect($this->cache->has('zb_aarrr_health_score'))->toBeFalse();
    });

    // ── Weakest/Strongest Pillar ─────────────────────────────────

    it('weakest pillar returns valid structure', function () {
        $weakest = $this->service->weakestPillar();

        expect($weakest)->not->toBeNull();
        expect($weakest)->toHaveKeys(['pillar', 'score', 'grade', 'recommendation']);
    });

    it('strongest pillar returns valid structure', function () {
        $strongest = $this->service->strongestPillar();

        expect($strongest)->not->toBeNull();
        expect($strongest)->toHaveKeys(['pillar', 'score', 'grade']);
    });

    it('strongest score >= weakest score', function () {
        $strongest = $this->service->strongestPillar();
        $weakest = $this->service->weakestPillar();

        expect($strongest['score'])->toBeGreaterThanOrEqual($weakest['score']);
    });

    // ── Coverage Analysis ───────────────────────────────────────

    it('coverage analysis returns all pillars', function () {
        $coverage = $this->service->coverageAnalysis();

        expect($coverage)->toHaveCount(5);
        expect(array_keys($coverage))->toBe([
            'acquisition', 'activation', 'retention', 'revenue', 'referral',
        ]);
    });

    it('coverage analysis has covered, missing, and coverage_pct', function () {
        $coverage = $this->service->coverageAnalysis();

        foreach ($coverage as $key => $data) {
            expect($data)->toHaveKeys(['covered', 'missing', 'coverage_pct']);
            expect($data['coverage_pct'])->toBeGreaterThanOrEqual(0.0);
            expect($data['coverage_pct'])->toBeLessThanOrEqual(100.0);
        }
    });

    it('covered + missing equals total events per pillar', function () {
        $pillars = $this->service->pillars();
        $coverage = $this->service->coverageAnalysis();

        foreach ($pillars as $key => $pillar) {
            $total = $pillar['total_events'];
            $covered = count($coverage[$key]['covered']);
            $missing = count($coverage[$key]['missing']);

            expect($covered + $missing)->toBe($total);
        }
    });

    // ── Unmapped Events ─────────────────────────────────────────

    it('unmapped events returns a list of strings', function () {
        $unmapped = $this->service->unmappedEvents();

        expect($unmapped)->toBeArray();
        foreach ($unmapped as $event) {
            expect($event)->toBeString();
        }
    });

    it('unmapped events exist in the catalog', function () {
        $unmapped = $this->service->unmappedEvents();

        foreach ($unmapped as $event) {
            expect(EventCatalog::has($event))->toBeTrue();
        }
    });

    // ── Dashboard ────────────────────────────────────────────────

    it('dashboard returns complete structure', function () {
        $dashboard = $this->service->dashboard();

        expect($dashboard)->toHaveKeys([
            'health', 'pillars', 'weakest', 'strongest',
            'coverage', 'recommendations', 'unmapped_count', 'total_catalog_events',
        ]);
        expect($dashboard['health'])->toHaveKeys(['score', 'grade']);
    });

    it('dashboard total events matches catalog count', function () {
        $dashboard = $this->service->dashboard();

        expect($dashboard['total_catalog_events'])->toBe(EventCatalog::count());
    });

    // ── Integration with EventCatalog ─────────────────────────────

    it('AARRR events exist in the event catalog', function () {
        $pillars = $this->service->pillars();
        $notInCatalog = [];

        foreach ($pillars as $key => $pillar) {
            foreach ($pillar['events'] as $eventName) {
                // 'first_feature_used' is a custom alias
                if ($eventName === 'first_feature_used') {
                    continue;
                }
                if (! EventCatalog::has($eventName)) {
                    $notInCatalog[] = "{$key}:{$eventName}";
                }
            }
        }

        expect($notInCatalog)->toBeEmpty();
    });

    it('revenue pillar overlaps with industry standard events', function () {
        $industry = EventCatalog::industryStandard();
        $industryNames = array_map(fn (array $e): string => $e['name'], $industry['all']);

        $revenuePillar = $this->service->pillar('revenue');
        expect($revenuePillar)->not->toBeNull();

        $overlap = array_intersect($revenuePillar['events'], $industryNames);
        expect(count($overlap))->toBeGreaterThan(0);
    });

    // ── Score Grading ────────────────────────────────────────────

    it('health score grade is a valid letter grade', function () {
        $health = $this->service->healthScore();
        $validGrades = ['A+', 'A', 'B', 'C', 'D', 'F'];

        expect($health['grade'])->toBeIn($validGrades);
    });
});
