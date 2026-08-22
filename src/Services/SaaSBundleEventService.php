<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Bundles related SaaS lifecycle events into named journey bundles.
 *
 * Groups sequences of events (e.g., signup → trial_start → subscribe) into
 * a single "journey bundle" with a shared bundle ID. This enables funnel
 * analysis, cohort attribution, and journey reconstruction in analytics providers.
 *
 * Bundles are tracked server-side and the bundle ID is propagated as an event
 * parameter for client-side correlation.
 *
 * Usage:
 *   $bundle = $service->startBundle('signup_funnel', $userId);
 *   $service->addToBundle($bundle, 'sign_up', [...]);
 *   $service->addToBundle($bundle, 'start_trial', [...]);
 *   $service->completeBundle($bundle, 'subscribe', [...]);
 *
 * @since 120.0.0
 */
final class SaaSBundleEventService
{
    /** @var array<string, array{id: string, name: string, user_id: string|null, events: list<array>, started_at: int, completed: bool}> */
    private array $bundles = [];

    private AnalyticsManager $manager;

    /**
     * Pre-defined journey templates mapping bundle names to expected event sequences.
     *
     * @var array<string, list<string>>
     */
    private const JOURNEY_TEMPLATES = [
        'signup_funnel' => ['sign_up', 'email_verified', 'start_trial', 'subscribe'],
        'activation_funnel' => ['sign_up', 'first_value', 'onboarding_completed'],
        'billing_funnel' => ['start_trial', 'subscribe', 'payment_succeeded'],
        'expansion_funnel' => ['plan_upgrade', 'feature_used', 'team_member_joined'],
        'retention_funnel' => ['subscription_renewal', 'feature_used', 'payment_succeeded'],
        'churn_funnel' => ['cancellation', 'account_deactivated'],
    ];

    /**
     * @param  AnalyticsManager  $manager
     */
    public function __construct(AnalyticsManager $manager){
        $this->manager = $manager;
    }

    /**
     * Start a new event bundle (journey).
     *
     * Creates a bundle with a unique ID and tracks it in memory.
     * The bundle ID is propagated as `bundle_id` in all subsequent events.
     *
     * @param  string  $journeyName  Journey template name or custom name
     * @param  string|null  $userId  User ID this journey belongs to
     * @param  array<string, mixed>  $context  Additional journey context
     * @return string  The bundle ID
     */
    public function startBundle(string $journeyName, ?string $userId = null, array $context = []): string
    {
        $bundleId = $this->generateBundleId($journeyName);

        $this->bundles[$bundleId] = [
            'id' => $bundleId,
            'name' => $journeyName,
            'user_id' => $userId,
            'events' => [],
            'started_at' => time(),
            'completed' => false,
            'context' => $context,
        ];

        $this->trackEvent('journey_start', [
            'bundle_id' => $bundleId,
            'journey_name' => $journeyName,
            'expected_steps' => $this->getExpectedSteps($journeyName),
        ], $userId);

        return $bundleId;
    }

    /**
     * Add an event to an existing bundle.
     *
     * Fires the individual event AND records it as part of the bundle.
     *
     * @param  string  $bundleId  Bundle ID from startBundle()
     * @param  string  $eventName  Analytics event name
     * @param  array<string, mixed>  $params  Event parameters
     * @return bool  Whether the event was added to the bundle
     */
    public function addToBundle(string $bundleId, string $eventName, array $params = []): bool
    {
        $bundle = $this->bundles[$bundleId] ?? null;

        if ($bundle === null) {
            return false;
        }

        if ($bundle['completed']) {
            return false;
        }

        $step = count($bundle['events']) + 1;
        $totalSteps = count($this->getExpectedSteps($bundle['name']));

        // Enrich the event with bundle context
        $enrichedParams = array_merge($params, [
            'bundle_id' => $bundleId,
            'bundle_name' => $bundle['name'],
            'bundle_step' => $step,
            'bundle_total_steps' => $totalSteps,
        ]);

        $this->bundles[$bundleId]['events'][] = [
            'event' => $eventName,
            'params' => $enrichedParams,
            'timestamp' => time(),
            'step' => $step,
        ];

        // Track the actual analytics event
        $this->trackEvent($eventName, $enrichedParams, $bundle['user_id']);

        return true;
    }

    /**
     * Complete a bundle and fire a journey completion event.
     *
     * @param  string  $bundleId  Bundle ID
     * @param  string  $finalEvent  Final event name (e.g., 'subscribe')
     * @param  array<string, mixed>  $params  Final event parameters
     * @return bool
     */
    public function completeBundle(string $bundleId, string $finalEvent, array $params = []): bool
    {
        $bundle = $this->bundles[$bundleId] ?? null;

        if ($bundle === null) {
            return false;
        }

        $this->addToBundle($bundleId, $finalEvent, $params);

        $this->bundles[$bundleId]['completed'] = true;

        $eventNames = array_map(fn (array $e): string => $e['event'], $bundle['events']);
        $duration = time() - $bundle['started_at'];

        $this->trackEvent('journey_completed', [
            'bundle_id' => $bundleId,
            'journey_name' => $bundle['name'],
            'steps_completed' => count($eventNames),
            'expected_steps' => count($this->getExpectedSteps($bundle['name'])),
            'event_sequence' => $eventNames,
            'duration_seconds' => $duration,
            'completion_pct' => $this->computeCompletionPct($bundle['name'], count($eventNames)),
        ], $bundle['user_id']);

        return true;
    }

