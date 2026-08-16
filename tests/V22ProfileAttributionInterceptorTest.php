<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\EventInterceptorRegistry;
use ZeroBoiler\Analytics\Services\AnalyticsProfileService;
use ZeroBoiler\Analytics\Services\AttributionService;
use ZeroBoiler\Analytics\Services\GdprErasureService;
use ZeroBoiler\Analytics\Services\TrackingPreferenceService;
use ZeroBoiler\Analytics\DTO\UtmAttribution;

beforeEach(function (): void {
    $this->cache = new \Illuminate\Cache\ArrayStore;
    $this->cacheRepository = new \Illuminate\Cache\Repository($this->cache);

    $this->config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $this->config->shouldReceive('get')->with('zeroboiler.analytics.ga4', Mockery::any())->andReturn([]);
    $this->config->shouldReceive('get')->with('zeroboiler.analytics.gtm', Mockery::any())->andReturn([]);
    $this->config->shouldReceive('get')->with('zeroboiler.analytics.meta_pixel', Mockery::any())->andReturn([]);
    $this->config->shouldReceive('get')->with('zeroboiler.analytics.plausible', Mockery::any())->andReturn([]);
    $this->config->shouldReceive('get')->with('zeroboiler.analytics.posthog', Mockery::any())->andReturn([]);
    $this->config->shouldReceive('get')->with('zeroboiler.analytics.webhook', Mockery::any())->andReturn([]);
    $this->config->shouldReceive('get')->with('zeroboiler.analytics.consent.default', Mockery::any())->andReturn('granted');
    $this->config->shouldReceive('get')->with('zeroboiler.analytics.debug', Mockery::any())->andReturn(['enabled' => false, 'log_events' => false]);
});

afterEach(function (): void {
    Mockery::close();
});

// ─── Event Interceptor Registry Tests ──────────────────────────────────

describe('EventInterceptorRegistry', function (): void {
    test('before interceptor can modify event', function (): void {
        $registry = new EventInterceptorRegistry;
        $event = new AnalyticsEvent(name: 'test_event', params: ['foo' => 'bar']);

        $registry->before(function (AnalyticsEvent $e): AnalyticsEvent {
            return new AnalyticsEvent(name: $e->name, params: array_merge($e->params, ['modified' => true]));
        });

        $result = $registry->runBefore($event);

        expect($result)->not->toBeNull()
            ->and($result->params)->toHaveKey('modified')
            ->and($result->params['modified'])->toBeTrue();
    });

    test('before interceptor can cancel event by returning null', function (): void {
        $registry = new EventInterceptorRegistry;
        $event = new AnalyticsEvent(name: 'blocked_event', params: []);

        $registry->before(function (AnalyticsEvent $e): ?AnalyticsEvent {
            return $e->name === 'blocked_event' ? null : $e;
        });

        $result = $registry->runBefore($event);

        expect($result)->toBeNull();
    });

    test('multiple before interceptors run in order', function (): void {
        $registry = new EventInterceptorRegistry;
        $order = [];

        $registry->before(function (AnalyticsEvent $e) use (&$order): AnalyticsEvent {
            $order[] = 'first';

            return $e;
        });

        $registry->before(function (AnalyticsEvent $e) use (&$order): AnalyticsEvent {
            $order[] = 'second';

            return $e;
        });

        $event = new AnalyticsEvent(name: 'test', params: []);
        $registry->runBefore($event);

        expect($order)->toBe(['first', 'second']);
    });

    test('after interceptors receive success flag', function (): void {
        $registry = new EventInterceptorRegistry;
        $received = [];

        $registry->after(function (AnalyticsEvent $e, bool $success) use (&$received): void {
            $received[] = ['name' => $e->name, 'success' => $success];
        });

        $event = new AnalyticsEvent(name: 'test', params: []);
        $registry->runAfter($event, true);

        expect($received)->toHaveCount(1)
            ->and($received[0]['success'])->toBeTrue();
    });

    test('after interceptors do not break on exception', function (): void {
        $registry = new EventInterceptorRegistry;
        $secondCalled = false;

        $registry->after(function (): void {
            throw new \RuntimeException('oops');
        });

        $registry->after(function () use (&$secondCalled): void {
            $secondCalled = true;
        });

        $event = new AnalyticsEvent(name: 'test', params: []);
        $registry->runAfter($event, true);

        expect($secondCalled)->toBeTrue();
    });

    test('flush clears all interceptors', function (): void {
        $registry = new EventInterceptorRegistry;

        $registry->before(fn () => null);
        $registry->after(fn () => null);

        expect($registry->beforeCount())->toBe(1)
            ->and($registry->afterCount())->toBe(1);

        $registry->flush();

        expect($registry->beforeCount())->toBe(0)
            ->and($registry->afterCount())->toBe(0);
    });

    test('beforeCount and afterCount return correct counts', function (): void {
        $registry = new EventInterceptorRegistry;

        expect($registry->beforeCount())->toBe(0)
            ->and($registry->afterCount())->toBe(0);

        $registry->before(fn () => null);
        $registry->before(fn () => null);
        $registry->after(fn () => null);

        expect($registry->beforeCount())->toBe(2)
            ->and($registry->afterCount())->toBe(1);
    });
});

