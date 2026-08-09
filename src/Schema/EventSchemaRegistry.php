<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Schema;

/**
 * Centralized registry of all analytics event schemas.
 *
 * Provides a single source of truth for event definitions including
 * parameter types, required fields, and provider-specific name mappings.
 * Used by EventValidationService for schema-aware validation.
 *
 * @see EventSchema
 * @see EventParam
 *
 * @since 1.0.0
 */
final class EventSchemaRegistry implements \Countable
{
    /** @var array<string, EventSchema> */
    private array $schemas = [];

    public function __construct(): void
    {
        $this->registerEcommerceSchemas();
        $this->registerSaaSSchemas();
        $this->registerEngagementSchemas();
        $this->registerCoreSchemas();
    }

    /**
     * Get a schema by event name.
     */
    public function get(string $eventName): ?EventSchema
    {
        return $this->schemas[$eventName] ?? null;
    }

    /**
     * Check if a schema exists for the given event name.
     */
    public function has(string $eventName): bool
    {
        return isset($this->schemas[$eventName]);
    }

    /**
     * Validate a set of event params against the named schema.
     *
     * @param  string  $eventName  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @return array{valid: bool, errors: array<int, string>, sanitized: array<string, mixed>}
     */
    public function validate(string $eventName, array $params): array
    {
        $schema = $this->get($eventName);

        if ($schema === null) {
            // No schema = permissive (pass through)
            return [
                'valid' => true,
                'errors' => [],
                'sanitized' => $params,
            ];
        }

        return $schema->validate($params);
    }

    /**
     * Get all registered event names.
     *
     * @return array<int, string>
     */
    public function getEventNames(): array
    {
        return array_keys($this->schemas);
    }

