<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;

/**
 * Central analytics manager — dispatches events to all configured trackers.
 *
 * Manages GA4, GTM, Meta Pixel, Plausible, and PostHog tracker instances.
 * Provides convenience methods for common events (purchase, identify, screenView),
 * consent management, debug mode, GDPR identity reset, and async dispatch.
 *
 * Typically accessed via the `Analytics` facade or resolved from the container
 * as `zeroboiler.analytics`.
 *
 * @see \ZeroBoiler\Analytics\Facades\Analytics
 */
class AnalyticsManager
{
    protected GA4Tracker $ga4;

    protected GTMTracker $gtm;

    protected MetaPixelTracker $meta;

    protected PlausibleTracker $plausible;

    protected PosthogTracker $posthog;

    private bool $debugMode;

    private bool $logEvents;

    /**
     * @param  ConfigRepository|null  $config  Optional config repository for testing
     */
    public function __construct(?ConfigRepository $config = null)
    {
        if ($config === null) {
            $container = $this->getContainer();
            $config = $container->make(ConfigRepository::class);
        }

        $ga4Config = $config->get('zeroboiler.analytics.ga4', []);
        $gtmConfig = $config->get('zeroboiler.analytics.gtm', []);
        $metaConfig = $config->get('zeroboiler.analytics.meta_pixel', []);

        /** @var array{enabled?: bool, measurement_id?: string, api_secret?: string} $ga4Config */
        /** @var array{enabled?: bool, container_id?: string} $gtmConfig */
        /** @var array{enabled?: bool, id?: string, access_token?: string} $metaConfig */
        $this->ga4 = new GA4Tracker(
            measurementId: $ga4Config['measurement_id'] ?? '',
            apiSecret: $ga4Config['api_secret'] ?? '',
            enabled: $ga4Config['enabled'] ?? false,
        );

        $this->gtm = new GTMTracker(
            containerId: $gtmConfig['container_id'] ?? '',
            enabled: $gtmConfig['enabled'] ?? false,
        );

        $this->meta = new MetaPixelTracker(
            pixelId: $metaConfig['id'] ?? '',
            accessToken: $metaConfig['access_token'] ?? '',
            enabled: $metaConfig['enabled'] ?? false,
        );

        // Optional: Plausible Analytics
        $plausibleConfig = $config->get('zeroboiler.analytics.plausible', []);
        /** @var array{enabled?: bool, domain?: string, api_key?: string, base_url?: string} $plausibleConfig */
        $this->plausible = new PlausibleTracker(
            domain: $plausibleConfig['domain'] ?? '',
            apiKey: $plausibleConfig['api_key'] ?? '',
            baseUrl: $plausibleConfig['base_url'] ?? 'https://plausible.io/api/event',
            enabled: $plausibleConfig['enabled'] ?? false,
        );

        // Optional: PostHog Analytics
        $posthogConfig = $config->get('zeroboiler.analytics.posthog', []);
        /** @var array{enabled?: bool, api_key?: string, host?: string, project_id?: string} $posthogConfig */
        $this->posthog = new PosthogTracker(
            apiKey: $posthogConfig['api_key'] ?? '',
            host: $posthogConfig['host'] ?? 'https://eu.posthog.com',
            projectId: $posthogConfig['project_id'] ?? '',
            enabled: $posthogConfig['enabled'] ?? false,
        );

        // Apply default consent state from config (GDPR-safe defaults)
        $consentDefault = $config->get('zeroboiler.analytics.consent.default', 'granted');
        if ($consentDefault === 'denied') {
            $this->denyConsent();
        }

        // Debug mode configuration
        $debugConfig = $config->get('zeroboiler.analytics.debug', []);
        /** @var array{enabled?: bool, log_events?: bool} $debugConfig */
        $this->debugMode = (bool) ($debugConfig['enabled'] ?? false);
        $this->logEvents = (bool) ($debugConfig['log_events'] ?? false);
    }

    /**
     * Track an event across all configured providers.
     *
     * @param  array<string, mixed>  $params
     */
    public function track(string $eventName, array $params = []): void
    {
        $this->dispatchToTrackers(new AnalyticsEvent(name: $eventName, params: $params));
    }

    /**
     * Track an AnalyticsEvent DTO across all configured providers.
     */
    public function trackEvent(AnalyticsEvent $event): void
    {
        $this->dispatchToTrackers($event);
    }

    /**
     * Track a purchase event with transaction details.
     *
     * Convenience method for quick e-commerce purchase tracking
     * without needing the EcommerceAnalyticsService.
     *
     * @param  string  $transactionId  Unique transaction identifier
     * @param  float  $value  Total revenue value
     * @param  array<int, array<string, mixed>>  $items  Array of item objects
     * @param  array<string, mixed>  $params  Additional parameters (currency, coupon, etc.)
     */
    public function purchase(string $transactionId, float $value, array $items = [], array $params = []): void
    {
        $this->track('purchase', array_merge([
            'transaction_id' => $transactionId,
            'value' => $value,
            'currency' => $params['currency'] ?? 'USD',
            'items' => $items,
        ], $params));
    }