// ─── Analytics Profile Service Tests ────────────────────────────────────

describe('AnalyticsProfileService', function (): void {
    test('creates empty profile for unknown user', function (): void {
        $manager = new AnalyticsManager($this->config);
        $service = new AnalyticsProfileService($manager, $this->cacheRepository);

        $profile = $service->getProfile('user-123');

        expect($profile)->toBe([
            'event_counts' => [],
            'total_events' => 0,
            'total_value' => 0.0,
            'first_seen' => null,
            'last_seen' => null,
            'funnel_steps' => [],
            'engagement_score' => 0.0,
            'plan' => null,
            'traits' => [],
        ]);
    });

    test('records event and increments count', function (): void {
        $manager = new AnalyticsManager($this->config);
        $service = new AnalyticsProfileService($manager, $this->cacheRepository);

        $event = new AnalyticsEvent(name: 'page_view', params: [], userId: 'user-1');
        $service->recordEvent($event);

        $profile = $service->getProfile('user-1');

        expect($profile['total_events'])->toBe(1)
            ->and($profile['event_counts']['page_view'])->toBe(1)
            ->and($profile['first_seen'])->not->toBeNull()
            ->and($profile['last_seen'])->not->toBeNull();
    });

    test('accumulates revenue from value param', function (): void {
        $manager = new AnalyticsManager($this->config);
        $service = new AnalyticsProfileService($manager, $this->cacheRepository);

        $event1 = new AnalyticsEvent(name: 'purchase', params: ['value' => 50.00], userId: 'user-2');
        $event2 = new AnalyticsEvent(name: 'purchase', params: ['value' => 25.50], userId: 'user-2');

        $service->recordEvent($event1);
        $service->recordEvent($event2);

        expect($service->getLifetimeValue('user-2'))->toBe(75.5);
    });

    test('accumulates revenue from amount param', function (): void {
        $manager = new AnalyticsManager($this->config);
        $service = new AnalyticsProfileService($manager, $this->cacheRepository);

        $event = new AnalyticsEvent(name: 'revenue_tracked', params: ['amount' => 100.00], userId: 'user-3');
        $service->recordEvent($event);

        expect($service->getLifetimeValue('user-3'))->toBe(100.0);
    });

    test('merges traits from identify events', function (): void {
        $manager = new AnalyticsManager($this->config);
        $service = new AnalyticsProfileService($manager, $this->cacheRepository);

        $event = new AnalyticsEvent(name: 'identify', params: [
            'user_id' => 'user-4',
            'plan' => 'pro',
            'company' => 'Acme',
        ], userId: 'user-4');

        $service->recordEvent($event);

        $traits = $service->getTraits('user-4');

        expect($traits)->toHaveKey('plan')
            ->and($traits['plan'])->toBe('pro')
            ->and($traits)->not->toHaveKey('user_id');
    });

    test('tracks funnel steps', function (): void {
        $manager = new AnalyticsManager($this->config);
        $service = new AnalyticsProfileService($manager, $this->cacheRepository);

        $event = new AnalyticsEvent(name: 'funnel_step', params: [
            'funnel' => 'signup',
            'funnel_step' => 'landing_page',
        ], userId: 'user-5');

        $service->recordEvent($event);

        expect($service->hasCompletedFunnelStep('user-5', 'signup', 'landing_page'))->toBeTrue()
            ->and($service->hasCompletedFunnelStep('user-5', 'signup', 'pricing'))->toBeFalse();
    });

    test('updates plan from subscription events', function (): void {
        $manager = new AnalyticsManager($this->config);
        $service = new AnalyticsProfileService($manager, $this->cacheRepository);

        $event = new AnalyticsEvent(name: 'subscription', params: [
            'plan_name' => 'enterprise',
        ], userId: 'user-6');

        $service->recordEvent($event);

        expect($service->getCurrentPlan('user-6'))->toBe('enterprise');
    });

    test('calculates engagement score', function (): void {
        $manager = new AnalyticsManager($this->config);
        $service = new AnalyticsProfileService($manager, $this->cacheRepository);

        // Record multiple events of different types
        for ($i = 0; $i < 5; $i++) {
            $event = new AnalyticsEvent(name: "event_type_{$i}", params: [], userId: 'user-7');
            $service->recordEvent($event);
        }

        $score = $service->getEngagementScore('user-7');

        expect($score)->toBeGreaterThan(0.0)
            ->and($score)->toBeLessThanOrEqual(100.0);
    });

    test('ignores events without user ID', function (): void {
        $manager = new AnalyticsManager($this->config);
        $service = new AnalyticsProfileService($manager, $this->cacheRepository);

        $event = new AnalyticsEvent(name: 'page_view', params: []);
        $service->recordEvent($event);

        expect($service->getTotalEvents(''))->toBe(0);
    });

    test('getProfileSummary returns correct structure', function (): void {
        $manager = new AnalyticsManager($this->config);
        $service = new AnalyticsProfileService($manager, $this->cacheRepository);

        $summary = $service->getProfileSummary('user-8');

        expect($summary)->toHaveKeys([
            'user_id', 'total_events', 'lifetime_value', 'first_seen',
            'last_seen', 'engagement_score', 'plan', 'event_types',
            'funnel_steps_completed', 'traits',
        ])
            ->and($summary['user_id'])->toBe('user-8');
    });

    test('deleteProfile removes profile data', function (): void {
        $manager = new AnalyticsManager($this->config);
        $service = new AnalyticsProfileService($manager, $this->cacheRepository);

        $event = new AnalyticsEvent(name: 'test', params: [], userId: 'user-9');
        $service->recordEvent($event);

        expect($service->getTotalEvents('user-9'))->toBe(1);

        $service->deleteProfile('user-9');

        expect($service->getTotalEvents('user-9'))->toBe(0);
    });

    test('getCompletedFunnelSteps returns structured list', function (): void {
        $manager = new AnalyticsManager($this->config);
        $service = new AnalyticsProfileService($manager, $this->cacheRepository);

        $event1 = new AnalyticsEvent(name: 'step', params: [
            'funnel' => 'trial', 'funnel_step' => 'started',
        ], userId: 'user-10');
        $event2 = new AnalyticsEvent(name: 'step', params: [
            'funnel' => 'trial', 'funnel_step' => 'converted',
        ], userId: 'user-10');

        $service->recordEvent($event1);
        $service->recordEvent($event2);

        $steps = $service->getCompletedFunnelSteps('user-10');

        expect($steps)->toHaveCount(2)
            ->and($steps[0])->toHaveKeys(['funnel', 'step']);
    });

    test('engagement score increases with more events', function (): void {
        $manager = new AnalyticsManager($this->config);
        $service = new AnalyticsProfileService($manager, $this->cacheRepository);

        $event = new AnalyticsEvent(name: 'page_view', params: [], userId: 'user-11');
        $service->recordEvent($event);
        $score1 = $service->getEngagementScore('user-11');

        // Add more diverse events
        for ($i = 0; $i < 10; $i++) {
            $event = new AnalyticsEvent(name: "event_{$i}", params: [], userId: 'user-11');
            $service->recordEvent($event);
        }
        $score2 = $service->getEngagementScore('user-11');

        expect($score2)->toBeGreaterThan($score1);
    });

    test('getTotalEvents and getFirstSeen/getLastSeen work correctly', function (): void {
        $manager = new AnalyticsManager($this->config);
        $service = new AnalyticsProfileService($manager, $this->cacheRepository);

        expect($service->getTotalEvents('user-12'))->toBe(0)
            ->and($service->getFirstSeen('user-12'))->toBeNull()
            ->and($service->getLastSeen('user-12'))->toBeNull();
    });
});

