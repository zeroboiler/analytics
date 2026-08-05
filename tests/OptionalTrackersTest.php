<?php

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;

describe('PlausibleTracker', function () {
    it('is disabled when credentials are missing', function () {
        $tracker = new PlausibleTracker(domain: '', apiKey: '', enabled: true);

        expect($tracker->isEnabled())->toBeFalse();
    });

    it('is disabled when enabled flag is false', function () {
        $tracker = new PlausibleTracker(
            domain: 'example.com',
            apiKey: 'secret-key',
            enabled: false,
        );

        expect($tracker->isEnabled())->toBeFalse();
    });

    it('is enabled with valid credentials', function () {
        $tracker = new PlausibleTracker(
            domain: 'example.com',
            apiKey: 'secret-key',
            enabled: true,
        );

        expect($tracker->isEnabled())->toBeTrue();
        expect($tracker->getDomain())->toBe('example.com');
    });

    it('returns empty head scripts when disabled', function () {
        $tracker = new PlausibleTracker(domain: '', apiKey: '', enabled: false);

        expect($tracker->headScripts())->toBe('');
    });

    it('returns head scripts when enabled', function () {
        $tracker = new PlausibleTracker(
            domain: 'example.com',
            apiKey: 'secret-key',
            enabled: true,
        );

        $scripts = $tracker->headScripts();

        expect($scripts)->toContain('data-domain="example.com"');
        expect($scripts)->toContain('plausible.io');
    });

    it('returns empty body scripts', function () {
        $tracker = new PlausibleTracker(domain: 'example.com', apiKey: 'key', enabled: true);

        expect($tracker->bodyScripts())->toBe('');
    });

    it('manages consent state', function () {
        $tracker = new PlausibleTracker(domain: 'example.com', apiKey: 'key', enabled: true);

        $tracker->setConsent(ConsentState::denied());
        expect($tracker->getConsent()->hasAnalyticsConsent())->toBeFalse();

        $tracker->setConsent(ConsentState::granted());
        expect($tracker->getConsent()->hasAnalyticsConsent())->toBeTrue();
    });
});

describe('PosthogTracker', function () {
    it('is disabled when API key is missing', function () {
        $tracker = new PosthogTracker(apiKey: '', enabled: true);

        expect($tracker->isEnabled())->toBeFalse();
    });

    it('is disabled when enabled flag is false', function () {
        $tracker = new PosthogTracker(apiKey: 'phc_key', enabled: false);

        expect($tracker->isEnabled())->toBeFalse();
    });

    it('is enabled with valid API key', function () {
        $tracker = new PosthogTracker(
            apiKey: 'phc_testkey',
            host: 'https://eu.posthog.com',
            projectId: '123',
            enabled: true,
        );

        expect($tracker->isEnabled())->toBeTrue();
        expect($tracker->getApiKey())->toBe('phc_testkey');
        expect($tracker->getHost())->toBe('https://eu.posthog.com');
    });

    it('returns empty head scripts when disabled', function () {
        $tracker = new PosthogTracker(apiKey: '', enabled: false);

        expect($tracker->headScripts())->toBe('');
    });

    it('returns empty head scripts when project_id is empty', function () {
        $tracker = new PosthogTracker(apiKey: 'key', projectId: '', enabled: true);

        expect($tracker->headScripts())->toBe('');
    });

    it('returns head scripts when enabled with project_id', function () {
        $tracker = new PosthogTracker(
            apiKey: 'phc_key',
            projectId: '123',
            enabled: true,
        );

        $scripts = $tracker->headScripts();

        expect($scripts)->toContain('posthog');
        expect($scripts)->toContain('array.js');
    });

    it('returns empty body scripts', function () {
        $tracker = new PosthogTracker(apiKey: 'key', enabled: true);

        expect($tracker->bodyScripts())->toBe('');
    });

    it('manages consent state', function () {
        $tracker = new PosthogTracker(apiKey: 'key', enabled: true);

        $tracker->setConsent(ConsentState::denied());
        expect($tracker->getConsent()->hasAnalyticsConsent())->toBeFalse();

        $tracker->setConsent(ConsentState::granted());
        expect($tracker->getConsent()->hasAnalyticsConsent())->toBeTrue();
    });
});
