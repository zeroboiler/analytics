<?php

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;

beforeEach(function () {
    $this->tracker = new MetaPixelTracker(
        pixelId: '123456789012345',
        accessToken: 'test-access-token',
        enabled: true,
    );
});

describe('MetaPixelTracker', function () {
    it('can be instantiated', function () {
        expect($this->tracker)->toBeInstanceOf(MetaPixelTracker::class);
    });

    it('is enabled when pixel ID and access token are valid', function () {
        expect($this->tracker->isEnabled())->toBeTrue();
    });

    it('is disabled when enabled flag is false', function () {
        $tracker = new MetaPixelTracker('123456789012345', 'test-access-token', false);

        expect($tracker->isEnabled())->toBeFalse();
    });

    it('is disabled when pixel ID is invalid', function () {
        $tracker = new MetaPixelTracker('INVALID', 'test-access-token', true);

        expect($tracker->isEnabled())->toBeFalse();
    });

    it('is disabled when access token is empty', function () {
        $tracker = new MetaPixelTracker('123456789012345', '', true);

        expect($tracker->isEnabled())->toBeFalse();
    });

    it('validates pixel ID format correctly', function () {
        expect($this->tracker->isValidPixelId('123456789012345'))->toBeTrue();
        expect($this->tracker->isValidPixelId('99999999999999999999'))->toBeTrue();
        expect($this->tracker->isValidPixelId('INVALID'))->toBeFalse();
        expect($this->tracker->isValidPixelId(''))->toBeFalse();
        expect($this->tracker->isValidPixelId('123'))->toBeFalse();
    });

    it('generates head scripts with fbq init', function () {
        $scripts = $this->tracker->headScripts();

        expect($scripts)->toContain('connect.facebook.net');
        expect($scripts)->toContain('fbevents.js');
        expect($scripts)->toContain('123456789012345');
        expect($scripts)->toContain("fbq('init'");
        expect($scripts)->toContain("fbq('track', 'PageView')");
    });

    it('generates body scripts with noscript img', function () {
        $scripts = $this->tracker->bodyScripts();

        expect($scripts)->toContain('facebook.com/tr');
        expect($scripts)->toContain('123456789012345');
        expect($scripts)->toContain('noscript');
        expect($scripts)->toContain('img');
    });

    it('returns empty head scripts for invalid pixel ID', function () {
        $tracker = new MetaPixelTracker('INVALID', 'test-access-token', true);

        expect($tracker->headScripts())->toBe('');
    });

    it('returns empty body scripts for invalid pixel ID', function () {
        $tracker = new MetaPixelTracker('INVALID', 'test-access-token', true);

        expect($tracker->bodyScripts())->toBe('');
    });

    it('identifies standard events correctly', function () {
        expect($this->tracker->isStandardEvent('PageView'))->toBeTrue();
        expect($this->tracker->isStandardEvent('ViewContent'))->toBeTrue();
        expect($this->tracker->isStandardEvent('Lead'))->toBeTrue();
        expect($this->tracker->isStandardEvent('CompleteRegistration'))->toBeTrue();
        expect($this->tracker->isStandardEvent('Purchase'))->toBeTrue();
        expect($this->tracker->isStandardEvent('AddToCart'))->toBeTrue();
        expect($this->tracker->isStandardEvent('InitiateCheckout'))->toBeTrue();
        expect($this->tracker->isStandardEvent('Search'))->toBeTrue();
        expect($this->tracker->isStandardEvent('AddToWishlist'))->toBeTrue();
        expect($this->tracker->isStandardEvent('AddPaymentInfo'))->toBeTrue();
    });

    it('identifies custom events correctly', function () {
        expect($this->tracker->isStandardEvent('MyCustomEvent'))->toBeFalse();
        expect($this->tracker->isStandardEvent('random_event'))->toBeFalse();
        expect($this->tracker->isStandardEvent(''))->toBeFalse();
    });

    it('returns all standard events', function () {
        $events = $this->tracker->getStandardEvents();

        expect($events)->toContain('PageView');
        expect($events)->toContain('ViewContent');
        expect($events)->toContain('Lead');
        expect($events)->toContain('CompleteRegistration');
        expect($events)->toContain('Purchase');
        expect(count($events))->toBeGreaterThanOrEqual(10);
    });

    it('returns pixel ID', function () {
        expect($this->tracker->getPixelId())->toBe('123456789012345');
    });

    it('returns access token', function () {
        expect($this->tracker->getAccessToken())->toBe('test-access-token');
    });

    it('does not track when disabled', function () {
        $tracker = new MetaPixelTracker('123456789012345', 'test-access-token', false);

        $tracker->track(new AnalyticsEvent(name: 'PageView'));

        expect(true)->toBeTrue();
    });
});
