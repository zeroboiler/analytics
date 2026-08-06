<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
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
 * @method static void trackEcommerce(string $eventName, array<string, mixed> $data = [], array<string, mixed> $params = [])
 * @method static void purchase(string $transactionId, float $value, array<int, array<string, mixed>> $items = [], array<string, mixed> $params = [])
 * @method static void identify(string $userId, ?string $clientId = null, array<string, mixed> $traits = [])
 * @method static void screenView(string $screenName, ?string $screenClass = null, array<string, mixed> $params = [])
 * @method static void pageView(string $title = '', string $location = '', string $referrer = '', array<string, mixed> $params = [])
 * @method static void serverSidePageView(string $title = '', string $location = '', string $referrer = '', string|null $clientId = null, string|null $userId = null, array<string, mixed> $params = [])
 * @method static void abTestExposure(string $experimentId, string $variantId, array<string, mixed> $params = [])
 * @method static void notification(string $channel, string $action, ?string $notificationType = null, array<string, mixed> $params = [])
 * @method static void trackAsync(string $eventName, array<string, mixed> $params = [])
 * @method static void setUserProperties(array<string, mixed> $properties, string|null $userId = null)
 * @method static void alias(string $previousId, string $newId)
 * @method static void logout(string|null $method = null, array<string, mixed> $params = [])
 * @method static void trialEnd(string $outcome, string|null $planName = null, array<string, mixed> $params = [])
 * @method static void planDowngrade(string $fromPlan, string $toPlan, array<string, mixed> $params = [])
 * @method static void wishlist(array<string, mixed> $item, array<string, mixed> $params = [])
 * @method static void directDispatch(AnalyticsEvent $event)
 * @method static array{content_ids: list<string>, contents: array<int, array<string, mixed>>, num_items: int} formatEcommerceForMeta(array<int, array<string, mixed>> $items)
 * @method static string headScripts()
 * @method static string bodyScripts()
 * @method static void push(array<string, mixed> $data)
 * @method static GA4Tracker ga4()
 * @method static GTMTracker gtm()
 * @method static MetaPixelTracker meta()
 * @method static PlausibleTracker plausible()
 * @method static PosthogTracker posthog()
 * @method static \ZeroBoiler\Analytics\Trackers\WebhookTracker webhook()
 * @method static void setConsent(ConsentState $state)
 * @method static void grantConsent()
 * @method static void denyConsent()
 * @method static ConsentState getConsent()
 * @method static bool isDebug()
 * @method static bool shouldLogEvents()
 * @method static void setDebug(bool $enabled)
 * @method static void resetIdentity()
 * @method static array{ecommerce: int, saas: int, engagement: int, total: int} eventCatalogSummary()
 * @method static bool eventExists(string $eventName)
 * @method static string|null eventCategory(string $eventName)
 * @method static int totalEventCount()
 * @method static void trackError(string $message, string|null $source = null, int|null $line = null, array<string, mixed> $params = [])
 * @method static void mrr(float $amount, int $subscribers = 0, array<string, mixed> $params = [])
 * @method static bool isTrackingAllowed(string|null $userId = null, string|null $clientId = null)
 * @method static void optOut(string $userId)
 * @method static void optIn(string $userId)
 * @method static void suppressClient(string $clientId)
 * @method static bool transferClientToUser(string $clientId, string $userId)
 * @method static string version()
 * @method static array<string, array{enabled: bool, id?: string}> providerSummary()
 * @method static \ZeroBoiler\Analytics\AnalyticsMetrics metrics()
 *
 * @see \ZeroBoiler\Analytics\AnalyticsManager
 */
class Analytics extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return 'zeroboiler.analytics';
    }
}
