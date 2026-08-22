<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Fluent event builder with catalog-aware validation and type safety.
 *
 * Provides a declarative, fluent API for constructing analytics events
 * with automatic catalog validation, provider name resolution, and
 * structured parameter building for common event types.
 *
 * @example
 *   $event = EventBuilder::make('purchase')
 *       ->param('transaction_id', 'TXN-123')
 *       ->param('value', 99.99)
 *       ->param('currency', 'USD')
 *       ->items([$item1, $item2])
 *       ->client($clientId)
 *       ->user($userId)
 *       ->priority('critical')
 *       ->build();
 *
 * @since 1.0.0
 */
final class EventBuilder
{
    private string $name;

    /** @var array<string, mixed> */
    private array $params = [];

    private ?string $clientId = null;

    private ?string $userId = null;

    private ?\DateTimeImmutable $timestamp = null;

    private ?string $priority = null;

    /** @var array<int, array<string, mixed>> */
    private array $items = [];

    private bool $validate = true;

    private ?string $source = null;

    private ?string $sourceId = null;

    private ?string $sessionId = null;

    private ?string $group = null;

    private function __construct(string $name){
        $this->name = $name;
    }

    /**
     * Create a new event builder for the given event name.
     */
    public static function make(string $name): self
    {
        return new self($name);
    }

    /**
     * Create a builder from an existing catalog event entry.
     *
     * @param  string  $name  Event name (must exist in the catalog)
     * @return self|null  Null if the event is not in the catalog
     */
    public static function fromCatalog(string $name): ?self
    {
        $entry = EventCatalog::get($name);

        if ($entry === null) {
            return null;
        }

        return new self($name);
    }

    /**
     * Create a purchase event builder with required params pre-structured.
     *
     * @param  string  $transactionId  Unique transaction identifier
     * @param  float  $value  Total revenue
     * @param  string  $currency  ISO 4217 currency code
     */
    public static function purchase(string $transactionId, float $value, string $currency = 'USD'): self
    {
        return (new self('purchase'))
            ->param('transaction_id', $transactionId)
            ->param('value', $value)
            ->param('currency', $currency)
            ->priority('critical');
    }

    /**
     * Create a sign_up event builder with optional user context.
     *
     * @param  string|null  $method  Signup method (email, google, github, etc.)
     */
    public static function signUp(?string $method = null): self
    {
        $builder = (new self('sign_up'))->priority('critical');

        if ($method !== null) {
            $builder->param('signup_method', $method);
        }

        return $builder;
    }

    /**
     * Create a page_view event builder.
     *
     * @param  string  $title  Page title
     * @param  string  $location  Full URL
     * @param  string|null  $referrer  Referrer URL
     */
    public static function pageView(string $title = '', string $location = '', ?string $referrer = null): self
    {
        $builder = new self('page_view');

        if ($title !== '') {
            $builder->param('page_title', $title);
        }

        if ($location !== '') {
            $builder->param('page_location', $location);
        }

        if ($referrer !== null) {
            $builder->param('page_referrer', $referrer);
        }

        return $builder;
    }

    /**
     * Create a login event builder.
     *
     * @param  string|null  $method  Login method (email, google, github, sso, etc.)
     */
    public static function login(?string $method = null): self
    {
        $builder = (new self('login'));

        if ($method !== null) {
            $builder->param('login_method', $method);
        }

        return $builder;
    }

    /**
     * Create a start_trial event builder.
     *
     * @param  string|null  $planName  Plan name (e.g. 'pro', 'business')
     */
    public static function startTrial(?string $planName = null): self
    {
        $builder = (new self('start_trial'))->priority('critical');

        if ($planName !== null) {
            $builder->param('plan_name', $planName);
        }

        return $builder;
    }

    /**
     * Create a trial_converted event builder.
     *
     * @param  string|null  $planName  Converted plan name
     */
    public static function trialConverted(?string $planName = null): self
    {
        $builder = (new self('trial_converted'))->priority('critical');

        if ($planName !== null) {
            $builder->param('plan_name', $planName);
        }

        return $builder;
    }

