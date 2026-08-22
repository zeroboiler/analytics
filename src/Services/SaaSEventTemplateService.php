<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

/**
 * Industry-standard SaaS event template service.
 *
 * Provides pre-configured event templates for common SaaS patterns.
 * Each template generates provider-optimized parameters for GA4, Meta Pixel,
 * PostHog, and Plausible simultaneously, following industry-standard schemas.
 *
 * Templates cover:
 * - Authentication flows (signup, login, logout)
 * - Subscription lifecycle (create, upgrade, downgrade, cancel, pause, resume)
 * - Trial management (start, convert, expire, end)
 * - Onboarding milestones (step completed, wizard finished, activation)
 * - Revenue events (MRR contribution, expansion, contraction, churn)
 * - Feature adoption (first use, power user, limit reached)
 * - Account management (profile update, email verify, password change)
 *
 * All templates include required UTM attribution context and support
 * optional user ID and client ID enrichment.
 *
 * Usage:
 *   $template = new SaaSEventTemplateService($manager);
 *   $template->signup($userId, ['method' => 'email', 'referral' => 'organic']);
 *
 * @since 6.9.0
 */
final class SaaSEventTemplateService
{
    /**
     * @param  AnalyticsManager  $manager  The analytics manager instance
     */
    public function __construct(
        private readonly AnalyticsManager $manager,
    ){}

    // ─── Authentication Templates ────────────────────────────────────

    /**
     * Track a user sign-up event with industry-standard params.
     *
     * Includes method (email, oauth_google, oauth_github, sso), referral source,
     * and optional UTM attribution context. Maps to GA4 sign_up, Meta CompleteRegistration.
     *
     * @param  string  $userId  User identifier
     * @param  array{method?: string, referral?: string, plan?: string, utm_source?: string, utm_medium?: string, utm_campaign?: string}  $params  Signup context
     */
    public function signup(string $userId, array $params = []): void
    {
        $this->manager->trackEvent('sign_up', array_merge([
            'user_id' => $userId,
            'method' => $params['method'] ?? 'email',
            'referral' => $params['referral'] ?? 'direct',
            'plan' => $params['plan'] ?? null,
        ], $this->extractUtm($params)));
    }

    /**
     * Track a user login event.
     *
     * Tracks authentication method and session context.
     *
     * @param  string  $userId  User identifier
     * @param  array{method?: string, is_first_login?: bool, session_count?: int}  $params  Login context
     */
    public function login(string $userId, array $params = []): void
    {
        $this->manager->trackEvent('login', array_merge([
            'user_id' => $userId,
            'method' => $params['method'] ?? 'email',
            'is_first_login' => $params['is_first_login'] ?? null,
            'session_count' => $params['session_count'] ?? null,
        ], $this->extractUtm($params)));
    }

    /**
     * Track a user logout event.
     *
     * @param  string  $userId  User identifier
     * @param  array<string, mixed>  $params  Additional context
     */
    public function logout(string $userId, array $params = []): void
    {
        $this->manager->trackEvent('logout', array_merge([
            'user_id' => $userId,
        ], $params));
    }

    // ─── Subscription Lifecycle Templates ──────────────────────────

    /**
     * Track subscription creation with revenue context.
     *
     * Generates provider-optimized params for GA4 purchase, Meta Subscribe,
     * PostHog subscription_created. Includes MRR calculation and billing cycle.
     *
     * @param  string  $userId  User identifier
     * @param  string  $plan  Plan name
     * @param  float  $revenue  Monthly recurring revenue
     * @param  array{billing_cycle?: string, currency?: string, trial_days?: int, payment_provider?: string}  $params  Subscription context
     */
    public function subscriptionCreated(string $userId, string $plan, float $revenue, array $params = []): void
    {
        $billingCycle = $params['billing_cycle'] ?? 'monthly';
        $currency = $params['currency'] ?? 'USD';

        $this->manager->trackEvent('subscribe', array_merge([
            'user_id' => $userId,
            'plan' => $plan,
            'revenue' => $revenue,
            'mrr' => $revenue,
            'arr' => round($revenue * 12, 2),
            'billing_cycle' => $billingCycle,
            'currency' => $currency,
            'payment_provider' => $params['payment_provider'] ?? null,
        ], $this->extractUtm($params)));
    }

