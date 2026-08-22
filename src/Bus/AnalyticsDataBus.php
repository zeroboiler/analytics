<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Bus;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

/**
 * Analytics event routing bus for conditional provider dispatch.
 *
 * Provides rule-based routing to selectively dispatch events to specific
 * providers based on event name, category, parameters, or custom rules.
 * This enables fine-grained control over which events go to which providers
 * without modifying individual tracker implementations.
 *
 * @see \ZeroBoiler\Analytics\AnalyticsManager
 *
 * @since 1.0.0
 */
final class AnalyticsDataBus
{
    /** @var array<int, array{condition: callable, providers: list<string>}> */
    private array $rules = [];

    /** @var list<string> */
    private array $defaultProviders = ['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook'];

    private AnalyticsManager $manager;

    private QueuedAnalyticsDispatcher $queue;

    private bool $useAsync;

    /**
     * @param  AnalyticsManager  $manager
     * @param  QueuedAnalyticsDispatcher  $queue
     * @param  bool  $useAsync
     */
    public function __construct(
        AnalyticsManager $manager,
        QueuedAnalyticsDispatcher $queue,
        bool $useAsync = true,
    ){
        $this->manager = $manager;
        $this->queue = $queue;
        $this->useAsync = $useAsync;
    }

    /**
     * Add a routing rule.
     *
     * Rules are evaluated in order. The first matching rule determines
     * the target providers. If no rule matches, the event is dispatched
     * to all enabled providers (default behavior).
     *
     * @param  callable(AnalyticsEvent): bool  $condition  Returns true if this rule matches
     * @param  list<string>  $providers  Provider names to dispatch to ('ga4', 'gtm', 'meta', 'plausible', 'posthog')
     */
    public function addRule(callable $condition, array $providers): self
    {
        $this->rules[] = [
            'condition' => $condition,
            'providers' => $providers,
        ];

        return $this;
    }

    /**
     * Route an event to providers based on rules.
     *
     * Evaluates all rules in order. If a rule matches, the event is
     * dispatched only to the specified providers. If no rule matches,
     * it dispatches to all enabled providers (standard behavior).
     */
    public function route(AnalyticsEvent $event): void
    {
        $targetProviders = $this->evaluateRules($event);

        if ($targetProviders === null) {
            // No specific rule matched — dispatch to all (standard behavior)
            $this->dispatchStandard($event);

            return;
        }

        $this->dispatchToProviders($event, $targetProviders);
    }

    /**
     * Route an event only to specific providers.
     *
     * Convenience method for one-off routing without persistent rules.
     *
     * @param  list<string>  $providers  Provider names
     */
    public function routeTo(AnalyticsEvent $event, array $providers): void
    {
        $this->dispatchToProviders($event, $providers);
    }

    /**
     * Route an event to all providers except specified ones.
     *
     * @param  list<string>  $excludeProviders  Provider names to exclude
     */
    public function routeExcept(AnalyticsEvent $event, array $excludeProviders): void
    {
        $providers = array_diff($this->defaultProviders, $excludeProviders);

        $this->dispatchToProviders($event, array_values($providers));
    }

    /**
     * Add a rule that routes events by name pattern.
     *
     * @param  string  $pattern  Glob pattern (e.g. 'purchase*', 'sign_*')
     * @param  list<string>  $providers
     */
    public function routeByPattern(string $pattern, array $providers): self
    {
        return $this->addRule(
            fn (AnalyticsEvent $event): bool => Str::is($pattern, $event->name),
            $providers,
        );
    }

    /**
     * Add a rule that routes events by category.
     *
     * @param  'ecommerce'|'saas'|'engagement'|list<string>  $categories
     * @param  list<string>  $providers
     */
    public function routeByCategory(array|string $categories, array $providers): self
    {
        $categoryList = (array) $categories;

        return $this->addRule(
            function (AnalyticsEvent $event) use ($categoryList): bool {
                $entry = EventCatalog::get($event->name);

                return $entry !== null && in_array($entry['category'], $categoryList, true);
            },
            $providers,
        );
    }

