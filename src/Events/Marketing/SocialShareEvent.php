<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Marketing;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user shares content via a social media channel.
 *
 * GA4: share
 * Meta: Share
 * PostHog: $share
 *
 * @since 121.0.0
 */
final readonly class SocialShareEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $platform  Social platform (e.g. 'twitter', 'linkedin', 'facebook')
     * @param  string|null  $url  Shared URL
     * @param  string|null  $contentType  Type of content shared (e.g. 'blog', 'product', 'infographic')
     */
    public function __construct(
        ?string $platform = null,
        ?string $url = null,
        ?string $contentType = null,
    ){
        parent::__construct('social_share', array_filter([
            'platform' => $platform,
            'url' => $url,
            'content_type' => $contentType,
        ]));
    }
}
