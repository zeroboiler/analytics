<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Trackers\GTMTracker;

beforeEach(function () {
    $this->tracker = new GTMTracker(
        containerId: 'GTM-TEST1',
        enabled: true,
    );
});

describe('GTMTracker', function () {
    it('can be instantiated', function () {
        expect($this->tracker)->toBeInstanceOf(GTMTracker::class);
    });

    it('is enabled when container ID is valid', function () {
        expect($this->tracker->isEnabled())->toBeTrue();
    });

    it('is disabled when enabled flag is false', function () {
        $tracker = new GTMTracker('GTM-TEST1', false);

        expect($tracker->isEnabled())->toBeFalse();
    });

    it('is disabled when container ID is invalid', function () {
        $tracker = new GTMTracker('INVALID', true);

        expect($tracker->isEnabled())->toBeFalse();
    });

    it('validates container ID format correctly', function () {
        expect($this->tracker->isValidContainerId('GTM-TEST1'))->toBeTrue();
        expect($this->tracker->isValidContainerId('GTM-ABCDEF'))->toBeTrue();
        expect($this->tracker->isValidContainerId('GTM-123456789'))->toBeTrue();
        expect($this->tracker->isValidContainerId('INVALID'))->toBeFalse();
        expect($this->tracker->isValidContainerId('gtm-test1'))->toBeFalse();
        expect($this->tracker->isValidContainerId(''))->toBeFalse();
    });

    it('generates head scripts with GTM container', function () {
        $scripts = $this->tracker->headScripts();

        expect($scripts)->toContain('googletagmanager.com/gtm.js');
        expect($scripts)->toContain('GTM-TEST1');
        expect($scripts)->toContain('gtm.start');
    });

    it('generates body scripts with noscript iframe', function () {
        $scripts = $this->tracker->bodyScripts();

        expect($scripts)->toContain('googletagmanager.com/ns.html');
        expect($scripts)->toContain('GTM-TEST1');
        expect($scripts)->toContain('iframe');
        expect($scripts)->toContain('noscript');
    });

    it('returns empty head scripts for invalid container ID', function () {
        $tracker = new GTMTracker('INVALID', true);

        expect($tracker->headScripts())->toBe('');
    });

    it('returns empty body scripts for invalid container ID', function () {
        $tracker = new GTMTracker('INVALID', true);

        expect($tracker->bodyScripts())->toBe('');
    });

    it('pushes data to dataLayer', function () {
        $this->tracker->push(['event' => 'test_event', 'value' => 100]);

        expect($this->tracker->getDataLayer())->toHaveCount(1);
        expect($this->tracker->getDataLayer()[0])->toEqual(['event' => 'test_event', 'value' => 100]);
    });

    it('pushes multiple items to dataLayer', function () {
        $this->tracker->push(['event' => 'event1']);
        $this->tracker->push(['event' => 'event2']);
        $this->tracker->push(['event' => 'event3']);

        expect($this->tracker->getDataLayer())->toHaveCount(3);
    });

    it('includes dataLayer in head scripts when data exists', function () {
        $this->tracker->push(['event' => 'page_view', 'page' => '/home']);

        $scripts = $this->tracker->headScripts();

        expect($scripts)->toContain('page_view');
        expect($scripts)->toContain('window.dataLayer.push');
    });

    it('tracks events by pushing to dataLayer', function () {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99, 'currency' => 'USD'],
        );

        $this->tracker->track($event);

        expect($this->tracker->getDataLayer())->toHaveCount(1);
        expect($this->tracker->getDataLayer()[0]['event'])->toBe('purchase');
        expect($this->tracker->getDataLayer()[0]['eventParams'])->toEqual(['value' => 99.99, 'currency' => 'USD']);
    });

    it('does not track when disabled', function () {
        $tracker = new GTMTracker('GTM-TEST1', false);

        $tracker->track(new AnalyticsEvent(name: 'test'));

        expect($tracker->getDataLayer())->toBeEmpty();
    });

    it('returns container ID', function () {
        expect($this->tracker->getContainerId())->toBe('GTM-TEST1');
    });
});
