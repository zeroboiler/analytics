<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Config-driven event routing service.
 *
 * Provides tag-based event routing that filters which providers receive
 * specific events. This is useful when different analytics providers have
 * different capabilities or privacy requirements — e.g., send purchase
 * events to GA4 and Meta but not to Plausible (which focuses on pageviews).
 *
 * Routes are defined in `zeroboiler.analytics.routing.rules` as an array
 * of pattern → provider mapping rules. Events are matched against patterns
 * (exact name, prefix wildcard, or tag-based) and dispatched only to
 * matched providers.
 *
 * When no routes match an event, it falls through to all enabled providers
 * (standard dispatch behavior).
 *
 * Configuration example:
 *   'routing' => [
 *       'enabled' => true,
 *       'rules' => [
 *           'purchase' => ['ga4', 'meta'],
 *           'refund' => ['ga4', 'meta'],
 *           'add_to_*' => ['ga4', 'meta', 'posthog'],
 *           'page_view' => ['ga4', 'plausible'],
 *       ],
 *   ],
 *
 * @see \ZeroBoiler\Analytics\Bus\AnalyticsDataBus
 *
 * @since 1.0.0
 */
final class AnalyticsEventRouter
{
    /** @var array<string, list<string>> Pattern → provider names */
    private array $rules = [];

    /** @var array<string, list<string>> Compiled regex patterns */
    private array $compiledRules = [];

    private bool $enabled;

    private AnalyticsManager $manager;

    /**
     * @param  AnalyticsManager  $manager
     * @param  ConfigRepository  $config
     */
    public function __construct(AnalyticsManager $manager, ConfigRepository $config): void
    {
        $this->manager = $manager;

        $routingConfig = $config->get('zeroboiler.analytics.routing', []);
        /** @var array{enabled?: bool, rules?: array<string, list<string>>, default_providers?: list<string>} $routingConfig */

        $this->enabled = (bool) ($routingConfig['enabled'] ?? false);
        $this->rules = $routingConfig['rules'] ?? [];

        $this->compileRules();
    }

    /**
     * Route an event to matched providers only.
     *
     * If routing is disabled or no rules match, the event is dispatched
     * to all enabled providers via standard dispatch.
     *
     * @param  AnalyticsEvent  $event
     * @return bool True if dispatched to at least one provider
     */
    public function route(AnalyticsEvent $event): bool
    {
        if (! $this->enabled || empty($this->compiledRules)) {
            return $this->manager->directDispatch($event);
        }

        $matchedProviders = $this->matchProviders($event->name);

        if (empty($matchedProviders)) {
            // No matching rules — fall through to all providers
            return $this->manager->directDispatch($event);
        }

        return $this->dispatchToProviders($event, $matchedProviders);
    }

    /**
     * Get providers that should receive an event based on routing rules.
     *
     * @param  string  $eventName
     * @return list<string> List of matched provider names (lowercase)
     */
    public function matchProviders(string $eventName): array
    {
        $matched = [];

        foreach ($this->compiledRules as $regex => $providers) {
            if (preg_match($regex, $eventName)) {
                foreach ($providers as $provider) {
                    if (! in_array($provider, $matched, true)) {
                        $matched[] = $provider;
                    }
                }
            }
        }

        return $matched;
    }

    /**
     * Dispatch an event to a specific set of providers.
     *
     * @param  AnalyticsEvent  $event
     * @param  list<string>  $providers  Provider names (ga4, gtm, meta, plausible, posthog, webhook)
     * @return bool True if at least one provider accepted the event
     */
    public function dispatchToProviders(AnalyticsEvent $event, array $providers): bool
    {
        $dispatched = false;
        $providerMap = $this->buildProviderMap();

        foreach ($providers as $name) {
            $tracker = $providerMap[$name] ?? null;

            if ($tracker === null) {
                continue;
            }

            if (! $tracker->isEnabled()) {
                continue;
            }

            try {
                $tracker->track($event);
                $this->manager->metrics()->recordDispatch($name);
                $dispatched = true;
            } catch (\Throwable $e) {
                $this->manager->metrics()->recordFailure($name, $e->getMessage());
            }
        }

        return $dispatched;
    }

    /**
     * Check if routing is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get all configured routing rules.
     *
     * @return array<string, list<string>>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Get the number of configured routing rules.
     */
    public function ruleCount(): int
    {
        return count($this->rules);
    }

    /**
     * Check if a specific pattern has rules.
     */
    public function hasRule(string $pattern): bool
    {
        return array_key_exists($pattern, $this->rules);
    }

    /**
     * Add a routing rule at runtime.
     *
     * @param  string  $pattern  Event name pattern (exact or wildcard with *)
     * @param  list<string>  $providers  Provider names
     */
    public function addRule(string $pattern, array $providers): void
    {
        $this->rules[$pattern] = $providers;
        $this->compileRules();
    }

    /**
     * Remove a routing rule.
     */
    public function removeRule(string $pattern): void
    {
        unset($this->rules[$pattern]);
        $this->compileRules();
    }

    /**
     * Clear all routing rules.
     */
    public function clearRules(): void
    {
        $this->rules = [];
        $this->compiledRules = [];
    }

    /**
     * Get a routing summary.
     *
     * @return array{enabled: bool, rule_count: int, rules: array<string, list<string>>, version: string}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'rule_count' => count($this->rules),
            'rules' => $this->rules,
            'version' => AnalyticsEvent::VERSION,
        ];
    }

    /**
     * Compile pattern rules into regex patterns.
     *
     * Supports exact match (purchase), wildcard prefix (add_to_*),
     * and suffix match (*_click).
     */
    private function compileRules(): void
    {
        $this->compiledRules = [];

        foreach ($this->rules as $pattern => $providers) {
            $regex = $this->patternToRegex($pattern);
            $this->compiledRules[$regex] = $providers;
        }
    }

    /**
     * Convert a routing pattern to a regex.
     *
     * Patterns:
     * - "purchase" → exact match: /^purchase$/
     * - "add_to_*" → prefix match: /^add_to_.*/
     * - "*_click" → suffix match: /.*_click$/
     * - "*" → match all: /.*/
     */
    private function patternToRegex(string $pattern): string
    {
        if ($pattern === '*') {
            return '/.*/';
        }

        if (str_contains($pattern, '*')) {
            $regexPattern = preg_quote($pattern, '/');
            $regexPattern = str_replace('\*', '.*', $regexPattern);

            return '/^' . $regexPattern . '$/';
        }

        // Exact match
        return '/^' . preg_quote($pattern, '/') . '$/';
    }

    /**
     * Build a map of provider name → tracker instance.
     *
     * @return array<string, \ZeroBoiler\Analytics\Trackers\TrackerInterface>
     */
    private function buildProviderMap(): array
    {
        return [
            'ga4' => $this->manager->ga4(),
            'gtm' => $this->manager->gtm(),
            'meta' => $this->manager->meta(),
            'plausible' => $this->manager->plausible(),
            'posthog' => $this->manager->posthog(),
            'webhook' => $this->manager->webhook(),
        ];
    }
}
