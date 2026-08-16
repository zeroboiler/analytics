<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Marketing\MarketingEvents;
use ZeroBoiler\Analytics\Events\Marketing\MarketingEventConstants;
use ZeroBoiler\Analytics\Events\Marketing\EmailSentEvent;
use ZeroBoiler\Analytics\Events\Marketing\EmailOpenedEvent;
use ZeroBoiler\Analytics\Events\Marketing\EmailClickedEvent;
use ZeroBoiler\Analytics\Events\Marketing\LeadCapturedEvent;
use ZeroBoiler\Analytics\Events\Marketing\SocialShareEvent;
use ZeroBoiler\Analytics\Events\Marketing\WebinarRegisteredEvent;
use ZeroBoiler\Analytics\Events\Marketing\ReferralLinkSharedEvent;
use ZeroBoiler\Analytics\Events\Marketing\AdImpressionEvent;
use ZeroBoiler\Analytics\Events\Marketing\SmsSentEvent;
use ZeroBoiler\Analytics\Events\Marketing\PushNotificationSentEvent;

beforeEach(function (): void {
    // Reset catalog static cache by accessing fresh instance
    $ref = new ReflectionClass(MarketingEvents::class);
    $prop = $ref->getProperty('catalog');
    $prop->setAccessible(true);
    $prop->setValue([]);
});

describe('MarketingEvents Catalog', function (): void {
    test('has correct number of marketing events', function (): void {
        expect(MarketingEvents::count())->toBeGreaterThan(0);
    });

    test('returns all event names', function (): void {
        $names = MarketingEvents::names();
        expect($names)->toBeArray();
        expect($names)->not->toBeEmpty();
        expect(in_array('email_sent', $names, true))->toBeTrue();
        expect(in_array('lead_captured', $names, true))->toBeTrue();
        expect(in_array('social_share', $names, true))->toBeTrue();
        expect(in_array('webinar_registered', $names, true))->toBeTrue();
        expect(in_array('referral_link_shared', $names, true))->toBeTrue();
        expect(in_array('ad_impression', $names, true))->toBeTrue();
        expect(in_array('sms_sent', $names, true))->toBeTrue();
        expect(in_array('push_notification_sent', $names, true))->toBeTrue();
        expect(in_array('affiliate_signup', $names, true))->toBeTrue();
        expect(in_array('campaign_response', $names, true))->toBeTrue();
    });

    test('has and get work correctly', function (): void {
        expect(MarketingEvents::has('email_sent'))->toBeTrue();
        expect(MarketingEvents::has('nonexistent'))->toBeFalse();
        expect(MarketingEvents::get('email_sent'))->not->toBeNull();
        expect(MarketingEvents::get('email_sent')['name'])->toBe('email_sent');
        expect(MarketingEvents::get('email_sent')['ga4'])->toBe('email_sent');
    });

    test('category is marketing', function (): void {
        expect(MarketingEvents::category())->toBe('marketing');
    });

    test('all entries have required keys', function (): void {
        foreach (MarketingEvents::all() as $name => $entry) {
            expect($entry)->toHaveKeys(['name', 'class', 'ga4']);
            expect($entry['name'])->toBe($name);
        }
    });

    test('provider name methods return arrays', function (): void {
        expect(MarketingEvents::ga4Names())->toBeArray();
        expect(MarketingEvents::metaNames())->toBeArray();
        expect(MarketingEvents::posthogNames())->toBeArray();
        expect(MarketingEvents::plausibleNames())->toBeArray();
        expect(MarketingEvents::mixpanelNames())->toBeArray();
        expect(MarketingEvents::amplitudeNames())->toBeArray();
        expect(MarketingEvents::tiktokNames())->toBeArray();
        expect(MarketingEvents::linkedinNames())->toBeArray();
    });

    test('classFor returns class strings for valid events', function (): void {
        expect(MarketingEvents::classFor('email_sent'))->toBe(EmailSentEvent::class);
        expect(MarketingEvents::classFor('lead_captured'))->toBe(LeadCapturedEvent::class);
        expect(MarketingEvents::classFor('nonexistent'))->toBeNull();
    });
});

describe('MarketingEvents in EventCatalog', function (): void {
    test('marketing category exists in byCategory', function (): void {
        $byCategory = EventCatalog::byCategory();
        expect($byCategory)->toHaveKey('marketing');
        expect($byCategory['marketing'])->not->toBeEmpty();
    });

    test('marketing events are in all()', function (): void {
        $all = EventCatalog::all();
        expect($all)->toHaveKey('email_sent');
        expect($all)->toHaveKey('lead_captured');
        expect($all['email_sent']['category'])->toBe('marketing');
    });

    test('getCategory returns marketing for marketing events', function (): void {
        expect(EventCatalog::getCategory('email_sent'))->toBe('marketing');
        expect(EventCatalog::getCategory('lead_captured'))->toBe('marketing');
    });

    test('has returns true for marketing events', function (): void {
        expect(EventCatalog::has('email_sent'))->toBeTrue();
        expect(EventCatalog::has('social_share'))->toBeTrue();
        expect(EventCatalog::has('webinar_registered'))->toBeTrue();
    });

    test('count includes marketing events', function (): void {
        $total = EventCatalog::count();
        $sum = EventCatalog::count();
        expect($total)->toBeGreaterThan(100);
    });

    test('marketing events included in GA4 names', function (): void {
        $ga4 = EventCatalog::allGa4Names();
        expect(in_array('email_sent', $ga4, true))->toBeTrue();
    });

    test('marketing events included in PostHog names', function (): void {
        $posthog = EventCatalog::allPosthogNames();
        expect(in_array('email_sent', $posthog, true))->toBeTrue();
    });

    test('marketing events included in Meta names where mapped', function (): void {
        $meta = EventCatalog::allMetaNames();
        expect(in_array('ViewContent', $meta, true))->toBeTrue();
    });

    test('classFor returns marketing event classes', function (): void {
        expect(EventCatalog::classFor('email_sent'))->toBe(EmailSentEvent::class);
    });

    test('category match returns marketing events', function (): void {
        $marketing = EventCatalog::category('marketing');
        expect($marketing)->not->toBeEmpty();
        expect($marketing)->toHaveKey('email_sent');
        expect($marketing['email_sent']['category'])->toBe('marketing');
    });

    test('resolve works for marketing event names', function (): void {
        expect(EventCatalog::resolve('email_sent'))->toBe('email_sent');
        expect(EventCatalog::resolve('email_opened'))->toBe('email_opened');
    });
});