    /**
     * Identify a user across all analytics providers.
     *
     * Links the user ID to the client tracking ID for cross-device identification.
     *
     * @param  string  $userId  Authenticated user ID
     * @param  string|null  $clientId  Client tracking ID (optional)
     * @param  array<string, mixed>  $traits  Additional user traits (email_hash, name, plan, etc.)
     */
    public function identify(string $userId, ?string $clientId = null, array $traits = []): void
    {
        $params = array_merge([
            'user_id' => $userId,
        ], $traits);

        if ($clientId !== null) {
            $params['client_id'] = $clientId;
        }

        $this->track('identify', $params);
    }

    /**
     * Dispatch an event to all enabled trackers.
     */
    private function dispatchToTrackers(AnalyticsEvent $event): void
    {
        // Debug mode: log but never send
        if ($this->debugMode) {
            if ($this->logEvents) {
                Log::debug('ZeroBoiler Analytics [debug]', [
                    'event' => $event->name,
                    'params' => $event->params,
                    'client_id' => $event->clientId,
                    'user_id' => $event->userId,
                ]);
            }

            return;
        }

        if ($this->ga4->isEnabled()) {
            $this->ga4->track($event);
        }

        if ($this->gtm->isEnabled()) {
            $this->gtm->track($event);
        }

        if ($this->meta->isEnabled()) {
            $this->meta->track($event);
        }

        if ($this->plausible->isEnabled()) {
            $this->plausible->track($event);
        }

        if ($this->posthog->isEnabled()) {
            $this->posthog->track($event);
        }
    }

    /**
     * Get script tags for the head section.
     */
    public function headScripts(): string
    {
        $scripts = [];

        if ($this->ga4->isEnabled()) {
            $scripts[] = $this->ga4->headScripts();
        }

        if ($this->gtm->isEnabled()) {
            $scripts[] = $this->gtm->headScripts();
        }

        if ($this->meta->isEnabled()) {
            $scripts[] = $this->meta->headScripts();
        }

        if ($this->plausible->isEnabled()) {
            $scripts[] = $this->plausible->headScripts();
        }

        if ($this->posthog->isEnabled()) {
            $scripts[] = $this->posthog->headScripts();
        }

        return implode("\n", array_filter($scripts));
    }

    /**
     * Get script tags for the body section.
     */
    public function bodyScripts(): string
    {
        $scripts = [];

        if ($this->gtm->isEnabled()) {
            $scripts[] = $this->gtm->bodyScripts();
        }

        if ($this->meta->isEnabled()) {
            $scripts[] = $this->meta->bodyScripts();
        }

        return implode("\n", array_filter($scripts));
    }

    /**
     * Push data to the GTM dataLayer.
     *
     * @param  array<string, mixed>  $data
     */
    public function push(array $data): void
    {
        if ($this->gtm->isEnabled()) {
            $this->gtm->push($data);
        }
    }

    /**
     * Get the GA4 tracker instance.
     */
    public function ga4(): GA4Tracker
    {
        return $this->ga4;
    }

    /**
     * Get the GTM tracker instance.
     */
    public function gtm(): GTMTracker
    {
        return $this->gtm;
    }

    /**
     * Get the Meta Pixel tracker instance.
     */
    public function meta(): MetaPixelTracker
    {
        return $this->meta;
    }

    /**
     * Get the Plausible tracker instance (optional).
     */
    public function plausible(): PlausibleTracker
    {
        return $this->plausible;
    }

    /**
     * Get the PostHog tracker instance (optional).
     */
    public function posthog(): PosthogTracker
    {
        return $this->posthog;
    }

    /**
     * Set consent state across all trackers.
     *
     * Propagates the given ConsentState to GA4, GTM, and Meta Pixel trackers.
     * Use this when the user grants or denies consent (e.g. via a cookie banner).
     */
    public function setConsent(ConsentState $state): void
    {
        $this->ga4->setConsent($state);
        $this->gtm->setConsent($state);
        $this->meta->setConsent($state);
        $this->plausible->setConsent($state);
        $this->posthog->setConsent($state);
    }

    /**
     * Grant all consent (shortcut for GDPR opt-in).
     */
    public function grantConsent(): void
    {
        $this->setConsent(ConsentState::granted());
    }

    /**
     * Deny all consent (shortcut for GDPR opt-out / default state).
     */
    public function denyConsent(): void
    {
        $this->setConsent(ConsentState::denied());
    }

