<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Content engagement event for tracking article/video/document depth.
 *
 * Tracks meaningful content consumption: reading percentage, time spent,
 * scroll milestones, and completion status for content-driven SaaS apps.
 */
final readonly class ContentEngagementEvent extends AnalyticsEvent
{
    /**
     * @param  non-empty-string  $contentType  Type of content (article, video, document, podcast)
     * @param  non-empty-string  $contentId  Content identifier or slug
     * @param  string|null  $title  Content title
     * @param  string|null  $author  Content author
     * @param  string|null  $category  Content category or tag
     * @param  int|null  $engagementPercent  How far the user engaged (0-100)
     * @param  int|null  $timeSpentSeconds  Time spent on content in seconds
     * @param  bool|null  $completed  Whether the user reached the end
     */
    public function __construct(
        string $contentType,
        string $contentId,
        ?string $title = null,
        ?string $author = null,
        ?string $category = null,
        ?int $engagementPercent = null,
        ?int $timeSpentSeconds = null,
        ?bool $completed = null,
    ) {
        parent::__construct(
            name: 'content_engagement',
            params: array_filter([
                'content_type' => $contentType,
                'content_id' => $contentId,
                'title' => $title,
                'author' => $author,
                'category' => $category,
                'engagement_percent' => $engagementPercent,
                'time_spent_seconds' => $timeSpentSeconds,
                'completed' => $completed,
            ], fn (mixed $v): bool => $v !== null),
        );
    }
}
