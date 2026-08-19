<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Support\EngagementFormatConverter;
use ZeroBoiler\Analytics\Support\SaaSFormatConverter;

test('SaaS Events shorthand factory methods build correct events', function (): void {
    $event = SaaSEvents::signUp(['method' => 'email', 'plan' => 'pro']);
    expect($event->name)->toBe('sign_up');
    expect($event->category)->toBe('saas');
    expect($event->params['method'])->toBe('email');
    expect($event->params['plan'])->toBe('pro');

    $event = SaaSEvents::login(['method' => 'google']);
    expect($event->name)->toBe('login');
    expect($event->category)->toBe('saas');

    $event = SaaSEvents::logout();
    expect($event->name)->toBe('logout');

    $event = SaaSEvents::trialStart(['plan_name' => 'Business', 'trial_days' => 14]);
    expect($event->name)->toBe('start_trial');
    expect($event->params['plan_name'])->toBe('Business');
    expect($event->params['trial_days'])->toBe(14);

    // trialStart is alias for startTrial
    $event2 = SaaSEvents::startTrial(['plan_name' => 'Starter']);
    expect($event2->name)->toBe($event->name);

    $event = SaaSEvents::planUpgrade('starter', 'pro', ['mrr_delta' => 30.0]);
    expect($event->name)->toBe('plan_upgrade');
    expect($event->params['from_plan'])->toBe('starter');
    expect($event->params['to_plan'])->toBe('pro');
    expect($event->params['mrr_delta'])->toBe(30.0);

    $event = SaaSEvents::planDowngrade('pro', 'starter', ['mrr_delta' => -30.0]);
    expect($event->name)->toBe('plan_downgrade');
    expect($event->params['from_plan'])->toBe('pro');
    expect($event->params['to_plan'])->toBe('starter');

    $event = SaaSEvents::planChanged('pro', 'enterprise');
    expect($event->name)->toBe('plan_upgrade');
    expect($event->params['from_plan'])->toBe('pro');
    expect($event->params['to_plan'])->toBe('enterprise');

    $event = SaaSEvents::planChanged('enterprise', 'starter');
    expect($event->name)->toBe('plan_downgrade');

    $event = SaaSEvents::cancellation(['plan_name' => 'Pro', 'reason' => 'too_expensive']);
    expect($event->name)->toBe('cancellation');
    expect($event->params['plan_name'])->toBe('Pro');
    expect($event->params['reason'])->toBe('too_expensive');

    $event = SaaSEvents::featureUsed('export_csv');
    expect($event->name)->toBe('feature_used');
    expect($event->params['feature_name'])->toBe('export_csv');

    $event = SaaSEvents::subscriptionCreated(['plan_name' => 'Pro', 'amount' => 49.0]);
    expect($event->name)->toBe('subscription_created');

    $event = SaaSEvents::paymentFailed(['amount' => 49.0, 'reason' => 'card_declined']);
    expect($event->name)->toBe('payment_failed');
});

test('Engagement Events shorthand factory methods build correct events', function (): void {
    $event = EngagementEvents::pageView(['url' => '/pricing', 'title' => 'Pricing']);
    expect($event->name)->toBe('page_view');
    expect($event->category)->toBe('engagement');

    $event = EngagementEvents::scrollDepth(75);
    expect($event->name)->toBe('scroll_depth');
    expect($event->params['percent'])->toBe(75);

    $event = EngagementEvents::click('cta-signup', ['element_id' => 'btn-primary']);
    expect($event->name)->toBe('click');
    expect($event->params['element'])->toBe('cta-signup');
    expect($event->params['element_id'])->toBe('btn-primary');

    $event = EngagementEvents::formStart('contact-form');
    expect($event->name)->toBe('form_start');
    expect($event->params['form_name'])->toBe('contact-form');

    $event = EngagementEvents::formSubmit('contact-form', ['success' => true]);
    expect($event->name)->toBe('form_submit');
    expect($event->params['form_name'])->toBe('contact-form');

    $event = EngagementEvents::search('laravel analytics');
    expect($event->name)->toBe('search');
    expect($event->params['query'])->toBe('laravel analytics');

    $event = EngagementEvents::share('twitter', 'article');
    expect($event->name)->toBe('share');
    expect($event->params['method'])->toBe('twitter');
    expect($event->params['content_type'])->toBe('article');

    $event = EngagementEvents::error('Division by zero', ['code' => 500]);
    expect($event->name)->toBe('error');
    expect($event->params['error_message'])->toBe('Division by zero');
    expect($event->params['code'])->toBe(500);

    $event = EngagementEvents::sessionStart(['source' => 'direct']);
    expect($event->name)->toBe('session_start');
    expect($event->params['source'])->toBe('direct');

    $event = EngagementEvents::sessionEnd(['duration_seconds' => 300]);
    expect($event->name)->toBe('session_end');
    expect($event->params['duration_seconds'])->toBe(300);

    $event = EngagementEvents::fileDownload('/docs/api.pdf', 'api.pdf', 'pdf');
    expect($event->name)->toBe('file_download');
    expect($event->params['file_url'])->toBe('/docs/api.pdf');
    expect($event->params['file_name'])->toBe('api.pdf');
    expect($event->params['file_extension'])->toBe('pdf');

    $event = EngagementEvents::videoPlay('/videos/demo.mp4', 'Product Demo', 50.0);
    expect($event->name)->toBe('video_play');
    expect($event->params['video_url'])->toBe('/videos/demo.mp4');
    expect($event->params['video_title'])->toBe('Product Demo');
    expect($event->params['video_percent'])->toBe(50.0);
});

