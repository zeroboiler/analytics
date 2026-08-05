<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Facades;

use Illuminate\Support\Facades\Facade;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;

/**
 * @method static void track(string $eventName, array<string, mixed> $params = [])
 * @method static void trackEvent(AnalyticsEvent $event)
 * @method static string headScripts()
 * @method static string bodyScripts()
 * @method static void push(array<string, mixed> $data)
 * @method static GA4Tracker ga4()
 * @method static GTMTracker gtm()
 * @method static MetaPixelTracker meta()
 * @method static PlausibleTracker plausible()
 * @method static PosthogTracker posthog()
 * @method static void setConsent(ConsentState $state)
 * @method static void grantConsent()
 * @method static void denyConsent()
 * @method static ConsentState getConsent()
 * @method static bool isDebug()
 * @method static bool shouldLogEvents()
 * @method static void setDebug(bool $enabled)
 *
 * @see \ZeroBoiler\Analytics\AnalyticsManager
 */
class Analytics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'zeroboiler.analytics';
    }
}
