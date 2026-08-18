<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * SaaS Starter Journey Service — validates and tracks the complete
 * SaaS user lifecycle with catalog-validated events.
 *
 * Provides high-level methods for tracking the standard SaaS journey
 * stages (acquisition, activation, revenue, retention) while automatically
 * validating events against the EventCatalog, enriching with identity
 * context, and ensuring consistent parameter structure.
 *
 * Each method validates that the event exists in the catalog before
 * dispatching, and returns a structured result indicating success,
 * the dispatched event, and any validation issues.
 *
 * @since 249.0.0
 */
final class SaaSStarterJourneyService
{
    /**
     * Journey stage constants.
     */
    public const STAGE_ACQUISITION = 'acquisition';
    public const STAGE_ACTIVATION = 'activation';
    public const STAGE_REVENUE = 'revenue';
    public const STAGE_RETENTION = 'retention';

    /**
     * All journey stages in execution order.
     *
     * @var list<string>
     */
    public const STAGES = [
        self::STAGE_ACQUISITION,
        self::STAGE_ACTIVATION,
        self::STAGE_REVENUE,
        self::STAGE_RETENTION,
    ];

    private AnalyticsManager $manager;

    public function __construct(AnalyticsManager $manager)
    {
        $this->manager = $manager;
    }

    // ── Acquisition ─────────────────────────────────────────────