    /**
     * Abandon a bundle (user dropped off).
     *
     * Fires a `journey_abandoned` event with the last completed step.
     *
     * @param  string  $bundleId  Bundle ID
     * @param  string|null  $reason  Abandonment reason
     * @return bool
     */
    public function abandonBundle(string $bundleId, ?string $reason = null): bool
    {
        $bundle = $this->bundles[$bundleId] ?? null;

        if ($bundle === null || $bundle['completed']) {
            return false;
        }

        $this->bundles[$bundleId]['completed'] = true;

        $eventNames = array_map(fn (array $e): string => $e['event'], $bundle['events']);
        $duration = time() - $bundle['started_at'];
        $lastStep = ! empty($eventNames) ? $eventNames[count($eventNames) - 1] : null;
        $expectedSteps = $this->getExpectedSteps($bundle['name']);

        $this->trackEvent('journey_abandoned', [
            'bundle_id' => $bundleId,
            'journey_name' => $bundle['name'],
            'steps_completed' => count($eventNames),
            'expected_steps' => count($expectedSteps),
            'last_step' => $lastStep,
            'next_expected_step' => $this->getNextExpectedStep($bundle['name'], count($eventNames)),
            'reason' => $reason,
            'duration_seconds' => $duration,
            'completion_pct' => $this->computeCompletionPct($bundle['name'], count($eventNames)),
        ], $bundle['user_id']);

        return true;
    }

    /**
     * Get a bundle by ID.
     *
     * @param  string  $bundleId
     * @return array{id: string, name: string, user_id: string|null, events: list<array>, started_at: int, completed: bool}|null
     */
    public function getBundle(string $bundleId): ?array
    {
        return $this->bundles[$bundleId] ?? null;
    }

    /**
     * Get all active (non-completed) bundles.
     *
     * @return array<string, array{id: string, name: string, user_id: string|null, events: list<array>, started_at: int, completed: bool}>
     */
    public function activeBundles(): array
    {
        return array_filter(
            $this->bundles,
            fn (array $b): bool => ! $b['completed'],
        );
    }

    /**
     * Get all completed bundles.
     *
     * @return array<string, array{id: string, name: string, user_id: string|null, events: list<array>, started_at: int, completed: bool}>
     */
    public function completedBundles(): array
    {
        return array_filter(
            $this->bundles,
            fn (array $b): bool => $b['completed'],
        );
    }

    /**
     * Get the list of available journey templates.
     *
     * @return array<string, list<string>>
     */
    public static function journeyTemplates(): array
    {
        return self::JOURNEY_TEMPLATES;
    }

    /**
     * Get expected event sequence for a journey name.
     *
     * @param  string  $journeyName
     * @return list<string>
     */
    public function getExpectedSteps(string $journeyName): array
    {
        return self::JOURNEY_TEMPLATES[$journeyName] ?? [];
    }

    /**
     * Get summary statistics for all bundles.
     *
     * @return array{total: int, active: int, completed: int, abandoned: int, avg_steps: float}
     */
    public function summary(): array
    {
        $total = count($this->bundles);
        $active = count($this->activeBundles());
        $completed = count($this->completedBundles());
        $abandoned = $total - $active - $completed;

        $allSteps = array_map(fn (array $b): int => count($b['events']), $this->bundles);

        return [
            'total' => $total,
            'active' => $active,
            'completed' => $completed,
            'abandoned' => max(0, $abandoned),
            'avg_steps' => $total > 0 ? round(array_sum($allSteps) / $total, 1) : 0.0,
        ];
    }

    /**
     * Clear all bundle state.
     */
    public function clear(): void
    {
        $this->bundles = [];
    }

    /**
     * Track an event through the analytics manager.
     *
     * @param  string  $name
     * @param  array<string, mixed>  $params
     * @param  string|null  $userId
     */
    private function trackEvent(string $name, array $params, ?string $userId): void
    {
        $event = new AnalyticsEvent(
            name: $name,
            params: $params,
            userId: $userId,
        );

        $this->manager->trackEvent($event);
    }

    /**
     * Generate a unique bundle ID.
     *
     * @param  string  $journeyName
     * @return string
     */
    private function generateBundleId(string $journeyName): string
    {
        return sprintf('bnd_%s_%s', substr($journeyName, 0, 20), bin2hex(random_bytes(8)));
    }

    /**
     * Compute completion percentage for a journey.
     *
     * @param  string  $journeyName
     * @param  int  $completedSteps
     * @return float
     */
    private function computeCompletionPct(string $journeyName, int $completedSteps): float
    {
        $expected = count($this->getExpectedSteps($journeyName));

        if ($expected === 0) {
            return 100.0;
        }

        return round(min(100.0, ($completedSteps / $expected) * 100), 1);
    }

    /**
     * Get the next expected step in a journey sequence.
     *
     * @param  string  $journeyName
     * @param  int  $currentStep  0-indexed current position
     * @return string|null
     */
    private function getNextExpectedStep(string $journeyName, int $currentStep): ?string
    {
        $steps = $this->getExpectedSteps($journeyName);

        return $steps[$currentStep] ?? null;
    }
}