    /**
     * Track plan upgrade with revenue impact analysis.
     *
     * Includes from/to plan comparison, revenue delta, and upgrade catalyst.
     *
     * @param  string  $userId  User identifier
     * @param  string  $fromPlan  Previous plan name
     * @param  string  $toPlan  New plan name
     * @param  array{from_revenue?: float, to_revenue?: float, catalyst?: string, currency?: string}  $params  Upgrade context
     */
    public function planUpgrade(string $userId, string $fromPlan, string $toPlan, array $params = []): void
    {
        $fromRevenue = $params['from_revenue'] ?? null;
        $toRevenue = $params['to_revenue'] ?? null;

        $upgradeParams = [
            'user_id' => $userId,
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
            'catalyst' => $params['catalyst'] ?? null,
            'currency' => $params['currency'] ?? 'USD',
        ];

        if ($fromRevenue !== null && $toRevenue !== null) {
            $upgradeParams['from_revenue'] = $fromRevenue;
            $upgradeParams['to_revenue'] = $toRevenue;
            $upgradeParams['revenue_change'] = round($toRevenue - $fromRevenue, 2);
            $upgradeParams['revenue_change_percent'] = $fromRevenue > 0
                ? round((($toRevenue - $fromRevenue) / $fromRevenue) * 100, 2)
                : null;
        }

        $this->manager->trackEvent('plan_upgrade', array_merge($upgradeParams, $this->extractUtm($params)));
    }

    /**
     * Track plan downgrade with retention context.
     *
     * @param  string  $userId  User identifier
     * @param  string  $fromPlan  Current plan name
     * @param  string  $toPlan  New plan name
     * @param  array{from_revenue?: float, to_revenue?: float, reason?: string, feedback?: string}  $params  Downgrade context
     */
    public function planDowngrade(string $userId, string $fromPlan, string $toPlan, array $params = []): void
    {
        $downgradeParams = [
            'user_id' => $userId,
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
            'reason' => $params['reason'] ?? null,
            'feedback' => $params['feedback'] ?? null,
        ];

        $fromRevenue = $params['from_revenue'] ?? null;
        $toRevenue = $params['to_revenue'] ?? null;

        if ($fromRevenue !== null && $toRevenue !== null) {
            $downgradeParams['from_revenue'] = $fromRevenue;
            $downgradeParams['to_revenue'] = $toRevenue;
            $downgradeParams['revenue_change'] = round($toRevenue - $fromRevenue, 2);
        }

        $this->manager->trackEvent('plan_downgrade', array_merge($downgradeParams, $this->extractUtm($params)));
    }

    /**
     * Track subscription cancellation with churn analysis context.
     *
     * Includes cancellation reason, plan at time of cancellation,
     * lifetime value, and whether the user was a trial conversion.
     *
     * @param  string  $userId  User identifier
     * @param  array{plan?: string, reason?: string, feedback?: string, was_trial_conversion?: bool, ltv?: float, months_active?: int, currency?: string}  $params  Cancellation context
     */
    public function cancellation(string $userId, array $params = []): void
    {
        $this->manager->trackEvent('cancellation', array_merge([
            'user_id' => $userId,
            'plan' => $params['plan'] ?? null,
            'reason' => $params['reason'] ?? 'unknown',
            'feedback' => $params['feedback'] ?? null,
            'was_trial_conversion' => $params['was_trial_conversion'] ?? null,
            'ltv' => $params['ltv'] ?? null,
            'months_active' => $params['months_active'] ?? null,
            'currency' => $params['currency'] ?? 'USD',
        ], $this->extractUtm($params)));
    }

    // ─── Trial Templates ─────────────────────────────────────────────

