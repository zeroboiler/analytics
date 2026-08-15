<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * SaaS event format converter for cross-provider data transformation.
 *
 * Provides bidirectional parameter structure conversion for SaaS lifecycle
 * events (sign_up, login, trial_start, subscription, plan_upgrade, cancellation)
 * between GA4, Meta Pixel, PostHog, and generic formats.
 *
 * Unlike EventTransformer (which maps event names), this service focuses on
 * the detailed parameter structure differences between providers — the SaaS
 * equivalent of EcommerceFormatConverter.
 *
 * Meta Pixel uses standard events: CompleteRegistration, Lead, Subscribe,
 * StartTrial, CancelSubscription, etc. with specific required parameters.
 *
 * PostHog uses $set/$identify properties and custom event properties.
 *
 * GA4 uses custom events with user_properties for SaaS metrics.
 *
 * @since 158.0.0
 */
final class SaaSFormatConverter
{
    // ── sign_up → Meta CompleteRegistration ───────────────────────────

    /**
     * Convert sign_up event params to Meta Pixel CompleteRegistration format.
     *
     * Meta requires: status (required), value (optional), currency (optional).
     * FB Standard Events reference: CompleteRegistration tracks when a
     * registration form is completed.
     *
     * @param  array<string, mixed>  $params  Internal sign_up params
     * @return array{status: string, value: float, currency: string, content_name: string, method: string|null, predicted_ltv: float|null, ...}
     */
    public static function signUpToMeta(array $params): array
    {
        return [
            'status' => (string) ($params['status'] ?? 'completed'),
            'value' => (float) ($params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'content_name' => (string) ($params['content_name'] ?? 'sign_up'),
            'method' => isset($params['method']) ? (string) $params['method'] : null,
            'predicted_ltv' => isset($params['predicted_ltv']) ? (float) $params['predicted_ltv'] : null,
        ];
    }

    /**
     * Convert sign_up event params to PostHog $signup properties.
     *
     * PostHog's $signup is an autocaptured event. Enrich with user properties
     * via $set for downstream cohort and funnel analysis.
     *
     * @param  array<string, mixed>  $params  Internal sign_up params
     * @return array{signup_method: string|null, signup_source: string|null, is_paid: bool, plan: string|null, ...}
     */
    public static function signUpToPosthog(array $params): array
    {
        return [
            'signup_method' => $params['method'] ?? null,
            'signup_source' => $params['source'] ?? null,
            'is_paid' => (bool) ($params['is_paid'] ?? false),
            'plan' => $params['plan'] ?? null,
            '$signup_code' => $params['referral_code'] ?? null,
        ];
    }

    /**
     * Convert sign_up event params to GA4 custom event format.
     *
     * GA4 recommended params: method, coupon, value, currency.
     * Additional user_properties are attached server-side.
     *
     * @param  array<string, mixed>  $params  Internal sign_up params
     * @return array{method: string|null, coupon: string|null, value: float, currency: string}
     */
    public static function signUpToGa4(array $params): array
    {
        return [
            'method' => $params['method'] ?? null,
            'coupon' => $params['referral_code'] ?? null,
            'value' => (float) ($params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
        ];
    }

    // ── login → Meta / PostHog / GA4 ─────────────────────────────────

    /**
     * Convert login event params to Meta Pixel Login format.
     *
     * @param  array<string, mixed>  $params  Internal login params
     * @return array{method: string|null, content_name: string}
     */
    public static function loginToMeta(array $params): array
    {
        return [
            'method' => $params['method'] ?? null,
            'content_name' => (string) ($params['content_name'] ?? 'login'),
        ];
    }

    /**
     * Convert login event params to PostHog $set properties.
     *
     * PostHog uses $identify for login events. Attach user properties
     * for identity resolution and cohort segmentation.
     *
     * @param  array<string, mixed>  $params  Internal login params
     * @return array{login_method: string|null, login_source: string|null, is_first_login: bool}
     */
    public static function loginToPosthog(array $params): array
    {
        return [
            'login_method' => $params['method'] ?? null,
            'login_source' => $params['source'] ?? null,
            'is_first_login' => (bool) ($params['is_first_login'] ?? false),
        ];
    }

    // ── trial_start → Meta StartTrial ──────────────────────────────────

    /**
     * Convert trial_start event params to Meta Pixel StartTrial format.
     *
     * Meta's StartTrial event is a standard e-commerce event used for
     * SaaS free trial signups. Includes value prediction and currency.
     *
     * @param  array<string, mixed>  $params  Internal trial_start params
     * @return array{value: float, currency: string, predicted_ltv: float|null, content_name: string, trial_days: int|null, plan: string|null}
     */
    public static function trialStartToMeta(array $params): array
    {
        return [
            'value' => (float) ($params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'predicted_ltv' => $params['predicted_ltv'] ?? null,
            'content_name' => (string) ($params['content_name'] ?? 'start_trial'),
            'trial_days' => isset($params['trial_days']) ? (int) $params['trial_days'] : null,
            'plan' => $params['plan'] ?? null,
        ];
    }

    /**
     * Convert trial_start event params to PostHog properties.
     *
     * @param  array<string, mixed>  $params  Internal trial_start params
     * @return array{trial_days: int|null, plan: string|null, trial_value: float, trial_currency: string, predicted_ltv: float|null}
     */
    public static function trialStartToPosthog(array $params): array
    {
        return [
            'trial_days' => $params['trial_days'] ?? null,
            'plan' => $params['plan'] ?? null,
            'trial_value' => (float) ($params['value'] ?? 0.0),
            'trial_currency' => (string) ($params['currency'] ?? 'USD'),
            'predicted_ltv' => $params['predicted_ltv'] ?? null,
        ];
    }

    /**
     * Convert trial_start event params to GA4 custom event format.
     *
     * @param  array<string, mixed>  $params  Internal trial_start params
     * @return array{method: string|null, value: float, currency: string, trial_days: int|null, plan: string|null, coupon: string|null}
     */
    public static function trialStartToGa4(array $params): array
    {
        return [
            'method' => $params['method'] ?? null,
            'value' => (float) ($params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'trial_days' => $params['trial_days'] ?? null,
            'plan' => $params['plan'] ?? null,
            'coupon' => $params['referral_code'] ?? null,
        ];
    }

    // ── subscription → Meta Subscribe ─────────────────────────────────

    /**
     * Convert subscription event params to Meta Pixel Subscribe format.
     *
     * Meta's Subscribe is a standard event for subscription purchases.
     * Requires: value, currency. Optional: predicted_ltv, content_name.
     *
     * @param  array<string, mixed>  $params  Internal subscription params
     * @return array{value: float, currency: string, predicted_ltv: float|null, content_name: string, plan: string|null, billing_cycle: string|null, is_trial: bool}
     */
    public static function subscriptionToMeta(array $params): array
    {
        return [
            'value' => (float) ($params['value'] ?? $params['revenue'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'predicted_ltv' => $params['predicted_ltv'] ?? null,
            'content_name' => (string) ($params['content_name'] ?? 'subscribe'),
            'plan' => $params['plan'] ?? null,
            'billing_cycle' => $params['billing_cycle'] ?? null,
            'is_trial' => (bool) ($params['is_trial'] ?? false),
        ];
    }

    /**
     * Convert subscription event params to PostHog properties.
     *
     * @param  array<string, mixed>  $params  Internal subscription params
     * @return array{plan: string|null, value: float, currency: string, billing_cycle: string|null, subscription_id: string|null, is_trial: bool, predicted_ltv: float|null}
     */
    public static function subscriptionToPosthog(array $params): array
    {
        return [
            'plan' => $params['plan'] ?? null,
            'value' => (float) ($params['value'] ?? $params['revenue'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'billing_cycle' => $params['billing_cycle'] ?? null,
            'subscription_id' => $params['subscription_id'] ?? null,
            'is_trial' => (bool) ($params['is_trial'] ?? false),
            'predicted_ltv' => $params['predicted_ltv'] ?? null,
        ];
    }

    /**
     * Convert subscription event params to GA4 custom event format.
     *
     * GA4 purchase event is recommended for subscription revenue tracking.
     * Uses GA4 e-commerce params extended with SaaS-specific fields.
     *
     * @param  array<string, mixed>  $params  Internal subscription params
     * @return array{transaction_id: string|null, value: float, currency: string, coupon: string|null, plan: string|null, billing_cycle: string|null, items: list<array<string, mixed>>}
     */
    public static function subscriptionToGa4(array $params): array
    {
        $transactionId = $params['subscription_id'] ?? $params['transaction_id'] ?? null;

        return [
            'transaction_id' => $transactionId,
            'value' => (float) ($params['value'] ?? $params['revenue'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'coupon' => $params['referral_code'] ?? $params['coupon'] ?? null,
            'plan' => $params['plan'] ?? null,
            'billing_cycle' => $params['billing_cycle'] ?? null,
            'items' => [
                [
                    'item_id' => (string) ($params['plan'] ?? 'subscription'),
                    'item_name' => (string) ($params['plan_name'] ?? $params['plan'] ?? 'Subscription'),
                    'item_category' => 'subscription',
                    'price' => (float) ($params['value'] ?? $params['revenue'] ?? 0.0),
                    'quantity' => 1,
                ],
            ],
        ];
    }

    // ── plan_upgrade → Meta / PostHog / GA4 ───────────────────────────

    /**
     * Convert plan_upgrade event params to Meta Pixel format.
     *
     * @param  array<string, mixed>  $params  Internal plan_upgrade params
     * @return array{value: float, currency: string, content_name: string, from_plan: string|null, to_plan: string|null, upgrade_type: string|null}
     */
    public static function planUpgradeToMeta(array $params): array
    {
        return [
            'value' => (float) ($params['value'] ?? $params['revenue_delta'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'content_name' => (string) ($params['content_name'] ?? 'plan_upgrade'),
            'from_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            'to_plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
            'upgrade_type' => $params['upgrade_type'] ?? null,
        ];
    }

    /**
     * Convert plan_upgrade event params to PostHog properties.
     *
     * @param  array<string, mixed>  $params  Internal plan_upgrade params
     * @return array{from_plan: string|null, to_plan: string|null, value_delta: float, currency: string, upgrade_type: string|null, billing_cycle: string|null}
     */
    public static function planUpgradeToPosthog(array $params): array
    {
        return [
            'from_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            'to_plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
            'value_delta' => (float) ($params['value'] ?? $params['revenue_delta'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'upgrade_type' => $params['upgrade_type'] ?? null,
            'billing_cycle' => $params['billing_cycle'] ?? null,
        ];
    }

    /**
     * Convert plan_upgrade event params to GA4 custom event format.
     *
     * @param  array<string, mixed>  $params  Internal plan_upgrade params
     * @return array{from_plan: string|null, to_plan: string|null, value: float, currency: string, upgrade_type: string|null, items: list<array<string, mixed>>}
     */
    public static function planUpgradeToGa4(array $params): array
    {
        return [
            'from_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            'to_plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
            'value' => (float) ($params['value'] ?? $params['revenue_delta'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'upgrade_type' => $params['upgrade_type'] ?? null,
            'items' => [
                [
                    'item_id' => (string) ($params['to_plan'] ?? $params['new_plan'] ?? 'plan_upgrade'),
                    'item_name' => (string) ($params['to_plan'] ?? $params['new_plan'] ?? 'Plan Upgrade'),
                    'item_category' => 'plan_upgrade',
                    'price' => (float) ($params['value'] ?? $params['revenue_delta'] ?? 0.0),
                    'quantity' => 1,
                ],
            ],
        ];
    }

    // ── cancellation → Meta CancelSubscription ───────────────────────

    /**
     * Convert cancellation event params to Meta Pixel CancelSubscription format.
     *
     * @param  array<string, mixed>  $params  Internal cancellation params
     * @return array{value: float, currency: string, content_name: string, plan: string|null, reason: string|null, cancellation_type: string|null}
     */
    public static function cancellationToMeta(array $params): array
    {
        return [
            'value' => (float) ($params['lost_revenue'] ?? $params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'content_name' => (string) ($params['content_name'] ?? 'cancellation'),
            'plan' => $params['plan'] ?? null,
            'reason' => $params['reason'] ?? null,
            'cancellation_type' => $params['cancellation_type'] ?? null,
        ];
    }

    /**
     * Convert cancellation event params to PostHog properties.
     *
     * @param  array<string, mixed>  $params  Internal cancellation params
     * @return array{plan: string|null, reason: string|null, cancellation_type: string|null, lost_mrr: float, currency: string, tenure_days: int|null, nps_before: int|null}
     */
    public static function cancellationToPosthog(array $params): array
    {
        return [
            'plan' => $params['plan'] ?? null,
            'reason' => $params['reason'] ?? null,
            'cancellation_type' => $params['cancellation_type'] ?? null,
            'lost_mrr' => (float) ($params['lost_revenue'] ?? $params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'tenure_days' => $params['tenure_days'] ?? null,
            'nps_before' => $params['nps_before'] ?? null,
        ];
    }

    /**
     * Convert cancellation event params to GA4 custom event format.
     *
     * @param  array<string, mixed>  $params  Internal cancellation params
     * @return array{plan: string|null, reason: string|null, cancellation_type: string|null, value: float, currency: string}
     */
    public static function cancellationToGa4(array $params): array
    {
        return [
            'plan' => $params['plan'] ?? null,
            'reason' => $params['reason'] ?? null,
            'cancellation_type' => $params['cancellation_type'] ?? null,
            'value' => (float) ($params['lost_revenue'] ?? $params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
        ];
    }

    // ── Generic SaaS Event Converter ──────────────────────────────────

    /**
     * Convert any SaaS event to a specific provider's format.
     *
     * Central dispatch method that routes to the appropriate converter
     * based on event name and target provider.
     *
     * @param  string  $eventName  Internal event name (e.g. 'sign_up', 'login', 'trial_start')
     * @param  array<string, mixed>  $params  Internal event params
     * @param  'ga4'|'meta'|'posthog'  $provider  Target provider
     * @return array<string, mixed>  Provider-formatted params
     */
    public static function convertForProvider(string $eventName, array $params, string $provider): array
    {
        return match ($eventName) {
            'sign_up' => match ($provider) {
                'meta' => self::signUpToMeta($params),
                'posthog' => self::signUpToPosthog($params),
                'ga4' => self::signUpToGa4($params),
                default => $params,
            },
            'login' => match ($provider) {
                'meta' => self::loginToMeta($params),
                'posthog' => self::loginToPosthog($params),
                'ga4' => $params,
                default => $params,
            },
            'start_trial' => match ($provider) {
                'meta' => self::trialStartToMeta($params),
                'posthog' => self::trialStartToPosthog($params),
                'ga4' => self::trialStartToGa4($params),
                default => $params,
            },
            'subscribe' => match ($provider) {
                'meta' => self::subscriptionToMeta($params),
                'posthog' => self::subscriptionToPosthog($params),
                'ga4' => self::subscriptionToGa4($params),
                default => $params,
            },
            'subscription' => match ($provider) {
                'meta' => self::subscriptionToMeta($params),
                'posthog' => self::subscriptionToPosthog($params),
                'ga4' => self::subscriptionToGa4($params),
                default => $params,
            },
            'plan_upgrade' => match ($provider) {
                'meta' => self::planUpgradeToMeta($params),
                'posthog' => self::planUpgradeToPosthog($params),
                'ga4' => self::planUpgradeToGa4($params),
                default => $params,
            },
            'cancellation' => match ($provider) {
                'meta' => self::cancellationToMeta($params),
                'posthog' => self::cancellationToPosthog($params),
                'ga4' => self::cancellationToGa4($params),
                default => $params,
            },
            default => $params,
        };
    }

    /**
     * Build a provider-optimized AnalyticsEvent from internal SaaS params.
     *
     * Convenience method that converts params AND resolves the provider-specific
     * event name (using EventTransformer for name mapping + SaaSFormatConverter
     * for param structure).
     *
     * @param  string  $eventName  Internal event name
     * @param  array<string, mixed>  $params  Internal event params
     * @param  'ga4'|'meta'|'posthog'  $provider  Target provider
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     * @return AnalyticsEvent  Provider-optimized event
     */
    public static function buildProviderEvent(
        string $eventName,
        array $params,
        string $provider,
        ?string $clientId = null,
        ?string $userId = null,
    ): AnalyticsEvent {
        $convertedParams = self::convertForProvider($eventName, $params, $provider);

        return new AnalyticsEvent(
            name: $eventName,
            params: $convertedParams,
            clientId: $clientId,
            userId: $userId,
            category: 'saas',
        );
    }

    // ── PostHog $set User Properties Builder ──────────────────────────

    /**
     * Build PostHog $set properties from a SaaS event.
     *
     * Returns user-level properties suitable for PostHog's $set call
     * (called alongside $identify on sign_up, login, plan_change).
     *
     * @param  string  $eventName  Internal event name
     * @param  array<string, mixed>  $params  Internal event params
     * @return array<string, mixed>  PostHog $set properties
     */
    public static function posthogUserProperties(string $eventName, array $params): array
    {
        $props = [];

        // Always include email if present
        if (isset($params['email']) && is_string($params['email'])) {
            $props['email'] = $params['email'];
        }

        // Always include name if present
        if (isset($params['name']) && is_string($params['name'])) {
            $props['name'] = $params['name'];
        }

        return match ($eventName) {
            'sign_up' => array_merge($props, [
                'plan' => $params['plan'] ?? 'free',
                'signup_method' => $params['method'] ?? null,
                'signup_date' => $params['signup_date'] ?? date('Y-m-d'),
                'predicted_ltv' => $params['predicted_ltv'] ?? null,
            ]),
            'login' => array_merge($props, [
                'last_login' => date('Y-m-d H:i:s'),
                'login_method' => $params['method'] ?? null,
            ]),
            'start_trial' => array_merge($props, [
                'trial_start_date' => $params['trial_start_date'] ?? date('Y-m-d'),
                'trial_plan' => $params['plan'] ?? null,
                'trial_days' => $params['trial_days'] ?? null,
            ]),
            'subscribe', 'subscription' => array_merge($props, [
                'plan' => $params['plan'] ?? null,
                'subscription_status' => 'active',
                'billing_cycle' => $params['billing_cycle'] ?? null,
                'mrr' => $params['value'] ?? $params['revenue'] ?? 0,
            ]),
            'plan_upgrade' => array_merge($props, [
                'plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
                'previous_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            ]),
            'cancellation' => array_merge($props, [
                'subscription_status' => 'cancelled',
                'cancellation_reason' => $params['reason'] ?? null,
                'cancelled_plan' => $params['plan'] ?? null,
            ]),
            default => $props,
        };
    }

    // ── GA4 User Properties Builder ───────────────────────────────────

    /**
     * Build GA4 user_properties from a SaaS event.
     *
     * Returns properties suitable for GA4's user_properties parameter
     * (sent alongside events for user-level dimension enrichment).
     *
     * @param  string  $eventName  Internal event name
     * @param  array<string, mixed>  $params  Internal event params
     * @return array<string, mixed>  GA4 user_properties
     */
    public static function ga4UserProperties(string $eventName, array $params): array
    {
        $base = [];

        if (isset($params['user_id'])) {
            $base['user_id'] = (string) $params['user_id'];
        }

        return match ($eventName) {
            'sign_up' => array_merge($base, [
                'signup_method' => $params['method'] ?? null,
                'user_type' => 'registered',
            ]),
            'start_trial' => array_merge($base, [
                'trial_status' => 'active',
                'trial_plan' => $params['plan'] ?? null,
            ]),
            'subscribe', 'subscription' => array_merge($base, [
                'subscription_status' => 'active',
                'plan' => $params['plan'] ?? null,
                'billing_cycle' => $params['billing_cycle'] ?? null,
            ]),
            'plan_upgrade' => array_merge($base, [
                'plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
            ]),
            'cancellation' => array_merge($base, [
                'subscription_status' => 'cancelled',
                'cancellation_reason' => $params['reason'] ?? null,
            ]),
            default => $base,
        };
    }

    // ── Revenue Helpers ───────────────────────────────────────────────

    /**
     * Build revenue event params optimized for a specific provider.
     *
     * Centralizes revenue event formatting across providers.
     *
     * @param  string  $provider  Target provider ('ga4', 'meta', 'posthog')
     * @param  float  $value  Revenue amount
     * @param  string  $currency  ISO 4217 currency code
     * @param  string|null  $plan  Plan name
     * @param  string|null  $billingCycle  Billing cycle (monthly, annual)
     * @param  string|null  $subscriptionId  Subscription ID
     * @return array<string, mixed>
     */
    public static function buildRevenueParams(
        string $provider,
        float $value,
        string $currency,
        ?string $plan = null,
        ?string $billingCycle = null,
        ?string $subscriptionId = null,
    ): array {
        return match ($provider) {
            'ga4' => array_filter([
                'value' => $value,
                'currency' => $currency,
                'transaction_id' => $subscriptionId,
                'plan' => $plan,
                'billing_cycle' => $billingCycle,
                'items' => $plan !== null ? [
                    [
                        'item_id' => $plan,
                        'item_name' => $plan,
                        'item_category' => 'subscription',
                        'price' => $value,
                        'quantity' => 1,
                    ],
                ] : [],
            ]),
            'meta' => array_filter([
                'value' => $value,
                'currency' => $currency,
                'content_name' => 'revenue',
                'plan' => $plan,
            ]),
            'posthog' => array_filter([
                'revenue' => $value,
                '$currency' => $currency,
                'plan' => $plan,
                'billing_cycle' => $billingCycle,
                'subscription_id' => $subscriptionId,
            ]),
            default => [
                'value' => $value,
                'currency' => $currency,
                'plan' => $plan,
            ],
        };
    }
}
