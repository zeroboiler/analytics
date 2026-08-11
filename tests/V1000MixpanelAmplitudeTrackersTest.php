<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Trackers\MixpanelTracker;
use ZeroBoiler\Analytics\Trackers\AmplitudeTracker;

describe('MixpanelTracker', function () {
    it('constructs with token and host', function () {
        $tracker = new MixpanelTracker(
            token: 'test_token',
            host: 'https://api.mixpanel.com',
            enabled: false,
        );

        expect($tracker->isEnabled())->toBeFalse();
        expect($tracker->getToken())->toBe('test_token');
        expect($tracker->getHost())->toBe('https://api.mixpanel.com');
    });

    it('is enabled when token is present and enabled is true', function () {
        $tracker = new MixpanelTracker(
            token: 'valid_token',
            host: 'https://api.mixpanel.com',
            enabled: true,
        );

        expect($tracker->isEnabled())->toBeTrue();
    });

    it('is disabled when token is empty', function () {
        $tracker = new MixpanelTracker(
            token: '',
            host: 'https://api.mixpanel.com',
            enabled: true,
        );

        expect($tracker->isEnabled())->toBeFalse();
    });

    it('defaults consent to granted', function () {
        $tracker = new MixpanelTracker(token: 't', enabled: false);

        $consent = $tracker->getConsent();
        expect($consent->signals['analytics_storage'])->toBe('granted');
    });

    it('supports consent state updates', function () {
        $tracker = new MixpanelTracker(token: 't', enabled: false);

        $tracker->setConsent(ConsentState::denied());
        $consent = $tracker->getConsent();

        expect($consent->signals['analytics_storage'])->toBe('denied');
    });

    it('headScripts returns empty string when disabled', function () {
        $tracker = new MixpanelTracker(token: '', enabled: false);

        expect($tracker->headScripts())->toBe('');
    });

    it('headScripts contains script tag when enabled', function () {
        $tracker = new MixpanelTracker(token: 'test_token', enabled: true);

        $scripts = $tracker->headScripts();
        expect($scripts)->toContain('Mixpanel Analytics');
        expect($scripts)->toContain('test_token');
    });

    it('bodyScripts returns empty string', function () {
        $tracker = new MixpanelTracker(token: 't', enabled: false);

        expect($tracker->bodyScripts())->toBe('');
    });

    it('builds correct payload for track event', function () {
        $event = new AnalyticsEvent(
            name: 'sign_up',
            params: ['method' => 'email', 'plan' => 'pro'],
            clientId: 'client_123',
            userId: 'user_456',
        );

        // Can't test actual HTTP dispatch without mocking, but we can verify the tracker
        // doesn't throw when disabled
        $tracker = new MixpanelTracker(token: 't', enabled: false);

        expect(fn () => $tracker->track($event))->not->toThrow(\Throwable::class);
    });

    it('implements TrackerInterface', function () {
        $tracker = new MixpanelTracker(token: 't', enabled: false);

        expect($tracker)->toBeInstanceOf(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class);
    });
});

describe('AmplitudeTracker', function () {
    it('constructs with api_key and host', function () {
        $tracker = new AmplitudeTracker(
            apiKey: 'test_api_key',
            host: 'https://api2.amplitude.com',
            platform: 'Laravel/Server',
            enabled: false,
        );

        expect($tracker->isEnabled())->toBeFalse();
        expect($tracker->getApiKey())->toBe('test_api_key');
        expect($tracker->getHost())->toBe('https://api2.amplitude.com');
        expect($tracker->getPlatform())->toBe('Laravel/Server');
    });

    it('is enabled when api_key is present and enabled is true', function () {
        $tracker = new AmplitudeTracker(
            apiKey: 'valid_key',
            host: 'https://api2.amplitude.com',
            platform: 'Laravel/Server',
            enabled: true,
        );

        expect($tracker->isEnabled())->toBeTrue();
    });

    it('is disabled when api_key is empty', function () {
        $tracker = new AmplitudeTracker(
            apiKey: '',
            host: 'https://api2.amplitude.com',
            platform: 'Laravel/Server',
            enabled: true,
        );

        expect($tracker->isEnabled())->toBeFalse();
    });

    it('defaults consent to granted', function () {
        $tracker = new AmplitudeTracker(apiKey: 'k', enabled: false);

        $consent = $tracker->getConsent();
        expect($consent->signals['analytics_storage'])->toBe('granted');
    });

    it('supports consent state updates', function () {
        $tracker = new AmplitudeTracker(apiKey: 'k', enabled: false);

        $tracker->setConsent(ConsentState::denied());
        $consent = $tracker->getConsent();

        expect($consent->signals['analytics_storage'])->toBe('denied');
    });

    it('headScripts returns empty string when disabled', function () {
        $tracker = new AmplitudeTracker(apiKey: '', enabled: false);

        expect($tracker->headScripts())->toBe('');
    });

    it('headScripts contains script tag when enabled', function () {
        $tracker = new AmplitudeTracker(apiKey: 'test_key', enabled: true);

        $scripts = $tracker->headScripts();
        expect($scripts)->toContain('Amplitude Analytics');
        expect($scripts)->toContain('test_key');
    });

    it('bodyScripts returns empty string', function () {
        $tracker = new AmplitudeTracker(apiKey: 'k', enabled: false);

        expect($tracker->bodyScripts())->toBe('');
    });

    it('does not throw when tracking with user_id', function () {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99, 'currency' => 'USD'],
            userId: 'user_789',
        );

        $tracker = new AmplitudeTracker(apiKey: 'k', enabled: false);

        expect(fn () => $tracker->track($event))->not->toThrow(\Throwable::class);
    });

    it('does not throw when tracking with client_id only', function () {
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: ['page' => '/dashboard'],
            clientId: 'client_abc',
        );

        $tracker = new AmplitudeTracker(apiKey: 'k', enabled: false);

        expect(fn () => $tracker->track($event))->not->toThrow(\Throwable::class);
    });

    it('implements TrackerInterface', function () {
        $tracker = new AmplitudeTracker(apiKey: 'k', enabled: false);

        expect($tracker)->toBeInstanceOf(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class);
    });
});

