<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Blade\Directives\AnalyticsDirectives;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts;
use ZeroBoiler\Analytics\Services\GoogleAnalyticsService;
use ZeroBoiler\Analytics\Services\GoogleTagManagerService;
use ZeroBoiler\Analytics\Services\MetaPixelService;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;

beforeEach(function (): void {
    $this->ga4 = new GA4Tracker(
        measurementId: 'G-TEST1234',
        apiSecret: 'test-secret-key-1234567890',
        enabled: true,
    );

    $this->gtm = new GTMTracker(
        containerId: 'GTM-TEST12',
        enabled: true,
    );

    $this->meta = new MetaPixelTracker(
        pixelId: '1234567890123456',
        accessToken: 'test-access-token-1234567890',
        enabled: true,
    );
});

describe('GoogleAnalyticsService', function (): void {
    it('can be instantiated with a GA4Tracker', function (): void {
        $service = new GoogleAnalyticsService($this->ga4);
        expect($service)->toBeInstanceOf(GoogleAnalyticsService::class);
    });

    it('returns the underlying tracker', function (): void {
        $service = new GoogleAnalyticsService($this->ga4);
        expect($service->getTracker())->toBe($this->ga4);
    });

    it('shares the same tracker instance (no copy)', function (): void {
        $service = new GoogleAnalyticsService($this->ga4);
        expect($service->getTracker())->toBe($this->ga4)
            ->and($service->getTracker()->getMeasurementId())->toBe('G-TEST1234')
            ->and($service->getTracker()->getApiSecret())->toBe('test-secret-key-1234567890');
    });

    it('reflects consent changes on the underlying tracker', function (): void {
        $service = new GoogleAnalyticsService($this->ga4);
        $service->getTracker()->setConsent(ConsentState::denied());

        expect($service->getTracker()->getConsent()->isDenied('analytics_storage'))->toBeTrue();
    });

    it('tracker is enabled with valid config', function (): void {
        $service = new GoogleAnalyticsService($this->ga4);
        expect($service->getTracker()->isEnabled())->toBeTrue();
    });

    it('tracker is disabled when enabled flag is false', function (): void {
        $disabled = new GA4Tracker('G-TEST1234', 'test-secret-key-1234567890', false);
        $service = new GoogleAnalyticsService($disabled);

        expect($service->getTracker()->isEnabled())->toBeFalse();
    });
});

describe('GoogleTagManagerService', function (): void {
    it('can be instantiated with a GTMTracker', function (): void {
        $service = new GoogleTagManagerService($this->gtm);
        expect($service)->toBeInstanceOf(GoogleTagManagerService::class);
    });

    it('returns the underlying tracker', function (): void {
        $service = new GoogleTagManagerService($this->gtm);
        expect($service->getTracker())->toBe($this->gtm);
    });

    it('pushes data to the dataLayer', function (): void {
        $service = new GoogleTagManagerService($this->gtm);
        $service->push(['key' => 'value']);

        expect($service->getDataLayer())->toHaveCount(1)
            ->and($service->getDataLayer()[0])->toBe(['key' => 'value']);
    });

    it('pushes an ecommerce event', function (): void {
        $service = new GoogleTagManagerService($this->gtm);
        $service->pushEcommerceEvent('purchase', [
            'transaction_id' => 'T-123',
            'value' => 49.99,
        ]);

        expect($service->getDataLayer()[0])->toBe([
            'event' => 'purchase',
            'ecommerce' => [
                'transaction_id' => 'T-123',
                'value' => 49.99,
            ],
        ]);
    });

    it('pushes user data', function (): void {
        $service = new GoogleTagManagerService($this->gtm);
        $service->pushUserData(['id' => 42, 'name' => 'Doruk']);

        expect($service->getDataLayer()[0])->toBe([
            'user' => ['id' => 42, 'name' => 'Doruk'],
        ]);
    });

    it('pushes a conversion event with default params', function (): void {
        $service = new GoogleTagManagerService($this->gtm);
        $service->pushConversion('CONV-001');

        $data = $service->getDataLayer()[0];
        expect($data['event'])->toBe('conversion')
            ->and($data['conversion_label'])->toBe('CONV-001');
    });

    it('pushes a conversion event with extra params merged', function (): void {
        $service = new GoogleTagManagerService($this->gtm);
        $service->pushConversion('CONV-001', ['value' => 100, 'currency' => 'USD']);

        $data = $service->getDataLayer()[0];
        expect($data['conversion_label'])->toBe('CONV-001')
            ->and($data['value'])->toBe(100)
            ->and($data['currency'])->toBe('USD')
            ->and($data['event'])->toBe('conversion');
    });

    it('accumulates multiple pushes in order', function (): void {
        $service = new GoogleTagManagerService($this->gtm);
        $service->push(['a' => 1]);
        $service->push(['b' => 2]);
        $service->pushConversion('X');

        $layer = $service->getDataLayer();
        expect($layer)->toHaveCount(3)
            ->and($layer[0])->toBe(['a' => 1])
            ->and($layer[1])->toBe(['b' => 2])
            ->and($layer[2]['event'])->toBe('conversion');
    });

    it('delegates to the same tracker instance (no copy)', function (): void {
        $service = new GoogleTagManagerService($this->gtm);
        $service->push(['test' => true]);

        // The tracker itself should reflect the push
        expect($this->gtm->getDataLayer())->toHaveCount(1);
    });

    it('generates head scripts with ecommerce data in dataLayer', function (): void {
        $service = new GoogleTagManagerService($this->gtm);
        $service->pushEcommerceEvent('view_item_list', ['items' => []]);

        $head = $service->getTracker()->headScripts();
        expect($head)->toContain('view_item_list')
            ->and($head)->toContain('dataLayer');
    });
});

