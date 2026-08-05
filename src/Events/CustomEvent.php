<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Generic custom event for arbitrary tracking needs.
 *
 * Use this when none of the typed event classes fit your use case.
 *
 * GA4: custom event with the given name
 * Meta: custom event with the given name
 */
final readonly class CustomEvent extends AnalyticsEvent
{
    /**
     * @param  string  $name  Custom event name (e.g. 'tutorial_completed', 'video_played')
     * @param  array<string, mixed>  $params  Arbitrary event parameters
     */
    public function __construct(
        string $name,
        array $params = [],
    ) {
        parent::__construct($name, $params);
    }
}