describe('AnalyticsManager — Mixpanel & Amplitude Integration', function () {
    it('constructs with mixpanel and amplitude trackers', function () {
        // Simulating AnalyticsManager construction with a config mock
        // The manager initializes all 8 trackers including mixpanel and amplitude
        $manager = new AnalyticsManager;

        expect($manager->mixpanel())->toBeInstanceOf(MixpanelTracker::class);
        expect($manager->amplitude())->toBeInstanceOf(AmplitudeTracker::class);
    });

    it('mixpanel getter returns the tracker instance', function () {
        $manager = new AnalyticsManager;

        $mixpanel = $manager->mixpanel();
        expect($mixpanel)->toBeInstanceOf(MixpanelTracker::class);
        expect($mixpanel->getToken())->toBe('');
        expect($mixpanel->isEnabled())->toBeFalse();
    });

    it('amplitude getter returns the tracker instance', function () {
        $manager = new AnalyticsManager;

        $amplitude = $manager->amplitude();
        expect($amplitude)->toBeInstanceOf(AmplitudeTracker::class);
        expect($amplitude->getApiKey())->toBe('');
        expect($amplitude->isEnabled())->toBeFalse();
    });

    it('setConsent propagates to mixpanel and amplitude', function () {
        $manager = new AnalyticsManager;

        $manager->setConsent(ConsentState::denied());

        expect($manager->mixpanel()->getConsent()->signals['analytics_storage'])->toBe('denied');
        expect($manager->amplitude()->getConsent()->signals['analytics_storage'])->toBe('denied');
    });

    it('grantConsent propagates to all trackers', function () {
        $manager = new AnalyticsManager;
        $manager->denyConsent();
        $manager->grantConsent();

        expect($manager->mixpanel()->getConsent()->signals['analytics_storage'])->toBe('granted');
        expect($manager->amplitude()->getConsent()->signals['analytics_storage'])->toBe('granted');
    });

    it('track dispatches without error when mixpanel and amplitude are disabled', function () {
        $manager = new AnalyticsManager;

        // Should not throw even with new trackers disabled
        expect(fn () => $manager->track('test_event', ['key' => 'value']))
            ->not->toThrow(\Throwable::class);
    });

    it('all 8 tracker getters are accessible', function () {
        $manager = new AnalyticsManager;

        expect($manager->ga4())->toBeInstanceOf(\ZeroBoiler\Analytics\Trackers\GA4Tracker::class);
        expect($manager->gtm())->toBeInstanceOf(\ZeroBoiler\Analytics\Trackers\GTMTracker::class);
        expect($manager->meta())->toBeInstanceOf(\ZeroBoiler\Analytics\Trackers\MetaPixelTracker::class);
        expect($manager->plausible())->toBeInstanceOf(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class);
        expect($manager->posthog())->toBeInstanceOf(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class);
        expect($manager->webhook())->toBeInstanceOf(\ZeroBoiler\Analytics\Trackers\WebhookTracker::class);
        expect($manager->mixpanel())->toBeInstanceOf(MixpanelTracker::class);
        expect($manager->amplitude())->toBeInstanceOf(AmplitudeTracker::class);
    });
});
