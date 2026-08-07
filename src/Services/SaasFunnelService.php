<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

/**
 * SaaS lifecycle funnel tracking service.
 *
 * Provides high-level methods for tracking complete user lifecycle funnels:
 * Acquisition → Activation → Trial → Conversion → Retention → Expansion → Churn.
 *
 * Each funnel method tracks the individual step event AND a funnel-type summary
 * event with the funnel name and step. This enables downstream funnel analytics
 * without requiring complex session reconstruction.
 *
 * All funnel steps are tracked as `funnel_{funnel}_{step}` events in addition
 * to the standard typed event class.
 *
 * Configuration:
 *   zeroboiler.analytics.funnels.enabled (default: true)
 */
final class SaasFunnelService
{
    /** @var array<string, list<string>> */
    private const FUNNEL_STEPS = [
        'signup_funnel' => [
            'landing_page',
            'signup_view',
            'signup_form_start',
            'signup_form_submit',
            'signup_confirm',
        ],
        'trial_funnel' => [
            'trial_start',
            'trial_active',
            'trial_converted',
            'trial_expired',
        ],
        'conversion_funnel' => [
            'pricing_view',
            'plan_select',
            'checkout_start',
            'checkout_complete',
        ],
        'retention_funnel' => [
            'feature_used',
            'renewal_eligible',
            'renewal_started',
            'renewal_complete',
        ],
        'expansion_funnel' => [
            'upgrade_eligible',
            'upgrade_view',
            'upgrade_select',
            'upgrade_complete',
        ],
    ];

    private AnalyticsManager $manager;

    private QueuedAnalyticsDispatcher $queue;

    private bool $enabled;

    /**
     * @param  AnalyticsManager  $manager
     * @param  QueuedAnalyticsDispatcher  $queue
     * @param  ConfigRepository  $config
     */
    public function __construct(AnalyticsManager $manager, QueuedAnalyticsDispatcher $queue, ConfigRepository $config): void
: void {
        $this->manager = $manager;
        $this->queue = $queue;

        $funnelConfig = $config->get('zeroboiler.analytics.funnels', []);
        /** @var array{enabled?: bool} $funnelConfig */
        $this->enabled = (bool) ($funnelConfig['enabled'] ?? true);
    }

    /**
     * ─── Signup Funnel ──────────────────────────────────────────────────
     */

    /**
     * Track a landing page view (acquisition start).
     *
     * @param  string|null  $source  Traffic source (organic, paid, referral, direct)
     * @param  array<string, mixed>  $params
     */
    public function signupLandingPage(?string $source = null, array $params = []): void
    {
        $this->trackFunnelStep('signup_funnel', 'landing_page', array_merge([
            'source' => $source,
        ], $params));
    }

    /**
     * Track signup page view.
     *
     * @param  string|null  $method  Signup method (email, google, github)
     * @param  array<string, mixed>  $params
     */
    public function signupView(?string $method = null, array $params = []): void
    {
        $this->trackFunnelStep('signup_funnel', 'signup_view', array_merge([
            'method' => $method,
        ], $params));
    }

    /**
     * Track signup form start (first field interaction).
     *
     * @param  array<string, mixed>  $params
     */
    public function signupFormStart(array $params = []): void
    {
        $this->trackFunnelStep('signup_funnel', 'signup_form_start', $params);
    }

    /**
     * Track signup form submission attempt.
     *
     * @param  string|null  $method  Signup method
     * @param  bool  $success  Whether the submission was valid
     * @param  array<string, mixed>  $params
     */
    public function signupFormSubmit(?string $method = null, bool $success = true, array $params = []): void
    {
        $this->trackFunnelStep('signup_funnel', 'signup_form_submit', array_merge([
            'method' => $method,
            'success' => $success,
        ], $params));
    }

    /**
     * Track signup completion / confirmation.
     *
     * @param  string|null  $method  Signup method
     * @param  string|null  $userId  Authenticated user ID
     * @param  array<string, mixed>  $params
     */
    public function signupComplete(?string $method = null, ?string $userId = null, array $params = []): void
    {
        $this->trackFunnelStep('signup_funnel', 'signup_confirm', array_merge([
            'method' => $method,
            'user_id' => $userId,
        ], $params));
    }

    /**
     * ─── Trial Funnel ────────────────────────────────────────────────────
     */

