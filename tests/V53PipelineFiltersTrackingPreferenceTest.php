<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Pipeline\EventDeduplicationFilter;
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Pipeline\TrackingPreferenceFilter;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventDeduplicationService;
use ZeroBoiler\Analytics\Services\TrackingPreferenceService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Mockery;

beforeEach(function (): void {
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->config = Mockery::mock(ConfigRepository::class);

    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.validation', [])
        ->andReturn([
            'deduplication_window' => 10,
            'max_recent_events' => 500,
        ]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.dedup.enabled', true)
        ->andReturn(true);
});

afterEach(function (): void {
    Mockery::close();
});

describe('V53 — Pipeline Filters + Tracking Preference Routes', function (): void {

    describe('EventDeduplicationFilter', function (): void {
        test('passes unique events through the pipeline', function (): void {
            $service = new EventDeduplicationService($this->config, $this->cache);

            // Simulate cache: no existing fingerprint
            $this->cache->shouldReceive('has')
                ->andReturn(false);
            $this->cache->shouldReceive('put')
                ->once();

            // Recent list
            $this->cache->shouldReceive('get')
                ->with('zb_analytics_recent:list', [])
                ->andReturn([]);
            $this->cache->shouldReceive('put')
                ->once();

            $filter = new EventDeduplicationFilter($service);
            $event = new AnalyticsEvent(name: 'test_click', params: ['button' => 'buy']);

            $result = $filter($event);

            expect($result)->not->toBeNull();
            expect($result->name)->toBe('test_click');
        });

        test('drops duplicate events (returns null)', function (): void {
            $service = new EventDeduplicationService($this->config, $this->cache);

            // First call: no existing fingerprint (stores it)
            $this->cache->shouldReceive('has')
                ->once()
                ->andReturn(false);
            $this->cache->shouldReceive('put')
                ->once();

            // Recent list handling
            $this->cache->shouldReceive('get')
                ->andReturn([]);
            $this->cache->shouldReceive('put')
                ->once();

            $event1 = new AnalyticsEvent(name: 'test_click', params: ['button' => 'buy']);
            $result1 = $service->isDuplicate(
                eventName: $event1->name,
                clientId: $event1->clientId,
                userId: $event1->userId,
                params: $event1->params,
            );
            expect($result1)->toBeFalse();

            // Second call: fingerprint exists (duplicate)
            $this->cache->shouldReceive('has')
                ->once()
                ->andReturn(true);

            $event2 = new AnalyticsEvent(name: 'test_click', params: ['button' => 'buy']);
            $result2 = $service->isDuplicate(
                eventName: $event2->name,
                clientId: $event2->clientId,
                userId: $event2->userId,
                params: $event2->params,
            );
            expect($result2)->toBeTrue();
        });

        test('allows events with different params through', function (): void {
            $service = new EventDeduplicationService($this->config, $this->cache);

            // First event
            $this->cache->shouldReceive('has')
                ->once()
                ->andReturn(false);
            $this->cache->shouldReceive('put')
                ->twice();
            $this->cache->shouldReceive('get')
                ->andReturn([]);
            $this->cache->shouldReceive('put')
                ->once();

            $event1 = new AnalyticsEvent(name: 'page_view', params: ['page' => '/home']);
            $result1 = $service->isDuplicate(
                eventName: $event1->name,
                clientId: 'client-123',
                userId: null,
                params: $event1->params,
            );
            expect($result1)->toBeFalse();

            // Second event with different params (should NOT be duplicate)
            $this->cache->shouldReceive('has')
                ->once()
                ->andReturn(false);
            $this->cache->shouldReceive('put')
                ->twice();
            $this->cache->shouldReceive('get')
                ->andReturn([]);
            $this->cache->shouldReceive('put')
                ->once();

            $event2 = new AnalyticsEvent(name: 'page_view', params: ['page' => '/pricing']);
            $result2 = $service->isDuplicate(
                eventName: $event2->name,
                clientId: 'client-123',
                userId: null,
                params: $event2->params,
            );
            expect($result2)->toBeFalse();
        });
    });

    describe('TrackingPreferenceFilter', function (): void {
        test('passes events for users without opt-out', function (): void {
            $preferenceService = new TrackingPreferenceService($this->cache);

            // Not opted out
            $this->cache->shouldReceive('get')
                ->with('zb_tracking_pref_user_42', false)
                ->andReturn(false);

            // Not client suppressed
            $this->cache->shouldReceive('get')
                ->with('zb_tracking_client_client_abc', false)
                ->andReturn(false);

            $filter = new TrackingPreferenceFilter($preferenceService);
            $event = new AnalyticsEvent(
                name: 'button_click',
                params: [],
                clientId: 'client_abc',
                userId: 'user_42',
            );

            $result = $filter($event);

            expect($result)->not->toBeNull();
            expect($result->name)->toBe('button_click');
        });

        test('drops events for opted-out users', function (): void {
            $preferenceService = new TrackingPreferenceService($this->cache);

            // User opted out
            $this->cache->shouldReceive('get')
                ->with('zb_tracking_pref_user_42', false)
                ->andReturn(true);

            $filter = new TrackingPreferenceFilter($preferenceService, checkClientSuppression: false);
            $event = new AnalyticsEvent(
                name: 'button_click',
                params: [],
                clientId: 'client_abc',
                userId: 'user_42',
            );

            $result = $filter($event);

            expect($result)->toBeNull();
        });

        test('drops events for suppressed clients', function (): void {
            $preferenceService = new TrackingPreferenceService($this->cache);

            // User not opted out
            $this->cache->shouldReceive('get')
                ->with('zb_tracking_pref_', false)
                ->andReturn(false);

            // Client suppressed
            $this->cache->shouldReceive('get')
                ->with('zb_tracking_client_anon_123', false)
                ->andReturn(true);

            $filter = new TrackingPreferenceFilter($preferenceService, checkClientSuppression: true);
            $event = new AnalyticsEvent(
                name: 'page_view',
                params: [],
                clientId: 'anon_123',
                userId: null,
            );

            $result = $filter($event);

            expect($result)->toBeNull();
        });

        test('can skip client suppression check', function (): void {
            $preferenceService = new TrackingPreferenceService($this->cache);

            // User not opted out (empty user ID)
            $this->cache->shouldReceive('get')
                ->with('zb_tracking_pref_', false)
                ->andReturn(false);

            $filter = new TrackingPreferenceFilter($preferenceService, checkClientSuppression: false);
            $event = new AnalyticsEvent(
                name: 'page_view',
                params: [],
                clientId: 'anon_123',
                userId: null,
            );

            // Should pass because client suppression check is disabled
            $result = $filter($event);

            expect($result)->not->toBeNull();
        });
    });

    describe('EventPipeline::withTrackingDefaults', function (): void {
        test('creates pipeline without optional filters', function (): void {
            $pipeline = EventPipeline::withTrackingDefaults();

            expect($pipeline)->toBeInstanceOf(EventPipeline::class);
            expect($pipeline->pipeCount())->toBe(4); // Consent, UTM, UserContext, Timestamp
        });

        test('creates pipeline with tracking preference filter', function (): void {
            $preferenceService = new TrackingPreferenceService($this->cache);
            $this->cache->shouldReceive('get')->andReturn(false);

            $pipeline = EventPipeline::withTrackingDefaults(
                preferenceService: $preferenceService,
            );

            expect($pipeline->pipeCount())->toBe(5); // +1 TrackingPreference
        });

        test('creates pipeline with both optional filters', function (): void {
            $preferenceService = new TrackingPreferenceService($this->cache);
            $dedupService = new EventDeduplicationService($this->config, $this->cache);
            $this->cache->shouldReceive('get')->andReturn(false);

            $pipeline = EventPipeline::withTrackingDefaults(
                preferenceService: $preferenceService,
                deduplicationService: $dedupService,
            );

            expect($pipeline->pipeCount())->toBe(6); // +1 TrackingPreference, +1 Dedup
        });

        test('processes event through full tracking pipeline', function (): void {
            $preferenceService = new TrackingPreferenceService($this->cache);
            $dedupService = new EventDeduplicationService($this->config, $this->cache);
            $this->cache->shouldReceive('get')
                ->andReturn(false); // not opted out, not suppressed
            $this->cache->shouldReceive('put')
                ->twice(); // dedup fingerprints

            $pipeline = EventPipeline::withTrackingDefaults(
                context: ['utm_source' => 'google'],
                preferenceService: $preferenceService,
                deduplicationService: $dedupService,
            );

            $event = new AnalyticsEvent(name: 'signup', params: ['plan' => 'pro']);
            $result = $pipeline->process($event);

            expect($result)->not->toBeNull();
            expect($result->name)->toBe('signup');
        });

        test('drops event when user is opted out in tracking pipeline', function (): void {
            $preferenceService = new TrackingPreferenceService($this->cache);
            $dedupService = new EventDeduplicationService($this->config, $this->cache);

            // User opted out
            $this->cache->shouldReceive('get')
                ->with('zb_tracking_pref_user_99', false)
                ->andReturn(true);

            $pipeline = EventPipeline::withTrackingDefaults(
                preferenceService: $preferenceService,
                deduplicationService: $dedupService,
            );

            $event = new AnalyticsEvent(
                name: 'button_click',
                params: [],
                clientId: 'client_1',
                userId: 'user_99',
            );
            $result = $pipeline->process($event);

            expect($result)->toBeNull();
        });
    });

    describe('TrackingPreferenceService integration', function (): void {
        test('shouldTrack returns false for opted-out users', function (): void {
            $service = new TrackingPreferenceService($this->cache);

            $this->cache->shouldReceive('get')
                ->with('zb_tracking_pref_user_1', false)
                ->andReturn(true);

            expect($service->shouldTrack('user_1', 'client_1'))->toBeFalse();
        });

        test('shouldTrack returns true for opted-in users', function (): void {
            $service = new TrackingPreferenceService($this->cache);

            $this->cache->shouldReceive('get')
                ->with('zb_tracking_pref_user_1', false)
                ->andReturn('opt_in');

            expect($service->shouldTrack('user_1', 'client_1'))->toBeTrue();
        });

        test('shouldTrack checks client suppression when user ID is null', function (): void {
            $service = new TrackingPreferenceService($this->cache);

            $this->cache->shouldReceive('get')
                ->with('zb_tracking_pref_', false)
                ->andReturn(false);

            $this->cache->shouldReceive('get')
                ->with('zb_tracking_client_anon_123', false)
                ->andReturn(true);

            expect($service->shouldTrack(null, 'anon_123'))->toBeFalse();
        });

        test('transferClientToUser carries suppression forward', function (): void {
            $service = new TrackingPreferenceService($this->cache);

            // Client is suppressed
            $this->cache->shouldReceive('get')
                ->with('zb_tracking_client_client_abc', false)
                ->andReturn(true);

            // Transfer: opt out user + clear client suppression
            $this->cache->shouldReceive('put')
                ->with('zb_tracking_pref_user_42', true, Mockery::any())
                ->once();
            $this->cache->shouldReceive('forget')
                ->with('zb_tracking_client_client_abc')
                ->once();

            $result = $service->transferClientToUser('client_abc', 'user_42');

            expect($result)->toBeTrue();
        });

        test('optOut stores preference in cache', function (): void {
            $service = new TrackingPreferenceService($this->cache, ttl: 3600);

            $this->cache->shouldReceive('put')
                ->with('zb_tracking_pref_user_1', true, 3600)
                ->once();

            $service->optOut('user_1');
        });

        test('optIn stores preference in cache', function (): void {
            $service = new TrackingPreferenceService($this->cache, ttl: 3600);

            $this->cache->shouldReceive('put')
                ->with('zb_tracking_pref_user_1', 'opt_in', 3600)
                ->once();

            $service->optIn('user_1');
        });

        test('clearPreference removes from cache', function (): void {
            $service = new TrackingPreferenceService($this->cache);

            $this->cache->shouldReceive('forget')
                ->with('zb_tracking_pref_user_1')
                ->once();

            $service->clearPreference('user_1');
        });
    });

    describe('Route file coverage', function (): void {
        test('routes file contains preference endpoints', function (): void {
            $content = file_get_contents(__DIR__ . '/../routes/analytics.php');

            expect($content)->toContain('preference');
            expect($content)->toContain('opt-out');
            expect($content)->toContain('opt-in');
            expect($content)->toContain('profile');
            expect($content)->toContain("'data'");
        });
    });

    describe('Pipeline filter file existence', function (): void {
        test('TrackingPreferenceFilter class exists', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Pipeline\TrackingPreferenceFilter::class))->toBeTrue();
        });

        test('EventDeduplicationFilter class exists', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Pipeline\EventDeduplicationFilter::class))->toBeTrue();
        });
    });

    describe('Version consistency', function (): void {
        test('composer.json version is 2.54.0', function (): void {
            $composer = json_decode(
                file_get_contents(__DIR__ . '/../composer.json'),
                true,
            );

            expect($composer['version'])->toBe('5.7.0');
        });
    });
});
