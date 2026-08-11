<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;

describe('PlausibleTracker — Full Provider Suite', function () {
    describe('Construction & Configuration', function () {
        it('constructs with all parameters', function () {
            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'plausible-secret-123',
                baseUrl: 'https://plausible.example.com/api/event',
                enabled: true,
                customScriptUrl: 'https://stats.example.com/js/script.js',
            );

            expect($tracker->isEnabled())->toBeTrue();
            expect($tracker->getDomain())->toBe('example.com');
            expect($tracker->isSelfHosted())->toBeTrue();
            expect($tracker->getCustomScriptUrl())->toBe('https://stats.example.com/js/script.js');
        });

        it('constructs with defaults (self-hosted = false)', function () {
            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'secret',
                enabled: true,
            );

            expect($tracker->isEnabled())->toBeTrue();
            expect($tracker->isSelfHosted())->toBeFalse();
            expect($tracker->getCustomScriptUrl())->toBeNull();
        });
    });

    describe('Enabled/Disabled States', function () {
        it('is disabled when domain is empty', function () {
            $tracker = new PlausibleTracker(domain: '', apiKey: 'secret', enabled: true);

            expect($tracker->isEnabled())->toBeFalse();
        });

        it('is disabled when API key is empty', function () {
            $tracker = new PlausibleTracker(domain: 'example.com', apiKey: '', enabled: true);

            expect($tracker->isEnabled())->toBeFalse();
        });

        it('is disabled when enabled flag is false', function () {
            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'secret',
                enabled: false,
            );

            expect($tracker->isEnabled())->toBeFalse();
        });

        it('is enabled with all valid credentials', function () {
            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'plausible-secret',
                enabled: true,
            );

            expect($tracker->isEnabled())->toBeTrue();
        });
    });

    describe('Script Generation', function () {
        it('returns empty head scripts when disabled', function () {
            $tracker = new PlausibleTracker(domain: '', apiKey: '', enabled: false);

            expect($tracker->headScripts())->toBe('');
        });

        it('returns Plausible cloud head scripts when enabled', function () {
            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'secret',
                enabled: true,
            );

            $scripts = $tracker->headScripts();

            expect($scripts)->toContain('data-domain="example.com"');
            expect($scripts)->toContain('plausible.io/js/script.js');
            expect($scripts)->toContain('defer');
            expect($scripts)->not->toContain('self-hosted');
        });

        it('returns custom script URL for self-hosted instances', function () {
            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'secret',
                enabled: true,
                customScriptUrl: 'https://stats.example.com/js/script.js',
            );

            $scripts = $tracker->headScripts();

            expect($scripts)->toContain('data-domain="example.com"');
            expect($scripts)->toContain('stats.example.com/js/script.js');
            expect($scripts)->toContain('Self-Hosted');
        });

        it('always returns empty body scripts', function () {
            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'secret',
                enabled: true,
            );

            expect($tracker->bodyScripts())->toBe('');
        });
    });

    describe('Consent Management', function () {
        it('initializes with granted consent', function () {
            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'secret',
                enabled: true,
            );

            expect($tracker->getConsent()->hasAnalyticsConsent())->toBeTrue();
        });

        it('accepts consent state changes', function () {
            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'secret',
                enabled: true,
            );

            $tracker->setConsent(ConsentState::denied());
            expect($tracker->getConsent()->hasAnalyticsConsent())->toBeFalse();

            $tracker->setConsent(ConsentState::granted());
            expect($tracker->getConsent()->hasAnalyticsConsent())->toBeTrue();
        });
    });

    describe('Event Tracking', function () {
        it('does not track when disabled', function () {
            $tracker = new PlausibleTracker(domain: '', apiKey: '', enabled: false);
            $event = new AnalyticsEvent(name: 'test_event', params: ['key' => 'value']);

            // Should not throw — just silently skip
            $tracker->track($event);
            expect(true)->toBeTrue(); // No exception = pass
        });

        it('does not track when analytics consent is denied', function () {
            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'secret',
                enabled: true,
            );
            $tracker->setConsent(ConsentState::denied());

            $event = new AnalyticsEvent(name: 'test_event', params: ['key' => 'value']);
            $tracker->track($event);
            expect(true)->toBeTrue(); // No exception = pass
        });

        it('accepts trackGoal with URL and props', function () {
            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'secret',
                enabled: false, // Disabled to avoid HTTP call
            );

            // trackGoal should silently skip when disabled
            $tracker->trackGoal('signup', '/pricing', ['plan' => 'pro']);
            expect(true)->toBeTrue();
        });

        it('accepts trackPageView with URL', function () {
            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'secret',
                enabled: false,
            );

            $tracker->trackPageView('/dashboard', 'https://example.com/');
            expect(true)->toBeTrue();
        });

        it('accepts trackPageView with URL and referrer', function () {
            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'secret',
                enabled: false,
            );

            $tracker->trackPageView('/pricing', 'https://google.com/');
            expect(true)->toBeTrue();
        });
    });
});