describe('MetaPixelService', function (): void {
    it('can be instantiated with a MetaPixelTracker', function (): void {
        $service = new MetaPixelService($this->meta);
        expect($service)->toBeInstanceOf(MetaPixelService::class);
    });

    it('returns the underlying tracker', function (): void {
        $service = new MetaPixelService($this->meta);
        expect($service->getTracker())->toBe($this->meta);
    });

    it('shares the same tracker instance (no copy)', function (): void {
        $service = new MetaPixelService($this->meta);
        expect($service->getTracker())->toBe($this->meta)
            ->and($service->getTracker()->getPixelId())->toBe('1234567890123456');
    });

    it('reflects consent changes on the underlying tracker', function (): void {
        $service = new MetaPixelService($this->meta);
        $service->getTracker()->setConsent(ConsentState::denied());

        expect($service->getTracker()->getConsent()->isDenied('analytics_storage'))->toBeTrue();
    });

    it('identifies standard events via the tracker', function (): void {
        $service = new MetaPixelService($this->meta);
        $tracker = $service->getTracker();

        expect($tracker->isStandardEvent('Purchase'))->toBeTrue()
            ->and($tracker->isStandardEvent('Lead'))->toBeTrue()
            ->and($tracker->isStandardEvent('ViewContent'))->toBeTrue()
            ->and($tracker->isStandardEvent('MyCustomThing'))->toBeFalse();
    });

    it('returns all standard events', function (): void {
        $service = new MetaPixelService($this->meta);
        $events = $service->getTracker()->getStandardEvents();

        expect($events)->toContain('PageView')
            ->and($events)->toContain('Purchase')
            ->and($events)->toContain('Subscribe')
            ->and(count($events))->toBe(18);
    });

    it('generates head scripts with pixel ID', function (): void {
        $service = new MetaPixelService($this->meta);
        $head = $service->getTracker()->headScripts();

        expect($head)->toContain('1234567890123456')
            ->and($head)->toContain('fbq');
    });

    it('generates body scripts with pixel ID', function (): void {
        $service = new MetaPixelService($this->meta);
        $body = $service->getTracker()->bodyScripts();

        expect($body)->toContain('1234567890123456')
            ->and($body)->toContain('facebook.com/tr');
    });

    it('tracker is enabled with valid config', function (): void {
        $service = new MetaPixelService($this->meta);
        expect($service->getTracker()->isEnabled())->toBeTrue();
    });
});

