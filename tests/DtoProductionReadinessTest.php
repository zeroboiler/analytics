<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;

describe('AnalyticsEvent', function () {
    it('creates with name only', function (): void {
        $event = new AnalyticsEvent(name: 'page_view');

        expect($event->name)->toBe('page_view')
            ->and($event->params)->toBe([])
            ->and($event->clientId)->toBeNull()
            ->and($event->userId)->toBeNull();
    });

    it('creates with all parameters', function (): void {
        $event = new AnalyticsEvent(
            name: 'purchase',
            params: ['value' => 99.99, 'currency' => 'USD'],
            clientId: 'cli-123',
            userId: 'user-456',
        );

        expect($event->name)->toBe('purchase')
            ->and($event->params)->toBe(['value' => 99.99, 'currency' => 'USD'])
            ->and($event->clientId)->toBe('cli-123')
            ->and($event->userId)->toBe('user-456');
    });

    it('serializes to array', function (): void {
        $event = new AnalyticsEvent(
            name: 'click',
            params: ['target' => 'button'],
            clientId: 'c1',
            userId: 'u1',
        );

        expect($event->toArray())->toBe([
            'name' => 'click',
            'params' => ['target' => 'button'],
            'client_id' => 'c1',
            'user_id' => 'u1',
        ]);
    });

    it('round-trips through fromArray', function (): void {
        $original = new AnalyticsEvent(
            name: 'signup',
            params: ['method' => 'email'],
            clientId: 'cli-abc',
            userId: 'user-xyz',
        );

        $restored = AnalyticsEvent::fromArray($original->toArray());

        expect($restored->name)->toBe($original->name)
            ->and($restored->params)->toBe($original->params)
            ->and($restored->clientId)->toBe($original->clientId)
            ->and($restored->userId)->toBe($original->userId);
    });

    it('fromArray handles missing fields gracefully', function (): void {
        $event = AnalyticsEvent::fromArray([]);

        expect($event->name)->toBe('')
            ->and($event->params)->toBe([])
            ->and($event->clientId)->toBeNull()
            ->and($event->userId)->toBeNull();
    });

    it('fromArray rejects non-string name', function (): void {
        $event = AnalyticsEvent::fromArray(['name' => 123]);

        expect($event->name)->toBe('');
    });

    it('fromArray rejects non-array params', function (): void {
        $event = AnalyticsEvent::fromArray(['params' => 'invalid']);

        expect($event->params)->toBe([]);
    });
});

describe('ConsentState', function () {
    it('creates granted state with all signals', function (): void {
        $state = ConsentState::granted();

        expect($state->isGranted('ad_storage'))->toBeTrue()
            ->and($state->isGranted('analytics_storage'))->toBeTrue()
            ->and($state->isGranted('security_storage'))->toBeTrue()
            ->and($state->hasAnalyticsConsent())->toBeTrue()
            ->and($state->hasAdConsent())->toBeTrue();
    });

    it('creates denied state with security_storage always granted', function (): void {
        $state = ConsentState::denied();

        expect($state->isDenied('ad_storage'))->toBeTrue()
            ->and($state->isDenied('analytics_storage'))->toBeTrue()
            ->and($state->isGranted('security_storage'))->toBeTrue() // always granted per Google spec
            ->and($state->hasAnalyticsConsent())->toBeFalse()
            ->and($state->hasAdConsent())->toBeFalse();
    });

    it('filters out invalid signal values', function (): void {
        $state = new ConsentState([
            'ad_storage' => 'granted',
            'analytics_storage' => 'invalid_value',
            'custom_signal' => null,
        ]);

        expect($state->signals)->toHaveKey('ad_storage')
            ->and($state->signals)->not->toHaveKey('analytics_storage')
            ->and($state->signals)->not->toHaveKey('custom_signal');
    });

    it('only accepts granted or denied values', function (): void {
        $state = new ConsentState([
            'ad_storage' => 'granted',
            'analytics_storage' => 'denied',
            'foo' => 'maybe',
            'bar' => 'yes',
        ]);

        expect($state->signals)->toHaveCount(2)
            ->and($state->signals)->toHaveKey('ad_storage')
            ->and($state->signals)->toHaveKey('analytics_storage');
    });

    it('isGranted returns false for unknown signals', function (): void {
        $state = ConsentState::granted();

        expect($state->isGranted('nonexistent_signal'))->toBeFalse();
    });

    it('isDenied returns false for unknown signals', function (): void {
        $state = ConsentState::denied();

        expect($state->isDenied('nonexistent_signal'))->toBeFalse();
    });

    it('with() creates a new state with merged signals', function (): void {
        $base = ConsentState::denied();
        $modified = $base->with(['analytics_storage' => 'granted']);

        expect($modified->isGranted('analytics_storage'))->toBeTrue()
            ->and($modified->isDenied('ad_storage'))->toBeTrue() // inherited from denied
            ->and($base->hasAnalyticsConsent())->toBeFalse(); // original unchanged
    });

    it('toArray returns all signals', function (): void {
        $state = ConsentState::granted();
        $array = $state->toArray();

        expect($array)->toBeArray()
            ->and($array)->toHaveKey('ad_storage')
            ->and($array['ad_storage'])->toBe('granted');
    });

    it('empty state has no signals', function (): void {
        $state = new ConsentState;

        expect($state->signals)->toBeEmpty()
            ->and($state->isGranted('ad_storage'))->toBeFalse()
            ->and($state->isDenied('ad_storage'))->toBeFalse();
    });
});
