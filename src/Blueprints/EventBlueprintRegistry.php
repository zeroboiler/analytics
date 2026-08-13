<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Blueprints;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException;

/**
 * Registry and factory service for event blueprints.
 *
 * Manages a registry of reusable event templates that can be registered
 * via config, code, or at runtime. Provides factory methods for creating
 * validated analytics events from blueprint definitions.
 *
 * Blueprint resolution order:
 *   1. Runtime-registered blueprints (register())
 *   2. Config-defined blueprints (config/blueprints)
 *   3. Built-in blueprint library (builtInBlueprints())
 *
 * @since 66.0.0
 *
 * @example
 *   $blueprint = $registry->find('saas.signup.email');
 *   $event = $registry->build('saas.signup.email', [
 *       'user_id' => 'usr_123',
 *       'email_hash' => 'abc123',
 *   ], clientId: 'cli_456');
 */
final class EventBlueprintRegistry
{
    private const CACHE_KEY_PREFIX = 'zb_blueprint_';
    private const CACHE_TTL = 86400; // 24 hours

    /** @var array<string, EventBlueprint> Runtime-registered blueprints */
    private array $blueprints = [];

    private readonly CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache  Cache repository for blueprint caching
     */
    public function __construct(CacheRepository $cache)
    {
        $this->cache = $cache;
    }

    // ── Registration ────────────────────────────────────────────────

    /**
     * Register a blueprint in the registry.
     *
     * @param  EventBlueprint  $blueprint  Blueprint to register
     */
    public function register(EventBlueprint $blueprint): void
    {
        $this->blueprints[$blueprint->name] = $blueprint;
        $this->cache->put(self::CACHE_KEY_PREFIX . $blueprint->name, $blueprint->toArray(), self::CACHE_TTL);
    }

    /**
     * Register a blueprint from array config.
     *
     * @param  array<string, mixed>  $config  Blueprint configuration array
     */
    public function registerFromArray(array $config): void
    {
        $blueprint = EventBlueprint::fromArray($config);

        if ($blueprint->name === '') {
            throw new InvalidAnalyticsArgumentException('Blueprint must have a non-empty name.');
        }

        $this->register($blueprint);
    }

    /**
     * Register multiple blueprints from config arrays.
     *
     * @param  array<string, array<string, mixed>>  $configs  Map of name → config
     */
    public function registerMany(array $configs): void
    {
        foreach ($configs as $config) {
            $this->registerFromArray($config);
        }
    }

    /**
     * Unregister a blueprint by name.
     */
    public function unregister(string $name): void
    {
        unset($this->blueprints[$name]);
        $this->cache->forget(self::CACHE_KEY_PREFIX . $name);
    }

    /**
     * Clear all runtime-registered blueprints.
     */
    public function clear(): void
    {
        $this->blueprints = [];
    }

    // ── Lookup ─────────────────────────────────────────────────────

    /**
     * Find a blueprint by name.
     *
     * Resolution order: runtime → cache → config → built-in.
     *
     * @return EventBlueprint|null
     */
    public function find(string $name): ?EventBlueprint
    {
        // 1. Runtime-registered
        if (isset($this->blueprints[$name])) {
            return $this->blueprints[$name];
        }

        // 2. Cache
        $cached = $this->cache->get(self::CACHE_KEY_PREFIX . $name);

        if (is_array($cached)) {
            $blueprint = EventBlueprint::fromArray($cached);
            $this->blueprints[$name] = $blueprint;

            return $blueprint;
        }

        // 3. Config
        $configBlueprints = $this->getConfigBlueprints();

        if (isset($configBlueprints[$name])) {
            $blueprint = EventBlueprint::fromArray($configBlueprints[$name]);
            $this->blueprints[$name] = $blueprint;

            return $blueprint;
        }

        // 4. Built-in
        $builtIn = $this->builtInBlueprints();

        if (isset($builtIn[$name])) {
            return $builtIn[$name];
        }

        return null;
    }

    /**
     * Check if a blueprint exists.
     */
    public function has(string $name): bool
    {
        return $this->find($name) !== null;
    }

    /**
     * Get all registered blueprint names.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $runtime = array_keys($this->blueprints);
        $config = array_keys($this->getConfigBlueprints());
        $builtIn = array_keys($this->builtInBlueprints());

        return array_values(array_unique(array_merge($runtime, $config, $builtIn)));
    }

    /**
     * Get all blueprints.
     *
     * @return array<string, EventBlueprint>
     */
    public function all(): array
    {
        $result = [];

        foreach ($this->names() as $name) {
            $blueprint = $this->find($name);

            if ($blueprint !== null) {
                $result[$name] = $blueprint;
            }
        }

        return $result;
    }