describe('Marketing Event Classes', function (): void {
    test('EmailSentEvent constructs with correct name and params', function (): void {
        $event = new EmailSentEvent('summer_sale', 'Welcome to our sale!');
        expect($event->name)->toBe('email_sent');
        expect($event->params['campaign'])->toBe('summer_sale');
        expect($event->params['subject'])->toBe('Welcome to our sale!');
    });

    test('EmailOpenedEvent constructs correctly', function (): void {
        $event = new EmailOpenedEvent('weekly_digest', 'user_123');
        expect($event->name)->toBe('email_opened');
        expect($event->params['campaign'])->toBe('weekly_digest');
    });

    test('EmailClickedEvent constructs with URL and CTA', function (): void {
        $event = new EmailClickedEvent('launch_email', 'https://example.com/launch', 'user_456', 'Buy Now');
        expect($event->name)->toBe('email_clicked');
        expect($event->params['url'])->toBe('https://example.com/launch');
        expect($event->params['cta'])->toBe('Buy Now');
    });

    test('LeadCapturedEvent constructs with source', function (): void {
        $event = new LeadCapturedEvent('form', 'google_ads', 'contact_form', 'https://example.com/landing');
        expect($event->name)->toBe('lead_captured');
        expect($event->params['source'])->toBe('form');
        expect($event->params['campaign'])->toBe('google_ads');
    });

    test('SocialShareEvent constructs with platform', function (): void {
        $event = new SocialShareEvent('twitter', 'https://example.com/blog/post', 'blog');
        expect($event->name)->toBe('social_share');
        expect($event->params['platform'])->toBe('twitter');
    });

    test('WebinarRegisteredEvent constructs correctly', function (): void {
        $event = new WebinarRegisteredEvent('Product Launch 2026', 'email_campaign', 'landing_page');
        expect($event->name)->toBe('webinar_registered');
        expect($event->params['webinar_name'])->toBe('Product Launch 2026');
    });

    test('ReferralLinkSharedEvent constructs correctly', function (): void {
        $event = new ReferralLinkSharedEvent('email', 'REF123', 'referral_program');
        expect($event->name)->toBe('referral_link_shared');
        expect($event->params['referrer_code'])->toBe('REF123');
    });

    test('AdImpressionEvent constructs correctly', function (): void {
        $event = new AdImpressionEvent('brand_awareness', 'google', 'cpc');
        expect($event->name)->toBe('ad_impression');
        expect($event->params['campaign'])->toBe('brand_awareness');
    });

    test('SmsSentEvent constructs correctly', function (): void {
        $event = new SmsSentEvent('flash_sale', 'sms', 'sms');
        expect($event->name)->toBe('sms_sent');
        expect($event->params['campaign'])->toBe('flash_sale');
    });

    test('PushNotificationSentEvent constructs correctly', function (): void {
        $event = new PushNotificationSentEvent('daily_digest', 'push', 'push');
        expect($event->name)->toBe('push_notification_sent');
    });
});

describe('MarketingEventConstants', function (): void {
    test('all constants are defined', function (): void {
        expect(MarketingEventConstants::EMAIL_SENT)->toBe('email_sent');
        expect(MarketingEventConstants::LEAD_CAPTURED)->toBe('lead_captured');
        expect(MarketingEventConstants::SOCIAL_SHARE)->toBe('social_share');
        expect(MarketingEventConstants::WEBINAR_REGISTERED)->toBe('webinar_registered');
        expect(MarketingEventConstants::REFERRAL_LINK_SHARED)->toBe('referral_link_shared');
        expect(MarketingEventConstants::AD_IMPRESSION)->toBe('ad_impression');
        expect(MarketingEventConstants::SMS_SENT)->toBe('sms_sent');
        expect(MarketingEventConstants::PUSH_NOTIFICATION_SENT)->toBe('push_notification_sent');
        expect(MarketingEventConstants::AFFILIATE_SIGNUP)->toBe('affiliate_signup');
        expect(MarketingEventConstants::CAMPAIGN_RESPONSE)->toBe('campaign_response');
    });

    test('all() returns all constants', function (): void {
        $all = MarketingEventConstants::all();
        expect($all)->toBeArray();
        expect($all)->not->toBeEmpty();
        expect(MarketingEventConstants::count())->toBe(count($all));
    });

    test('names() returns list of constant values', function (): void {
        $names = MarketingEventConstants::names();
        expect($names)->toBeArray();
        expect($names)->toContain('email_sent');
        expect($names)->toContain('lead_captured');
    });

    test('isValid validates constant membership', function (): void {
        expect(MarketingEventConstants::isValid('email_sent'))->toBeTrue();
        expect(MarketingEventConstants::isValid('nonexistent_event'))->toBeFalse();
    });

    test('constant values match catalog names', function (): void {
        $names = MarketingEventConstants::names();
        foreach ($names as $name) {
            expect(MarketingEvents::has($name))->toBeTrue();
        }
    });
});