    /**
     * Add a rule that routes events with specific parameter values.
     *
     * @param  string  $paramKey  Parameter key to check
     * @param  mixed  $paramValue  Expected parameter value
     * @param  list<string>  $providers
     */
    public function routeByParam(string $paramKey, mixed $paramValue, array $providers): self
    {
        return $this->addRule(
            fn (AnalyticsEvent $event): bool => Arr::get($event->params, $paramKey) === $paramValue,
            $providers,
        );
    }

    /**
     * Add a rule that routes PII events (events containing user data) to specific providers.
     *
     * Useful for routing events with email, name, or other PII to privacy-safe
     * providers only (e.g. excluding third-party ad platforms).
     *
     * @param  list<string>  $piiProviders  Providers allowed to receive PII events
     */
    public function routePiiOnly(array $piiProviders): self
    {
        $piiKeys = ['email', 'phone', 'address', 'user_email', 'full_name', 'first_name', 'last_name'];

        return $this->addRule(
            function (AnalyticsEvent $event) use ($piiKeys): bool {
                foreach ($piiKeys as $key) {
                    if (Arr::has($event->params, $key)) {
                        return true;
                    }
                }

                return false;
            },
            $piiProviders,
        );
    }

    /**
     * Remove all routing rules.
     */
    public function clearRules(): self
    {
        $this->rules = [];

        return $this;
    }

    /**
     * Get all registered rules.
     *
     * @return array<int, array{condition: callable, providers: list<string>}>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Get the default providers list.
     *
     * @return list<string>
     */
    public function getDefaultProviders(): array
    {
        return $this->defaultProviders;
    }

    /**
     * Evaluate all rules against an event.
     *
     * @return list<string>|null Target providers, or null if no rule matched
     */
    private function evaluateRules(AnalyticsEvent $event): ?array
    {
        foreach ($this->rules as $rule) {
            if (($rule['condition'])($event)) {
                return $rule['providers'];
            }
        }

        return null;
    }

    /**
     * Dispatch an event to all enabled providers (standard behavior).
     */
    private function dispatchStandard(AnalyticsEvent $event): void
    {
        if ($this->useAsync) {
            $this->queue->dispatch($event);
        } else {
            $this->manager->trackEvent($event);
        }
    }

    /**
     * Dispatch an event to specific providers.
     *
     * @param  list<string>  $providers  Provider names
     */
    private function dispatchToProviders(AnalyticsEvent $event, array $providers): void
    {
        foreach ($providers as $provider) {
            $tracker = $this->resolveTracker($provider);

            if ($tracker !== null && $this->isTrackerEnabled($provider)) {
                $tracker->track($event);
            }
        }
    }

    /**
     * Resolve a tracker instance by provider name.
     */
    private function resolveTracker(string $name): ?object
    {
        return match ($name) {
            'ga4' => $this->manager->ga4(),
            'gtm' => $this->manager->gtm(),
            'meta' => $this->manager->meta(),
            'plausible' => $this->manager->plausible(),
            'posthog' => $this->manager->posthog(),
            'webhook' => $this->manager->webhook(),
            default => null,
        };
    }

    /**
     * Check if a tracker is enabled by provider name.
     */
    private function isTrackerEnabled(string $name): bool
    {
        return match ($name) {
            'ga4' => $this->manager->ga4()->isEnabled(),
            'gtm' => $this->manager->gtm()->isEnabled(),
            'meta' => $this->manager->meta()->isEnabled(),
            'plausible' => $this->manager->plausible()->isEnabled(),
            'posthog' => $this->manager->posthog()->isEnabled(),
            'webhook' => $this->manager->webhook()->isEnabled(),
            default => false,
        };
    }
}
