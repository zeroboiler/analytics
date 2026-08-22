<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * User feature request event for tracking demand signals in SaaS products.
 *
 * Captures when a user requests a new feature, votes on existing requests,
 * or interacts with a feature request board. Essential for product-roadmap
 * alignment and prioritization analytics.
 *
 * @since 1.0.0
 */
final readonly class FeatureRequestEvent extends AnalyticsEvent
{
    /**
     * @param  non-empty-string  $featureDescription  Short description of the requested feature
     * @param  string|null  $category  Feature category (e.g. 'reporting', 'integration', 'automation', 'ui')
     * @param  string|null  $source  Where the request originated (e.g. 'in_app_modal', 'feedback_widget', 'support_ticket', 'changelog')
     * @param  int|null  $voteCount  Number of votes if this is a vote action on existing request
     * @param  string|null  $requestId  Unique identifier for the feature request (for deduplication)
     * @param  string|null  $pageUrl  URL where the request was made
     */
    public function __construct(
        string $featureDescription,
        ?string $category = null,
        ?string $source = null,
        ?int $voteCount = null,
        ?string $requestId = null,
        ?string $pageUrl = null,
    ){
        parent::__construct(
            name: 'feature_request',
            params: array_filter([
                'feature_description' => $featureDescription,
                'category' => $category,
                'source' => $source,
                'vote_count' => $voteCount,
                'request_id' => $requestId,
                'page_url' => $pageUrl,
            ], fn (mixed $v): bool => $v !== null),
        );
    }
}
