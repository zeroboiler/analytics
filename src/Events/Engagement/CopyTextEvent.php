<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks text copy/cut to clipboard events.
 *
 * Useful for measuring which content users find valuable enough to copy,
 * tracking code snippet usage, promo code copying, or pricing details.
 *
 * GA4: copy_text (custom)
 * Meta: null (custom)
 *
 * @since 27.0.0
 */
final readonly class CopyTextEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $copiedText  The text that was copied (truncated/sanitized)
     * @param  string|null  $elementType  Type of element containing the text (code, paragraph, span, etc.)
     * @param  string|null  $elementId  CSS ID or data attribute of the copied element
     * @param  string|null  $selectionLength  Length of the selected/copied text
     * @param  string|null  $pagePath  Page where the copy occurred
     */
    public function __construct(
        ?string $copiedText = null,
        ?string $elementType = null,
        ?string $elementId = null,
        ?string $selectionLength = null,
        ?string $pagePath = null,
    ): void {
        parent::__construct('copy_text', array_filter([
            'copied_text' => $copiedText !== null ? mb_substr($copiedText, 0, 200) : null,
            'element_type' => $elementType,
            'element_id' => $elementId,
            'selection_length' => $selectionLength !== null ? (int) $selectionLength : null,
            'page_path' => $pagePath,
        ], fn (mixed $v): bool => $v !== null));
    }
}
