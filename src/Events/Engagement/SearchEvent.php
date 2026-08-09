<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a search query.
 *
 * GA4: search (standard)
 * Meta: Search (standard)
 *
 * @since 1.0.0
 */
final readonly class SearchEvent extends AnalyticsEvent
{
    /**
     * @param  string  $searchTerm  The search query string
     * @param  int|null  $resultsCount  Number of results returned
     * @param  string|null  $category  Search category/context
     */
    public function __construct(
        string $searchTerm = '',
        ?int $resultsCount = null,
        ?string $category = null,
    ): void {
        parent::__construct('search', array_filter([
            'search_term' => $searchTerm,
            'results_count' => $resultsCount,
            'category' => $category,
        ]));
    }
}
