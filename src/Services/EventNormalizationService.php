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
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Provider-agnostic event normalization service.
 *
 * Converts a single catalog event into provider-specific formats (GA4, GTM dataLayer,
 * Meta Pixel, PostHog, Plausible, Mixpanel, Amplitude, Webhook) in one call. Eliminates
 * the need for manual per-provider transformation in application code.
 *
 * Inspired by Segment's unified event model — write once, dispatch everywhere.
 *
 * @since 10.8.0
 */
final class EventNormalizationService
{
    private ConfigRepository $config;

    /** @var array<string, bool> */
    private array $enabledProviders;

    private CacheRepository $cache;

    private int $cacheTtl;

    private ?Request $request;

    /**
     * @param  array<string, bool>  $enabledProviders  Provider name => enabled flag
     */
    public function __construct(
        ConfigRepository $config,
        CacheRepository $cache,
        array $enabledProviders = [],
        int $cacheTtl = 300,
        ?Request $request = null,
    ){
        $this->config = $config;
        $this->cache = $cache;
        $this->enabledProviders = $enabledProviders;
        $this->cacheTtl = $cacheTtl;
        $this->request = $request;
    }

    /**
     * Normalize a single event into all enabled provider formats.
     *
     * Returns an array keyed by provider name, each containing the
     * provider-specific event payload ready for dispatch.
     *
     * @return array{ga4?: array{name: string, params: array<string, mixed>}, gtm?: array{event: string, ecommerce?: array<string, mixed>, data: array<string, mixed>}, meta?: array{event: string, eventData?: array<string, mixed>, userData?: array<string, mixed>}, posthog?: array{event: string, properties: array<string, mixed>, distinct_id?: string}, plausible?: array{name: string, props: array<string, mixed>, domain: string}, mixpanel?: array{event: string, properties: array<string, mixed>}, amplitude?: array{event_type: string, event_properties: array<string, mixed>}, webhook?: array{name: string, params: array<string, mixed>, client_id: string|null}}
     */
    public function normalize(AnalyticsEvent $event): array
    {
        $catalogEntry = EventCatalog::get($event->name);
        $result = [];

        if ($this->isProviderEnabled('ga4')) {
            $result['ga4'] = $this->normalizeForGa4($event, $catalogEntry);
        }

        if ($this->isProviderEnabled('gtm')) {
            $result['gtm'] = $this->normalizeForGtm($event, $catalogEntry);
        }

        if ($this->isProviderEnabled('meta')) {
            $result['meta'] = $this->normalizeForMeta($event, $catalogEntry);
        }

        if ($this->isProviderEnabled('posthog')) {
            $result['posthog'] = $this->normalizeForPostHog($event, $catalogEntry);
        }

        if ($this->isProviderEnabled('plausible')) {
            $result['plausible'] = $this->normalizeForPlausible($event, $catalogEntry);
        }

        if ($this->isProviderEnabled('mixpanel')) {
            $result['mixpanel'] = $this->normalizeForMixpanel($event, $catalogEntry);
        }

        if ($this->isProviderEnabled('amplitude')) {
            $result['amplitude'] = $this->normalizeForAmplitude($event, $catalogEntry);
        }

        if ($this->isProviderEnabled('webhook')) {
            $result['webhook'] = $this->normalizeForWebhook($event, $catalogEntry);
        }

        return $result;
    }

    /**
     * Normalize a batch of events.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return list<array{event: AnalyticsEvent, normalized: array<string, array<string, mixed>>}>
     */
    public function normalizeBatch(array $events): array
    {
        $results = [];

        foreach ($events as $event) {
            $results[] = [
                'event' => $event,
                'normalized' => $this->normalize($event),
            ];
        }

        return $results;
    }

    /**
     * Get the provider-specific event name from the catalog.
     *
     * @param  'ga4'|'gtm'|'meta'|'posthog'|'plausible'|'mixpanel'|'amplitude'  $provider
     */
    public function providerNameFor(string $eventName, string $provider): string
    {
        $entry = EventCatalog::get($eventName);

        if ($entry === null) {
            return $eventName;
        }

        return match ($provider) {
            'ga4' => $entry['ga4'] ?? $eventName,
            'meta' => $entry['meta'] ?? $eventName,
            'posthog' => $entry['posthog'] ?? $eventName,
            'plausible' => $entry['plausible'] ?? $eventName,
            default => $eventName,
        };
    }

