<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a click on an outbound (external) link.
 *
 * Separate from the generic ClickEvent to enable outbound link
 * analysis as a distinct engagement signal.
 *
 * GA4: outbound_click (custom)
 * Meta: (custom)
 *
 * @since 1.0.0
 */
final readonly class OutboundClickEvent extends AnalyticsEvent
{
    /**
     * @param  string  $linkUrl  Destination URL
     * @param  string  $linkText  Anchor text of the link
     * @param  string|null  $linkName  Custom link name from data-analytics-link attribute
     * @param  string|null  $pagePath  Page where the click occurred
     */
    public function __construct(
        string $linkUrl,
        string $linkText = '',
        ?string $linkName = null,
        ?string $pagePath = null,
    ): void {
        parent::__construct('outbound_click', array_filter([
            'link_url' => $linkUrl,
            'link_text' => $linkText,
            'link_name' => $linkName,
            'page_path' => $pagePath,
        ], fn (mixed $v): bool => $v !== null));
    }
}
