<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\Log;
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
use ZeroBoiler\Analytics\Events\Engagement\ErrorEvent;
use ZeroBoiler\Analytics\Events\Engagement\FormSubmitEvent;
use ZeroBoiler\Analytics\Events\Engagement\SearchEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\PurchaseEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\RefundEvent;

/**
 * Config-driven lifecycle event mapping service.
 *
 * Provides a declarative way to map application events (Laravel events,
 * model events, custom dispatched events) to ZeroBoiler analytics events.
 * Supports parameter extraction, conditional mapping, and priority ordering.
 *
 * Configuration is read from `zeroboiler.analytics.lifecycle_map`.
 *
 * @see \ZeroBoiler\Analytics\Tracking\ServerSideTracker
 */
final class LifecycleEventMapper
{
    /**
     * Built-in lifecycle mapping templates for common SaaS patterns.
     *
     * @var array<string, array{source: string, target: class-string<AnalyticsEvent>, params_extractor?: string, condition?: string, priority?: int}>
     */
    private const DEFAULT_MAPPINGS = [
        // ── Authentication Lifecycle ───────────────────────────────
        'auth.login' => [
            'source' => 'Illuminate\\Auth\\Events\\Login',
            'target' => LoginEvent::class,
            'params_extractor' => 'extractAuthParams',
            'priority' => 100,
        ],
        'auth.register' => [
            'source' => 'Illuminate\\Auth\\Events\\Registered',
            'target' => SignUpEvent::class,
            'params_extractor' => 'extractRegisterParams',
            'priority' => 100,
        ],
        'auth.logout' => [
            'source' => 'Illuminate\\Auth\\Events\\Logout',
            'target' => LogoutEvent::class,
            'params_extractor' => 'extractLogoutParams',
            'priority' => 50,
        ],

        // ── Subscription Lifecycle ──────────────────────────────────
        'subscription.created' => [
            'source' => 'subscription.created',
            'target' => SubscriptionEvent::class,
            'params_extractor' => 'extractSubscriptionParams',
            'priority' => 90,
        ],
        'subscription.upgraded' => [
            'source' => 'subscription.upgraded',
            'target' => PlanUpgradeEvent::class,
            'params_extractor' => 'extractPlanChangeParams',
            'priority' => 90,
        ],
        'subscription.downgraded' => [
            'source' => 'subscription.downgraded',
            'target' => PlanDowngradeEvent::class,
            'params_extractor' => 'extractPlanChangeParams',
            'priority' => 90,
        ],
        'subscription.cancelled' => [
            'source' => 'subscription.cancelled',
            'target' => CancellationEvent::class,
            'params_extractor' => 'extractCancellationParams',
            'priority' => 90,
        ],

        // ── Trial Lifecycle ─────────────────────────────────────────
        'trial.started' => [
            'source' => 'trial.started',
            'target' => TrialStartEvent::class,
            'params_extractor' => 'extractTrialParams',
            'priority' => 85,
        ],
        'trial.ended' => [
            'source' => 'trial.ended',
            'target' => TrialEndEvent::class,
            'params_extractor' => 'extractTrialParams',
            'priority' => 85,
        ],

        // ── Feature Usage ──────────────────────────────────────────
        'feature.used' => [
            'source' => 'feature.used',
            'target' => FeatureUsedEvent::class,
            'params_extractor' => 'extractFeatureParams',
            'priority' => 80,
        ],

        // ── E-commerce Lifecycle ──────────────────────────────────
        'order.completed' => [
            'source' => 'order.completed',
            'target' => PurchaseEvent::class,
            'params_extractor' => 'extractPurchaseParams',
            'priority' => 95,
        ],
        'order.refunded' => [
            'source' => 'order.refunded',
            'target' => RefundEvent::class,
            'params_extractor' => 'extractRefundParams',
            'priority' => 95,
        ],

        // ── Engagement Lifecycle ───────────────────────────────────
        'form.submitted' => [
            'source' => 'form.submitted',
            'target' => FormSubmitEvent::class,
            'params_extractor' => 'extractFormParams',
            'priority' => 70,
        ],
        'search.performed' => [
            'source' => 'search.performed',
            'target' => SearchEvent::class,
            'params_extractor' => 'extractSearchParams',
            'priority' => 70,
        ],
        'error.occurred' => [
            'source' => 'error.occurred',
            'target' => ErrorEvent::class,
            'params_extractor' => 'extractErrorParams',
            'priority' => 60,
        ],
    ];

    /** @var array<string, array{source: string, target: string, params_extractor?: string, condition?: string, priority?: int}> */
    private array $activeMappings = [];

    /** @var array<string, bool> */
    private array $enabledToggles = [];

    private bool $enabled;

