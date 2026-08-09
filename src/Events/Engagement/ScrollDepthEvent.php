<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks scroll depth milestones (25%, 50%, 75%, 90%).
 *
 * GA4: scroll (custom)
 * Meta: (custom)
 *
 * @since 1.0.0
 */
final readonly class ScrollDepthEvent extends AnalyticsEvent
{
    /**
     * @param  int  $percent  Scroll depth percentage (e.g. 25, 50, 75, 90)
     * @param  string  $pagePath  Current page path
     * @param  string|null  $pageTitle  Current page title
     */
    public function __construct(
        int $percent,
        string $pagePath = '',
        ?string $pageTitle = null,
    ): void {
        parent::__construct('scroll_depth', array_filter([
            'percent' => $percent,
            'page_path' => $pagePath,
            'page_title' => $pageTitle,
        ]));
    }
}