    /**
     * Get the current consent state from the primary tracker (GA4).
     */
    public function getConsent(): ConsentState
    {
        return $this->ga4->getConsent();
    }

    /**
     * Resolve the application container.
     */
    private function getContainer(): Container
    {
        return app();
    }

    /**
     * Check if debug mode is enabled.
     *
     * When debug mode is on, events are logged but not dispatched to providers.
     */
    public function isDebug(): bool
    {
        return $this->debugMode;
    }

    /**
     * Check if event logging is enabled in debug mode.
     */
    public function shouldLogEvents(): bool
    {
        return $this->logEvents;
    }

    /**
     * Enable or disable debug mode.
     *
     * When enabled, events are logged but not sent to any provider.
     */
    public function setDebug(bool $enabled): void
    {
        $this->debugMode = $enabled;
    }

    /**
     * Reset user identity across all providers (GDPR right to be forgotten).
     *
     * Sends a 'reset_identity' signal to GA4 and clears any user-level
     * data stored on the tracker instances. Call this when a user requests
     * account deletion or data erasure.
     */
    public function resetIdentity(): void
    {
        $this->ga4->resetUserId();
        $this->posthog->reset();

        Log::info('ZeroBoiler Analytics: identity reset for GDPR compliance');
    }

    /**
     * Track a screen view event (for multi-page / SPA navigation).
     *
     * Use this to track navigation between distinct screens within a
     * single-page app (e.g. "Dashboard", "Settings", "Billing").
     *
     * @param  string  $screenName  Screen or view name
     * @param  string|null  $screenClass  Optional screen class/type
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function screenView(string $screenName, ?string $screenClass = null, array $params = []): void
    {
        $this->track('screen_view', array_merge([
            'screen_name' => $screenName,
            'screen_class' => $screenClass,
        ], $params));
    }

    /**
     * Track an A/B test exposure event.
     *
     * @param  string  $experimentId  The experiment identifier
     * @param  string  $variantId  The variant assigned to this user
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function abTestExposure(string $experimentId, string $variantId, array $params = []): void
    {
        $this->track('ab_test_exposure', array_merge([
            'experiment_id' => $experimentId,
            'variant_id' => $variantId,
        ], $params));
    }

    /**
     * Track a notification event.
     *
     * @param  string  $channel  Notification channel (email, push, in_app, sms)
     * @param  string  $action  Action type (sent, delivered, opened, clicked, failed)
     * @param  string|null  $notificationType  Notification type/template
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function notification(
        string $channel,
        string $action,
        ?string $notificationType = null,
        array $params = [],
    ): void {
        $this->track('notification', array_filter(array_merge([
            'notification_channel' => $channel,
            'notification_action' => $action,
            'notification_type' => $notificationType,
        ], $params)));
    }

    /**
     * Dispatch an event asynchronously via the queue dispatcher.
     *
     * Shortcut for queuing events without injecting QueuedAnalyticsDispatcher.
     *
     * @param  array<string, mixed>  $params
     */
    public function trackAsync(string $eventName, array $params = []): void
    {
        $event = new AnalyticsEvent(name: $eventName, params: $params);

        try {
            $dispatcher = app(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);
            $dispatcher->dispatch($event);
        } catch (\Throwable $e) {
            // Fallback to synchronous dispatch
            Log::warning('ZeroBoiler Analytics: async dispatch failed, falling back to sync', [
                'event' => $eventName,
                'error' => $e->getMessage(),
            ]);
            $this->trackEvent($event);
        }
    }

    /**
     * Set user properties / traits on all providers.
     *
     * Sends a $set or $set_once event to PostHog and equivalent user
     * property updates to other providers. Use after signup or profile update.
     *
     * @param  array<string, mixed>  $properties  User traits (name, email, plan, company, etc.)
     * @param  string|null  $userId  Optional user ID (defaults to null for provider-resolved)
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
     * Alias one user identity to another (merge identities).
     *
     * Common in analytics when a user signs up and you need to merge
     * their anonymous (client ID) profile with their authenticated profile.
     * Used by PostHog ($create_alias), Mixpanel (alias), and similar.
     *
     * @param  string  $previousId  The previous identifier (e.g. client ID or anonymous ID)
     * @param  string  $newId  The new identifier (e.g. authenticated user ID)
     */
    public function alias(string $previousId, string $newId): void
    {
        $this->track('alias', [
            'previous_id' => $previousId,
            'new_id' => $newId,
        ]);
    }