    /**
     * Track trial start.
     *
     * @param  string  $plan  Trial plan name
     * @param  int  $trialDays  Number of trial days
     * @param  array<string, mixed>  $params
     */
    public function trialStart(string $plan, int $trialDays = 14, array $params = []): void
    {
        $this->trackFunnelStep('trial_funnel', 'trial_start', array_merge([
            'plan' => $plan,
            'trial_days' => $trialDays,
        ], $params));
    }

    /**
     * Track trial engagement (user returned and was active).
     *
     * @param  string  $plan  Trial plan name
     * @param  int  $day  Trial day number
     * @param  array<string, mixed>  $params
     */
    public function trialActive(string $plan, int $day, array $params = []): void
    {
        $this->trackFunnelStep('trial_funnel', 'trial_active', array_merge([
            'plan' => $plan,
            'trial_day' => $day,
        ], $params));
    }

    /**
     * Track trial conversion (user subscribed before trial expired).
     *
     * @param  string  $plan  Trial plan name
     * @param  string  $convertedPlan  Subscription plan name
     * @param  array<string, mixed>  $params
     */
    public function trialConverted(string $plan, string $convertedPlan, array $params = []): void
    {
        $this->trackFunnelStep('trial_funnel', 'trial_converted', array_merge([
            'plan' => $plan,
            'converted_plan' => $convertedPlan,
        ], $params));
    }

    /**
     * Track trial expiration (user did not convert).
     *
     * @param  string  $plan  Trial plan name
     * @param  int  $daysActive  How many days the user was active during trial
     * @param  array<string, mixed>  $params
     */
    public function trialExpired(string $plan, int $daysActive = 0, array $params = []): void
    {
        $this->trackFunnelStep('trial_funnel', 'trial_expired', array_merge([
            'plan' => $plan,
            'days_active' => $daysActive,
        ], $params));
    }

    /**
     * ─── Conversion Funnel ──────────────────────────────────────────────
     */

    /**
     * Track pricing page view.
     *
     * @param  string|null  $referral  Where the user came from
     * @param  array<string, mixed>  $params
     */
    public function pricingView(?string $referral = null, array $params = []): void
    {
        $this->trackFunnelStep('conversion_funnel', 'pricing_view', array_merge([
            'referral' => $referral,
        ], $params));
    }

    /**
     * Track plan selection.
     *
     * @param  string  $plan  Selected plan name
     * @param  string|null  $billingCycle  monthly, annual
     * @param  array<string, mixed>  $params
     */
    public function planSelect(string $plan, ?string $billingCycle = null, array $params = []): void
    {
        $this->trackFunnelStep('conversion_funnel', 'plan_select', array_merge([
            'plan' => $plan,
            'billing_cycle' => $billingCycle,
        ], $params));
    }

    /**
     * Track checkout start.
     *
     * @param  string  $plan  Selected plan name
     * @param  float|null  $value  Expected revenue
     * @param  array<string, mixed>  $params
     */
    public function checkoutStart(string $plan, ?float $value = null, array $params = []): void
    {
        $this->trackFunnelStep('conversion_funnel', 'checkout_start', array_merge([
            'plan' => $plan,
            'value' => $value,
        ], $params));
    }

    /**
     * Track checkout completion.
     *
     * @param  string  $plan  Subscribed plan name
     * @param  float  $value  Revenue amount
     * @param  string  $currency  Currency code
     * @param  array<string, mixed>  $params
     */
    public function checkoutComplete(string $plan, float $value, string $currency = 'USD', array $params = []): void
    {
        $this->trackFunnelStep('conversion_funnel', 'checkout_complete', array_merge([
            'plan' => $plan,
            'value' => $value,
            'currency' => $currency,
        ], $params));
    }

    /**
     * ─── Retention Funnel ───────────────────────────────────────────────
     */

    /**
     * Track feature usage (retention indicator).
     *
     * @param  string  $feature  Feature identifier
     * @param  int  $usageCount  Usage count
     * @param  array<string, mixed>  $params
     */
    public function featureUsed(string $feature, int $usageCount = 1, array $params = []): void
    {
        $this->trackFunnelStep('retention_funnel', 'feature_used', array_merge([
            'feature' => $feature,
            'usage_count' => $usageCount,
        ], $params));
    }

