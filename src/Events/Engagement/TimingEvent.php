<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a custom timing measurement using the Performance API.
 *
 * Used for measuring operation duration, API response times,
 * render times, or any user-defined timing marks.
 *
 * GA4: timing (custom)
 * Meta: (custom)
 *
 * @since 1.0.0
 */
final readonly class TimingEvent extends AnalyticsEvent
{
    /**
     * @param  string  $timingName  Descriptive name for the timing measurement
     * @param  int  $durationMs  Duration in milliseconds
     * @param  string|null  $category  Timing category (e.g. 'api', 'render', 'custom')
     * @param  string|null  $pagePath  Page where timing was measured
     */
    public function __construct(
        string $timingName,
        int $durationMs,
        ?string $category = null,
        ?string $pagePath = null,
    ): void {
        parent::__construct('timing', array_filter([
            'timing_name' => $timingName,
            'timing_duration_ms' => $durationMs,
            'timing_category' => $category,
            'page_path' => $pagePath,
        ], fn (mixed $v): bool => $v !== null));
    }
}
