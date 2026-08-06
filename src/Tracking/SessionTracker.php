<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tracking;

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

/**
 * Tracks session-level analytics for SaaS applications.
 *
 * Provides session start/end tracking, page view counting, session duration,
 * and conversion funnel tracking. Works with the QueuedAnalyticsDispatcher
 * for async, non-blocking event dispatch.
 */
class SessionTracker
{
    /** @var array<string, int> */
    private array $sessionPageCounts = [];

    /** @var array<string, float> */
    private array $sessionStartTimes = [];

    private QueuedAnalyticsDispatcher $queue;

    private AnalyticsManager $manager;

    public function __construct(
        QueuedAnalyticsDispatcher $queue,
        AnalyticsManager $manager,
    ) {
        $this->queue = $queue;
        $this->manager = $manager;
    }

    /**
     * Start tracking a session.
     *
     * @param  array<string, mixed>  $params  Additional session parameters
     */
    public function startSession(string $sessionId, array $params = []): void
    {
        $this->sessionStartTimes[$sessionId] = microtime(true);
        $this->sessionPageCounts[$sessionId] = 0;

        $event = new AnalyticsEvent(
            name: 'session_start',
            params: array_merge([
                'session_id' => $sessionId,
            ], $params),
        );

        $this->queue->dispatch($event);
    }

    /**
     * End a session and track its duration.
     *
     * @param  array<string, mixed>  $params  Additional session end parameters
     */
    public function endSession(string $sessionId, array $params = []): void
    {
        $startTime = $this->sessionStartTimes[$sessionId] ?? null;
        $pageCount = $this->sessionPageCounts[$sessionId] ?? 0;

        if ($startTime !== null) {
            $duration = (int) ((microtime(true) - $startTime) * 1000);
            unset($this->sessionStartTimes[$sessionId]);
        } else {
            $duration = 0;
        }

        unset($this->sessionPageCounts[$sessionId]);

        $event = new AnalyticsEvent(
            name: 'session_end',
            params: array_merge([
                'session_id' => $sessionId,
                'session_duration_ms' => $duration,
                'session_page_count' => $pageCount,
            ], $params),
        );

        $this->queue->dispatch($event);
    }

    /**
     * Track a page view within a session context.
     *
     * @param  array<string, mixed>  $params  Additional page view parameters
     */
    public function trackSessionPageView(string $sessionId, array $params = []): void
    {
        $this->sessionPageCounts[$sessionId] = ($this->sessionPageCounts[$sessionId] ?? 0) + 1;

        $event = new AnalyticsEvent(
            name: 'session_page_view',
            params: array_merge([
                'session_id' => $sessionId,
                'session_page_number' => $this->sessionPageCounts[$sessionId],
            ], $params),
        );

        $this->manager->trackEvent($event);
    }

    /**
     * Track a step in a conversion funnel.
     *
     * @param  string  $funnelName  The funnel identifier (e.g. 'signup', 'purchase')
     * @param  string  $stepName  The step within the funnel (e.g. 'form_start', 'confirm')
     * @param  int  $stepNumber  The sequential step number (1-based)
     * @param  array<string, mixed>  $params  Additional funnel parameters
     */
    public function trackFunnelStep(
        string $funnelName,
        string $stepName,
        int $stepNumber,
        array $params = [],
    ): void {
        $event = new AnalyticsEvent(
            name: 'funnel_step',
            params: array_merge([
                'funnel_name' => $funnelName,
                'funnel_step' => $stepName,
                'funnel_step_number' => $stepNumber,
            ], $params),
        );

        $this->manager->trackEvent($event);
    }

    /**
     * Track a completed funnel (conversion).
     *
     * @param  string  $funnelName  The funnel identifier
     * @param  int  $totalSteps  Total number of steps in the funnel
     * @param  array<string, mixed>  $params  Additional conversion parameters
     */
    public function trackFunnelComplete(
        string $funnelName,
        int $totalSteps,
        array $params = [],
    ): void {
        $event = new AnalyticsEvent(
            name: 'funnel_complete',
            params: array_merge([
                'funnel_name' => $funnelName,
                'funnel_total_steps' => $totalSteps,
            ], $params),
        );

        $this->manager->trackEvent($event);
    }

    /**
     * Track a funnel abandonment.
     *
     * @param  string  $funnelName  The funnel identifier
     * @param  string  $abandonedAtStep  The step where the user abandoned
     * @param  int  $totalSteps  Total number of steps in the funnel
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackFunnelAbandon(
        string $funnelName,
        string $abandonedAtStep,
        int $totalSteps,
        array $params = [],
    ): void {
        $event = new AnalyticsEvent(
            name: 'funnel_abandon',
            params: array_merge([
                'funnel_name' => $funnelName,
                'funnel_abandoned_at_step' => $abandonedAtStep,
                'funnel_total_steps' => $totalSteps,
            ], $params),
        );

        $this->manager->trackEvent($event);
    }

    /**
     * Get the current page count for a session.
     */
    public function getSessionPageCount(string $sessionId): int
    {
        return $this->sessionPageCounts[$sessionId] ?? 0;
    }

    /**
     * Get the elapsed session duration in milliseconds.
     */
    public function getSessionDuration(string $sessionId): int
    {
        $startTime = $this->sessionStartTimes[$sessionId] ?? null;

        if ($startTime === null) {
            return 0;
        }

        return (int) ((microtime(true) - $startTime) * 1000);
    }

    /**
     * Check if a session is being tracked.
     */
    public function hasSession(string $sessionId): bool
    {
        return isset($this->sessionStartTimes[$sessionId]);
    }
}