    /**
     * Count total registered blueprints.
     */
    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Get blueprints grouped by category.
     *
     * @return array<string, array<string, EventBlueprint>>
     */
    public function byCategory(): array
    {
        $grouped = [];

        foreach ($this->all() as $blueprint) {
            $category = $blueprint->category;
            $grouped[$category][$blueprint->name] = $blueprint;
        }

        ksort($grouped);

        return $grouped;
    }

    // ── Factory ─────────────────────────────────────────────────────

    /**
     * Build a validated analytics event from a blueprint.
     *
     * Merges provided params with blueprint defaults, validates required
     * params and types, then creates an AnalyticsEvent DTO.
     *
     * @param  string  $blueprintName  Blueprint identifier
     * @param  array<string, mixed>  $params  Override/default params
     * @param  string|null  $clientId  Optional client ID
     * @param  string|null  $userId  Optional user ID
     * @param  string|null  $priority  Override priority
     * @return AnalyticsEvent
     *
     * @throws InvalidAnalyticsArgumentException If blueprint not found or validation fails
     */
    public function build(
        string $blueprintName,
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
        ?string $priority = null,
    ): AnalyticsEvent {
        $blueprint = $this->find($blueprintName);

        if ($blueprint === null) {
            throw new InvalidAnalyticsArgumentException(
                "Blueprint '{$blueprintName}' not found. Available: " .
                implode(', ', array_slice($this->names(), 0, 10)) .
                (count($this->names()) > 10 ? '...' : ''),
            );
        }

        if ($blueprint->isDeprecated()) {
            $notice = $blueprint->deprecationNotice() ?? 'This blueprint is deprecated.';
            // Log deprecation warning (non-fatal)
            if (function_exists('logger')) {
                logger()->warning("[ZeroBoiler] Deprecated blueprint used: {$blueprintName} — {$notice}");
            }
        }

        // Merge defaults with overrides
        $mergedParams = array_merge($blueprint->defaultParams, $params);

        // Validate required params
        $errors = $blueprint->validateParams($mergedParams);

        if ($errors !== []) {
            throw new InvalidAnalyticsArgumentException(
                "Blueprint '{$blueprintName}' validation failed: " . implode('; ', $errors),
            );
        }

        // Use blueprint's base event or fallback to blueprint name
        $eventName = $blueprint->baseEvent !== '' ? $blueprint->baseEvent : $blueprint->name;

        // Validate against catalog if base event exists
        if ($blueprint->baseEvent !== '' && ! EventCatalog::has($blueprint->baseEvent)) {
            throw new InvalidAnalyticsArgumentException(
                "Blueprint '{$blueprintName}' references unknown catalog event '{$blueprint->baseEvent}'.",
            );
        }

        return new AnalyticsEvent(
            name: $eventName,
            params: $mergedParams,
            clientId: $clientId,
            userId: $userId,
            priority: $priority ?? $blueprint->priority,
        );
    }

