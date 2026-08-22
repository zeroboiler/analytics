<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

/**
 * Pre-built SaaS funnel definition templates.
 *
 * Provides industry-standard funnel definitions for common SaaS analytics
 * patterns. Each funnel defines an ordered sequence of events, expected
 * conversion windows, and business context for use with DeclarativeFunnelService
 * and FunnelAnalyticsService.
 *
 * Funnel definitions follow the AARRR framework and include:
 *
 * - **signup_funnel**: Landing page → signup → email verify → activation
 * - **trial_conversion_funnel**: Trial start → feature usage → trial convert → first payment
 * - **expansion_revenue_funnel**: Feature adoption → limit reached → plan upgrade → payment
 * - **activation_funnel**: Signup → first feature → onboarding complete → return visit
 * - **checkout_funnel**: View item → add to cart → begin checkout → payment → purchase
 * - **retention_funnel**: Login → feature usage → content engagement → return within 7 days
 * - **referral_funnel**: Share → invite sent → invitee signup → team member joined
 * - **cancellation_flow_funnel**: Payment failure → support contact → downgrade → cancel
 *
 * Each definition includes:
 * - Ordered step events with names
 * - Step labels for dashboards
 * - Expected conversion windows (in days)
 * - Optional timeout thresholds for drop-off detection
 *
 * @see \ZeroBoiler\Analytics\Services\DeclarativeFunnelService
 * @see \ZeroBoiler\Analytics\Services\FunnelAnalyticsService
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 *
 * @since 101.0.0
 */
final class SaaSFunnelDefinitions
{
    /**
     * @phpstan-type FunnelStep array{name: string, label: string, event_name: string, expected_window_days: int, timeout_days?: int|null, weight: float}
     * @phpstan-type FunnelDefinition array{key: string, name: string, description: string, category: string, steps: list<FunnelStep>, aarrr_pillar: string, industry: string}
     */

    /**
     * Get all built-in funnel definitions.
     *
     * Returns an array of funnel definitions keyed by their unique identifier.
     * Each definition contains metadata, ordered steps, and business context.
     *
     * @return array<string, FunnelDefinition>
     */
    public static function all(): array
    {
        return [
            'signup_funnel' => self::signupFunnel(),
            'trial_conversion_funnel' => self::trialConversionFunnel(),
            'expansion_revenue_funnel' => self::expansionRevenueFunnel(),
            'activation_funnel' => self::activationFunnel(),
            'checkout_funnel' => self::checkoutFunnel(),
            'retention_funnel' => self::retentionFunnel(),
            'referral_funnel' => self::referralFunnel(),
            'cancellation_flow_funnel' => self::cancellationFlowFunnel(),
        ];
    }

    /**
     * Get a specific funnel definition by key.
     *
     * @return FunnelDefinition|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Get all funnel definition keys.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Get funnel definitions grouped by AARRR pillar.
     *
     * @return array{acquisition: list<FunnelDefinition>, activation: list<FunnelDefinition>, retention: list<FunnelDefinition>, revenue: list<FunnelDefinition>, referral: list<FunnelDefinition>}
     */
    public static function byAarrrPillar(): array
    {
        $grouped = [
            'acquisition' => [],
            'activation' => [],
            'retention' => [],
            'revenue' => [],
            'referral' => [],
        ];

        foreach (self::all() as $definition) {
            $pillar = $definition['aarrr_pillar'];
            if (isset($grouped[$pillar])) {
                $grouped[$pillar][] = $definition;
            }
        }

        return $grouped;
    }

    /**
     * Get funnel definitions for a specific category.
     *
     * @param  'saas'|'ecommerce'|'growth'  $category
     * @return list<FunnelDefinition>
     */
    public static function byCategory(string $category): array
    {
        return array_values(array_filter(
            self::all(),
            fn (array $def): bool => $def['category'] === $category,
        ));
    }

    /**
     * Get the event names required for a specific funnel.
     *
     * Useful for checking instrumentation coverage — if all events in a
     * funnel are tracked, the funnel can be visualized.
     *
     * @return list<string>
     */
    public static function requiredEvents(string $key): array
    {
        $funnel = self::get($key);

        if ($funnel === null) {
            return [];
        }

        return array_map(
            fn (array $step): string => $step['event_name'],
            $funnel['steps'],
        );
    }

