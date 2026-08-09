<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks the end of a client-side analytics session.
 *
 * Fired when the tab is hidden, the user becomes idle beyond the
 * timeout, or on page unload. Carries session duration and
 * event counts for engagement analysis.
 *
 * GA4: session_end (custom)
 * Meta: (custom)
 *
 * @since 1.0.0
 */
final readonly class SessionEndEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $sessionId  Unique session identifier
     * @param  int|null  $durationSeconds  Total session duration in seconds
     * @param  int|null  $eventCount  Number of events tracked during the session
     * @param  int|null  $pageViewCount  Number of page views during the session
     * @param  string|null  $exitPage  Last page viewed before session end
     * @param  string|null  $endReason  Why the session ended (visibility, idle, unload)
     */
    public function __construct(
        ?string $sessionId = null,
        ?int $durationSeconds = null,
        ?int $eventCount = null,
        ?int $pageViewCount = null,
        ?string $exitPage = null,
        ?string $endReason = null,
    ): void {
        parent::__construct('session_end', array_filter([
            'session_id' => $sessionId,
            'duration_seconds' => $durationSeconds,
            'event_count' => $eventCount,
            'page_view_count' => $pageViewCount,
            'exit_page' => $exitPage,
            'end_reason' => $endReason,
        ], fn (mixed $v): bool => $v !== null));
    }
}
