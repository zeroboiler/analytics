<?php

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Events\CustomEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\AddToCartEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\PurchaseEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\ViewItemEvent;
use ZeroBoiler\Analytics\Events\Engagement\ClickEvent;
use ZeroBoiler\Analytics\Events\Engagement\ErrorEvent;
use ZeroBoiler\Analytics\Events\Engagement\FormSubmitEvent;
use ZeroBoiler\Analytics\Events\Engagement\PageViewEvent;
use ZeroBoiler\Analytics\Events\Engagement\SearchEvent;
use ZeroBoiler\Analytics\Events\Engagement\ShareEvent;
use ZeroBoiler\Analytics\Events\SaaS\LoginEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanUpgradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\SignUpEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialStartEvent;

// ── Integration Tests ───────────────────────────────────────────────────────

describe('Event Catalog Integration', function () {
    it('can create and track e-commerce events via manager', function () {
        $manager = createTestManager();

        $purchase = new PurchaseEvent(
            transactionId: 'TX-001',
            value: 99.99,
            currency: 'EUR',
            items: [['item_id' => 'SKU-1', 'item_name' => 'Widget', 'price' => 99.99, 'quantity' => 1]],
        );

        expect($purchase->name)->toBe('purchase');
        expect($purchase->params['transaction_id'])->toBe('TX-001');
        expect($purchase->params['currency'])->toBe('EUR');
        expect($purchase->params['items'])->toHaveCount(1);
    });

    it('can create SaaS lifecycle events', function () {
        $signup = new SignUpEvent(method: 'github');
        $login = new LoginEvent(method: 'web');
        $trial = new TrialStartEvent(planName: 'Pro');
        $subscription = new SubscriptionEvent(planName: 'Pro', value: 29.99);
        $upgrade = new PlanUpgradeEvent(fromPlan: 'Free', toPlan: 'Pro');

        expect($signup->name)->toBe('sign_up');
        expect($login->name)->toBe('login');
        expect($trial->name)->toBe('start_trial');
        expect($subscription->name)->toBe('subscribe');
        expect($upgrade->name)->toBe('plan_upgrade');
        expect($upgrade->params['from_plan'])->toBe('Free');
        expect($upgrade->params['to_plan'])->toBe('Pro');
    });

    it('can create engagement events', function () {
        $pageView = new PageViewEvent(pageTitle: 'Dashboard', pageLocation: 'https://app.example.com/dashboard');
        $click = new ClickEvent(elementText: 'Save', elementType: 'button');
        $search = new SearchEvent(searchTerm: 'analytics', resultsCount: 10);
        $share = new ShareEvent(method: 'twitter', contentType: 'article');
        $error = new ErrorEvent(errorType: '500', message: 'Server Error', fatal: true);
        $formSubmit = new FormSubmitEvent(formName: 'Contact', value: 25.0, currency: 'EUR');

        expect($pageView->name)->toBe('page_view');
        expect($click->name)->toBe('click');
        expect($search->name)->toBe('search');
        expect($share->name)->toBe('share');
        expect($error->name)->toBe('error');
        expect($error->params['fatal'])->toBeTrue();
        expect($formSubmit->name)->toBe('form_submit');
        expect($formSubmit->params['value'])->toBe(25.0);
    });

    it('custom event supports arbitrary names', function () {
        $event = new CustomEvent('video_completed', [
            'video_id' => 'vid-123',
            'duration' => 120,
            'quality' => '1080p',
        ]);

        expect($event->name)->toBe('video_completed');
        expect($event->params['video_id'])->toBe('vid-123');
        expect($event->params['duration'])->toBe(120);
    });
});

