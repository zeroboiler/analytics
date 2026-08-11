<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;

/**
 * Analytics test fake — intercepts all dispatched analytics events.
 *
 * Drop-in replacement for the Analytics facade in tests. Provides fluent
 * assertion API modeled after Laravel's MailFake, NotificationFake, and BusFake.
 *
 * Usage in Pest tests:
 *   use ZeroBoiler\Analytics\Facades\Analytics;
 *   use ZeroBoiler\Analytics\Support\AnalyticsFake;
 *
 *   beforeEach(function () {
 *       app()->instance('zeroboiler.analytics', new AnalyticsFake);
 *       // Or use the WithAnalyticsFake trait:
 *       // $this->withAnalyticsFake();
 *   });
 *
 *   test('signup event is tracked', function () {
 *       app('zeroboiler.analytics')->track('sign_up', ['method' => 'email']);
 *       AnalyticsFake::assertTracked('sign_up');
 *   });
 *
 * Or with the Facade helper:
 *   beforeEach(function () {
 *       Analytics::swap(new AnalyticsFake);
 *   });
 *
 *   test('purchase is tracked', function () {
 *       app('zeroboiler.analytics')->track('purchase', ['value' => 99.99]);
 *       AnalyticsFake::assertTracked('purchase');
 *   });
 *
 * @since 10.4.0
 */
final class AnalyticsFake
{
    /**
     * All dispatched analytics events, in dispatch order.
     *
     * @var list<AnalyticsEvent>
     */
    private array $events = [];

    /**
     * Identity calls captured for assertion.
     *
     * @var list<array{userId: string, clientId: string|null, traits: array<string, mixed>}>
     */
    private array $identifyCalls = [];

    /**
     * Consent state history.
     *
     * @var list<ConsentState>
     */
    private array $consentHistory = [];

    /**
     * Page view events captured separately for specific assertions.
     *
     * @var list<AnalyticsEvent>
     */
    private array $pageViews = [];

    /**
     * Track an analytics event (intercepts the call).
     */
    public function track(string $eventName, array $params = []): void
    {
        $this->trackEvent(new AnalyticsEvent($eventName, $params));
    }

    /**
     * Track an analytics event from a DTO object (intercepts the call).
     */
    public function trackEvent(AnalyticsEvent $event): void
    {
        $this->events[] = $event;

        if ($event->name === 'page_view') {
            $this->pageViews[] = $event;
        }
    }

    /**
     * Track an e-commerce analytics event (intercepts the call).
     */
    public function trackEcommerce(string $eventName, array $data = [], array $params = []): void
    {
        $this->track($eventName, array_merge($data, $params));
    }

    /**
     * Identify a user (intercepts the call).
     */
    public function identify(string $userId, ?string $clientId = null, array $traits = []): void
    {
        $this->identifyCalls[] = [
            'userId' => $userId,
            'clientId' => $clientId,
            'traits' => $traits,
        ];
    }

    /**
     * Set consent state (intercepts the call).
     */
    public function setConsent(ConsentState $state): void
    {
        $this->consentHistory[] = $state;
    }

    /**
     * Get current consent state.
     */
    public function getConsent(): ConsentState
    {
        return $this->consentHistory[array_key_last($this->consentHistory) ?? 0]
            ?? ConsentState::granted();
    }

    /**
     * Safe no-op for debug queries.
     */
    public function isDebug(): bool
    {
        return false;
    }

    /**
     * Safe no-op for event logging queries.
     */
    public function shouldLogEvents(): bool
    {
        return false;
    }

    // ─── Fluent Assertions ──────────────────────────────────────────

    /**
     * Assert that a given event name was tracked at least once.
     *
     * @param  callable(AnalyticsEvent): bool|null  $callback  Optional filter callback
     */
    public static function assertTracked(string $eventName, ?callable $callback = null): void
    {
        $fake = self::instance();
        $events = $fake->trackedEvents($eventName);

        if ($callback !== null) {
            $matching = array_filter($events, $callback);
            assert(
                count($matching) > 0,
                'The analytics event [' . $eventName . '] was tracked but no events matched the given callback.',
            );
        } else {
            assert(
                count($events) > 0,
                'The expected analytics event [' . $eventName . '] was not tracked. Events tracked: '
                    . implode(', ', array_column($fake->events, 'name')),
            );
        }
    }