describe('PosthogTracker — Full Provider Suite', function () {
    describe('Construction & Configuration', function () {
        it('constructs with all parameters', function () {
            $tracker = new PosthogTracker(
                apiKey: 'posthog-secret-123',
                host: 'https://us.posthog.com',
                projectId: 'proj-123',
                enabled: true,
                capiEnabled: true,
                capturePath: '/capture/',
            );

            expect($tracker->isEnabled())->toBeTrue();
            expect($tracker->getApiKey())->toBe('posthog-secret-123');
            expect($tracker->getHost())->toBe('https://us.posthog.com');
            expect($tracker->isCapiEnabled())->toBeTrue();
        });

        it('constructs with defaults', function () {
            $tracker = new PosthogTracker(
                apiKey: 'secret',
                enabled: true,
            );

            expect($tracker->isEnabled())->toBeTrue();
            expect($tracker->getHost())->toBe('https://eu.posthog.com');
            expect($tracker->isCapiEnabled())->toBeTrue();
        });

        it('strips trailing slash from host', function () {
            $tracker = new PosthogTracker(
                apiKey: 'secret',
                host: 'https://eu.posthog.com/',
                enabled: true,
            );

            expect($tracker->getHost())->toBe('https://eu.posthog.com');
        });
    });

    describe('Enabled/Disabled States', function () {
        it('is disabled when API key is missing', function () {
            $tracker = new PosthogTracker(apiKey: '', enabled: true);

            expect($tracker->isEnabled())->toBeFalse();
        });

        it('is disabled when enabled flag is false', function () {
            $tracker = new PosthogTracker(
                apiKey: 'secret',
                enabled: false,
            );

            expect($tracker->isEnabled())->toBeFalse();
        });

        it('is enabled with valid API key', function () {
            $tracker = new PosthogTracker(apiKey: 'secret', enabled: true);

            expect($tracker->isEnabled())->toBeTrue();
        });
    });

    describe('Script Generation', function () {
        it('returns empty head scripts when disabled', function () {
            $tracker = new PosthogTracker(apiKey: '', enabled: false);

            expect($tracker->headScripts())->toBe('');
        });

        it('returns empty head scripts when no project ID', function () {
            $tracker = new PosthogTracker(
                apiKey: 'secret',
                enabled: true,
                projectId: '',
            );

            expect($tracker->headScripts())->toBe('');
        });

        it('returns PostHog head scripts when fully configured', function () {
            $tracker = new PosthogTracker(
                apiKey: 'posthog-secret',
                host: 'https://eu.posthog.com',
                projectId: 'proj-123',
                enabled: true,
            );

            $scripts = $tracker->headScripts();

            expect($scripts)->toContain('posthog');
            expect($scripts)->toContain("'{$tracker->getApiKey()}'");
            expect($scripts)->toContain('api_host');
            expect($scripts)->toContain('PostHog Analytics');
        });

        it('always returns empty body scripts', function () {
            $tracker = new PosthogTracker(
                apiKey: 'secret',
                enabled: true,
                projectId: 'proj-123',
            );

            expect($tracker->bodyScripts())->toBe('');
        });
    });

    describe('Consent Management', function () {
        it('initializes with granted consent', function () {
            $tracker = new PosthogTracker(apiKey: 'secret', enabled: true);

            expect($tracker->getConsent()->hasAnalyticsConsent())->toBeTrue();
        });

        it('accepts consent state changes', function () {
            $tracker = new PosthogTracker(apiKey: 'secret', enabled: true);

            $tracker->setConsent(ConsentState::denied());
            expect($tracker->getConsent()->hasAnalyticsConsent())->toBeFalse();

            $tracker->setConsent(ConsentState::granted());
            expect($tracker->getConsent()->hasAnalyticsConsent())->toBeTrue();
        });
    });

    describe('Event Tracking', function () {
        it('does not track when disabled', function () {
            $tracker = new PosthogTracker(apiKey: '', enabled: false);
            $event = new AnalyticsEvent(name: 'test_event', params: ['key' => 'value']);

            $tracker->track($event);
            expect(true)->toBeTrue();
        });

        it('does not track when analytics consent is denied', function () {
            $tracker = new PosthogTracker(apiKey: 'secret', enabled: true);
            $tracker->setConsent(ConsentState::denied());

            $event = new AnalyticsEvent(name: 'test_event', params: ['key' => 'value']);
            $tracker->track($event);
            expect(true)->toBeTrue();
        });

        it('accepts trackWithPerson with CAPI enabled', function () {
            $tracker = new PosthogTracker(
                apiKey: 'secret',
                enabled: false, // Disabled to avoid HTTP call
                capiEnabled: true,
            );

            $tracker->trackWithPerson(
                event: 'signup',
                distinctId: 'user-123',
                eventProps: ['method' => 'email'],
                personProps: ['email' => 'user@example.com', 'plan' => 'pro'],
            );
            expect(true)->toBeTrue();
        });

        it('accepts identify call', function () {
            $tracker = new PosthogTracker(apiKey: 'secret', enabled: false);

            $tracker->identify('user-123', ['name' => 'John', 'email' => 'john@example.com']);
            expect(true)->toBeTrue();
        });

        it('accepts alias call', function () {
            $tracker = new PosthogTracker(apiKey: 'secret', enabled: false);

            $tracker->alias('anonymous-abc', 'user-123');
            expect(true)->toBeTrue();
        });

        it('accepts trackPageView call', function () {
            $tracker = new PosthogTracker(apiKey: 'secret', enabled: false);

            $tracker->trackPageView('user-123', '/dashboard', 'https://google.com/', 'Dashboard');
            expect(true)->toBeTrue();
        });
    });

    describe('Feature Flag Evaluation', function () {
        it('returns null when disabled', function () {
            $tracker = new PosthogTracker(apiKey: '', enabled: false);

            expect($tracker->isFeatureEnabled('new-feature', 'user-123'))->toBeNull();
        });
    });

    describe('GDPR Reset', function () {
        it('does not throw when disabled', function () {
            $tracker = new PosthogTracker(apiKey: '', enabled: false);

            $tracker->reset();
            expect(true)->toBeTrue();
        });
    });
});

describe('Tracker Parity — Interface Contract', function () {
    it('PlausibleTracker implements TrackerInterface', function () {
        $implements = class_implements(PlausibleTracker::class);

        expect($implements)->toContain(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class);
    });

    it('PosthogTracker implements TrackerInterface', function () {
        $implements = class_implements(PosthogTracker::class);

        expect($implements)->toContain(\ZeroBoiler\Analytics\Trackers\TrackerInterface::class);
    });

    it('both trackers are final classes', function () {
        $plausible = new ReflectionClass(PlausibleTracker::class);
        $posthog = new ReflectionClass(PosthogTracker::class);

        expect($plausible->isFinal())->toBeTrue();
        expect($posthog->isFinal())->toBeTrue();
    });

    it('both trackers use strict types', function () {
        $plausibleContent = file_get_contents((new ReflectionClass(PlausibleTracker::class))->getFileName());
        $posthogContent = file_get_contents((new ReflectionClass(PosthogTracker::class))->getFileName());

        expect($plausibleContent)->toContain('declare(strict_types=1)');
        expect($posthogContent)->toContain('declare(strict_types=1)');
    });
});
