<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;

class AnalyticsManager
{
    protected GA4Tracker $ga4;

    protected GTMTracker $gtm;

    protected MetaPixelTracker $meta;

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

        // Apply default consent state from config (GDPR-safe defaults)
        $consentDefault = $config->get('zeroboiler.analytics.consent.default', 'granted');
        if ($consentDefault === 'denied') {
            $this->denyConsent();
        }
    }

    /**
     * Track an event across all configured providers.
     *
     * @param  array<string, mixed>  $params
     */
    public function track(string $eventName, array $params = []): void
    {
        $event = new AnalyticsEvent(name: $eventName, params: $params);

        if ($this->ga4->isEnabled()) {
            $this->ga4->track($event);
        }

        if ($this->gtm->isEnabled()) {
            $this->gtm->track($event);
        }

        if ($this->meta->isEnabled()) {
            $this->meta->track($event);
        }
    }

    /**
     * Track an AnalyticsEvent DTO across all configured providers.
     */
    public function trackEvent(AnalyticsEvent $event): void
    {
        if ($this->ga4->isEnabled()) {
            $this->ga4->track($event);
        }

        if ($this->gtm->isEnabled()) {
            $this->gtm->track($event);
        }

        if ($this->meta->isEnabled()) {
            $this->meta->track($event);
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
}