    /**
     * Create a subscribe event builder.
     *
     * @param  string|null  $planName  Subscription plan name
     * @param  float|null  $value  Subscription value
     * @param  string  $currency  ISO 4217 currency code
     */
    public static function subscribe(?string $planName = null, ?float $value = null, string $currency = 'USD'): self
    {
        $builder = (new self('subscribe'))->priority('critical');

        if ($planName !== null) {
            $builder->param('plan_name', $planName);
        }

        if ($value !== null) {
            $builder->param('value', $value);
            $builder->param('currency', $currency);
        }

        return $builder;
    }

    /**
     * Create a plan_upgrade event builder.
     *
     * @param  string|null  $fromPlan  Previous plan name
     * @param  string|null  $toPlan  New plan name
     */
    public static function planUpgrade(?string $fromPlan = null, ?string $toPlan = null): self
    {
        $builder = (new self('plan_upgrade'))->priority('critical');

        if ($fromPlan !== null) {
            $builder->param('from_plan', $fromPlan);
        }

        if ($toPlan !== null) {
            $builder->param('to_plan', $toPlan);
        }

        return $builder;
    }

    /**
     * Create a cancellation event builder.
     *
     * @param  string|null  $reason  Cancellation reason
     */
    public static function cancellation(?string $reason = null): self
    {
        $builder = (new self('cancellation'))->priority('critical');

        if ($reason !== null) {
            $builder->param('reason', $reason);
        }

        return $builder;
    }

    /**
     * Create a view_item event builder.
     *
     * @param  string  $itemId  Product/item ID
     * @param  string|null  $itemName  Product/item name
     * @param  string|null  $category  Item category
     * @param  float|null  $price  Item price
     */
    public static function viewItem(string $itemId, ?string $itemName = null, ?string $category = null, ?float $price = null): self
    {
        return (new self('view_item'))
            ->param('item_id', $itemId)
            ->param('item_name', $itemName)
            ->param('item_category', $category)
            ->param('price', $price);
    }

    /**
     * Create an add_to_cart event builder.
     *
     * @param  string  $itemId  Product/item ID
     * @param  string|null  $itemName  Product/item name
     * @param  float|null  $price  Item price
     * @param  int|null  $quantity  Quantity added
     */
    public static function addToCart(string $itemId, ?string $itemName = null, ?float $price = null, ?int $quantity = null): self
    {
        $builder = (new self('add_to_cart'))
            ->param('item_id', $itemId)
            ->param('item_name', $itemName)
            ->param('price', $price);

        if ($quantity !== null) {
            $builder->param('quantity', $quantity);
        }

        return $builder;
    }

    /**
     * Create a refund event builder.
     *
     * @param  string  $transactionId  Original transaction ID
     * @param  float  $value  Refund amount
     * @param  string  $currency  ISO 4217 currency code
     */
    public static function refund(string $transactionId, float $value, string $currency = 'USD'): self
    {
        return (new self('refund'))
            ->param('transaction_id', $transactionId)
            ->param('value', $value)
            ->param('currency', $currency)
            ->priority('critical');
    }

    /**
     * Create a search event builder.
     *
     * @param  string  $query  Search query string
     * @param  int|null  $resultCount  Number of results returned
     */
    public static function search(string $query, ?int $resultCount = null): self
    {
        $builder = (new self('search'))
            ->param('search_term', $query);

        if ($resultCount !== null) {
            $builder->param('results_count', $resultCount);
        }

        return $builder;
    }

    /**
     * Create a share event builder.
     *
     * @param  string  $method  Share method (email, twitter, linkedin, copy_link, etc.)
     * @param  string|null  $contentType  Content type being shared
     * @param  string|null  $itemId  Shared item ID
     */
    public static function share(string $method, ?string $contentType = null, ?string $itemId = null): self
    {
        $builder = (new self('share'))
            ->param('method', $method);

        if ($contentType !== null) {
            $builder->param('content_type', $contentType);
        }

        if ($itemId !== null) {
            $builder->param('item_id', $itemId);
        }

        return $builder;
    }

