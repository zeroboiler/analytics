<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;

describe('ConsentState DTO', function (): void {
    it('can be created empty', function (): void {
        $state = new ConsentState([]);

        expect($state->signals)->toBe([]);
    });

    it('normalizes invalid values out', function (): void {
        $state = new ConsentState([
            'ad_storage' => 'granted',
            'analytics_storage' => 'denied',
            'bad_signal' => 'maybe',
            'another_bad' => 123,
            'null_value' => null,
        ]);

        expect($state->signals)->toHaveCount(2)
            ->and($state->signals)->toHaveKey('ad_storage')
            ->and($state->signals)->toHaveKey('analytics_storage')
            ->and($state->signals)->not->toHaveKey('bad_signal');
    });

    it('creates granted state with all signals', function (): void {
        $state = ConsentState::granted();

        expect($state->signals)->toHaveCount(7)
            ->and($state->isGranted('ad_storage'))->toBeTrue()
            ->and($state->isGranted('analytics_storage'))->toBeTrue()
            ->and($state->isGranted('ad_user_data'))->toBeTrue()
            ->and($state->isGranted('ad_personalization'))->toBeTrue()
            ->and($state->isGranted('functionality_storage'))->toBeTrue()
            ->and($state->isGranted('personalization_storage'))->toBeTrue()
            ->and($state->isGranted('security_storage'))->toBeTrue();
    });

    it('creates denied state with security_storage always granted', function (): void {
        $state = ConsentState::denied();

        expect($state->signals)->toHaveCount(7)
            ->and($state->isDenied('ad_storage'))->toBeTrue()
            ->and($state->isDenied('analytics_storage'))->toBeTrue()
            ->and($state->isDenied('ad_user_data'))->toBeTrue()
            ->and($state->isDenied('ad_personalization'))->toBeTrue()
            ->and($state->isDenied('functionality_storage'))->toBeTrue()
            ->and($state->isDenied('personalization_storage'))->toBeTrue()
            ->and($state->isGranted('security_storage'))->toBeTrue()
            ->and($state->isDenied('security_storage'))->toBeFalse();
    });

    it('checks isGranted correctly', function (): void {
        $state = new ConsentState(['analytics_storage' => 'granted']);

        expect($state->isGranted('analytics_storage'))->toBeTrue()
            ->and($state->isGranted('ad_storage'))->toBeFalse();
    });

    it('checks isDenied correctly', function (): void {
        $state = new ConsentState(['analytics_storage' => 'denied']);

        expect($state->isDenied('analytics_storage'))->toBeTrue()
            ->and($state->isDenied('ad_storage'))->toBeFalse();
    });

    it('returns false for unknown signals', function (): void {
        $state = new ConsentState([]);

        expect($state->isGranted('unknown'))->toBeFalse()
            ->and($state->isDenied('unknown'))->toBeFalse();
    });

    it('checks hasAnalyticsConsent', function (): void {
        $granted = new ConsentState(['analytics_storage' => 'granted']);
        $denied = new ConsentState(['analytics_storage' => 'denied']);
        $unset = new ConsentState([]);

        expect($granted->hasAnalyticsConsent())->toBeTrue()
            ->and($denied->hasAnalyticsConsent())->toBeFalse()
            ->and($unset->hasAnalyticsConsent())->toBeFalse();
    });

    it('checks hasAdConsent', function (): void {
        $granted = new ConsentState(['ad_storage' => 'granted']);
        $denied = new ConsentState(['ad_storage' => 'denied']);

        expect($granted->hasAdConsent())->toBeTrue()
            ->and($denied->hasAdConsent())->toBeFalse();
    });

    it('creates new state with with() merge', function (): void {
        $state = new ConsentState(['ad_storage' => 'granted']);
        $updated = $state->with(['analytics_storage' => 'granted']);

        expect($state->signals)->toHaveCount(1)
            ->and($updated->signals)->toHaveCount(2)
            ->and($updated->isGranted('ad_storage'))->toBeTrue()
            ->and($updated->isGranted('analytics_storage'))->toBeTrue();
    });

    it('with() overrides existing signals', function (): void {
        $state = new ConsentState(['ad_storage' => 'denied']);
        $updated = $state->with(['ad_storage' => 'granted']);

        expect($updated->isGranted('ad_storage'))->toBeTrue()
            ->and($state->isDenied('ad_storage'))->toBeTrue();
    });

    it('converts to array', function (): void {
        $state = new ConsentState(['ad_storage' => 'granted', 'analytics_storage' => 'denied']);

        expect($state->toArray())->toBe([
            'ad_storage' => 'granted',
            'analytics_storage' => 'denied',
        ]);
    });

    it('is immutable (readonly class)', function (): void {
        $reflection = new ReflectionClass(ConsentState::class);

        expect($reflection->isReadOnly())->toBeTrue()
            ->and($reflection->isFinal())->toBeTrue();
    });
});