    /**
     * Get funnel definitions that match the given events.
     *
     * Returns funnels where ALL required events are present in the
     * provided event list. Useful for determining which funnels are
     * fully instrumented.
     *
     * @param  list<string>  $trackedEvents  List of event names that are currently tracked
     * @return list<FunnelDefinition>
     */
    public static function fullyInstrumented(array $trackedEvents): array
    {
        $trackedSet = array_flip($trackedEvents);
        $result = [];

        foreach (self::all() as $key => $definition) {
            $required = self::requiredEvents($key);
            $allCovered = true;

            foreach ($required as $eventName) {
                if (! isset($trackedSet[$eventName])) {
                    $allCovered = false;
                    break;
                }
            }

            if ($allCovered) {
                $result[] = $definition;
            }
        }

        return $result;
    }

    /**
     * Get instrumentation coverage for each funnel.
     *
     * Returns a breakdown of how many steps are instrumented for each funnel,
     * expressed as a percentage and list of missing events.
     *
     * @param  list<string>  $trackedEvents  List of event names that are currently tracked
     * @return array<string, array{funnel: string, total_steps: int, covered_steps: int, coverage_percent: float, missing_events: list<string>, status: string}>
     */
    public static function coverageReport(array $trackedEvents): array
    {
        $trackedSet = array_flip($trackedEvents);
        $report = [];

        foreach (self::all() as $key => $definition) {
            $total = count($definition['steps']);
            $covered = 0;
            $missing = [];

            foreach ($definition['steps'] as $step) {
                if (isset($trackedSet[$step['event_name']])) {
                    $covered++;
                } else {
                    $missing[] = $step['event_name'];
                }
            }

            $percent = $total > 0 ? round(($covered / $total) * 100, 1) : 0.0;

            $report[$key] = [
                'funnel' => $definition['name'],
                'total_steps' => $total,
                'covered_steps' => $covered,
                'coverage_percent' => $percent,
                'missing_events' => $missing,
                'status' => $percent === 100.0 ? 'complete' : ($percent >= 50.0 ? 'partial' : 'minimal'),
            ];
        }

        return $report;
    }

    /**
     * Get funnel step definitions formatted for DeclarativeFunnelService.
     *
     * Converts funnel definitions into the format expected by
     * DeclarativeFunnelService::define().
     *
     * @return array<string, array{steps: list<string>, name: string}>
     */
    public static function forDeclarativeService(): array
    {
        $result = [];

        foreach (self::all() as $key => $definition) {
            $result[$key] = [
                'steps' => array_map(
                    fn (array $step): string => $step['event_name'],
                    $definition['steps'],
                ),
                'name' => $definition['name'],
            ];
        }

        return $result;
    }

    // ─── Funnel Definitions ─────────────────────────────────────────

    /**
     * Signup Funnel — user acquisition from first touch to activated account.
     *
     * Tracks the journey from landing page engagement through account creation
     * to email verification. This is the top-of-funnel for any SaaS product.
     *
     * @return FunnelDefinition
     */
    public static function signupFunnel(): array
    {
        return [
            'key' => 'signup_funnel',
            'name' => 'Signup Funnel',
            'description' => 'Tracks user acquisition from first engagement to account activation',
            'category' => 'saas',
            'aarrr_pillar' => 'acquisition',
            'industry' => 'all',
            'steps' => [
                [
                    'name' => 'landing',
                    'label' => 'Landing Page View',
                    'event_name' => 'page_view',
                    'expected_window_days' => 0,
                    'weight' => 1.0,
                ],
                [
                    'name' => 'signup',
                    'label' => 'Account Created',
                    'event_name' => 'sign_up',
                    'expected_window_days' => 1,
                    'weight' => 2.0,
                ],
                [
                    'name' => 'verify',
                    'label' => 'Email Verified',
                    'event_name' => 'email_verified',
                    'expected_window_days' => 3,
                    'weight' => 1.5,
                ],
                [
                    'name' => 'first_login',
                    'label' => 'First Login',
                    'event_name' => 'login',
                    'expected_window_days' => 7,
                    'weight' => 1.0,
                ],
            ],
        ];
    }

