<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Services\EventArchetypeService;
use ZeroBoiler\Analytics\Services\ConfigDriftDetectionService;
use ZeroBoiler\Analytics\Services\EventAnonymizationAggregationService;

describe('Event Archetype Service', function (): void {
    beforeEach(function (): void {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturn([]);
        $cache->shouldReceive('put')->andReturn(true);
        $cache->shouldReceive('has')->andReturn(false);
        $cache->shouldReceive('forget')->andReturn(true);

        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.archetypes', [])
            ->andReturn(['enabled' => true, 'cache_ttl' => 3600, 'custom' => []]);

        $this->service = new EventArchetypeService($cache, $config);
    });

    it('provides built-in archetype keys', function (): void {
        $keys = $this->service->keys();

        expect($keys)->toContain('signup_funnel');
        expect($keys)->toContain('activation');
        expect($keys)->toContain('trial_conversion');
        expect($keys)->toContain('ecommerce_checkout');
        expect($keys)->toContain('expansion');
        expect($keys)->toContain('retention_loop');
    });

    it('returns archetype by key', function (): void {
        $archetype = $this->service->get('signup_funnel');

        expect($archetype)->not->toBeNull();
        expect($archetype['name'])->toBe('Signup Funnel');
        expect($archetype['category'])->toBe('acquisition');
        expect($archetype['steps'])->toBeArray();
        expect($archetype['steps'])->not->toBeEmpty();
    });

    it('returns null for unknown archetype', function (): void {
        expect($this->service->get('nonexistent'))->toBeNull();
    });

    it('groups archetypes by category', function (): void {
        $groups = $this->service->byCategory();

        expect($groups)->toHaveKey('acquisition');
        expect($groups)->toHaveKey('activation');
        expect($groups)->toHaveKey('conversion');
        expect($groups)->toHaveKey('ecommerce');
        expect($groups)->toHaveKey('growth');
        expect($groups)->toHaveKey('retention');
    });

    it('provides archetype summary', function (): void {
        $summary = $this->service->summary();

        expect($summary)->toBeArray();
        expect($summary)->not->toBeEmpty();

        $first = $summary[0];
        expect($first)->toHaveKeys(['key', 'name', 'description', 'category', 'steps', 'required_steps', 'event_names']);
    });

    it('detects instrumentation gaps', function (): void {
        $gaps = $this->service->detectGaps();

        expect($gaps)->toHaveKeys(['gaps', 'coverage_pct', 'total_steps', 'missing_steps']);
        expect($gaps['coverage_pct'])->toBeGreaterThan(0.0);
        expect($gaps['total_steps'])->toBeGreaterThan(0);
    });

    it('calculates completion score', function (): void {
        $score = $this->service->completionScore('signup_funnel', ['page_view', 'sign_up', 'email_verified', 'login']);

        expect($score)->toHaveKeys(['score', 'max_score', 'completed_steps', 'missing_steps', 'pct']);
        expect($score['pct'])->toBeGreaterThan(0.0);
        expect($score['completed_steps'])->not->toBeEmpty();
    });

    it('returns zero score for unknown archetype', function (): void {
        $score = $this->service->completionScore('nonexistent', ['page_view']);

        expect($score['pct'])->toBe(0.0);
        expect($score['max_score'])->toBe(0.0);
    });

    it('returns full score when all events completed', function (): void {
        $archetype = $this->service->get('signup_funnel');
        $allEvents = array_map(fn (array $s): string => $s['event'], $archetype['steps']);

        $score = $this->service->completionScore('signup_funnel', $allEvents);

        expect($score['pct'])->toBe(100.0);
        expect($score['missing_steps'])->toBeEmpty();
    });

    it('generates lifecycle config from archetype', function (): void {
        $lifecycle = $this->service->toLifecycleConfig('trial_conversion');

        expect($lifecycle)->toBeArray();
        expect($lifecycle)->toHaveKey('start_trial');
        expect($lifecycle)->toHaveKey('purchase');
    });

    it('returns empty lifecycle config for unknown archetype', function (): void {
        expect($this->service->toLifecycleConfig('nonexistent'))->toBe([]);
    });

    it('collects all event names across archetypes', function (): void {
        $events = $this->service->allEventNames();

        expect($events)->toContain('page_view');
        expect($events)->toContain('sign_up');
        expect($events)->toContain('purchase');
        expect($events)->toContain('feature_used');
    });

    it('reports enabled status', function (): void {
        expect($this->service->isEnabled())->toBeTrue();
    });
});