    /**
     * Get a list of all providers that would receive a given event.
     *
     * @return list<string>
     */
    public function targetProvidersFor(string $eventName): array
    {
        $entry = EventCatalog::get($eventName);
        $providers = [];

        if ($entry === null) {
            // Unknown events go to all enabled providers under their original name
            return array_keys(array_filter($this->enabledProviders));
        }

        if ($this->isProviderEnabled('ga4') && isset($entry['ga4'])) {
            $providers[] = 'ga4';
        }

        if ($this->isProviderEnabled('gtm') && isset($entry['ga4'])) {
            $providers[] = 'gtm';
        }

        if ($this->isProviderEnabled('meta') && ($entry['meta'] ?? null) !== null) {
            $providers[] = 'meta';
        }

        if ($this->isProviderEnabled('posthog') && isset($entry['posthog'])) {
            $providers[] = 'posthog';
        }

        if ($this->isProviderEnabled('plausible') && ($entry['plausible'] ?? null) !== null) {
            $providers[] = 'plausible';
        }

        if ($this->isProviderEnabled('mixpanel')) {
            $providers[] = 'mixpanel';
        }

        if ($this->isProviderEnabled('amplitude')) {
            $providers[] = 'amplitude';
        }

        if ($this->isProviderEnabled('webhook')) {
            $providers[] = 'webhook';
        }

        return $providers;
    }

