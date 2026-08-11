<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\EventInterceptorRegistry;
use ZeroBoiler\Analytics\Trackers\AmplitudeTracker;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;
use ZeroBoiler\Analytics\Trackers\MixpanelTracker;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;
use ZeroBoiler\Analytics\Trackers\WebhookTracker;

/**
 * Analytics test fake — complete drop-in replacement for AnalyticsManager.
 *
 * Intercepts all dispatched analytics events and provides fluent assertion API
 * modeled after Laravel's MailFake, NotificationFake, and BusFake.
 *
 * Every public method on AnalyticsManager has a corresponding no-op or
 * tracking implementation in this fake, so code using the Analytics facade
 * or resolved manager works identically in tests without real dispatch.
 *
 * Usage in Pest tests:
 *   use ZeroBoiler\Analytics\Facades\Analytics;
 *   use ZeroBoiler\Analytics\Support\AnalyticsFake;
 *
 *   beforeEach(function () {
 *       app()->instance('zeroboiler.analytics', new AnalyticsFake);
 *       // Or use the WithAnalyticsFake trait:
 *       // $this->withAnalyticsFake();
 *   });
 *
 *   test('signup event is tracked', function () {
 *       app('zeroboiler.analytics')->track('sign_up', ['method' => 'email']);
 *       AnalyticsFake::assertTracked('sign_up');
 *   });
 *
 * Or with the Facade helper:
 *   beforeEach(function () {
 *       Analytics::swap(new AnalyticsFake);
 *   });
 *
 *   test('purchase is tracked', function () {
 *       app('zeroboiler.analytics')->track('purchase', ['value' => 99.99]);
 *       AnalyticsFake::assertTracked('purchase');
 *   });
 *
 * @since 10.4.0
 * @version 16.0.0 Full AnalyticsManager proxy coverage
 */
final class AnalyticsFake
{
    /**
     * All dispatched analytics events, in dispatch order.
     *
     * @var list<AnalyticsEvent>
     */
    private array $events = [];

    /**
     * Identity calls captured for assertion.
     *
     * @var list<array{userId: string, clientId: string|null, traits: array<string, mixed>}>
     */
    private array $identifyCalls = [];

    /**
     * Consent state history.
     *
     * @var list<ConsentState>
     */
    private array $consentHistory = [];

    /**
     * Page view events captured separately for specific assertions.
     *
     * @var list<AnalyticsEvent>
     */
    private array $pageViews = [];

    /**
     * E-commerce events captured separately.
     *
     * @var list<array{eventName: string, data: array<string, mixed>, params: array<string, mixed>}>
     */
    private array $ecommerceCalls = [];

    /**
     * Funnel progress results captured for assertion.
     *
     * @var list<array{funnelName: string, stepName: string, identity: string, stepNumber: int, totalSteps: int}>
     */
    private array $funnelProgressCalls = [];

    /**
     * SaaS identity calls captured for assertion.
     *
     * @var list<array{userId: string, clientId: string, traits: array<string, mixed>}>
     */
    private array $saasIdentityCalls = [];

    /**
     * Debug mode state.
     */
    private bool $debugMode = false;

    /**
     * Metrics instance for tracking dispatch counts.
     */
    private AnalyticsMetrics $metrics;

    /**
     * Interceptor registry.
     */
    private EventInterceptorRegistry $interceptors;

    /**
     * Tracker stubs (return instances for ga4(), gtm(), meta(), etc.).
     */
    private GA4Tracker $ga4;
    private GTMTracker $gtm;
    private MetaPixelTracker $meta;
    private PlausibleTracker $plausible;
    private PosthogTracker $posthog;
    private WebhookTracker $webhook;
    private MixpanelTracker $mixpanel;
    private AmplitudeTracker $amplitude;

    public function __construct(): void
    {
        $this->metrics = new AnalyticsMetrics;
        $this->interceptors = new EventInterceptorRegistry;

        // Create tracker stubs with disabled configs so they don't make HTTP calls
        $this->ga4 = new GA4Tracker('', '', false);
        $this->gtm = new GTMTracker('', false);
        $this->meta = new MetaPixelTracker('', '', false);
        $this->plausible = new PlausibleTracker('', '', '', false);
        $this->posthog = new PosthogTracker('', '', '', false);
        $this->webhook = new WebhookTracker('', '', false);
        $this->mixpanel = new MixpanelTracker('', '', false);
        $this->amplitude = new AmplitudeTracker('', '', '', false);
    }

    // ─── Core Tracking ────────────────────────────────────────────────

    /**
     * Track an analytics event (intercepts the call).
     */
    public function track(string $eventName, array $params = []): void
    {
        $this->trackEvent(new AnalyticsEvent($eventName, $params));
    }

    /**
     * Track an analytics event from a DTO object (intercepts the call).
     */
    public function trackEvent(AnalyticsEvent $event): void
    {
        // Run before interceptors
        $filtered = $this->interceptors->runBefore($event);
        if ($filtered === null) {
            return;
        }

        $this->events[] = $filtered;

        if ($filtered->name === 'page_view') {
            $this->pageViews[] = $filtered;
        }

        $this->metrics->recordDispatch('fake');

        // Run after interceptors
        $this->interceptors->runAfter($filtered, true);
    }

    /**
     * Direct dispatch (bypass interceptors in fake).
     */
    public function directDispatch(AnalyticsEvent $event): bool
    {
        $this->trackEvent($event);

        return true;
    }

    // ─── E-Commerce ───────────────────────────────────────────────────

    /**
     * Track an e-commerce analytics event (intercepts the call).
     */
    public function trackEcommerce(string $eventName, array $data = [], array $params = []): void
    {
        $this->ecommerceCalls[] = [
            'eventName' => $eventName,
            'data' => $data,
            'params' => $params,
        ];

        $this->track($eventName, array_merge($data, $params));
    }