    /**
     * Create a form_start event builder.
     *
     * @param  string|null  $formId  Form identifier
     * @param  string|null  $formName  Human-readable form name
     */
    public static function formStart(?string $formId = null, ?string $formName = null): self
    {
        return (new self('form_start'))
            ->param('form_id', $formId)
            ->param('form_name', $formName);
    }

    /**
     * Create a form_submit event builder.
     *
     * @param  string|null  $formId  Form identifier
     * @param  string|null  $formName  Human-readable form name
     * @param  bool|null  $success  Whether the submission was successful
     */
    public static function formSubmit(?string $formId = null, ?string $formName = null, ?bool $success = null): self
    {
        $builder = (new self('form_submit'))
            ->param('form_id', $formId)
            ->param('form_name', $formName);

        if ($success !== null) {
            $builder->param('success', $success);
        }

        return $builder;
    }

    /**
     * Create a scroll_depth event builder.
     *
     * @param  int  $percent  Scroll percentage (0-100)
     * @param  string|null  $direction  Scroll direction (vertical|horizontal)
     */
    public static function scrollDepth(int $percent, ?string $direction = null): self
    {
        return (new self('scroll_depth'))
            ->param('percent', min(100, max(0, $percent)))
            ->param('direction', $direction);
    }

    /**
     * Create an error event builder.
     *
     * @param  string  $message  Error message
     * @param  string|null  $severity  Error severity (fatal|error|warning)
     * @param  string|null  $source  Error source (js|php|api)
     */
    public static function error(string $message, ?string $severity = null, ?string $source = null): self
    {
        return (new self('error'))
            ->param('message', $message)
            ->param('severity', $severity)
            ->param('source', $source);
    }

    /**
     * Create an identify event builder.
     *
     * @param  string  $userId  User ID to identify
     * @param  array<string, mixed>  $traits  User traits (email_hash, name, plan, etc.)
     */
    public static function identify(string $userId, array $traits = []): self
    {
        return (new self('identify'))
            ->user($userId)
            ->params($traits)
            ->priority('critical');
    }

    /**
     * Create a begin_checkout event builder.
     *
     * @param  float|null  $value  Cart total value
     * @param  string  $currency  ISO 4217 currency code
     * @param  int|null  $itemCount  Number of items in cart
     */
    public static function beginCheckout(?float $value = null, string $currency = 'USD', ?int $itemCount = null): self
    {
        $builder = (new self('begin_checkout'));

        if ($value !== null) {
            $builder->param('value', $value);
            $builder->param('currency', $currency);
        }

        if ($itemCount !== null) {
            $builder->param('item_count', $itemCount);
        }

        return $builder;
    }

    /**
     * Create a feature_used event builder.
     *
     * @param  string  $featureName  Feature identifier
     * @param  string|null  $category  Feature category
     */
    public static function featureUsed(string $featureName, ?string $category = null): self
    {
        return (new self('feature_used'))
            ->param('feature_name', $featureName)
            ->param('feature_category', $category);
    }

    /**
     * Create an onboarding_step event builder.
     *
     * @param  int  $step  Step number (1-based)
     * @param  string|null  $stepName  Step display name
     * @param  bool|null  $completed  Whether the step was completed
     */
    public static function onboardingStep(int $step, ?string $stepName = null, ?bool $completed = null): self
    {
        return (new self('onboarding_step'))
            ->param('step_number', $step)
            ->param('step_name', $stepName)
            ->param('completed', $completed);
    }

    /**
     * Create a plan_downgrade event builder.
     *
     * @param  string|null  $fromPlan  Previous plan name
     * @param  string|null  $toPlan  New plan name
     */
    public static function planDowngrade(?string $fromPlan = null, ?string $toPlan = null): self
    {
        return (new self('plan_downgrade'))
            ->param('from_plan', $fromPlan)
            ->param('to_plan', $toPlan);
    }

