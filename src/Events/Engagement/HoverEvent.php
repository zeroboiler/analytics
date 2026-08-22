<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks element hover/focus interactions.
 *
 * Fires when a user hovers over a tracked interactive element for a
 * minimum duration, indicating interest or intent before clicking.
 * Useful for measuring feature discovery, pricing plan interest,
 * and CTA engagement signals.
 *
 * GA4: hover (custom)
 * Meta: null (custom)
 *
 * @since 27.0.0
 */
final readonly class HoverEvent extends AnalyticsEvent
{
    /**
     * @param  string  $elementId  CSS ID or data attribute of the hovered element
     * @param  string|null  $elementClass  CSS class name(s)
     * @param  string|null  $elementType  Element tag type (button, a, div, etc.)
     * @param  string|null  $label  Accessible label or text content
     * @param  int|null  $hoverDurationMs  Duration of the hover in milliseconds
     * @param  string|null  $pagePath  Page where hover occurred
     */
    public function __construct(
        string $elementId,
        ?string $elementClass = null,
        ?string $elementType = null,
        ?string $label = null,
        ?int $hoverDurationMs = null,
        ?string $pagePath = null,
    ){
        parent::__construct('hover', array_filter([
            'element_id' => $elementId,
            'element_class' => $elementClass,
            'element_type' => $elementType,
            'label' => $label !== null ? mb_substr($label, 0, 200) : null,
            'hover_duration_ms' => $hoverDurationMs,
            'page_path' => $pagePath,
        ], fn (mixed $v): bool => $v !== null));
    }
}