    /**
     * Track trial start with conversion probability context.
     *
     * @param  string  $userId  User identifier
     * @param  string  $plan  Trial plan name
     * @param  int|null  $trialDays  Trial duration in days
     * @param  array{currency?: string, monthly_value?: float, activation_method?: string}  $params  Trial context
     */
    public function trialStart(string $userId, string $plan = 'free', ?int $trialDays = null, array $params = []): void
    {
        $this->manager->trackEvent('start_trial', array_merge([
            'user_id' => $userId,
            'plan' => $plan,
            'trial_days' => $trialDays,
            'currency' => $params['currency'] ?? 'USD',
            'monthly_value' => $params['monthly_value'] ?? null,
            'activation_method' => $params['activation_method'] ?? null,
        ], $this->extractUtm($params)));
    }

    /**
     * Track trial conversion with TTV (time-to-value) metrics.
     *
     * @param  string  $userId  User identifier
     * @param  string  $plan  Converted-to plan name
     * @param  array{revenue?: float, trial_days?: int, days_to_convert?: int, currency?: string, features_used_during_trial?: list<string>}  $params  Conversion context
     */
    public function trialConverted(string $userId, string $plan, array $params = []): void
    {
        $this->manager->trackEvent('trial_converted', array_merge([
            'user_id' => $userId,
            'plan' => $plan,
            'revenue' => $params['revenue'] ?? null,
            'trial_days' => $params['trial_days'] ?? null,
            'days_to_convert' => $params['days_to_convert'] ?? null,
            'currency' => $params['currency'] ?? 'USD',
            'features_used_during_trial' => $params['features_used_during_trial'] ?? null,
        ], $this->extractUtm($params)));
    }

    /**
     * Track trial expiration (user did not convert).
     *
     * @param  string  $userId  User identifier
     * @param  array{plan?: string, trial_days?: int, features_used?: int, last_active_days_ago?: int}  $params  Expiration context
     */
    public function trialExpired(string $userId, array $params = []): void
    {
        $this->manager->trackEvent('trial_expired', array_merge([
            'user_id' => $userId,
            'plan' => $params['plan'] ?? null,
            'trial_days' => $params['trial_days'] ?? null,
            'features_used' => $params['features_used'] ?? null,
            'last_active_days_ago' => $params['last_active_days_ago'] ?? null,
        ], $this->extractUtm($params)));
    }

    // ─── Revenue Templates ───────────────────────────────────────────

    /**
     * Track MRR movement event (new, expansion, contraction, churn).
     *
     * Industry-standard revenue event following the MRR movement framework.
     * Categorizes revenue change into standard SaaS revenue buckets.
     *
     * @param  string  $type  Movement type: 'new', 'expansion', 'contraction', 'churn', 'reactivation'
     * @param  float  $amount  MRR amount changed
     * @param  array{user_id?: string, plan?: string, currency?: string, previous_mrr?: float, new_mrr?: float}  $params  Revenue context
     */
    public function mrrMovement(string $type, float $amount, array $params = []): void
    {
        $validTypes = ['new', 'expansion', 'contraction', 'churn', 'reactivation'];
        $safeType = in_array($type, $validTypes, true) ? $type : 'new';

        $this->manager->trackEvent('revenue_tracked', array_merge([
            'user_id' => $params['user_id'] ?? null,
            'mrr_movement_type' => $safeType,
            'mrr_amount' => $amount,
            'plan' => $params['plan'] ?? null,
            'currency' => $params['currency'] ?? 'USD',
            'previous_mrr' => $params['previous_mrr'] ?? null,
            'new_mrr' => $params['new_mrr'] ?? null,
        ], $this->extractUtm($params)));
    }

    /**
     * Track a revenue event with full provider-optimized params.
     *
     * Generates GA4 purchase, Meta Purchase, and PostHog revenue event
     * parameters using the EcommerceFormatConverter.
     *
     * @param  string  $transactionId  Transaction/order ID
     * @param  float  $value  Revenue amount
     * @param  string  $currency  ISO 4217 currency code
     * @param  array<int, array{item_id: string, item_name?: string, item_category?: string, price: float, quantity: int}>  $items  Line items
     * @param  array{user_id?: string, tax?: float, shipping?: float, coupon?: string}  $params  Additional params
     */
    public function revenue(string $transactionId, float $value, string $currency, array $items, array $params = []): void
    {
        $ga4Params = EcommerceFormatConverter::buildGa4Purchase(
            $transactionId,
            $value,
            $currency,
            $items,
            array_filter([
                'tax' => $params['tax'] ?? null,
                'shipping' => $params['shipping'] ?? null,
                'coupon' => $params['coupon'] ?? null,
            ], fn ($v): bool => $v !== null),
        );

        $metaParams = EcommerceFormatConverter::ga4ToMetaPurchase($ga4Params);
        $posthogParams = EcommerceFormatConverter::ga4ToPosthogPurchase($ga4Params);

        $this->manager->trackEvent('purchase', array_merge([
            'user_id' => $params['user_id'] ?? null,
            'transaction_id' => $transactionId,
            'value' => $value,
            'currency' => $currency,
            'items' => $items,
            '_ga4_params' => $ga4Params,
            '_meta_params' => $metaParams,
            '_posthog_params' => $posthogParams,
        ], $this->extractUtm($params)));
    }