describe('Config Drift Detection Service', function (): void {
    beforeEach(function (): void {
        $this->cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $this->cache->shouldReceive('put')->andReturn(true);
        $this->cache->shouldReceive('forget')->andReturn(true);

        $this->config = mock(\Illuminate\Contracts\Config\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.config_drift', [])
            ->andReturn(['enabled' => true, 'cache_ttl' => 2592000, 'exclude_keys' => [], 'monitored_sections' => []]);

        // For snapshot building
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics', [])
            ->andReturn([
                'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST', 'api_secret' => 'secret'],
                'gtm' => ['enabled' => false],
                'meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
                'consent' => ['default' => 'granted'],
            ]);

        $this->service = new ConfigDriftDetectionService($this->cache, $this->config);
    });

    it('captures a baseline', function (): void {
        $meta = $this->service->captureBaseline();

        expect($meta)->toHaveKey('captured_at');
        expect($meta)->toHaveKey('version');
        expect($meta)->toHaveKey('sections');
        expect($meta)->toHaveKey('keys');
        expect($meta['version'])->toBe(AnalyticsEvent::VERSION);
    });

    it('reports no drift when no baseline exists', function (): void {
        $this->cache->shouldReceive('get')->with(\Mockery::any(), null)->andReturn(null);
        $this->cache->shouldReceive('has')->andReturn(false);

        $result = $this->service->detectDrift();

        expect($result['drift_detected'])->toBeFalse();
        expect($result['note'])->toContain('No baseline captured');
    });

    it('checks baseline existence', function (): void {
        $this->cache->shouldReceive('has')->andReturn(false);

        expect($this->service->hasBaseline())->toBeFalse();
    });

    it('returns baseline info when no baseline exists', function (): void {
        $this->cache->shouldReceive('has')->andReturn(false);
        $this->cache->shouldReceive('get')->andReturn(null);

        $info = $this->service->baselineInfo();

        expect($info['exists'])->toBeFalse();
        expect($info['captured_at'])->toBeNull();
    });

    it('reports enabled status', function (): void {
        expect($this->service->isEnabled())->toBeTrue();
    });
});

describe('Event Anonymization Aggregation Service', function (): void {
    beforeEach(function (): void {
        $this->cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $this->cache->shouldReceive('get')->andReturn(0);
        $this->cache->shouldReceive('increment')->andReturn(1);
        $this->cache->shouldReceive('put')->andReturn(true);

        $this->config = mock(\Illuminate\Contracts\Config\Repository::class);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.anonymized_aggregation', [])
            ->andReturn([
                'enabled' => true,
                'k_threshold' => 5,
                'cache_ttl' => 3600,
                'laplace_noise' => false,
                'noise_scale' => 1.0,
                'time_granularity' => 'hour',
                'max_event_age' => 86400,
            ]);

        $this->metrics = mock(\ZeroBoiler\Analytics\AnalyticsMetrics::class);
        $this->metrics->shouldReceive('getEventCounts')->andReturn([
            'page_view' => 150,
            'sign_up' => 20,
            'purchase' => 3, // Below k threshold
        ]);
        $this->metrics->shouldReceive('getCategoryCounts')->andReturn([
            'engagement' => 200,
            'saas' => 50,
            'ecommerce' => 2, // Below k threshold
        ]);

        $this->service = new EventAnonymizationAggregationService($this->cache, $this->config, $this->metrics);
    });

    it('aggregates by event with k-anonymity suppression', function (): void {
        $result = $this->service->aggregateByEvent();

        expect($result['aggregated']['page_view'])->toBe(150);
        expect($result['aggregated']['sign_up'])->toBe(20);
        expect($result['aggregated']['purchase'])->toBeNull(); // Below k=5
        expect($result['suppressed'])->toContain('purchase');
        expect($result['k_threshold'])->toBe(5);
    });

    it('aggregates by category with k-anonymity', function (): void {
        $result = $this->service->aggregateByCategory();

        expect($result['aggregated']['engagement'])->toBe(200);
        expect($result['aggregated']['saas'])->toBe(50);
        expect($result['aggregated']['ecommerce'])->toBeNull(); // Below k=5
    });

    it('aggregates by time', function (): void {
        $result = $this->service->aggregateByTime('hour', 3);

        expect($result['granularity'])->toBe('hour');
        expect($result['buckets'])->toBeArray();
        expect($result['buckets'])->toHaveCount(3);
        expect($result['k_threshold'])->toBe(5);
    });

    it('builds dashboard summary', function (): void {
        $summary = $this->service->dashboardSummary();

        expect($summary)->toHaveKeys(['events', 'categories', 'hourly', 'total_dispatched', 'k_threshold', 'noise_applied']);
        expect($summary['noise_applied'])->toBeFalse();
    });

    it('reports enabled status', function (): void {
        expect($this->service->isEnabled())->toBeTrue();
    });

    it('reports k threshold', function (): void {
        expect($this->service->getKThreshold())->toBe(5);
    });

    it('reports noise status', function (): void {
        expect($this->service->isNoiseEnabled())->toBeFalse();
    });
});