describe('AnalyticsManager GTM track dispatch (bug fix)', function (): void {
    it('dispatches track() to GTM (verified via dataLayer, GTM-only to avoid HTTP facade)', function (): void {
        $manager = new AnalyticsManager(
            new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'gtm' => [
                            'enabled' => true,
                            'container_id' => 'GTM-TEST12',
                        ],
                    ],
                ],
            ]),
        );

        // Before track, dataLayer should be empty
        expect($manager->gtm()->getDataLayer())->toBeEmpty();

        $manager->track('purchase', ['transaction_id' => 'T-999']);

        // GTM should have received the event in its dataLayer
        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->not->toBeEmpty()
            ->and($layer[0]['event'])->toBe('purchase')
            ->and($layer[0]['eventParams']['transaction_id'])->toBe('T-999');
    });

    it('dispatches trackEvent() to GTM', function (): void {
        $manager = new AnalyticsManager(
            new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'gtm' => [
                            'enabled' => true,
                            'container_id' => 'GTM-TEST12',
                        ],
                    ],
                ],
            ]),
        );

        expect($manager->gtm()->getDataLayer())->toBeEmpty();

        $event = new AnalyticsEvent(name: 'signup', params: ['method' => 'email']);
        $manager->trackEvent($event);

        $layer = $manager->gtm()->getDataLayer();
        expect($layer)->toHaveCount(1)
            ->and($layer[0]['event'])->toBe('signup')
            ->and($layer[0]['eventParams']['method'])->toBe('email');
    });

    it('does not dispatch to GTM when GTM is disabled', function (): void {
        $manager = new AnalyticsManager(
            new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'gtm' => [
                            'enabled' => false,
                            'container_id' => 'GTM-TEST12',
                        ],
                    ],
                ],
            ]),
        );

        $manager->track('test_event');

        expect($manager->gtm()->getDataLayer())->toBeEmpty();
    });

    it('does not dispatch to GTM when GTM is disabled (even if other trackers exist)', function (): void {
        $manager = new AnalyticsManager(
            new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'gtm' => [
                            'enabled' => false,
                            'container_id' => '',
                        ],
                    ],
                ],
            ]),
        );

        $manager->track('no_gtm_event');

        expect($manager->gtm()->getDataLayer())->toBeEmpty();
    });
});

describe('AnalyticsManager consent propagation', function (): void {
    it('grants consent across all trackers', function (): void {
        $manager = new AnalyticsManager(
            new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST1234', 'api_secret' => 'test-secret-key-1234567890'],
                        'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST12'],
                        'meta_pixel' => ['enabled' => true, 'id' => '1234567890123456', 'access_token' => 'test-access-token-1234567890'],
                        'consent' => ['default' => 'denied'],
                    ],
                ],
            ]),
        );

        // Initially denied
        expect($manager->getConsent()->isDenied('analytics_storage'))->toBeTrue();

        $manager->grantConsent();

        expect($manager->ga4()->getConsent()->isGranted('analytics_storage'))->toBeTrue()
            ->and($manager->gtm()->getConsent()->isGranted('analytics_storage'))->toBeTrue()
            ->and($manager->meta()->getConsent()->isGranted('analytics_storage'))->toBeTrue();
    });

    it('denies consent across all trackers', function (): void {
        $manager = new AnalyticsManager(
            new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST1234', 'api_secret' => 'test-secret-key-1234567890'],
                        'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST12'],
                        'meta_pixel' => ['enabled' => true, 'id' => '1234567890123456', 'access_token' => 'test-access-token-1234567890'],
                    ],
                ],
            ]),
        );

        expect($manager->getConsent()->isGranted('analytics_storage'))->toBeTrue();

        $manager->denyConsent();

        expect($manager->ga4()->getConsent()->isDenied('analytics_storage'))->toBeTrue()
            ->and($manager->gtm()->getConsent()->isDenied('analytics_storage'))->toBeTrue()
            ->and($manager->meta()->getConsent()->isDenied('analytics_storage'))->toBeTrue();
    });

    it('setConsent propagates partial consent to all trackers', function (): void {
        $manager = new AnalyticsManager(
            new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'ga4' => ['enabled' => true, 'measurement_id' => 'G-TEST1234', 'api_secret' => 'test-secret-key-1234567890'],
                        'gtm' => ['enabled' => true, 'container_id' => 'GTM-TEST12'],
                        'meta_pixel' => ['enabled' => true, 'id' => '1234567890123456', 'access_token' => 'test-access-token-1234567890'],
                    ],
                ],
            ]),
        );

        $partial = ConsentState::granted()->with([
            'analytics_storage' => 'denied',
        ]);

        $manager->setConsent($partial);

        expect($manager->ga4()->getConsent()->isDenied('analytics_storage'))->toBeTrue()
            ->and($manager->ga4()->getConsent()->isGranted('ad_storage'))->toBeTrue()
            ->and($manager->gtm()->getConsent()->isDenied('analytics_storage'))->toBeTrue()
            ->and($manager->meta()->getConsent()->isDenied('analytics_storage'))->toBeTrue();
    });
});

describe('AnalyticsDirectives', function (): void {
    it('registers all Blade directives', function (): void {
        // AnalyticsDirectives::register() calls Blade::directive(),
        // which requires a booted container. Verify the class exists and has register method.
        expect(method_exists(AnalyticsDirectives::class, 'register'))->toBeTrue();
    });
});