    private AnalyticsManager $manager;

    /**
     * @param  AnalyticsManager  $manager
     * @param  ConfigRepository  $config
     */
    public function __construct(AnalyticsManager $manager, ConfigRepository $config): void
    {
        $this->manager = $manager;

        $lifecycleConfig = $config->get('zeroboiler.analytics.lifecycle', []);
        /** @var array{enabled?: bool, events?: array<string, bool>, custom_mappings?: array<string, array{source: string, target: string, params_extractor?: string, condition?: string, priority?: int}>, override_defaults?: bool} $lifecycleConfig */

        $this->enabled = (bool) ($lifecycleConfig['enabled'] ?? true);
        $this->enabledToggles = $lifecycleConfig['events'] ?? [];

        // Build active mappings: defaults + custom
        $overrideDefaults = (bool) ($lifecycleConfig['override_defaults'] ?? false);

        if ($overrideDefaults) {
            $this->activeMappings = $lifecycleConfig['custom_mappings'] ?? [];
        } else {
            $this->activeMappings = self::DEFAULT_MAPPINGS;

            // Merge custom mappings (can override defaults by key)
            $customMappings = $lifecycleConfig['custom_mappings'] ?? [];
            foreach ($customMappings as $key => $mapping) {
                $this->activeMappings[$key] = $mapping;
            }
        }

        // Sort by priority (highest first)
        uasort($this->activeMappings, function (array $a, array $b): int {
            return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
        });
    }

    /**
     * Register all lifecycle event listeners on the dispatcher.
     *
     * @param  EventDispatcher  $dispatcher
     */
    public function register(EventDispatcher $dispatcher): void
    {
        if (! $this->enabled) {
            return;
        }

        foreach ($this->activeMappings as $eventKey => $mapping) {
            if (! $this->isMappingEnabled($eventKey)) {
                continue;
            }

            $this->registerMapping($dispatcher, $eventKey, $mapping);
        }
    }

