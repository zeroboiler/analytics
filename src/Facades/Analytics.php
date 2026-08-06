<?php
/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
 */

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
 * @method static void purchase(string $transactionId, float $value, array<int, array<string, mixed>> $items = [], array<string, mixed> $params = [])
 * @method static void identify(string $userId, ?string $clientId = null, array<string, mixed> $traits = [])
 * @method static void screenView(string $screenName, ?string $screenClass = null, array<string, mixed> $params = [])
 * @method static void abTestExposure(string $experimentId, string $variantId, array<string, mixed> $params = [])
 * @method static void notification(string $channel, string $action, ?string $notificationType = null, array<string, mixed> $params = [])
 * @method static void trackAsync(string $eventName, array<string, mixed> $params = [])
 * @method static void setUserProperties(array<string, mixed> $properties, string|null $userId = null)
 * @method static void alias(string $previousId, string $newId)
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
 * @method static void resetIdentity()
 * @method static array{ecommerce: int, saas: int, engagement: int, total: int} eventCatalogSummary()
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
