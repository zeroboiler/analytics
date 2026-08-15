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
use ZeroBoiler\Analytics\Trackers\WebhookTracker;
use ZeroBoiler\Analytics\Trackers\MixpanelTracker;
use ZeroBoiler\Analytics\Trackers\AmplitudeTracker;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\EventInterceptorRegistry;
use ZeroBoiler\Analytics\Context\AnalyticsContextBus;
use ZeroBoiler\Analytics\Services\EventFlushingService;

/**
 * Central analytics manager — dispatches events to all configured trackers.
 *
 * Manages GA4, GTM, Meta Pixel, Plausible, PostHog, Mixpanel, Amplitude, and Webhook tracker instances.
 * Provides convenience methods for common events (purchase, identify, screenView),
 * consent management, debug mode, GDPR identity reset, and async dispatch.
 *
 * Typically accessed via the `Analytics` facade or resolved from the container
 * as `zeroboiler.analytics`.
 *
 * @see \ZeroBoiler\Analytics\Facades\Analytics
 *
 * @since 1.0.0
 */
final class AnalyticsManager
{
    protected GA4Tracker $ga4;

    protected GTMTracker $gtm;

    protected MetaPixelTracker $meta;

    protected PlausibleTracker $plausible;

    protected PosthogTracker $posthog;

    protected WebhookTracker $webhook;

    protected MixpanelTracker $mixpanel;

    protected AmplitudeTracker $amplitude;

    protected \ZeroBoiler\Analytics\Trackers\TikTokTracker $tiktok;

    protected \ZeroBoiler\Analytics\Trackers\LinkedInTracker $linkedin;

    private AnalyticsMetrics $metrics;

    private bool $debugMode;

    private bool $logEvents;

    private EventInterceptorRegistry $interceptors;

    /** @var array<string, string> Persistent event alias registry (alias → canonical name) */
    private array $aliasRegistry = [];

    /**
     * @param  ConfigRepository|null  $config  Optional config repository for testing
     */
    public function __construct(?ConfigRepository $config = null): void
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

        // Optional: Webhook Tracker
        $webhookConfig = $config->get('zeroboiler.analytics.webhook', []);
        /** @var array{enabled?: bool, url?: string, secret?: string, timeout?: int, retries?: int, sign?: bool, headers?: array<string, string>} $webhookConfig */
        $this->webhook = new WebhookTracker(
            webhookUrl: $webhookConfig['url'] ?? '',
            secret: $webhookConfig['secret'] ?? '',
            enabled: $webhookConfig['enabled'] ?? false,
            timeout: (int) ($webhookConfig['timeout'] ?? 5),
            retries: (int) ($webhookConfig['retries'] ?? 1),
            headers: $webhookConfig['headers'] ?? [],
            signPayloads: (bool) ($webhookConfig['sign'] ?? false),
        );

        // Optional: Mixpanel Analytics (v10.0.0)
        $mixpanelConfig = $config->get('zeroboiler.analytics.mixpanel', []);
        /** @var array{enabled?: bool, token?: string, host?: string} $mixpanelConfig */
        $this->mixpanel = new MixpanelTracker(
            token: $mixpanelConfig['token'] ?? '',
            host: $mixpanelConfig['host'] ?? 'https://api.mixpanel.com',
            enabled: $mixpanelConfig['enabled'] ?? false,
        );

        // Optional: Amplitude Analytics (v10.0.0)
        $amplitudeConfig = $config->get('zeroboiler.analytics.amplitude', []);
        /** @var array{enabled?: bool, api_key?: string, host?: string, platform?: string} $amplitudeConfig */
        $this->amplitude = new AmplitudeTracker(
            apiKey: $ampli...ey'] ?? '',
            host: $amplitudeConfig['host'] ?? 'https://api2.amplitude.com',
            platform: $amplitudeConfig['platform'] ?? 'Laravel/Server',
            enabled: $amplitudeConfig['enabled'] ?? false,
        );

        // Optional: TikTok Pixel & CAPI (v32.0.0)
        $tiktokConfig = $config->get('zeroboiler.analytics.tiktok', []);
        /** @var array{enabled?: bool, pixel_id?: string, access_token?: string, api_version?: string} $tiktokConfig */
        $this->tiktok = new \ZeroBoiler\Analytics\Trackers\TikTokTracker(
            pixelId: $tiktokConfig['pixel_id'] ?? '',
            accessToken: $tiktokConfig['access_token'] ?? '',
            enabled: $tiktokConfig['enabled'] ?? false,
            apiVersion: $tiktokConfig['api_version'] ?? 'v1.3',
        );

        // Optional: LinkedIn Insight Tag & CAPI (v32.0.0)
        $linkedinConfig = $config->get('zeroboiler.analytics.linkedin', []);
        /** @var array{enabled?: bool, partner_id?: string, conversion_id?: string, access_token?: string, api_version?: string} $linkedinConfig */
        $this->linkedin = new \ZeroBoiler\Analytics\Trackers\LinkedInTracker(
            partnerId: $linkedinConfig['partner_id'] ?? '',
            conversionId: $linkedinConfig['conversion_id'] ?? '',
            accessToken: $linkedinConfig['access_token'] ?? '',
            enabled: $linkedinConfig['enabled'] ?? false,
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

        // Metrics tracking
        $this->metrics = new AnalyticsMetrics($config);

        // Event interceptor registry
        $this->interceptors = new EventInterceptorRegistry;
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
        // Run before-interceptors
        $processed = $this->interceptors->runBefore($event);

        if ($processed === null) {
            return;
        }

        $event = $processed;

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

            $this->interceptors->runAfter($event, true);

            return;
        }

        // If DataBus is available and has rules, route through it
        try {
            $bus = app(\ZeroBoiler\Analytics\Bus\AnalyticsDataBus::class);
            $rules = $bus->getRules();

            if (! empty($rules)) {
                $bus->route($event);

                $this->interceptors->runAfter($event, true);

                return;
            }
        } catch (\Throwable) {
            // DataBus not available — fall through to standard dispatch
        }

