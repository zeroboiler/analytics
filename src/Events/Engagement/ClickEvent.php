<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a click on a button or element.
 *
 * GA4: click (custom)
 * Meta: (custom)
 */
final readonly class ClickEvent extends AnalyticsEvent
{
    /**
     * @param  string  $elementId  ID of the clicked element
     * @param  string  $elementClass  CSS class of the clicked element
     * @param  string  $elementText  Text content of the clicked element
     * @param  string  $elementType  Type of element (e.g. 'button', 'link', 'input')
     * @param  string  $targetUrl  URL for link clicks
     */
    public function __construct(
        string $elementText = '',
        string $elementType = 'button',
        string $elementId = '',
        string $elementClass = '',
        string $targetUrl = '',
    ) {
        parent::__construct('click', array_filter([
            'element_text' => $elementText,
            'element_type' => $elementType,
            'element_id' => $elementId,
            'element_class' => $elementClass,
            'target_url' => $targetUrl,
        ]));
    }
}
