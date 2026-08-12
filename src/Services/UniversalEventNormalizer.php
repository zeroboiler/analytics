<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

/**
 * Cross-provider universal event normalizer.
 *
 * Transforms a single AnalyticsEvent into provider-specific payloads
 * using catalog mappings and the EcommerceFormatConverter. Ensures that
 * each provider receives events in its native format with correct
 * parameter naming, structure, and type conventions.
 *
 * Supports all 8 providers: GA4, GTM, Meta Pixel, Plausible, PostHog,
 * Mixpanel, Amplitude, and Webhook.
 *
 * @since 28.0.0
 */
final class UniversalEventNormalizer
{
    /** @var array<string, string> Canonical event name → provider-specific name */
    private array $catalogCache = [];

    /**
     * Normalize an event for a specific provider.
     *
     * Converts the event name using catalog mappings and transforms
     * the parameters to match the provider's expected format.
     *
     * @param  'ga4'|'meta'|'posthog'|'plausible'|'mixpanel'|'amplitude'|'webhook'|'gtm'  $provider
     * @return array{name: string, params: array<string, mixed>}
     */
    public function normalize(AnalyticsEvent $event, string $provider): array
    {
        $normalizedName = $this->resolveEventName($event->name, $provider);
        $normalizedParams = $this->resolveParams($event->params, $provider, $event->name);

        // E-commerce events get special cross-format conversion
        if ($this->isEcommerceEvent($event->name)) {
            $normalizedParams = $this->normalizeEcommerceParams(
                $event->params,
                $provider,
                $event->name,
            );
        }

        // Attach identity fields
        if ($event->clientId !== null) {
            $normalizedParams = $this->attachClientId($normalizedParams, $provider, $event->clientId);
        }

        if ($event->userId !== null) {
            $normalizedParams = $this->attachUserId($normalizedParams, $provider, $event->userId);
        }

        // Attach timestamp
        $normalizedParams = $this->attachTimestamp($normalizedParams, $provider, $event->timestamp);

        return [
            'name' => $normalizedName,
            'params' => $normalizedParams,
        ];
    }

    /**
     * Normalize an event for all enabled providers at once.
     *
     * Returns a map of provider name → normalized payload.
     *
     * @param  list<string>  $enabledProviders  List of enabled provider identifiers
     * @return array<string, array{name: string, params: array<string, mixed>}>
     */
    public function normalizeForAll(AnalyticsEvent $event, array $enabledProviders): array
    {
        $result = [];

        foreach ($enabledProviders as $provider) {
            $result[$provider] = $this->normalize($event, $provider);
        }

        return $result;
    }

    /**
     * Get the provider-specific event name from the catalog.
     *
     * Falls back to the original name if no mapping exists.
     */
    public function resolveEventName(string $eventName, string $provider): string
    {
        $cacheKey = "{$eventName}:{$provider}";

        if (isset($this->catalogCache[$cacheKey])) {
            return $this->catalogCache[$cacheKey];
        }

        $name = match ($provider) {
            'ga4' => EventCatalog::get($eventName)['ga4'] ?? $eventName,
            'meta' => EventCatalog::get($eventName)['meta'] ?? $eventName,
            'posthog' => EventCatalog::get($eventName)['posthog'] ?? $eventName,
            'plausible' => EventCatalog::get($eventName)['plausible'] ?? $eventName,
            'mixpanel' => EventCatalog::get($eventName)['mixpanel'] ?? $eventName,
            'amplitude' => EventCatalog::get($eventName)['amplitude'] ?? $eventName,
            default => $eventName,
        };

        $this->catalogCache[$cacheKey] = $name;

        return $name;
    }

    /**
     * Check if an event belongs to the e-commerce category.
     */
    private function isEcommerceEvent(string $eventName): bool
    {
        return EventCatalog::getCategory($eventName) === 'ecommerce';
    }