    /**
     * Track renewal eligibility.
     *
     * @param  string  $plan  Current plan
     * @param  int  $daysUntilRenewal  Days until subscription renewal
     * @param  array<string, mixed>  $params
     */
    public function renewalEligible(string $plan, int $daysUntilRenewal, array $params = []): void
    {
        $this->trackFunnelStep('retention_funnel', 'renewal_eligible', array_merge([
            'plan' => $plan,
            'days_until_renewal' => $daysUntilRenewal,
        ], $params));
    }

    /**
     * Track renewal start.
     *
     * @param  string  $plan  Renewed plan
     * @param  float|null  $value  Renewal amount
     * @param  array<string, mixed>  $params
     */
    public function renewalStart(string $plan, ?float $value = null, array $params = []): void
    {
        $this->trackFunnelStep('retention_funnel', 'renewal_started', array_merge([
            'plan' => $plan,
            'value' => $value,
        ], $params));
    }

    /**
     * Track renewal completion.
     *
     * @param  string  $plan  Renewed plan
     * @param  float  $value  Renewal amount
     * @param  string  $currency  Currency code
     * @param  array<string, mixed>  $params
     */
    public function renewalComplete(string $plan, float $value, string $currency = 'USD', array $params = []): void
    {
        $this->trackFunnelStep('retention_funnel', 'renewal_complete', array_merge([
            'plan' => $plan,
            'value' => $value,
            'currency' => $currency,
        ], $params));
    }

    /**
     * ─── Expansion Funnel ───────────────────────────────────────────────
     */

    /**
     * Track upgrade eligibility.
     *
     * @param  string  $currentPlan  Current plan
     * @param  string|null  $eligiblePlan  Eligible upgrade plan
     * @param  array<string, mixed>  $params
     */
    public function upgradeEligible(string $currentPlan, ?string $eligiblePlan = null, array $params = []): void
    {
        $this->trackFunnelStep('expansion_funnel', 'upgrade_eligible', array_merge([
            'current_plan' => $currentPlan,
            'eligible_plan' => $eligiblePlan,
        ], $params));
    }

    /**
     * Track upgrade page view.
     *
     * @param  string  $currentPlan  Current plan
     * @param  array<string, mixed>  $params
     */
    public function upgradeView(string $currentPlan, array $params = []): void
    {
        $this->trackFunnelStep('expansion_funnel', 'upgrade_view', [
            'current_plan' => $currentPlan,
        ]);
    }

    /**
     * Track upgrade plan selection.
     *
     * @param  string  $fromPlan  Current plan
     * @param  string  $toPlan  Selected upgrade plan
     * @param  array<string, mixed>  $params
     */
    public function upgradeSelect(string $fromPlan, string $toPlan, array $params = []): void
    {
        $this->trackFunnelStep('expansion_funnel', 'upgrade_select', array_merge([
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
        ], $params));
    }

    /**
     * Track upgrade completion.
     *
     * @param  string  $fromPlan  Previous plan
     * @param  string  $toPlan  New plan
     * @param  float|null  $value  Additional revenue
     * @param  array<string, mixed>  $params
     */
    public function upgradeComplete(string $fromPlan, string $toPlan, ?float $value = null, array $params = []): void
    {
        $this->trackFunnelStep('expansion_funnel', 'upgrade_complete', array_merge([
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
            'value' => $value,
        ], $params));
    }

    /**
     * Track a generic funnel step.
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  string  $step  Step name
     * @param  array<string, mixed>  $params  Step parameters
     */
    public function trackFunnelStep(string $funnelName, string $step, array $params = []): void
    {
        if (! $this->enabled) {
            return;
        }

        $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
            name: "funnel_{$funnelName}_{$step}",
            params: array_merge($params, [
                'funnel' => $funnelName,
                'funnel_step' => $step,
            ]),
        );

        $this->manager->trackEvent($event);
    }

    /**
     * Get all available funnel definitions.
     *
     * @return array<string, list<string>>
     */
    public static function getFunnels(): array
    {
        return self::FUNNEL_STEPS;
    }

    /**
     * Get the steps for a specific funnel.
     *
     * @return list<string>
     */
    public static function getFunnelSteps(string $funnelName): array
    {
        return self::FUNNEL_STEPS[$funnelName] ?? [];
    }

    /**
     * Check if funnel tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
