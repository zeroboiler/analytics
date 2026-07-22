<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void track(string $eventName, array<string, mixed> $params = [])
 * @method static void trackEvent(\ZeroBoiler\Analytics\DTO\AnalyticsEvent $event)
 * @method static string headScripts()
 * @method static string bodyScripts()
 * @method static void push(array<string, mixed> $data)
 * @method static \ZeroBoiler\Analytics\Trackers\GA4Tracker ga4()
 * @method static \ZeroBoiler\Analytics\Trackers\GTMTracker gtm()
 * @method static \ZeroBoiler\Analytics\Trackers\MetaPixelTracker meta()
 */
class Analytics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'zeroboiler.analytics';
    }
}