        $success = $this->directDispatch($event);
        $this->interceptors->runAfter($event, $success);
    }

    /**
     * Dispatch an event to specific providers only (targeted dispatch).
     *
     * Allows sending an event to a subset of enabled providers instead of all.
     * Useful when certain events should only go to specific providers (e.g.,
     * PII events only to server-side providers, not client-side pixels).
     *
     * @param  AnalyticsEvent  $event  The event to dispatch
     * @param  list<string>  $providers  Provider names to dispatch to (e.g. ['ga4', 'posthog'])
     * @return array{dispatched: list<string>, failed: list<string>, skipped: list<string>}
     *
     * @since 112.0.0
     */
    public function trackTo(AnalyticsEvent $event, array $providers): array
    {
        $trackerMap = [
            'ga4' => fn () => $this->ga4,
            'gtm' => fn () => $this->gtm,
            'meta' => fn () => $this->meta,
            'plausible' => fn () => $this->plausible,
            'posthog' => fn () => $this->posthog,
            'webhook' => fn () => $this->webhook,
            'mixpanel' => fn () => $this->mixpanel,
            'amplitude' => fn () => $this->amplitude,
            'tiktok' => fn () => $this->tiktok,
            'linkedin' => fn () => $this->linkedin,
        ];

        $result = [
            'dispatched' => [],
            'failed' => [],
            'skipped' => [],
        ];

        foreach ($providers as $provider) {
            $getter = $trackerMap[$provider] ?? null;

            if ($getter === null) {
                $result['skipped'][] = $provider;

                continue;
            }

            $tracker = $getter();

            if (! $tracker->isEnabled()) {
                $result['skipped'][] = $provider;

                continue;
            }

            try {
                $tracker->track($event);
                $this->metrics->recordDispatch($provider);
                $result['dispatched'][] = $provider;
            } catch (\Throwable $e) {
                $this->metrics->recordFailure($provider, $e->getMessage());
                $result['failed'][] = $provider;
            }
        }

        return $result;
    }

    /**
     * Dispatch an event directly to all enabled trackers (bypasses DataBus).
     *
     * Use this when you want to ensure the event goes to all providers
     * regardless of any routing rules configured in the DataBus.
     *
     * @return bool True if dispatch succeeded to at least one provider
     */
    public function directDispatch(AnalyticsEvent $event): bool
    {
        $dispatched = false;

        if ($this->ga4->isEnabled()) {
            try {
                $this->ga4->track($event);
                $this->metrics->recordDispatch('ga4');
                $dispatched = true;
            } catch (\Throwable $e) {
                $this->metrics->recordFailure('ga4', $e->getMessage());
            }
        }

        if ($this->gtm->isEnabled()) {
            try {
                $this->gtm->track($event);
                $this->metrics->recordDispatch('gtm');
                $dispatched = true;
            } catch (\Throwable $e) {
                $this->metrics->recordFailure('gtm', $e->getMessage());
            }
        }

        if ($this->meta->isEnabled()) {
            try {
                $this->meta->track($event);
                $this->metrics->recordDispatch('meta');
                $dispatched = true;
            } catch (\Throwable $e) {
                $this->metrics->recordFailure('meta', $e->getMessage());
            }
        }

        if ($this->plausible->isEnabled()) {
            try {
                $this->plausible->track($event);
                $this->metrics->recordDispatch('plausible');
                $dispatched = true;
            } catch (\Throwable $e) {
                $this->metrics->recordFailure('plausible', $e->getMessage());
            }
        }

        if ($this->posthog->isEnabled()) {
            try {
                $this->posthog->track($event);
                $this->metrics->recordDispatch('posthog');
                $dispatched = true;
            } catch (\Throwable $e) {
                $this->metrics->recordFailure('posthog', $e->getMessage());
            }
        }

        if ($this->webhook->isEnabled()) {
            try {
                $this->webhook->track($event);
                $this->metrics->recordDispatch('webhook');
                $dispatched = true;
            } catch (\Throwable $e) {
                $this->metrics->recordFailure('webhook', $e->getMessage());
            }
        }

        if ($this->mixpanel->isEnabled()) {
            try {
                $this->mixpanel->track($event);
                $this->metrics->recordDispatch('mixpanel');
                $dispatched = true;
            } catch (\Throwable $e) {
                $this->metrics->recordFailure('mixpanel', $e->getMessage());
            }
        }

        if ($this->amplitude->isEnabled()) {
            try {
                $this->amplitude->track($event);
                $this->metrics->recordDispatch('amplitude');
                $dispatched = true;
            } catch (\Throwable $e) {
                $this->metrics->recordFailure('amplitude', $e->getMessage());
            }
        }

        if ($this->tiktok->isEnabled()) {
            try {
                $this->tiktok->track($event);
                $this->metrics->recordDispatch('tiktok');
                $dispatched = true;
            } catch (\Throwable $e) {
                $this->metrics->recordFailure('tiktok', $e->getMessage());
            }
        }

        if ($this->linkedin->isEnabled()) {
            try {
                $this->linkedin->track($event);
                $this->metrics->recordDispatch('linkedin');
                $dispatched = true;
            } catch (\Throwable $e) {
                $this->metrics->recordFailure('linkedin', $e->getMessage());
            }
        }

        return $dispatched;
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

        if ($this->mixpanel->isEnabled()) {
            $scripts[] = $this->mixpanel->headScripts();
        }

        if ($this->amplitude->isEnabled()) {
            $scripts[] = $this->amplitude->headScripts();
        }

        if ($this->tiktok->isEnabled()) {
            $scripts[] = $this->tiktok->headScripts();
        }

        if ($this->linkedin->isEnabled()) {
            $scripts[] = $this->linkedin->headScripts();
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
     * Get the Webhook tracker instance (optional).
     */
    public function webhook(): WebhookTracker
    {
        return $this->webhook;
    }

    /**
     * Get the Mixpanel tracker instance (optional).
     *
     * @since 10.0.0
     */
    public function mixpanel(): MixpanelTracker
    {
        return $this->mixpanel;
    }

    /**
     * Get the Amplitude tracker instance (optional).
     *
     * @since 10.0.0
     */
    public function amplitude(): AmplitudeTracker
    {
        return $this->amplitude;
    }

    /**
     * Get the TikTok Pixel tracker instance (optional).
     *
     * @since 32.0.0
     */
    public function tiktok(): \ZeroBoiler\Analytics\Trackers\TikTokTracker
    {
        return $this->tiktok;
    }

    /**
     * Get the LinkedIn Insight Tag tracker instance (optional).
     *
     * @since 32.0.0
     */
    public function linkedin(): \ZeroBoiler\Analytics\Trackers\LinkedInTracker
    {
        return $this->linkedin;
    }

    /**
     * Set consent state across all trackers.
     *
     * Propagates the given ConsentState to GA4, GTM, Meta Pixel, Plausible, PostHog, Mixpanel, Amplitude, TikTok, LinkedIn, and Webhook trackers.
     * Use this when the user grants or denies consent (e.g. via a cookie banner).
     */
    public function setConsent(ConsentState $state): void
    {
        $this->ga4->setConsent($state);
        $this->gtm->setConsent($state);
        $this->meta->setConsent($state);
        $this->plausible->setConsent($state);
        $this->posthog->setConsent($state);
        $this->webhook->setConsent($state);
        $this->mixpanel->setConsent($state);
        $this->amplitude->setConsent($state);
        $this->tiktok->setConsent($state);
        $this->linkedin->setConsent($state);
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
     * Track an A/B test conversion event.
     *
     * Fires when a user completes a conversion goal within an experiment.
     * Complements abTestExposure() for full experiment funnel tracking.
     *
     * @param  string  $experimentId  The experiment identifier
     * @param  string  $variantId  The variant that converted
     * @param  string  $goalName  The conversion goal name
     * @param  array<string, mixed>  $params  Additional parameters (value, revenue, etc.)
     */
    public function abTestConversion(string $experimentId, string $variantId, string $goalName, array $params = []): void
    {
        $this->track('ab_test_conversion', array_merge([
            'experiment_id' => $experimentId,
            'variant_id' => $variantId,
            'goal_name' => $goalName,
        ], $params));
    }

    /**
     * Track a wishlist/add-to-wishlist event.
     *
     * E-commerce convenience for tracking when users add items to their wishlist.
     *
     * @param  array{item_id: string, item_name?: string, item_category?: string, price?: float, currency?: string}  $item  Wishlisted item
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function addToWishlist(array $item, array $params = []): void
    {
        $this->track('add_to_wishlist', array_merge([
            'currency' => $item['currency'] ?? 'USD',
            'value' => (float) ($item['price'] ?? 0),
            'items' => [$item],
        ], $params));
    }

    /**
     * Track a promotion view event.
     *
     * E-commerce convenience for tracking promotional banner/content views.
     *
     * @param  string  $promotionId  Promotion identifier
     * @param  string  $promotionName  Promotion display name
     * @param  string|null  $creativeName  Creative/banner name
     * @param  string|null  $creativeSlot  Creative slot position
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function promotionView(
        string $promotionId,
        string $promotionName,
        ?string $creativeName = null,
        ?string $creativeSlot = null,
        array $params = [],
    ): void {
        $this->track('view_promotion', array_filter(array_merge([
            'promotion_id' => $promotionId,
            'promotion_name' => $promotionName,
            'creative_name' => $creativeName,
            'creative_slot' => $creativeSlot,
        ], $params)));
    }

    /**
     * Track Monthly Recurring Revenue change.
     *
     * SaaS revenue convenience for tracking MRR movements
     * (new, expansion, contraction, churn, reactivation).
     *
     * @param  float  $amount  MRR amount
     * @param  string  $movementType  Movement type: new, expansion, contraction, churn, reactivation
     * @param  string|null  $planName  Subscription plan name
     * @param  string|null  $userId  User ID (optional)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackMrr(
        float $amount,
        string $movementType,
        ?string $planName = null,
        ?string $userId = null,
        array $params = [],
    ): void {
        $this->track('mrr_movement', array_filter(array_merge([
            'amount' => $amount,
            'currency' => $params['currency'] ?? 'USD',
            'movement_type' => $movementType,
            'plan_name' => $planName,
            'user_id' => $userId,
        ], $params)));
    }

    /**
     * Track Annual Recurring Revenue milestone.
     *
     * SaaS revenue convenience for tracking ARR snapshots.
     *
     * @param  float  $arr  Total ARR amount
     * @param  int|null  $customerCount  Active customer count
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackArr(float $arr, ?int $customerCount = null, array $params = []): void
    {
        $this->track('arr_snapshot', array_filter(array_merge([
            'arr' => $arr,
            'currency' => $params['currency'] ?? 'USD',
            'customer_count' => $customerCount,
        ], $params)));
    }

    /**
     * Track a customer churn event with revenue impact.
     *
     * SaaS revenue convenience combining cancellation tracking with
     * MRR impact analysis.
     *
     * @param  string|null  $userId  Churned user ID
     * @param  string|null  $planName  Cancelled plan name
     * @param  float|null  $lostMrr  MRR lost from this churn
     * @param  string|null  $reason  Churn reason
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackChurn(
        ?string $userId = null,
        ?string $planName = null,
        ?float $lostMrr = null,
        ?string $reason = null,
        array $params = [],
    ): void {
        $this->track('churn', array_filter(array_merge([
            'user_id' => $userId,
            'plan_name' => $planName,
            'lost_mrr' => $lostMrr,
            'currency' => $params['currency'] ?? 'USD',
            'reason' => $reason,
        ], $params)));
    }

    /**
     * Track Customer Lifetime Value calculation point.
     *
     * SaaS revenue convenience for tracking LTV at key milestones
     * (payment, renewal, upgrade).
     *
     * @param  float  $ltv  Calculated LTV amount
     * @param  string|null  $userId  User ID
     * @param  string|null  $planName  Current plan
     * @param  string|null  $trigger  What triggered the LTV calculation
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackLtv(
        float $ltv,
        ?string $userId = null,
        ?string $planName = null,
        ?string $trigger = null,
        array $params = [],
    ): void {
        $this->track('ltv_calculated', array_filter(array_merge([
            'ltv' => $ltv,
            'currency' => $params['currency'] ?? 'USD',
            'user_id' => $userId,
            'plan_name' => $planName,
            'trigger' => $trigger,
        ], $params)));
    }

    /**
     * Register a persistent event alias mapping.
     *
     * Registered aliases are stored for the request lifecycle and can be
     * used by trackWithAlias() for automatic resolution. Useful for
     * standardizing event names across teams or microservices.
     *
     * @param  array<string, string>  $aliases  Map of alias → canonical name
     * @return void
     */
    public function registerAliases(array $aliases): void
    {
        foreach ($aliases as $alias => $canonical) {
            $this->aliasRegistry[$alias] = $canonical;
        }
    }

    /**
     * Resolve an event alias to its canonical name.
     *
     * If the name is not a registered alias, it is returned unchanged.
     *
     * @param  string  $name  Event name or alias
     * @return string  Canonical event name
     */
    public function resolveAlias(string $name): string
    {
        return $this->aliasRegistry[$name] ?? $name;
    }

    /**
     * Get all registered event aliases.
     *
     * @return array<string, string>
     */
    public function getAliases(): array
    {
        return $this->aliasRegistry;
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
     * Track an event with debounce — suppresses duplicate events within a time window.
     *
     * Uses AnalyticsEventBuffer to deduplicate events by name + clientId + userId.
     * If the same event was recently flushed, it is silently dropped.
     * Ideal for high-frequency events like scroll_depth, page_view, click.
     *
     * @param  string  $eventName  Event name to track
     * @param  array<string, mixed>  $params  Event parameters
     * @return bool True if the event was dispatched, false if debounced (suppressed)
     *
     * @since 141.0.0
     */
    public function trackDebounced(string $eventName, array $params = []): bool
    {
        $event = new AnalyticsEvent(
            name: $eventName,
            params: $params,
            clientId: $params['client_id'] ?? null,
            userId: $params['user_id'] ?? null,
        );

        try {
            $buffer = app(\ZeroBoiler\Analytics\Services\AnalyticsEventBuffer::class);
        } catch (\Throwable) {
            // Fallback: dispatch directly if buffer unavailable
            $this->trackEvent($event);

            return true;
        }

        if ($buffer->wasRecentlyFlushed($event)) {
            return false;
        }

        $buffer->push($event);
        $this->trackEvent($event);

        return true;
    }

    /**
     * Track an event only once per session/lifetime — prevents duplicates entirely.
     *
     * Uses AnalyticsEventBuffer to check if an equivalent event has already been
     * dispatched. If the event is already in the buffer, it is silently dropped.
     * Ideal for one-time events like sign_up, trial_start, first_value.
     *
     * @param  string  $eventName  Event name to track
     * @param  array<string, mixed>  $params  Event parameters
     * @return bool True if the event was dispatched, false if it was already tracked
     *
     * @since 141.0.0
     */
    public function trackOnce(string $eventName, array $params = []): bool
    {
        $event = new AnalyticsEvent(
            name: $eventName,
            params: $params,
            clientId: $params['client_id'] ?? null,
            userId: $params['user_id'] ?? null,
        );

        try {
            $buffer = app(\ZeroBoiler\Analytics\Services\AnalyticsEventBuffer::class);
        } catch (\Throwable) {
            // Fallback: dispatch directly if buffer unavailable
            $this->trackEvent($event);

            return true;
        }

        if ($buffer->has($event)) {
            return false;
        }

        $buffer->push($event);
        $this->trackEvent($event);

        return true;
    }

    /**
     * Get the event buffer instance for advanced operations.
     *
     * Allows flushing, stats, and manual buffer manipulation.
     *
     * @since 141.0.0
     */
    public function eventBuffer(): \ZeroBoiler\Analytics\Services\AnalyticsEventBuffer
    {
        return app(\ZeroBoiler\Analytics\Services\AnalyticsEventBuffer::class);
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
     * Track a wishlist add event.
     *
     * Convenience method for tracking when a user adds an item
     * to their wishlist. Maps to GA4 'add_to_wishlist' and Meta 'AddToWishlist'.
     *
     * @param  array<string, mixed>  $item  Item data (item_id, item_name, item_category, price, currency)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function wishlist(array $item, array $params = []): void
    {
        $this->track('add_to_wishlist', array_merge([
            'item_id' => $item['item_id'] ?? '',
            'item_name' => $item['item_name'] ?? null,
            'item_category' => $item['item_category'] ?? null,
            'price' => $item['price'] ?? null,
            'currency' => $item['currency'] ?? 'USD',
        ], $params));

        // Track Meta equivalent if enabled
        if ($this->meta->isEnabled()) {
            $this->track('AddToWishlist', [
                'content_ids' => [$item['item_id'] ?? ''],
                'content_name' => $item['item_name'] ?? null,
                'content_type' => 'product',
                'currency' => $item['currency'] ?? 'USD',
                'value' => $item['price'] ?? 0,
            ]);
        }
    }

    /**
     * Track an item selection from a list.
     *
     * Part of the GA4 e-commerce product funnel.
     * Typically fired before view_item or add_to_cart.
     *
     * @param  array<int, array<string, mixed>>  $items  Selected items
     * @param  string|null  $itemListId  Item list identifier (e.g. 'related_products')
     * @param  string|null  $itemListName  Item list name (e.g. 'Related Products')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function selectItem(array $items = [], ?string $itemListId = null, ?string $itemListName = null, array $params = []): void
    {
        $this->track('select_item', array_merge([
            'item_list_id' => $itemListId,
            'item_list_name' => $itemListName,
            'items' => $items,
        ], $params));
    }

    /**
     * Track a promotion click/selection.
     *
     * Part of the GA4 e-commerce promotion funnel.
     * Use this when a user clicks on a promotion banner or link.
     *
     * @param  string|null  $promotionId  Promotion ID
     * @param  string|null  $promotionName  Promotion name (e.g. 'Summer Sale')
     * @param  string|null  $creativeName  Creative name (e.g. 'hero_banner')
     * @param  string|null  $creativeSlot  Creative slot position (e.g. 'homepage_top')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function selectPromotion(?string $promotionId = null, ?string $promotionName = null, ?string $creativeName = null, ?string $creativeSlot = null, array $params = []): void
    {
        $this->track('select_promotion', array_filter(array_merge([
            'promotion_id' => $promotionId,
            'promotion_name' => $promotionName,
            'creative_name' => $creativeName,
            'creative_slot' => $creativeSlot,
        ], $params)));
    }

    /**
     * Track a promotion view.
     *
     * Part of the GA4 e-commerce promotion funnel.
     * Use this when a promotion banner is displayed to the user.
     *
     * @param  string|null  $promotionId  Promotion ID
     * @param  string|null  $promotionName  Promotion name (e.g. 'Summer Sale')
     * @param  string|null  $creativeName  Creative name (e.g. 'hero_banner')
     * @param  string|null  $creativeSlot  Creative slot position (e.g. 'homepage_top')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function viewPromotion(?string $promotionId = null, ?string $promotionName = null, ?string $creativeName = null, ?string $creativeSlot = null, array $params = []): void
    {
        $this->track('view_promotion', array_filter(array_merge([
            'promotion_id' => $promotionId,
            'promotion_name' => $promotionName,
            'creative_name' => $creativeName,
            'creative_slot' => $creativeSlot,
        ], $params)));
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
            'select_item' => null,
            'select_promotion' => null,
            'view_promotion' => null,
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
     * @return array{ecommerce: int, saas: int, engagement: int, marketing: int, infrastructure: int, security: int, uptime: int, total: int}
     */
    public function eventCatalogSummary(): array
    {
        $ecommerce = \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::count();
        $saas = \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::count();
        $engagement = \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::count();
        $marketing = \ZeroBoiler\Analytics\Events\Marketing\MarketingEvents::count();
        $infrastructure = \ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents::count();
        $security = \ZeroBoiler\Analytics\Events\Security\SecurityEvents::count();
        $uptime = \ZeroBoiler\Analytics\Events\Uptime\UptimeEvents::count();

        return [
            'ecommerce' => $ecommerce,
            'saas' => $saas,
            'engagement' => $engagement,
            'marketing' => $marketing,
            'infrastructure' => $infrastructure,
            'security' => $security,
            'uptime' => $uptime,
            'total' => $ecommerce + $saas + $engagement + $marketing + $infrastructure + $security + $uptime,
        ];
    }

    /**
     * Check if a specific event name exists in any catalog.
     */
    public function eventExists(string $eventName): bool
    {
        return \ZeroBoiler\Analytics\Events\EventCatalog::has($eventName);
    }

    /**
     * Validate event parameters against configured field rules.
     *
     * Uses the EventFieldValidator to check required fields, types,
     * ranges, enums, and formats. Returns coerced params on success.
     *
     * @param  string  $eventName  Event name to validate against
     * @param  array<string, mixed>  $params  Event parameters
     * @return array{valid: bool, errors: list<array{field: string, rule: string, message: string, value?: mixed, severity: string}>, coerced_params: array<string, mixed>, coercions: int}
     */
    public function validateEvent(string $eventName, array $params): array
    {
        $validator = new \ZeroBoiler\Analytics\Services\EventFieldValidator(
            rules: $this->config?->get('zeroboiler.analytics.field_validation.rules', []) ?? [],
            globalRules: $this->config?->get('zeroboiler.analytics.field_validation.global_rules', []) ?? [],
            enabled: (bool) ($this->config?->get('zeroboiler.analytics.field_validation.enabled', true) ?? true),
        );

        return $validator->validateRaw($eventName, $params);
    }

    /**
     * Get the category of an event name.
     *
     * @return string|null Category name or null if not found
     */
    public function eventCategory(string $eventName): ?string
    {
        $entry = \ZeroBoiler\Analytics\Events\EventCatalog::get($eventName);

        return $entry['category'] ?? null;
    }

    /**
     * Get the total number of tracked events across all categories.
     */
    public function totalEventCount(): int
    {
        return \ZeroBoiler\Analytics\Events\EventCatalog::count();
    }

    /**
     * Track a JS/client error event (server-side convenience).
     *
     * Useful for logging errors that were captured client-side but need
     * server-side persistence or dispatch to non-client providers.
     *
     * @param  string  $message  Error message
     * @param  string|null  $source  Error source (file/URL)
     * @param  int|null  $line  Line number
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackError(string $message, ?string $source = null, ?int $line = null, array $params = []): void
    {
        $this->track('error', array_filter(array_merge([
            'error_message' => $message,
            'error_source' => $source,
            'error_line' => $line,
        ], $params)));
    }

    /**
     * Track an MRR (Monthly Recurring Revenue) event.
     *
     * Shortcut for the most common SaaS revenue tracking scenario.
     * For advanced revenue tracking (ARR, one-time, add-on, etc.), use
     * RevenueAnalyticsService directly.
     *
     * @param  float  $amount  MRR amount
     * @param  int  $subscribers  Current subscriber count
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function mrr(float $amount, int $subscribers = 0, array $params = []): void
    {
        $this->track('revenue_tracked', array_merge([
            'amount' => $amount,
            'revenue_type' => 'mrr',
            'subscriber_count' => $subscribers,
        ], $params));
    }

    /**
     * Track a revenue event with structured parameters.
     *
     * Convenience method for tracking any revenue-related event (MRR, ARR,
     * one-time, expansion, contraction, reactivation) with proper currency
     * and categorization. Integrates with RevenueAnalyticsService.
     *
     * @param  float  $amount  Revenue amount
     * @param  string  $currency  ISO 4217 currency code (default: USD)
     * @param  string  $revenueType  Revenue type: 'mrr', 'arr', 'one_time', 'expansion', 'contraction', 'reactivation'
     * @param  array<string, mixed>  $params  Additional event parameters (plan_name, customer_id, etc.)
     */
    public function trackRevenue(
        float $amount,
        string $currency = 'USD',
        string $revenueType = 'one_time',
        array $params = [],
    ): void {
        $this->track('revenue_tracked', array_merge([
            'value' => $amount,
            'currency' => $currency,
            'revenue_type' => $revenueType,
        ], $params));
    }

    /**
     * Track a revenue event with HMAC-SHA256 checksum verification.
     *
     * For revenue-critical events (purchases, subscriptions, refunds),
     * generates a cryptographic checksum to prevent replay attacks and
     * ensure data integrity. Uses the revenue_checksum config secret.
     *
     * @param  string  $eventName  Event name (e.g., 'purchase', 'subscription_created', 'refund')
     * @param  float  $amount  Revenue amount
     * @param  string  $currency  ISO 4217 currency code
     * @param  string|null  $transactionId  Transaction/order ID for checksum uniqueness
     * @param  array<string, mixed>  $params  Additional event parameters
     * @return array{checksum: string, event_name: string, amount: float}  Dispatch metadata
     */
    public function trackRevenueEvent(
        string $eventName,
        float $amount,
        string $currency = 'USD',
        ?string $transactionId = null,
        array $params = [],
    ): array {
        $secret = config('zeroboiler.analytics.revenue_checksum.secret', '') ?: config('app.key', '');
        $payload = json_encode([
            'event' => $eventName,
            'amount' => $amount,
            'currency' => $currency,
            'transaction' => $transactionId,
            'ts' => now()->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        $checksum = hash_hmac('sha256', $payload, $secret);

        $this->track($eventName, array_merge([
            'value' => $amount,
            'currency' => $currency,
            'transaction_id' => $transactionId,
            'revenue_checksum' => $checksum,
        ], $params));

        return [
            'checksum' => $checksum,
            'event_name' => $eventName,
            'amount' => $amount,
        ];
    }

    /**
     * Check if tracking is allowed for a given identity.
     *
     * Combines tracking preference checks (per-user opt-out) with consent state.
     * Returns true if both consent is granted and the user has not opted out.
     *
     * @param  string|null  $userId  Authenticated user ID
     * @param  string|null  $clientId  Client tracking ID
     */
    public function isTrackingAllowed(?string $userId = null, ?string $clientId = null): bool
    {
        // Check tracking preferences if service is available
        try {
            $preferences = app(\ZeroBoiler\Analytics\Services\TrackingPreferenceService::class);

            if (! $preferences->shouldTrack($userId, $clientId)) {
                return false;
            }
        } catch (\Throwable) {
            // TrackingPreferenceService not available — continue with consent check only
        }

        // Check consent state
        $consent = $this->getConsent();

        return $consent->hasAnalyticsConsent();
    }

    /**
     * Opt a user out of all tracking (GDPR do-not-track).
     *
     * Persists the opt-out preference via TrackingPreferenceService.
     * This goes beyond consent — even if consent is granted, the user's
     * events will not be dispatched after opting out.
     */
    public function optOut(string $userId): void
    {
        try {
            $preferences = app(\ZeroBoiler\Analytics\Services\TrackingPreferenceService::class);
            $preferences->optOut($userId);
        } catch (\Throwable $e) {
            Log::warning('ZeroBoiler Analytics: failed to persist opt-out', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Opt a user in to tracking (override previous opt-out).
     */
    public function optIn(string $userId): void
    {
        try {
            $preferences = app(\ZeroBoiler\Analytics\Services\TrackingPreferenceService::class);
            $preferences->optIn($userId);
        } catch (\Throwable $e) {
            Log::warning('ZeroBoiler Analytics: failed to persist opt-in', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Suppress tracking for an anonymous client ID.
     *
     * Use before authentication when a user declines tracking.
     * The preference is transferred to the user ID after login/registration.
     */
    public function suppressClient(string $clientId): void
    {
        try {
            $preferences = app(\ZeroBoiler\Analytics\Services\TrackingPreferenceService::class);
            $preferences->suppressClient($clientId);
        } catch (\Throwable) {
            // Silent fail
        }
    }

    /**
     * Transfer client suppression to user ID (called on login/signup).
     *
     * @return bool True if the client was suppressed and the user was opted out
     */
    public function transferClientToUser(string $clientId, string $userId): bool
    {
        try {
            $preferences = app(\ZeroBoiler\Analytics\Services\TrackingPreferenceService::class);

            return $preferences->transferClientToUser($clientId, $userId);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Track an invite sent event (team member, collaborator, referral).
     *
     * @param  string  $inviteType  Type of invitation (e.g. 'team_member', 'collaborator', 'referral')
     * @param  string|null  $role  Assigned role for the invitee
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function inviteSent(string $inviteType, ?string $role = null, array $params = []): void
    {
        $this->track('invite_sent', array_filter(array_merge([
            'invite_type' => $inviteType,
            'role' => $role,
        ], $params)));
    }

    /**
     * Track an external integration connection event.
     *
     * @param  string  $integrationName  Integration name (e.g. 'slack', 'github', 'stripe')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function integrationConnected(string $integrationName, array $params = []): void
    {
        $this->track('integration_connected', array_merge([
            'integration_name' => $integrationName,
        ], $params));
    }

    /**
     * Track a file download event.
     *
     * @param  string  $fileName  Name of the downloaded file
     * @param  string|null  $fileType  File extension or MIME type
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function fileDownload(string $fileName, ?string $fileType = null, array $params = []): void
    {
        $this->track('file_download', array_filter(array_merge([
            'file_name' => $fileName,
            'file_type' => $fileType,
        ], $params)));
    }

    /**
     * Track a video play event.
     *
     * @param  string  $videoTitle  Title or identifier of the video
     * @param  string|null  $videoProvider  Hosting provider (e.g. 'youtube', 'vimeo', 'wistia')
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function videoPlay(string $videoTitle, ?string $videoProvider = null, array $params = []): void
    {
        $this->track('video_play', array_filter(array_merge([
            'video_title' => $videoTitle,
            'video_provider' => $videoProvider,
        ], $params)));
    }

    /**
     * Track a subscription renewal event.
     *
     * Convenience method for tracking recurring subscription renewals.
     * Includes plan name, amount, currency, and billing cycle metadata.
     *
     * @param  string|null  $planName  Current plan name
     * @param  float|null  $amount  Renewal amount
     * @param  string  $currency  ISO 4217 currency code
     * @param  string|null  $billingCycle  'monthly', 'yearly', 'custom'
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function subscriptionRenewal(
        ?string $planName = null,
        ?float $amount = null,
        string $currency = 'USD',
        ?string $billingCycle = null,
        array $params = [],
    ): void {
        $this->track('subscription_renewal', array_filter(array_merge([
            'plan_name' => $planName,
            'amount' => $amount,
            'currency' => $currency,
            'billing_cycle' => $billingCycle,
        ], $params)));
    }

    /**
     * Validate the integrity of the event catalog.
     *
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public function validateCatalog(): array
    {
        return \ZeroBoiler\Analytics\Events\EventCatalog::validate();
    }

    /**
     * Resolve a potentially aliased event name to its canonical form.
     *
     * Uses EventAliasResolver to normalize event names from different
     * sources (JS client, webhooks, integrations) to canonical catalog names.
     *
     * @param  string  $name  The event name to resolve
     * @return string The canonical event name
     */
    public function resolveEventName(string $name): string
    {
        $resolver = $this->getContainer()->make(\ZeroBoiler\Analytics\Services\EventAliasResolver::class);

        return $resolver->resolve($name);
    }

    /**
     * Track an event using a potentially aliased name.
     *
     * Resolves the event name to its canonical form, then dispatches
     * to all enabled providers. Useful when receiving events from
     * external sources that may use non-canonical event names.
     *
     * @param  string  $name  The event name (alias or canonical)
     * @param  array<string, mixed>  $params
     */
    public function trackWithAlias(string $name, array $params = []): void
    {
        $canonical = $this->resolveEventName($name);
        $event = new AnalyticsEvent(name: $canonical, params: $params);

        $this->trackEvent($event);
    }

    /**
     * Get the package version.
     */
    public function version(): string
    {
        return AnalyticsEvent::VERSION;
    }

    /**
     * Get the analytics metrics instance.
     *
     * Use this to access dispatch counts, failure rates, and per-provider
     * statistics for monitoring and debugging.
     */
    public function metrics(): AnalyticsMetrics
    {
        return $this->metrics;
    }

    /**
     * Flush all accumulated metrics (reset counters to zero).
     *
     * Useful for testing, admin dashboards, and periodic metric collection.
     * Returns the pre-flush metrics snapshot for logging/reporting.
     *
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

    /**
     * Get a summary of all enabled providers.
     *
     * @return array<string, array{enabled: bool, id?: string}>
     */
    public function providerSummary(): array
    {
        return [
            'ga4' => [
                'enabled' => $this->ga4->isEnabled(),
                'id' => $this->ga4->isEnabled() ? $this->ga4->getMeasurementId() : null,
            ],
            'gtm' => [
                'enabled' => $this->gtm->isEnabled(),
                'id' => $this->gtm->isEnabled() ? $this->gtm->getContainerId() : null,
            ],
            'meta' => [
                'enabled' => $this->meta->isEnabled(),
                'id' => $this->meta->isEnabled() ? $this->meta->getPixelId() : null,
            ],
            'plausible' => [
                'enabled' => $this->plausible->isEnabled(),
                'id' => $this->plausible->isEnabled() ? $this->plausible->getDomain() : null,
            ],
            'posthog' => [
                'enabled' => $this->posthog->isEnabled(),
                'id' => $this->posthog->isEnabled() ? $this->posthog->getHost() : null,
            ],
            'webhook' => [
                'enabled' => $this->webhook->isEnabled(),
                'id' => $this->webhook->isEnabled() ? $this->webhook->getWebhookUrl() : null,
            ],
            'mixpanel' => [
                'enabled' => $this->mixpanel->isEnabled(),
                'id' => $this->mixpanel->isEnabled() ? $this->mixpanel->getToken() : null,
            ],
            'amplitude' => [
                'enabled' => $this->amplitude->isEnabled(),
                'id' => $this->amplitude->isEnabled() ? $this->amplitude->getApiKey() : null,
            ],
        ];
    }

    /**
     * Register a before-dispatch interceptor.
     *
     * Interceptors receive the AnalyticsEvent before dispatch.
     * Return the event to continue, or null to cancel.
     *
     * @param  callable(AnalyticsEvent): AnalyticsEvent|null  $interceptor
     */
    public function interceptBefore(callable $interceptor): void
    {
        $this->interceptors->before($interceptor);
    }

    /**
     * Register an after-dispatch interceptor.
     *
     * Interceptors receive the AnalyticsEvent and a success flag after dispatch.
     *
     * @param  callable(AnalyticsEvent, bool): void  $interceptor
     */
    public function interceptAfter(callable $interceptor): void
    {
        $this->interceptors->after($interceptor);
    }

    /**
     * Get the event interceptor registry.
     */
    public function interceptors(): EventInterceptorRegistry
    {
        return $this->interceptors;
    }

    /**
     * Get a quick report summary of analytics activity.
     *
     * Returns dispatch counts, success rate, and top event from the metrics instance.
     * Useful for admin dashboards and health checks.
     *
     * @return array{events: int, dispatched: int, failed: int, success_rate: float, top_event: string|null}
     */
    public function reportSummary(): array
    {
        $totalDispatched = $this->metrics->totalDispatched();
        $totalFailed = $this->metrics->totalFailed();
        $total = $totalDispatched + $totalFailed;

        return [
            'events' => $total,
            'dispatched' => $totalDispatched,
            'failed' => $totalFailed,
            'success_rate' => $total > 0 ? round(($totalDispatched / $total) * 100, 2) : 100.0,
            'top_event' => $this->metricsTopProvider(),
        ];
    }

    /**
     * Get the top provider by dispatch count.
     */
    private function metricsTopProvider(): ?string
    {
        $byProvider = $this->metrics->dispatchedByProvider();
        if (empty($byProvider)) {
            return null;
        }

        arsort($byProvider);

        return array_key_first($byProvider);
    }

    /**
     * Get the dead letter queue summary.
     *
     * Returns DLQ statistics from the DeadLetterQueueService.
     * Useful for monitoring failed event recovery.
     *
     * @return array{enabled: bool, strategy: string, total: int, buffered: int, max_size: int, storage_path: string, utilization: float}
     */
    public function dlqSummary(): array
    {
        try {
            $dlq = app(\ZeroBoiler\Analytics\Services\DeadLetterQueueService::class);

            return $dlq->summary();
        } catch (\Throwable) {
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
    }

    /**
     * Get the analytics profile for a user.
     *
     * Returns the full profile array from AnalyticsProfileService.
     *
     * @param  string  $userId
     * @return array{event_counts: array<string, int>, total_events: int, total_value: float, first_seen: string|null, last_seen: string|null, funnel_steps: array<string, bool>, engagement_score: float, plan: string|null, traits: array<string, mixed>}
     */
    public function getProfile(string $userId): array
    {
        try {
            $profile = app(\ZeroBoiler\Analytics\Services\AnalyticsProfileService::class);

            return $profile->getProfile($userId);
        } catch (\Throwable) {
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
    }

    /**
     * Get a user profile summary for API responses.
     *
     * @param  string  $userId
     * @return array{user_id: string, total_events: int, lifetime_value: float, first_seen: string|null, last_seen: string|null, engagement_score: float, plan: string|null, event_types: int, funnel_steps_completed: int, traits: array<string, mixed>}
     */
    public function getProfileSummary(string $userId): array
    {
        try {
            $profile = app(\ZeroBoiler\Analytics\Services\AnalyticsProfileService::class);

            return $profile->getProfileSummary($userId);
        } catch (\Throwable) {
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
    }

    /**
     * Get the event priority calculator for AARRR classification.
     *
     * Provides SaaS analytics maturity scoring, funnel readiness,
     * and onboarding checklist generation.
     */
    public function priorityCalculator(): \ZeroBoiler\Analytics\Services\EventPriorityCalculator
    {
        return new \ZeroBoiler\Analytics\Services\EventPriorityCalculator;
    }

    /**
     * Calculate SaaS analytics maturity score.
     *
     * @return array{score: int, grade: string, details: array<string, mixed>}
     */
    public function maturityScore(): array
    {
        return $this->priorityCalculator()->maturityScore();
    }

    /**
     * Get SaaS analytics onboarding checklist.
     *
     * @return array{checklist: array<string, array<string, array{event: string, tracked: bool, priority: string}>>, summary: array{total: int, tracked: int, completion: float, gaps: list<string>}}
     */
    public function onboardingChecklist(): array
    {
        return $this->priorityCalculator()->onboardingChecklist();
    }

    /**
     * Get funnel conversion readiness scores.
     *
     * @return array{signup_funnel: array, purchase_funnel: array, subscription_funnel: array, overall: float}
     */
    public function funnelReadiness(): array
    {
        return $this->priorityCalculator()->funnelReadiness();
    }

    /**
     * Get product-led growth (PLG) specific events from the catalog.
     *
     * Returns events that directly measure PLG motion: feature adoption,
     * expansion revenue, trial conversion, team expansion, and organic sharing.
     *
     * @return list<array{name: string, class: class-string, ga4: string, category: string}>
     */
    public function plgEvents(): array
    {
        return \ZeroBoiler\Analytics\Events\EventCatalog::plgEvents();
    }

    /**
     * Track a feature adoption event (PLG activation milestone).
     *
     * Fires once when a user first adopts a high-value feature.
     *
     * @param  string  $featureName  Feature identifier
     * @param  string|null  $category  Feature category (core/premium/integration)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function featureAdopted(string $featureName, ?string $category = null, array $params = []): void
    {
        $this->trackEvent(new \ZeroBoiler\Analytics\Events\SaaS\FeatureAdoptedEvent(
            featureName: $featureName,
            category: $category,
            params: $params,
        ));
    }

    /**
     * Track expansion revenue from an existing customer.
     *
     * Captures add-on purchases, seat expansion, usage overages,
     * and cross-sell conversions for net revenue retention analysis.
     *
     * @param  float  $amount  Expansion revenue amount
     * @param  string  $source  Expansion source (addon/seat_expansion/usage_overage/cross_sell)
     * @param  string|null  $currency  Currency code
     */
    public function expansionRevenue(float $amount, string $source, ?string $currency = null): void
    {
        $this->trackEvent(new \ZeroBoiler\Analytics\Events\SaaS\ExpansionRevenueEvent(
            amount: $amount,
            source: $source,
            currency: $currency,
        ));
    }

    /**
     * Track a data export action.
     *
     * Tracks user data exports for GDPR compliance monitoring,
     * churn prediction (exports often precede cancellation),
     * and data portability analytics.
     *
     * @param  string  $format  Export format (csv, json, pdf, xlsx)
     * @param  string|null  $resource  Type of data exported (reports, users, transactions)
     * @param  int|null  $recordCount  Number of records exported
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function exportEvent(string $format, ?string $resource = null, ?int $recordCount = null, array $params = []): void
    {
        $this->trackEvent(new \ZeroBoiler\Analytics\Events\SaaS\ExportEvent(
            format: $format,
            resource: $resource,
            recordCount: $recordCount,
            params: $params,
        ));
    }

    /**
     * Track a data import action.
     *
     * Tracks bulk data imports for onboarding optimization,
     * power user identification, and data migration monitoring.
     *
     * @param  string  $format  Import format (csv, json, xlsx)
     * @param  string|null  $resource  Type of data imported (contacts, products, transactions)
     * @param  int|null  $recordCount  Number of records imported
     * @param  bool|null  $success  Whether the import succeeded
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function importEvent(string $format, ?string $resource = null, ?int $recordCount = null, ?bool $success = null, array $params = []): void
    {
        $this->trackEvent(new \ZeroBoiler\Analytics\Events\SaaS\ImportEvent(
            format: $format,
            resource: $resource,
            recordCount: $recordCount,
            success: $success,
            params: $params,
        ));
    }

    /**
     * Track a funnel step with automatic metadata enrichment.
     *
     * Convenience method for tracking multi-step funnel progression
     * (signup, checkout, onboarding, etc.) with funnel name and step metadata.
     *
     * @param  string  $funnelName  Funnel identifier (e.g. 'signup', 'checkout', 'trial')
     * @param  string  $stepName  Step within the funnel (e.g. 'form_start', 'payment_info')
     * @param  int|null  $stepNumber  Sequential step number (1-indexed)
     * @param  int|null  $totalSteps  Total number of steps in the funnel
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function trackFunnel(string $funnelName, string $stepName, ?int $stepNumber = null, ?int $totalSteps = null, array $params = []): void
    {
        $this->track('funnel_step', array_filter([
            'funnel_name' => $funnelName,
            'step_name' => $stepName,
            'step_number' => $stepNumber,
            'total_steps' => $totalSteps,
        ] + $params, fn (mixed $v): bool => $v !== null && $v !== ''));
    }

    /**
     * Track funnel progress with cache-persisted state.
     *
     * Delegates to FunnelProgressTracker::track() which manages per-user,
     * per-funnel state in cache. Automatically detects advancement, regression,
     * and completion. Dispatches funnel_step and funnel_completed events.
     *
     * @param  string  $funnelName  Funnel identifier (e.g. 'signup', 'checkout', 'onboarding')
     * @param  string  $stepName  Current step identifier (e.g. 'form_submit', 'payment_info')
     * @param  string  $identity  User ID or client ID for tracking
     * @param  int  $stepNumber  Current step number (1-indexed)
     * @param  int  $totalSteps  Total steps in the funnel
     * @param  array<string, mixed>  $params  Additional event parameters
     * @return array{funnel_name: string, step_name: string, step_number: int, total_steps: int, completion_pct: float, is_complete: bool, is_advancement: bool, is_regression: bool, elapsed_seconds: float|null, previous_step: string|null, previous_step_number: int|null, first_seen: string|null, last_updated: string}
     */
    public function funnelProgress(
        string $funnelName,
        string $stepName,
        string $identity,
        int $stepNumber,
        int $totalSteps,
        array $params = [],
    ): array {
        /** @var \ZeroBoiler\Analytics\Services\FunnelProgressTracker $tracker */
        $tracker = $this->getContainer()->make(\ZeroBoiler\Analytics\Services\FunnelProgressTracker::class);

        return $tracker->track($funnelName, $stepName, $identity, $stepNumber, $totalSteps, $params);
    }

    /**
     * Get the quick-start event set for day-one SaaS instrumentation.
     *
     * Returns the 12 essential events every SaaS should track immediately.
     * Use for onboarding guidance and instrumentation checklists.
     *
     * @return array{events: list<array<string, mixed>>, count: int, categories: array<string, int>, funnel_coverage: array<string, bool>}
     */
    public function quickStartEvents(): array
    {
        return \ZeroBoiler\Analytics\Events\EventCatalog::quickStart();
    }

    /**
     * Track funnel progress with completion percentage and auto-detection.
     *
     * Delegates to FunnelProgressTracker for persistent state tracking.
     * Tracks step advancement, regression, completion, and timing.
     *
     * @param  string  $funnelName  Funnel identifier (e.g. 'signup', 'checkout')
     * @param  string  $stepName  Current step identifier
     * @param  string  $identity  User ID or client ID
     * @param  int  $stepNumber  Current step (1-indexed)
     * @param  int  $totalSteps  Total steps in funnel
     * @param  array<string, mixed>  $params  Additional parameters
     * @return array{funnel_name: string, step_name: string, step_number: int, total_steps: int, completion_pct: float, is_complete: bool, is_advancement: bool, is_regression: bool, elapsed_seconds: float|null, previous_step: string|null, previous_step_number: int|null, first_seen: string|null, last_updated: string}
     */
    public function trackFunnelProgress(
        string $funnelName,
        string $stepName,
        string $identity,
        int $stepNumber,
        int $totalSteps,
        array $params = [],
    ): array {
        try {
            $tracker = $this->getContainer()->make(
                \ZeroBoiler\Analytics\Services\FunnelProgressTracker::class,
            );

            return $tracker->track(
                funnelName: $funnelName,
                stepName: $stepName,
                identity: $identity,
                stepNumber: $stepNumber,
                totalSteps: $totalSteps,
                params: $params,
            );
        } catch (\Throwable) {
            // Fallback to basic track if tracker unavailable
            $this->trackFunnel($funnelName, $stepName, $stepNumber, $totalSteps, $params);

            return [
                'funnel_name' => $funnelName,
                'step_name' => $stepName,
                'step_number' => $stepNumber,
                'total_steps' => $totalSteps,
                'completion_pct' => $totalSteps > 0 ? round(($stepNumber / $totalSteps) * 100, 1) : 0.0,
                'is_complete' => $stepNumber >= $totalSteps,
                'is_advancement' => false,
                'is_regression' => false,
                'elapsed_seconds' => null,
                'previous_step' => null,
                'previous_step_number' => null,
                'first_seen' => null,
                'last_updated' => (string) now()->toIso8601String(),
            ];
        }
    }

    /**
     * Get pre-built funnel templates.
     *
     * @return array<string, array{name: string, description: string, steps: list<array{event: string, label: string}>, total_steps: int}>
     */
    public function funnelTemplates(): array
    {
        return \ZeroBoiler\Analytics\Events\EventCatalog::funnelTemplates();
    }

    /**
     * Get a specific funnel template by name.
     *
     * @return array{name: string, description: string, steps: list<array{event: string, label: string}>, total_steps: int}|null
     */
    public function funnelTemplate(string $name): ?array
    {
        return \ZeroBoiler\Analytics\Events\EventCatalog::funnelTemplate($name);
    }

    // ── SaaS Lifecycle Convenience Methods (v2.96.0) ──────────────────

    /**
     * Track a user sign-up event.
     *
     * Fires the core SaaS acquisition event. Typically called from
     * Registered listener or RegisterController.
     *
     * @param  string|null  $method  Registration method (email, google, github, sso)
     * @param  array<string, mixed>  $params  Additional parameters (invite_id, referral_code, etc.)
     */
    public function signUp(?string $method = null, array $params = []): void
    {
        $this->track('sign_up', array_filter(array_merge([
            'method' => $method,
        ], $params)));
    }

    /**
     * Track a user login event.
     *
     * Convenience wrapper that also links client ↔ user identity.
     *
     * @param  string  $userId  Authenticated user ID
     * @param  string|null  $clientId  Client tracking ID (for identity linking)
     * @param  string|null  $method  Auth method (email, oauth, sso, token)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function login(string $userId, ?string $clientId = null, ?string $method = null, array $params = []): void
    {
        $this->track('login', array_filter(array_merge([
            'user_id' => $userId,
            'method' => $method,
        ], $params)));

        // Auto-link identity if client ID provided
        if ($clientId !== null && $clientId !== '') {
            $this->identify($userId, $clientId);
        }
    }

    /**
     * Track a trial start event.
     *
     * Fires when a user begins a free trial. Essential for conversion funnel analysis.
     *
     * @param  string|null  $planName  Trial plan name
     * @param  int|null  $trialDays  Trial duration in days
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trialStart(?string $planName = null, ?int $trialDays = null, array $params = []): void
    {
        $this->track('start_trial', array_filter(array_merge([
            'plan_name' => $planName,
            'trial_days' => $trialDays,
        ], $params)));
    }

    /**
     * Track a subscription creation event.
     *
     * Fires when a user subscribes to a paid plan. Use for MRR tracking
     * and subscription funnel analysis.
     *
     * @param  string|null  $planName  Plan name (e.g. 'pro', 'enterprise')
     * @param  float|null  $amount  Subscription amount
     * @param  string  $currency  ISO 4217 currency code
     * @param  string|null  $billingCycle  'monthly', 'yearly', 'custom'
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function subscription(
        ?string $planName = null,
        ?float $amount = null,
        string $currency = 'USD',
        ?string $billingCycle = null,
        array $params = [],
    ): void {
        $this->track('subscribe', array_filter(array_merge([
            'plan_name' => $planName,
            'amount' => $amount,
            'currency' => $currency,
            'billing_cycle' => $billingCycle,
        ], $params)));
    }

    /**
     * Track a plan upgrade event.
     *
     * Fires when a user upgrades to a higher plan. Used for expansion
     * revenue analysis and plan migration funnels.
     *
     * @param  string  $fromPlan  Previous plan name
     * @param  string  $toPlan  New plan name
     * @param  float|null  $priceDifference  Additional cost
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function planUpgrade(string $fromPlan, string $toPlan, ?float $priceDifference = null, array $params = []): void
    {
        $this->track('plan_upgrade', array_filter(array_merge([
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
            'price_difference' => $priceDifference,
        ], $params)));
    }

    /**
     * Track a trial conversion event.
     *
     * Fires when a trial user converts to a paid subscription.
     * Critical for trial-to-paid conversion funnel analysis.
     *
     * @param  string|null  $planName  Converted plan name
     * @param  float|null  $amount  Subscription amount
     * @param  string  $currency  ISO 4217 currency code
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trialConverted(?string $planName = null, ?float $amount = null, string $currency = 'USD', array $params = []): void
    {
        $this->track('trial_converted', array_filter(array_merge([
            'plan_name' => $planName,
            'amount' => $amount,
            'currency' => $currency,
        ], $params)));
    }

    /**
     * Track a subscription cancellation event.
     *
     * Fires when a user cancels their subscription. Critical for
     * churn analysis and retention funnel optimization.
     *
     * @param  string|null  $planName  Cancelled plan name
     * @param  string|null  $reason  Cancellation reason (price, features, competitor, unused)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function cancellation(?string $planName = null, ?string $reason = null, array $params = []): void
    {
        $this->track('cancellation', array_filter(array_merge([
            'plan_name' => $planName,
            'reason' => $reason,
        ], $params)));
    }

    /**
     * Run a comprehensive health check diagnostic.
     *
     * Returns a full diagnostic report covering provider configuration,
     * catalog coverage, AARRR completeness, identity tracking, queue setup,
     * GDPR compliance, consent mode, lifecycle mapper, auto-tracking, dedup,
     * API, and pipeline configuration.
     *
     * @return array{status: string, version: string, overall_score: int, timestamp: string, subsystems: array<string, mixed>, recommendations: list<array{priority: string, category: string, message: string}>}
     */
    public function healthCheck(): array
    {
        $container = $this->getContainer();

        /** @var \ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService $service */
        $service = $container->make(\ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService::class);

        return $service->run();
    }

    /**
     * Quick ping — returns version and provider count.
     *
     * @return array{status: string, version: string, providers_configured: int, catalog_size: int}
     */
    public function ping(): array
    {
        $container = $this->getContainer();

        /** @var \ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService $service */
        $service = $container->make(\ZeroBoiler\Analytics\Services\AnalyticsHealthCheckService::class);

        return $service->ping();
    }

    /**
     * Track a complete SaaS signup-to-paid conversion funnel.
     *
     * Convenience method that dispatches the full SaaS acquisition
     * sequence: sign_up → start_trial → subscribe in a single call.
     * Useful for landing pages and onboarding flows.
     *
     * @param  string|null  $planName  Plan name
     * @param  float|null  $amount  Subscription amount
     * @param  string  $currency  Currency code
     * @param  array{method?: string|null, trial_days?: int|null, billing_cycle?: string|null, skip_trial?: bool}  $options  Options
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function trackSaaSAcquisition(
        ?string $planName = null,
        ?float $amount = null,
        string $currency = 'USD',
        array $options = [],
        array $params = [],
    ): void {
        $this->signUp($options['method'] ?? null, $params);

        $skipTrial = (bool) ($options['skip_trial'] ?? false);
        if (! $skipTrial) {
            $this->trialStart($planName, $options['trial_days'] ?? null, $params);
        }

        if ($amount !== null) {
            $this->subscription(
                $planName,
                $amount,
                $currency,
                $options['billing_cycle'] ?? null,
                $params,
            );
        }
    }

    // ── Event Orchestration (v2.99.0) ─────────────────────────────────────

    /**
     * Start a multi-step orchestration pipeline.
     *
     * @param  array<string, mixed>  $params
     * @return array{pipeline: string, status: string, started_at: string, steps: int, completed_steps: int, identity: string}
     */
    public function orchestrate(
        string $pipelineName,
        string $clientId,
        ?string $userId = null,
        array $params = [],
    ): array {
        $service = $this->resolveOrchestrationService();

        return $service->startPipeline($pipelineName, $clientId, $userId, $params);
    }

    /**
     * Advance to the next step in an orchestration pipeline.
     *
     * @param  array<string, mixed>  $params
     * @return array{step: string, event: string, pipeline_status: string, completed_steps: list<string>, remaining_steps: int, is_complete: bool}
     */
    public function orchestrateAdvance(
        string $pipelineName,
        string $stepName,
        string $clientId,
        ?string $userId = null,
        array $params = [],
    ): array {
        $service = $this->resolveOrchestrationService();

        return $service->advanceStep($pipelineName, $stepName, $clientId, $userId, $params);
    }

    /**
     * Get the progress percentage of an orchestration pipeline.
     */
    public function orchestrateProgress(
        string $pipelineName,
        string $clientId,
        ?string $userId = null,
    ): float {
        $service = $this->resolveOrchestrationService();

        return $service->getProgress($pipelineName, $clientId, $userId);
    }

    /**
     * Complete an orchestration pipeline manually.
     *
     * @param  array<string, mixed>  $params
     * @return array{pipeline: string, status: string, completed_at: string, completed_steps: list<string>}
     */
    public function orchestrateComplete(
        string $pipelineName,
        string $clientId,
        ?string $userId = null,
        array $params = [],
    ): array {
        $service = $this->resolveOrchestrationService();

        return $service->completePipeline($pipelineName, $clientId, $userId, $params);
    }

    /**
     * Cancel an active orchestration pipeline.
     *
     * @param  array<string, mixed>  $params
     */
    public function orchestrateCancel(
        string $pipelineName,
        string $clientId,
        ?string $userId = null,
        string $reason = '',
        array $params = [],
    ): array {
        $service = $this->resolveOrchestrationService();

        return $service->cancelPipeline($pipelineName, $clientId, $userId, $reason, $params);
    }

    /**
     * Get the next expected step for an active orchestration pipeline.
     *
     * @return array{name: string, event: string, required: bool}|null
     */
    public function orchestrateNextStep(
        string $pipelineName,
        string $clientId,
        ?string $userId = null,
    ): ?array {
        $service = $this->resolveOrchestrationService();

        return $service->getNextStep($pipelineName, $clientId, $userId);
    }

    // ── Automated Insights (v2.99.0) ────────────────────────────────────────

    /**
     * Generate a full automated insight report.
     *
     * Analyzes tracked events for trends, anomalies, funnel drop-offs,
     * engagement patterns, and conversion opportunities.
     *
     * @return array{generated_at: string, insights: list<array{type: string, category: string, title: string, description: string, severity: string, metric: string|null, value: mixed|null, recommendation: string|null}>, summary: array{total: int, by_type: array<string, int>, by_severity: array<string, int>}}
     */
    public function insightReport(): array
    {
        $service = $this->resolveInsightAggregator();

        return $service->generateReport();
    }

    // ── SaaS Identity Convenience (v10.9.0) ────────────────────────────────

    /**
     * Track a complete SaaS identity linking event.
     *
     * Combines identify + set_user_properties + user_profile enrichment
     * in a single call. Designed for login/signup flows where you want to
     * establish the full user identity across all providers simultaneously.
     *
     * Links client_id ↔ user_id, sets user traits (name, email, plan, company),
     * and updates the analytics profile in one atomic operation.
     *
     * @param  string  $userId  Authenticated user ID
     * @param  string  $clientId  Client tracking ID (from cookie)
     * @param  array<string, mixed>  $traits  User properties (name, email, plan, company, mrr, etc.)
     */
    public function trackSaaSIdentity(string $userId, string $clientId, array $traits = []): void
    {
        $event = new AnalyticsEvent(
            name: 'identify',
            params: array_merge([
                'user_id' => $userId,
                'client_id' => $clientId,
                'identity_type' => 'saas_auto_link',
            ], $traits),
            clientId: $clientId,
            userId: $userId,
        );

        $this->trackEvent($event);

        // Set user properties on all providers that support it
        if (! empty($traits)) {
            $this->setUserProperties($traits, $userId);
        }

        // Persist identity link via IdentityResolutionService
        try {
            $service = $this->getContainer()->make(
                \ZeroBoiler\Analytics\Services\IdentityResolutionService::class,
            );
            $service->link($clientId, $userId);
        } catch (\Throwable) {
            // Service not available — identity event still dispatched
        }
    }

    // ── B2B Group Analytics (v9.5.0) ───────────────────────────────────────

    /**
     * Identify a B2B group/account with traits.
     *
     * Associates a company, organization, or workspace with its properties.
     * Used for B2B SaaS account-level analytics (Segment/Mixpanel group spec).
     *
     * @param  string  $groupId  Group identifier (company ID, workspace ID)
     * @param  array<string, mixed>  $traits  Group properties (name, industry, plan, mrr, etc.)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function group(string $groupId, array $traits = [], array $params = []): void
    {
        $this->track('group_identify', array_merge([
            'group_id' => $groupId,
            'traits' => $traits,
        ], $params));

        // Persist group traits via GroupAnalyticsService
        try {
            $service = $this->getContainer()->make(\ZeroBoiler\Analytics\Services\GroupAnalyticsService::class);
            $service->identify($groupId, $traits);
        } catch (\Throwable) {
            // Service not available — event still dispatched
        }
    }

    /**
     * Add a user to a B2B group.
     *
     * Creates a membership link between user and group for account-level analytics.
     *
     * @param  string  $userId  User identifier
     * @param  string  $groupId  Group identifier
     * @param  string|null  $role  User's role in the group
     * @param  array<string, mixed>  $traits  Membership traits
     */
    public function groupAddMember(string $userId, string $groupId, ?string $role = null, array $traits = []): void
    {
        try {
            $service = $this->getContainer()->make(\ZeroBoiler\Analytics\Services\GroupAnalyticsService::class);
            $service->addMember($userId, $groupId, $role, $traits);
        } catch (\Throwable) {
            // Service not available
        }

        $this->track('group_member_added', array_filter([
            'group_id' => $groupId,
            'user_id' => $userId,
            'role' => $role,
        ]));
    }

    /**
     * Get group properties for a given group ID.
     *
     * @param  string  $groupId  Group identifier
     * @return array{group_id: string, traits: array<string, mixed>, member_count: int, updated_at: string|null}
     */
    public function getGroup(string $groupId): array
    {
        try {
            $service = $this->getContainer()->make(\ZeroBoiler\Analytics\Services\GroupAnalyticsService::class);

            return $service->getGroup($groupId);
        } catch (\Throwable) {
            return [
                'group_id' => $groupId,
                'traits' => [],
                'member_count' => 0,
                'updated_at' => null,
            ];
        }
    }

    // ── Context & Flushing (v17.0.0) ──────────────────────────────────────

    /**
     * Get the request-scoped AnalyticsContextBus.
     *
     * Returns the singleton context bus that auto-collects request context
     * (device, session, UTM, tenant, locale) for event enrichment.
     *
     * @return AnalyticsContextBus
     */
    public function contextBus(): AnalyticsContextBus
    {
        try {
            return $this->getContainer()->make(AnalyticsContextBus::class);
        } catch (\Throwable) {
            // Return a fresh instance if container resolution fails
            return new AnalyticsContextBus($this->getContainer()->make(\Illuminate\Contracts\Config\Repository::class));
        }
    }

    /**
     * Track an event with automatic context enrichment.
     *
     * Merges the current request context (device, session, UTM, tenant, locale)
     * into the event params before dispatching. Context params use the
     * `_ctx_` prefix to avoid conflicts with user-defined params.
     *
     * @param  string  $eventName
     * @param  array<string, mixed>  $params
     * @param  bool  $enrichContext  Whether to auto-enrich with request context
     * @return void
     */
    public function trackWithContext(string $eventName, array $params = [], bool $enrichContext = true): void
    {
        $finalParams = $params;

        if ($enrichContext) {
            $contextParams = $this->contextBus()->asEventParams();
            $finalParams = array_merge($params, $contextParams);
        }

        $this->trackEvent(new AnalyticsEvent(name: $eventName, params: $finalParams));
    }

    /**
     * Get the EventFlushingService for buffered dispatch.
     *
     * Use this when you want to buffer events and flush them later:
     *   $flusher = Analytics::getFlushingService();
     *   $flusher->setStrategy(EventFlushingService::STRATEGY_BUFFERED);
     *   $flusher->process($event);
     *   // ... later
     *   $flusher->flush();
     *
     * @return EventFlushingService
     */
    public function getFlushingService(): EventFlushingService
    {
        return $this->getContainer()->make(EventFlushingService::class);
    }

    // ── Service Resolvers ─────────────────────────────────────────────────

    private function resolveOrchestrationService(): Services\EventOrchestrationService
    {
        return $this->getContainer()->make(Services\EventOrchestrationService::class);
    }

    private function resolveInsightAggregator(): Services\AnalyticsInsightAggregator
    {
        return $this->getContainer()->make(Services\AnalyticsInsightAggregator::class);
    }

    // ── PLG Scoring (v6.0.0) ──────────────────────────────────────────────

    /**
     * Compute PLG score for an identity.
     *
     * Returns a composite Product-Led Growth score with activation,
     * engagement, retention, and feature breadth dimensions.
     *
     * @param  string  $identity  User ID or client tracking ID
     * @return array{score: float, grade: string, activation: float, engagement: float, retention: float, feature_breadth: float, segment: string, signals: list<string>, identity: string, computed_at: string}
     */
    public function plgScore(string $identity): array
    {
        /** @var Services\PLGScoringService $service */
        $service = $this->getContainer()->make(Services\PLGScoringService::class);

        return $service->score($identity);
    }

    /**
     * Get PLG aggregate statistics (average score, grade distribution).
     *
     * @return array{avg_score: float, total_cached: int, grade_distribution: array<string, int>}
     */
    public function plgAggregate(): array
    {
        /** @var Services\PLGScoringService $service */
        $service = $this->getContainer()->make(Services\PLGScoringService::class);

        return $service->aggregateStats();
    }

    /**
     * Invalidate cached PLG score for an identity.
     */
    public function plgInvalidate(string $identity): void
    {
        /** @var Services\PLGScoringService $service */
        $service = $this->getContainer()->make(Services\PLGScoringService::class);
        $service->invalidateScore($identity);
    }

    // ── Event Time-Series (v6.0.0) ────────────────────────────────────────

    /**
     * Get aggregated event time-series data for a period.
     *
     * Returns event counts, top events, category breakdown,
     * trend direction, and moving averages.
     *
     * @param  string  $period  Time period ('5m', '15m', '1h', '6h', '1d', '7d', '30d')
     * @return array{total_events: int, unique_identities: int, top_events: list<array{event: string, count: int}>, category_breakdown: array<string, int>, trend: array{direction: string, change_pct: float, current: int, previous: int}, moving_avg: float, period: string, computed_at: string}
     */
    public function timeSeries(string $period = '1h'): array
    {
        /** @var Services\EventTimeSeriesService $service */
        $service = $this->getContainer()->make(Services\EventTimeSeriesService::class);

        return $service->aggregate($period);
    }

    /**
     * Get full time-series dashboard data across all periods.
     *
     * @return array<string, array{total_events: int, unique_identities: int, top_events: list<array{event: string, count: int}>, category_breakdown: array<string, int>, trend: array, moving_avg: float, period: string}>
     */
    public function timeSeriesDashboard(): array
    {
        /** @var Services\EventTimeSeriesService $service */
        $service = $this->getContainer()->make(Services\EventTimeSeriesService::class);

        return $service->dashboard();
    }

    /**
     * Compare two time periods side-by-side.
     *
     * @param  string  $currentPeriod  Current period
     * @param  string  $previousPeriod  Previous comparison period
     * @return array{current: array, previous: array, delta: array{events: int, identities: int, pct_change: float}}
     */
    public function timeSeriesCompare(string $currentPeriod, string $previousPeriod): array
    {
        /** @var Services\EventTimeSeriesService $service */
        $service = $this->getContainer()->make(Services\EventTimeSeriesService::class);

        return $service->compare($currentPeriod, $previousPeriod);
    }

    // ─── E-Commerce Shorthand Methods (v26.0.0) ─────────────────────────

    /**
     * Track a view_item event.
     *
     * @param  array<string, mixed>  $item  Item data (item_id, item_name, item_category, price, currency, ...)
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function viewItem(array $item, array $params = []): void
    {
        $this->track('view_item', array_merge($params, $item));
    }

    /**
     * Track an add_to_cart event.
     *
     * @param  array<string, mixed>  $item  Item data (item_id, item_name, item_category, price, quantity, currency, ...)
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function addToCart(array $item, array $params = []): void
    {
        $this->track('add_to_cart', array_merge($params, $item));
    }

    /**
     * Track a remove_from_cart event.
     *
     * @param  array<string, mixed>  $item  Item data (item_id, item_name, price, quantity, currency, ...)
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function removeFromCart(array $item, array $params = []): void
    {
        $this->track('remove_from_cart', array_merge($params, $item));
    }

    /**
     * Track a view_cart event.
     *
     * @param  list<array<string, mixed>>  $items  Cart items
     * @param  float|null  $value  Cart total value
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function viewCart(array $items = [], ?float $value = null, array $params = []): void
    {
        $payload = $params;
        if ($items !== []) {
            $payload['items'] = $items;
        }
        if ($value !== null) {
            $payload['value'] = $value;
        }
        $this->track('view_cart', $payload);
    }

    /**
     * Track a begin_checkout event.
     *
     * @param  list<array<string, mixed>>  $items  Cart items
     * @param  float|null  $value  Cart total value
     * @param  array<string, mixed>  $params  Additional event parameters (currency, coupon, ...)
     */
    public function beginCheckout(array $items = [], ?float $value = null, array $params = []): void
    {
        $payload = $params;
        if ($items !== []) {
            $payload['items'] = $items;
        }
        if ($value !== null) {
            $payload['value'] = $value;
        }
        $this->track('begin_checkout', $payload);
    }

    /**
     * Track an add_payment_info event.
     *
     * @param  string|null  $paymentType  Payment type (credit_card, paypal, ...)
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function addPaymentInfo(?string $paymentType = null, array $params = []): void
    {
        $payload = $params;
        if ($paymentType !== null) {
            $payload['payment_type'] = $paymentType;
        }
        $this->track('add_payment_info', $payload);
    }

    /**
     * Track a refund event.
     *
     * @param  string  $transactionId  Original transaction ID
     * @param  float  $value  Refund amount
     * @param  array<string, mixed>  $params  Additional event parameters (currency, items, ...)
     */
    public function refund(string $transactionId, float $value, array $params = []): void
    {
        $this->track('refund', array_merge($params, [
            'transaction_id' => $transactionId,
            'value' => $value,
        ]));
    }

    /**
     * Track an abandoned_cart event.
     *
     * @param  list<array<string, mixed>>  $items  Cart items left behind
     * @param  float|null  $value  Cart total value
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function abandonedCart(array $items = [], ?float $value = null, array $params = []): void
    {
        $payload = $params;
        if ($items !== []) {
            $payload['items'] = $items;
        }
        if ($value !== null) {
            $payload['value'] = $value;
        }
        $this->track('abandoned_cart', $payload);
    }

    /**
     * Track a checkout_abandon event.
     *
     * @param  int|null  $step  Checkout step where user abandoned
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function checkoutAbandon(?int $step = null, array $params = []): void
    {
        $payload = $params;
        if ($step !== null) {
            $payload['checkout_step'] = $step;
        }
        $this->track('checkout_abandon', $payload);
    }

    /**
     * Track a checkout_step event.
     *
     * @param  int  $step  Current checkout step number
     * @param  string|null  $stepName  Human-readable step name
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function checkoutStep(int $step, ?string $stepName = null, array $params = []): void
    {
        $payload = $params;
        $payload['checkout_step'] = $step;
        if ($stepName !== null) {
            $payload['checkout_step_name'] = $stepName;
        }
        $this->track('checkout_step', $payload);
    }

    // ─── Engagement Shorthand Methods (v26.0.0) ────────────────────────

    /**
     * Track a scroll_depth event.
     *
     * @param  int  $percent  Scroll depth percentage (0-100)
     * @param  string|null  $url  Page URL where scroll occurred
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function scrollDepth(int $percent, ?string $url = null, array $params = []): void
    {
        $this->track('scroll_depth', array_merge($params, [
            'percent' => $percent,
            'url' => $url,
        ]));
    }

    /**
     * Track a click event.
     *
     * @param  string  $target  Clicked element identifier (CSS selector, button ID, ...)
     * @param  string|null  $url  Page URL where click occurred
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function click(string $target, ?string $url = null, array $params = []): void
    {
        $this->track('click', array_merge($params, [
            'target' => $target,
            'url' => $url,
        ]));
    }

    /**
     * Track a form_start event.
     *
     * @param  string|null  $formId  Form identifier
     * @param  string|null  $formName  Human-readable form name
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function formStart(?string $formId = null, ?string $formName = null, array $params = []): void
    {
        $this->track('form_start', array_merge($params, [
            'form_id' => $formId,
            'form_name' => $formName,
        ]));
    }

    /**
     * Track a form_submit event.
     *
     * @param  string|null  $formId  Form identifier
     * @param  string|null  $formName  Human-readable form name
     * @param  bool|null  $success  Whether submission succeeded
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function formSubmit(?string $formId = null, ?string $formName = null, ?bool $success = null, array $params = []): void
    {
        $this->track('form_submit', array_merge($params, array_filter([
            'form_id' => $formId,
            'form_name' => $formName,
            'success' => $success,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a search event.
     *
     * @param  string  $query  Search query string
     * @param  int|null  $resultsCount  Number of results returned
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function search(string $query, ?int $resultsCount = null, array $params = []): void
    {
        $payload = $params;
        $payload['search_term'] = $query;
        if ($resultsCount !== null) {
            $payload['results_count'] = $resultsCount;
        }
        $this->track('search', $payload);
    }

    /**
     * Track a share event.
     *
     * @param  string  $method  Share method (email, twitter, facebook, link, ...)
     * @param  string|null  $contentType  Type of shared content (article, product, ...)
     * @param  string|null  $itemId  Shared item identifier
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function share(string $method, ?string $contentType = null, ?string $itemId = null, array $params = []): void
    {
        $this->track('share', array_merge($params, array_filter([
            'method' => $method,
            'content_type' => $contentType,
            'item_id' => $itemId,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track an outbound_click event.
     *
     * @param  string  $url  Destination URL
     * @param  string|null  $label  Link label or text
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function outboundClick(string $url, ?string $label = null, array $params = []): void
    {
        $this->track('outbound_click', array_merge($params, array_filter([
            'url' => $url,
            'label' => $label,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a content_engagement event.
     *
     * @param  string  $contentType  Type of content (article, video, podcast, ...)
     * @param  string|null  $contentId  Content identifier
     * @param  int|null  $durationSeconds  Time spent engaging (seconds)
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function contentEngagement(string $contentType, ?string $contentId = null, ?int $durationSeconds = null, array $params = []): void
    {
        $this->track('content_engagement', array_merge($params, array_filter([
            'content_type' => $contentType,
            'content_id' => $contentId,
            'duration_seconds' => $durationSeconds,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track an onboarding_step event.
     *
     * @param  int  $step  Onboarding step number
     * @param  string|null  $stepName  Step name
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function onboardingStep(int $step, ?string $stepName = null, array $params = []): void
    {
        $payload = $params;
        $payload['step'] = $step;
        if ($stepName !== null) {
            $payload['step_name'] = $stepName;
        }
        $this->track('onboarding_step', $payload);
    }

    /**
     * Track an onboarding_completed event.
     *
     * @param  int|null  $totalSteps  Total onboarding steps completed
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function onboardingCompleted(?int $totalSteps = null, array $params = []): void
    {
        $payload = $params;
        if ($totalSteps !== null) {
            $payload['total_steps'] = $totalSteps;
        }
        $this->track('onboarding_completed', $payload);
    }

    /**
     * Track a goal_conversion event.
     *
     * @param  string  $goalName  Goal identifier
     * @param  float|null  $value  Goal value (revenue or score)
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function goalConversion(string $goalName, ?float $value = null, array $params = []): void
    {
        $payload = $params;
        $payload['goal_name'] = $goalName;
        if ($value !== null) {
            $payload['value'] = $value;
        }
        $this->track('goal_conversion', $payload);
    }

    /**
     * Track a feedback event.
     *
     * @param  string  $type  Feedback type (rating, nps, survey, ...)
     * @param  int|string|null  $score  Numeric score or text value
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function feedback(string $type, int|string|null $score = null, array $params = []): void
    {
        $payload = $params;
        $payload['feedback_type'] = $type;
        if ($score !== null) {
            $payload['score'] = $score;
        }
        $this->track('feedback', $payload);
    }

    /**
     * Track a feature_request event.
     *
     * @param  string  $featureName  Requested feature name
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function featureRequest(string $featureName, array $params = []): void
    {
        $this->track('feature_request', array_merge($params, [
            'feature_name' => $featureName,
        ]));
    }

    // ─── SaaS Lifecycle Shorthand Methods (v26.0.0) ──────────────────

    /**
     * Track a subscription_paused event.
     *
     * @param  string|null  $planName  Plan name being paused
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function subscriptionPaused(?string $planName = null, array $params = []): void
    {
        $payload = $params;
        if ($planName !== null) {
            $payload['plan_name'] = $planName;
        }
        $this->track('subscription_paused', $payload);
    }

    /**
     * Track a subscription_resumed event.
     *
     * @param  string|null  $planName  Plan name being resumed
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function subscriptionResumed(?string $planName = null, array $params = []): void
    {
        $payload = $params;
        if ($planName !== null) {
            $payload['plan_name'] = $planName;
        }
        $this->track('subscription_resumed', $payload);
    }

    /**
     * Track a plan_changed event.
     *
     * @param  string  $fromPlan  Previous plan name
     * @param  string  $toPlan  New plan name
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function planChanged(string $fromPlan, string $toPlan, array $params = []): void
    {
        $this->track('plan_changed', array_merge($params, [
            'from_plan' => $fromPlan,
            'to_plan' => $toPlan,
        ]));
    }

    /**
     * Track a team_created event.
     *
     * @param  string|null  $teamName  Team name
     * @param  int|null  $memberCount  Initial member count
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function teamCreated(?string $teamName = null, ?int $memberCount = null, array $params = []): void
    {
        $this->track('team_created', array_merge($params, array_filter([
            'team_name' => $teamName,
            'member_count' => $memberCount,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a team_member_joined event.
     *
     * @param  string|null  $role  Member role
     * @param  string|null  $inviteMethod  How the member was invited
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function teamMemberJoined(?string $role = null, ?string $inviteMethod = null, array $params = []): void
    {
        $this->track('team_member_joined', array_merge($params, array_filter([
            'role' => $role,
            'invite_method' => $inviteMethod,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a team_member_removed event.
     *
     * @param  string|null  $role  Removed member's role
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function teamMemberRemoved(?string $role = null, array $params = []): void
    {
        $payload = $params;
        if ($role !== null) {
            $payload['role'] = $role;
        }
        $this->track('team_member_removed', $payload);
    }

    /**
     * Track a role_changed event.
     *
     * @param  string  $fromRole  Previous role
     * @param  string  $toRole  New role
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function roleChanged(string $fromRole, string $toRole, array $params = []): void
    {
        $this->track('role_changed', array_merge($params, [
            'from_role' => $fromRole,
            'to_role' => $toRole,
        ]));
    }

    /**
     * Track a payment_failed event.
     *
     * @param  string|null  $reason  Failure reason
     * @param  float|null  $amount  Attempted payment amount
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function paymentFailed(?string $reason = null, ?float $amount = null, array $params = []): void
    {
        $this->track('payment_failed', array_merge($params, array_filter([
            'reason' => $reason,
            'amount' => $amount,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a payment_succeeded event.
     *
     * @param  float|null  $amount  Payment amount
     * @param  string|null  $method  Payment method
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function paymentSucceeded(?float $amount = null, ?string $method = null, array $params = []): void
    {
        $this->track('payment_succeeded', array_merge($params, array_filter([
            'amount' => $amount,
            'method' => $method,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a milestone_reached event.
     *
     * @param  string  $milestoneName  Milestone identifier
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function milestoneReached(string $milestoneName, array $params = []): void
    {
        $this->track('milestone_reached', array_merge($params, [
            'milestone' => $milestoneName,
        ]));
    }

    /**
     * Track a workspace_created event.
     *
     * @param  string|null  $workspaceName  Workspace name
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function workspaceCreated(?string $workspaceName = null, array $params = []): void
    {
        $payload = $params;
        if ($workspaceName !== null) {
            $payload['workspace_name'] = $workspaceName;
        }
        $this->track('workspace_created', $payload);
    }

    /**
     * Track a usage_quota_reached event.
     *
     * @param  string  $quotaType  Type of quota (storage, api_calls, users, ...)
     * @param  int|null  $limit  Quota limit value
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function usageQuotaReached(string $quotaType, ?int $limit = null, array $params = []): void
    {
        $payload = $params;
        $payload['quota_type'] = $quotaType;
        if ($limit !== null) {
            $payload['quota_limit'] = $limit;
        }
        $this->track('usage_quota_reached', $payload);
    }

    /**
     * Track a billing_retry event.
     *
     * @param  int|null  $attemptNumber  Retry attempt number
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function billingRetry(?int $attemptNumber = null, array $params = []): void
    {
        $payload = $params;
        if ($attemptNumber !== null) {
            $payload['attempt'] = $attemptNumber;
        }
        $this->track('billing_retry', $payload);
    }

    // ── SaaS Account Lifecycle Shorthands (v101.0.0) ─────────────────

    /**
     * Track an account_activated event.
     *
     * Fires when a user activates their account (e.g., confirms email,
     * completes verification). Critical for SaaS activation rate metrics.
     *
     * @param  string|null  $method  Activation method (email, sso, admin)
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function accountActivated(?string $method = null, array $params = []): void
    {
        $this->track('account_activated', array_merge($params, array_filter([
            'method' => $method,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track an account_deactivated event.
     *
     * Fires when a user deactivates their account (self-service or admin-initiated).
     * Used for churn prediction and retention analysis.
     *
     * @param  string|null  $reason  Deactivation reason (self_service, admin, inactivity)
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function accountDeactivated(?string $reason = null, array $params = []): void
    {
        $this->track('account_deactivated', array_merge($params, array_filter([
            'reason' => $reason,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a feature_used event.
     *
     * Fires when a user interacts with a specific product feature.
     * Essential for feature adoption analysis, product-led growth,
     * and activation funnel tracking.
     *
     * @param  string  $featureName  Feature identifier (e.g., 'api_keys', 'export_csv')
     * @param  string|null  $category  Feature category (reporting, integrations, billing)
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function featureUsed(string $featureName, ?string $category = null, array $params = []): void
    {
        $this->track('feature_used', array_merge($params, array_filter([
            'feature_name' => $featureName,
            'category' => $category,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track an email_verified event.
     *
     * Fires when a user verifies their email address.
     * Part of the SaaS activation funnel.
     *
     * @param  string|null  $method  Verification method (link, code, oauth)
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function emailVerified(?string $method = null, array $params = []): void
    {
        $this->track('email_verified', array_merge($params, array_filter([
            'method' => $method,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a password_changed event.
     *
     * Fires when a user changes their password.
     * Part of account security event tracking.
     *
     * @param  string|null  $method  Change method (self_service, admin_reset, sso)
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function passwordChanged(?string $method = null, array $params = []): void
    {
        $this->track('password_changed', array_merge($params, array_filter([
            'method' => $method,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a profile_updated event.
     *
     * Fires when a user updates their profile information.
     * Used for engagement scoring and user activity tracking.
     *
     * @param  list<string>|null  $fields  List of updated field names
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function profileUpdated(?array $fields = null, array $params = []): void
    {
        $this->track('profile_updated', array_merge($params, array_filter([
            'fields' => $fields,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track an api_rate_limited event.
     *
     * Fires when a user hits an API rate limit. Used for usage analytics
     * and product-led growth upsell signals.
     *
     * @param  string|null  $endpoint  Rate-limited endpoint
     * @param  int|null  $limit  Rate limit threshold
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function apiRateLimited(?string $endpoint = null, ?int $limit = null, array $params = []): void
    {
        $this->track('api_rate_limited', array_merge($params, array_filter([
            'endpoint' => $endpoint,
            'limit' => $limit,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track an invoice_generated event.
     *
     * Fires when an invoice is generated for a subscription.
     * Part of SaaS revenue and billing lifecycle tracking.
     *
     * @param  float|null  $amount  Invoice amount
     * @param  string|null  $invoiceId  Invoice identifier
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function invoiceGenerated(?float $amount = null, ?string $invoiceId = null, array $params = []): void
    {
        $this->track('invoice_generated', array_merge($params, array_filter([
            'amount' => $amount,
            'invoice_id' => $invoiceId,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track an account_deleted event.
     *
     * Fires when a user permanently deletes their account.
     * Critical for churn analysis and GDPR compliance tracking.
     *
     * @param  string|null  $reason  Deletion reason (self_service, gdpr_request, admin)
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function accountDeleted(?string $reason = null, array $params = []): void
    {
        $this->track('account_deleted', array_merge($params, array_filter([
            'reason' => $reason,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a data_erasure_completed event.
     *
     * Fires when GDPR data erasure is completed for a user.
     * Part of privacy compliance event tracking.
     *
     * @param  string|null  $requestId  GDPR erasure request identifier
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function dataErasureCompleted(?string $requestId = null, array $params = []): void
    {
        $this->track('data_erasure_completed', array_merge($params, array_filter([
            'request_id' => $requestId,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track an export event.
     *
     * Fires when a user exports data (GDPR DSAR or product export).
     * Used for compliance tracking and feature usage analytics.
     *
     * @param  string|null  $format  Export format (csv, json, pdf)
     * @param  string|null  $resource  Exported resource type
     * @param  int|null  $recordCount  Number of records exported
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function exportEvent(?string $format = null, ?string $resource = null, ?int $recordCount = null, array $params = []): void
    {
        $this->track('export', array_merge($params, array_filter([
            'format' => $format,
            'resource' => $resource,
            'record_count' => $recordCount,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track an import event.
     *
     * Fires when a user imports data into the application.
     * Used for feature adoption and data migration tracking.
     *
     * @param  string|null  $format  Import format (csv, json)
     * @param  string|null  $resource  Imported resource type
     * @param  int|null  $recordCount  Number of records imported
     * @param  bool|null  $success  Whether the import succeeded
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function importEvent(?string $format = null, ?string $resource = null, ?int $recordCount = null, ?bool $success = null, array $params = []): void
    {
        $this->track('import', array_merge($params, array_filter([
            'format' => $format,
            'resource' => $resource,
            'record_count' => $recordCount,
            'success' => $success,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a first_value event.
     *
     * Fires when a user reaches their "aha moment" — the first time
     * they experience core product value. Critical for product-market
     * fit analysis and activation rate optimization.
     *
     * @param  string  $eventName  Name of the value-creating action
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function firstValue(string $eventName, array $params = []): void
    {
        $this->track('first_value', array_merge($params, [
            'value_event' => $eventName,
        ]));
    }

    /**
     * Track a growth_milestone event.
     *
     * Fires when the application reaches a growth milestone
     * (user count, revenue, feature adoption thresholds).
     * Used for investor reporting and team celebration.
     *
     * @param  string  $milestone  Milestone identifier (e.g., '1000_users', '10k_mrr')
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function growthMilestone(string $milestone, array $params = []): void
    {
        $this->track('growth_milestone', array_merge($params, [
            'milestone' => $milestone,
        ]));
    }

    // ── Identity + Event (v145.0.0) ──────────────────────────────────────

    /**
     * Identify a user and track an event in one atomic call.
     *
     * Common SaaS pattern for post-authentication events (signup, trial_start,
     * subscription) where the user identity must be linked before the event
     * is tracked. Ensures the identify call fires before the event, so all
     * providers receive the user ID on the subsequent event.
     *
     * Usage:
     *   Analytics::identifyAndTrack($userId, 'sign_up', $clientId, ['method' => 'email']);
     *   Analytics::identifyAndTrack($userId, 'trial_start', $clientId, ['plan' => 'Pro']);
     *   Analytics::identifyAndTrack($userId, 'purchase', $clientId, ['value' => 99.99]);
     *
     * @param  string  $userId  Authenticated user ID
     * @param  string  $eventName  Event to track after identification
     * @param  string|null  $clientId  Client tracking ID (optional)
     * @param  array<string, mixed>  $params  Event parameters
     * @param  array<string, mixed>  $traits  Additional user traits for identify
     */
    public function identifyAndTrack(string $userId, string $eventName, ?string $clientId = null, array $params = [], array $traits = []): void
    {
        $this->identify($userId, $clientId, $traits);
        $this->track($eventName, array_merge($params, [
            'user_id' => $userId,
            'client_id' => $clientId,
        ]));
    }

    // ── Additional SaaS Convenience Methods (v145.0.0) ─────────────────

    /**
     * Track a web_vitals event (Core Web Vitals from client).
     *
     * @param  string  $metricName  Vital name (LCP, FID, CLS, INP, TTFB, FCP)
     * @param  float|int  $value  Metric value (ms for timing, score for CLS)
     * @param  array<string, mixed>  $params  Additional parameters (rating, url, element)
     */
    public function webVitals(string $metricName, float|int $value, array $params = []): void
    {
        $this->track('web_vitals', array_merge($params, [
            'metric_name' => $metricName,
            'value' => $value,
            'rating' => $params['rating'] ?? $this->inferWebVitalsRating($metricName, $value),
        ]));
    }

    /**
     * Track a js_error event (client-side JavaScript error).
     *
     * @param  string  $message  Error message
     * @param  string|null  $source  File/source where error occurred
     * @param  int|null  $line  Line number
     * @param  int|null  $col  Column number
     * @param  array<string, mixed>  $params  Additional parameters (stack, url)
     */
    public function jsError(string $message, ?string $source = null, ?int $line = null, ?int $col = null, array $params = []): void
    {
        $this->track('js_error', array_merge($params, array_filter([
            'message' => $message,
            'source' => $source,
            'line' => $line,
            'col' => $col,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a timing event (custom performance measurement).
     *
     * @param  string  $category  Timing category (e.g., 'api_call', 'render')
     * @param  string  $variable  Timing variable name
     * @param  int|float  $valueMs  Duration in milliseconds
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function timing(string $category, string $variable, int|float $valueMs, array $params = []): void
    {
        $this->track('timing', array_merge($params, [
            'timing_category' => $category,
            'timing_variable' => $variable,
            'timing_value' => $valueMs,
        ]));
    }

    /**
     * Track a session_start event.
     *
     * @param  string|null  $sessionId  Session identifier
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function sessionStart(?string $sessionId = null, array $params = []): void
    {
        $this->track('session_start', array_merge($params, array_filter([
            'session_id' => $sessionId,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a session_end event.
     *
     * @param  string|null  $sessionId  Session identifier
     * @param  int|null  $durationSeconds  Session duration in seconds
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function sessionEnd(?string $sessionId = null, ?int $durationSeconds = null, array $params = []): void
    {
        $this->track('session_end', array_merge($params, array_filter([
            'session_id' => $sessionId,
            'duration_seconds' => $durationSeconds,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track an element_visibility event (IntersectionObserver).
     *
     * @param  string  $elementId  Target element identifier
     * @param  string|null  $elementType  Element type (button, banner, section)
     * @param  bool|null  $isVisible  Whether the element is visible
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function elementVisibility(string $elementId, ?string $elementType = null, ?bool $isVisible = null, array $params = []): void
    {
        $this->track('element_visibility', array_merge($params, array_filter([
            'element_id' => $elementId,
            'element_type' => $elementType,
            'is_visible' => $isVisible,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a copy_text event (text copy/cut tracking).
     *
     * @param  string  $text  Copied text (truncated for privacy)
     * @param  string|null  $source  Source element or context
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function copyText(string $text, ?string $source = null, array $params = []): void
    {
        $this->track('copy_text', array_merge($params, [
            'text_length' => mb_strlen($text),
            'source' => $source,
        ]));
    }

    /**
     * Track a hover event (element hover/focus tracking).
     *
     * @param  string  $elementId  Target element identifier
     * @param  int|null  $durationMs  Hover duration in milliseconds
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function hover(string $elementId, ?int $durationMs = null, array $params = []): void
    {
        $this->track('hover', array_merge($params, array_filter([
            'element_id' => $elementId,
            'duration_ms' => $durationMs,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a client_error event (structured client-side error with context).
     *
     * @param  string  $message  Error message
     * @param  string|null  $type  Error type (TypeError, ReferenceError, etc.)
     * @param  string|null  $source  File or component where error occurred
     * @param  array<string, mixed>  $params  Additional parameters (stack, url, line, col)
     */
    public function clientError(string $message, ?string $type = null, ?string $source = null, array $params = []): void
    {
        $this->track('client_error', array_merge($params, [
            'message' => $message,
            'type' => $type,
            'source' => $source,
        ]));
    }

    /**
     * Track a consent_granted event.
     *
     * @param  array<string, bool>|null  $purposes  Granted consent purposes
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function consentGrant(?array $purposes = null, array $params = []): void
    {
        $this->track('consent_granted', array_merge($params, array_filter([
            'purposes' => $purposes,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a consent_withdrawn event.
     *
     * @param  array<string, bool>|null  $purposes  Withdrawn consent purposes
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function consentWithdraw(?array $purposes = null, array $params = []): void
    {
        $this->track('consent_withdrawn', array_merge($params, array_filter([
            'purposes' => $purposes,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a payment_method_added event.
     *
     * @param  string|null  $method  Payment method type (card, paypal, bank_transfer)
     * @param  string|null  $provider  Payment provider (stripe, paddle, etc.)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function paymentMethodAdded(?string $method = null, ?string $provider = null, array $params = []): void
    {
        $this->track('payment_method_added', array_merge($params, array_filter([
            'method' => $method,
            'provider' => $provider,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a credit_applied event.
     *
     * @param  float|null  $amount  Credit amount
     * @param  string|null  $reason  Credit reason (referral, support, adjustment)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function creditApplied(?float $amount = null, ?string $reason = null, array $params = []): void
    {
        $this->track('credit_applied', array_merge($params, array_filter([
            'amount' => $amount,
            'reason' => $reason,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track a feature_limit_reached event.
     *
     * @param  string  $feature  Feature name that hit the limit
     * @param  string|null  $limitType  Limit type (count, storage, api_calls)
     * @param  int|float|null  $currentValue  Current usage value
     * @param  int|float|null  $limitValue  Limit threshold
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function featureLimitReached(string $feature, ?string $limitType = null, int|float|null $currentValue = null, int|float|null $limitValue = null, array $params = []): void
    {
        $this->track('feature_limit_reached', array_merge($params, array_filter([
            'feature' => $feature,
            'limit_type' => $limitType,
            'current_value' => $currentValue,
            'limit_value' => $limitValue,
        ], fn (mixed $v): bool => $v !== null)));
    }

    /**
     * Track an integration_failed event.
     *
     * @param  string  $integration  Integration name (slack, github, stripe)
     * @param  string|null  $errorType  Error type (auth, timeout, rate_limit)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function integrationFailed(string $integration, ?string $errorType = null, array $params = []): void
    {
        $this->track('integration_failed', array_merge($params, [
            'integration' => $integration,
            'error_type' => $errorType,
        ]));
    }

    /**
     * Revenue Attribution Dashboard — full revenue-by-channel ROI summary.
     *
     * @param  int|null  $lookbackDays  Override default lookback window
     * @return array{generated_at: string, period_days: int, currency: string, total_revenue: float, total_customers: int, channels: array<string, array{revenue: float, customers: int, ltv: float, cac: float|null, payback_months: float|null, revenue_share: float, avg_revenue_per_customer: float}>, top_channel: string|null, revenue_concentration: float, recommendations: list<string>}
     *
     * @since 147.0.0
     */
    public function revenueAttributionDashboard(?int $lookbackDays = null): array
    {
        /** @var \ZeroBoiler\Analytics\Services\RevenueAttributionDashboardService $service */
        $service = $this->getContainer()->make(\ZeroBoiler\Analytics\Services\RevenueAttributionDashboardService::class);

        return $service->dashboard($lookbackDays);
    }

    /**
     * Infer a Web Vitals rating from the metric value.
     *
     * @param  string  $metricName  Vital name
     * @param  float|int  $value  Metric value
     * @return string  Rating: 'good', 'needs-improvement', or 'poor'
     */
    private function inferWebVitalsRating(string $metricName, float|int $value): string
    {
        return match ($metricName) {
            'LCP' => $value <= 2500 ? 'good' : ($value <= 4000 ? 'needs-improvement' : 'poor'),
            'FID', 'INP' => $value <= 200 ? 'good' : ($value <= 500 ? 'needs-improvement' : 'poor'),
            'CLS' => $value <= 0.1 ? 'good' : ($value <= 0.25 ? 'needs-improvement' : 'poor'),
            'TTFB' => $value <= 800 ? 'good' : ($value <= 1800 ? 'needs-improvement' : 'poor'),
            'FCP' => $value <= 1800 ? 'good' : ($value <= 3000 ? 'needs-improvement' : 'poor'),
            default => 'unknown',
        };
    }

    // ── Unified Category Dispatchers (v126.0.0) ──────────────────────────

    /**
     * Track an e-commerce event by name with auto-formatted parameters.
     *
     * Unified entry point for all e-commerce events. Accepts the canonical
     * event name (snake_case, camelCase, or PascalCase) and dispatches to all
     * enabled providers with the correct parameter format.
     *
     * Supported events: view_item, add_to_cart, remove_from_cart, view_cart,
     * begin_checkout, add_payment_info, purchase, refund, add_to_wishlist,
     * select_item, select_promotion, view_promotion, checkout_step,
     * abandoned_cart, checkout_abandon.
     *
     * @param  string  $eventName  Event name in any format (e.g. 'ViewItem', 'add_to_cart', 'Purchase')
     * @param  array<string, mixed>  $params  Event parameters (item data for single-item events, full params for purchase/refund)
     * @param  array{currency?: string, value?: float, transaction_id?: string, items?: array<int, array<string, mixed>>}  $options  Additional context (currency, value for computed events)
     * @return bool  True if the event name was found in the catalog
     *
     * @since 126.0.0
     */
    public function trackEcommerceEvent(string $eventName, array $params = [], array $options = []): bool
    {
        $resolved = \ZeroBoiler\Analytics\Events\EventCatalog::resolve($eventName);

        if ($resolved === null || \ZeroBoiler\Analytics\Events\EventCatalog::getCategory($resolved) !== 'ecommerce') {
            return false;
        }

        // Auto-compute value for single-item events when not provided
        if (isset($options['value']) && ! isset($params['value'])) {
            $params['value'] = $options['value'];
        }
        if (isset($options['currency']) && ! isset($params['currency'])) {
            $params['currency'] = $options['currency'];
        }
        if (isset($options['transaction_id']) && ! isset($params['transaction_id'])) {
            $params['transaction_id'] = $options['transaction_id'];
        }
        if (isset($options['items']) && ! isset($params['items'])) {
            $params['items'] = $options['items'];
        }

        // Auto-compute value from single item for view_item, add_to_cart, remove_from_cart
        if (
            ! isset($params['value'])
            && isset($params['price'], $params['quantity'])
            && in_array($resolved, ['view_item', 'add_to_cart', 'remove_from_cart'], true)
        ) {
            $params['value'] = (float) $params['price'] * (int) $params['quantity'];
        }

        $this->track($resolved, $params);

        return true;
    }

    /**
     * Track a SaaS lifecycle event by name with auto-formatted parameters.
     *
     * Unified entry point for all SaaS events. Accepts the canonical event
     * name and dispatches to all enabled providers. Supports all 71 SaaS
     * lifecycle events including auth, subscription, billing, team, and
     * cohort events.
     *
     * Supported events: sign_up, login, logout, start_trial, trial_end,
     * subscribe, plan_upgrade, plan_downgrade, cancellation, feature_used,
     * revenue_tracked, trial_converted, team_created, team_member_joined,
     * payment_failed, payment_succeeded, subscription_renewal, and all
     * other events in the SaaS catalog.
     *
     * @param  string  $eventName  Event name in any format (e.g. 'TrialStart', 'plan_upgrade')
     * @param  array<string, mixed>  $params  Event parameters
     * @return bool  True if the event name was found in the SaaS catalog
     *
     * @since 126.0.0
     */
    public function trackSaaSLifecycle(string $eventName, array $params = []): bool
    {
        $resolved = \ZeroBoiler\Analytics\Events\EventCatalog::resolve($eventName);

        if ($resolved === null || \ZeroBoiler\Analytics\Events\EventCatalog::getCategory($resolved) !== 'saas') {
            return false;
        }

        $this->track($resolved, $params);

        return true;
    }

    /**
     * Track an engagement event by name with auto-formatted parameters.
     *
     * Unified entry point for all engagement events. Accepts the canonical
     * event name and dispatches to all enabled providers. Supports all
     * engagement events including page tracking, scroll depth, clicks,
     * form interactions, search, sharing, errors, and session events.
     *
     * Supported events: page_view, scroll_depth, click, form_start,
     * form_submit, search, share, error, time_on_page, session_start,
     * session_end, web_vitals, js_error, and all other engagement events.
     *
     * @param  string  $eventName  Event name in any format (e.g. 'PageView', 'scroll_depth')
     * @param  array<string, mixed>  $params  Event parameters
     * @return bool  True if the event name was found in the engagement catalog
     *
     * @since 126.0.0
     */
    public function trackEngagement(string $eventName, array $params = []): bool
    {
        $resolved = \ZeroBoiler\Analytics\Events\EventCatalog::resolve($eventName);

        if ($resolved === null || \ZeroBoiler\Analytics\Events\EventCatalog::getCategory($resolved) !== 'engagement') {
            return false;
        }

        $this->track($resolved, $params);

        return true;
    }

    /**
     * Track an event from any category by name with unified dispatch.
     *
     * Cross-category dispatcher that resolves any event name from any
     * catalog category (ecommerce, saas, engagement, marketing, security,
     * infrastructure, uptime) and dispatches to all enabled providers.
     *
     * @param  string  $eventName  Event name in any format
     * @param  array<string, mixed>  $params  Event parameters
     * @return bool  True if the event was found in any catalog
     *
     * @since 126.0.0
     */
    public function trackByCategory(string $eventName, array $params = []): bool
    {
        $resolved = \ZeroBoiler\Analytics\Events\EventCatalog::resolve($eventName);

        if ($resolved === null) {
            return false;
        }

        $this->track($resolved, $params);

        return true;
    }
}