    /**
     * Register a single event mapping.
     *
     * @param  EventDispatcher  $dispatcher
     * @param  string  $eventKey
     * @param  array{source: string, target: string, params_extractor?: string, condition?: string, priority?: int}  $mapping
     */
    public function registerMapping(EventDispatcher $dispatcher, string $eventKey, array $mapping): void
    {
        $source = $mapping['source'];
        $target = $mapping['target'];
        $extractor = $mapping['params_extractor'] ?? null;
        $condition = $mapping['condition'] ?? null;

        $manager = $this->manager;

        $dispatcher->listen($source, function (mixed $payload) use ($manager, $target, $extractor, $condition, $eventKey): void {
            // Check conditional filter
            if ($condition !== null && is_string($condition) && method_exists($this, $condition)) {
                if (! $this->{$condition}($payload)) {
                    return;
                }
            }

            try {
                $event = $this->buildEvent($target, $payload, $extractor);
                $manager->trackEvent($event);
            } catch (\Throwable $e) {
                try {
                    Log::warning('LifecycleEventMapper: failed to map event', [
                        'key' => $eventKey,
                        'target' => $target,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {
                    // Log facade unavailable
                }
            }
        });
    }

    /**
     * Build an analytics event from a mapping target and payload.
     *
     * @param  string  $targetClass
     * @param  mixed  $payload
     * @param  string|null  $extractor
     */
    private function buildEvent(string $targetClass, mixed $payload, ?string $extractor): AnalyticsEvent
    {
        // Use named extractor method if available
        if ($extractor !== null && method_exists($this, $extractor)) {
            return $this->{$extractor}($targetClass, $payload);
        }

        // If payload is already an AnalyticsEvent, return it
        if ($payload instanceof AnalyticsEvent) {
            return $payload;
        }

        // Try to construct with payload as named params
        if (is_array($payload)) {
            return $this->constructWithParams($targetClass, $payload);
        }

        // Try reflection-based construction
        return $this->constructWithReflection($targetClass, $payload);
    }

    /**
     * Construct an event class with an associative array of params.
     *
     * @param  string  $class
     * @param  array<string, mixed>  $params
     */
    private function constructWithParams(string $class, array $params): AnalyticsEvent
    {
        if (! class_exists($class)) {
            return new AnalyticsEvent(name: 'unknown_lifecycle', params: $params);
        }

        // Convert snake_case keys to camelCase for constructor matching
        $camelParams = [];
        foreach ($params as $key => $value) {
            $camelParams[str_replace('_', '', ucwords($key, '_'))] = $value;
        }

        try {
            return new $class(...$camelParams);
        } catch (\Throwable) {
            // Fallback: generic AnalyticsEvent
            $eventName = $this->extractEventName($class);

            return new AnalyticsEvent(name: $eventName, params: $params);
        }
    }

    /**
     * Construct an event using reflection to match constructor params.
     *
     * @param  string  $class
     * @param  mixed  $payload
     */
    private function constructWithReflection(string $class, mixed $payload): AnalyticsEvent
    {
        if (! class_exists($class)) {
            return new AnalyticsEvent(name: 'unknown_lifecycle', params: []);
        }

        try {
            $reflection = new \ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return new $class;
            }

            $args = [];
            foreach ($constructor->getParameters() as $param) {
                $name = $param->getName();

                if (is_object($payload) && property_exists($payload, $name)) {
                    $args[] = $payload->{$name};
                } elseif (is_array($payload) && isset($payload[$name])) {
                    $args[] = $payload[$name];
                } elseif (is_object($payload) && method_exists($payload, $this->getterName($name))) {
                    $args[] = $payload->{$this->getterName($name)}();
                } else {
                    $args[] = $param->getDefaultValue();
                }
            }

            return new $class(...$args);
        } catch (\Throwable) {
            return new AnalyticsEvent(
                name: $this->extractEventName($class),
                params: is_array($payload) ? $payload : ['raw' => true],
            );
        }
    }

    /**
     * Extract the event name from a class by stripping namespace.
     */
    private function extractEventName(string $class): string
    {
        $short = (new \ReflectionClass($class))->getShortName();

        // Convert PascalCase to snake_case
        return strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', $short) ?? $short);
    }

    /**
     * Convert a property name to a getter method name.
     */
    private function getterName(string $name): string
    {
        return 'get' . ucfirst($name);
    }

    /**
     * Check if a mapping is enabled via config toggles.
     */
    private function isMappingEnabled(string $eventKey): bool
    {
        if (empty($this->enabledToggles)) {
            return true;
        }

        return (bool) ($this->enabledToggles[$eventKey] ?? true);
    }

    // ── Param Extractors ───────────────────────────────────────────

    /**
     * Extract params from Illuminate Auth Login event.
     */
    private function extractAuthParams(string $class, mixed $payload): AnalyticsEvent
    {
        $method = '';

        if (is_object($payload)) {
            $method = property_exists($payload, 'guard')
                ? (string) $payload->guard
                : '';
        }

        return new LoginEvent(method: $method);
    }

    /**
     * Extract params from Illuminate Auth Registered event.
     */
    private function extractRegisterParams(string $class, mixed $payload): AnalyticsEvent
    {
        return new SignUpEvent(method: 'default');
    }

    /**
     * Extract params from Illuminate Auth Logout event.
     */
    private function extractLogoutParams(string $class, mixed $payload): AnalyticsEvent
    {
        return new LogoutEvent;
    }

    /**
     * Extract params from subscription events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractSubscriptionParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new SubscriptionEvent(
            planName: (string) ($params['plan_name'] ?? $params['planName'] ?? ''),
            price: (float) ($params['price'] ?? 0.0),
            currency: (string) ($params['currency'] ?? 'USD'),
            userId: (string) ($params['user_id'] ?? $params['userId'] ?? ''),
        );
    }

    /**
     * Extract params from plan upgrade/downgrade events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractPlanChangeParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return match ($class) {
            PlanUpgradeEvent::class => new PlanUpgradeEvent(
                fromPlan: (string) ($params['from_plan'] ?? $params['fromPlan'] ?? ''),
                toPlan: (string) ($params['to_plan'] ?? $params['toPlan'] ?? ''),
                userId: (string) ($params['user_id'] ?? $params['userId'] ?? ''),
            ),
            PlanDowngradeEvent::class => new PlanDowngradeEvent(
                fromPlan: (string) ($params['from_plan'] ?? $params['fromPlan'] ?? ''),
                toPlan: (string) ($params['to_plan'] ?? $params['toPlan'] ?? ''),
                userId: (string) ($params['user_id'] ?? $params['userId'] ?? ''),
            ),
            default => new AnalyticsEvent(name: 'plan_change', params: $params),
        };
    }

    /**
     * Extract params from cancellation events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractCancellationParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new CancellationEvent(
            reason: (string) ($params['reason'] ?? ''),
            planName: (string) ($params['plan_name'] ?? $params['planName'] ?? ''),
            userId: (string) ($params['user_id'] ?? $params['userId'] ?? ''),
        );
    }

    /**
     * Extract params from trial events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractTrialParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return match ($class) {
            TrialStartEvent::class => new TrialStartEvent(
                planName: (string) ($params['plan_name'] ?? $params['planName'] ?? ''),
                trialDays: (int) ($params['trial_days'] ?? $params['trialDays'] ?? 14),
                userId: (string) ($params['user_id'] ?? $params['userId'] ?? ''),
            ),
            TrialEndEvent::class => new TrialEndEvent(
                outcome: (string) ($params['outcome'] ?? 'expired'),
                planName: (string) ($params['plan_name'] ?? $params['planName'] ?? ''),
                userId: (string) ($params['user_id'] ?? $params['userId'] ?? ''),
            ),
            default => new AnalyticsEvent(name: 'trial_event', params: $params),
        };
    }

    /**
     * Extract params from feature usage events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractFeatureParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new FeatureUsedEvent(
            featureName: (string) ($params['feature_name'] ?? $params['featureName'] ?? ''),
            category: (string) ($params['category'] ?? ''),
            userId: (string) ($params['user_id'] ?? $params['userId'] ?? ''),
        );
    }

    /**
     * Extract params from purchase events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractPurchaseParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new PurchaseEvent(
            transactionId: (string) ($params['transaction_id'] ?? $params['transactionId'] ?? ''),
            value: (float) ($params['value'] ?? $params['revenue'] ?? 0.0),
            currency: (string) ($params['currency'] ?? 'USD'),
            items: (array) ($params['items'] ?? []),
        );
    }

    /**
     * Extract params from refund events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractRefundParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new RefundEvent(
            transactionId: (string) ($params['transaction_id'] ?? $params['transactionId'] ?? ''),
            value: (float) ($params['value'] ?? 0.0),
            currency: (string) ($params['currency'] ?? 'USD'),
            reason: (string) ($params['reason'] ?? ''),
        );
    }

    /**
     * Extract params from form submit events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractFormParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new FormSubmitEvent(
            formId: (string) ($params['form_id'] ?? $params['formId'] ?? ''),
            formName: (string) ($params['form_name'] ?? $params['formName'] ?? ''),
        );
    }

    /**
     * Extract params from search events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractSearchParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new SearchEvent(
            query: (string) ($params['query'] ?? $params['search_term'] ?? ''),
            resultsCount: (int) ($params['results_count'] ?? $params['resultsCount'] ?? 0),
            category: (string) ($params['category'] ?? ''),
        );
    }

    /**
     * Extract params from error events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractErrorParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new ErrorEvent(
            message: (string) ($params['message'] ?? $params['error_message'] ?? ''),
            source: (string) ($params['source'] ?? ''),
            severity: (string) ($params['severity'] ?? 'error'),
        );
    }

    /**
     * Convert a payload to an associative array.
     *
     * @return array<string, mixed>
     */
    private function payloadToArray(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_object($payload) && method_exists($payload, 'toArray')) {
            return $payload->toArray();
        }

        if (is_object($payload)) {
            return get_object_vars($payload);
        }

        return [];
    }

    // ── Conditional Filters ──────────────────────────────────────

    /**
     * Example condition: only track if user is authenticated.
     */
    private function requireAuth(mixed $payload): bool
    {
        if (is_object($payload) && property_exists($payload, 'user')) {
            return $payload->user !== null;
        }

        if (is_array($payload) && isset($payload['user_id'])) {
            return $payload['user_id'] !== null && $payload['user_id'] !== '';
        }

        return false;
    }

    /**
     * Get all active mappings.
     *
     * @return array<string, array{source: string, target: string, priority: int, enabled: bool}>
     */
    public function getMappings(): array
    {
        $result = [];

        foreach ($this->activeMappings as $key => $mapping) {
            $result[$key] = [
                'source' => $mapping['source'],
                'target' => $mapping['target'],
                'priority' => $mapping['priority'] ?? 0,
                'enabled' => $this->isMappingEnabled($key),
            ];
        }

        return $result;
    }

    /**
     * Get the number of registered mappings.
     */
    public function count(): int
    {
        return count($this->activeMappings);
    }

    /**
     * Check if the lifecycle mapper is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the event keys that are currently enabled.
     *
     * @return list<string>
     */
    public function enabledEventKeys(): array
    {
        $keys = [];

        foreach (array_keys($this->activeMappings) as $key) {
            if ($this->isMappingEnabled($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Get a summary of the lifecycle mapper state.
     *
     * @return array{enabled: bool, total_mappings: int, enabled_count: int, categories: array<string, int>, event_keys: list<string>}
     */
    public function summary(): array
    {
        $categories = [];
        $keys = $this->enabledEventKeys();

        foreach ($keys as $key) {
            $parts = explode('.', $key);
            $category = $parts[0] ?? 'unknown';
            $categories[$category] = ($categories[$category] ?? 0) + 1;
        }

        return [
            'enabled' => $this->enabled,
            'total_mappings' => $this->count(),
            'enabled_count' => count($keys),
            'categories' => $categories,
            'event_keys' => $keys,
        ];
    }
}
