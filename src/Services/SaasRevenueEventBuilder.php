<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Convenience builder for SaaS revenue and subscription events.
 *
 * Provides factory methods for constructing provider-optimized SaaS events
 * (subscription, trial, plan change, cancellation) with correct parameter
 * formats for GA4, Meta Pixel (CAPI), and PostHog.
 *
 * Unlike EcommerceFormatConverter which handles item-based e-commerce events,
 * this service focuses on SaaS-specific subscription and revenue events that
 * use value/plan/currency parameters instead of items arrays.
 *
 * @since 6.4.0
 */
final class SaasRevenueEventBuilder
{
    // ── Subscription Events ──────────────────────────────────────────

    /**
     * Build a subscription/purchase event (SaaS subscribe).
     *
     * Maps to: GA4 `purchase`, Meta `Subscribe`, PostHog `subscription_created`.
     *
     * @param  string  $planName  Plan/tier name (e.g. 'Pro', 'Enterprise')
     * @param  float  $value  Subscription value (MRR or one-time)
     * @param  string  $currency  ISO 4217 currency code
     * @param  string|null  $billingCycle  'monthly'|'annual'|'lifetime'
     * @param  array<string, mixed>  $extra  Additional provider params
     * @return array{ga4: array<string, mixed>, meta: array<string, mixed>, posthog: array<string, mixed>}
     */
    public static function subscription(
        string $planName,
        float $value,
        string $currency = 'USD',
        ?string $billingCycle = null,
        array $extra = [],
    ): array {
        $ga4 = array_merge([
            'transaction_id' => (string) ($extra['transaction_id'] ?? ''),
            'value' => $value,
            'currency' => $currency,
            'items' => [[
                'item_id' => 'subscription',
                'item_name' => $planName,
                'item_category' => 'subscription',
                'price' => $value,
                'quantity' => 1,
            ]],
        ], $extra);

        $meta = array_merge([
            'value' => $value,
            'currency' => $currency,
            'content_name' => $planName,
            'content_category' => 'subscription',
        ], $extra);

        $posthog = array_merge([
            'plan' => $planName,
            'value' => $value,
            '$currency' => $currency,
        ], $billingCycle !== null ? ['billing_cycle' => $billingCycle] : [], $extra);

        return ['ga4' => $ga4, 'meta' => $meta, 'posthog' => $posthog];
    }

    /**
     * Build a plan upgrade event.
     *
     * Maps to: GA4 `plan_upgrade`, Meta `PlanUpgrade`, PostHog `plan_upgraded`.
     *
     * @param  string  $fromPlan  Previous plan name
     * @param  string  $toPlan  New plan name
     * @param  float  $newValue  New plan value
     * @param  string  $currency  ISO 4217 currency code
     * @param  array<string, mixed>  $extra  Additional params
     * @return array{ga4: array<string, mixed>, meta: array<string, mixed>, posthog: array<string, mixed>}
     */
    public static function planUpgrade(
        string $fromPlan,
        string $toPlan,
        float $newValue,
        string $currency = 'USD',
        array $extra = [],
    ): array {
        $base = [
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
            'value' => $newValue,
            'currency' => $currency,
        ];

        return [
            'ga4' => array_merge($base, $extra),
            'meta' => array_merge($base, ['content_name' => $toPlan], $extra),
            'posthog' => array_merge($base, $extra),
        ];
    }

    /**
     * Build a plan downgrade event.
     *
     * Maps to: GA4 `plan_downgrade`, Meta `PlanDowngrade`, PostHog `plan_downgraded`.
     *
     * @param  string  $fromPlan  Previous plan name
     * @param  string  $toPlan  New plan name
     * @param  float  $newValue  New plan value
     * @param  string  $currency  ISO 4217 currency code
     * @param  array<string, mixed>  $extra  Additional params
     * @return array{ga4: array<string, mixed>, meta: array<string, mixed>, posthog: array<string, mixed>}
     */
    public static function planDowngrade(
        string $fromPlan,
        string $toPlan,
        float $newValue,
        string $currency = 'USD',
        array $extra = [],
    ): array {
        $base = [
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
            'value' => $newValue,
            'currency' => $currency,
        ];

        return [
            'ga4' => array_merge($base, $extra),
            'meta' => array_merge($base, ['content_name' => $toPlan], $extra),
            'posthog' => array_merge($base, $extra),
        ];
    }

    /**
     * Build a cancellation event.
     *
     * Maps to: GA4 `cancellation`, Meta `CancelSubscription`, PostHog `cancellation`.
     *
     * @param  string  $planName  Cancelled plan name
     * @param  string|null  $reason  Cancellation reason
     * @param  array<string, mixed>  $extra  Additional params
     * @return array{ga4: array<string, mixed>, meta: array<string, mixed>, posthog: array<string, mixed>}
     */
    public static function cancellation(
        string $planName,
        ?string $reason = null,
        array $extra = [],
    ): array {
        $base = array_filter([
            'plan' => $planName,
            'reason' => $reason,
        ]);

        return [
            'ga4' => array_merge($base, $extra),
            'meta' => array_merge($base, ['content_name' => $planName], $extra),
            'posthog' => array_merge($base, $extra),
        ];
    }