    /**
     * Trial Conversion Funnel — free trial to paid subscription.
     *
     * The most critical SaaS funnel. Measures how effectively the product
     * converts trial users to paying customers through feature engagement.
     *
     * @return FunnelDefinition
     */
    public static function trialConversionFunnel(): array
    {
        return [
            'key' => 'trial_conversion_funnel',
            'name' => 'Trial Conversion Funnel',
            'description' => 'Measures free trial to paid subscription conversion effectiveness',
            'category' => 'saas',
            'aarrr_pillar' => 'revenue',
            'industry' => 'all',
            'steps' => [
                [
                    'name' => 'trial_start',
                    'label' => 'Trial Started',
                    'event_name' => 'start_trial',
                    'expected_window_days' => 0,
                    'weight' => 1.0,
                ],
                [
                    'name' => 'onboarding',
                    'label' => 'Onboarding Step',
                    'event_name' => 'onboarding_step',
                    'expected_window_days' => 3,
                    'weight' => 1.5,
                ],
                [
                    'name' => 'feature_use',
                    'label' => 'Feature Used',
                    'event_name' => 'feature_used',
                    'expected_window_days' => 7,
                    'weight' => 2.0,
                ],
                [
                    'name' => 'onboarding_complete',
                    'label' => 'Onboarding Completed',
                    'event_name' => 'onboarding_completed',
                    'expected_window_days' => 14,
                    'weight' => 1.5,
                ],
                [
                    'name' => 'convert',
                    'label' => 'Trial Converted',
                    'event_name' => 'trial_converted',
                    'expected_window_days' => 30,
                    'timeout_days' => 30,
                    'weight' => 3.0,
                ],
                [
                    'name' => 'first_payment',
                    'label' => 'First Payment',
                    'event_name' => 'payment_succeeded',
                    'expected_window_days' => 30,
                    'weight' => 2.0,
                ],
            ],
        ];
    }

    /**
     * Expansion Revenue Funnel — existing customer upsell path.
     *
     * Tracks how existing customers discover and adopt premium features,
     * hit usage limits, and ultimately upgrade to higher-value plans.
     *
     * @return FunnelDefinition
     */
    public static function expansionRevenueFunnel(): array
    {
        return [
            'key' => 'expansion_revenue_funnel',
            'name' => 'Expansion Revenue Funnel',
            'description' => 'Tracks existing customer path from feature adoption to plan upgrade',
            'category' => 'saas',
            'aarrr_pillar' => 'revenue',
            'industry' => 'b2b_sass',
            'steps' => [
                [
                    'name' => 'feature_adopt',
                    'label' => 'Feature Adopted',
                    'event_name' => 'feature_adopted',
                    'expected_window_days' => 0,
                    'weight' => 1.0,
                ],
                [
                    'name' => 'milestone',
                    'label' => 'Milestone Reached',
                    'event_name' => 'milestone_reached',
                    'expected_window_days' => 14,
                    'weight' => 1.5,
                ],
                [
                    'name' => 'limit_reached',
                    'label' => 'Feature Limit Reached',
                    'event_name' => 'feature_limit_reached',
                    'expected_window_days' => 30,
                    'weight' => 2.0,
                ],
                [
                    'name' => 'upgrade',
                    'label' => 'Plan Upgraded',
                    'event_name' => 'plan_upgrade',
                    'expected_window_days' => 14,
                    'weight' => 3.0,
                ],
                [
                    'name' => 'expansion_payment',
                    'label' => 'Expansion Payment',
                    'event_name' => 'payment_succeeded',
                    'expected_window_days' => 14,
                    'weight' => 2.0,
                ],
            ],
        ];
    }

    /**
     * Activation Funnel — signup to first meaningful value realization.
     *
     * Measures product-led growth activation. The user must complete
     * key milestones to experience the "aha moment" that drives retention.
     *
     * @return FunnelDefinition
     */
    public static function activationFunnel(): array
    {
        return [
            'key' => 'activation_funnel',
            'name' => 'Activation Funnel',
            'description' => 'Measures time to value from signup to first meaningful feature use',
            'category' => 'saas',
            'aarrr_pillar' => 'activation',
            'industry' => 'all',
            'steps' => [
                [
                    'name' => 'signup',
                    'label' => 'Signed Up',
                    'event_name' => 'sign_up',
                    'expected_window_days' => 0,
                    'weight' => 1.0,
                ],
                [
                    'name' => 'verify',
                    'label' => 'Email Verified',
                    'event_name' => 'email_verified',
                    'expected_window_days' => 1,
                    'weight' => 1.5,
                ],
                [
                    'name' => 'onboarding',
                    'label' => 'Onboarding Started',
                    'event_name' => 'onboarding_step',
                    'expected_window_days' => 1,
                    'weight' => 1.0,
                ],
                [
                    'name' => 'first_feature',
                    'label' => 'First Feature Used',
                    'event_name' => 'feature_used',
                    'expected_window_days' => 3,
                    'weight' => 2.0,
                ],
                [
                    'name' => 'aha_moment',
                    'label' => 'Activation Event',
                    'event_name' => 'activation',
                    'expected_window_days' => 7,
                    'timeout_days' => 7,
                    'weight' => 3.0,
                ],
                [
                    'name' => 'return_visit',
                    'label' => 'Return Visit (D1)',
                    'event_name' => 'login',
                    'expected_window_days' => 1,
                    'weight' => 2.0,
                ],
            ],
        ];
    }

