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
 * across all 8 supported providers: GA4, Meta Pixel, PostHog, Mixpanel,
 * Amplitude, Plausible, TikTok, and LinkedIn.
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
 * Mixpanel uses flat event properties with distinct_id for user identity.
 *
 * Amplitude uses event properties with user_properties for enrichment.
 *
 * Plausible uses a simple {event_name, props} structure.
 *
 * TikTok Events API uses a flat structure with content, value, currency.
 *
 * LinkedIn Conversions API uses conversion_id, value, currency with flat structure.
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

    // ── sign_up → Mixpanel / Amplitude / Plausible / TikTok / LinkedIn ──

    /**
     * Convert sign_up event params to Mixpanel event properties.
     *
     * Mixpanel uses flat properties with distinct_id. SaaS signup events
     * map to a 'Signup' event type with signup method and plan properties.
     *
     * @param  array<string, mixed>  $params  Internal sign_up params
     * @return array{signup_method: string|null, signup_source: string|null, is_paid: bool, plan: string|null, referral_code: string|null, predicted_ltv: float|null}
     */
    public static function signUpToMixpanel(array $params): array
    {
        return [
            'signup_method' => $params['method'] ?? null,
            'signup_source' => $params['source'] ?? null,
            'is_paid' => (bool) ($params['is_paid'] ?? false),
            'plan' => $params['plan'] ?? null,
            'referral_code' => $params['referral_code'] ?? null,
            'predicted_ltv' => isset($params['predicted_ltv']) ? (float) $params['predicted_ltv'] : null,
        ];
    }

    /**
     * Convert sign_up event params to Amplitude event properties.
     *
     * Amplitude uses event properties and user_properties for enrichment.
     * Signup maps to 'Sign Up' event with plan and method properties.
     *
     * @param  array<string, mixed>  $params  Internal sign_up params
     * @return array{signup_method: string|null, signup_source: string|null, is_paid: bool, plan: string|null, referral_code: string|null, user_properties: array{predicted_ltv: mixed}}
     */
    public static function signUpToAmplitude(array $params): array
    {
        $props = [
            'signup_method' => $params['method'] ?? null,
            'signup_source' => $params['source'] ?? null,
            'is_paid' => (bool) ($params['is_paid'] ?? false),
            'plan' => $params['plan'] ?? null,
            'referral_code' => $params['referral_code'] ?? null,
        ];

        if (isset($params['predicted_ltv'])) {
            $props['user_properties'] = ['predicted_ltv' => (float) $params['predicted_ltv']];
        }

        return $props;
    }

    /**
     * Convert sign_up event params to Plausible event properties.
     *
     * Plausible uses a simple {props} structure with string/number values.
     * Revenue events require $plausible_revenue custom property.
     *
     * @param  array<string, mixed>  $params  Internal sign_up params
     * @return array{signup_method: string|null, plan: string|null, is_paid: string|null}
     */
    public static function signUpToPlausible(array $params): array
    {
        return [
            'signup_method' => $params['method'] ?? null,
            'plan' => $params['plan'] ?? null,
            'is_paid' => $params['is_paid'] ? 'true' : 'false',
        ];
    }

    /**
     * Convert sign_up event params to TikTok Events API format.
     *
     * TikTok uses CompleteRegistration for signup events with flat properties.
     * Include value for paid signups and content_name for segmentation.
     *
     * @param  array<string, mixed>  $params  Internal sign_up params
     * @return array{content_name: string|null, value: float, currency: string, status: string, method: string|null}
     */
    public static function signUpToTiktok(array $params): array
    {
        return [
            'content_name' => (string) ($params['content_name'] ?? 'sign_up'),
            'value' => (float) ($params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'status' => (string) ($params['status'] ?? 'completed'),
            'method' => $params['method'] ?? null,
        ];
    }

    /**
     * Convert sign_up event params to LinkedIn Conversions API format.
     *
     * LinkedIn uses a flat structure with value, currency for conversion events.
     * Maps to a custom conversion event for signup.
     *
     * @param  array<string, mixed>  $params  Internal sign_up params
     * @return array{value: float, currency: string, method: string|null, plan: string|null}
     */
    public static function signUpToLinkedin(array $params): array
    {
        return [
            'value' => (float) ($params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'method' => $params['method'] ?? null,
            'plan' => $params['plan'] ?? null,
        ];
    }

    // ── login → Meta / PostHog / GA4 / Mixpanel / Amplitude / Plausible / TikTok / LinkedIn ─────────────────────────────────

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

    /**
     * Convert login event params to Mixpanel event properties.
     *
     * @param  array<string, mixed>  $params  Internal login params
     * @return array{login_method: string|null, login_source: string|null, is_first_login: bool}
     */
    public static function loginToMixpanel(array $params): array
    {
        return [
            'login_method' => $params['method'] ?? null,
            'login_source' => $params['source'] ?? null,
            'is_first_login' => (bool) ($params['is_first_login'] ?? false),
        ];
    }

    /**
     * Convert login event params to Amplitude event properties.
     *
     * @param  array<string, mixed>  $params  Internal login params
     * @return array{login_method: string|null, login_source: string|null, is_first_login: bool}
     */
    public static function loginToAmplitude(array $params): array
    {
        return [
            'login_method' => $params['method'] ?? null,
            'login_source' => $params['source'] ?? null,
            'is_first_login' => (bool) ($params['is_first_login'] ?? false),
        ];
    }

    /**
     * Convert login event params to Plausible event properties.
     *
     * @param  array<string, mixed>  $params  Internal login params
     * @return array{method: string|null}
     */
    public static function loginToPlausible(array $params): array
    {
        return [
            'method' => $params['method'] ?? null,
        ];
    }

    /**
     * Convert login event params to TikTok Events API format.
     *
     * @param  array<string, mixed>  $params  Internal login params
     * @return array{content_name: string, method: string|null}
     */
    public static function loginToTiktok(array $params): array
    {
        return [
            'content_name' => 'login',
            'method' => $params['method'] ?? null,
        ];
    }

    /**
     * Convert login event params to LinkedIn Conversions API format.
     *
     * @param  array<string, mixed>  $params  Internal login params
     * @return array{method: string|null}
     */
    public static function loginToLinkedin(array $params): array
    {
        return [
            'method' => $params['method'] ?? null,
        ];
    }

    // ── trial_start → Meta StartTrial / PostHog / GA4 / Mixpanel / Amplitude / Plausible / TikTok / LinkedIn ──────────────────────────

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

    /**
     * Convert trial_start event params to Mixpanel event properties.
     *
     * @param  array<string, mixed>  $params  Internal trial_start params
     * @return array{trial_days: int|null, plan: string|null, trial_value: float, trial_currency: string, predicted_ltv: float|null}
     */
    public static function trialStartToMixpanel(array $params): array
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
     * Convert trial_start event params to Amplitude event properties.
     *
     * @param  array<string, mixed>  $params  Internal trial_start params
     * @return array{trial_days: int|null, plan: string|null, value: float, currency: string}
     */
    public static function trialStartToAmplitude(array $params): array
    {
        return [
            'trial_days' => $params['trial_days'] ?? null,
            'plan' => $params['plan'] ?? null,
            'value' => (float) ($params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
        ];
    }

    /**
     * Convert trial_start event params to Plausible event properties.
     *
     * @param  array<string, mixed>  $params  Internal trial_start params
     * @return array{plan: string|null, trial_days: string|null}
     */
    public static function trialStartToPlausible(array $params): array
    {
        return [
            'plan' => $params['plan'] ?? null,
            'trial_days' => $params['trial_days'] !== null ? (string) $params['trial_days'] : null,
        ];
    }

    /**
     * Convert trial_start event params to TikTok Events API format.
     *
     * @param  array<string, mixed>  $params  Internal trial_start params
     * @return array{content_name: string, value: float, currency: string, trial_days: int|null, plan: string|null}
     */
    public static function trialStartToTiktok(array $params): array
    {
        return [
            'content_name' => 'start_trial',
            'value' => (float) ($params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'trial_days' => $params['trial_days'] ?? null,
            'plan' => $params['plan'] ?? null,
        ];
    }

    /**
     * Convert trial_start event params to LinkedIn Conversions API format.
     *
     * @param  array<string, mixed>  $params  Internal trial_start params
     * @return array{value: float, currency: string, plan: string|null}
     */
    public static function trialStartToLinkedin(array $params): array
    {
        return [
            'value' => (float) ($params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'plan' => $params['plan'] ?? null,
        ];
    }

    // ── subscription → Meta Subscribe / PostHog / GA4 / Mixpanel / Amplitude / Plausible / TikTok / LinkedIn ─────────────────────────

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

    /**
     * Convert subscription event params to Mixpanel event properties.
     *
     * Mixpanel tracks subscription as a revenue event with plan details.
     *
     * @param  array<string, mixed>  $params  Internal subscription params
     * @return array{plan: string|null, value: float, currency: string, billing_cycle: string|null, subscription_id: string|null, is_trial: bool}
     */
    public static function subscriptionToMixpanel(array $params): array
    {
        return [
            'plan' => $params['plan'] ?? null,
            'value' => (float) ($params['value'] ?? $params['revenue'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'billing_cycle' => $params['billing_cycle'] ?? null,
            'subscription_id' => $params['subscription_id'] ?? null,
            'is_trial' => (bool) ($params['is_trial'] ?? false),
        ];
    }

    /**
     * Convert subscription event params to Amplitude event properties.
     *
     * @param  array<string, mixed>  $params  Internal subscription params
     * @return array{plan: string|null, revenue: float, price: float, currency: string, billing_cycle: string|null, subscription_id: string|null, is_trial: bool, user_properties: array{plan: string|null, mrr: mixed}}
     */
    public static function subscriptionToAmplitude(array $params): array
    {
        $value = (float) ($params['value'] ?? $params['revenue'] ?? 0.0);

        return [
            'plan' => $params['plan'] ?? null,
            'revenue' => $value,
            'price' => $value,
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'billing_cycle' => $params['billing_cycle'] ?? null,
            'subscription_id' => $params['subscription_id'] ?? null,
            'is_trial' => (bool) ($params['is_trial'] ?? false),
            'user_properties' => [
                'plan' => $params['plan'] ?? null,
                'mrr' => $value,
            ],
        ];
    }

    /**
     * Convert subscription event params to Plausible event properties.
     *
     * @param  array<string, mixed>  $params  Internal subscription params
     * @return array{plan: string|null, billing_cycle: string|null, amount: string|null}
     */
    public static function subscriptionToPlausible(array $params): array
    {
        return [
            'plan' => $params['plan'] ?? null,
            'billing_cycle' => $params['billing_cycle'] ?? null,
            'amount' => isset($params['value']) ? (string) $params['value'] : null,
        ];
    }

    /**
     * Convert subscription event params to TikTok Events API format.
     *
     * TikTok uses SubscribePayment for subscription events.
     *
     * @param  array<string, mixed>  $params  Internal subscription params
     * @return array{content_name: string, value: float, currency: string, plan: string|null, billing_cycle: string|null}
     */
    public static function subscriptionToTiktok(array $params): array
    {
        return [
            'content_name' => 'subscribe',
            'value' => (float) ($params['value'] ?? $params['revenue'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'plan' => $params['plan'] ?? null,
            'billing_cycle' => $params['billing_cycle'] ?? null,
        ];
    }

    /**
     * Convert subscription event params to LinkedIn Conversions API format.
     *
     * @param  array<string, mixed>  $params  Internal subscription params
     * @return array{value: float, currency: string, plan: string|null}
     */
    public static function subscriptionToLinkedin(array $params): array
    {
        return [
            'value' => (float) ($params['value'] ?? $params['revenue'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'plan' => $params['plan'] ?? null,
        ];
    }

    // ── plan_upgrade → Meta / PostHog / GA4 / Mixpanel / Amplitude / Plausible / TikTok / LinkedIn ───────────────────────────

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

    /**
     * Convert plan_upgrade event params to Mixpanel event properties.
     *
     * @param  array<string, mixed>  $params  Internal plan_upgrade params
     * @return array{from_plan: string|null, to_plan: string|null, value_delta: float, currency: string, upgrade_type: string|null, billing_cycle: string|null}
     */
    public static function planUpgradeToMixpanel(array $params): array
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
     * Convert plan_upgrade event params to Amplitude event properties.
     *
     * @param  array<string, mixed>  $params  Internal plan_upgrade params
     * @return array{from_plan: string|null, to_plan: string|null, revenue: float, currency: string, upgrade_type: string|null, user_properties: array{plan: string|null, previous_plan: string|null}}
     */
    public static function planUpgradeToAmplitude(array $params): array
    {
        return [
            'from_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            'to_plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
            'revenue' => (float) ($params['value'] ?? $params['revenue_delta'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'upgrade_type' => $params['upgrade_type'] ?? null,
            'user_properties' => [
                'plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
                'previous_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            ],
        ];
    }

    /**
     * Convert plan_upgrade event params to Plausible event properties.
     *
     * @param  array<string, mixed>  $params  Internal plan_upgrade params
     * @return array{from_plan: string|null, to_plan: string|null}
     */
    public static function planUpgradeToPlausible(array $params): array
    {
        return [
            'from_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            'to_plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
        ];
    }

    /**
     * Convert plan_upgrade event params to TikTok Events API format.
     *
     * @param  array<string, mixed>  $params  Internal plan_upgrade params
     * @return array{content_name: string, value: float, currency: string, from_plan: string|null, to_plan: string|null}
     */
    public static function planUpgradeToTiktok(array $params): array
    {
        return [
            'content_name' => 'plan_upgrade',
            'value' => (float) ($params['value'] ?? $params['revenue_delta'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'from_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            'to_plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
        ];
    }

    /**
     * Convert plan_upgrade event params to LinkedIn Conversions API format.
     *
     * @param  array<string, mixed>  $params  Internal plan_upgrade params
     * @return array{value: float, currency: string, from_plan: string|null, to_plan: string|null}
     */
    public static function planUpgradeToLinkedin(array $params): array
    {
        return [
            'value' => (float) ($params['value'] ?? $params['revenue_delta'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'from_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            'to_plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
        ];
    }

    // ── plan_downgrade → Meta / PostHog / GA4 / Mixpanel / Amplitude / Plausible / TikTok / LinkedIn ─────────────────────

    /**
     * Convert plan_downgrade event params to Meta Pixel format.
     *
     * @param  array<string, mixed>  $params  Internal plan_downgrade params
     * @return array{content_name: string, value: float, currency: string, from_plan: string|null, to_plan: string|null}
     */
    public static function planDowngradeToMeta(array $params): array
    {
        return [
            'content_name' => (string) ($params['content_name'] ?? 'plan_downgrade'),
            'value' => (float) ($params['value'] ?? $params['revenue_delta'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'from_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            'to_plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
        ];
    }

    /**
     * Convert plan_downgrade event params to PostHog properties.
     *
     * @param  array<string, mixed>  $params  Internal plan_downgrade params
     * @return array{from_plan: string|null, to_plan: string|null, value: float, currency: string, revenue_delta: float}
     */
    public static function planDowngradeToPosthog(array $params): array
    {
        return [
            'from_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            'to_plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
            'value' => (float) ($params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'revenue_delta' => (float) ($params['revenue_delta'] ?? 0.0),
        ];
    }

    /**
     * Convert plan_downgrade event params to GA4 custom event format.
     *
     * @param  array<string, mixed>  $params  Internal plan_downgrade params
     * @return array{items: list<array<string, mixed>>, value: float, currency: string}
     */
    public static function planDowngradeToGa4(array $params): array
    {
        return [
            'items' => [[
                'item_id' => (string) ($params['to_plan'] ?? $params['new_plan'] ?? 'plan_downgrade'),
                'item_name' => (string) ($params['to_plan'] ?? $params['new_plan'] ?? ''),
                'item_category' => 'plan_downgrade',
                'item_variant' => (string) ($params['from_plan'] ?? $params['previous_plan'] ?? ''),
            ]],
            'value' => (float) ($params['value'] ?? $params['revenue_delta'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
        ];
    }

    /**
     * Convert plan_downgrade event params to Mixpanel event properties.
     *
     * @param  array<string, mixed>  $params  Internal plan_downgrade params
     * @return array{from_plan: string|null, to_plan: string|null, value: float, currency: string, revenue_delta: float}
     */
    public static function planDowngradeToMixpanel(array $params): array
    {
        return [
            'from_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            'to_plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
            'value' => (float) ($params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'revenue_delta' => (float) ($params['revenue_delta'] ?? 0.0),
        ];
    }

    /**
     * Convert plan_downgrade event params to Amplitude event properties.
     *
     * @param  array<string, mixed>  $params  Internal plan_downgrade params
     * @return array{from_plan: string|null, to_plan: string|null, value: float, currency: string, user_properties: array{plan: mixed, mrr: mixed}}
     */
    public static function planDowngradeToAmplitude(array $params): array
    {
        $toPlan = $params['to_plan'] ?? $params['new_plan'] ?? null;

        return [
            'from_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            'to_plan' => $toPlan,
            'value' => (float) ($params['value'] ?? $params['revenue_delta'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'user_properties' => $toPlan !== null
                ? ['plan' => $toPlan, 'mrr' => (float) ($params['value'] ?? 0.0)]
                : [],
        ];
    }

    /**
     * Convert plan_downgrade event params to Plausible event properties.
     *
     * @param  array<string, mixed>  $params  Internal plan_downgrade params
     * @return array{from_plan: string|null, to_plan: string|null}
     */
    public static function planDowngradeToPlausible(array $params): array
    {
        return [
            'from_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            'to_plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
        ];
    }

    /**
     * Convert plan_downgrade event params to TikTok Events API format.
     *
     * @param  array<string, mixed>  $params  Internal plan_downgrade params
     * @return array{content_name: string, value: float, currency: string, from_plan: string|null, to_plan: string|null}
     */
    public static function planDowngradeToTiktok(array $params): array
    {
        return [
            'content_name' => 'plan_downgrade',
            'value' => (float) ($params['value'] ?? $params['revenue_delta'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'from_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            'to_plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
        ];
    }

    /**
     * Convert plan_downgrade event params to LinkedIn Conversions API format.
     *
     * @param  array<string, mixed>  $params  Internal plan_downgrade params
     * @return array{value: float, currency: string, from_plan: string|null, to_plan: string|null}
     */
    public static function planDowngradeToLinkedin(array $params): array
    {
        return [
            'value' => (float) ($params['value'] ?? $params['revenue_delta'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'from_plan' => $params['from_plan'] ?? $params['previous_plan'] ?? null,
            'to_plan' => $params['to_plan'] ?? $params['new_plan'] ?? null,
        ];
    }

    // ── cancellation → Meta CancelSubscription / PostHog / GA4 / Mixpanel / Amplitude / Plausible / TikTok / LinkedIn ───────────────

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

    /**
     * Convert cancellation event params to Mixpanel event properties.
     *
     * @param  array<string, mixed>  $params  Internal cancellation params
     * @return array{plan: string|null, reason: string|null, cancellation_type: string|null, lost_mrr: float, currency: string, tenure_days: int|null, nps_before: int|null}
     */
    public static function cancellationToMixpanel(array $params): array
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
     * Convert cancellation event params to Amplitude event properties.
     *
     * @param  array<string, mixed>  $params  Internal cancellation params
     * @return array{plan: string|null, reason: string|null, cancellation_type: string|null, revenue_lost: float, currency: string, user_properties: array{subscription_status: string, plan: string|null}}
     */
    public static function cancellationToAmplitude(array $params): array
    {
        return [
            'plan' => $params['plan'] ?? null,
            'reason' => $params['reason'] ?? null,
            'cancellation_type' => $params['cancellation_type'] ?? null,
            'revenue_lost' => (float) ($params['lost_revenue'] ?? $params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'user_properties' => [
                'subscription_status' => 'cancelled',
                'plan' => $params['plan'] ?? null,
            ],
        ];
    }

    /**
     * Convert cancellation event params to Plausible event properties.
     *
     * @param  array<string, mixed>  $params  Internal cancellation params
     * @return array{plan: string|null, reason: string|null}
     */
    public static function cancellationToPlausible(array $params): array
    {
        return [
            'plan' => $params['plan'] ?? null,
            'reason' => $params['reason'] ?? null,
        ];
    }

    /**
     * Convert cancellation event params to TikTok Events API format.
     *
     * @param  array<string, mixed>  $params  Internal cancellation params
     * @return array{content_name: string, value: float, currency: string, plan: string|null, reason: string|null}
     */
    public static function cancellationToTiktok(array $params): array
    {
        return [
            'content_name' => 'cancel_subscription',
            'value' => (float) ($params['lost_revenue'] ?? $params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'plan' => $params['plan'] ?? null,
            'reason' => $params['reason'] ?? null,
        ];
    }

    /**
     * Convert cancellation event params to LinkedIn Conversions API format.
     *
     * @param  array<string, mixed>  $params  Internal cancellation params
     * @return array{value: float, currency: string, plan: string|null, reason: string|null}
     */
    public static function cancellationToLinkedin(array $params): array
    {
        return [
            'value' => (float) ($params['lost_revenue'] ?? $params['value'] ?? 0.0),
            'currency' => (string) ($params['currency'] ?? 'USD'),
            'plan' => $params['plan'] ?? null,
            'reason' => $params['reason'] ?? null,
        ];
    }

    // ── Generic SaaS Event Converter (8 providers) ──────────────────────

    /**
     * Convert any SaaS event to a specific provider's format.
     *
     * Central dispatch method that routes to the appropriate converter
     * based on event name and target provider. Supports all 8 providers:
     * GA4, Meta Pixel, PostHog, Mixpanel, Amplitude, Plausible, TikTok, LinkedIn.
     *
     * @param  string  $eventName  Internal event name (e.g. 'sign_up', 'login', 'trial_start')
     * @param  array<string, mixed>  $params  Internal event params
     * @param  'ga4'|'meta'|'posthog'|'mixpanel'|'amplitude'|'plausible'|'tiktok'|'linkedin'  $provider  Target provider
     * @return array<string, mixed>  Provider-formatted params
     */
    public static function convertForProvider(string $eventName, array $params, string $provider): array
    {
        return match ($eventName) {
            'sign_up' => match ($provider) {
                'meta' => self::signUpToMeta($params),
                'posthog' => self::signUpToPosthog($params),
                'ga4' => self::signUpToGa4($params),
                'mixpanel' => self::signUpToMixpanel($params),
                'amplitude' => self::signUpToAmplitude($params),
                'plausible' => self::signUpToPlausible($params),
                'tiktok' => self::signUpToTiktok($params),
                'linkedin' => self::signUpToLinkedin($params),
                default => $params,
            },
            'login' => match ($provider) {
                'meta' => self::loginToMeta($params),
                'posthog' => self::loginToPosthog($params),
                'ga4' => $params,
                'mixpanel' => self::loginToMixpanel($params),
                'amplitude' => self::loginToAmplitude($params),
                'plausible' => self::loginToPlausible($params),
                'tiktok' => self::loginToTiktok($params),
                'linkedin' => self::loginToLinkedin($params),
                default => $params,
            },
            'start_trial' => match ($provider) {
                'meta' => self::trialStartToMeta($params),
                'posthog' => self::trialStartToPosthog($params),
                'ga4' => self::trialStartToGa4($params),
                'mixpanel' => self::trialStartToMixpanel($params),
                'amplitude' => self::trialStartToAmplitude($params),
                'plausible' => self::trialStartToPlausible($params),
                'tiktok' => self::trialStartToTiktok($params),
                'linkedin' => self::trialStartToLinkedin($params),
                default => $params,
            },
            'subscribe' => match ($provider) {
                'meta' => self::subscriptionToMeta($params),
                'posthog' => self::subscriptionToPosthog($params),
                'ga4' => self::subscriptionToGa4($params),
                'mixpanel' => self::subscriptionToMixpanel($params),
                'amplitude' => self::subscriptionToAmplitude($params),
                'plausible' => self::subscriptionToPlausible($params),
                'tiktok' => self::subscriptionToTiktok($params),
                'linkedin' => self::subscriptionToLinkedin($params),
                default => $params,
            },
            'subscription' => match ($provider) {
                'meta' => self::subscriptionToMeta($params),
                'posthog' => self::subscriptionToPosthog($params),
                'ga4' => self::subscriptionToGa4($params),
                'mixpanel' => self::subscriptionToMixpanel($params),
                'amplitude' => self::subscriptionToAmplitude($params),
                'plausible' => self::subscriptionToPlausible($params),
                'tiktok' => self::subscriptionToTiktok($params),
                'linkedin' => self::subscriptionToLinkedin($params),
                default => $params,
            },
            'plan_upgrade' => match ($provider) {
                'meta' => self::planUpgradeToMeta($params),
                'posthog' => self::planUpgradeToPosthog($params),
                'ga4' => self::planUpgradeToGa4($params),
                'mixpanel' => self::planUpgradeToMixpanel($params),
                'amplitude' => self::planUpgradeToAmplitude($params),
                'plausible' => self::planUpgradeToPlausible($params),
                'tiktok' => self::planUpgradeToTiktok($params),
                'linkedin' => self::planUpgradeToLinkedin($params),
                default => $params,
            },
            'plan_downgrade' => match ($provider) {
                'meta' => self::planDowngradeToMeta($params),
                'posthog' => self::planDowngradeToPosthog($params),
                'ga4' => self::planDowngradeToGa4($params),
                'mixpanel' => self::planDowngradeToMixpanel($params),
                'amplitude' => self::planDowngradeToAmplitude($params),
                'plausible' => self::planDowngradeToPlausible($params),
                'tiktok' => self::planDowngradeToTiktok($params),
                'linkedin' => self::planDowngradeToLinkedin($params),
                default => $params,
            },
            'cancellation' => match ($provider) {
                'meta' => self::cancellationToMeta($params),
                'posthog' => self::cancellationToPosthog($params),
                'ga4' => self::cancellationToGa4($params),
                'mixpanel' => self::cancellationToMixpanel($params),
                'amplitude' => self::cancellationToAmplitude($params),
                'plausible' => self::cancellationToPlausible($params),
                'tiktok' => self::cancellationToTiktok($params),
                'linkedin' => self::cancellationToLinkedin($params),
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
     * @param  'ga4'|'meta'|'posthog'|'mixpanel'|'amplitude'|'plausible'|'tiktok'|'linkedin'  $provider  Target provider
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
     * Centralizes revenue event formatting across all 8 providers.
     *
     * @param  string  $provider  Target provider ('ga4', 'meta', 'posthog', 'mixpanel', 'amplitude', 'plausible', 'tiktok', 'linkedin')
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
            'mixpanel' => array_filter([
                'revenue' => $value,
                'currency' => $currency,
                'plan' => $plan,
                'billing_cycle' => $billingCycle,
                'subscription_id' => $subscriptionId,
            ]),
            'amplitude' => array_filter([
                'revenue' => $value,
                'price' => $value,
                'currency' => $currency,
                'plan' => $plan,
                'user_properties' => $plan !== null ? ['plan' => $plan, 'mrr' => $value] : [],
            ], fn (mixed $v): bool => $v !== null && $v !== [] && $v !== 0),
            'plausible' => array_filter([
                'revenue' => (string) $value,
                'currency' => $currency,
                'plan' => $plan,
            ]),
            'tiktok' => array_filter([
                'value' => $value,
                'currency' => $currency,
                'content_name' => 'revenue',
                'plan' => $plan,
            ]),
            'linkedin' => array_filter([
                'value' => $value,
                'currency' => $currency,
                'plan' => $plan,
            ]),
            default => [
                'value' => $value,
                'currency' => $currency,
                'plan' => $plan,
            ],
        };
    }

    /**
     * Get all supported provider names for SaaS format conversion.
     *
     * @return list<string>
     */
    public static function supportedProviders(): array
    {
        return ['ga4', 'meta', 'posthog', 'mixpanel', 'amplitude', 'plausible', 'tiktok', 'linkedin'];
    }

    /**
     * Check if a given event name is supported by this converter.
     */
    public static function supports(string $eventName): bool
    {
        return in_array($eventName, ['sign_up', 'login', 'start_trial', 'subscribe', 'subscription', 'plan_upgrade', 'plan_downgrade', 'cancellation'], true);
    }
}
