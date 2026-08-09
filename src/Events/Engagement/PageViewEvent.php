<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a page view.
 *
 * GA4: page_view
 * Meta: PageView
 *
 * @since 1.0.0
 */
final readonly class PageViewEvent extends AnalyticsEvent
{
    /**
     * @param  string  $pageTitle  Page title
     * @param  string  $pageLocation  Full URL of the page
     * @param  string  $pageReferrer  Referrer URL
     */
    public function __construct(
        string $pageTitle = '',
        string $pageLocation = '',
        string $pageReferrer = '',
    ): void {
        parent::__construct('page_view', array_filter([
            'page_title' => $pageTitle,
            'page_location' => $pageLocation,
            'page_referrer' => $pageReferrer,
        ]));
    }
}