    /**
     * Checkout Funnel — e-commerce purchase path.
     *
     * Standard e-commerce conversion funnel from product discovery
     * through to completed purchase. Applies to both SaaS payment
     * flows and traditional e-commerce.
     *
     * @return FunnelDefinition
     */
    public static function checkoutFunnel(): array
    {
        return [
            'key' => 'checkout_funnel',
            'name' => 'Checkout Funnel',
            'description' => 'Standard e-commerce purchase funnel from product view to completed order',
            'category' => 'ecommerce',
            'aarrr_pillar' => 'revenue',
            'industry' => 'ecommerce',
            'steps' => [
                [
                    'name' => 'view',
                    'label' => 'Product Viewed',
                    'event_name' => 'view_item',
                    'expected_window_days' => 0,
                    'weight' => 1.0,
                ],
                [
                    'name' => 'add_to_cart',
                    'label' => 'Added to Cart',
                    'event_name' => 'add_to_cart',
                    'expected_window_days' => 1,
                    'weight' => 1.5,
                ],
                [
                    'name' => 'view_cart',
                    'label' => 'Cart Viewed',
                    'event_name' => 'view_cart',
                    'expected_window_days' => 1,
                    'weight' => 1.0,
                ],
                [
                    'name' => 'checkout',
                    'label' => 'Checkout Started',
                    'event_name' => 'begin_checkout',
                    'expected_window_days' => 1,
                    'weight' => 1.5,
                ],
                [
                    'name' => 'payment_info',
                    'label' => 'Payment Info Added',
                    'event_name' => 'add_payment_info',
                    'expected_window_days' => 1,
                    'weight' => 1.5,
                ],
                [
                    'name' => 'purchase',
                    'label' => 'Purchase Completed',
                    'event_name' => 'purchase',
                    'expected_window_days' => 1,
                    'timeout_days' => 7,
                    'weight' => 3.0,
                ],
            ],
        ];
    }

    /**
     * Retention Funnel — ongoing user engagement health.
     *
     * Measures whether users return and engage after initial signup/activation.
     * High retention funnel completion correlates with strong product-market fit.
     *
     * @return FunnelDefinition
     */
    public static function retentionFunnel(): array
    {
        return [
            'key' => 'retention_funnel',
            'name' => 'Retention Funnel',
            'description' => 'Measures ongoing user engagement and return visit patterns',
            'category' => 'saas',
            'aarrr_pillar' => 'retention',
            'industry' => 'all',
            'steps' => [
                [
                    'name' => 'd0_login',
                    'label' => 'Day 0: Login',
                    'event_name' => 'login',
                    'expected_window_days' => 0,
                    'weight' => 1.0,
                ],
                [
                    'name' => 'd0_feature',
                    'label' => 'Day 0: Feature Used',
                    'event_name' => 'feature_used',
                    'expected_window_days' => 0,
                    'weight' => 1.5,
                ],
                [
                    'name' => 'd1_return',
                    'label' => 'Day 1: Return Visit',
                    'event_name' => 'login',
                    'expected_window_days' => 1,
                    'weight' => 2.0,
                ],
                [
                    'name' => 'd3_return',
                    'label' => 'Day 3: Return Visit',
                    'event_name' => 'login',
                    'expected_window_days' => 3,
                    'weight' => 2.0,
                ],
                [
                    'name' => 'd7_return',
                    'label' => 'Day 7: Return Visit',
                    'event_name' => 'login',
                    'expected_window_days' => 7,
                    'weight' => 2.5,
                ],
                [
                    'name' => 'd7_engagement',
                    'label' => 'Day 7: Deep Engagement',
                    'event_name' => 'content_engagement',
                    'expected_window_days' => 7,
                    'weight' => 2.0,
                ],
            ],
        ];
    }