    /**
     * Normalize e-commerce event parameters for a specific provider.
     *
     * Uses EcommerceFormatConverter for GA4 ↔ Meta ↔ PostHog conversions.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function normalizeEcommerceParams(array $params, string $provider, string $eventName): array
    {
        if ($provider === 'meta' && $eventName === 'purchase') {
            return EcommerceFormatConverter::ga4ToMetaPurchase($params);
        }

        if ($provider === 'meta' && $eventName === 'refund') {
            return EcommerceFormatConverter::ga4ToMetaRefund($params);
        }

        if ($provider === 'meta' && in_array($eventName, ['add_to_cart', 'view_item'], true)) {
            $items = $params['items'] ?? [];
            /** @var array<int, array<string, mixed>> $items */
            $metaItems = EcommerceFormatConverter::ga4ToMetaContents($items);

            return array_merge($params, [
                'content_ids' => $metaItems['content_ids'],
                'contents' => $metaItems['contents'],
                'num_items' => $metaItems['num_items'],
                'value' => $metaItems['value'],
            ]);
        }

        if ($provider === 'posthog' && $eventName === 'purchase') {
            return EcommerceFormatConverter::ga4ToPosthogPurchase($params);
        }

        if ($provider === 'posthog' && $eventName === 'refund') {
            return EcommerceFormatConverter::ga4ToPosthogRefund($params);
        }

        return $params;
    }

    /**
     * Attach client ID in provider-specific format.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function attachClientId(array $params, string $provider, string $clientId): array
    {
        return match ($provider) {
            'ga4' => array_merge($params, ['client_id' => $clientId]),
            'posthog' => array_merge($params, ['distinct_id' => $clientId]),
            'mixpanel' => array_merge($params, ['distinct_id' => $clientId]),
            'amplitude' => array_merge($params, ['device_id' => $clientId]),
            'meta' => array_merge($params, ['event_id' => $clientId]),
            'webhook' => array_merge($params, ['client_id' => $clientId]),
            default => array_merge($params, ['client_id' => $clientId]),
        };
    }

    /**
     * Attach user ID in provider-specific format.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function attachUserId(array $params, string $provider, string $userId): array
    {
        return match ($provider) {
            'ga4' => array_merge($params, ['user_id' => $userId]),
            'posthog' => array_merge($params, ['$user_id' => $userId]),
            'mixpanel' => array_merge($params, ['$user_id' => $userId]),
            'amplitude' => array_merge($params, ['user_id' => $userId]),
            'meta' => array_merge($params, ['external_id' => $userId]),
            'webhook' => array_merge($params, ['user_id' => $userId]),
            default => array_merge($params, ['user_id' => $userId]),
        };
    }

    /**
     * Attach timestamp in provider-specific format.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function attachTimestamp(array $params, string $provider, ?\DateTimeImmutable $timestamp): array
    {
        if ($timestamp === null) {
            return $params;
        }

        $iso = $timestamp->format(\DateTimeInterface::ATOM);

        return match ($provider) {
            'ga4' => $params, // GA4 MP uses its own timestamp
            'posthog' => array_merge($params, ['timestamp' => $iso]),
            'mixpanel' => array_merge($params, ['time' => $timestamp->getTimestamp() * 1000]),
            'amplitude' => array_merge($params, ['time' => $timestamp->getTimestamp() * 1000]),
            'webhook' => array_merge($params, ['timestamp' => $iso]),
            default => array_merge($params, ['timestamp' => $iso]),
        };
    }

    /**
     * Resolve basic parameter transformations for non-ecommerce events.
     *
     * Handles common field name differences between providers.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function resolveParams(array $params, string $provider, string $eventName): array
    {
        // GTM receives events via dataLayer push — params stay flat
        if ($provider === 'gtm') {
            return array_merge($params, [
                'event' => $this->resolveEventName($eventName, 'gtm'),
            ]);
        }

        // Plausible only supports pageview natively — custom events go through API
        if ($provider === 'plausible') {
            $plausibleName = EventCatalog::get($eventName)['plausible'] ?? null;

            if ($plausibleName === null) {
                return array_merge($params, [
                    'name' => $eventName,
                ]);
            }
        }

        return $params;
    }

    /**
     * Clear the internal catalog cache.
     *
     * Useful for testing or after hot-reloading catalog changes.
     */
    public function clearCache(): void
    {
        $this->catalogCache = [];
    }
}