// ─── Attribution Service Tests ──────────────────────────────────────────

describe('AttributionService', function (): void {
    beforeEach(function (): void {
        $this->attributionConfig = $this->config;
        $this->attributionConfig->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution', Mockery::any())
            ->andReturn([
                'first_touch_ttl' => 2592000,
                'touch_history_ttl' => 2592000,
                'max_touch_history' => 20,
            ]);

        $this->service = new AttributionService($this->cacheRepository, $this->attributionConfig);
    });

    test('stores first-touch attribution for new client', function (): void {
        $this->service->recordTouchpoint('client-1', [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring_sale',
        ], 'https://google.com', '/pricing');

        $firstTouch = $this->service->getFirstTouch('client-1');

        expect($firstTouch)->not->toBeNull()
            ->and($firstTouch['utm_source'])->toBe('google')
            ->and($firstTouch['utm_medium'])->toBe('cpc')
            ->and($firstTouch['utm_campaign'])->toBe('spring_sale')
            ->and($firstTouch['utm_first_touch'])->toBeTrue()
            ->and($firstTouch['utm_referrer'])->toBe('https://google.com')
            ->and($firstTouch['utm_landing_page'])->toBe('/pricing');
    });

    test('preserves first-touch on subsequent visits', function (): void {
        $this->service->recordTouchpoint('client-2', [
            'utm_source' => 'google',
            'utm_medium' => 'organic',
        ]);

        $this->service->recordTouchpoint('client-2', [
            'utm_source' => 'newsletter',
            'utm_medium' => 'email',
            'utm_campaign' => 'weekly',
        ]);

        $firstTouch = $this->service->getFirstTouch('client-2');

        expect($firstTouch['utm_source'])->toBe('google');
    });

    test('builds touch history with multiple visits', function (): void {
        $this->service->recordTouchpoint('client-3', [
            'utm_source' => 'twitter',
            'utm_medium' => 'social',
        ]);

        $this->service->recordTouchpoint('client-3', [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ]);

        $this->service->recordTouchpoint('client-3', [
            'utm_source' => 'direct',
            'utm_medium' => 'none',
        ]);

        $history = $this->service->getTouchHistory('client-3');

        expect($history)->toHaveCount(3);
    });

    test('getAttributionSummary returns complete data', function (): void {
        $this->service->recordTouchpoint('client-4', [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'brand',
        ]);

        $this->service->recordTouchpoint('client-4', [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'competitor',
        ]);

        $summary = $this->service->getAttributionSummary('client-4');

        expect($summary)->toHaveKeys([
            'first_touch', 'last_touch', 'total_touches',
            'sources', 'mediums', 'campaigns', 'journey',
        ])
            ->and($summary['total_touches'])->toBe(2)
            ->and($summary['sources']['google'])->toBe(2)
            ->and($summary['campaigns'])->toHaveCount(2);
    });

    test('getLastTouch returns most recent', function (): void {
        $this->service->recordTouchpoint('client-5', [
            'utm_source' => 'first',
            'utm_medium' => 'email',
        ]);

        $this->service->recordTouchpoint('client-5', [
            'utm_source' => 'last',
            'utm_medium' => 'social',
        ]);

        $last = $this->service->getLastTouch('client-5');

        expect($last['utm_source'])->toBe('last');
    });

    test('ignores requests without UTM params', function (): void {
        $this->service->recordTouchpoint('client-6', [], null, '/home');

        expect($this->service->getFirstTouch('client-6'))->toBeNull()
            ->and($this->service->getTouchHistory('client-6'))->toBeEmpty();
    });

    test('ignores empty client ID', function (): void {
        $this->service->recordTouchpoint('', [
            'utm_source' => 'google',
        ]);

        expect($this->service->getFirstTouch(''))->toBeNull();
    });

    test('deleteAttribution removes all data for client', function (): void {
        $this->service->recordTouchpoint('client-7', [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ]);

        expect($this->service->getFirstTouch('client-7'))->not->toBeNull();

        $this->service->deleteAttribution('client-7');

        expect($this->service->getFirstTouch('client-7'))->toBeNull()
            ->and($this->service->getTouchHistory('client-7'))->toBeEmpty();
    });

    test('respects max touch history limit', function (): void {
        // Create a service with max_history = 3
        $limitedConfig = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
        $limitedConfig->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution', Mockery::any())
            ->andReturn([
                'first_touch_ttl' => 2592000,
                'touch_history_ttl' => 2592000,
                'max_touch_history' => 3,
            ]);

        $service = new AttributionService($this->cacheRepository, $limitedConfig);

        for ($i = 0; $i < 5; $i++) {
            $service->recordTouchpoint('client-limited', [
                'utm_source' => "source_{$i}",
                'utm_medium' => 'email',
            ]);
        }

        $history = $service->getTouchHistory('client-limited');

        expect($history)->toHaveCount(3);
    });

    test('getFirstTouch returns null for unknown client', function (): void {
        expect($this->service->getFirstTouch('unknown'))->toBeNull();
    });

    test('getTouchHistory returns empty array for unknown client', function (): void {
        expect($this->service->getTouchHistory('unknown'))->toBe([]);
    });

    test('getLastTouch returns null for unknown client', function (): void {
        expect($this->service->getLastTouch('unknown'))->toBeNull();
    });
});

