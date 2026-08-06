<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;

beforeEach(function () {
    $this->tracker = new GA4Tracker(
        measurementId: 'G-TEST1234',
        apiSecret: 'test-api-secret-with-20-chars',
        enabled: true,
    );
});

describe('GA4Tracker', function () {
    it('can be instantiated', function () {
        expect($this->tracker)->toBeInstanceOf(GA4Tracker::class);
    });

    it('is enabled when measurement ID and API secret are valid', function () {
        expect($this->tracker->isEnabled())->toBeTrue();
    });

    it('is disabled when not enabled flag is false', function () {
        $tracker = new GA4Tracker('G-TEST1234', 'test-api-secret-with-20-chars', false);

        expect($tracker->isEnabled())->toBeFalse();
    });

    it('is disabled when measurement ID is invalid', function () {
        $tracker = new GA4Tracker('INVALID', 'test-api-secret-with-20-chars', true);

        expect($tracker->isEnabled())->toBeFalse();
    });

    it('is disabled when API secret is empty', function () {
        $tracker = new GA4Tracker('G-TEST1234', '', true);

        expect($tracker->isEnabled())->toBeFalse();
    });

    it('validates measurement ID format correctly', function () {
        expect($this->tracker->isValidMeasurementId('G-TEST1234'))->toBeTrue();
        expect($this->tracker->isValidMeasurementId('G-ABCDEFGHIJ'))->toBeTrue();
        expect($this->tracker->isValidMeasurementId('INVALID'))->toBeFalse();
        expect($this->tracker->isValidMeasurementId(''))->toBeFalse();
        expect($this->tracker->isValidMeasurementId('g-test1234'))->toBeFalse();
    });

    it('validates API secret format correctly', function () {
        expect($this->tracker->isValidApiSecret('this-is-a-valid-secret-20chars'))->toBeTrue();
        expect($this->tracker->isValidApiSecret('short'))->toBeFalse();
        expect($this->tracker->isValidApiSecret(''))->toBeFalse();
    });

    it('generates head scripts with gtag.js', function () {
        $scripts = $this->tracker->headScripts();

        expect($scripts)->toContain('googletagmanager.com/gtag/js');
        expect($scripts)->toContain('G-TEST1234');
        expect($scripts)->toContain('gtag');
    });

    it('returns empty head scripts for invalid measurement ID', function () {
        $tracker = new GA4Tracker('INVALID', 'test-api-secret-with-20-chars', true);

        expect($tracker->headScripts())->toBe('');
    });

    it('returns empty body scripts', function () {
        expect($this->tracker->bodyScripts())->toBe('');
    });

    it('returns measurement ID', function () {
        expect($this->tracker->getMeasurementId())->toBe('G-TEST1234');
    });

    it('returns API secret', function () {
        expect($this->tracker->getApiSecret())->toBe('test-api-secret-with-20-chars');
    });

    it('does not track when disabled', function () {
        $tracker = new GA4Tracker('G-TEST1234', 'test-api-secret-with-20-chars', false);

        // Should not throw any exception, just return void
        $tracker->track(new AnalyticsEvent(name: 'test_event'));

        expect(true)->toBeTrue();
    });
});