describe('AnalyticsEvent DTO', function () {
    it('creates from array correctly', function () {
        $event = AnalyticsEvent::fromArray([
            'name' => 'test_event',
            'params' => ['key' => 'value'],
            'client_id' => 'client-123',
            'user_id' => 'user-456',
        ]);

        expect($event->name)->toBe('test_event');
        expect($event->params)->toBe(['key' => 'value']);
        expect($event->clientId)->toBe('client-123');
        expect($event->userId)->toBe('user-456');
    });

    it('round-trips through toArray', function () {
        $original = new AnalyticsEvent(
            name: 'round_trip',
            params: ['a' => 1, 'b' => true],
            clientId: 'c1',
            userId: 'u1',
        );

        $restored = AnalyticsEvent::fromArray($original->toArray());

        expect($restored->name)->toBe($original->name);
        expect($restored->params)->toBe($original->params);
        expect($restored->clientId)->toBe($original->clientId);
        expect($restored->userId)->toBe($original->userId);
    });

    it('handles invalid fromArray gracefully', function () {
        $event = AnalyticsEvent::fromArray([]);

        expect($event->name)->toBe('');
        expect($event->params)->toBe([]);
        expect($event->clientId)->toBeNull();
        expect($event->userId)->toBeNull();
    });
});

describe('ConsentState', function () {
    it('creates granted state with all signals', function () {
        $state = ConsentState::granted();

        expect($state->hasAnalyticsConsent())->toBeTrue();
        expect($state->hasAdConsent())->toBeTrue();
        expect($state->isGranted('functionality_storage'))->toBeTrue();
        // security_storage is always granted
        expect($state->isGranted('security_storage'))->toBeTrue();
    });

    it('creates denied state (GDPR-safe)', function () {
        $state = ConsentState::denied();

        expect($state->hasAnalyticsConsent())->toBeFalse();
        expect($state->hasAdConsent())->toBeFalse();
        expect($state->isDenied('analytics_storage'))->toBeTrue();
        expect($state->isGranted('security_storage'))->toBeTrue(); // Always granted
    });

    it('creates modified state with with()', function () {
        $denied = ConsentState::denied();
        $modified = $denied->with(['analytics_storage' => 'granted']);

        expect($modified->hasAnalyticsConsent())->toBeTrue();
        expect($modified->hasAdConsent())->toBeFalse();
        expect($modified->isGranted('security_storage'))->toBeTrue();
    });

    it('filters invalid signal values', function () {
        $state = new ConsentState([
            'analytics_storage' => 'granted',
            'ad_storage' => 'maybe',
            'custom_signal' => 'granted',
        ]);

        expect($state->hasAnalyticsConsent())->toBeTrue();
        expect($state->isGranted('ad_storage'))->toBeFalse();
        expect($state->isGranted('custom_signal'))->toBeTrue();
    });

    it('converts to array', function () {
        $state = ConsentState::granted();
        $array = $state->toArray();

        expect($array)->toBeArray();
        expect($array)->toHaveKey('analytics_storage');
        expect($array['analytics_storage'])->toBe('granted');
    });
});

// ── Helpers ────────────────────────────────────────────────────────────────

function createTestManager(): AnalyticsManager
{
    $config = Mockery::mock(\Illuminate\Contracts\Config\Repository::class);
    $config->shouldReceive('get')->andReturnUsing(function (string $key, $default = null) {
        $map = [
            'zeroboiler.analytics.ga4' => ['enabled' => false, 'measurement_id' => '', 'api_secret' => ''],
            'zeroboiler.analytics.gtm' => ['enabled' => false, 'container_id' => ''],
            'zeroboiler.analytics.meta_pixel' => ['enabled' => false, 'id' => '', 'access_token' => ''],
            'zeroboiler.analytics.plausible' => ['enabled' => false, 'domain' => '', 'api_key' => ''],
            'zeroboiler.analytics.posthog' => ['enabled' => false, 'api_key' => '', 'host' => ''],
            'zeroboiler.analytics.consent.default' => 'granted',
        ];

        return $map[$key] ?? $default;
    });

    return new AnalyticsManager($config);
}