    /**
     * Build a blueprint event without throwing on validation errors.
     *
     * Returns [event, errors] tuple. Event is created even if validation has errors.
     *
     * @param  string  $blueprintName  Blueprint identifier
     * @param  array<string, mixed>  $params  Override/default params
     * @return array{event: AnalyticsEvent, errors: list<string>, warnings: list<string>}
     */
    public function buildUnsafe(string $blueprintName, array $params = []): array
    {
        $blueprint = $this->find($blueprintName);

        if ($blueprint === null) {
            return [
                'event' => new AnalyticsEvent(name: $blueprintName, params: $params),
                'errors' => ["Blueprint '{$blueprintName}' not found"],
                'warnings' => [],
            ];
        }

        $warnings = [];

        if ($blueprint->isDeprecated()) {
            $warnings[] = $blueprint->deprecationNotice() ?? "Blueprint '{$blueprintName}' is deprecated.";
        }

        $mergedParams = array_merge($blueprint->defaultParams, $params);
        $errors = $blueprint->validateParams($mergedParams);

        $eventName = $blueprint->baseEvent !== '' ? $blueprint->baseEvent : $blueprint->name;

        $event = new AnalyticsEvent(
            name: $eventName,
            params: $mergedParams,
            priority: $blueprint->priority,
        );

        return [
            'event' => $event,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    // ── Built-in Blueprint Library ───────────────────────────────────

    /**
     * Get the built-in blueprint library.
     *
     * Pre-configured blueprints for common SaaS event patterns.
     *
     * @return array<string, EventBlueprint>
     */
    public function builtInBlueprints(): array
    {
        return [
            // ── SaaS Lifecycle ───────────────────────────────────
            'saas.signup.email' => new EventBlueprint(
                name: 'saas.signup.email',
                label: 'Email Signup',
                description: 'User registered via email/password',
                baseEvent: 'sign_up',
                category: 'saas',
                defaultParams: ['signup_method' => 'email'],
                requiredParams: ['user_id'],
                paramTypes: ['user_id' => 'string', 'signup_method' => 'string'],
                priority: 'critical',
                metadata: ['owner' => 'growth'],
            ),
            'saas.signup.google' => new EventBlueprint(
                name: 'saas.signup.google',
                label: 'Google OAuth Signup',
                description: 'User registered via Google OAuth',
                baseEvent: 'sign_up',
                category: 'saas',
                defaultParams: ['signup_method' => 'google'],
                requiredParams: ['user_id'],
                paramTypes: ['user_id' => 'string', 'signup_method' => 'string'],
                priority: 'critical',
                metadata: ['owner' => 'growth'],
            ),
            'saas.signup.github' => new EventBlueprint(
                name: 'saas.signup.github',
                label: 'GitHub OAuth Signup',
                description: 'User registered via GitHub OAuth',
                baseEvent: 'sign_up',
                category: 'saas',
                defaultParams: ['signup_method' => 'github'],
                requiredParams: ['user_id'],
                paramTypes: ['user_id' => 'string', 'signup_method' => 'string'],
                priority: 'critical',
                metadata: ['owner' => 'growth'],
            ),
            'saas.login.standard' => new EventBlueprint(
                name: 'saas.login.standard',
                label: 'Standard Login',
                description: 'User logged in via email/password',
                baseEvent: 'login',
                category: 'saas',
                defaultParams: ['login_method' => 'email'],
                requiredParams: [],
                paramTypes: ['login_method' => 'string'],
                priority: 'normal',
                metadata: ['owner' => 'growth'],
            ),
            'saas.login.sso' => new EventBlueprint(
                name: 'saas.login.sso',
                label: 'SSO Login',
                description: 'User logged in via SAML/SSO',
                baseEvent: 'login',
                category: 'saas',
                defaultParams: ['login_method' => 'sso'],
                requiredParams: [],
                paramTypes: ['login_method' => 'string'],
                priority: 'critical',
                metadata: ['owner' => 'growth'],
            ),
            'saas.trial.started' => new EventBlueprint(
                name: 'saas.trial.started',
                label: 'Trial Started',
                description: 'User started a free trial',
                baseEvent: 'start_trial',
                category: 'saas',
                defaultParams: [],
                requiredParams: ['plan_name'],
                paramTypes: ['plan_name' => 'string', 'trial_days' => 'int'],
                priority: 'critical',
                metadata: ['owner' => 'revenue'],
            ),
            'saas.trial.converted' => new EventBlueprint(
                name: 'saas.trial.converted',
                label: 'Trial Converted',
                description: 'User converted from trial to paid subscription',
                baseEvent: 'trial_converted',
                category: 'saas',
                defaultParams: [],
                requiredParams: ['plan_name'],
                paramTypes: ['plan_name' => 'string', 'value' => 'float', 'currency' => 'string'],
                priority: 'critical',
                metadata: ['owner' => 'revenue'],
            ),
            'saas.subscription.created' => new EventBlueprint(
                name: 'saas.subscription.created',
                label: 'Subscription Created',
                description: 'New subscription activated',
                baseEvent: 'subscribe',
                category: 'saas',
                defaultParams: [],
                requiredParams: ['plan_name'],
                paramTypes: ['plan_name' => 'string', 'value' => 'float', 'currency' => 'string'],
                priority: 'critical',
                metadata: ['owner' => 'revenue'],
            ),
            'saas.plan.upgraded' => new EventBlueprint(
                name: 'saas.plan.upgraded',
                label: 'Plan Upgraded',
                description: 'User upgraded to a higher plan',
                baseEvent: 'plan_upgrade',
                category: 'saas',
                defaultParams: [],
                requiredParams: ['from_plan', 'to_plan'],
                paramTypes: ['from_plan' => 'string', 'to_plan' => 'string'],
                priority: 'critical',
                metadata: ['owner' => 'revenue'],
            ),
            'saas.subscription.cancelled' => new EventBlueprint(
                name: 'saas.subscription.cancelled',
                label: 'Subscription Cancelled',
                description: 'User cancelled their subscription',
                baseEvent: 'cancellation',
                category: 'saas',
                defaultParams: [],
                requiredParams: [],
                paramTypes: ['reason' => 'string', 'plan_name' => 'string'],
                priority: 'critical',
                metadata: ['owner' => 'revenue'],
            ),

            // ── E-commerce ──────────────────────────────────────────
            'ecommerce.product.viewed' => new EventBlueprint(
                name: 'ecommerce.product.viewed',
                label: 'Product Viewed',
                description: 'User viewed a product detail page',
                baseEvent: 'view_item',
                category: 'ecommerce',
                defaultParams: [],
                requiredParams: ['item_id'],
                paramTypes: ['item_id' => 'string', 'item_name' => 'string', 'price' => 'float', 'item_category' => 'string'],
                priority: 'normal',
                metadata: ['owner' => 'ecommerce'],
            ),
            'ecommerce.cart.added' => new EventBlueprint(
                name: 'ecommerce.cart.added',
                label: 'Added to Cart',
                description: 'User added an item to their cart',
                baseEvent: 'add_to_cart',
                category: 'ecommerce',
                defaultParams: [],
                requiredParams: ['item_id'],
                paramTypes: ['item_id' => 'string', 'item_name' => 'string', 'price' => 'float', 'quantity' => 'int'],
                priority: 'normal',
                metadata: ['owner' => 'ecommerce'],
            ),
            'ecommerce.checkout.started' => new EventBlueprint(
                name: 'ecommerce.checkout.started',
                label: 'Checkout Started',
                description: 'User began the checkout process',
                baseEvent: 'begin_checkout',
                category: 'ecommerce',
                defaultParams: [],
                requiredParams: [],
                paramTypes: ['value' => 'float', 'currency' => 'string', 'item_count' => 'int'],
                priority: 'normal',
                metadata: ['owner' => 'ecommerce'],
            ),
            'ecommerce.purchase.completed' => new EventBlueprint(
                name: 'ecommerce.purchase.completed',
                label: 'Purchase Completed',
                description: 'User completed a purchase',
                baseEvent: 'purchase',
                category: 'ecommerce',
                defaultParams: [],
                requiredParams: ['transaction_id', 'value'],
                paramTypes: ['transaction_id' => 'string', 'value' => 'float', 'currency' => 'string', 'items' => 'array'],
                priority: 'critical',
                metadata: ['owner' => 'ecommerce'],
            ),
            'ecommerce.refund.issued' => new EventBlueprint(
                name: 'ecommerce.refund.issued',
                label: 'Refund Issued',
                description: 'Refund processed for a transaction',
                baseEvent: 'refund',
                category: 'ecommerce',
                defaultParams: [],
                requiredParams: ['transaction_id', 'value'],
                paramTypes: ['transaction_id' => 'string', 'value' => 'float', 'currency' => 'string'],
                priority: 'critical',
                metadata: ['owner' => 'ecommerce'],
            ),

            // ── Engagement ──────────────────────────────────────────
            'engagement.page.viewed' => new EventBlueprint(
                name: 'engagement.page.viewed',
                label: 'Page Viewed',
                description: 'User viewed a page',
                baseEvent: 'page_view',
                category: 'engagement',
                defaultParams: [],
                requiredParams: [],
                paramTypes: ['page_title' => 'string', 'page_location' => 'string'],
                priority: 'low',
                metadata: ['owner' => 'product'],
            ),
            'engagement.search.performed' => new EventBlueprint(
                name: 'engagement.search.performed',
                label: 'Search Performed',
                description: 'User performed a search query',
                baseEvent: 'search',
                category: 'engagement',
                defaultParams: [],
                requiredParams: ['search_term'],
                paramTypes: ['search_term' => 'string', 'results_count' => 'int'],
                priority: 'normal',
                metadata: ['owner' => 'product'],
            ),
            'engagement.content.shared' => new EventBlueprint(
                name: 'engagement.content.shared',
                label: 'Content Shared',
                description: 'User shared content via a channel',
                baseEvent: 'share',
                category: 'engagement',
                defaultParams: [],
                requiredParams: ['method'],
                paramTypes: ['method' => 'string', 'content_type' => 'string', 'item_id' => 'string'],
                priority: 'normal',
                metadata: ['owner' => 'product'],
            ),
            'engagement.form.started' => new EventBlueprint(
                name: 'engagement.form.started',
                label: 'Form Started',
                description: 'User began interacting with a form',
                baseEvent: 'form_start',
                category: 'engagement',
                defaultParams: [],
                requiredParams: [],
                paramTypes: ['form_id' => 'string', 'form_name' => 'string'],
                priority: 'normal',
                metadata: ['owner' => 'product'],
            ),
            'engagement.form.submitted' => new EventBlueprint(
                name: 'engagement.form.submitted',
                label: 'Form Submitted',
                description: 'User submitted a form',
                baseEvent: 'form_submit',
                category: 'engagement',
                defaultParams: [],
                requiredParams: [],
                paramTypes: ['form_id' => 'string', 'form_name' => 'string', 'success' => 'bool'],
                priority: 'normal',
                metadata: ['owner' => 'product'],
            ),
            'engagement.scroll.depth' => new EventBlueprint(
                name: 'engagement.scroll.depth',
                label: 'Scroll Depth Milestone',
                description: 'User scrolled to a depth percentage',
                baseEvent: 'scroll_depth',
                category: 'engagement',
                defaultParams: [],
                requiredParams: ['percent'],
                paramTypes: ['percent' => 'int', 'direction' => 'string'],
                priority: 'low',
                metadata: ['owner' => 'product'],
            ),
            'engagement.error.occurred' => new EventBlueprint(
                name: 'engagement.error.occurred',
                label: 'Error Occurred',
                description: 'An error was encountered by the user',
                baseEvent: 'error',
                category: 'engagement',
                defaultParams: [],
                requiredParams: ['message'],
                paramTypes: ['message' => 'string', 'severity' => 'string', 'source' => 'string'],
                priority: 'high',
                metadata: ['owner' => 'product'],
            ),

            // ── Identity ────────────────────────────────────────────
            'identity.user.identified' => new EventBlueprint(
                name: 'identity.user.identified',
                label: 'User Identified',
                description: 'Identify a user with traits for cross-device linking',
                baseEvent: 'identify',
                category: 'custom',
                defaultParams: [],
                requiredParams: ['user_id'],
                paramTypes: ['user_id' => 'string', 'email_hash' => 'string', 'name' => 'string', 'plan' => 'string'],
                priority: 'critical',
                metadata: ['owner' => 'data'],
            ),
        ];
    }

    // ── Config ──────────────────────────────────────────────────────

    /**
     * Get blueprint definitions from config.
     *
     * @return array<string, array<string, mixed>>
     */
    private function getConfigBlueprints(): array
    {
        if (! function_exists('config')) {
            return [];
        }

        $blueprints = config('zeroboiler.analytics.blueprints.library', []);

        return is_array($blueprints) ? $blueprints : [];
    }

    // ── Diagnostics ─────────────────────────────────────────────────

    /**
     * Get diagnostic summary of the blueprint registry.
     *
     * @return array{total: int, by_category: array<string, int>, deprecated: int, built_in: int, config: int, runtime: int}
     */
    public function diagnostics(): array
    {
        $all = $this->all();
        $byCategory = [];

        $deprecated = 0;
        $builtInCount = 0;
        $configCount = 0;
        $runtimeCount = 0;

        $builtIn = $this->builtInBlueprints();
        $configNames = array_keys($this->getConfigBlueprints());

        foreach ($all as $blueprint) {
            $category = $blueprint->category;
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;

            if ($blueprint->isDeprecated()) {
                $deprecated++;
            }

            if (isset($builtIn[$blueprint->name])) {
                $builtInCount++;
            } elseif (in_array($blueprint->name, $configNames, true)) {
                $configCount++;
            } else {
                $runtimeCount++;
            }
        }

        return [
            'total' => count($all),
            'by_category' => $byCategory,
            'deprecated' => $deprecated,
            'built_in' => $builtInCount,
            'config' => $configCount,
            'runtime' => $runtimeCount,
        ];
    }

    /**
     * Validate the entire blueprint registry for consistency.
     *
     * Checks for: missing base events, duplicate names, invalid types.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validateRegistry(): array
    {
        $errors = [];
        $warnings = [];
        $seen = [];

        foreach ($this->all() as $name => $blueprint) {
            // Duplicate check
            if (isset($seen[$name])) {
                $errors[] = "Duplicate blueprint name: '{$name}'";
            }
            $seen[$name] = true;

            // Base event validation
            if ($blueprint->baseEvent !== '' && ! EventCatalog::has($blueprint->baseEvent)) {
                $errors[] = "Blueprint '{$name}' references unknown catalog event '{$blueprint->baseEvent}'";
            }

            // Deprecation warning
            if ($blueprint->isDeprecated()) {
                $warnings[] = "Blueprint '{$name}' is deprecated: " . ($blueprint->deprecationNotice() ?? 'no notice');
            }

            // Name format check
            if (! str_contains($name, '.')) {
                $warnings[] = "Blueprint '{$name}' does not follow dot.case naming (e.g. 'saas.signup.email')";
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
