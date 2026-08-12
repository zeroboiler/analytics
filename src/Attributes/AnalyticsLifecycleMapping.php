<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Attributes;

use Attribute;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Attribute for mapping Laravel framework/application events to analytics events.
 *
 * Apply to methods that are event listeners to declare which analytics event
 * they should produce, with parameter extraction logic.
 *
 * Complements the config-driven LifecycleEventMapper with code-first mapping.
 *
 * @since 19.0.0
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class AnalyticsLifecycleMapping
{
    /**
     * @param  string  $source  Application event name (e.g. 'Illuminate\Auth\Events\Login')
     * @param  class-string<AnalyticsEvent>  $target  Analytics event class to dispatch
     * @param  string|null  $paramsExtractor  Method name for extracting params from the source event
     * @param  int  $priority  Dispatch priority (higher = dispatched first)
     * @param  string|null  $condition  Expression to evaluate before dispatch (optional)
     */
    public function __construct(
        public string $source,
        public string $target,
        public ?string $paramsExtractor = null,
        public int $priority = 80,
        public ?string $condition = null,
    ) {}
}
