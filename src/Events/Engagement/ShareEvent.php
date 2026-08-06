<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks content sharing.
 *
 * GA4: share (standard)
 * Meta: (custom)
 */
final readonly class ShareEvent extends AnalyticsEvent
{
    /**
     * @param  string  $method  Sharing method (e.g. 'twitter', 'facebook', 'email', 'copy')
     * @param  string  $contentType  Type of content shared (e.g. 'article', 'product')
     * @param  string|null  $itemId  ID of the shared item
     */
    public function __construct(
        string $method = '',
        string $contentType = '',
        ?string $itemId = null,
    ) {
        parent::__construct('share', array_filter([
            'method' => $method,
            'content_type' => $contentType,
            'item_id' => $itemId,
        ]));
    }
}
