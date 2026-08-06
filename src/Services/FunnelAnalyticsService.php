<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

/**
 * Conversion funnel analytics service for SaaS applications.
 *
 * Provides a high-level API for tracking multi-step conversion funnels
 * (signup, onboarding, purchase, trial-to-paid) with step tracking,
 * abandonment detection, and funnel completion analytics.
 *
 * Funnels are tracked via the server-side API and can be configured
 * with custom step names and metadata.
 *
 * @see \ZeroBoiler\Analytics\AnalyticsManager
 */
final class FunnelAnalyticsService
{
    /** @var array<string, mixed> */
    private array $activeFunnels = [];

    private AnalyticsManager $manager;

    private QueuedAnalyticsDispatcher $queue;

    private bool $useAsync;

    /**
     * @param  AnalyticsManager  $manager
     * @param  QueuedAnalyticsDispatcher  $queue
     * @param  ConfigRepository  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        QueuedAnalyticsDispatcher $queue,
        ConfigRepository $config,
    ) {
        $this->manager = $manager;
        $this->queue = $queue;

        $queueConfig = $config->get('zeroboiler.analytics.queue', []);
        /** @var array{enabled?: bool} $queueConfig */
        $this->useAsync = (bool) ($queueConfig['enabled'] ?? true);
    }

    /**
     * Start a new funnel tracking session.
     *
     * @param  string  $funnelName  Unique funnel identifier (e.g. 'signup', 'purchase')
     * @param  array<string, mixed>  $metadata  Additional funnel metadata
     */
    public function startFunnel(string $funnelName, array $metadata = []): void
    {
        $this->activeFunnels[$funnelName] = [
            'started_at' => microtime(true),
            'steps_completed' => 0,
            'current_step' => null,
            'metadata' => $metadata,
        ];

        $this->trackFunnelEvent('funnel_started', $funnelName, $metadata);
    }

    /**
     * Track a step within a funnel.
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  string  $stepName  Step name (e.g. 'form_start', 'payment_info')
     * @param  int  $stepNumber  Sequential step number (1-based)
     * @param  array<string, mixed>  $params  Additional step parameters
     */
    public function trackStep(string $funnelName, string $stepName, int $stepNumber, array $params = []): void
    {
        if (! isset($this->activeFunnels[$funnelName])) {
            $this->startFunnel($funnelName);
        }

        $this->activeFunnels[$funnelName]['current_step'] = $stepName;
        $this->activeFunnels[$funnelName]['steps_completed'] = $stepNumber;

        $elapsed = $this->getFunnelElapsed($funnelName);

        $this->trackFunnelEvent('funnel_step', $funnelName, array_merge([
            'funnel_step_name' => $stepName,
            'funnel_step_number' => $stepNumber,
            'funnel_elapsed_ms' => $elapsed,
        ], $params));
    }

    /**
     * Track funnel completion (successful conversion).
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  int  $totalSteps  Total steps in the funnel
     * @param  array<string, mixed>  $params  Additional conversion parameters
     */
    public function complete(string $funnelName, int $totalSteps, array $params = []): void
    {
        $elapsed = $this->getFunnelElapsed($funnelName);
        $stepsCompleted = $this->activeFunnels[$funnelName]['steps_completed'] ?? $totalSteps;

        $this->trackFunnelEvent('funnel_completed', $funnelName, array_merge([
            'funnel_total_steps' => $totalSteps,
            'funnel_steps_completed' => $stepsCompleted,
            'funnel_elapsed_ms' => $elapsed,
            'funnel_skipped_steps' => max(0, $totalSteps - $stepsCompleted),
        ], $params));

        unset($this->activeFunnels[$funnelName]);
    }

    /**
     * Track funnel abandonment (user dropped off).
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  string  $abandonedAt  Step where the user abandoned
     * @param  int  $totalSteps  Total steps in the funnel
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function abandon(string $funnelName, string $abandonedAt, int $totalSteps, array $params = []): void
    {
        $elapsed = $this->getFunnelElapsed($funnelName);
        $stepsCompleted = $this->activeFunnels[$funnelName]['steps_completed'] ?? 0;

        $this->trackFunnelEvent('funnel_abandoned', $funnelName, array_merge([
            'funnel_abandoned_at' => $abandonedAt,
            'funnel_total_steps' => $totalSteps,
            'funnel_steps_completed' => $stepsCompleted,
            'funnel_elapsed_ms' => $elapsed,
            'funnel_completion_rate' => $totalSteps > 0
                ? round(($stepsCompleted / $totalSteps) * 100, 2)
                : 0,
        ], $params));

        unset($this->activeFunnels[$funnelName]);
    }

    /**
     * Track a funnel retry (user re-entered the funnel after abandoning).
     *
     * @param  string  $funnelName  Funnel identifier
     * @param  int  $attemptNumber  Retry attempt number (2, 3, etc.)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function retry(string $funnelName, int $attemptNumber, array $params = []): void
    {
        $this->trackFunnelEvent('funnel_retry', $funnelName, array_merge([
            'funnel_attempt_number' => $attemptNumber,
        ], $params));
    }

    /**
     * Check if a funnel is currently active.
     */
    public function isActive(string $funnelName): bool
    {
        return isset($this->activeFunnels[$funnelName]);
    }

    /**
     * Get the current step of an active funnel.
     */
    public function getCurrentStep(string $funnelName): ?string
    {
        return $this->activeFunnels[$funnelName]['current_step'] ?? null;
    }

    /**
     * Get all active funnel names.
     *
     * @return list<string>
     */
    public function getActiveFunnels(): array
    {
        return array_keys($this->activeFunnels);
    }

    /**
     * Get the elapsed time for a funnel in milliseconds.
     */
    private function getFunnelElapsed(string $funnelName): int
    {
        if (! isset($this->activeFunnels[$funnelName])) {
            return 0;
        }

        return (int) ((microtime(true) - $this->activeFunnels[$funnelName]['started_at']) * 1000);
    }

    /**
     * Track a funnel-related event.
     *
     * @param  array<string, mixed>  $params
     */
    private function trackFunnelEvent(string $eventName, string $funnelName, array $params = []): void
    {
        $event = new AnalyticsEvent(
            name: $eventName,
            params: array_merge([
                'funnel_name' => $funnelName,
            ], $params),
        );

        if ($this->useAsync) {
            $this->queue->dispatch($event);
        } else {
            $this->manager->trackEvent($event);
        }
    }

    /**
     * Get the underlying analytics manager.
     */
    public function getManager(): AnalyticsManager
    {
        return $this->manager;
    }
}
