<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user plays or interacts with video content.
 *
 * GA4: video_play (custom)
 * Meta: VideoPlay (custom)
 *
 * Use this to track onboarding video engagement, tutorial completion,
 * and product demo views in SaaS applications.
 *
 * @since 1.0.0
 */
final readonly class VideoPlayEvent extends AnalyticsEvent
{
    /**
     * @param  string  $videoTitle  Title or identifier of the video
     * @param  string|null  $videoProvider  Video hosting provider (e.g. 'youtube', 'vimeo', 'wistia', 'self_hosted')
     * @param  float|null  $duration  Video duration in seconds (optional)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function __construct(
        string $videoTitle,
        ?string $videoProvider = null,
        ?float $duration = null,
        array $extra = [],
    ): void {
        $baseParams = array_filter([
            'video_title' => $videoTitle,
            'video_provider' => $videoProvider,
            'video_duration' => $duration,
        ]);

        parent::__construct('video_play', array_merge($baseParams, $extra));
    }
}
