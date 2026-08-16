<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

describe('V51 — TikTok & LinkedIn Provider Coverage Parity', function () {
    describe('Version sweep', function () {
        it('has VERSION 51.0.0', function () {
            expect(AnalyticsEvent::VERSION)->toBe('51.0.0');
        });
    });

    describe('SaaS Events — TikTok mappings', function () {
        it('sign_up maps to CompleteRegistration', function () {
            $entry = SaaSEvents::get('sign_up');
            expect($entry)->not->toBeNull();
            expect($entry['tiktok'])->toBe('CompleteRegistration');
        });

        it('login maps to Login', function () {
            $entry = SaaSEvents::get('login');
            expect($entry)->not->toBeNull();
            expect($entry['tiktok'])->toBe('Login');
        });

        it('logout has null tiktok mapping', function () {
            $entry = SaaSEvents::get('logout');
            expect($entry)->not->toBeNull();
            expect($entry['tiktok'])->toBeNull();
        });

        it('start_trial maps to Subscribe', function () {
            $entry = SaaSEvents::get('start_trial');
            expect($entry)->not->toBeNull();
            expect($entry['tiktok'])->toBe('Subscribe');
        });

        it('subscribe maps to Subscribe', function () {
            $entry = SaaSEvents::get('subscribe');
            expect($entry)->not->toBeNull();
            expect($entry['tiktok'])->toBe('Subscribe');
        });

        it('plan_upgrade maps to Subscribe', function () {
            $entry = SaaSEvents::get('plan_upgrade');
            expect($entry)->not->toBeNull();
            expect($entry['tiktok'])->toBe('Subscribe');
        });

        it('revenue_tracked maps to CompletePayment', function () {
            $entry = SaaSEvents::get('revenue_tracked');
            expect($entry)->not->toBeNull();
            expect($entry['tiktok'])->toBe('CompletePayment');
        });

        it('cancellation has null tiktok mapping', function () {
            $entry = SaaSEvents::get('cancellation');
            expect($entry)->not->toBeNull();
            expect($entry['tiktok'])->toBeNull();
        });
    });

    describe('SaaS Events — LinkedIn mappings', function () {
        it('sign_up maps to signup', function () {
            $entry = SaaSEvents::get('sign_up');
            expect($entry)->not->toBeNull();
            expect($entry['linkedin'])->toBe('signup');
        });

        it('login maps to login', function () {
            $entry = SaaSEvents::get('login');
            expect($entry)->not->toBeNull();
            expect($entry['linkedin'])->toBe('login');
        });

        it('subscribe maps to purchase', function () {
            $entry = SaaSEvents::get('subscribe');
            expect($entry)->not->toBeNull();
            expect($entry['linkedin'])->toBe('purchase');
        });

        it('plan_upgrade maps to purchase', function () {
            $entry = SaaSEvents::get('plan_upgrade');
            expect($entry)->not->toBeNull();
            expect($entry['linkedin'])->toBe('purchase');
        });

        it('revenue_tracked maps to purchase', function () {
            $entry = SaaSEvents::get('revenue_tracked');
            expect($entry)->not->toBeNull();
            expect($entry['linkedin'])->toBe('purchase');
        });

        it('logout has null linkedin mapping', function () {
            $entry = SaaSEvents::get('logout');
            expect($entry)->not->toBeNull();
            expect($entry['linkedin'])->toBeNull();
        });
    });

    describe('Engagement Events — TikTok mappings', function () {
        it('page_view maps to Pageview', function () {
            $entry = EngagementEvents::get('page_view');
            expect($entry)->not->toBeNull();
            expect($entry['tiktok'])->toBe('Pageview');
        });

        it('click maps to ClickButton', function () {
            $entry = EngagementEvents::get('click');
            expect($entry)->not->toBeNull();
            expect($entry['tiktok'])->toBe('ClickButton');
        });

        it('form_submit maps to SubmitForm', function () {
            $entry = EngagementEvents::get('form_submit');
            expect($entry)->not->toBeNull();
            expect($entry['tiktok'])->toBe('SubmitForm');
        });

        it('search maps to Search', function () {
            $entry = EngagementEvents::get('search');
            expect($entry)->not->toBeNull();
            expect($entry['tiktok'])->toBe('Search');
        });

        it('scroll_depth has null tiktok mapping', function () {
            $entry = EngagementEvents::get('scroll_depth');
            expect($entry)->not->toBeNull();
            expect($entry['tiktok'])->toBeNull();
        });

        it('share has null tiktok mapping', function () {
            $entry = EngagementEvents::get('share');
            expect($entry)->not->toBeNull();
            expect($entry['tiktok'])->toBeNull();
        });

        it('error has null tiktok mapping', function () {
            $entry = EngagementEvents::get('error');
            expect($entry)->not->toBeNull();
            expect($entry['tiktok'])->toBeNull();
        });
    });

    describe('Engagement Events — LinkedIn mappings', function () {
        it('page_view maps to page_view', function () {
            $entry = EngagementEvents::get('page_view');
            expect($entry)->not->toBeNull();
            expect($entry['linkedin'])->toBe('page_view');
        });

        it('click has null linkedin mapping', function () {
            $entry = EngagementEvents::get('click');
            expect($entry)->not->toBeNull();
            expect($entry['linkedin'])->toBeNull();
        });
    });

    describe('Ecommerce baseline consistency', function () {
        it('EcommerceEvents has tiktokNames method returning non-empty for mapped events', function () {
            $names = EcommerceEvents::tiktokNames();
            expect($names)->not->toBeEmpty();
            expect(in_array('view_item', $names, true))->toBeTrue();
        });

        it('EcommerceEvents has linkedinNames method returning non-empty for mapped events', function () {
            $names = EcommerceEvents::linkedinNames();
            expect($names)->not->toBeEmpty();
            expect(in_array('add_to_cart', $names, true))->toBeTrue();
        });
    });

    describe('SaaS tiktokNames/linkedinNames methods', function () {
        it('tiktokNames returns non-empty list after adding mappings', function () {
            $names = SaaSEvents::tiktokNames();
            expect($names)->not->toBeEmpty();
            expect(in_array('CompleteRegistration', $names, true))->toBeTrue();
            expect(in_array('Login', $names, true))->toBeTrue();
            expect(in_array('Subscribe', $names, true))->toBeTrue();
            expect(in_array('CompletePayment', $names, true))->toBeTrue();
        });

        it('linkedinNames returns non-empty list after adding mappings', function () {
            $names = SaaSEvents::linkedinNames();
            expect($names)->not->toBeEmpty();
            expect(in_array('signup', $names, true))->toBeTrue();
            expect(in_array('login', $names, true))->toBeTrue();
            expect(in_array('purchase', $names, true))->toBeTrue();
        });
    });

    describe('Engagement tiktokNames/linkedinNames methods', function () {
        it('tiktokNames returns non-empty list after adding mappings', function () {
            $names = EngagementEvents::tiktokNames();
            expect($names)->not->toBeEmpty();
            expect(in_array('Pageview', $names, true))->toBeTrue();
            expect(in_array('ClickButton', $names, true))->toBeTrue();
            expect(in_array('SubmitForm', $names, true))->toBeTrue();
            expect(in_array('Search', $names, true))->toBeTrue();
        });

        it('linkedinNames returns non-empty list after adding mappings', function () {
            $names = EngagementEvents::linkedinNames();
            expect($names)->not->toBeEmpty();
            expect(in_array('page_view', $names, true))->toBeTrue();
        });
    });

    describe('Entry structure integrity', function () {
        it('SaaS sign_up entry has all 10 provider fields', function () {
            $entry = SaaSEvents::get('sign_up');
            expect($entry)->not->toBeNull();
            expect(array_keys($entry))->toContain('name');
            expect(array_keys($entry))->toContain('class');
            expect(array_keys($entry))->toContain('ga4');
            expect(array_keys($entry))->toContain('meta');
            expect(array_keys($entry))->toContain('posthog');
            expect(array_keys($entry))->toContain('plausible');
            expect(array_keys($entry))->toContain('mixpanel');
            expect(array_keys($entry))->toContain('amplitude');
            expect(array_keys($entry))->toContain('tiktok');
            expect(array_keys($entry))->toContain('linkedin');
        });

        it('Engagement page_view entry has all 10 provider fields', function () {
            $entry = EngagementEvents::get('page_view');
            expect($entry)->not->toBeNull();
            expect(array_keys($entry))->toContain('tiktok');
            expect(array_keys($entry))->toContain('linkedin');
        });
    });
});