    /**
     * Create a logout event builder.
     *
     * @param  string|null  $method  Logout method (manual, session_expired, forced)
     */
    public static function logout(?string $method = null): self
    {
        return (new self('logout'))
            ->param('method', $method);
    }

    /**
     * Create a subscription_paused event builder.
     *
     * @param  string|null  $plan  Paused plan name
     * @param  string|null  $reason  Pause reason
     */
    public static function subscriptionPaused(?string $plan = null, ?string $reason = null): self
    {
        return (new self('subscription_paused'))
            ->param('plan', $plan)
            ->param('reason', $reason);
    }

    /**
     * Create a subscription_resumed event builder.
     *
     * @param  string|null  $plan  Resumed plan name
     */
    public static function subscriptionResumed(?string $plan = null): self
    {
        return (new self('subscription_resumed'))
            ->param('plan', $plan);
    }

    /**
     * Create an invoice_generated event builder.
     *
     * @param  string|null  $invoiceId  Invoice identifier
     * @param  float|null  $amount  Invoice amount
     * @param  string  $currency  Currency code
     */
    public static function invoiceGenerated(?string $invoiceId = null, ?float $amount = null, string $currency = 'USD'): self
    {
        return (new self('invoice_generated'))
            ->param('invoice_id', $invoiceId)
            ->param('amount', $amount)
            ->param('currency', $currency);
    }

    /**
     * Create a team_created event builder.
     *
     * @param  string|null  $teamName  Team display name
     * @param  int|null  $memberCount  Initial member count
     */
    public static function teamCreated(?string $teamName = null, ?int $memberCount = null): self
    {
        return (new self('team_created'))
            ->param('team_name', $teamName)
            ->param('member_count', $memberCount);
    }

    /**
     * Create an invite_sent event builder.
     *
     * @param  string|null  $role  Invited role
     * @param  string|null  $channel  Invitation channel (email, link)
     */
    public static function inviteSent(?string $role = null, ?string $channel = null): self
    {
        return (new self('invite_sent'))
            ->param('role', $role)
            ->param('channel', $channel);
    }

    /**
     * Create a payment_failed event builder.
     *
     * @param  string|null  $reason  Failure reason
     * @param  float|null  $amount  Attempted amount
     * @param  string  $currency  Currency code
     */
    public static function paymentFailed(?string $reason = null, ?float $amount = null, string $currency = 'USD'): self
    {
        return (new self('payment_failed'))
            ->param('reason', $reason)
            ->param('amount', $amount)
            ->param('currency', $currency);
    }

    /**
     * Create a subscription_renewal event builder.
     *
     * @param  string|null  $plan  Renewed plan name
     * @param  float|null  $value  Renewal value
     * @param  int|null  $cycleCount  Renewal cycle number
     */
    public static function subscriptionRenewal(?string $plan = null, ?float $value = null, ?int $cycleCount = null): self
    {
        return (new self('subscription_renewal'))
            ->param('plan', $plan)
            ->param('value', $value)
            ->param('cycle_count', $cycleCount);
    }

    /**
     * Create a trial_expired event builder.
     *
     * @param  string|null  $plan  Expired trial plan name
     * @param  int|null  $trialDays  Number of trial days
     */
    public static function trialExpired(?string $plan = null, ?int $trialDays = null): self
    {
        return (new self('trial_expired'))
            ->param('plan', $plan)
            ->param('trial_days', $trialDays);
    }

