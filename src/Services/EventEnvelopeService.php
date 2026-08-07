<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\DTO\EventContextEvent;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;
use ZeroBoiler\Analytics\Tracking\AnonymousIdTracker;

/**
 * Builds fully-qualified event envelopes with rich context.
 *
 * Automatically enriches AnalyticsEvent instances with session, device,
 * geolocation, identity, UTM attribution, referrer, and consent context
 * from the current HTTP request. Produces EventContextEvent DTOs suitable
 * for SaaS observability dashboards and comprehensive audit trails.
 *
 * Context enrichment is configurable — disable sections you don't need
 * via `zeroboiler.analytics.envelope` config.
 *
 * @see \ZeroBoiler\Analytics\DTO\EventContextEvent
 */
final class EventEnvelopeService
{
    private const CACHE_PREFIX = 'zb_envelope_';

    private bool $enabled;

    private bool $sessionContext;

    private bool $deviceContext;

    private bool $geoContext;

    private bool $identityContext;

    private bool $utmContext;

    private bool $referrerContext;

    private bool $consentContext;

    private bool $metadataContext;

    private CacheRepository $cache;

    private ?DeviceContextService $deviceService;

    private ?GeolocationEnricher $geoEnricher;

    private ?ReferrerTrackingService $referrerService;

    private ?AttributionService $attributionService;

    private ?TrackingPreferenceService $trackingPreferenceService;

