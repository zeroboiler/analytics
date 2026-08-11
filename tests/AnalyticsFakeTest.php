<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Support\AnalyticsFake;

beforeEach(function (): void {
    $this->fake = new AnalyticsFake;
});

describe('AnalyticsFake — basic event tracking', function (): void {
    test('intercepts track() calls', function (): void {
        $this->fake->track('sign_up', ['method' => 'email']);

        expect($this->fake->eventCount())->toBe(1);
        expect($this->fake->trackedEvents('sign_up'))->toHaveCount(1);
    });

    test('intercepts trackEvent() calls', function (): void {
        $event = new AnalyticsEvent('purchase', ['value' => 99.99]);
        $this->fake->trackEvent($event);

        expect($this->fake->eventCount())->toBe(1);
        expect($this->fake->trackedEvents('purchase')[0]->params['value'])->toBe(99.99);
    });

    test('intercepts trackEcommerce() calls', function (): void {
        $this->fake->trackEcommerce('purchase', ['value' => 49.99], ['currency' => 'USD']);

        expect($this->fake->eventCount())->toBe(1);
        expect($this->fake->trackedEvents('purchase')[0]->params)->toHaveKey('value');
    });

    test('accumulates multiple events', function (): void {
        $this->fake->track('page_view', ['url' => '/home']);
        $this->fake->track('sign_up');
        $this->fake->track('login', ['user_id' => '1']);

        expect($this->fake->eventCount())->toBe(3);
        expect($this->fake->eventCounts())->toEqual([
            'page_view' => 1,
            'sign_up' => 1,
            'login' => 1,
        ]);
    });

    test('allEvents returns all dispatched events in order', function (): void {
        $this->fake->track('a');
        $this->fake->track('b');
        $this->fake->track('c');

        $names = array_map(fn (AnalyticsEvent $e): string => $e->name, $this->fake->allEvents());

        expect($names)->toEqual(['a', 'b', 'c']);
    });
});

describe('AnalyticsFake — identity tracking', function (): void {
    test('intercepts identify() calls', function (): void {
        $this->fake->identify('user_42', 'client_abc', ['plan' => 'pro']);

        expect($this->fake->identifyCalls())->toHaveCount(1);
        expect($this->fake->identifyCalls()[0])->toEqual([
            'userId' => 'user_42',
            'clientId' => 'client_abc',
            'traits' => ['plan' => 'pro'],
        ]);
    });

    test('accumulates multiple identify calls', function (): void {
        $this->fake->identify('user_1');
        $this->fake->identify('user_2');

        expect($this->fake->identifyCalls())->toHaveCount(2);
    });
});

describe('AnalyticsFake — consent tracking', function (): void {
    test('intercepts setConsent() calls', function (): void {
        $this->fake->setConsent(ConsentState::denied());
        $this->fake->setConsent(ConsentState::granted());

        expect($this->fake->getConsent()->adStorage)->toBeTrue();
    });

    test('returns granted consent by default when none set', function (): void {
        expect($this->fake->getConsent()->adStorage)->toBeTrue();
    });
});

describe('AnalyticsFake — page view tracking', function (): void {
    test('captures page views separately', function (): void {
        $this->fake->track('page_view', ['url' => '/home']);
        $this->fake->track('sign_up');

        expect($this->fake->pageViews())->toHaveCount(1);
        expect($this->fake->pageViews()[0]->params['url'])->toBe('/home');
        expect($this->fake->eventCount())->toBe(2);
    });

    test('pageViews() is empty when no page views tracked', function (): void {
        $this->fake->track('sign_up');

        expect($this->fake->pageViews())->toHaveCount(0);
    });
});

describe('AnalyticsFake — reset', function (): void {
    test('clears all captured state', function (): void {
        $this->fake->track('sign_up');
        $this->fake->identify('user_1');
        $this->fake->setConsent(ConsentState::denied());

        $this->fake->reset();

        expect($this->fake->eventCount())->toBe(0);
        expect($this->fake->identifyCalls())->toHaveCount(0);
        expect($this->fake->pageViews())->toHaveCount(0);
        expect($this->fake->getConsent()->adStorage)->toBeTrue();
    });
});