// ─── GDPR Erasure Service Tests ─────────────────────────────────────────

describe('GdprErasureService', function (): void {
    beforeEach(function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.attribution', Mockery::any())
            ->andReturn([]);

        $this->manager = new AnalyticsManager($this->config);
        $this->profileService = new AnalyticsProfileService($this->manager, $this->cacheRepository);
        $this->attributionService = new AttributionService($this->cacheRepository, $this->config);
        $this->preferenceService = new TrackingPreferenceService($this->cacheRepository);

        $this->erasureService = new GdprErasureService(
            $this->profileService,
            $this->attributionService,
            $this->preferenceService,
        );
    });

    test('erases user profile', function (): void {
        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99], userId: 'user-gdpr-1');
        $this->profileService->recordEvent($event);

        expect($this->profileService->getTotalEvents('user-gdpr-1'))->toBe(1);

        $result = $this->erasureService->eraseUser('user-gdpr-1');

        expect($result['profile_deleted'])->toBeTrue()
            ->and($this->profileService->getTotalEvents('user-gdpr-1'))->toBe(0);
    });

    test('erases attribution data', function (): void {
        $this->attributionService->recordTouchpoint('client-gdpr', [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ]);

        expect($this->attributionService->getFirstTouch('client-gdpr'))->not->toBeNull();

        $result = $this->erasureService->eraseUser('user-gdpr-2', 'client-gdpr');

        expect($result['attribution_deleted'])->toBeTrue()
            ->and($this->attributionService->getFirstTouch('client-gdpr'))->toBeNull();
    });

    test('erases tracking preferences', function (): void {
        $result = $this->erasureService->eraseUser('user-gdpr-3');

        expect($result['preferences_deleted'])->toBeTrue();
    });

    test('returns correct structure', function (): void {
        $result = $this->erasureService->eraseUser('user-gdpr-4');

        expect($result)->toHaveKeys(['profile_deleted', 'attribution_deleted', 'preferences_deleted']);
    });

    test('eraseAttribution works independently', function (): void {
        $this->attributionService->recordTouchpoint('client-ind', [
            'utm_source' => 'twitter',
        ]);

        expect($this->erasureService->eraseAttribution('client-ind'))->toBeTrue()
            ->and($this->attributionService->getFirstTouch('client-ind'))->toBeNull();
    });

    test('eraseAttribution returns false for empty client ID', function (): void {
        expect($this->erasureService->eraseAttribution(''))->toBeFalse();
    });
});