    /**
     * Format e-commerce items for Meta Pixel (no-op — returns formatted structure).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{content_ids: list<string>, contents: array<int, array<string, mixed>>, num_items: int}
     */
    public function formatEcommerceForMeta(array $items): array
    {
        $contentIds = [];
        $contents = [];

        foreach ($items as $item) {
            $contentIds[] = (string) ($item['item_id'] ?? '');
            $contents[] = [
                'id' => (string) ($item['item_id'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'item_price' => (float) ($item['price'] ?? 0),
                'name' => (string) ($item['item_name'] ?? ''),
                'category' => (string) ($item['item_category'] ?? ''),
            ];
        }

        return [
            'content_ids' => $contentIds,
            'contents' => $contents,
            'num_items' => array_sum(array_column($contents, 'quantity')),
        ];
    }

    // ─── Identity ─────────────────────────────────────────────────────

    /**
     * Identify a user (intercepts the call).
     */
    public function identify(string $userId, ?string $clientId = null, array $traits = []): void
    {
        $this->identifyCalls[] = [
            'userId' => $userId,
            'clientId' => $clientId,
            'traits' => $traits,
        ];
    }

    /**
     * Track SaaS identity linking event.
     */
    public function trackSaaSIdentity(string $userId, string $clientId, array $traits = []): void
    {
        $this->saasIdentityCalls[] = [
            'userId' => $userId,
            'clientId' => $clientId,
            'traits' => $traits,
        ];

        $this->track('identify', array_merge([
            'user_id' => $userId,
            'client_id' => $clientId,
            'identity_type' => 'saas_auto_link',
        ], $traits));

        if (! empty($traits)) {
            $this->setUserProperties($traits, $userId);
        }
    }

    /**
     * Alias one user identity to another.
     */
    public function alias(string $previousId, string $newId): void
    {
        $this->track('alias', [
            'previous_id' => $previousId,
            'new_id' => $newId,
        ]);
    }

    /**
     * Set user properties / traits.
     */
    public function setUserProperties(array $properties, ?string $userId = null): void
    {
        $params = $properties;
        if ($userId !== null) {
            $params['user_id'] = $userId;
        }

        $this->track('set_user_properties', $params);
    }

    /**
     * Reset user identity (GDPR).
     */
    public function resetIdentity(): void
    {
        // No-op in fake
    }

    // ─── Page Views ───────────────────────────────────────────────────

    /**
     * Track a page view event.
     */
    public function pageView(string $title = '', string $location = '', string $referrer = '', array $params = []): void
    {
        $this->track('page_view', array_filter(array_merge([
            'page_title' => $title,
            'page_location' => $location,
            'page_referrer' => $referrer,
        ], $params)));
    }

    /**
     * Track a server-side page view with identity.
     */
    public function serverSidePageView(
        string $title = '',
        string $location = '',
        string $referrer = '',
        ?string $clientId = null,
        ?string $userId = null,
        array $params = [],
    ): void {
        $event = new AnalyticsEvent(
            name: 'page_view',
            params: array_filter(array_merge([
                'page_title' => $title,
                'page_location' => $location,
                'page_referrer' => $referrer,
            ], $params)),
            clientId: $clientId,
            userId: $userId,
        );

        $this->trackEvent($event);
    }

    /**
     * Track a screen view event.
     */
    public function screenView(string $screenName, ?string $screenClass = null, array $params = []): void
    {
        $this->track('screen_view', array_merge([
            'screen_name' => $screenName,
            'screen_class' => $screenClass,
        ], $params));
    }

    // ─── Consent ──────────────────────────────────────────────────────

    /**
     * Set consent state (intercepts the call).
     */
    public function setConsent(ConsentState $state): void
    {
        $this->consentHistory[] = $state;
    }

    /**
     * Get current consent state.
     */
    public function getConsent(): ConsentState
    {
        if ($this->consentHistory === []) {
            return ConsentState::granted();
        }

        return $this->consentHistory[array_key_last($this->consentHistory)]
            ?? ConsentState::granted();
    }

    /**
     * Grant all consent (shortcut).
     */
    public function grantConsent(): void
    {
        $this->setConsent(ConsentState::granted());
    }

    /**
     * Deny all consent (shortcut).
     */
    public function denyConsent(): void
    {
        $this->setConsent(ConsentState::denied());
    }

    // ─── SaaS Lifecycle ───────────────────────────────────────────────

    /** @param array<string, mixed> $params */
    public function signUp(?string $method = null, array $params = []): void
    {
        $this->track('sign_up', array_filter(array_merge(['method' => $method], $params)));
    }

    /** @param array<string, mixed> $params */
    public function login(string $userId, ?string $clientId = null, ?string $method = null, array $params = []): void
    {
        $this->track('login', array_filter(array_merge(['user_id' => $userId, 'method' => $method], $params)));

        if ($clientId !== null && $clientId !== '') {
            $this->identify($userId, $clientId);
        }
    }

    public function logout(?string $method = null, array $params = []): void
    {
        $this->track('logout', array_filter(array_merge(['method' => $method], $params)));
    }

    /** @param array<string, mixed> $params */
    public function trialStart(?string $planName = null, ?int $trialDays = null, array $params = []): void
    {
        $this->track('start_trial', array_filter(array_merge(['plan_name' => $planName, 'trial_days' => $trialDays], $params)));
    }

    public function trialEnd(string $outcome, ?string $planName = null, array $params = []): void
    {
        $this->track('trial_end', array_filter(array_merge(['outcome' => $outcome, 'plan_name' => $planName], $params)));
    }

    public function trialConverted(?string $planName = null, ?float $amount = null, string $currency = 'USD', array $params = []): void
    {
        $this->track('trial_converted', array_filter(array_merge(['plan_name' => $planName, 'amount' => $amount, 'currency' => $currency], $params)));
    }

    /** @param array<string, mixed> $params */
    public function subscription(?string $planName = null, ?float $amount = null, string $currency = 'USD', ?string $billingCycle = null, array $params = []): void
    {
        $this->track('subscribe', array_filter(array_merge(['plan_name' => $planName, 'amount' => $amount, 'currency' => $currency, 'billing_cycle' => $billingCycle], $params)));
    }

    public function subscriptionRenewal(?string $planName = null, ?float $amount = null, string $currency = 'USD', ?string $billingCycle = null, array $params = []): void
    {
        $this->track('subscription_renewal', array_filter(array_merge(['plan_name' => $planName, 'amount' => $amount, 'currency' => $currency, 'billing_cycle' => $billingCycle], $params)));
    }

    /** @param array<string, mixed> $params */
    public function planUpgrade(string $fromPlan, string $toPlan, ?float $priceDifference = null, array $params = []): void
    {
        $this->track('plan_upgrade', array_filter(array_merge(['from_plan' => $fromPlan, 'to_plan' => $toPlan, 'price_difference' => $priceDifference], $params)));
    }

    /** @param array<string, mixed> $params */
    public function planDowngrade(string $fromPlan, string $toPlan, array $params = []): void
    {
        $this->track('plan_downgrade', array_merge(['from_plan' => $fromPlan, 'to_plan' => $toPlan], $params));
    }

    /** @param array<string, mixed> $params */
    public function cancellation(?string $planName = null, ?string $reason = null, array $params = []): void
    {
        $this->track('cancellation', array_filter(array_merge(['plan_name' => $planName, 'reason' => $reason], $params)));
    }

    // ─── E-Commerce Convenience ────────────────────────────────────────

    public function purchase(string $transactionId, float $value, array $items = [], array $params = []): void
    {
        $this->track('purchase', array_merge([
            'transaction_id' => $transactionId,
            'value' => $value,
            'items' => $items,
        ], $params));
    }

    /** @param array<string, mixed> $item @param array<string, mixed> $params */
    public function wishlist(array $item, array $params = []): void
    {
        $this->track('add_to_wishlist', array_merge([
            'item_id' => $item['item_id'] ?? '',
            'item_name' => $item['item_name'] ?? null,
            'price' => $item['price'] ?? null,
            'currency' => $item['currency'] ?? 'USD',
        ], $params));
    }

    /** @param array<int, array<string, mixed>> $items @param array<string, mixed> $params */
    public function selectItem(array $items = [], ?string $itemListId = null, ?string $itemListName = null, array $params = []): void
    {
        $this->track('select_item', array_merge(['item_list_id' => $itemListId, 'item_list_name' => $itemListName, 'items' => $items], $params));
    }

    public function selectPromotion(?string $promotionId = null, ?string $promotionName = null, ?string $creativeName = null, ?string $creativeSlot = null, array $params = []): void
    {
        $this->track('select_promotion', array_filter(array_merge(['promotion_id' => $promotionId, 'promotion_name' => $promotionName, 'creative_name' => $creativeName, 'creative_slot' => $creativeSlot], $params)));
    }

    public function viewPromotion(?string $promotionId = null, ?string $promotionName = null, ?string $creativeName = null, ?string $creativeSlot = null, array $params = []): void
    {
        $this->track('view_promotion', array_filter(array_merge(['promotion_id' => $promotionId, 'promotion_name' => $promotionName, 'creative_name' => $creativeName, 'creative_slot' => $creativeSlot], $params)));
    }

    // ─── Engagement Convenience ────────────────────────────────────────

    public function trackError(string $message, ?string $source = null, ?int $line = null, array $params = []): void
    {
        $this->track('error', array_filter(array_merge(['error_message' => $message, 'error_source' => $source, 'error_line' => $line], $params)));
    }

    public function abTestExposure(string $experimentId, string $variantId, array $params = []): void
    {
        $this->track('ab_test_exposure', array_merge(['experiment_id' => $experimentId, 'variant_id' => $variantId], $params));
    }

    public function abTestConversion(string $experimentId, string $variantId, string $goalName, array $params = []): void
    {
        $this->track('ab_test_conversion', array_merge(['experiment_id' => $experimentId, 'variant_id' => $variantId, 'goal_name' => $goalName], $params));
    }

    public function addToWishlist(array $item, array $params = []): void
    {
        $this->track('add_to_wishlist', array_merge(['currency' => $item['currency'] ?? 'USD', 'value' => (float) ($item['price'] ?? 0), 'items' => [$item]], $params));
    }

    public function promotionView(string $promotionId, string $promotionName, ?string $creativeName = null, ?string $creativeSlot = null, array $params = []): void
    {
        $this->track('view_promotion', array_filter(array_merge(['promotion_id' => $promotionId, 'promotion_name' => $promotionName, 'creative_name' => $creativeName, 'creative_slot' => $creativeSlot], $params)));
    }

    public function trackMrr(float $amount, string $movementType, ?string $planName = null, ?string $userId = null, array $params = []): void
    {
        $this->track('mrr_movement', array_filter(array_merge(['amount' => $amount, 'currency' => $params['currency'] ?? 'USD', 'movement_type' => $movementType, 'plan_name' => $planName, 'user_id' => $userId], $params)));
    }

    public function trackArr(float $arr, ?int $customerCount = null, array $params = []): void
    {
        $this->track('arr_snapshot', array_filter(array_merge(['arr' => $arr, 'currency' => $params['currency'] ?? 'USD', 'customer_count' => $customerCount], $params)));
    }

    public function trackChurn(?string $userId = null, ?string $planName = null, ?float $lostMrr = null, ?string $reason = null, array $params = []): void
    {
        $this->track('churn', array_filter(array_merge(['user_id' => $userId, 'plan_name' => $planName, 'lost_mrr' => $lostMrr, 'currency' => $params['currency'] ?? 'USD', 'reason' => $reason], $params)));
    }

    public function trackLtv(float $ltv, ?string $userId = null, ?string $planName = null, ?string $trigger = null, array $params = []): void
    {
        $this->track('ltv_calculated', array_filter(array_merge(['ltv' => $ltv, 'currency' => $params['currency'] ?? 'USD', 'user_id' => $userId, 'plan_name' => $planName, 'trigger' => $trigger], $params)));
    }

    public function registerAliases(array $aliases): void
    {
        // No-op in fake — aliasing is captured via track()
    }

    public function resolveAlias(string $name): string
    {
        return $name;
    }

    public function getAliases(): array
    {
        return [];
    }

    public function notification(string $channel, string $action, ?string $notificationType = null, array $params = []): void
    {
        $this->track('notification', array_filter(array_merge(['notification_channel' => $channel, 'notification_action' => $action, 'notification_type' => $notificationType], $params)));
    }

    /** @param array<string, mixed> $params */
    public function fileDownload(string $fileName, ?string $fileType = null, array $params = []): void
    {
        $this->track('file_download', array_filter(array_merge(['file_name' => $fileName, 'file_type' => $fileType], $params)));
    }

    public function videoPlay(string $videoTitle, ?string $videoProvider = null, array $params = []): void
    {
        $this->track('video_play', array_filter(array_merge(['video_title' => $videoTitle, 'video_provider' => $videoProvider], $params)));
    }

    /** @param array<string, mixed> $params */
    public function inviteSent(string $inviteType, ?string $role = null, array $params = []): void
    {
        $this->track('invite_sent', array_filter(array_merge(['invite_type' => $inviteType, 'role' => $role], $params)));
    }

    /** @param array<string, mixed> $params */
    public function integrationConnected(string $integrationName, array $params = []): void
    {
        $this->track('integration_connected', array_merge(['integration_name' => $integrationName], $params));
    }

    // ─── Revenue ───────────────────────────────────────────────────────

    public function mrr(float $amount, int $subscribers = 0, array $params = []): void
    {
        $this->track('revenue_tracked', array_merge(['amount' => $amount, 'revenue_type' => 'mrr', 'subscriber_count' => $subscribers], $params));
    }

    // ─── PLG & Feature Adoption ────────────────────────────────────────

    /** @param array<string, mixed> $params */
    public function featureAdopted(string $featureName, ?string $category = null, array $params = []): void
    {
        $this->track('feature_adopted', array_filter(array_merge(['feature_name' => $featureName, 'category' => $category], $params)));
    }

    public function expansionRevenue(float $amount, string $source, ?string $currency = null): void
    {
        $this->track('expansion_revenue', array_filter(['amount' => $amount, 'source' => $source, 'currency' => $currency]));
    }

    // ─── Import/Export ─────────────────────────────────────────────────

    /** @param array<string, mixed> $params */
    public function exportEvent(string $format, ?string $resource = null, ?int $recordCount = null, array $params = []): void
    {
        $this->track('export', array_filter(array_merge(['format' => $format, 'resource' => $resource, 'record_count' => $recordCount], $params)));
    }

    /** @param array<string, mixed> $params */
    public function importEvent(string $format, ?string $resource = null, ?int $recordCount = null, ?bool $success = null, array $params = []): void
    {
        $this->track('import', array_filter(array_merge(['format' => $format, 'resource' => $resource, 'record_count' => $recordCount, 'success' => $success], $params)));
    }

    // ─── Funnel Tracking ───────────────────────────────────────────────

    /** @param array<string, mixed> $params */
    public function trackFunnel(string $funnelName, string $stepName, ?int $stepNumber = null, ?int $totalSteps = null, array $params = []): void
    {
        $merged = array_merge([
            'funnel_name' => $funnelName,
            'step_name' => $stepName,
            'step_number' => $stepNumber,
            'total_steps' => $totalSteps,
        ], $params);

        $this->track('funnel_step', array_filter(
            $merged,
            fn (mixed $v): bool => $v !== null && $v !== '',
        ));
    }

    /**
     * @param array<string, mixed> $params
     * @return array{funnel_name: string, step_name: string, step_number: int, total_steps: int, completion_pct: float, is_complete: bool, is_advancement: bool, is_regression: bool, elapsed_seconds: float|null, previous_step: string|null, previous_step_number: int|null, first_seen: string|null, last_updated: string}
     */
    public function funnelProgress(string $funnelName, string $stepName, string $identity, int $stepNumber, int $totalSteps, array $params = []): array
    {
        $this->funnelProgressCalls[] = [
            'funnelName' => $funnelName,
            'stepName' => $stepName,
            'identity' => $identity,
            'stepNumber' => $stepNumber,
            'totalSteps' => $totalSteps,
        ];

        $this->track('funnel_step', array_filter([
            'funnel_name' => $funnelName,
            'step_name' => $stepName,
            'step_number' => $stepNumber,
            'total_steps' => $totalSteps,
        ] + $params, fn (mixed $v): bool => $v !== null && $v !== ''));

        return [
            'funnel_name' => $funnelName,
            'step_name' => $stepName,
            'step_number' => $stepNumber,
            'total_steps' => $totalSteps,
            'completion_pct' => $totalSteps > 0 ? round(($stepNumber / $totalSteps) * 100, 1) : 0.0,
            'is_complete' => $stepNumber >= $totalSteps,
            'is_advancement' => true,
            'is_regression' => false,
            'elapsed_seconds' => null,
            'previous_step' => null,
            'previous_step_number' => null,
            'first_seen' => null,
            'last_updated' => (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
        ];
    }

    // ─── Async Dispatch ────────────────────────────────────────────────

    /** @param array<string, mixed> $params */
    public function trackAsync(string $eventName, array $params = []): void
    {
        $this->track($eventName, $params);
    }

    // ─── Tracking Preferences ───────────────────────────────────────────

    public function isTrackingAllowed(?string $userId = null, ?string $clientId = null): bool
    {
        return $this->getConsent()->hasAnalyticsConsent();
    }

    public function optOut(string $userId): void
    {
        // No-op in fake
    }

    public function optIn(string $userId): void
    {
        // No-op in fake
    }

    public function suppressClient(string $clientId): void
    {
        // No-op in fake
    }

    public function transferClientToUser(string $clientId, string $userId): bool
    {
        return false;
    }

    // ─── Debug & Metrics ─────────────────────────────────────────────

    public function isDebug(): bool
    {
        return $this->debugMode;
    }

    public function shouldLogEvents(): bool
    {
        return false;
    }

    public function setDebug(bool $enabled): void
    {
        $this->debugMode = $enabled;
    }

    public function metrics(): AnalyticsMetrics
    {
        return $this->metrics;
    }

    /**
     * @return array{dispatched: int, failed: int, by_provider: array<string, int>}
     */
    public function flushMetrics(): array
    {
        $snapshot = [
            'dispatched' => $this->metrics->totalDispatched(),
            'failed' => $this->metrics->totalFailed(),
            'by_provider' => $this->metrics->dispatchedByProvider(),
        ];

        $this->metrics->flush();

        return $snapshot;
    }

    // ─── Interceptors ──────────────────────────────────────────────────

    /**
     * @param callable(AnalyticsEvent): AnalyticsEvent|null $interceptor
     */
    public function interceptBefore(callable $interceptor): void
    {
        $this->interceptors->before($interceptor);
    }

    /**
     * @param callable(AnalyticsEvent, bool): void $interceptor
     */
    public function interceptAfter(callable $interceptor): void
    {
        $this->interceptors->after($interceptor);
    }

    public function interceptors(): EventInterceptorRegistry
    {
        return $this->interceptors;
    }

    // ─── Tracker Accessors ────────────────────────────────────────────

    public function ga4(): GA4Tracker
    {
        return $this->ga4;
    }

    public function gtm(): GTMTracker
    {
        return $this->gtm;
    }

    public function meta(): MetaPixelTracker
    {
        return $this->meta;
    }

    public function plausible(): PlausibleTracker
    {
        return $this->plausible;
    }

    public function posthog(): PosthogTracker
    {
        return $this->posthog;
    }

    public function webhook(): WebhookTracker
    {
        return $this->webhook;
    }

    public function mixpanel(): MixpanelTracker
    {
        return $this->mixpanel;
    }

    public function amplitude(): AmplitudeTracker
    {
        return $this->amplitude;
    }

    // ─── Script Generation ────────────────────────────────────────────

    public function headScripts(): string
    {
        return '';
    }

    public function bodyScripts(): string
    {
        return '';
    }

    // ─── Data Layer ───────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    public function push(array $data): void
    {
        // No-op in fake
    }

    // ─── Catalog Queries ──────────────────────────────────────────────

    /**
     * @return array{ecommerce: int, saas: int, engagement: int, total: int}
     */
    public function eventCatalogSummary(): array
    {
        $ecommerce = \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::count();
        $saas = \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::count();
        $engagement = \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::count();

        return [
            'ecommerce' => $ecommerce,
            'saas' => $saas,
            'engagement' => $engagement,
            'total' => $ecommerce + $saas + $engagement,
        ];
    }

    public function eventExists(string $eventName): bool
    {
        return \ZeroBoiler\Analytics\Events\EventCatalog::has($eventName);
    }

    public function eventCategory(string $eventName): ?string
    {
        $entry = \ZeroBoiler\Analytics\Events\EventCatalog::get($eventName);

        return $entry['category'] ?? null;
    }

    public function totalEventCount(): int
    {
        return \ZeroBoiler\Analytics\Events\EventCatalog::count();
    }

    public function validateCatalog(): array
    {
        return \ZeroBoiler\Analytics\Events\EventCatalog::validate();
    }

    public function resolveEventName(string $name): string
    {
        return $name;
    }

    /** @param array<string, mixed> $params */
    public function trackWithAlias(string $name, array $params = []): void
    {
        $this->track($name, $params);
    }

    public function version(): string
    {
        return AnalyticsEvent::VERSION;
    }

    /**
     * @return array<string, array{enabled: bool, id?: string}>
     */
    public function providerSummary(): array
    {
        return [
            'ga4' => ['enabled' => false, 'id' => null],
            'gtm' => ['enabled' => false, 'id' => null],
            'meta' => ['enabled' => false, 'id' => null],
            'plausible' => ['enabled' => false, 'id' => null],
            'posthog' => ['enabled' => false, 'id' => null],
            'webhook' => ['enabled' => false, 'id' => null],
            'mixpanel' => ['enabled' => false, 'id' => null],
            'amplitude' => ['enabled' => false, 'id' => null],
        ];
    }

    /**
     * @return array{events: int, dispatched: int, failed: int, success_rate: float, top_event: string|null}
     */
    public function reportSummary(): array
    {
        return [
            'events' => $this->metrics->totalDispatched(),
            'dispatched' => $this->metrics->totalDispatched(),
            'failed' => $this->metrics->totalFailed(),
            'success_rate' => 100.0,
            'top_event' => null,
        ];
    }

    /**
     * @return array{enabled: bool, strategy: string, total: int, buffered: int, max_size: int, storage_path: string, utilization: float}
     */
    public function dlqSummary(): array
    {
        return [
            'enabled' => false,
            'strategy' => 'null',
            'total' => 0,
            'buffered' => 0,
            'max_size' => 0,
            'storage_path' => '',
            'utilization' => 0.0,
        ];
    }

    // ─── Profile & Health ───────────────────────────────────────────────

    /**
     * @return array{event_counts: array<string, int>, total_events: int, total_value: float, first_seen: string|null, last_seen: string|null, funnel_steps: array<string, bool>, engagement_score: float, plan: string|null, traits: array<string, mixed>}
     */
    public function getProfile(string $userId): array
    {
        return [
            'event_counts' => [],
            'total_events' => 0,
            'total_value' => 0.0,
            'first_seen' => null,
            'last_seen' => null,
            'funnel_steps' => [],
            'engagement_score' => 0.0,
            'plan' => null,
            'traits' => [],
        ];
    }

    /**
     * @return array{user_id: string, total_events: int, lifetime_value: float, first_seen: string|null, last_seen: string|null, engagement_score: float, plan: string|null, event_types: int, funnel_steps_completed: int, traits: array<string, mixed>}
     */
    public function getProfileSummary(string $userId): array
    {
        return [
            'user_id' => $userId,
            'total_events' => 0,
            'lifetime_value' => 0.0,
            'first_seen' => null,
            'last_seen' => null,
            'engagement_score' => 0.0,
            'plan' => null,
            'event_types' => 0,
            'funnel_steps_completed' => 0,
            'traits' => [],
        ];
    }

    public function quickStartEvents(): array
    {
        return \ZeroBoiler\Analytics\Events\EventCatalog::quickStart();
    }

    /**
     * @return list<array{name: string, class: class-string, ga4: string, category: string}>
     */
    public function plgEvents(): array
    {
        return \ZeroBoiler\Analytics\Events\EventCatalog::plgEvents();
    }

    public function healthCheck(): array
    {
        return [
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'overall_score' => 100,
            'timestamp' => (string) (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
            'subsystems' => [],
            'recommendations' => [],
        ];
    }

    public function ping(): array
    {
        return [
            'status' => 'ok',
            'version' => AnalyticsEvent::VERSION,
            'providers_configured' => 0,
            'catalog_size' => \ZeroBoiler\Analytics\Events\EventCatalog::count(),
        ];
    }

    /**
     * @return array{score: int, grade: string, details: array<string, mixed>}
     */
    public function maturityScore(): array
    {
        return ['score' => 0, 'grade' => 'N/A', 'details' => []];
    }

    public function onboardingChecklist(): array
    {
        return ['checklist' => [], 'summary' => ['total' => 0, 'tracked' => 0, 'completion' => 0.0, 'gaps' => []]];
    }

    public function funnelReadiness(): array
    {
        return ['signup_funnel' => [], 'purchase_funnel' => [], 'subscription_funnel' => [], 'overall' => 0.0];
    }

    // ─── Orchestration ─────────────────────────────────────────────────

    /** @param array<string, mixed> $params
     * @return array{pipeline: string, status: string, started_at: string, steps: int, completed_steps: int, identity: string}
     */
    public function orchestrate(string $pipelineName, string $clientId, ?string $userId = null, array $params = []): array
    {
        return [
            'pipeline' => $pipelineName,
            'status' => 'started',
            'started_at' => (string) (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
            'steps' => 0,
            'completed_steps' => 0,
            'identity' => $clientId,
        ];
    }

    /** @param array<string, mixed> $params
     * @return array{step: string, event: string, pipeline_status: string, completed_steps: list<string>, remaining_steps: int, is_complete: bool}
     */
    public function orchestrateAdvance(string $pipelineName, string $stepName, string $clientId, ?string $userId = null, array $params = []): array
    {
        return [
            'step' => $stepName,
            'event' => $stepName,
            'pipeline_status' => 'in_progress',
            'completed_steps' => [],
            'remaining_steps' => 0,
            'is_complete' => false,
        ];
    }

    public function orchestrateProgress(string $pipelineName, string $clientId, ?string $userId = null): float
    {
        return 0.0;
    }

    public function insightReport(): array
    {
        return [
            'generated_at' => (string) (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
            'insights' => [],
            'summary' => ['total' => 0, 'by_type' => [], 'by_severity' => []],
        ];
    }

    // ─── PLG Scoring ──────────────────────────────────────────────────

    /**
     * @return array{score: float, grade: string, activation: float, engagement: float, retention: float, feature_breadth: float, segment: string, signals: list<string>, identity: string, computed_at: string}
     */
    public function plgScore(string $identity): array
    {
        return [
            'score' => 0.0,
            'grade' => 'N/A',
            'activation' => 0.0,
            'engagement' => 0.0,
            'retention' => 0.0,
            'feature_breadth' => 0.0,
            'segment' => '',
            'signals' => [],
            'identity' => $identity,
            'computed_at' => (string) (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{avg_score: float, total_cached: int, grade_distribution: array<string, int>}
     */
    public function plgAggregate(): array
    {
        return ['avg_score' => 0.0, 'total_cached' => 0, 'grade_distribution' => []];
    }

    public function plgInvalidate(string $identity): void
    {
        // No-op in fake
    }

    // ─── Time Series ───────────────────────────────────────────────────

    /**
     * @return array{total_events: int, unique_identities: int, top_events: list<array{event: string, count: int}>, category_breakdown: array<string, int>, trend: array{direction: string, change_pct: float, current: int, previous: int}, moving_avg: float, period: string, computed_at: string}
     */
    public function timeSeries(string $period = '1h'): array
    {
        return [
            'total_events' => 0,
            'unique_identities' => 0,
            'top_events' => [],
            'category_breakdown' => [],
            'trend' => ['direction' => 'stable', 'change_pct' => 0.0, 'current' => 0, 'previous' => 0],
            'moving_avg' => 0.0,
            'period' => $period,
            'computed_at' => (string) (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, array{total_events: int, unique_identities: int, top_events: list<array{event: string, count: int}>, category_breakdown: array<string, int>, trend: array, moving_avg: float, period: string}>
     */
    public function timeSeriesDashboard(): array
    {
        return [];
    }

    /**
     * @return array{current: array, previous: array, delta: array{events: int, identities: int, pct_change: float}}
     */
    public function timeSeriesCompare(string $currentPeriod, string $previousPeriod): array
    {
        return [
            'current' => [],
            'previous' => [],
            'delta' => ['events' => 0, 'identities' => 0, 'pct_change' => 0.0],
        ];
    }

    // ─── B2B Groups ────────────────────────────────────────────────────

    /** @param array<string, mixed> $traits @param array<string, mixed> $params */
    public function group(string $groupId, array $traits = [], array $params = []): void
    {
        $this->track('group_identify', array_merge(['group_id' => $groupId, 'traits' => $traits], $params));
    }

    /** @param array<string, mixed> $traits */
    public function groupAddMember(string $userId, string $groupId, ?string $role = null, array $traits = []): void
    {
        $this->track('group_member_added', array_filter(['group_id' => $groupId, 'user_id' => $userId, 'role' => $role]));
    }

    /**
     * @return array{group_id: string, traits: array<string, mixed>, member_count: int, updated_at: string|null}
     */
    public function getGroup(string $groupId): array
    {
        return ['group_id' => $groupId, 'traits' => [], 'member_count' => 0, 'updated_at' => null];
    }

    // ─── SaaS Acquisition ──────────────────────────────────────────────

    /** @param array<string, mixed> $options @param array<string, mixed> $params */
    public function trackSaaSAcquisition(?string $planName = null, ?float $amount = null, string $currency = 'USD', array $options = [], array $params = []): void
    {
        $this->signUp($options['method'] ?? null, $params);

        $skipTrial = (bool) ($options['skip_trial'] ?? false);
        if (! $skipTrial) {
            $this->trialStart($planName, $options['trial_days'] ?? null, $params);
        }

        if ($amount !== null) {
            $this->subscription($planName, $amount, $currency, $options['billing_cycle'] ?? null, $params);
        }
    }

    // ─── Fluent Assertions ────────────────────────────────────────────

    /**
     * Assert that a given event name was tracked at least once.
     *
     * @param  callable(AnalyticsEvent): bool|null  $callback  Optional filter callback
     */
    public static function assertTracked(string $eventName, ?callable $callback = null): void
    {
        $fake = self::instance();
        $events = $fake->trackedEvents($eventName);

        if ($callback !== null) {
            $matching = array_filter($events, $callback);
            assert(
                count($matching) > 0,
                'The analytics event [' . $eventName . '] was tracked but no events matched the given callback.',
            );
        } else {
            assert(
                count($events) > 0,
                'The expected analytics event [' . $eventName . '] was not tracked. Events tracked: '
                    . implode(', ', array_column($fake->events, 'name')),
            );
        }
    }

    /**
     * Assert that a given event name was NOT tracked.
     */
    public static function assertNotTracked(string $eventName): void
    {
        $fake = self::instance();

        assert(
            count($fake->trackedEvents($eventName)) === 0,
            "The unexpected analytics event [{$eventName}] was tracked.",
        );
    }

    /**
     * Assert that a given event was tracked exactly N times.
     */
    public static function assertTrackedTimes(string $eventName, int $times): void
    {
        $fake = self::instance();
        $count = count($fake->trackedEvents($eventName));

        assert(
            $count === $times,
            "The analytics event [{$eventName}] was tracked {$count} times, expected {$times} times.",
        );
    }

    /**
     * Assert that a given event was tracked exactly once.
     */
    public static function assertTrackedOnce(string $eventName): void
    {
        self::assertTrackedTimes($eventName, 1);
    }

    /**
     * Assert that a given event was tracked at least N times.
     */
    public static function assertTrackedAtLeast(string $eventName, int $times): void
    {
        $fake = self::instance();
        $count = count($fake->trackedEvents($eventName));

        assert(
            $count >= $times,
            "The analytics event [{$eventName}] was tracked {$count} times, expected at least {$times} times.",
        );
    }

    /**
     * Assert that no analytics events were dispatched at all.
     */
    public static function assertNothingTracked(): void
    {
        $fake = self::instance();

        assert(
            count($fake->events) === 0,
            'Analytics events were tracked when none were expected: '
                . implode(', ', array_column($fake->events, 'name')),
        );
    }

    /**
     * Assert an identity (identify) call was made for a given user ID.
     *
     * @param  callable(array{userId: string, clientId: string|null, traits: array<string, mixed>}): bool|null  $callback
     */
    public static function assertIdentified(string $userId, ?callable $callback = null): void
    {
        $fake = self::instance();
        $calls = array_filter($fake->identifyCalls, fn (array $c): bool => $c['userId'] === $userId);

        if ($callback !== null) {
            $matching = array_filter($calls, $callback);
            assert(
                count($matching) > 0,
                "An identify call was made for user [{$userId}] but no calls matched the given callback.",
            );
        } else {
            assert(
                count($calls) > 0,
                "No identify call was made for user [{$userId}].",
            );
        }
    }

    /**
     * Assert a page view was tracked.
     *
     * @param  callable(AnalyticsEvent): bool|null  $callback
     */
    public static function assertPageViewTracked(?callable $callback = null): void
    {
        $fake = self::instance();

        if ($callback !== null) {
            $matching = array_filter($fake->pageViews, $callback);
            assert(
                count($matching) > 0,
                'A page view was tracked but no events matched the given callback.',
            );
        } else {
            assert(
                count($fake->pageViews) > 0,
                'No page view events were tracked.',
            );
        }
    }

    /**
     * Assert that events were tracked in a specific sequence.
     *
     * Pass event names in the expected order. Only considers events matching
     * the given names — other events in between are ignored.
     *
     * @param  list<string>  $eventNames  Expected event name sequence
     */
    public static function assertEventSequence(array $eventNames): void
    {
        $fake = self::instance();

        $filtered = array_values(array_filter(
            $fake->events,
            fn (AnalyticsEvent $e): bool => in_array($e->name, $eventNames, true),
        ));

        $actualNames = array_map(fn (AnalyticsEvent $e): string => $e->name, $filtered);

        assert(
            $actualNames === $eventNames,
            'Analytics event sequence mismatch. Expected [' . implode(' → ', $eventNames)
                . '], got [' . implode(' → ', $actualNames ?: ['(none)']) . '].',
        );
    }

    /**
     * Assert that a batch of events were all tracked.
     *
     * Every event name in the given list must appear at least once in the
     * tracked events. Order is not enforced.
     *
     * @param  list<string>  $eventNames  Event names that must all be present
     */
    public static function assertEventBatch(array $eventNames): void
    {
        $fake = self::instance();
        $actualNames = array_unique(array_map(fn (AnalyticsEvent $e): string => $e->name, $fake->events));
        $missing = array_values(array_diff($eventNames, $actualNames));

        assert(
            empty($missing),
            'Expected analytics events not tracked: [' . implode(', ', $missing) . ']. '
                . 'Tracked events: [' . implode(', ', $actualNames ?: ['(none)']) . '].',
        );
    }

    /**
     * Assert that a SaaS identity linking call was made.
     *
     * @param  callable(array{userId: string, clientId: string, traits: array<string, mixed>}): bool|null  $callback
     */
    public static function assertSaaSIdentityLinked(string $userId, ?callable $callback = null): void
    {
        $fake = self::instance();
        $calls = array_filter($fake->saasIdentityCalls, fn (array $c): bool => $c['userId'] === $userId);

        if ($callback !== null) {
            $matching = array_filter($calls, $callback);
            assert(
                count($matching) > 0,
                "No SaaS identity link for user [{$userId}] matched the callback.",
            );
        } else {
            assert(
                count($calls) > 0,
                "No SaaS identity link was made for user [{$userId}].",
            );
        }
    }

    /**
     * Assert that a funnel progress call was tracked.
     *
     * @param  callable(array{funnelName: string, stepName: string, identity: string, stepNumber: int, totalSteps: int}): bool|null  $callback
     */
    public static function assertFunnelProgressTracked(string $funnelName, ?callable $callback = null): void
    {
        $fake = self::instance();
        $calls = array_filter($fake->funnelProgressCalls, fn (array $c): bool => $c['funnelName'] === $funnelName);

        if ($callback !== null) {
            $matching = array_filter($calls, $callback);
            assert(
                count($matching) > 0,
                "No funnel progress for [{$funnelName}] matched the callback.",
            );
        } else {
            assert(
                count($calls) > 0,
                "No funnel progress was tracked for [{$funnelName}].",
            );
        }
    }

    // ─── Inspection Methods ───────────────────────────────────────────

    /**
     * Get all dispatched analytics events.
     *
     * @return list<AnalyticsEvent>
     */
    public function allEvents(): array
    {
        return $this->events;
    }

    /**
     * Get events matching a given event name.
     *
     * @return list<AnalyticsEvent>
     */
    public function trackedEvents(string $eventName): array
    {
        return array_values(array_filter(
            $this->events,
            fn (AnalyticsEvent $e): bool => $e->name === $eventName,
        ));
    }

    /**
     * Get all identify calls.
     *
     * @return list<array{userId: string, clientId: string|null, traits: array<string, mixed>}>
     */
    public function identifyCalls(): array
    {
        return $this->identifyCalls;
    }

    /**
     * Get all SaaS identity calls.
     *
     * @return list<array{userId: string, clientId: string, traits: array<string, mixed>}>
     */
    public function saasIdentityCalls(): array
    {
        return $this->saasIdentityCalls;
    }

    /**
     * Get all captured page views.
     *
     * @return list<AnalyticsEvent>
     */
    public function pageViews(): array
    {
        return $this->pageViews;
    }

    /**
     * Get all e-commerce tracking calls.
     *
     * @return list<array{eventName: string, data: array<string, mixed>, params: array<string, mixed>}>
     */
    public function ecommerceCalls(): array
    {
        return $this->ecommerceCalls;
    }

    /**
     * Get all funnel progress calls.
     *
     * @return list<array{funnelName: string, stepName: string, identity: string, stepNumber: int, totalSteps: int}>
     */
    public function funnelProgressCalls(): array
    {
        return $this->funnelProgressCalls;
    }

    /**
     * Get the count of total tracked events.
     */
    public function eventCount(): int
    {
        return count($this->events);
    }

    /**
     * Get event names grouped by count.
     *
     * @return array<string, int>
     */
    public function eventCounts(): array
    {
        $counts = [];
        foreach ($this->events as $event) {
            $counts[$event->name] = ($counts[$event->name] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Reset all captured state. Useful for test isolation within a single test.
     */
    public function reset(): void
    {
        $this->events = [];
        $this->identifyCalls = [];
        $this->saasIdentityCalls = [];
        $this->consentHistory = [];
        $this->pageViews = [];
        $this->ecommerceCalls = [];
        $this->funnelProgressCalls = [];
        $this->metrics = new AnalyticsMetrics;
    }

    /**
     * Static accessor helper for static assertion methods.
     *
     * @return static
     */
    private static function instance(): static
    {
        return app('zeroboiler.analytics');
    }
}