    /**
     * Create an event from a registered blueprint.
     *
     * Looks up the blueprint by name, merges defaults with overrides,
     * validates required params, and returns a builder pre-configured
     * with the blueprint's base event and priority.
     *
     * @param  string  $blueprintName  Blueprint identifier (e.g. 'saas.signup.email')
     * @param  array<string, mixed>  $params  Override/default params
     * @return self
     *
     * @throws \ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException
     *
     * @since 66.0.0
     */
    public static function fromBlueprint(string $blueprintName, array $params = []): self
    {
        $container = \Illuminate\Support\Facades\App::getContainer();

        if ($container === null || ! $container->has(\ZeroBoiler\Analytics\Blueprints\EventBlueprintRegistry::class)) {
            return new self($blueprintName);
        }

        $registry = $container->make(\ZeroBoiler\Analytics\Blueprints\EventBlueprintRegistry::class);
        $event = $registry->buildUnsafe($blueprintName, $params);

        if ($event['errors'] !== []) {
            // Log warnings but don't throw — return the event as-is
            if (function_exists('logger')) {
                logger()->warning('[ZeroBoiler] Blueprint validation warnings: ' . implode('; ', $event['errors']));
            }
        }

        $builtEvent = $event['event'];
        $builder = new self($builtEvent->name);

        foreach ($builtEvent->params as $key => $value) {
            $builder->param($key, $value);
        }

        if ($builtEvent->priority !== null) {
            $builder->priority($builtEvent->priority);
        }

        return $builder;
    }

    /**
     * Add a parameter to the event.
     *
     * @param  string  $key  Parameter key
     * @param  mixed  $value  Parameter value
     */
    public function param(string $key, mixed $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }

    /**
     * Merge an array of parameters into the event.
     *
     * @param  array<string, mixed>  $params  Parameters to merge
     */
    public function params(array $params): self
    {
        $this->params = array_merge($this->params, $params);

        return $this;
    }

    /**
     * Set the client tracking ID.
     */
    public function client(string $clientId): self
    {
        $this->clientId = $clientId;

        return $this;
    }