    /**
     * Assert that a given event name was NOT tracked.
     */
    public static function assertNotTracked(string $eventName): void
    {
        $fake = self::instance();

        assert(
            count($fake->trackedEvents($eventName)) === 0,
            "The unexpected analytics event [{$eventName}] was tracked.",
        );
    }

    /**
     * Assert that a given event was tracked exactly N times.
     */
    public static function assertTrackedTimes(string $eventName, int $times): void
    {
        $fake = self::instance();
        $count = count($fake->trackedEvents($eventName));

        assert(
            $count === $times,
            "The analytics event [{$eventName}] was tracked {$count} times, expected {$times} times.",
        );
    }

    /**
     * Assert that no analytics events were dispatched at all.
     */
    public static function assertNothingTracked(): void
    {
        $fake = self::instance();

        assert(
            count($fake->events) === 0,
            'Analytics events were tracked when none were expected: '
                . implode(', ', array_column($fake->events, 'name')),
        );
    }

    /**
     * Assert an identity (identify) call was made for a given user ID.
     *
     * @param  callable(array{userId: string, clientId: string|null, traits: array<string, mixed>}): bool|null  $callback
     */
    public static function assertIdentified(string $userId, ?callable $callback = null): void
    {
        $fake = self::instance();
        $calls = array_filter($fake->identifyCalls, fn (array $c): bool => $c['userId'] === $userId);

        if ($callback !== null) {
            $matching = array_filter($calls, $callback);
            assert(
                count($matching) > 0,
                "An identify call was made for user [{$userId}] but no calls matched the given callback.",
            );
        } else {
            assert(
                count($calls) > 0,
                "No identify call was made for user [{$userId}].",
            );
        }
    }

    /**
     * Assert a page view was tracked.
     *
     * @param  callable(AnalyticsEvent): bool|null  $callback
     */
    public static function assertPageViewTracked(?callable $callback = null): void
    {
        $fake = self::instance();

        if ($callback !== null) {
            $matching = array_filter($fake->pageViews, $callback);
            assert(
                count($matching) > 0,
                'A page view was tracked but no events matched the given callback.',
            );
        } else {
            assert(
                count($fake->pageViews) > 0,
                'No page view events were tracked.',
            );
        }
    }

    // ─── Inspection Methods ───────────────────────────────────────────

    /**
     * Get all dispatched analytics events.
     *
     * @return list<AnalyticsEvent>
     */
    public function allEvents(): array
    {
        return $this->events;
    }

    /**
     * Get events matching a given event name.
     *
     * @return list<AnalyticsEvent>
     */
    public function trackedEvents(string $eventName): array
    {
        return array_values(array_filter(
            $this->events,
            fn (AnalyticsEvent $e): bool => $e->name === $eventName,
        ));
    }

    /**
     * Get all identify calls.
     *
     * @return list<array{userId: string, clientId: string|null, traits: array<string, mixed>}>
     */
    public function identifyCalls(): array
    {
        return $this->identifyCalls;
    }

    /**
     * Get all captured page views.
     *
     * @return list<AnalyticsEvent>
     */
    public function pageViews(): array
    {
        return $this->pageViews;
    }

    /**
     * Get the count of total tracked events.
     */
    public function eventCount(): int
    {
        return count($this->events);
    }

    /**
     * Get event names grouped by count.
     *
     * @return array<string, int>
     */
    public function eventCounts(): array
    {
        $counts = [];
        foreach ($this->events as $event) {
            $counts[$event->name] = ($counts[$event->name] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Reset all captured state. Useful for test isolation within a single test.
     */
    public function reset(): void
    {
        $this->events = [];
        $this->identifyCalls = [];
        $this->consentHistory = [];
        $this->pageViews = [];
    }

    /**
     * Static accessor helper for static assertion methods.
     *
     * @return static
     */
    private static function instance(): static
    {
        return app('zeroboiler.analytics');
    }
}