// ─── AnalyticsManager Interceptor Integration Tests ─────────────────────

describe('AnalyticsManager Interceptor Integration', function (): void {
    test('version returns 2.22.0', function (): void {
        $manager = new AnalyticsManager($this->config);

        expect($manager->version())->toBe('76.0.0');
    });

    test('directDispatch returns bool', function (): void {
        $manager = new AnalyticsManager($this->config);
        $event = new AnalyticsEvent(name: 'test', params: []);

        // With no providers enabled, should return false
        $result = $manager->directDispatch($event);

        expect($result)->toBeBool();
    });

    test('interceptors registry is accessible', function (): void {
        $manager = new AnalyticsManager($this->config);
        $registry = $manager->interceptors();

        expect($registry)->toBeInstanceOf(EventInterceptorRegistry::class)
            ->and($registry->beforeCount())->toBe(0)
            ->and($registry->afterCount())->toBe(0);
    });

    test('interceptBefore registers interceptor', function (): void {
        $manager = new AnalyticsManager($this->config);

        $manager->interceptBefore(function (AnalyticsEvent $e): AnalyticsEvent {
            return $e;
        });

        expect($manager->interceptors()->beforeCount())->toBe(1);
    });

    test('interceptAfter registers interceptor', function (): void {
        $manager = new AnalyticsManager($this->config);

        $manager->interceptAfter(function (AnalyticsEvent $e, bool $ok): void {
            // no-op
        });

        expect($manager->interceptors()->afterCount())->toBe(1);
    });

    test('getProfile returns default structure when service unavailable', function (): void {
        $manager = new AnalyticsManager($this->config);

        $profile = $manager->getProfile('test-user');

        expect($profile)->toHaveKey('event_counts')
            ->and($profile['total_events'])->toBe(0);
    });

    test('getProfileSummary returns default structure when service unavailable', function (): void {
        $manager = new AnalyticsManager($this->config);

        $summary = $manager->getProfileSummary('test-user');

        expect($summary)->toHaveKey('user_id')
            ->and($summary['user_id'])->toBe('test-user')
            ->and($summary['total_events'])->toBe(0);
    });
});