    /**
     * Get all event names for a given category.
     *
     * @return array<int, string>
     */
    public function getEventsByCategory(string $category): array
    {
        $names = [];

        foreach ($this->schemas as $name => $schema) {
            if ($schema->category === $category) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Get all schemas grouped by category.
     *
     * @return array<string, array<string, EventSchema>>
     */
    public function getSchemasByCategory(): array
    {
        $grouped = [];

        foreach ($this->schemas as $name => $schema) {
            $grouped[$schema->category][$name] = $schema;
        }

        return $grouped;
    }

    /**
     * Get all registered schemas.
     *
     * @return array<string, EventSchema>
     */
    public function all(): array
    {
        return $this->schemas;
    }

    /**
     * Register a custom schema.
     */
    public function register(EventSchema $schema): void
    {
        $this->schemas[$schema->name] = $schema;
    }

    /**
     * Remove a schema from the registry.
     */
    public function unregister(string $eventName): void
    {
        unset($this->schemas[$eventName]);
    }

    /**
     * Get the total number of registered schemas.
     */
    #[\Override]
    public function count(): int
    {
        return count($this->schemas);
    }

    /**
     * Register all e-commerce event schemas.
     */
    private function registerEcommerceSchemas(): void
    {
        $this->schemas['view_item'] = new EventSchema(
            name: 'view_item',
            category: 'ecommerce',
            description: 'Tracks a product view',
            requiredParams: [
                'item_id' => new EventParam(type: 'string', maxLength: 100, description: 'Product/item SKU or ID'),
            ],
            optionalParams: [
                'item_name' => new EventParam(type: 'string', maxLength: 200, description: 'Product name'),
                'item_category' => new EventParam(type: 'string', maxLength: 100, description: 'Product category'),
                'price' => new EventParam(type: 'float', min: 0, description: 'Product price'),
                'currency' => new EventParam(type: 'string', maxLength: 3, description: 'Currency code (ISO 4217)'),
            ],
            providerMapping: [
                'ga4' => 'view_item',
                'meta' => 'ViewContent',
            ],
        );

        $this->schemas['add_to_cart'] = new EventSchema(
            name: 'add_to_cart',
            category: 'ecommerce',
            description: 'Tracks an add-to-cart action',
            requiredParams: [
                'item_id' => new EventParam(type: 'string', maxLength: 100),
            ],
            optionalParams: [
                'item_name' => new EventParam(type: 'string', maxLength: 200),
                'item_category' => new EventParam(type: 'string', maxLength: 100),
                'price' => new EventParam(type: 'float', min: 0),
                'quantity' => new EventParam(type: 'int', min: 1),
                'currency' => new EventParam(type: 'string', maxLength: 3),
            ],
            providerMapping: [
                'ga4' => 'add_to_cart',
                'meta' => 'AddToCart',
            ],
        );

        $this->schemas['remove_from_cart'] = new EventSchema(
            name: 'remove_from_cart',
            category: 'ecommerce',
            description: 'Tracks a remove-from-cart action',
            requiredParams: [
                'item_id' => new EventParam(type: 'string', maxLength: 100),
            ],
            optionalParams: [
                'item_name' => new EventParam(type: 'string', maxLength: 200),
                'item_category' => new EventParam(type: 'string', maxLength: 100),
                'price' => new EventParam(type: 'float', min: 0),
                'quantity' => new EventParam(type: 'int', min: 1),
                'currency' => new EventParam(type: 'string', maxLength: 3),
            ],
        );

        $this->schemas['view_cart'] = new EventSchema(
            name: 'view_cart',
            category: 'ecommerce',
            description: 'Tracks viewing the shopping cart',
            optionalParams: [
                'value' => new EventParam(type: 'float', min: 0),
                'currency' => new EventParam(type: 'string', maxLength: 3),
                'items' => new EventParam(type: 'array'),
            ],
        );

        $this->schemas['begin_checkout'] = new EventSchema(
            name: 'begin_checkout',
            category: 'ecommerce',
            description: 'Tracks beginning the checkout process',
            optionalParams: [
                'value' => new EventParam(type: 'float', min: 0),
                'currency' => new EventParam(type: 'string', maxLength: 3),
                'coupon' => new EventParam(type: 'string', maxLength: 50),
                'items' => new EventParam(type: 'array'),
            ],
            providerMapping: [
                'ga4' => 'begin_checkout',
                'meta' => 'InitiateCheckout',
            ],
        );

        $this->schemas['add_payment_info'] = new EventSchema(
            name: 'add_payment_info',
            category: 'ecommerce',
            description: 'Tracks adding payment information',
            optionalParams: [
                'payment_type' => new EventParam(type: 'string', maxLength: 50),
                'currency' => new EventParam(type: 'string', maxLength: 3),
            ],
            providerMapping: [
                'ga4' => 'add_payment_info',
                'meta' => 'AddPaymentInfo',
            ],
        );

        $this->schemas['add_to_wishlist'] = new EventSchema(
            name: 'add_to_wishlist',
            category: 'ecommerce',
            description: 'Tracks adding an item to the wishlist',
            requiredParams: [
                'item_id' => new EventParam(type: 'string', maxLength: 100),
            ],
            optionalParams: [
                'item_name' => new EventParam(type: 'string', maxLength: 200),
                'item_category' => new EventParam(type: 'string', maxLength: 100),
                'price' => new EventParam(type: 'float', min: 0),
                'currency' => new EventParam(type: 'string', maxLength: 3),
            ],
            providerMapping: [
                'ga4' => 'add_to_wishlist',
                'meta' => 'AddToWishlist',
            ],
        );

        $this->schemas['purchase'] = new EventSchema(
            name: 'purchase',
            category: 'ecommerce',
            description: 'Tracks a completed purchase transaction',
            requiredParams: [
                'transaction_id' => new EventParam(type: 'string', maxLength: 100),
                'value' => new EventParam(type: 'float', min: 0),
            ],
            optionalParams: [
                'currency' => new EventParam(type: 'string', maxLength: 3),
                'coupon' => new EventParam(type: 'string', maxLength: 50),
                'affiliation' => new EventParam(type: 'string', maxLength: 100),
                'tax' => new EventParam(type: 'float', min: 0),
                'shipping' => new EventParam(type: 'float', min: 0),
                'items' => new EventParam(type: 'array'),
            ],
            providerMapping: [
                'ga4' => 'purchase',
                'meta' => 'Purchase',
            ],
        );

        $this->schemas['refund'] = new EventSchema(
            name: 'refund',
            category: 'ecommerce',
            description: 'Tracks a refund',
            requiredParams: [
                'transaction_id' => new EventParam(type: 'string', maxLength: 100),
            ],
            optionalParams: [
                'value' => new EventParam(type: 'float', min: 0),
                'currency' => new EventParam(type: 'string', maxLength: 3),
                'items' => new EventParam(type: 'array'),
            ],
        );

        $this->schemas['select_item'] = new EventSchema(
            name: 'select_item',
            category: 'ecommerce',
            description: 'Tracks an item selection from a list',
            optionalParams: [
                'item_list_id' => new EventParam(type: 'string', maxLength: 100),
                'item_list_name' => new EventParam(type: 'string', maxLength: 200),
                'items' => new EventParam(type: 'array'),
            ],
            providerMapping: [
                'ga4' => 'select_item',
            ],
        );

        $this->schemas['select_promotion'] = new EventSchema(
            name: 'select_promotion',
            category: 'ecommerce',
            description: 'Tracks a promotion click/selection',
            optionalParams: [
                'promotion_id' => new EventParam(type: 'string', maxLength: 100),
                'promotion_name' => new EventParam(type: 'string', maxLength: 200),
                'creative_name' => new EventParam(type: 'string', maxLength: 200),
                'creative_slot' => new EventParam(type: 'string', maxLength: 100),
                'location_id' => new EventParam(type: 'string', maxLength: 100),
            ],
            providerMapping: [
                'ga4' => 'select_promotion',
            ],
        );

        $this->schemas['view_promotion'] = new EventSchema(
            name: 'view_promotion',
            category: 'ecommerce',
            description: 'Tracks a promotion view/impression',
            optionalParams: [
                'promotion_id' => new EventParam(type: 'string', maxLength: 100),
                'promotion_name' => new EventParam(type: 'string', maxLength: 200),
                'creative_name' => new EventParam(type: 'string', maxLength: 200),
                'creative_slot' => new EventParam(type: 'string', maxLength: 100),
                'location_id' => new EventParam(type: 'string', maxLength: 100),
            ],
            providerMapping: [
                'ga4' => 'view_promotion',
            ],
        );
    }

    /**
     * Register all SaaS lifecycle event schemas.
     */
    private function registerSaaSSchemas(): void
    {
        $this->schemas['sign_up'] = new EventSchema(
            name: 'sign_up',
            category: 'saas',
            description: 'Tracks a new user registration',
            optionalParams: [
                'method' => new EventParam(type: 'string', maxLength: 50, description: 'Signup method (email, google, github)'),
            ],
            providerMapping: [
                'ga4' => 'sign_up',
                'meta' => 'CompleteRegistration',
            ],
        );

        $this->schemas['login'] = new EventSchema(
            name: 'login',
            category: 'saas',
            description: 'Tracks a user login',
            optionalParams: [
                'method' => new EventParam(type: 'string', maxLength: 50, description: 'Auth guard (web, sanctum)'),
            ],
        );

        $this->schemas['logout'] = new EventSchema(
            name: 'logout',
            category: 'saas',
            description: 'Tracks a user logout',
        );

        $this->schemas['start_trial'] = new EventSchema(
            name: 'start_trial',
            category: 'saas',
            description: 'Tracks a trial start',
            optionalParams: [
                'plan' => new EventParam(type: 'string', maxLength: 50),
                'trial_days' => new EventParam(type: 'int', min: 1),
            ],
            providerMapping: [
                'ga4' => 'start_trial',
                'meta' => 'StartTrial',
            ],
        );

        $this->schemas['trial_end'] = new EventSchema(
            name: 'trial_end',
            category: 'saas',
            description: 'Tracks a trial end',
            optionalParams: [
                'plan' => new EventParam(type: 'string', maxLength: 50),
                'converted' => new EventParam(type: 'bool'),
            ],
        );

        $this->schemas['subscribe'] = new EventSchema(
            name: 'subscribe',
            category: 'saas',
            description: 'Tracks a subscription creation or renewal',
            requiredParams: [
                'plan_name' => new EventParam(type: 'string', maxLength: 50),
                'value' => new EventParam(type: 'float'),
            ],
            optionalParams: [
                'currency' => new EventParam(type: 'string', maxLength: 3),
                'billing_cycle' => new EventParam(type: 'string', maxLength: 20),
                'transaction_id' => new EventParam(type: 'string', maxLength: 100),
                'is_renewal' => new EventParam(type: 'bool'),
            ],
            providerMapping: [
                'ga4' => 'subscribe',
                'meta' => 'Subscribe',
            ],
        );

        $this->schemas['plan_upgrade'] = new EventSchema(
            name: 'plan_upgrade',
            category: 'saas',
            description: 'Tracks a plan upgrade',
            requiredParams: [
                'from_plan' => new EventParam(type: 'string', maxLength: 50),
                'to_plan' => new EventParam(type: 'string', maxLength: 50),
            ],
        );

        $this->schemas['plan_downgrade'] = new EventSchema(
            name: 'plan_downgrade',
            category: 'saas',
            description: 'Tracks a plan downgrade',
            requiredParams: [
                'from_plan' => new EventParam(type: 'string', maxLength: 50),
                'to_plan' => new EventParam(type: 'string', maxLength: 50),
            ],
        );

        $this->schemas['cancellation'] = new EventSchema(
            name: 'cancellation',
            category: 'saas',
            description: 'Tracks a subscription cancellation',
            requiredParams: [
                'plan' => new EventParam(type: 'string', maxLength: 50),
            ],
            optionalParams: [
                'reason' => new EventParam(type: 'string', maxLength: 500),
            ],
        );

        $this->schemas['feature_used'] = new EventSchema(
            name: 'feature_used',
            category: 'saas',
            description: 'Tracks a feature usage event',
            requiredParams: [
                'feature' => new EventParam(type: 'string', maxLength: 100),
            ],
            optionalParams: [
                'usage_count' => new EventParam(type: 'int', min: 1),
            ],
        );

        $this->schemas['revenue_tracked'] = new EventSchema(
            name: 'revenue_tracked',
            category: 'saas',
            description: 'Tracks a revenue metric event',
            requiredParams: [
                'amount' => new EventParam(type: 'float', min: 0),
                'currency' => new EventParam(type: 'string', maxLength: 3),
                'revenue_type' => new EventParam(type: 'string', maxLength: 20),
            ],
            optionalParams: [
                'plan_name' => new EventParam(type: 'string', maxLength: 50),
            ],
        );
    }

    /**
     * Register all engagement event schemas.
     */
    private function registerEngagementSchemas(): void
    {
        $this->schemas['page_view'] = new EventSchema(
            name: 'page_view',
            category: 'engagement',
            description: 'Tracks a page view',
            optionalParams: [
                'page_title' => new EventParam(type: 'string', maxLength: 500),
                'page_location' => new EventParam(type: 'string', maxLength: 2000),
                'page_referrer' => new EventParam(type: 'string', maxLength: 2000),
            ],
            providerMapping: [
                'ga4' => 'page_view',
                'meta' => 'PageView',
            ],
        );

        $this->schemas['scroll_depth'] = new EventSchema(
            name: 'scroll_depth',
            category: 'engagement',
            description: 'Tracks scroll depth percentage',
            optionalParams: [
                'percent' => new EventParam(type: 'int', min: 0, max: 100),
                'page_location' => new EventParam(type: 'string', maxLength: 2000),
            ],
        );

        $this->schemas['click'] = new EventSchema(
            name: 'click',
            category: 'engagement',
            description: 'Tracks a click interaction',
            optionalParams: [
                'element' => new EventParam(type: 'string', maxLength: 200),
                'page' => new EventParam(type: 'string', maxLength: 2000),
            ],
        );

        $this->schemas['form_start'] = new EventSchema(
            name: 'form_start',
            category: 'engagement',
            description: 'Tracks when a user starts interacting with a form',
            optionalParams: [
                'form_name' => new EventParam(type: 'string', maxLength: 100),
                'form_id' => new EventParam(type: 'string', maxLength: 100),
                'form_action' => new EventParam(type: 'string', maxLength: 2000),
                'page_location' => new EventParam(type: 'string', maxLength: 2000),
            ],
        );

        $this->schemas['form_submit'] = new EventSchema(
            name: 'form_submit',
            category: 'engagement',
            description: 'Tracks a form submission',
            optionalParams: [
                'form_name' => new EventParam(type: 'string', maxLength: 100),
                'form_id' => new EventParam(type: 'string', maxLength: 100),
                'form_method' => new EventParam(type: 'string', maxLength: 10),
                'page_location' => new EventParam(type: 'string', maxLength: 2000),
            ],
            providerMapping: [
                'meta' => 'Lead',
            ],
        );

        $this->schemas['search'] = new EventSchema(
            name: 'search',
            category: 'engagement',
            description: 'Tracks a search query',
            requiredParams: [
                'query' => new EventParam(type: 'string', maxLength: 500),
            ],
            optionalParams: [
                'results' => new EventParam(type: 'int', min: 0),
            ],
            providerMapping: [
                'ga4' => 'search',
                'meta' => 'Search',
            ],
        );

        $this->schemas['share'] = new EventSchema(
            name: 'share',
            category: 'engagement',
            description: 'Tracks content sharing',
            optionalParams: [
                'method' => new EventParam(type: 'string', maxLength: 50, description: 'Share method (email, twitter, etc.)'),
                'content_type' => new EventParam(type: 'string', maxLength: 50),
                'item_id' => new EventParam(type: 'string', maxLength: 100),
            ],
            providerMapping: [
                'ga4' => 'share',
                'meta' => 'Share',
            ],
        );

        $this->schemas['error'] = new EventSchema(
            name: 'error',
            category: 'engagement',
            description: 'Tracks an error event',
            optionalParams: [
                'code' => new EventParam(type: 'int'),
                'message' => new EventParam(type: 'string', maxLength: 1000),
                'url' => new EventParam(type: 'string', maxLength: 2000),
                'error_type' => new EventParam(type: 'string', maxLength: 100),
            ],
        );

        $this->schemas['time_on_page'] = new EventSchema(
            name: 'time_on_page',
            category: 'engagement',
            description: 'Tracks time spent on a page',
            optionalParams: [
                'seconds' => new EventParam(type: 'int', min: 0),
                'page_location' => new EventParam(type: 'string', maxLength: 2000),
            ],
        );

        $this->schemas['campaign_attribution'] = new EventSchema(
            name: 'campaign_attribution',
            category: 'engagement',
            description: 'Tracks UTM campaign attribution',
            optionalParams: [
                'source' => new EventParam(type: 'string', maxLength: 200),
                'medium' => new EventParam(type: 'string', maxLength: 100),
                'campaign' => new EventParam(type: 'string', maxLength: 200),
                'term' => new EventParam(type: 'string', maxLength: 200),
                'content' => new EventParam(type: 'string', maxLength: 200),
                'landing_page' => new EventParam(type: 'string', maxLength: 2000),
            ],
        );

        $this->schemas['screen_view'] = new EventSchema(
            name: 'screen_view',
            category: 'engagement',
            description: 'Tracks a screen/view navigation in SPAs',
            requiredParams: [
                'screen_name' => new EventParam(type: 'string', maxLength: 200),
            ],
            optionalParams: [
                'screen_class' => new EventParam(type: 'string', maxLength: 50),
            ],
            providerMapping: [
                'ga4' => 'screen_view',
            ],
        );

        $this->schemas['ab_test_exposure'] = new EventSchema(
            name: 'ab_test_exposure',
            category: 'engagement',
            description: 'Tracks A/B test variant exposure',
            requiredParams: [
                'experiment_id' => new EventParam(type: 'string', maxLength: 100),
                'variant_id' => new EventParam(type: 'string', maxLength: 100),
            ],
            optionalParams: [
                'experiment_name' => new EventParam(type: 'string', maxLength: 200),
            ],
            providerMapping: [
                'ga4' => 'ab_test_exposure',
            ],
        );

        $this->schemas['notification'] = new EventSchema(
            name: 'notification',
            category: 'engagement',
            description: 'Tracks notification events (sent, delivered, opened, clicked, failed)',
            requiredParams: [
                'notification_channel' => new EventParam(type: 'string', maxLength: 20),
                'notification_action' => new EventParam(type: 'string', maxLength: 20),
            ],
            optionalParams: [
                'notification_type' => new EventParam(type: 'string', maxLength: 100),
            ],
            providerMapping: [
                'ga4' => 'notification',
            ],
        );
    }

    /**
     * Register core / system event schemas.
     */
    private function registerCoreSchemas(): void
    {
        $this->schemas['identify'] = new EventSchema(
            name: 'identify',
            category: 'core',
            description: 'Links a client ID to a user ID',
            optionalParams: [
                'user_id' => new EventParam(type: 'string', maxLength: 100),
                'client_id' => new EventParam(type: 'string', maxLength: 100),
            ],
        );

        $this->schemas['session_start'] = new EventSchema(
            name: 'session_start',
            category: 'core',
            description: 'Tracks a session start',
            optionalParams: [
                'session_id' => new EventParam(type: 'string', maxLength: 100),
            ],
        );

        $this->schemas['session_end'] = new EventSchema(
            name: 'session_end',
            category: 'core',
            description: 'Tracks a session end',
            optionalParams: [
                'session_id' => new EventParam(type: 'string', maxLength: 100),
                'session_duration_ms' => new EventParam(type: 'int', min: 0),
                'session_page_count' => new EventParam(type: 'int', min: 0),
            ],
        );

        $this->schemas['funnel_step'] = new EventSchema(
            name: 'funnel_step',
            category: 'core',
            description: 'Tracks a funnel step',
            optionalParams: [
                'funnel_name' => new EventParam(type: 'string', maxLength: 50),
                'funnel_step' => new EventParam(type: 'string', maxLength: 50),
                'funnel_step_number' => new EventParam(type: 'int', min: 1),
            ],
        );

        $this->schemas['funnel_complete'] = new EventSchema(
            name: 'funnel_complete',
            category: 'core',
            description: 'Tracks a completed funnel',
            optionalParams: [
                'funnel_name' => new EventParam(type: 'string', maxLength: 50),
                'funnel_total_steps' => new EventParam(type: 'int', min: 1),
            ],
        );

        $this->schemas['funnel_abandon'] = new EventSchema(
            name: 'funnel_abandon',
            category: 'core',
            description: 'Tracks funnel abandonment',
            optionalParams: [
                'funnel_name' => new EventParam(type: 'string', maxLength: 50),
                'funnel_abandoned_at_step' => new EventParam(type: 'string', maxLength: 50),
                'funnel_total_steps' => new EventParam(type: 'int', min: 1),
            ],
        );

        $this->schemas['web_vitals'] = new EventSchema(
            name: 'web_vitals',
            category: 'engagement',
            description: 'Tracks a Web Vitals metric',
            optionalParams: [
                'metric_name' => new EventParam(type: 'string', maxLength: 20),
                'metric_value' => new EventParam(type: 'float'),
                'page_location' => new EventParam(type: 'string', maxLength: 2000),
                'rating' => new EventParam(type: 'string', maxLength: 20),
            ],
        );

        $this->schemas['js_error'] = new EventSchema(
            name: 'js_error',
            category: 'engagement',
            description: 'Tracks a JavaScript error',
            optionalParams: [
                'error_message' => new EventParam(type: 'string', maxLength: 1000),
                'error_source' => new EventParam(type: 'string', maxLength: 2000),
                'error_line' => new EventParam(type: 'int'),
                'error_col' => new EventParam(type: 'int'),
                'error_type' => new EventParam(type: 'string', maxLength: 100),
                'page_location' => new EventParam(type: 'string', maxLength: 2000),
            ],
        );

        $this->schemas['timing'] = new EventSchema(
            name: 'timing',
            category: 'engagement',
            description: 'Tracks a timing event',
            optionalParams: [
                'timing_name' => new EventParam(type: 'string', maxLength: 100),
                'timing_duration_ms' => new EventParam(type: 'int', min: 0),
                'page_location' => new EventParam(type: 'string', maxLength: 2000),
            ],
        );

        $this->schemas['set_user_properties'] = new EventSchema(
            name: 'set_user_properties',
            category: 'core',
            description: 'Sets user traits/properties across all providers',
            optionalParams: [
                'user_id' => new EventParam(type: 'string', maxLength: 100),
            ],
        );

        $this->schemas['alias'] = new EventSchema(
            name: 'alias',
            category: 'core',
            description: 'Merges two user identities (anonymous → authenticated)',
            requiredParams: [
                'previous_id' => new EventParam(type: 'string', maxLength: 200),
                'new_id' => new EventParam(type: 'string', maxLength: 200),
            ],
        );

        $this->schemas['outbound_click'] = new EventSchema(
            name: 'outbound_click',
            category: 'engagement',
            description: 'Tracks an outbound (external) link click',
            optionalParams: [
                'link_name' => new EventParam(type: 'string', maxLength: 200),
                'link_url' => new EventParam(type: 'string', maxLength: 2048),
                'link_type' => new EventParam(type: 'string', maxLength: 20),
                'link_text' => new EventParam(type: 'string', maxLength: 200),
                'page_location' => new EventParam(type: 'string', maxLength: 2000),
            ],
        );

        $this->schemas['internal_click'] = new EventSchema(
            name: 'internal_click',
            category: 'engagement',
            description: 'Tracks an internal (same-origin) link click',
            optionalParams: [
                'link_name' => new EventParam(type: 'string', maxLength: 200),
                'link_url' => new EventParam(type: 'string', maxLength: 2048),
                'link_type' => new EventParam(type: 'string', maxLength: 20),
                'link_text' => new EventParam(type: 'string', maxLength: 200),
                'page_location' => new EventParam(type: 'string', maxLength: 2000),
            ],
        );

        // ── Cohort Analytics Schemas ───────────────────────────────────

        $this->schemas['cohort_assigned'] = new EventSchema(
            name: 'cohort_assigned',
            category: 'saas',
            description: 'Tracks assigning a user to a time-based cohort',
            requiredParams: [
                'user_id' => new EventParam(type: 'string', maxLength: 100),
                'cohort_name' => new EventParam(type: 'string', maxLength: 50),
            ],
            optionalParams: [
                'cohort_type' => new EventParam(type: 'string', maxLength: 20),
            ],
        );

        $this->schemas['cohort_retention'] = new EventSchema(
            name: 'cohort_retention',
            category: 'saas',
            description: 'Tracks a user returning within a cohort (retention)',
            requiredParams: [
                'user_id' => new EventParam(type: 'string', maxLength: 100),
                'cohort_name' => new EventParam(type: 'string', maxLength: 50),
                'retention_day' => new EventParam(type: 'int', min: 1),
            ],
            optionalParams: [
                'retention_period' => new EventParam(type: 'string', maxLength: 10),
            ],
        );

        $this->schemas['cohort_churn'] = new EventSchema(
            name: 'cohort_churn',
            category: 'saas',
            description: 'Tracks a user dropping out of a cohort',
            requiredParams: [
                'user_id' => new EventParam(type: 'string', maxLength: 100),
                'cohort_name' => new EventParam(type: 'string', maxLength: 50),
            ],
            optionalParams: [
                'churn_day' => new EventParam(type: 'int', min: 1),
                'churn_period' => new EventParam(type: 'string', maxLength: 10),
                'churn_reason' => new EventParam(type: 'string', maxLength: 200),
            ],
        );

        $this->schemas['cohort_conversion'] = new EventSchema(
            name: 'cohort_conversion',
            category: 'saas',
            description: 'Tracks a cohort conversion (e.g. trial-to-paid)',
            requiredParams: [
                'user_id' => new EventParam(type: 'string', maxLength: 100),
                'cohort_name' => new EventParam(type: 'string', maxLength: 50),
                'conversion_type' => new EventParam(type: 'string', maxLength: 50),
            ],
        );

        $this->schemas['cohort_migration'] = new EventSchema(
            name: 'cohort_migration',
            category: 'saas',
            description: 'Tracks a user migrating between cohorts',
            requiredParams: [
                'user_id' => new EventParam(type: 'string', maxLength: 100),
                'from_cohort' => new EventParam(type: 'string', maxLength: 50),
                'to_cohort' => new EventParam(type: 'string', maxLength: 50),
            ],
        );

        $this->schemas['cohort_engagement'] = new EventSchema(
            name: 'cohort_engagement',
            category: 'saas',
            description: 'Tracks cohort engagement summary (active/total users)',
            requiredParams: [
                'cohort_name' => new EventParam(type: 'string', maxLength: 50),
                'active_users' => new EventParam(type: 'int', min: 0),
                'total_users' => new EventParam(type: 'int', min: 0),
            ],
            optionalParams: [
                'engagement_rate' => new EventParam(type: 'float', min: 0, max: 100),
                'period' => new EventParam(type: 'string', maxLength: 20),
            ],
        );

        // ── Session Analytics Schema ────────────────────────────────────

        $this->schemas['analytics_session_summary'] = new EventSchema(
            name: 'analytics_session_summary',
            category: 'core',
            description: 'Tracks a session summary on session end with aggregated engagement stats',
            requiredParams: [
                'session_id' => new EventParam(type: 'string', maxLength: 100),
            ],
            optionalParams: [
                'session_event_count' => new EventParam(type: 'int', min: 0),
                'session_page_count' => new EventParam(type: 'int', min: 0),
                'session_unique_events' => new EventParam(type: 'int', min: 0),
                'session_duration_estimate' => new EventParam(type: 'int', min: 0),
                'session_event_types' => new EventParam(type: 'array'),
            ],
        );
    }
}