    /**
     * Get normalization statistics for an event (debug/diagnostic).
     *
     * @return array{event: string, catalog_entry: bool, providers: list<string>, missing_mappings: list<string>}
     */
    public function normalizationStats(string $eventName): array
    {
        $entry = EventCatalog::get($eventName);
        $allProviders = ['ga4', 'gtm', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude'];
        $missing = [];

        foreach ($allProviders as $provider) {
            if (! $this->isProviderEnabled($provider)) {
                continue;
            }

            $mapped = match ($provider) {
                'ga4', 'gtm' => isset($entry['ga4']),
                'meta' => ($entry['meta'] ?? null) !== null,
                'posthog' => isset($entry['posthog']),
                'plausible' => ($entry['plausible'] ?? null) !== null,
                default => true,
            };

            if (! $mapped) {
                $missing[] = $provider;
            }
        }

        return [
            'event' => $eventName,
            'catalog_entry' => $entry !== null,
            'providers' => $this->targetProvidersFor($eventName),
            'missing_mappings' => $missing,
        ];
    }

    /**
     * Check normalization coverage across the entire catalog.
     *
     * @return array{total: int, fully_covered: int, partial: int, no_coverage: int, gaps: list<string>}
     */
    public function catalogCoverageReport(): array
    {
        $cacheKey = 'zb_normalization_coverage';
        $cached = $this->cache->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $all = EventCatalog::all();
        $total = count($all);
        $fullyCovered = 0;
        $partial = 0;
        $noCoverage = 0;
        $gaps = [];

        foreach ($all as $name => $entry) {
            $stats = $this->normalizationStats($name);
            $enabledCount = count($stats['providers']);
            $missingCount = count($stats['missing_mappings']);

            if ($missingCount === 0 && $enabledCount > 0) {
                $fullyCovered++;
            } elseif ($enabledCount > 0) {
                $partial++;
                if ($missingCount > 0) {
                    $gaps[] = $name;
                }
            } else {
                $noCoverage++;
            }
        }

        $report = [
            'total' => $total,
            'fully_covered' => $fullyCovered,
            'partial' => $partial,
            'no_coverage' => $noCoverage,
            'gaps' => $gaps,
        ];

        $this->cache->put($cacheKey, $report, $this->cacheTtl);

        return $report;
    }

    /**
     * Normalize event for GA4 Measurement Protocol.
     *
     * @param  array{name: string, class: class-string, ga4: string, meta?: string|null, posthog?: string, plausible?: string|null}|null  $catalogEntry
     * @return array{name: string, params: array<string, mixed>}
     */
    private function normalizeForGa4(AnalyticsEvent $event, ?array $catalogEntry): array
    {
        $ga4Name = $catalogEntry['ga4'] ?? $event->name;
        $params = $event->params;

        // Auto-attach client_id and user_id
        if ($event->clientId !== null) {
            $params['client_id'] = $event->clientId;
        }

        if ($event->userId !== null) {
            $params['user_id'] = $event->userId;
        }

        return [
            'name' => $ga4Name,
            'params' => $params,
        ];
    }

    /**
     * Normalize event for GTM dataLayer push.
     *
     * @param  array{name: string, class: class-string, ga4: string, meta?: string|null, posthog?: string, plausible?: string|null}|null  $catalogEntry
     * @return array{event: string, ecommerce?: array<string, mixed>, data: array<string, mixed>}
     */
    private function normalizeForGtm(AnalyticsEvent $event, ?array $catalogEntry): array
    {
        $gtmEvent = $catalogEntry['ga4'] ?? $event->name;
        $data = $event->params;

        if ($event->clientId !== null) {
            $data['client_id'] = $event->clientId;
        }

        if ($event->userId !== null) {
            $data['user_id'] = $event->userId;
        }

        $result = [
            'event' => $gtmEvent,
            'data' => $data,
        ];

        // E-commerce events get an ecommerce key for GTM Enhanced Ecommerce
        if (EventCatalog::getCategory($event->name) === 'ecommerce') {
            $result['ecommerce'] = $event->params;
        }

        return $result;
    }

    /**
     * Normalize event for Meta Pixel (CAPI).
     *
     * @param  array{name: string, class: class-string, ga4: string, meta?: string|null, posthog?: string, plausible?: string|null}|null  $catalogEntry
     * @return array{event: string, eventData?: array<string, mixed>, userData?: array<string, mixed>}
     */
    private function normalizeForMeta(AnalyticsEvent $event, ?array $catalogEntry): array
    {
        $metaEvent = $catalogEntry['meta'] ?? null;

        if ($metaEvent === null) {
            return [
                'event' => 'CustomEvent',
                'eventData' => array_merge(
                    ['event_name' => $event->name],
                    $event->params,
                ),
            ];
        }

        $result = [
            'event' => $metaEvent,
            'eventData' => $event->params,
        ];

        // Attach user data for CAPI matching
        if ($event->userId !== null || $event->clientId !== null) {
            $result['userData'] = [
                'client_user_agent' => $this->request?->userAgent() ?? '',
                'external_id' => $event->userId,
            ];
        }

        return $result;
    }

    /**
     * Normalize event for PostHog capture.
     *
     * @param  array{name: string, class: class-string, ga4: string, meta?: string|null, posthog?: string, plausible?: string|null}|null  $catalogEntry
     * @return array{event: string, properties: array<string, mixed>, distinct_id?: string}
     */
    private function normalizeForPostHog(AnalyticsEvent $event, ?array $catalogEntry): array
    {
        $posthogEvent = $catalogEntry['posthog'] ?? $event->name;

        $properties = array_merge($event->params, [
            '$zb_event_name' => $event->name,
            '$zb_source' => $event->source ?? 'server',
        ]);

        $result = [
            'event' => $posthogEvent,
            'properties' => $properties,
        ];

        if ($event->clientId !== null) {
            $result['distinct_id'] = $event->clientId;
        } elseif ($event->userId !== null) {
            $result['distinct_id'] = $event->userId;
        }

        return $result;
    }

    /**
     * Normalize event for Plausible custom events.
     *
     * @param  array{name: string, class: class-string, ga4: string, meta?: string|null, posthog?: string, plausible?: string|null}|null  $catalogEntry
     * @return array{name: string, props: array<string, mixed>, domain: string}
     */
    private function normalizeForPlausible(AnalyticsEvent $event, ?array $catalogEntry): array
    {
        $plausibleName = $catalogEntry['plausible'] ?? null;

        // Plausible only supports pageviews and custom events
        if ($plausibleName === null) {
            // Page view events are tracked by Plausible's default script
            return [
                'name' => 'pageview',
                'props' => [],
                'domain' => $this->config->get('zeroboiler.analytics.plausible.domain', ''),
            ];
        }

        return [
            'name' => $plausibleName,
            'props' => $event->params,
            'domain' => $this->config->get('zeroboiler.analytics.plausible.domain', ''),
        ];
    }

    /**
     * Normalize event for Mixpanel track.
     *
     * @param  array{name: string, class: class-string, ga4: string, meta?: string|null, posthog?: string, plausible?: string|null}|null  $catalogEntry
     * @return array{event: string, properties: array<string, mixed>}
     */
    private function normalizeForMixpanel(AnalyticsEvent $event, ?array $catalogEntry): array
    {
        $properties = array_merge($event->params, [
            'zb_event_name' => $event->name,
            'zb_source' => $event->source ?? 'server',
        ]);

        if ($event->clientId !== null) {
            $properties['distinct_id'] = $event->clientId;
        }

        if ($event->userId !== null) {
            $properties['zb_user_id'] = $event->userId;
        }

        return [
            'event' => $event->name,
            'properties' => $properties,
        ];
    }

    /**
     * Normalize event for Amplitude track.
     *
     * @param  array{name: string, class: class-string, ga4: string, meta?: string|null, posthog?: string, plausible?: string|null}|null  $catalogEntry
     * @return array{event_type: string, event_properties: array<string, mixed>}
     */
    private function normalizeForAmplitude(AnalyticsEvent $event, ?array $catalogEntry): array
    {
        $properties = array_merge($event->params, [
            'zb_event_name' => $event->name,
            'zb_source' => $event->source ?? 'server',
        ]);

        if ($event->clientId !== null) {
            $properties['device_id'] = $event->clientId;
        }

        if ($event->userId !== null) {
            $properties['user_id'] = $event->userId;
        }

        return [
            'event_type' => $event->name,
            'event_properties' => $properties,
        ];
    }

    /**
     * Normalize event for Webhook dispatch.
     *
     * @param  array{name: string, class: class-string, ga4: string, meta?: string|null, posthog?: string, plausible?: string|null}|null  $catalogEntry
     * @return array{name: string, params: array<string, mixed>, client_id: string|null}
     */
    private function normalizeForWebhook(AnalyticsEvent $event, ?array $catalogEntry): array
    {
        return [
            'name' => $event->name,
            'params' => $event->params,
            'client_id' => $event->clientId,
        ];
    }

    /**
     * Check if a provider is enabled.
     */
    private function isProviderEnabled(string $provider): bool
    {
        return $this->enabledProviders[$provider] ?? false;
    }
}