// ─── UtmAttribution Integration Tests ───────────────────────────────────

describe('UtmAttribution', function (): void {
    test('fromRequest captures all UTM params', function (): void {
        $utm = UtmAttribution::fromRequest([
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'summer',
            'utm_term' => 'analytics tool',
            'utm_content' => 'banner_v2',
        ], false, 'https://ref.com', '/landing');

        expect($utm->source)->toBe('google')
            ->and($utm->medium)->toBe('cpc')
            ->and($utm->campaign)->toBe('summer')
            ->and($utm->term)->toBe('analytics tool')
            ->and($utm->content)->toBe('banner_v2')
            ->and($utm->referrer)->toBe('https://ref.com')
            ->and($utm->landingPage)->toBe('/landing');
    });

    test('hasAttribution returns false for empty UTM', function (): void {
        $utm = new UtmAttribution;

        expect($utm->hasAttribution())->toBeFalse();
    });

    test('describe returns readable string', function (): void {
        $utm = new UtmAttribution(source: 'google', medium: 'cpc', campaign: 'sale');

        expect($utm->describe())->toBe('google/cpc (sale)');
    });

    test('toString and fromString roundtrip', function (): void {
        $original = UtmAttribution::fromRequest([
            'utm_source' => 'twitter',
            'utm_medium' => 'social',
        ], true);

        $restored = UtmAttribution::fromString($original->toString());

        expect($restored->source)->toBe('twitter')
            ->and($restored->medium)->toBe('social')
            ->and($restored->firstTouch)->toBeTrue();
    });

    test('fromString handles invalid JSON gracefully', function (): void {
        $restored = UtmAttribution::fromString('invalid-json');

        expect($restored->hasAttribution())->toBeFalse();
    });
});

// ─── Config Expansion Tests ─────────────────────────────────────────────

describe('Config Expansion v2.22', function (): void {
    test('config file contains attribution section', function (): void {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $analytics = $config['analytics'] ?? [];

        expect($analytics)->toHaveKey('attribution')
            ->and($analytics['attribution'])->toHaveKeys([
                'enabled', 'first_touch_ttl', 'touch_history_ttl', 'max_touch_history',
            ]);
    });

    test('config file contains profile section', function (): void {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $analytics = $config['analytics'] ?? [];

        expect($analytics)->toHaveKey('profile')
            ->and($analytics['profile'])->toHaveKeys(['enabled', 'ttl']);
    });

    test('attribution config has sensible defaults', function (): void {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $attr = $config['analytics']['attribution'];

        expect($attr['enabled'])->toBeTrue()
            ->and($attr['first_touch_ttl'])->toBe(2592000)
            ->and($attr['max_touch_history'])->toBe(20);
    });

    test('profile config has sensible defaults', function (): void {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        $profile = $config['analytics']['profile'];

        expect($profile['enabled'])->toBeTrue()
            ->and($profile['ttl'])->toBe(86400);
    });
});

// ─── Service Provider Registration Tests ───────────────────────────────

describe('Service Provider Registrations', function (): void {
    test('AnalyticsProfileService is referenced in provider', function (): void {
        $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

        expect($content)->toContain('AnalyticsProfileService::class')
            ->and($content)->toContain('AttributionService::class')
            ->and($content)->toContain('GdprErasureService::class');
    });

    test('new routes are registered', function (): void {
        $content = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');

        expect($content)->toContain("'analytics/profile'")
            ->and($content)->toContain("'analytics/data'")
            ->and($content)->toContain("'eraseData'");
    });
});
