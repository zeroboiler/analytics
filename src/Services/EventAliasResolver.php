<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event alias resolver — maps common event name aliases to canonical names.
 *
 * Provides bidirectional resolution of event name variations. Supports:
 * - CamelCase → snake_case (e.g., `AddToCart` → `add_to_cart`)
 * - Abbreviations (e.g., `signup` → `sign_up`, `atc` → `add_to_cart`)
 * - PostHog convention (e.g., `$signup` → `sign_up`, `$identify` → `identify`)
 * - Plausible variants (e.g., `pageview` → `page_view`)
 * - Custom aliases from config
 *
 * This is useful for normalizing event names from different sources
 * (JS client, server-side, third-party integrations) before dispatch.
 *
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 */
final class EventAliasResolver
{
    /** @var array<string, string> Alias → canonical name */
    private array $aliases = [];

    /** @var array<string, list<string>> Canonical name → aliases */
    private array $reverseMap = [];

    /** @var array<string, string> CamelCase → snake_case cache */
    private array $camelCaseCache = [];

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config): void
: void {
        $this->loadDefaults();
        $this->loadCustomAliases($config);
    }

    /**
     * Resolve an event name to its canonical form.
     *
     * Resolution order:
     * 1. Exact match in catalog (already canonical)
     * 2. Exact match in alias map
     * 3. CamelCase → snake_case conversion
     * 4. PostHog convention stripping ($ prefix)
     * 5. Plausible variant normalization
     *
     * @param  string  $name  The event name to resolve
     * @return string The canonical event name, or the original if no mapping exists
     */
    public function resolve(string $name): string
    {
        // 1. Already canonical
        if (EventCatalog::has($name)) {
            return $name;
        }

        // 2. Direct alias lookup
        $lower = strtolower($name);
        if (isset($this->aliases[$lower])) {
            return $this->aliases[$lower];
        }

        // 3. PostHog convention ($signup, $identify, etc.)
        if (str_starts_with($name, '$')) {
            $stripped = substr($name, 1);
            if (isset($this->aliases[$stripped])) {
                return $this->aliases[$stripped];
            }
            if (EventCatalog::has($stripped)) {
                return $stripped;
            }
        }

        // 4. CamelCase → snake_case
        $snake = $this->camelToSnake($name);
        if (EventCatalog::has($snake)) {
            return $snake;
        }

        // 5. Check if snake_case is a known alias
        if (isset($this->aliases[$snake])) {
            return $this->aliases[$snake];
        }

        // No resolution found — return original
        return $name;
    }

    /**
     * Check if a name is a known alias (not a canonical name).
     */
    public function isAlias(string $name): bool
    {
        return isset($this->aliases[strtolower($name)]);
    }

    /**
     * Check if a name is already a canonical event name.
     */
    public function isCanonical(string $name): bool
    {
        return EventCatalog::has($name);
    }

    /**
     * Get all aliases for a canonical event name.
     *
     * @return list<string>
     */
    public function getAliasesFor(string $canonicalName): array
    {
        return $this->reverseMap[$canonicalName] ?? [];
    }

    /**
     * Get the total number of registered aliases.
     */
    public function aliasCount(): int
    {
        return count($this->aliases);
    }

    /**
     * Get all registered aliases.
     *
     * @return array<string, string>
     */
    public function allAliases(): array
    {
        return $this->aliases;
    }

    /**
     * Register a custom alias at runtime.
     *
     * @param  string  $alias  The alias name
     * @param  string  $canonical  The canonical event name
     */
    public function addAlias(string $alias, string $canonical): void
    {
        $key = strtolower($alias);
        $this->aliases[$key] = $canonical;
        $this->reverseMap[$canonical][] = $alias;
    }

    /**
     * Remove an alias.
     */
    public function removeAlias(string $alias): void
    {
        $key = strtolower($alias);
        if (isset($this->aliases[$key])) {
            $canonical = $this->aliases[$key];
            unset($this->aliases[$key]);
            $this->reverseMap[$canonical] = array_values(
                array_filter(
                    $this->reverseMap[$canonical] ?? [],
                    fn (string $a): bool => strtolower($a) !== $key,
                ),
            );
        }
    }

    /**
     * Resolve a batch of event names to canonical forms.
     *
     * @param  list<string>  $names
     * @return array<string, string> Original → canonical
     */
    public function resolveBatch(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $result[$name] = $this->resolve($name);
        }

        return $result;
    }

    /**
     * Get a summary of the alias resolver state.
     *
     * @return array{alias_count: int, canonical_count: int, categories: array<string, int>, version: string}
     */
    public function summary(): array
    {
        $categories = EventCatalog::byCategory();

        return [
            'alias_count' => count($this->aliases),
            'canonical_count' => EventCatalog::count(),
            'categories' => [
                'ecommerce' => count($categories['ecommerce']),
                'saas' => count($categories['saas']),
                'engagement' => count($categories['engagement']),
            ],
            'version' => '2.58.0',
        ];
    }

    /**
     * Load default aliases for all catalog events.
     */
    private function loadDefaults(): void
    {
        // E-commerce aliases
        $defaults = [
            // Common abbreviations and variations
            'signup' => 'sign_up',
            'register' => 'sign_up',
            'signup_complete' => 'sign_up',
            'registration' => 'sign_up',
            'login_complete' => 'login',
            'signin' => 'login',
            'signout' => 'logout',
            'sign_out' => 'logout',
            'trial_start' => 'start_trial',
            'trial_started' => 'start_trial',
            'trial_end' => 'trial_end',
            'trial_ended' => 'trial_end',
            'subscription_created' => 'subscribe',
            'subscription_create' => 'subscribe',
            'subscribed' => 'subscribe',
            'plan_upgraded' => 'plan_upgrade',
            'upgrade_plan' => 'plan_upgrade',
            'plan_downgraded' => 'plan_downgrade',
            'downgrade_plan' => 'plan_downgrade',
            'subscription_cancelled' => 'cancellation',
            'subscription_canceled' => 'cancellation',
            'churn' => 'cancellation',
            'revenue' => 'revenue_tracked',
            'feature' => 'feature_used',
            'pageview' => 'page_view',
            'pageview_event' => 'page_view',
            'scroll' => 'scroll_depth',
            'scroll_event' => 'scroll_depth',
            'button_click' => 'click',
            'link_click' => 'click',
            'form_submission' => 'form_submit',
            'lead' => 'form_submit',
            'search_query' => 'search',
            'site_search' => 'search',
            'share_event' => 'share',
            'social_share' => 'share',
            'error_event' => 'error',
            'exception' => 'error',
            'exception_event' => 'error',
            'time_on_page' => 'time_on_page',
            'screen' => 'screen_view',
            'ab_test' => 'ab_test_exposure',
            'experiment' => 'ab_test_exposure',
            'notification_event' => 'notification',
            'web_vitals_event' => 'web_vitals',
            'core_web_vitals' => 'web_vitals',
            'js_exception' => 'js_error',
            'javascript_error' => 'js_error',
            'timing_event' => 'timing',
            'user_timing' => 'timing',
            'session_begin' => 'session_start',
            'session_close' => 'session_end',
            'external_click' => 'outbound_click',
            'outbound' => 'outbound_click',
            'download' => 'file_download',
            'file_downloaded' => 'file_download',
            'video' => 'video_play',
            'video_started' => 'video_play',
            // E-commerce
            'view_product' => 'view_item',
            'product_view' => 'view_item',
            'item_view' => 'view_item',
            'cart_add' => 'add_to_cart',
            'addtocart' => 'add_to_cart',
            'remove_from_cart_event' => 'remove_from_cart',
            'cart_remove' => 'remove_from_cart',
            'cart_view' => 'view_cart',
            'checkout_start' => 'begin_checkout',
            'begin_checkout_event' => 'begin_checkout',
            'checkout' => 'begin_checkout',
            'payment_info' => 'add_payment_info',
            'add_payment' => 'add_payment_info',
            'order_complete' => 'purchase',
            'order' => 'purchase',
            'order_placed' => 'purchase',
            'transaction' => 'purchase',
            'refund_event' => 'refund',
            'order_refund' => 'refund',
            'wish_list' => 'add_to_wishlist',
            'add_wishlist' => 'add_to_wishlist',
            'product_select' => 'select_item',
            'item_select' => 'select_item',
            'promotion_select' => 'select_promotion',
            'promo_select' => 'select_promotion',
            'promotion_view' => 'view_promotion',
            'promo_view' => 'view_promotion',
            // SaaS lifecycle
            'account_activate' => 'account_activated',
            'account_deactivate' => 'account_deactivated',
            'password_update' => 'password_changed',
            'password_change' => 'password_changed',
            'password_forgot' => 'password_reset',
            'reset_password' => 'password_reset',
            'profile_update' => 'profile_updated',
            'email_verify' => 'email_verified',
            'team_create' => 'team_created',
            'team_member_add' => 'team_member_joined',
            'team_member_remove' => 'team_member_removed',
            'member_removed' => 'team_member_removed',
            'role_update' => 'role_changed',
            'payment_fail' => 'payment_failed',
            'payment_error' => 'payment_failed',
            'payment_success' => 'payment_succeeded',
            'payment_ok' => 'payment_succeeded',
            'payment_method' => 'payment_method_added',
            'invoice' => 'invoice_generated',
            'credit' => 'credit_applied',
            'invite' => 'invite_sent',
            'integration_connect' => 'integration_connected',
            'renewal' => 'subscription_renewal',
            'subscription_renew' => 'subscription_renewal',
            'limit_reached' => 'feature_limit_reached',
            'integration_fail' => 'integration_failed',
            // Cohort events
            'cohort_assign' => 'cohort_assigned',
            'cohort_retain' => 'cohort_retention',
            'cohort_churn_event' => 'cohort_churn',
            'cohort_convert' => 'cohort_conversion',
            'cohort_migrate' => 'cohort_migration',
            'cohort_engage' => 'cohort_engagement',
            // Engagement variations
            'campaign_attribution_event' => 'campaign_attribution',
            'utm_attribution' => 'campaign_attribution',
            'performance_timing' => 'timing',
            'app_error' => 'error',
            'api_error' => 'error',
        ];

        foreach ($defaults as $alias => $canonical) {
            $this->aliases[strtolower($alias)] = $canonical;
            $this->reverseMap[$canonical][] = $alias;
        }

        // Auto-generate CamelCase aliases for all catalog events
        $allEvents = EventCatalog::all();
        foreach ($allEvents as $name => $entry) {
            $camel = $this->snakeToCamel($name);
            if ($camel !== $name) {
                $this->camelCaseCache[$camel] = $name;
                $this->aliases[strtolower($camel)] = $name;
                $this->reverseMap[$name][] = $camel;
            }
        }
    }

    /**
     * Load custom aliases from config.
     *
     * @param  ConfigRepository  $config
     */
    private function loadCustomAliases(ConfigRepository $config): void
    {
        $custom = $config->get('zeroboiler.analytics.aliases', []);
        /** @var array<string, string> $custom */

        foreach ($custom as $alias => $canonical) {
            $this->aliases[strtolower((string) $alias)] = (string) $canonical;
            $this->reverseMap[(string) $canonical][] = (string) $alias;
        }
    }

    /**
     * Convert CamelCase to snake_case.
     *
     * Handles PascalCase (AddToCart), camelCase (addToCart),
     * and already-snake names (add_to_cart).
     */
    private function camelToSnake(string $input): string
    {
        $lower = strtolower($input);

        // Already snake_case
        if ($lower === $input || ! preg_match('/[A-Z]/', $input)) {
            return $input;
        }

        // Strip PostHog $ prefix
        if (str_starts_with($input, '$')) {
            $input = substr($input, 1);
        }

        $result = preg_replace('/([a-z])([A-Z])/', '$1_$2', $input);
        $result = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $result);

        return strtolower($result ?? $input);
    }

    /**
     * Convert snake_case to CamelCase.
     */
    private function snakeToCamel(string $input): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $input)));
    }
}
