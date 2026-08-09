<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tracking;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaS\CancellationEvent;
use ZeroBoiler\Analytics\Events\SaaS\FeatureUsedEvent;
use ZeroBoiler\Analytics\Events\SaaS\LoginEvent;
use ZeroBoiler\Analytics\Events\SaaS\LogoutEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanDowngradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanUpgradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\SignUpEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialEndEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialStartEvent;

/**
 * Auto-tracks Laravel framework events as analytics events.
 *
 * Maps Illuminate\Auth\Events and custom application events to typed
 * ZeroBoiler analytics events. Configurable via zeroboiler.analytics.auto_track.
 *
 * @since 1.0.0
 */
final class ServerSideTracker
{
    /**
     * Default mapping of Laravel event classes → ZeroBoiler analytics event classes.
     *
     * @var array<class-string, class-string>
     */
    protected array $eventMap = [
        Login::class => LoginEvent::class,
        Registered::class => SignUpEvent::class,
        Logout::class => LogoutEvent::class,
    ];

    /**
     * Custom event name → analytics event class mappings.
     * For application-specific events (e.g. SubscriptionCreated).
     *
     * @var array<string, class-string>
     */
    protected array $customEventMap = [
        'subscription.created' => SubscriptionEvent::class,
        'subscription.upgraded' => PlanUpgradeEvent::class,
        'subscription.downgraded' => PlanDowngradeEvent::class,
        'subscription.cancelled' => CancellationEvent::class,
        'subscription.renewal' => \ZeroBoiler\Analytics\Events\SaaS\SubscriptionRenewalEvent::class,
        'subscription.paused' => \ZeroBoiler\Analytics\Events\SaaS\SubscriptionPausedEvent::class,
        'subscription.value_changed' => \ZeroBoiler\Analytics\Events\SaaS\SubscriptionValueChangedEvent::class,
        'usage.quota_reached' => \ZeroBoiler\Analytics\Events\SaaS\UsageQuotaReachedEvent::class,
        'billing.retry' => \ZeroBoiler\Analytics\Events\SaaS\BillingRetryEvent::class,
        'trial.started' => TrialStartEvent::class,
        'trial.ended' => TrialEndEvent::class,
        'trial.converted' => \ZeroBoiler\Analytics\Events\SaaS\TrialConvertedEvent::class,
        'feature.used' => FeatureUsedEvent::class,
    ];

    /**
     * Additional event mappings loaded from config.
     * Merged with $customEventMap at construction time.
     *
     * @var array<string, class-string>
     */
    protected array $configEventMap = [];

    /**
     * Config key toggles for each auto-trackable event.
     *
     * @var array<string, bool>
     */
    protected array $eventToggles;

    private AnalyticsManager $manager;

    private bool $enabled;

    public function __construct(AnalyticsManager $manager, ConfigRepository $config): void
    {
        $this->manager = $manager;

        $autoTrack = $config->get('zeroboiler.analytics.auto_track', []);
        /** @var array{enabled?: bool, events?: array<string, bool>, event_map?: array<string, class-string>} $autoTrack */
        $this->enabled = (bool) ($autoTrack['enabled'] ?? true);
        $this->eventToggles = $autoTrack['events'] ?? [];

        // Load additional event → class mappings from config
        $this->configEventMap = $autoTrack['event_map'] ?? [];
    }

    /**
     * Register all event listeners on the dispatcher.
     */
    public function register(EventDispatcher $dispatcher): void
    {
        if (! $this->enabled) {
            return;
        }

        foreach ($this->eventMap as $laravelEvent => $analyticsEvent) {
            $this->registerLaravelListener($dispatcher, $laravelEvent, $analyticsEvent);
        }
    }

    /**
     * Listen for a specific custom application event name.
     *
     * Call this in your service provider boot() to register custom events:
     *   $tracker->listen('subscription.created', $dispatcher);
     */
    public function listen(string $eventName, EventDispatcher $dispatcher): void
    {
        $analyticsClass = $this->customEventMap[$eventName]
            ?? $this->configEventMap[$eventName]
            ?? null;

        if ($analyticsClass === null) {
            try {
                Log::warning('ServerSideTracker: no mapping for custom event', [
                    'event' => $eventName,
                ]);
            } catch (\Throwable) {
                // Log facade may not be available in tests
            }

            return;
        }

        if (! $this->isEventEnabled($eventName)) {
            return;
        }

        $manager = $this->manager;
        $class = $analyticsClass;

        $dispatcher->listen($eventName, function (mixed $payload) use ($manager, $class): void {
            $this->dispatchAnalyticsEvent($manager, $class, $payload);
        });
    }