    // ─── Onboarding Templates ─────────────────────────────────────────

    /**
     * Track an onboarding step completion.
     *
     * @param  string  $userId  User identifier
     * @param  string  $step  Step identifier (e.g. 'profile_setup', 'team_invite', 'first_project')
     * @param  int  $stepNumber  Step order number (1-based)
     * @param  int  $totalSteps  Total number of onboarding steps
     * @param  array<string, mixed>  $params  Additional context
     */
    public function onboardingStepCompleted(string $userId, string $step, int $stepNumber, int $totalSteps, array $params = []): void
    {
        $this->manager->trackEvent('onboarding_step', array_merge([
            'user_id' => $userId,
            'step' => $step,
            'step_number' => $stepNumber,
            'total_steps' => $totalSteps,
            'completion_percent' => round(($stepNumber / $totalSteps) * 100, 1),
            'is_last_step' => $stepNumber === $totalSteps,
        ], $params));
    }

    /**
     * Track onboarding flow completion.
     *
     * @param  string  $userId  User identifier
     * @param  int  $totalSteps  Total steps completed
     * @param  array{time_to_complete?: int, skipped_steps?: list<string>, plan?: string}  $params  Completion context
     */
    public function onboardingCompleted(string $userId, int $totalSteps, array $params = []): void
    {
        $this->manager->trackEvent('onboarding_completed', array_merge([
            'user_id' => $userId,
            'total_steps' => $totalSteps,
            'time_to_complete_seconds' => $params['time_to_complete'] ?? null,
            'skipped_steps' => $params['skipped_steps'] ?? null,
            'plan' => $params['plan'] ?? null,
        ], $this->extractUtm($params)));
    }

    // ─── Feature Adoption Templates ──────────────────────────────────

    /**
     * Track first-time feature usage (activation event).
     *
     * @param  string  $userId  User identifier
     * @param  string  $featureName  Feature identifier
     * @param  array{category?: string, days_since_signup?: int}  $params  Feature context
     */
    public function featureFirstUse(string $userId, string $featureName, array $params = []): void
    {
        $this->manager->trackEvent('feature_used', array_merge([
            'user_id' => $userId,
            'feature_name' => $featureName,
            'is_first_use' => true,
            'category' => $params['category'] ?? null,
            'days_since_signup' => $params['days_since_signup'] ?? null,
        ], $params));
    }

    /**
     * Track power user milestone (Nth use of a feature).
     *
     * @param  string  $userId  User identifier
     * @param  string  $featureName  Feature identifier
     * @param  int  $usageCount  Total usage count at this milestone
     * @param  array<string, mixed>  $params  Additional context
     */
    public function featurePowerUser(string $userId, string $featureName, int $usageCount, array $params = []): void
    {
        $this->manager->trackEvent('feature_used', array_merge([
            'user_id' => $userId,
            'feature_name' => $featureName,
            'usage_count' => $usageCount,
            'milestone' => $usageCount >= 100 ? 'power_user' : ($usageCount >= 50 ? 'active' : 'regular'),
        ], $params));
    }

    // ─── Account Management Templates ────────────────────────────────

    /**
     * Track account email verification.
     *
     * @param  string  $userId  User identifier
     * @param  array{method?: string, time_to_verify?: int}  $params  Verification context
     */
    public function emailVerified(string $userId, array $params = []): void
    {
        $this->manager->trackEvent('email_verified', array_merge([
            'user_id' => $userId,
            'method' => $params['method'] ?? 'link',
            'time_to_verify_seconds' => $params['time_to_verify'] ?? null,
        ], $params));
    }