    /**
     * Track user sign-up with catalog validation.
     *
     * @param  array{method?: string, user_id?: string, client_id?: string}  $params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function signUp(array $params = []): array
    {
        return $this->trackValidated(
            'sign_up',
            array_merge(['method' => $params['method'] ?? 'email'], $params),
            'saas',
        );
    }

    /**
     * Track user login with catalog validation.
     *
     * @param  array{user_id?: string, client_id?: string, method?: string}  $params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function login(array $params = []): array
    {
        return $this->trackValidated(
            'login',
            array_merge(['method' => $params['method'] ?? 'email'], $params),
            'saas',
        );
    }

    // ── Activation ───────────────────────────────────────────────

    /**
     * Track trial start with catalog validation.
     *
     * @param  array{plan?: string, trial_days?: int, user_id?: string}  $params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function trialStart(array $params = []): array
    {
        return $this->trackValidated(
            'start_trial',
            array_merge([
                'plan' => $params['plan'] ?? 'free',
                'trial_days' => $params['trial_days'] ?? 14,
            ], $params),
            'saas',
        );
    }

    /**
     * Track feature used (aha moment) with catalog validation.
     *
     * @param  string  $feature  Feature name
     * @param  array<string, mixed>  $extra  Additional params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function featureUsed(string $feature, array $extra = []): array
    {
        return $this->trackValidated(
            'feature_used',
            array_merge(['feature' => $feature], $extra),
            'saas',
        );
    }

    // ── Revenue ──────────────────────────────────────────────────

    /**
     * Track subscription creation with catalog validation.
     *
     * @param  array{plan?: string, amount?: float, currency?: string, billing_cycle?: string}  $params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function subscription(array $params = []): array
    {
        return $this->trackValidated(
            'subscribe',
            array_merge([
                'plan' => $params['plan'] ?? 'starter',
                'amount' => $params['amount'] ?? 0.0,
                'currency' => $params['currency'] ?? 'USD',
                'billing_cycle' => $params['billing_cycle'] ?? 'monthly',
            ], $params),
            'saas',
        );
    }

    /**
     * Track plan upgrade with catalog validation.
     *
     * @param  string  $fromPlan  Previous plan
     * @param  string  $toPlan  New plan
     * @param  array<string, mixed>  $extra  Additional params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function planUpgrade(string $fromPlan, string $toPlan, array $extra = []): array
    {
        return $this->trackValidated(
            'plan_upgrade',
            array_merge(['from_plan' => $fromPlan, 'to_plan' => $toPlan], $extra),
            'saas',
        );
    }

    /**
     * Track e-commerce purchase with catalog validation.
     *
     * @param  array{transaction_id: string, value: float, currency?: string, items?: array<int, array<string, mixed>>}  $params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function purchase(array $params): array
    {
        return $this->trackValidated(
            'purchase',
            array_merge(['currency' => 'USD'], $params),
            'ecommerce',
        );
    }

    /**
     * Track refund with catalog validation.
     *
     * @param  array{transaction_id?: string, value?: float, currency?: string}  $params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function refund(array $params = []): array
    {
        return $this->trackValidated(
            'refund',
            array_merge(['currency' => 'USD'], $params),
            'ecommerce',
        );
    }

    // ── Retention ────────────────────────────────────────────────

    /**
     * Track cancellation with catalog validation.
     *
     * @param  array{plan?: string, reason?: string}  $params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function cancellation(array $params = []): array
    {
        return $this->trackValidated(
            'cancellation',
            array_merge(['reason' => $params['reason'] ?? 'unknown'], $params),
            'saas',
        );
    }

    // ── Engagement shortcuts ─────────────────────────────────────

    /**
     * Track page view with catalog validation.
     *
     * @param  array{title?: string, location?: string, referrer?: string}  $params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function pageView(array $params = []): array
    {
        return $this->trackValidated('page_view', $params, 'engagement');
    }

    /**
     * Track scroll depth with catalog validation.
     *
     * @param  int  $percent  Scroll percentage (0-100)
     * @param  array<string, mixed>  $extra  Additional params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function scrollDepth(int $percent, array $extra = []): array
    {
        return $this->trackValidated('scroll_depth', array_merge(['percent' => $percent], $extra), 'engagement');
    }

    /**
     * Track click with catalog validation.
     *
     * @param  string  $target  Click target identifier
     * @param  array<string, mixed>  $extra  Additional params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function click(string $target, array $extra = []): array
    {
        return $this->trackValidated('click', array_merge(['target' => $target], $extra), 'engagement');
    }

    /**
     * Track form start with catalog validation.
     *
     * @param  array{form_id?: string, form_name?: string}  $params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function formStart(array $params = []): array
    {
        return $this->trackValidated('form_start', $params, 'engagement');
    }

    /**
     * Track form submit with catalog validation.
     *
     * @param  array{form_id?: string, form_name?: string, success?: bool}  $params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function formSubmit(array $params = []): array
    {
        return $this->trackValidated('form_submit', $params, 'engagement');
    }

    /**
     * Track search with catalog validation.
     *
     * @param  string  $query  Search query
     * @param  array<string, mixed>  $extra  Additional params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function search(string $query, array $extra = []): array
    {
        return $this->trackValidated('search', array_merge(['query' => $query], $extra), 'engagement');
    }

    /**
     * Track share with catalog validation.
     *
     * @param  string  $method  Share method (email, twitter, link, etc.)
     * @param  array<string, mixed>  $extra  Additional params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function share(string $method, array $extra = []): array
    {
        return $this->trackValidated('share', array_merge(['method' => $method], $extra), 'engagement');
    }

    /**
     * Track error with catalog validation.
     *
     * @param  string  $message  Error message
     * @param  array<string, mixed>  $extra  Additional params
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    public function error(string $message, array $extra = []): array
    {
        return $this->trackValidated('error', array_merge(['message' => $message], $extra), 'engagement');
    }

    // ── Journey orchestration ─────────────────────────────────────

    /**
     * Track the complete SaaS starter journey in sequence.
     *
     * Fires all 20 starter events in priority order, validating each
     * against the catalog. Returns a detailed report of the journey.
     *
     * @param  array{user_id?: string, client_id?: string, plan?: string, method?: string}  $context
     * @return array{total: int, succeeded: int, failed: int, events: list<array{name: string, category: string, stage: string, success: bool, errors: list<string>}>}
     */
    public function trackFullJourney(array $context = []): array
    {
        $plan = $context['plan'] ?? 'starter';
        $method = $context['method'] ?? 'email';
        $results = [];
        $succeeded = 0;
        $failed = 0;

        $steps = [
            // Acquisition
            ['name' => 'sign_up', 'category' => 'saas', 'stage' => self::STAGE_ACQUISITION, 'params' => ['method' => $method] + $context],
            ['name' => 'login', 'category' => 'saas', 'stage' => self::STAGE_ACQUISITION, 'params' => ['method' => $method] + $context],
            // Activation
            ['name' => 'start_trial', 'category' => 'saas', 'stage' => self::STAGE_ACTIVATION, 'params' => ['plan' => $plan, 'trial_days' => 14] + $context],
            ['name' => 'feature_used', 'category' => 'saas', 'stage' => self::STAGE_ACTIVATION, 'params' => ['feature' => 'dashboard'] + $context],
            ['name' => 'page_view', 'category' => 'engagement', 'stage' => self::STAGE_ACTIVATION, 'params' => ['title' => 'Dashboard'] + $context],
            // Revenue
            ['name' => 'subscribe', 'category' => 'saas', 'stage' => self::STAGE_REVENUE, 'params' => ['plan' => $plan, 'amount' => 29.0, 'currency' => 'USD', 'billing_cycle' => 'monthly'] + $context],
            ['name' => 'plan_upgrade', 'category' => 'saas', 'stage' => self::STAGE_REVENUE, 'params' => ['from_plan' => $plan, 'to_plan' => 'pro'] + $context],
            ['name' => 'purchase', 'category' => 'ecommerce', 'stage' => self::STAGE_REVENUE, 'params' => ['transaction_id' => 'TX-JOURNEY', 'value' => 49.0, 'currency' => 'USD'] + $context],
            ['name' => 'view_item', 'category' => 'ecommerce', 'stage' => self::STAGE_REVENUE, 'params' => ['item_id' => 'SKU-001', 'item_name' => 'Pro Plan'] + $context],
            ['name' => 'add_to_cart', 'category' => 'ecommerce', 'stage' => self::STAGE_REVENUE, 'params' => ['item_id' => 'SKU-001', 'price' => 49.0, 'quantity' => 1] + $context],
            ['name' => 'cancellation', 'category' => 'saas', 'stage' => self::STAGE_REVENUE, 'params' => ['plan' => 'pro', 'reason' => 'test_journey'] + $context],
            ['name' => 'refund', 'category' => 'ecommerce', 'stage' => self::STAGE_REVENUE, 'params' => ['transaction_id' => 'TX-JOURNEY', 'value' => 49.0, 'currency' => 'USD'] + $context],
            ['name' => 'trial_converted', 'category' => 'saas', 'stage' => self::STAGE_REVENUE, 'params' => ['plan' => $plan] + $context],
            // Retention
            ['name' => 'form_start', 'category' => 'engagement', 'stage' => self::STAGE_RETENTION, 'params' => ['form_id' => 'feedback'] + $context],
            ['name' => 'form_submit', 'category' => 'engagement', 'stage' => self::STAGE_RETENTION, 'params' => ['form_id' => 'feedback', 'success' => true] + $context],
            ['name' => 'click', 'category' => 'engagement', 'stage' => self::STAGE_RETENTION, 'params' => ['target' => 'cta_upgrade'] + $context],
            ['name' => 'search', 'category' => 'engagement', 'stage' => self::STAGE_RETENTION, 'params' => ['query' => 'export feature'] + $context],
            ['name' => 'scroll_depth', 'category' => 'engagement', 'stage' => self::STAGE_RETENTION, 'params' => ['percent' => 75] + $context],
            ['name' => 'share', 'category' => 'engagement', 'stage' => self::STAGE_RETENTION, 'params' => ['method' => 'link'] + $context],
            ['name' => 'error', 'category' => 'engagement', 'stage' => self::STAGE_RETENTION, 'params' => ['message' => 'Test error for journey validation'] + $context],
        ];

        foreach ($steps as $step) {
            $result = $this->trackValidated($step['name'], $step['params'], $step['category']);
            $results[] = [
                'name' => $step['name'],
                'category' => $step['category'],
                'stage' => $step['stage'],
                'success' => $result['success'],
                'errors' => $result['errors'],
            ];

            if ($result['success']) {
                $succeeded++;
            } else {
                $failed++;
            }
        }

        return [
            'total' => count($steps),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'events' => $results,
        ];
    }