    /**
     * Track an Eloquent model event as an analytics event.
     *
     * Usage in config:
     *   'models' => [
     *       App\Models\Habit::class => ['created', 'deleted'],
     *   ]
     *
     * @param  array<class-string, array<int, string>>  $modelEvents
     */
    public function registerModelListeners(array $modelEvents): void
    {
        if (! $this->enabled) {
            return;
        }

        foreach ($modelEvents as $modelClass => $actions) {
            foreach ($actions as $action) {
                $eventName = "eloquent.{$action}: {$modelClass}";
                $analyticsName = "model_{$action}";
                $manager = $this->manager;

                Event::listen($eventName, function (
                    mixed $event,
                ) use ($manager, $analyticsName, $modelClass, $action): void {
                    $model = $event instanceof Model ? $event : null;

                    $analyticsEvent = new AnalyticsEvent($analyticsName, array_filter([
                        'model' => Str::afterLast($modelClass, '\\'),
                        'action' => $action,
                        'model_id' => $model?->getKey(),
                    ]));

                    $manager->trackEvent($analyticsEvent);
                });
            }
        }
    }

    /**
     * Check if a specific event is enabled in config.
     */
    protected function isEventEnabled(string $eventKey): bool
    {
        return (bool) ($this->eventToggles[$eventKey] ?? true);
    }

    /**
     * Register a listener for a Laravel framework event.
     */
    private function registerLaravelListener(
        EventDispatcher $dispatcher,
        string $laravelEvent,
        string $analyticsEventClass,
    ): void {
        // Map Laravel event class to config key
        $configKey = $this->laravelEventToConfigKey($laravelEvent);

        if (! $this->isEventEnabled($configKey)) {
            return;
        }

        $manager = $this->manager;
        $class = $analyticsEventClass;

        $dispatcher->listen($laravelEvent, function (mixed $event) use ($manager, $class): void {
            $this->dispatchAnalyticsEvent($manager, $class, $event);
        });
    }

    /**
     * Dispatch an analytics event from a Laravel event payload.
     */
    private function dispatchAnalyticsEvent(
        AnalyticsManager $manager,
        string $analyticsEventClass,
        mixed $payload,
    ): void {
        try {
            $analyticsEvent = $this->buildAnalyticsEvent($analyticsEventClass, $payload);
            $manager->trackEvent($analyticsEvent);
        } catch (\Throwable $e) {
            Log::error('ServerSideTracker: failed to dispatch analytics event', [
                'analytics_class' => $analyticsEventClass,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build a typed analytics event from a Laravel event payload.
     */
    private function buildAnalyticsEvent(string $analyticsEventClass, mixed $payload): AnalyticsEvent
    {
        // Auth events carry a user property
        if ($payload instanceof Login) {
            /** @var AnalyticsEvent $event */
            $event = new $analyticsEventClass(method: $payload->guard);

            return $event;
        }

        if ($payload instanceof Registered) {
            return new SignUpEvent(method: 'default');
        }

        if ($payload instanceof Logout) {
            return new LogoutEvent;
        }

        // Custom application events — try to construct with the payload as params
        if (is_array($payload)) {
            return new $analyticsEventClass(...$this->extractConstructorArgs($analyticsEventClass, $payload));
        }

        if ($payload instanceof AnalyticsEvent) {
            return $payload;
        }

        // Fallback: construct with no args
        return new $analyticsEventClass;
    }

    /**
     * Extract constructor argument values from an associative array payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, mixed>
     */
    private function extractConstructorArgs(string $class, array $payload): array
    {
        if (! class_exists($class)) {
            return [];
        }

        try {
            $reflection = new \ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return [];
            }

            $args = [];
            foreach ($constructor->getParameters() as $param) {
                $name = $param->getName();
                $args[] = $payload[$name] ?? $param->getDefaultValue();
            }

            return $args;
        } catch (\Throwable $e) {
            Log::warning('ServerSideTracker: failed to extract constructor args', [
                'class' => $class,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Convert a Laravel event class FQCN to a config key.
     */
    private function laravelEventToConfigKey(string $laravelEvent): string
    {
        $map = [
            Login::class => 'auth.login',
            Registered::class => 'auth.register',
            Logout::class => 'auth.logout',
        ];

        return $map[$laravelEvent] ?? 'custom.'.$laravelEvent;
    }
}
