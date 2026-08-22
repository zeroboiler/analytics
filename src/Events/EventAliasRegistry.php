<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events;

/**
 * Maps common alternative event names to canonical catalog names.
 *
 * Provides an alias resolution layer for event names that users might
 * pass in various formats — shorthand, alternate casing, common
 * synonyms, or legacy names from other analytics SDKs.
 *
 * Extends EventCatalog::resolve() with domain-specific aliases that
 * don't follow snake_case naming conventions.
 *
 * Usage:
 *   $canonical = EventAliasRegistry::resolve('signup');      // 'sign_up'
 *   $canonical = EventAliasRegistry::resolve('order');       // 'purchase'
 *   $canonical = EventAliasRegistry::resolve('AddToCart');   // 'add_to_cart'
 *
 * @since 238.0.0
 */
final class EventAliasRegistry
{
    /**
     * Alias → canonical event name mapping.
     *
     * Keys are alternative names users might use; values are the
     * canonical snake_case event names from the EventCatalog.
     *
     * @var array<string, string>
     */
    private static array $aliases = [];

    /**
     * Build the alias map (lazy initialization).
     *
     * @return array<string, string>
     */
    private static function map(): array
    {
        if (self::$aliases !== []) {
            return self::$aliases;
        }

        self::$aliases = [
            // ── E-commerce Aliases ────────────────────────────
            'product_view' => 'view_item',
            'view_product' => 'view_item',
            'product_viewed' => 'view_item',
            'item_view' => 'view_item',
            'cart_add' => 'add_to_cart',
            'add_item' => 'add_to_cart',
            'cart_remove' => 'remove_from_cart',
            'checkout' => 'begin_checkout',
            'checkout_start' => 'begin_checkout',
            'start_checkout' => 'begin_checkout',
            'initiate_checkout' => 'begin_checkout',
            'order' => 'purchase',
            'order_completed' => 'purchase',
            'transaction' => 'purchase',
            'complete_order' => 'purchase',
            'payment' => 'purchase',

            // ── SaaS Aliases ───────────────────────────────────
            'signup' => 'sign_up',
            'register' => 'sign_up',
            'registration' => 'sign_up',
            'user_signup' => 'sign_up',
            'sign_in' => 'login',
            'signin' => 'login',
            'user_login' => 'login',
            'auth' => 'login',
            'signout' => 'logout',
            'sign_out' => 'logout',
            'user_logout' => 'logout',
            'trial' => 'start_trial',
            'trial_start' => 'start_trial',
            'free_trial' => 'start_trial',
            'start_free_trial' => 'start_trial',
            'subscription' => 'subscribe',
            'upgrade' => 'plan_upgrade',
            'downgrade' => 'plan_downgrade',
            'cancel' => 'cancellation',
            'unsubscribe' => 'cancellation',
            'churn' => 'cancellation',
            'feature_use' => 'feature_used',
            'feature_activation' => 'feature_adopted',

            // ── Engagement Aliases ─────────────────────────────
            'pageview' => 'page_view',
            'pageview_event' => 'page_view',
            'pv' => 'page_view',
            'scroll' => 'scroll_depth',
            'button_click' => 'click',
            'element_click' => 'click',
            'cta_click' => 'click',
            'form_submission' => 'form_submit',
            'form_complete' => 'form_submit',
            'site_search' => 'search',
            'internal_search' => 'search',
            'social_share' => 'share',
            'exception' => 'error',
            'js_exception' => 'error',
            'client_error' => 'error',

            // ── Infrastructure Aliases ─────────────────────────
            'deploy' => 'deployment',
            'deployment_rolled_back' => 'deployment',
            'incident' => 'incident_started',
            'outage' => 'service_down',
            'error_spike' => 'error_spike',
            'latency' => 'api_latency',
            'service_up_event' => 'service_up',
            'service_down_event' => 'service_down',

            // ── Marketing Aliases ───────────────────────────────
            'email_open' => 'email_opened',
            'email_click' => 'email_clicked',
            'newsletter_signup' => 'newsletter_subscribed',
            'affiliate_signup' => 'affiliate_signup',
            'referral_conversion' => 'referral_conversion',
        ];

        return self::$aliases;
    }

    /**
     * Resolve an alias to its canonical event name.
     *
     * First checks EventCatalog::resolve() for built-in normalization
     * (snake_case, camelCase, PascalCase, kebab-case), then falls back
     * to the domain-specific alias map.
     *
     * Returns null if no mapping exists.
     */
    public static function resolve(string $name): ?string
    {
        // 1. Try EventCatalog built-in resolution first
        $catalog = EventCatalog::resolve($name);
        if ($catalog !== null) {
            return $catalog;
        }

        // 2. Normalize to lowercase
        $lower = strtolower($name);

        // 3. Check alias map
        if (isset(self::map()[$lower])) {
            return self::map()[$lower];
        }

        // 4. Try snake_case collapse (double underscores, extra separators)
        $collapsed = preg_replace('/_+/', '_', preg_replace('/[\s\-]+/', '_', $lower)) ?? $lower;
        if (isset(self::map()[$collapsed])) {
            return self::map()[$collapsed];
        }

        return null;
    }

    /**
     * Get all registered aliases.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::map();
    }

    /**
     * Get the total number of registered aliases.
     */
    public static function count(): int
    {
        return count(self::map());
    }

    /**
     * Check if a specific alias exists.
     */
    public static function has(string $alias): bool
    {
        return isset(self::map()[strtolower($alias)]);
    }

    /**
     * Get aliases grouped by target canonical event.
     *
     * Returns a map of canonical event name → list of alias names.
     * Useful for documentation and debugging alias coverage.
     *
     * @return array<string, list<string>>
     */
    public static function groupedByTarget(): array
    {
        $groups = [];

        foreach (self::map() as $alias => $canonical) {
            $groups[$canonical][] = $alias;
        }

        uksort($groups, fn (string $a, string $b): int => count($groups[$b]) <=> count($groups[$a]));

        return $groups;
    }

    /**
     * Get aliases for a specific canonical event name.
     *
     * @return list<string>
     */
    public static function aliasesFor(string $canonicalName): array
    {
        $result = [];

        foreach (self::map() as $alias => $canonical) {
            if ($canonical === $canonicalName) {
                $result[] = $alias;
            }
        }

        return $result;
    }

    /**
     * Add a custom alias at runtime.
     *
     * Useful for application-specific event name mappings that aren't
     * in the built-in alias registry.
     */
    public static function register(string $alias, string $canonicalName): void
    {
        self::map(); // ensure initialized
        self::$aliases[strtolower($alias)] = $canonicalName;
    }

    /**
     * Validate that all alias targets exist in the EventCatalog.
     *
     * Returns a list of aliases whose target events are missing
     * from the catalog (should be empty for a healthy setup).
     *
     * @return list<array{alias: string, target: string}>
     */
    public static function validate(): array
    {
        $invalid = [];

        foreach (self::map() as $alias => $canonical) {
            if (! EventCatalog::has($canonical)) {
                $invalid[] = [
                    'alias' => $alias,
                    'target' => $canonical,
                ];
            }
        }

        return $invalid;
    }
}
