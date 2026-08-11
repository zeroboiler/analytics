<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventNormalizationService;
use ZeroBoiler\Analytics\Services\AnalyticsConsistencyService;

// ── V105 Event Normalization & Consistency ──────────────────────

describe('V105 Event Normalization & Consistency', function () {

    // ── EventNormalizationService ─────────────────────────────────

    describe('EventNormalizationService', function () {
        it('normalizes a catalog event to GA4 format', function (): void {
            $event = new AnalyticsEvent(
                name: 'purchase',
                params: ['value' => 99.99, 'currency' => 'USD'],
                clientId: 'client-123',
            );

            $config = new \Illuminate\Config\Repository([]);

            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new EventNormalizationService(
                $config,
                $cache,
                ['ga4' => true],
            );

            $result = $service->normalize($event);

            expect($result)->toHaveKey('ga4');
            expect($result['ga4']['name'])->toBe('purchase');
            expect($result['ga4']['params'])->toHaveKey('client_id');
            expect($result['ga4']['params']['client_id'])->toBe('client-123');
        });

        it('normalizes a SaaS event to PostHog format', function (): void {
            $event = new AnalyticsEvent(
                name: 'sign_up',
                params: ['method' => 'google'],
                clientId: 'client-456',
            );

            $config = new \Illuminate\Config\Repository([]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new EventNormalizationService(
                $config,
                $cache,
                ['posthog' => true],
            );

            $result = $service->normalize($event);

            expect($result)->toHaveKey('posthog');
            expect($result['posthog']['event'])->toBe('$signup');
            expect($result['posthog']['properties'])->toHaveKey('method');
            expect($result['posthog']['distinct_id'])->toBe('client-456');
        });

        it('normalizes an engagement event to Meta Pixel format', function (): void {
            $event = new AnalyticsEvent(
                name: 'search',
                params: ['search_term' => 'analytics'],
                clientId: 'client-789',
            );

            $config = new \Illuminate\Config\Repository([]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new EventNormalizationService(
                $config,
                $cache,
                ['meta' => true],
            );

            $result = $service->normalize($event);

            expect($result)->toHaveKey('meta');
            expect($result['meta']['event'])->toBe('Search');
            expect($result['meta']['eventData'])->toHaveKey('search_term');
        });

        it('returns empty array when no providers are enabled', function (): void {
            $event = new AnalyticsEvent(name: 'page_view');

            $config = new \Illuminate\Config\Repository([]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new EventNormalizationService(
                $config,
                $cache,
                [],
            );

            $result = $service->normalize($event);

            expect($result)->toBeEmpty();
        });

        it('normalizes a batch of events', function (): void {
            $events = [
                new AnalyticsEvent(name: 'page_view'),
                new AnalyticsEvent(name: 'sign_up', params: ['method' => 'email']),
            ];

            $config = new \Illuminate\Config\Repository([]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new EventNormalizationService(
                $config,
                $cache,
                ['ga4' => true],
            );

            $results = $service->normalizeBatch($events);

            expect($results)->toHaveCount(2);
            expect($results[0]['event']->name)->toBe('page_view');
            expect($results[1]['event']->name)->toBe('sign_up');
            expect($results[0]['normalized'])->toHaveKey('ga4');
            expect($results[1]['normalized'])->toHaveKey('ga4');
        });

        it('returns correct provider names from catalog', function (): void {
            $service = new EventNormalizationService(
                new \Illuminate\Config\Repository([]),
                mock(\Illuminate\Contracts\Cache\Repository::class),
                ['ga4' => true],
            );

            expect($service->providerNameFor('purchase', 'ga4'))->toBe('purchase');
            expect($service->providerNameFor('sign_up', 'ga4'))->toBe('sign_up');
            expect($service->providerNameFor('purchase', 'meta'))->toBe('Purchase');
            expect($service->providerNameFor('sign_up', 'posthog'))->toBe('$signup');
        });

        it('computes normalization stats for catalog events', function (): void {
            $service = new EventNormalizationService(
                new \Illuminate\Config\Repository([]),
                mock(\Illuminate\Contracts\Cache\Repository::class),
                ['ga4' => true, 'meta' => true, 'posthog' => true],
            );

            $stats = $service->normalizationStats('purchase');

            expect($stats['event'])->toBe('purchase');
            expect($stats['catalog_entry'])->toBeTrue();
            expect($stats['providers'])->toContain('ga4');
            expect($stats['providers'])->toContain('meta');
        });

        it('computes target providers for an event', function (): void {
            $service = new EventNormalizationService(
                new \Illuminate\Config\Repository([]),
                mock(\Illuminate\Contracts\Cache\Repository::class),
                ['ga4' => true, 'gtm' => true, 'meta' => true, 'posthog' => true],
            );

            $providers = $service->targetProvidersFor('sign_up');

            expect($providers)->toContain('ga4');
            expect($providers)->toContain('gtm');
            expect($providers)->toContain('meta');
            expect($providers)->toContain('posthog');
        });

        it('generates catalog coverage report', function (): void {
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturn(true);

            $service = new EventNormalizationService(
                new \Illuminate\Config\Repository([]),
                $cache,
                ['ga4' => true, 'meta' => true, 'posthog' => true],
            );

            $report = $service->catalogCoverageReport();

            expect($report)->toHaveKey('total');
            expect($report)->toHaveKey('fully_covered');
            expect($report)->toHaveKey('partial');
            expect($report)->toHaveKey('no_coverage');
            expect($report)->toHaveKey('gaps');
            expect($report['total'])->toBeGreaterThan(0);
        });

        it('normalizes to GTM with ecommerce enrichment for ecommerce events', function (): void {
            $event = new AnalyticsEvent(
                name: 'purchase',
                params: ['value' => 49.99, 'currency' => 'EUR'],
                clientId: 'client-gtm-1',
            );

            $config = new \Illuminate\Config\Repository([]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new EventNormalizationService(
                $config,
                $cache,
                ['gtm' => true],
            );

            $result = $service->normalize($event);

            expect($result)->toHaveKey('gtm');
            expect($result['gtm']['event'])->toBe('purchase');
            expect($result['gtm']['data'])->toHaveKey('client_id');
            expect($result['gtm'])->toHaveKey('ecommerce');
        });

        it('normalizes to Mixpanel with distinct_id from clientId', function (): void {
            $event = new AnalyticsEvent(
                name: 'page_view',
                clientId: 'mixpanel-client-1',
            );

            $config = new \Illuminate\Config\Repository([]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new EventNormalizationService(
                $config,
                $cache,
                ['mixpanel' => true],
            );

            $result = $service->normalize($event);

            expect($result)->toHaveKey('mixpanel');
            expect($result['mixpanel']['properties']['distinct_id'])->toBe('mixpanel-client-1');
        });

        it('normalizes to Amplitude with device_id and user_id', function (): void {
            $event = new AnalyticsEvent(
                name: 'login',
                clientId: 'amp-client-1',
                userId: 'user-42',
            );

            $config = new \Illuminate\Config\Repository([]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new EventNormalizationService(
                $config,
                $cache,
                ['amplitude' => true],
            );

            $result = $service->normalize($event);

            expect($result)->toHaveKey('amplitude');
            expect($result['amplitude']['event_type'])->toBe('login');
            expect($result['amplitude']['event_properties']['device_id'])->toBe('amp-client-1');
            expect($result['amplitude']['event_properties']['user_id'])->toBe('user-42');
        });

        it('normalizes to Plausible with domain from config', function (): void {
            $config = new \Illuminate\Config\Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'plausible' => ['domain' => 'example.com'],
                    ],
                ],
            ]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new EventNormalizationService(
                $config,
                $cache,
                ['plausible' => true],
            );

            $event = new AnalyticsEvent(name: 'page_view');
            $result = $service->normalize($event);

            expect($result)->toHaveKey('plausible');
            expect($result['plausible']['domain'])->toBe('example.com');
        });

        it('normalizes to Webhook with client_id', function (): void {
            $event = new AnalyticsEvent(
                name: 'custom_event',
                params: ['key' => 'value'],
                clientId: 'wh-client-1',
            );

            $config = new \Illuminate\Config\Repository([]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new EventNormalizationService(
                $config,
                $cache,
                ['webhook' => true],
            );

            $result = $service->normalize($event);

            expect($result)->toHaveKey('webhook');
            expect($result['webhook']['name'])->toBe('custom_event');
            expect($result['webhook']['client_id'])->toBe('wh-client-1');
            expect($result['webhook']['params'])->toHaveKey('key');
        });

        it('attaches userData for Meta CAPI when userId is present', function (): void {
            $event = new AnalyticsEvent(
                name: 'purchase',
                params: ['value' => 100],
                userId: 'user-meta-1',
            );

            $config = new \Illuminate\Config\Repository([]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new EventNormalizationService(
                $config,
                $cache,
                ['meta' => true],
            );

            $result = $service->normalize($event);

            expect($result['meta'])->toHaveKey('userData');
            expect($result['meta']['userData']['external_id'])->toBe('user-meta-1');
            expect($result['meta']['userData']['client_user_agent'])->toBe('');
        });

        it('falls back to original event name for unknown events', function (): void {
            $service = new EventNormalizationService(
                new \Illuminate\Config\Repository([]),
                mock(\Illuminate\Contracts\Cache\Repository::class),
                ['ga4' => true],
            );

            expect($service->providerNameFor('unknown_custom_event', 'ga4'))->toBe('unknown_custom_event');
            expect($service->providerNameFor('unknown_custom_event', 'meta'))->toBe('unknown_custom_event');
        });
    });

    // ── AnalyticsConsistencyService ──────────────────────────────

    describe('AnalyticsConsistencyService', function () {
        it('checks catalog integrity', function (): void {
            $manager = mock(AnalyticsManager::class);
            $config = new \Illuminate\Config\Repository([
                'zeroboiler' => [
                    'analytics' => [],
                ],
            ]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new AnalyticsConsistencyService($manager, $config, $cache);

            $result = $service->checkCatalogIntegrity();

            expect($result)->toHaveKey('status');
            expect($result)->toHaveKey('issues');
            expect($result)->toHaveKey('warnings');
            expect($result['status'])->toBe('pass');
        });

        it('checks provider config validity', function (): void {
            $manager = mock(AnalyticsManager::class);
            $manager->shouldReceive('ga4->isEnabled')->andReturn(true);
            $manager->shouldReceive('ga4->getMeasurementId')->andReturn('G-TEST123');
            $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
            $manager->shouldReceive('meta->isEnabled')->andReturn(true);
            $manager->shouldReceive('meta->getPixelId')->andReturn('123456789');
            $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
            $manager->shouldReceive('posthog->isEnabled')->andReturn(false);
            $manager->shouldReceive('mixpanel->isEnabled')->andReturn(false);
            $manager->shouldReceive('amplitude->isEnabled')->andReturn(false);

            $config = new \Illuminate\Config\Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'meta_pixel' => ['access_token' => 'abc123'],
                    ],
                ],
            ]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new AnalyticsConsistencyService($manager, $config, $cache);

            $result = $service->checkProviderConfig();

            expect($result['status'])->toBe('pass');
        });

        it('detects missing provider configuration', function (): void {
            $manager = mock(AnalyticsManager::class);
            $manager->shouldReceive('ga4->isEnabled')->andReturn(true);
            $manager->shouldReceive('ga4->getMeasurementId')->andReturn('');
            $manager->shouldReceive('gtm->isEnabled')->andReturn(true);
            $manager->shouldReceive('gtm->getContainerId')->andReturn('');
            $manager->shouldReceive('meta->isEnabled')->andReturn(true);
            $manager->shouldReceive('meta->getPixelId')->andReturn('');
            $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
            $manager->shouldReceive('posthog->isEnabled')->andReturn(false);
            $manager->shouldReceive('mixpanel->isEnabled')->andReturn(false);
            $manager->shouldReceive('amplitude->isEnabled')->andReturn(false);

            $config = new \Illuminate\Config\Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'meta_pixel' => ['access_token' => ''],
                    ],
                ],
            ]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new AnalyticsConsistencyService($manager, $config, $cache);

            $result = $service->checkProviderConfig();

            expect($result['status'])->toBe('fail');
            expect($result['issues'])->toBeNonEmpty();
        });

        it('checks naming convention compliance', function (): void {
            $manager = mock(AnalyticsManager::class);
            $config = new \Illuminate\Config\Repository([]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new AnalyticsConsistencyService($manager, $config, $cache);

            $result = $service->checkNamingConvention();

            expect($result)->toHaveKey('status');
            expect($result)->toHaveKey('issues');
            expect($result)->toHaveKey('warnings');
            // All catalog events should be snake_case
            expect($result['status'])->toBe('pass');
        });

        it('checks identity consistency configuration', function (): void {
            $manager = mock(AnalyticsManager::class);
            $config = new \Illuminate\Config\Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'identity' => [
                            'cookie_name' => 'zb_analytics_id',
                            'cookie_ttl' => 525600,
                            'link_on_auth' => true,
                            'cache_prefix' => 'zb_identity_',
                        ],
                    ],
                ],
            ]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new AnalyticsConsistencyService($manager, $config, $cache);

            $result = $service->checkIdentityConsistency();

            expect($result['status'])->toBe('pass');
            expect($result['issues'])->toBeEmpty();
        });

        it('warns when identity auto-linking is disabled', function (): void {
            $manager = mock(AnalyticsManager::class);
            $config = new \Illuminate\Config\Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'identity' => [
                            'cookie_name' => 'zb_analytics_id',
                            'cookie_ttl' => 525600,
                            'link_on_auth' => false,
                            'cache_prefix' => 'zb_identity_',
                        ],
                    ],
                ],
            ]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new AnalyticsConsistencyService($manager, $config, $cache);

            $result = $service->checkIdentityConsistency();

            expect($result['warnings'])->toBeNonEmpty();
            expect($result['warnings'][0])->toContain('auto-linking is disabled');
        });

        it('checks config validity for queue and consent settings', function (): void {
            $manager = mock(AnalyticsManager::class);
            $config = new \Illuminate\Config\Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'queue' => [
                            'enabled' => true,
                            'queue' => 'analytics',
                            'max_batch_size' => 50,
                        ],
                        'consent' => [
                            'default' => 'denied',
                        ],
                        'debug' => [
                            'enabled' => false,
                        ],
                        'sampling' => [
                            'enabled' => true,
                            'rate' => 0.5,
                        ],
                    ],
                ],
            ]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new AnalyticsConsistencyService($manager, $config, $cache);

            $result = $service->checkConfigValidity();

            expect($result['status'])->toBe('pass');
        });

        it('detects debug mode enabled as warning', function (): void {
            $manager = mock(AnalyticsManager::class);
            $config = new \Illuminate\Config\Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'debug' => ['enabled' => true],
                    ],
                ],
            ]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new AnalyticsConsistencyService($manager, $config, $cache);

            $result = $service->checkConfigValidity();

            expect($result['warnings'])->toBeNonEmpty();
            $debugWarning = false;
            foreach ($result['warnings'] as $w) {
                if (str_contains($w, 'Debug mode')) {
                    $debugWarning = true;
                }
            }
            expect($debugWarning)->toBeTrue();
        });

        it('detects invalid sampling rate', function (): void {
            $manager = mock(AnalyticsManager::class);
            $config = new \Illuminate\Config\Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'sampling' => [
                            'enabled' => true,
                            'rate' => 1.5, // invalid: > 1.0
                        ],
                    ],
                ],
            ]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);

            $service = new AnalyticsConsistencyService($manager, $config, $cache);

            $result = $service->checkConfigValidity();

            expect($result['status'])->toBe('fail');
            expect($result['issues'])->toBeNonEmpty();
        });

        it('returns a full check with score and grade', function (): void {
            $manager = mock(AnalyticsManager::class);
            $manager->shouldReceive('ga4->isEnabled')->andReturn(false);
            $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
            $manager->shouldReceive('meta->isEnabled')->andReturn(false);
            $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
            $manager->shouldReceive('posthog->isEnabled')->andReturn(false);
            $manager->shouldReceive('mixpanel->isEnabled')->andReturn(false);
            $manager->shouldReceive('amplitude->isEnabled')->andReturn(false);

            $config = new \Illuminate\Config\Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'identity' => [
                            'cookie_name' => 'zb_id',
                            'cookie_ttl' => 525600,
                            'link_on_auth' => true,
                            'cache_prefix' => 'zb_',
                        ],
                        'queue' => ['enabled' => true, 'queue' => 'analytics', 'max_batch_size' => 50],
                        'consent' => ['default' => 'denied'],
                        'debug' => ['enabled' => false],
                        'sampling' => ['enabled' => false, 'rate' => 1.0],
                    ],
                ],
            ]);

            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturn(true);

            $service = new AnalyticsConsistencyService($manager, $config, $cache);

            $result = $service->fullCheck();

            expect($result)->toHaveKey('score');
            expect($result)->toHaveKey('grade');
            expect($result)->toHaveKey('checks');
            expect($result['score'])->toBeGreaterThanOrEqual(0);
            expect($result['score'])->toBeLessThanOrEqual(100);
            expect($result['checks'])->toHaveKey('catalog_integrity');
            expect($result['checks'])->toHaveKey('provider_mapping');
            expect($result['checks'])->toHaveKey('identity_consistency');
            expect($result['checks'])->toHaveKey('config_validity');
            expect($result['checks'])->toHaveKey('naming_convention');
            expect($result['checks'])->toHaveKey('provider_config');
        });

        it('invalidates cache correctly', function (): void {
            $manager = mock(AnalyticsManager::class);
            $config = new \Illuminate\Config\Repository([]);
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('forget')->with('zb_consistency_full')->once();

            $service = new AnalyticsConsistencyService($manager, $config, $cache);

            $service->invalidateCache();
        });

        it('quick score returns an integer', function (): void {
            $manager = mock(AnalyticsManager::class);
            $manager->shouldReceive('ga4->isEnabled')->andReturn(false);
            $manager->shouldReceive('gtm->isEnabled')->andReturn(false);
            $manager->shouldReceive('meta->isEnabled')->andReturn(false);
            $manager->shouldReceive('plausible->isEnabled')->andReturn(false);
            $manager->shouldReceive('posthog->isEnabled')->andReturn(false);
            $manager->shouldReceive('mixpanel->isEnabled')->andReturn(false);
            $manager->shouldReceive('amplitude->isEnabled')->andReturn(false);

            $config = new \Illuminate\Config\Repository([
                'zeroboiler' => ['analytics' => [
                    'identity' => ['cookie_name' => 'zb', 'cookie_ttl' => 525600, 'link_on_auth' => true, 'cache_prefix' => 'zb_'],
                    'queue' => ['enabled' => true, 'queue' => 'a', 'max_batch_size' => 50],
                    'consent' => ['default' => 'denied'],
                    'debug' => ['enabled' => false],
                    'sampling' => ['enabled' => false, 'rate' => 1.0],
                ]],
            ]);

            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturn(true);

            $service = new AnalyticsConsistencyService($manager, $config, $cache);

            $score = $service->quickScore();

            expect($score)->toBeInt();
            expect($score)->toBeGreaterThanOrEqual(0);
            expect($score)->toBeLessThanOrEqual(100);
        });
    });
});