describe('AnalyticsFake — assertTracked (via container)', function (): void {
    test('assertTracked passes when event was tracked', function (): void {
        $this->fake->track('sign_up');
        app()->instance('zeroboiler.analytics', $this->fake);

        AnalyticsFake::assertTracked('sign_up');
    });

    test('assertTracked passes with callback match', function (): void {
        $this->fake->track('purchase', ['value' => 99.99]);
        $this->fake->track('purchase', ['value' => 49.99]);
        app()->instance('zeroboiler.analytics', $this->fake);

        AnalyticsFake::assertTracked('purchase', function (AnalyticsEvent $e): bool {
            return ($e->params['value'] ?? 0) > 50;
        });
    });

    test('assertTracked fails when event was not tracked', function (): void {
        $this->fake->track('login');
        app()->instance('zeroboiler.analytics', $this->fake);

        expect(fn (): mixed => AnalyticsFake::assertTracked('sign_up'))
            ->toThrow(\Throwable::class);
    });

    test('assertTracked fails when callback does not match', function (): void {
        $this->fake->track('purchase', ['value' => 10]);
        app()->instance('zeroboiler.analytics', $this->fake);

        expect(fn (): mixed => AnalyticsFake::assertTracked('purchase', fn (AnalyticsEvent $e): bool => ($e->params['value'] ?? 0) > 50))
            ->toThrow(\Throwable::class);
    });
});

describe('AnalyticsFake — assertNotTracked', function (): void {
    test('passes when event was not tracked', function (): void {
        $this->fake->track('login');
        app()->instance('zeroboiler.analytics', $this->fake);

        AnalyticsFake::assertNotTracked('sign_up');
    });

    test('fails when event was tracked', function (): void {
        $this->fake->track('sign_up');
        app()->instance('zeroboiler.analytics', $this->fake);

        expect(fn (): mixed => AnalyticsFake::assertNotTracked('sign_up'))
            ->toThrow(\Throwable::class);
    });
});

describe('AnalyticsFake — assertTrackedTimes', function (): void {
    test('passes when tracked exact number of times', function (): void {
        $this->fake->track('page_view');
        $this->fake->track('page_view');
        $this->fake->track('page_view');
        app()->instance('zeroboiler.analytics', $this->fake);

        AnalyticsFake::assertTrackedTimes('page_view', 3);
    });

    test('fails when tracked different number of times', function (): void {
        $this->fake->track('page_view');
        app()->instance('zeroboiler.analytics', $this->fake);

        expect(fn (): mixed => AnalyticsFake::assertTrackedTimes('page_view', 3))
            ->toThrow(\Throwable::class);
    });
});

describe('AnalyticsFake — assertNothingTracked', function (): void {
    test('passes when nothing was tracked', function (): void {
        app()->instance('zeroboiler.analytics', $this->fake);

        AnalyticsFake::assertNothingTracked();
    });

    test('fails when events were tracked', function (): void {
        $this->fake->track('sign_up');
        app()->instance('zeroboiler.analytics', $this->fake);

        expect(fn (): mixed => AnalyticsFake::assertNothingTracked())
            ->toThrow(\Throwable::class);
    });
});

describe('AnalyticsFake — type safety', function (): void {
    test('all return types are correct', function (): void {
        expect($this->fake->allEvents())->toBeArray();
        expect($this->fake->trackedEvents('test'))->toBeArray();
        expect($this->fake->eventCount())->toBeInt();
        expect($this->fake->eventCounts())->toBeArray();
        expect($this->fake->identifyCalls())->toBeArray();
        expect($this->fake->pageViews())->toBeArray();
        expect($this->fake->isDebug())->toBeBool();
        expect($this->fake->shouldLogEvents())->toBeBool();
        expect($this->fake->getConsent())->toBeInstanceOf(ConsentState::class);
    });

    test('class is final', function (): void {
        $reflection = new ReflectionClass(AnalyticsFake::class);

        expect($reflection->isFinal())->toBeTrue();
    });
});
