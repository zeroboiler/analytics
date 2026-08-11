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
 * @method static void selectItem(array<int, array<string, mixed>> $items = [], string|null $itemListId = null, string|null $itemListName = null, array<string, mixed> $params = [])
 * @method static void selectPromotion(string|null $promotionId = null, string|null $promotionName = null, string|null $creativeName = null, string|null $creativeSlot = null, array<string, mixed> $params = [])
 * @method static void viewPromotion(string|null $promotionId = null, string|null $promotionName = null, string|null $creativeName = null, string|null $creativeSlot = null, array<string, mixed> $params = [])
 * @method static void subscriptionRenewal(string|null $planName = null, float|null $amount = null, string $currency = 'USD', string|null $billingCycle = null, array<string, mixed> $params = [])
 * @method static bool directDispatch(AnalyticsEvent $event)
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
 * @method static \ZeroBoiler\Analytics\Trackers\MixpanelTracker mixpanel()
 * @method static \ZeroBoiler\Analytics\Trackers\AmplitudeTracker amplitude()
 * @method static void trackSaaSIdentity(string $userId, string $clientId, array<string, mixed> $traits = [])
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
 * @method static void interceptBefore(callable $interceptor)
 * @method static void interceptAfter(callable $interceptor)
 * @method static \ZeroBoiler\Analytics\EventInterceptorRegistry interceptors()
 * @method static array{event_counts: array<string, int>, total_events: int, total_value: float, first_seen: string|null, last_seen: string|null, funnel_steps: array<string, bool>, engagement_score: float, plan: string|null, traits: array<string, mixed>} getProfile(string $userId)
 * @method static array{user_id: string, total_events: int, lifetime_value: float, first_seen: string|null, last_seen: string|null, engagement_score: float, plan: string|null, event_types: int, funnel_steps_completed: int, traits: array<string, mixed>} getProfileSummary(string $userId)
 * @method static void inviteSent(string $inviteType, string|null $role = null, array<string, mixed> $params = [])
 * @method static void integrationConnected(string $integrationName, array<string, mixed> $params = [])
 * @method static void fileDownload(string $fileName, string|null $fileType = null, array<string, mixed> $params = [])
 * @method static void videoPlay(string $videoTitle, string|null $videoProvider = null, array<string, mixed> $params = [])
 * @method static array{valid: bool, errors: list<string>, warnings: list<string>} validateCatalog()
 * @method static string version()
 * @method static array<string, array{enabled: bool, id?: string}> providerSummary()
 * @method static \ZeroBoiler\Analytics\AnalyticsMetrics metrics()
 * @method static array{events: int, dispatched: int, failed: int, success_rate: float, top_event: string|null} reportSummary()
 * @method static array{enabled: bool, strategy: string, total: int, buffered: int, max_size: int, storage_path: string, utilization: float} dlqSummary()
 * @method static string resolveEventName(string $name)
 * @method static void trackWithAlias(string $name, array<string, mixed> $params = [])
 * @method static void featureAdopted(string $featureName, ?string $category = null, array<string, mixed> $params = [])
 * @method static void expansionRevenue(float $amount, string $source, ?string $currency = null)
 * @method static void exportEvent(string $format, ?string $resource = null, ?int $recordCount = null, array<string, mixed> $params = [])
 * @method static void importEvent(string $format, ?string $resource = null, ?int $recordCount = null, ?bool $success = null, array<string, mixed> $params = [])
 * @method static void trackFunnel(string $funnelName, string $stepName, ?int $stepNumber = null, ?int $totalSteps = null, array<string, mixed> $params = [])
 * @method static array{funnel_name: string, step_name: string, step_number: int, total_steps: int, completion_pct: float, is_complete: bool, is_advancement: bool, is_regression: bool, elapsed_seconds: float|null, previous_step: string|null, previous_step_number: int|null, first_seen: string|null, last_updated: string} funnelProgress(string $funnelName, string $stepName, string $identity, int $stepNumber, int $totalSteps, array<string, mixed> $params = [])
 * @method static array{events: list<array<string, mixed>>, count: int, categories: array<string, int>, funnel_coverage: array<string, bool>} quickStartEvents()
 * @method static list<array{name: string, class: class-string, ga4: string, category: string}> plgEvents()
 * @method static void signUp(?string $method = null, array<string, mixed> $params = [])
 * @method static void login(string $userId, ?string $clientId = null, ?string $method = null, array<string, mixed> $params = [])
 * @method static void trialStart(?string $planName = null, ?int $trialDays = null, array<string, mixed> $params = [])
 * @method static void subscription(?string $planName = null, ?float $amount = null, string $currency = 'USD', ?string $billingCycle = null, array<string, mixed> $params = [])
 * @method static void planUpgrade(string $fromPlan, string $toPlan, ?float $priceDifference = null, array<string, mixed> $params = [])
 * @method static void cancellation(?string $planName = null, ?string $reason = null, array<string, mixed> $params = [])
 * @method static void trackSaaSAcquisition(?string $planName = null, ?float $amount = null, string $currency = 'USD', array<string, mixed> $options = [], array<string, mixed> $params = [])
 * @method static array{status: string, version: string, overall_score: int, timestamp: string, subsystems: array<string, mixed>, recommendations: list<array{priority: string, category: string, message: string}>} healthCheck()
 * @method static array{status: string, version: string, providers_configured: int, catalog_size: int} ping()
 * @method static array{score: int, grade: string, details: array{critical_events: array{present: int, total: int, score: int, max_score: int, missing: list<string>}, aarr_categories: int, providers: int, catalog_size: int}} maturityScore()
 * @method static array{checklist: array<string, list<array{event: string, tracked: bool, priority: string}>>, summary: array{total: int, tracked: int, completion: float, gaps: list<string>}} onboardingChecklist()
 * @method static array{signup_funnel: array{steps: int, present: int, missing: list<string>, score: float}, purchase_funnel: array{steps: int, present: int, missing: list<string>, score: float}, subscription_funnel: array{steps: int, present: int, missing: list<string>, score: float}, overall: float} funnelReadiness()
 * @method static array{pipeline: string, status: string, started_at: string, steps: int, completed_steps: int, identity: string} orchestrate(string $pipelineName, string $clientId, ?string $userId = null, array $params = [])
 * @method static array{step: string, event: string, pipeline_status: string, completed_steps: list<string>, remaining_steps: int, is_complete: bool} orchestrateAdvance(string $pipelineName, string $stepName, string $clientId, ?string $userId = null, array $params = [])
 * @method static float orchestrateProgress(string $pipelineName, string $clientId, ?string $userId = null)
 * @method static array{generated_at: string, insights: list<array{type: string, category: string, title: string, description: string, severity: string, metric: string|null, value: mixed|null, recommendation: string|null}>, summary: array{total: int, by_type: array<string, int>, by_severity: array<string, int>}} insightReport()
 * @method static array{score: float, grade: string, activation: float, engagement: float, retention: float, feature_breadth: float, segment: string, signals: list<string>, identity: string, computed_at: string} plgScore(string $identity)
 * @method static array{avg_score: float, total_cached: int, grade_distribution: array<string, int>} plgAggregate()
 * @method static void plgInvalidate(string $identity)
 * @method static array{total_events: int, unique_identities: int, top_events: list<array{event: string, count: int}>, category_breakdown: array<string, int>, trend: array{direction: string, change_pct: float, current: int, previous: int}, moving_avg: float, period: string, computed_at: string} timeSeries(string $period = '1h')
 * @method static array<string, array{total_events: int, unique_identities: int, top_events: list<array{event: string, count: int}>, category_breakdown: array<string, int>, trend: array, moving_avg: float, period: string}> timeSeriesDashboard()
 * @method static array{current: array, previous: array, delta: array{events: int, identities: int, pct_change: float}} timeSeriesCompare(string $currentPeriod, string $previousPeriod)
 * @method static void group(string $groupId, array<string, mixed> $traits = [], array<string, mixed> $params = [])
 * @method static void groupAddMember(string $userId, string $groupId, ?string $role = null, array<string, mixed> $traits = [])
 * @method static array{group_id: string, traits: array<string, mixed>, member_count: int, updated_at: string|null} getGroup(string $groupId)
 *
 * Testing assertions (when swapped with AnalyticsFake):
 * @method static void assertTracked(string $eventName, ?callable $callback = null)
 * @method static void assertNotTracked(string $eventName)
 * @method static void assertTrackedTimes(string $eventName, int $times)
 * @method static void assertNothingTracked()
 * @method static void assertIdentified(string $userId, ?callable $callback = null)
 * @method static void assertPageViewTracked(?callable $callback = null)
 *
 * @see \ZeroBoiler\Analytics\AnalyticsManager
 * @see \ZeroBoiler\Analytics\Support\AnalyticsFake
 *
 * @since 1.0.0
 */
final class Analytics extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return 'zeroboiler.analytics';
    }
}
