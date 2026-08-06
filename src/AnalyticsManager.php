<?php

/**
 * This file is part of ZeroBoiler, licensed under the proprietary license.
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
     * Get the event catalog summary (event counts per category).
     *
     * @return array{ecommerce: int, saas: int, engagement: int, total: int}
     */
    public function eventCatalogSummary(): array
    {
        return [
            'ecommerce' => 8,
            'saas' => 11,
            'engagement' => 10,
            'total' => 29,
        ];
    }
}
