<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks element visibility via IntersectionObserver.
 *
 * Fires when a tracked element enters or leaves the viewport.
 * Used for content visibility analytics, hero section tracking,
 * pricing table impressions, and ad viewability measurement.
 *
 * GA4: element_visibility (custom)
 * Meta: null (custom)
 *
 * @since 27.0.0
 */
final readonly class ElementVisibilityEvent extends AnalyticsEvent
{
    /**
     * @param  string  $elementId  CSS ID or data attribute identifier
     * @param  string  $visibilityState  'visible' or 'hidden'
     * @param  float|null  $visibilityRatio  Percentage of element visible (0.0-1.0)
     * @param  string|null  $elementClass  CSS class name(s) of the element
     * @param  string|null  $section  Semantic section name (hero, pricing, testimonials, etc.)
     * @param  string|null  $pagePath  Page where element was observed
     */
    public function __construct(
        string $elementId,
        string $visibilityState,
        ?float $visibilityRatio = null,
        ?string $elementClass = null,
        ?string $section = null,
        ?string $pagePath = null,
    ): void {
        parent::__construct('element_visibility', array_filter([
            'element_id' => $elementId,
            'visibility_state' => $visibilityState,
            'visibility_ratio' => $visibilityRatio !== null ? round($visibilityRatio, 2) : null,
            'element_class' => $elementClass,
            'section' => $section,
            'page_path' => $pagePath,
        ], fn (mixed $v): bool => $v !== null));
    }
}