    /**
     * Referral Funnel — organic growth through user sharing.
     *
     * Tracks the viral loop from sharing to new user acquisition.
     * Essential for PLG (product-led growth) companies measuring K-factor.
     *
     * @return FunnelDefinition
     */
    public static function referralFunnel(): array
    {
        return [
            'key' => 'referral_funnel',
            'name' => 'Referral Funnel',
            'description' => 'Tracks organic growth through user sharing and team invitations',
            'category' => 'growth',
            'aarrr_pillar' => 'referral',
            'industry' => 'plg',
            'steps' => [
                [
                    'name' => 'share',
                    'label' => 'Content Shared',
                    'event_name' => 'share',
                    'expected_window_days' => 0,
                    'weight' => 1.0,
                ],
                [
                    'name' => 'invite',
                    'label' => 'Invitation Sent',
                    'event_name' => 'invite_sent',
                    'expected_window_days' => 7,
                    'weight' => 1.5,
                ],
                [
                    'name' => 'signup',
                    'label' => 'Invitee Signed Up',
                    'event_name' => 'sign_up',
                    'expected_window_days' => 14,
                    'weight' => 2.0,
                ],
                [
                    'name' => 'team_join',
                    'label' => 'Joined Team',
                    'event_name' => 'team_member_joined',
                    'expected_window_days' => 14,
                    'weight' => 2.5,
                ],
            ],
        ];
    }

    /**
     * Cancellation Flow Funnel — churn prevention path.
     *
     * Tracks the common steps users take before cancelling, helping
     * identify intervention points. Payment failures, support contacts,
     * and plan downgrades often precede full cancellation.
     *
     * @return FunnelDefinition
     */
    public static function cancellationFlowFunnel(): array
    {
        return [
            'key' => 'cancellation_flow_funnel',
            'name' => 'Cancellation Flow Funnel',
            'description' => 'Tracks common pre-cancellation signals for churn prevention',
            'category' => 'saas',
            'aarrr_pillar' => 'retention',
            'industry' => 'all',
            'steps' => [
                [
                    'name' => 'payment_fail',
                    'label' => 'Payment Failed',
                    'event_name' => 'payment_failed',
                    'expected_window_days' => 0,
                    'weight' => 2.0,
                ],
                [
                    'name' => 'billing_retry',
                    'label' => 'Billing Retry',
                    'event_name' => 'billing_retry',
                    'expected_window_days' => 3,
                    'weight' => 2.0,
                ],
                [
                    'name' => 'downgrade',
                    'label' => 'Plan Downgraded',
                    'event_name' => 'plan_downgrade',
                    'expected_window_days' => 7,
                    'weight' => 2.5,
                ],
                [
                    'name' => 'pause',
                    'label' => 'Subscription Paused',
                    'event_name' => 'subscription_paused',
                    'expected_window_days' => 14,
                    'weight' => 2.0,
                ],
                [
                    'name' => 'cancel',
                    'label' => 'Subscription Cancelled',
                    'event_name' => 'cancellation',
                    'expected_window_days' => 30,
                    'timeout_days' => 30,
                    'weight' => 3.0,
                ],
            ],
        ];
    }

    /**
     * Get the total count of built-in funnel definitions.
     */
    public static function count(): int
    {
        return count(self::all());
    }

    /**
     * Validate the integrity of all funnel definitions.
     *
     * Checks that each funnel has required fields, valid step counts,
     * and that all referenced event names exist in the EventCatalog.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public static function validate(): array
    {
        $errors = [];
        $warnings = [];

        foreach (self::all() as $key => $definition) {
            if (empty($definition['name'])) {
                $errors[] = "Funnel '{$key}' is missing 'name'";
            }

            if (empty($definition['steps']) || ! is_array($definition['steps'])) {
                $errors[] = "Funnel '{$key}' has no steps or invalid steps";
                continue;
            }

            if (count($definition['steps']) < 2) {
                $warnings[] = "Funnel '{$key}' has fewer than 2 steps — not a meaningful funnel";
            }

            $seenEvents = [];
            foreach ($definition['steps'] as $i => $step) {
                if (empty($step['event_name'])) {
                    $errors[] = "Funnel '{$key}' step {$i} is missing 'event_name'";
                }

                if (empty($step['label'])) {
                    $warnings[] = "Funnel '{$key}' step {$i} is missing 'label'";
                }

                $eventName = $step['event_name'] ?? '';
                if ($eventName !== '' && ! \ZeroBoiler\Analytics\Events\EventCatalog::has($eventName)) {
                    $warnings[] = "Funnel '{$key}' step '{$step['name']}' references unknown event '{$eventName}'";
                }

                if (in_array($eventName, $seenEvents, true)) {
                    $warnings[] = "Funnel '{$key}' has duplicate event '{$eventName}'";
                }
                $seenEvents[] = $eventName;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