describe('GA4Tracker Consent', function (): void {
    it('defaults to granted consent', function (): void {
        $tracker = new GA4Tracker('G-TEST1234', 'test-secret-20chars-long', true);

        expect($tracker->getConsent()->hasAnalyticsConsent())->toBeTrue();
    });

    it('can set denied consent', function (): void {
        $tracker = new GA4Tracker('G-TEST1234', 'test-secret-20chars-long', true);
        $tracker->setConsent(ConsentState::denied());

        expect($tracker->getConsent()->isDenied('analytics_storage'))->toBeTrue();
    });

    it('includes consent default in head scripts when denied', function (): void {
        $tracker = new GA4Tracker('G-TEST1234', 'test-secret-20chars-long', true);
        $tracker->setConsent(ConsentState::denied());

        $scripts = $tracker->headScripts();

        expect($scripts)->toContain("gtag('consent', 'default'")
            ->and($scripts)->toContain('"analytics_storage":"denied"');
    });

    it('includes consent default in head scripts when granted', function (): void {
        $tracker = new GA4Tracker('G-TEST1234', 'test-secret-20chars-long', true);
        $tracker->setConsent(ConsentState::granted());

        $scripts = $tracker->headScripts();

        expect($scripts)->toContain("gtag('consent', 'default'")
            ->and($scripts)->toContain('"analytics_storage":"granted"');
    });
});

describe('GTMTracker Consent', function (): void {
    it('defaults to granted consent', function (): void {
        $tracker = new GTMTracker('GTM-TEST12', true);

        expect($tracker->getConsent()->hasAnalyticsConsent())->toBeTrue();
    });

    it('can set denied consent', function (): void {
        $tracker = new GTMTracker('GTM-TEST12', true);
        $tracker->setConsent(ConsentState::denied());

        expect($tracker->getConsent()->isDenied('analytics_storage'))->toBeTrue();
    });

    it('includes consent default in head scripts', function (): void {
        $tracker = new GTMTracker('GTM-TEST12', true);
        $tracker->setConsent(ConsentState::denied());

        $scripts = $tracker->headScripts();

        expect($scripts)->toContain("gtag('consent', 'default'")
            ->and($scripts)->toContain('"analytics_storage":"denied"');
    });
});

describe('MetaPixelTracker Consent', function (): void {
    it('defaults to granted consent', function (): void {
        $tracker = new MetaPixelTracker('1234567890', 'test-access-token', true);

        expect($tracker->getConsent()->hasAnalyticsConsent())->toBeTrue();
    });

    it('can set denied consent', function (): void {
        $tracker = new MetaPixelTracker('1234567890', 'test-access-token', true);
        $tracker->setConsent(ConsentState::denied());

        expect($tracker->getConsent()->isDenied('analytics_storage'))->toBeTrue();
    });

    it('includes consent revoke in head scripts when denied', function (): void {
        $tracker = new MetaPixelTracker('1234567890', 'test-access-token', true);
        $tracker->setConsent(ConsentState::denied());

        $scripts = $tracker->headScripts();

        expect($scripts)->toContain("fbq('consent', 'revoke')");
    });

    it('does not include consent revoke when granted', function (): void {
        $tracker = new MetaPixelTracker('1234567890', 'test-access-token', true);
        $tracker->setConsent(ConsentState::granted());

        $scripts = $tracker->headScripts();

        expect($scripts)->not->toContain("fbq('consent', 'revoke')");
    });
});

describe('TrackerInterface Consent Contract', function (): void {
    it('all trackers implement setConsent/getConsent', function (): void {
        $trackers = [
            new GA4Tracker('G-TEST1234', 'test-secret-20chars-long', true),
            new GTMTracker('GTM-TEST12', true),
            new MetaPixelTracker('1234567890', 'test-access-token', true),
        ];

        foreach ($trackers as $tracker) {
            $tracker->setConsent(ConsentState::denied());
            expect($tracker->getConsent()->isDenied('analytics_storage'))->toBeTrue();

            $tracker->setConsent(ConsentState::granted());
            expect($tracker->getConsent()->isGranted('analytics_storage'))->toBeTrue();
        }
    });
});