    // ── Trial Events ──────────────────────────────────────────────────

    /**
     * Build a trial start event.
     *
     * Maps to: GA4 `start_trial`, Meta `StartTrial`, PostHog `start_trial`.
     *
     * @param  string  $planName  Trial plan name
     * @param  int|null  $trialDays  Trial duration in days
     * @param  array<string, mixed>  $extra  Additional params
     * @return array{ga4: array<string, mixed>, meta: array<string, mixed>, posthog: array<string, mixed>}
     */
    public static function trialStart(
        string $planName,
        ?int $trialDays = null,
        array $extra = [],
    ): array {
        $base = array_filter([
            'plan' => $planName,
            'trial_days' => $trialDays,
        ]);

        return [
            'ga4' => array_merge($base, $extra),
            'meta' => array_merge($base, ['content_name' => $planName], $extra),
            'posthog' => array_merge($base, $extra),
        ];
    }

    /**
     * Build a trial conversion event.
     *
     * Maps to: GA4 `trial_converted`, Meta `Subscribe`, PostHog `trial_converted`.
     *
     * @param  string  $planName  Converted-to plan name
     * @param  float  $value  Subscription value
     * @param  string  $currency  ISO 4217 currency code
     * @param  array<string, mixed>  $extra  Additional params
     * @return array{ga4: array<string, mixed>, meta: array<string, mixed>, posthog: array<string, mixed>}
     */
    public static function trialConversion(
        string $planName,
        float $value,
        string $currency = 'USD',
        array $extra = [],
    ): array {
        $base = [
            'plan' => $planName,
            'value' => $value,
            'currency' => $currency,
        ];

        return [
            'ga4' => array_merge($base, $extra),
            'meta' => array_merge($base, ['content_name' => $planName, 'content_category' => 'subscription'], $extra),
            'posthog' => array_merge($base, $extra),
        ];
    }

    // ── Billing Events ────────────────────────────────────────────────

    /**
     * Build a payment succeeded event.
     *
     * Maps to: GA4 `payment_succeeded`, Meta `Purchase`, PostHog `payment_succeeded`.
     *
     * @param  float  $amount  Payment amount
     * @param  string  $currency  ISO 4217 currency code
     * @param  string|null  $invoiceId  Invoice ID
     * @param  array<string, mixed>  $extra  Additional params
     * @return array{ga4: array<string, mixed>, meta: array<string, mixed>, posthog: array<string, mixed>}
     */
    public static function paymentSucceeded(
        float $amount,
        string $currency = 'USD',
        ?string $invoiceId = null,
        array $extra = [],
    ): array {
        $base = array_filter([
            'value' => $amount,
            'currency' => $currency,
            'transaction_id' => $invoiceId,
        ]);

        return [
            'ga4' => array_merge($base, $extra),
            'meta' => array_merge($base, $extra),
            'posthog' => array_merge($base, $extra),
        ];
    }

    /**
     * Build a payment failed event.
     *
     * Maps to: GA4 `payment_failed`, Meta `PaymentFailed`, PostHog `payment_failed`.
     *
     * @param  float  $amount  Attempted payment amount
     * @param  string  $currency  ISO 4217 currency code
     * @param  string|null  $reason  Failure reason
     * @param  array<string, mixed>  $extra  Additional params
     * @return array{ga4: array<string, mixed>, meta: array<string, mixed>, posthog: array<string, mixed>}
     */
    public static function paymentFailed(
        float $amount,
        string $currency = 'USD',
        ?string $reason = null,
        array $extra = [],
    ): array {
        $base = array_filter([
            'value' => $amount,
            'currency' => $currency,
            'reason' => $reason,
        ]);

        return [
            'ga4' => array_merge($base, $extra),
            'meta' => array_merge($base, $extra),
            'posthog' => array_merge($base, $extra),
        ];
    }

    // ── Cross-Provider Event Factory ────────────────────────────────────

    /**
     * Build a complete SaaS event with provider-specific parameters.
     *
     * Returns an AnalyticsEvent with a merged params array suitable for
     * direct dispatch. The event name is the canonical ZeroBoiler name.
     *
     * @param  string  $eventName  Canonical event name (e.g. 'subscribe', 'plan_upgrade')
     * @param  string  $provider  Target provider ('ga4', 'meta', 'posthog', or 'all')
     * @param  array<string, mixed>  $params  Provider-specific parameters
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     * @return AnalyticsEvent
     */
    public static function buildEvent(
        string $eventName,
        string $provider = 'all',
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
    ): AnalyticsEvent {
        return new AnalyticsEvent(
            name: $eventName,
            params: array_merge($params, [
                '_target_provider' => $provider,
            ]),
            clientId: $clientId,
            userId: $userId,
        );
    }
}
