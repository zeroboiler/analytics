<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Product feedback / NPS survey response event.
 *
 * Tracks user satisfaction signals including NPS scores, CSAT ratings,
 * feature feedback, and survey responses. Critical for product-market fit
 * analysis and customer success metrics.
 *
 * @phpstan-type FeedbackParams array{
 *     feedback_type: string,
 *     score: int|null,
 *     rating?: string|null,
 *     category?: string|null,
 *     comment?: string|null,
 *     source?: string|null,
 *     ...array<string, mixed>
 * }
 *
 * @since 1.0.0
 */
final readonly class FeedbackEvent extends AnalyticsEvent
{
    /**
     * @param  string  $feedbackType  Type of feedback (nps, csat, ces, feature_request, bug_report)
     * @param  int|null  $score  Numeric score (0-10 for NPS, 1-5 for CSAT)
     * @param  string|null  $rating  Textual rating (promoter, passive, detractor)
     * @param  string|null  $category  Feedback category (ui, performance, feature, support)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $feedbackType,
        ?int $score = null,
        ?string $rating = null,
        ?string $category = null,
        array $params = [],
    ){
        parent::__construct(
            name: 'feedback',
            params: array_merge([
                'feedback_type' => $feedbackType,
                'score' => $score,
                'rating' => $rating,
                'category' => $category,
            ], $params),
        );
    }
}