    /**
     * Track a page view event.
     *
     * Convenience method for server-side page view tracking without
     * needing the API controller. Useful for server-rendered pages,
     * middleware-based tracking, or non-JS environments.
     *
     * @param  string  $title  Page title
     * @param  string  $location  Full URL of the page
     * @param  string  $referrer  Referrer URL
     * @param  array<string, mixed>  $params  Additional parameters
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
     * Track a server-side page view with client/user identity.
     *
     * Full-featured server-side page view tracking that combines the
     * page view event with client ID and user ID resolution. Designed
     * for middleware-based tracking where the request context is available.
     *
     * @param  string  $title  Page title
     * @param  string  $location  Full URL of the page
     * @param  string  $referrer  Referrer URL
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     * @param  array<string, mixed>  $params  Additional parameters
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
     * Track a logout event.
     *
     * Convenience method for logout tracking from the facade.
     *
     * @param  string|null  $method  Auth guard used (e.g. 'web', 'sanctum')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function logout(?string $method = null, array $params = []): void
    {
        $this->track('logout', array_filter(array_merge([
            'method' => $method,
        ], $params)));
    }

    /**
     * Track a trial end event.
     *
     * @param  string  $outcome  'converted' or 'expired'
     * @param  string|null  $planName  Trial plan name
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trialEnd(string $outcome, ?string $planName = null, array $params = []): void
    {
        $this->track('trial_end', array_filter(array_merge([
            'outcome' => $outcome,
            'plan_name' => $planName,
        ], $params)));
    }

    /**
     * Track a plan downgrade event.
     *
     * @param  string  $fromPlan  Previous plan name
     * @param  string  $toPlan  New (lower) plan name
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function planDowngrade(string $fromPlan, string $toPlan, array $params = []): void
    {
        $this->track('plan_downgrade', array_merge([
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
        ], $params));
    }

    /**
     * Format e-commerce items for Meta Pixel (contents array format).
     *
     * Converts GA4-style item arrays to Meta Pixel's `contents` format.
     * Useful for cross-provider event dispatching.
     *
     * @param  array<int, array<string, mixed>>  $items  GA4-format item arrays
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

    /**
     * Track a full e-commerce event with cross-provider formatting.
     *
     * Convenience method that dispatches the event to all providers with
     * proper GA4 and Meta Pixel formatting. The Meta equivalent event name
     * and formatted `contents` parameter are automatically computed.
     *
     * @param  string  $eventName  GA4 event name (e.g. 'purchase', 'add_to_cart', 'view_item')
     * @param  array<string, mixed>  $data  Event data (value, currency, items, transaction_id, etc.)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackEcommerce(string $eventName, array $data = [], array $params = []): void
    {
        // Track GA4 event with original data
        $this->track($eventName, array_merge($data, $params));

        // Track Meta Pixel equivalent if applicable
        $metaEvent = $this->mapEcommerceToMeta($eventName);
        if ($metaEvent !== null && $this->meta->isEnabled()) {
            $metaParams = $this->buildMetaParams($data);
            $this->track($metaEvent, array_merge($metaParams, $params));
        }
    }

    /**
     * Map GA4 e-commerce event names to Meta Pixel equivalents.
     *
     * @return string|null Meta Pixel event name, or null if no equivalent exists
     */
    private function mapEcommerceToMeta(string $eventName): ?string
    {
        $mapping = [
            'view_item' => 'ViewContent',
            'add_to_cart' => 'AddToCart',
            'remove_from_cart' => null,
            'view_cart' => null,
            'begin_checkout' => 'InitiateCheckout',
            'add_payment_info' => 'AddPaymentInfo',
            'purchase' => 'Purchase',
            'refund' => null,
        ];

        return $mapping[$eventName] ?? null;
    }

    /**
     * Build Meta Pixel-compatible parameters from GA4 e-commerce data.
     *
     * @param  array<string, mixed>  $data  GA4-format event data
     * @return array<string, mixed>  Meta Pixel formatted parameters
     */
    private function buildMetaParams(array $data): array
    {
        $params = [
            'value' => $data['value'] ?? null,
            'currency' => $data['currency'] ?? 'USD',
        ];

        if (isset($data['items']) && is_array($data['items'])) {
            $params['contents'] = array_map(
                fn (array $item): array => [
                    'id' => (string) ($item['item_id'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'item_price' => (float) ($item['price'] ?? 0),
                    'name' => (string) ($item['item_name'] ?? ''),
                    'category' => (string) ($item['item_category'] ?? ''),
                ],
                $data['items'],
            );
            $params['content_ids'] = array_column($data['items'], 'item_id');
            $params['num_items'] = array_sum(array_column($data['items'], 'quantity'));
        }

        if (isset($data['transaction_id'])) {
            $params['content_ids'] ??= [];
        }

        return array_filter($params, fn (mixed $v): bool => $v !== null);
    }

    /**
     * Get the event catalog summary (event counts per category).
     *
     * Dynamically computes counts from the category catalogs
     * rather than using hard-coded values.
     *
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
}