    /**
     * Track profile update event.
     *
     * @param  string  $userId  User identifier
     * @param  array{fields?: list<string>}  $params  Updated fields
     */
    public function profileUpdated(string $userId, array $params = []): void
    {
        $this->manager->trackEvent('profile_updated', array_merge([
            'user_id' => $userId,
            'updated_fields' => $params['fields'] ?? null,
        ], $params));
    }

    // ─── E-Commerce Shortcut Templates ──────────────────────────────

    /**
     * Track a view item event with standard e-commerce params.
     *
     * @param  array{item_id: string, item_name?: string, item_category?: string, price: float, currency?: string}  $item  Product data
     * @param  array{user_id?: string, list_name?: string}  $params  Additional context
     */
    public function viewItem(array $item, array $params = []): void
    {
        $currency = $item['currency'] ?? $params['currency'] ?? 'USD';
        $ga4Params = EcommerceFormatConverter::buildGa4ViewItem($item, $currency);

        $this->manager->trackEvent('view_item', array_merge([
            'user_id' => $params['user_id'] ?? null,
            '_ga4_params' => $ga4Params,
            'item_list_name' => $params['list_name'] ?? null,
        ], $item));
    }

    /**
     * Track add to cart event with standard e-commerce params.
     *
     * @param  array{item_id: string, item_name?: string, item_category?: string, price: float, quantity: int}  $item  Cart item
     * @param  string  $currency  ISO 4217 currency code
     * @param  array{user_id?: string, list_name?: string}  $params  Additional context
     */
    public function addToCart(array $item, string $currency = 'USD', array $params = []): void
    {
        $ga4Params = EcommerceFormatConverter::buildGa4AddToCart($item, $currency, $params['list_name'] ?? null);
        $metaParams = EcommerceFormatConverter::buildMetaAddToCart(
            $item['item_name'] ?? '',
            $item['item_category'] ?? '',
            $item,
            $currency,
        );

        $this->manager->trackEvent('add_to_cart', array_merge([
            'user_id' => $params['user_id'] ?? null,
            '_ga4_params' => $ga4Params,
            '_meta_params' => $metaParams,
        ], $item));
    }

    // ─── Utility ─────────────────────────────────────────────────────

    /**
     * Extract UTM attribution parameters from the params array.
     *
     * Only includes UTM keys that are present, avoiding null entries.
     *
     * @param  array<string, mixed>  $params  Source params
     * @return array<string, mixed>  Extracted UTM params
     */
    private function extractUtm(array $params): array
    {
        $utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        $utm = [];

        foreach ($utmKeys as $key) {
            if (isset($params[$key]) && (is_string($params[$key]) || is_numeric($params[$key]))) {
                $utm[$key] = $params[$key];
            }
        }

        return $utm;
    }

    /**
     * Create an AnalyticsEvent DTO without dispatching.
     *
     * Useful for custom dispatch pipelines or queue-based processing.
     *
     * @param  string  $name  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $clientId  Client ID
     * @param  string|null  $userId  User ID
     * @return AnalyticsEvent  The event DTO
     */
    public function createEvent(string $name, array $params = [], ?string $clientId = null, ?string $userId = null): AnalyticsEvent
    {
        return new AnalyticsEvent(
            name: $name,
            params: $params,
            clientId: $clientId,
            userId: $userId,
        );
    }

    /**
     * Dispatch an event using the async queue dispatcher.
     *
     * Non-blocking dispatch that routes through the configured queue.
     *
     * @param  string  $name  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string|null  $clientId  Client ID
     * @param  string|null  $userId  User ID
     */
    public function dispatchAsync(string $name, array $params = [], ?string $clientId = null, ?string $userId = null): void
    {
        $event = $this->createEvent($name, $params, $clientId, $userId);

        try {
            $dispatcher = app(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);
            $dispatcher->dispatch($event);
        } catch (\Throwable $e) {
            // Fallback to synchronous dispatch
            $this->manager->trackEvent($name, $params);
        }
    }
}