test('Ecommerce Events shorthand factory methods build correct events', function (): void {
    $event = EcommerceEvents::viewItem(['item_id' => 'SKU-123', 'item_name' => 'Widget', 'price' => 29.99]);
    expect($event->name)->toBe('view_item');
    expect($event->category)->toBe('ecommerce');
    expect($event->params['item_id'])->toBe('SKU-123');

    $event = EcommerceEvents::addToCart(['item_id' => 'SKU-123', 'price' => 29.99, 'quantity' => 2]);
    expect($event->name)->toBe('add_to_cart');

    $event = EcommerceEvents::purchase(['transaction_id' => 'TXN-001', 'value' => 59.98, 'currency' => 'USD']);
    expect($event->name)->toBe('purchase');
    expect($event->params['transaction_id'])->toBe('TXN-001');

    $event = EcommerceEvents::refund(['transaction_id' => 'TXN-001', 'value' => 59.98]);
    expect($event->name)->toBe('refund');

    $event = EcommerceEvents::checkoutStep(2, 'credit_card');
    expect($event->name)->toBe('checkout_step');
    expect($event->params['step'])->toBe(2);
    expect($event->params['checkout_option'])->toBe('credit_card');
});

test('SaaSFormatConverter supports plan_downgrade across all providers', function (): void {
    $params = [
        'from_plan' => 'pro',
        'to_plan' => 'starter',
        'value' => -30.0,
        'currency' => 'USD',
    ];

    expect(SaaSFormatConverter::supports('plan_downgrade'))->toBeTrue();

    $meta = SaaSFormatConverter::planDowngradeToMeta($params);
    expect($meta['content_name'])->toBe('plan_downgrade');
    expect($meta['value'])->toBe(-30.0);
    expect($meta['from_plan'])->toBe('pro');
    expect($meta['to_plan'])->toBe('starter');

    $ga4 = SaaSFormatConverter::planDowngradeToGa4($params);
    expect($ga4['items'][0]['item_category'])->toBe('plan_downgrade');
    expect($ga4['value'])->toBe(-30.0);

    $posthog = SaaSFormatConverter::planDowngradeToPosthog($params);
    expect($posthog['from_plan'])->toBe('pro');
    expect($posthog['to_plan'])->toBe('starter');

    // convertForProvider dispatches to the right method
    $result = SaaSFormatConverter::convertForProvider('plan_downgrade', $params, 'meta');
    expect($result)->toBe($meta);

    $result = SaaSFormatConverter::convertForProvider('plan_downgrade', $params, 'ga4');
    expect($result)->toBe($ga4);

    $result = SaaSFormatConverter::convertForProvider('plan_downgrade', $params, 'plausible');
    expect($result['from_plan'])->toBe('pro');
    expect($result['to_plan'])->toBe('starter');

    $result = SaaSFormatConverter::convertForProvider('plan_downgrade', $params, 'tiktok');
    expect($result['content_name'])->toBe('plan_downgrade');

    $result = SaaSFormatConverter::convertForProvider('plan_downgrade', $params, 'linkedin');
    expect($result['currency'])->toBe('USD');
});

test('SaaSFormatConverter plan_upgrade parity with plan_downgrade', function (): void {
    $upgradeParams = ['from_plan' => 'starter', 'to_plan' => 'pro', 'value' => 30.0, 'currency' => 'EUR'];
    $downgradeParams = ['from_plan' => 'pro', 'to_plan' => 'starter', 'value' => -30.0, 'currency' => 'EUR'];

    $providers = ['meta', 'posthog', 'ga4', 'mixpanel', 'amplitude', 'plausible', 'tiktok', 'linkedin'];

    foreach ($providers as $provider) {
        $upResult = SaaSFormatConverter::convertForProvider('plan_upgrade', $upgradeParams, $provider);
        $downResult = SaaSFormatConverter::convertForProvider('plan_downgrade', $downgradeParams, $provider);

        // Both should return arrays with the same structure
        expect($upResult)->toBeArray();
        expect($downResult)->toBeArray();

        // Both should have from_plan and to_plan
        expect(isset($upResult['from_plan']))->toBeTrue();
        expect(isset($downResult['from_plan']))->toBeTrue();
        expect(isset($upResult['to_plan']))->toBeTrue();
        expect(isset($downResult['to_plan']))->toBeTrue();
    }
});

test('EngagementFormatConverter covers all 8 core events', function (): void {
    expect(EngagementFormatConverter::pageViewToGa4(['url' => '/test']))->toBeArray();
    expect(EngagementFormatConverter::scrollDepthToGa4(['percent' => 50]))->toBeArray();
    expect(EngagementFormatConverter::clickToGa4(['url' => 'https://example.com']))->toBeArray();
    expect(EngagementFormatConverter::formStartToGa4(['form_name' => 'signup']))->toBeArray();
    expect(EngagementFormatConverter::formSubmitToGa4(['form_name' => 'signup']))->toBeArray();
    expect(EngagementFormatConverter::searchToGa4(['query' => 'test']))->toBeArray();
    expect(EngagementFormatConverter::shareToGa4(['method' => 'twitter']))->toBeArray();
    expect(EngagementFormatConverter::errorToGa4(['message' => 'oops']))->toBeArray();
});

test('build generic factory throws for unknown events', function (): void {
    expect(fn () => SaaSEvents::build('nonexistent_event'))->toThrow(\InvalidArgumentException::class);
    expect(fn () => EngagementEvents::build('nonexistent_event'))->toThrow(\InvalidArgumentException::class);
    expect(fn () => EcommerceEvents::build('nonexistent_event'))->toThrow(\InvalidArgumentException::class);
});
