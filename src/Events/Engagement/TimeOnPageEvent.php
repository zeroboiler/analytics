<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks time spent on a page.
 *
 * GA4: time_on_page (custom engagement timing)
 * Meta: (custom)
 */
final readonly class TimeOnPageEvent extends AnalyticsEvent
{
    /**
     * @param  int  $seconds  Time spent on page in seconds
     * @param  string  $pagePath  Current page path
     * @param  string|null  $pageTitle  Current page title
     */
    public function __construct(
        int $seconds,
        string $pagePath = '',
        ?string $pageTitle = null,
    ) {
        parent::__construct('time_on_page', array_filter([
            'engagement_time_msec' => $seconds * 1000,
            'seconds' => $seconds,
            'page_path' => $pagePath,
            'page_title' => $pageTitle,
        ]));
    }
}