    /**
     * Get the journey readiness report.
     *
     * Checks which of the 20 starter events are present in the catalog
     * and returns a structured report grouped by journey stage.
     *
     * @return array{stages: array<string, array{total: int, present: int, missing: list<string>, score: float}>, overall: float, total: int, present: int}
     */
    public function readinessReport(): array
    {
        $stages = [
            self::STAGE_ACQUISITION => ['sign_up', 'login'],
            self::STAGE_ACTIVATION => ['start_trial', 'feature_used', 'page_view'],
            self::STAGE_REVENUE => ['subscribe', 'plan_upgrade', 'purchase', 'view_item', 'add_to_cart', 'cancellation', 'refund', 'trial_converted'],
            self::STAGE_RETENTION => ['form_start', 'form_submit', 'click', 'search', 'scroll_depth', 'share', 'error'],
        ];

        $report = ['stages' => [], 'overall' => 0.0, 'total' => 0, 'present' => 0];

        foreach ($stages as $stage => $events) {
            $present = 0;
            $missing = [];

            foreach ($events as $event) {
                $report['total']++;

                if (EventCatalog::has($event)) {
                    $present++;
                    $report['present']++;
                } else {
                    $missing[] = $event;
                }
            }

            $total = count($events);
            $score = $total > 0 ? round(($present / $total) * 100.0, 1) : 100.0;

            $report['stages'][$stage] = [
                'total' => $total,
                'present' => $present,
                'missing' => $missing,
                'score' => $score,
            ];
        }

        $report['overall'] = $report['total'] > 0
            ? round(($report['present'] / $report['total']) * 100.0, 1)
            : 100.0;

        return $report;
    }

    // ── Internal ─────────────────────────────────────────────────

    /**
     * Track an event with catalog validation.
     *
     * Validates that the event name exists in the EventCatalog,
     * creates a typed AnalyticsEvent, and dispatches via the manager.
     *
     * @param  string  $name  Event name
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string  $expectedCategory  Expected category for validation
     * @return array{success: bool, event: AnalyticsEvent|null, errors: list<string>}
     */
    private function trackValidated(string $name, array $params, string $expectedCategory): array
    {
        $errors = [];

        if (! EventCatalog::has($name)) {
            $errors[] = "Event '{$name}' not found in EventCatalog.";

            return ['success' => false, 'event' => null, 'errors' => $errors];
        }

        $actualCategory = EventCatalog::category($name);

        if ($actualCategory !== $expectedCategory && $actualCategory !== null) {
            $errors[] = "Category mismatch: expected '{$expectedCategory}', got '{$actualCategory}'.";
        }

        $event = new AnalyticsEvent(name: $name, params: $params, category: $expectedCategory);

        try {
            $this->manager->trackEvent($event);
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();

            return ['success' => false, 'event' => $event, 'errors' => $errors];
        }

        return ['success' => true, 'event' => $event, 'errors' => $errors];
    }
}