    /**
     * Set the authenticated user ID.
     */
    public function user(string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    /**
     * Set the event priority level.
     *
     * @param  'critical'|'high'|'medium'|'low'|'background'  $priority
     */
    public function priority(string $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Set the event timestamp.
     */
    public function timestamp(\DateTimeImmutable $timestamp): self
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    /**
     * Set the timestamp to now.
     */
    public function now(): self
    {
        $this->timestamp = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Add GA4-format items to the event.
     *
     * @param  array<int, array<string, mixed>>  $items  GA4 items array
     */
    public function items(array $items): self
    {
        $this->items = $items;

        return $this;
    }

    /**
     * Add a single GA4-format item to the event.
     *
     * @param  array{item_id: string, item_name?: string, item_category?: string, price?: float, quantity?: int}  $item
     */
    public function item(array $item): self
    {
        $this->items[] = $item;

        return $this;
    }

    /**
     * Enable or disable catalog validation when building.
     *
     * When enabled (default), the builder will validate that the event
     * name exists in the catalog and log a warning if it doesn't.
     */
    public function validate(bool $enabled): self
    {
        $this->validate = $enabled;

        return $this;
    }

    /**
     * Set the event origin source (api|server|client|webhook|replay|batch).
     *
     * @param  string  $source  Event origin identifier
     */
    public function source(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Set the event source ID for traceability.
     *
     * @param  string  $sourceId  Unique source identifier (e.g., request ID, job ID)
     */
    public function sourceId(string $sourceId): self
    {
        $this->sourceId = $sourceId;

        return $this;
    }

    /**
     * Set the session ID for session-scoped events.
     *
     * @param  string  $sessionId  Session identifier
     */
    public function sessionId(string $sessionId): self
    {
        $this->sessionId = $sessionId;

        return $this;
    }

    /**
     * Set the group/context ID for B2B or multi-tenant analytics.
     *
     * @param  string  $group  Group identifier (e.g., workspace ID, team ID)
     */
    public function group(string $group): self
    {
        $this->group = $group;

        return $this;
    }

    /**
     * Build the AnalyticsEvent DTO.
     *
     * Validates the event name against the catalog (if validation enabled),
     * merges items into params, and returns an immutable AnalyticsEvent.
     *
     * @return AnalyticsEvent
     * @throws \InvalidArgumentException If the event name is empty
     */
    public function build(): AnalyticsEvent
    {
        if ($this->name === '') {
            throw new \ZeroBoiler\Analytics\Exceptions\InvalidAnalyticsArgumentException('Event name cannot be empty');
        }

        // Catalog validation (soft — logs warning, doesn't throw)
        if ($this->validate && ! EventCatalog::has($this->name)) {
            \Illuminate\Support\Facades\Log::warning("ZeroBoiler Analytics: Event '{$this->name}' is not in the catalog", [
                'hint' => 'Use EventCatalog::names() to see all registered events, or disable validation with ->validate(false)',
            ]);
        }

        $params = $this->params;
        if ($this->items !== []) {
            $params['items'] = $this->items;
        }

        // Embed contextual identifiers into params
        if ($this->sourceId !== null) {
            $params['_source_id'] = $this->sourceId;
        }
        if ($this->sessionId !== null) {
            $params['_session_id'] = $this->sessionId;
        }
        if ($this->group !== null) {
            $params['_group'] = $this->group;
        }

        return new AnalyticsEvent(
            name: $this->name,
            params: $params,
            clientId: $this->clientId,
            userId: $this->userId,
            timestamp: $this->timestamp,
            priority: $this->priority,
            source: $this->source,
        );
    }

    /**
     * Build and immediately dispatch via the AnalyticsManager.
     *
     * Shortcut for build + dispatch in a single call.
     */
    public function dispatch(): void
    {
        $event = $this->build();

        try {
            $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
            $manager->trackEvent($event);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ZeroBoiler Analytics: EventBuilder::dispatch failed', [
                'event' => $this->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build and immediately queue via QueuedAnalyticsDispatcher.
     *
     * Shortcut for build + async dispatch.
     */
    public function dispatchAsync(): void
    {
        $event = $this->build();

        try {
            $dispatcher = app(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);
            $dispatcher->dispatch($event);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ZeroBoiler Analytics: EventBuilder::dispatchAsync failed, falling back to sync', [
                'event' => $this->name,
                'error' => $e->getMessage(),
            ]);

            // Fallback to synchronous dispatch
            try {
                $manager = app(\ZeroBoiler\Analytics\AnalyticsManager::class);
                $manager->trackEvent($event);
            } catch (\Throwable $e) {
                // Silent fail — already logged
            }
        }
    }

    /**
     * Get the event name being built.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the current parameters (without items, which are separate).
     *
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Get the current items array.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Check if the event name exists in the catalog.
     */
    public function isInCatalog(): bool
    {
        return EventCatalog::has($this->name);
    }

    /**
     * Get the catalog entry for the event being built.
     *
     * @return array{name: string, class: class-string, ga4: string, meta: string|null, posthog: string, plausible: string|null, category: string}|null
     */
    public function getCatalogEntry(): ?array
    {
        return EventCatalog::get($this->name);
    }

    /**
     * Get the resolved provider event names for this event.
     *
     * @return array{ga4: string, meta: string|null, posthog: string|null, plausible: string|null, tiktok: string|null, linkedin: string|null}
     */
    public function getProviderNames(): array
    {
        $entry = EventCatalog::get($this->name);

        if ($entry === null) {
            return [
                'ga4' => $this->name,
                'meta' => null,
                'posthog' => null,
                'plausible' => null,
                'tiktok' => null,
                'linkedin' => null,
            ];
        }

        return [
            'ga4' => $entry['ga4'] ?? $this->name,
            'meta' => $entry['meta'] ?? null,
            'posthog' => $entry['posthog'] ?? null,
            'plausible' => $entry['plausible'] ?? null,
            'tiktok' => $entry['tiktok'] ?? null,
            'linkedin' => $entry['linkedin'] ?? null,
        ];
    }

    /**
     * Get the AARRR category for this event.
     *
     * @return string
     */
    public function getAarrCategory(): string
    {
        return EventCatalog::eventCategory($this->name);
    }

    /**
     * Get the priority level for this event from the catalog.
     *
     * @return string
     */
    public function getCatalogPriority(): string
    {
        return EventCatalog::eventPriority($this->name);
    }
}