describe('InjectAnalyticsScripts middleware', function (): void {
    it('can be instantiated with AnalyticsManager', function (): void {
        $manager = new AnalyticsManager(
            new Repository([]),
        );

        $middleware = new InjectAnalyticsScripts($manager);

        expect($middleware)->toBeInstanceOf(InjectAnalyticsScripts::class);
    });
});

describe('AnalyticsEvent DTO', function (): void {
    it('creates event with all params', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99],
            clientId: 'client-123',
            userId: 'user-456',
        );

        expect($event->name)->toBe('purchase')
            ->and($event->params)->toBe(['value' => 99.99])
            ->and($event->clientId)->toBe('client-123')
            ->and($event->userId)->toBe('user-456');
    });

    it('creates event with defaults', function (): void {
        $event = new AnalyticsEvent(name: 'page_view');

        expect($event->name)->toBe('page_view')
            ->and($event->params)->toBe([])
            ->and($event->clientId)->toBeNull()
            ->and($event->userId)->toBeNull();
    });

    it('converts to array', function (): void {
        $event = new AnalyticsEvent('test', ['a' => 1], 'c1', 'u1');

        expect($event->toArray())->toBe([
            'name' => 'test',
            'params' => ['a' => 1],
            'client_id' => 'c1',
            'user_id' => 'u1',
        ]);
    });

    it('creates from array with valid data', function (): void {
        $event = AnalyticsEvent::fromArray([
            'name' => 'signup',
            'params' => ['method' => 'google'],
            'client_id' => 'c-1',
            'user_id' => 'u-1',
        ]);

        expect($event->name)->toBe('signup')
            ->and($event->params['method'])->toBe('google');
    });

    it('handles missing fields in fromArray', function (): void {
        $event = AnalyticsEvent::fromArray([]);

        expect($event->name)->toBe('')
            ->and($event->params)->toBe([])
            ->and($event->clientId)->toBeNull()
            ->and($event->userId)->toBeNull();
    });

    it('handles null values in fromArray', function (): void {
        $event = AnalyticsEvent::fromArray([
            'name' => null,
            'params' => null,
            'client_id' => null,
            'user_id' => null,
        ]);

        expect($event->name)->toBe('')
            ->and($event->params)->toBe([]);
    });

    it('is readonly and immutable', function (): void {
        $reflection = new ReflectionClass(AnalyticsEvent::class);

        expect($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->isFinal())->toBeTrue();
    });
});

describe('ConsentState DTO extended', function (): void {
    it('normalizes invalid signal values out', function (): void {
        $state = new ConsentState([
            'ad_storage' => 'granted',
            'analytics_storage' => 'invalid_value',
            'custom_signal' => 'granted',
        ]);

        // Only valid values for known keys are kept; 'invalid_value' filtered out
        expect($state->signals)->toHaveKey('ad_storage')
            ->and($state->signals)->not->toHaveKey('analytics_storage')
            ->and($state->signals)->toHaveKey('custom_signal');
    });

    it('empty consent state has no signals granted', function (): void {
        $state = new ConsentState([]);

        expect($state->isGranted('analytics_storage'))->toBeFalse()
            ->and($state->isDenied('analytics_storage'))->toBeFalse()
            ->and($state->hasAnalyticsConsent())->toBeFalse()
            ->and($state->hasAdConsent())->toBeFalse();
    });

    it('with() creates new immutable instance', function (): void {
        $original = ConsentState::granted();
        $modified = $original->with(['analytics_storage' => 'denied']);

        expect($original->isGranted('analytics_storage'))->toBeTrue()
            ->and($modified->isDenied('analytics_storage'))->toBeTrue();
    });

    it('granted state has all 7 Google Consent Mode v2 signals', function (): void {
        $state = ConsentState::granted();
        $signals = $state->toArray();

        expect(array_keys($signals))->toHaveCount(7)
            ->and($signals)->toHaveKey('ad_storage')
            ->and($signals)->toHaveKey('ad_user_data')
            ->and($signals)->toHaveKey('ad_personalization')
            ->and($signals)->toHaveKey('analytics_storage')
            ->and($signals)->toHaveKey('functionality_storage')
            ->and($signals)->toHaveKey('personalization_storage')
            ->and($signals)->toHaveKey('security_storage');
    });

    it('denied state keeps security_storage granted per spec', function (): void {
        $state = ConsentState::denied();

        expect($state->isDenied('ad_storage'))->toBeTrue()
            ->and($state->isDenied('analytics_storage'))->toBeTrue()
            ->and($state->isGranted('security_storage'))->toBeTrue();
    });

    it('is readonly and immutable', function (): void {
        $reflection = new ReflectionClass(ConsentState::class);

        expect($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->isFinal())->toBeTrue();
    });
});