    private ?ConsentLogService $consentLogService;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     * @param  DeviceContextService|null  $deviceService
     * @param  GeolocationEnricher|null  $geoEnricher
     * @param  ReferrerTrackingService|null  $referrerService
     * @param  AttributionService|null  $attributionService
     * @param  TrackingPreferenceService|null  $trackingPreferenceService
     * @param  ConsentLogService|null  $consentLogService
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        ?DeviceContextService $deviceService = null,
        ?GeolocationEnricher $geoEnricher = null,
        ?ReferrerTrackingService $referrerService = null,
        ?AttributionService $attributionService = null,
        ?TrackingPreferenceService $trackingPreferenceService = null,
        ?ConsentLogService $consentLogService = null,
    ): void {
        $this->cache = $cache;
        $this->deviceService = $deviceService;
        $this->geoEnricher = $geoEnricher;
        $this->referrerService = $referrerService;
        $this->attributionService = $attributionService;
        $this->trackingPreferenceService = $trackingPreferenceService;
        $this->consentLogService = $consentLogService;

        $envelopeConfig = $config->get('zeroboiler.analytics.envelope', []);
        /** @var array{enabled?: bool, session?: bool, device?: bool, geo?: bool, identity?: bool, utm?: bool, referrer?: bool, consent?: bool, metadata?: bool} $envelopeConfig */
        $this->enabled = (bool) ($envelopeConfig['enabled'] ?? true);
        $this->sessionContext = (bool) ($envelopeConfig['session'] ?? true);
        $this->deviceContext = (bool) ($envelopeConfig['device'] ?? true);
        $this->geoContext = (bool) ($envelopeConfig['geo'] ?? false);
        $this->identityContext = (bool) ($envelopeConfig['identity'] ?? true);
        $this->utmContext = (bool) ($envelopeConfig['utm'] ?? true);
        $this->referrerContext = (bool) ($envelopeConfig['referrer'] ?? true);
        $this->consentContext = (bool) ($envelopeConfig['consent'] ?? true);
        $this->metadataContext = (bool) ($envelopeConfig['metadata'] ?? true);
    }

    /**
     * Build a fully-qualified event envelope from a request.
     *
     * Enriches the base event with all enabled context sections.
     * Omitted context sections are left as empty arrays for consistency.
     *
     * @param  AnalyticsEvent  $event  Base analytics event
     * @param  Request  $request  Current HTTP request
     * @return EventContextEvent Fully-enriched event envelope
     */
    public function build(AnalyticsEvent $event, Request $request): EventContextEvent
    {
        if (! $this->enabled) {
            return EventContextEvent::fromEvent($event);
        }

        return new EventContextEvent(
            event: $event,
            session: $this->sessionContext ? $this->buildSessionContext($request) : [],
            device: $this->deviceContext ? $this->buildDeviceContext($request) : [],
            geo: $this->geoContext ? $this->buildGeoContext($request) : [],
            identity: $this->identityContext ? $this->buildIdentityContext($request, $event) : [],
            utm: $this->utmContext ? $this->buildUtmContext($request) : [],
            referrer: $this->referrerContext ? $this->buildReferrerContext($request) : [],
            consent: $this->consentContext ? $this->buildConsentContext($request) : [],
            metadata: $this->metadataContext ? $this->buildMetadataContext($request) : [],
        );
    }

    /**
     * Build from event without a request (server-side / queue context).
     *
     * Uses the event's existing client_id/user_id for identity context.
     * Other context sections default to empty unless overridden.
     *
     * @param  AnalyticsEvent  $event
     * @param  array<string, mixed>  $overrides  Optional context overrides
     */
    public function buildFromEvent(AnalyticsEvent $event, array $overrides = []): EventContextEvent
    {
        $identity = $event->userId || $event->clientId
            ? array_filter([
                'user_id' => $event->userId,
                'client_id' => $event->clientId,
                'is_authenticated' => $event->userId !== null,
            ])
            : [];

        return new EventContextEvent(
            event: $event,
            session: $overrides['session'] ?? [],
            device: $overrides['device'] ?? [],
            geo: $overrides['geo'] ?? [],
            identity: $overrides['identity'] ?? $identity,
            utm: $overrides['utm'] ?? [],
            referrer: $overrides['referrer'] ?? [],
            consent: $overrides['consent'] ?? [],
            metadata: $overrides['metadata'] ?? ['source' => 'server', 'environment' => app()->environment()],
        );
    }

    /**
     * Build session context from the current request.
     *
     * @return array<string, mixed>
     */
    private function buildSessionContext(Request $request): array
    {
        $sessionId = $request->session()->getId() ?? null;
        $cookieName = 'zb_analytics_session';

        return array_filter([
            'id' => $sessionId,
            'is_new' => ! $request->hasCookie($cookieName),
            'started_at' => $request->hasCookie($cookieName) ? null : time(),
        ]);
    }

    /**
     * Build device context from the request User-Agent.
     *
     * @return array<string, mixed>
     */
    private function buildDeviceContext(Request $request): array
    {
        if ($this->deviceService === null) {
            return [];
        }

        $ua = $request->headers->get('User-Agent', '');
        $parsed = $this->deviceService->parse($ua);

        return array_filter([
            'browser' => $parsed['browser'] ?? null,
            'browser_version' => $parsed['browser_version'] ?? null,
            'os' => $parsed['os'] ?? null,
            'os_version' => $parsed['os_version'] ?? null,
            'device_type' => $parsed['device_type'] ?? null,
            'device_brand' => $parsed['device_brand'] ?? null,
        ]);
    }

    /**
     * Build geolocation context from the request.
     *
     * @return array<string, mixed>
     */
    private function buildGeoContext(Request $request): array
    {
        if ($this->geoEnricher === null) {
            return [];
        }

        return array_filter([
            'country' => $request->headers->get('CF-IPCountry') ?? null,
            'strategy' => 'header',
        ]);
    }

    /**
     * Build identity context from request and event.
     *
     * @return array<string, mixed>
     */
    private function buildIdentityContext(Request $request, AnalyticsEvent $event): array
    {
        $userId = $event->userId ?? ($request->user()?->id);
        $clientId = $event->clientId ?? $request->cookie('zb_analytics_id');

        return array_filter([
            'user_id' => is_string($userId) || is_int($userId) ? (string) $userId : null,
            'client_id' => is_string($clientId) ? $clientId : null,
            'is_authenticated' => $userId !== null,
            'ip' => $request->ip(),
        ]);
    }

    /**
     * Build UTM attribution context from the request.
     *
     * @return array<string, mixed>
     */
    private function buildUtmContext(Request $request): array
    {
        return array_filter([
            'source' => $request->query('utm_source'),
            'medium' => $request->query('utm_medium'),
            'campaign' => $request->query('utm_campaign'),
            'term' => $request->query('utm_term'),
            'content' => $request->query('utm_content'),
        ]);
    }

    /**
     * Build referrer context from the request.
     *
     * @return array<string, mixed>
     */
    private function buildReferrerContext(Request $request): array
    {
        $referrer = $request->headers->get('referer', '');
        $parsed = parse_url($referrer);
        $domain = $parsed['host'] ?? '';
        $currentHost = $request->getHost();

        return array_filter([
            'url' => $referrer ?: null,
            'domain' => $domain ?: null,
            'is_internal' => $domain !== '' && $domain === $currentHost,
        ]);
    }

    /**
     * Build consent context from the current state.
     *
     * @return array<string, mixed>
     */
    private function buildConsentContext(Request $request): array
    {
        $clientId = $request->cookie('zb_analytics_id');

        if ($clientId === null || $this->consentLogService === null) {
            return [];
        }

        $current = $this->consentLogService->getCurrentConsent($clientId);

        return [
            'source' => $current['source'],
            'updated_at' => $current['updated_at'],
            'purposes' => $current['purposes'],
        ];
    }

    /**
     * Build metadata context (source tagging, version, environment).
     *
     * @return array<string, mixed>
     */
    private function buildMetadataContext(Request $request): array
    {
        return array_filter([
            'source' => 'server',
            'environment' => app()->environment(),
            'ip' => $request->ip(),
            'url' => $request->fullUrl(),
        ]);
    }

    /**
     * Check if the envelope service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the list of active context sections.
     *
     * @return list<string>
     */
    public function activeSections(): array
    {
        $sections = [];
        $map = [
            'session' => $this->sessionContext,
            'device' => $this->deviceContext,
            'geo' => $this->geoContext,
            'identity' => $this->identityContext,
            'utm' => $this->utmContext,
            'referrer' => $this->referrerContext,
            'consent' => $this->consentContext,
            'metadata' => $this->metadataContext,
        ];

        foreach ($map as $name => $active) {
            if ($active) {
                $sections[] = $name;
            }
        }

        return $sections;
    }

    /**
     * Get service summary for diagnostics.
     *
     * @return array{enabled: bool, sections: list<string>, version: string}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'sections' => $this->activeSections(),
            'version' => '2.61.0',
        ];
    }
}
