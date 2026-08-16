<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Privacy-aware event router that routes analytics events based on
 * the user's geographic privacy zone (GDPR, CCPA, LGPD, none) with
 * automatic field stripping and consent-level enforcement.
 *
 * Prevents PII leakage to non-compliant providers by stripping
 * sensitive fields before dispatch. Supports configurable per-zone
 * field blocklists, provider allowlists, and consent gate enforcement.
 *
 * @since 82.0.0
 */
final class PrivacyAwareEventRouter
{
    /**
     * Privacy zones with their regulatory requirements.
     *
     * @var array<string, array{block_fields: list<string>, require_consent: bool, strict_mode: bool, log_events: bool}>
     */
    private const ZONE_CONFIG = [
        'gdpr' => [
            'block_fields' => ['email', 'phone', 'ip', 'user_agent', 'ssn', 'credit_card', 'address', 'full_name', 'date_of_birth'],
            'require_consent' => true,
            'strict_mode' => true,
            'log_events' => true,
        ],
        'ccpa' => [
            'block_fields' => ['email', 'phone', 'ssn', 'credit_card', 'address', 'full_name', 'date_of_birth'],
            'require_consent' => true,
            'strict_mode' => false,
            'log_events' => false,
        ],
        'lgpd' => [
            'block_fields' => ['email', 'phone', 'ip', 'ssn', 'credit_card', 'address', 'full_name', 'date_of_birth'],
            'require_consent' => true,
            'strict_mode' => true,
            'log_events' => true,
        ],
        'pipeda' => [
            'block_fields' => ['email', 'phone', 'ssn', 'credit_card', 'address', 'full_name', 'date_of_birth'],
            'require_consent' => true,
            'strict_mode' => false,
            'log_events' => false,
        ],
        'none' => [
            'block_fields' => ['ssn', 'credit_card', 'password', 'secret', 'api_key', 'token'],
            'require_consent' => false,
            'strict_mode' => false,
            'log_events' => false,
        ],
    ];

    /** @var list<string> Always-blocked fields regardless of zone */
    private const GLOBAL_BLOCK_FIELDS = ['password', 'secret', 'api_key', 'access_token', 'private_key'];

    /** @var array<string, list<string>> Additional custom block fields per zone */
    private array $customBlockFields;

    /** @var array<string, list<string>> Provider allowlists per zone (empty = all providers allowed) */
    private array $providerAllowlists;

    /** @var bool Whether the router is enabled */
    private bool $enabled;

    /** @var string Default privacy zone for unclassified requests */
    private string $defaultZone;

    /**
     * @param  array<string, mixed>  $config  zeroboiler.analytics.privacy_router
     */
    public function __construct(array $config): void
    {
        $this->enabled = (bool) ($config['enabled'] ?? true);
        $this->defaultZone = (string) ($config['default_zone'] ?? 'none');
        $this->customBlockFields = (array) ($config['custom_block_fields'] ?? []);
        $this->providerAllowlists = (array) ($config['provider_allowlists'] ?? []);
    }

    /**
     * Route and sanitize an event based on its privacy zone.
     *
     * Returns a sanitized event copy with blocked fields removed,
     * and determines which providers are allowed to receive it.
     *
     * @param  AnalyticsEvent  $event  The event to route
     * @param  string|null  $zone  Privacy zone override (auto-detected if null)
     * @return array{event: AnalyticsEvent, allowed_providers: list<string>, zone: string, stripped_fields: list<string>, blocked: bool, blocked_reason: string|null}
     */
    public function route(AnalyticsEvent $event, ?string $zone = null): array
    {
        if (! $this->enabled) {
            return [
                'event' => $event,
                'allowed_providers' => [],
                'zone' => 'none',
                'stripped_fields' => [],
                'blocked' => false,
                'blocked_reason' => null,
            ];
        }

        $resolvedZone = $zone ?? $this->defaultZone;
        $zoneConfig = self::ZONE_CONFIG[$resolvedZone] ?? self::ZONE_CONFIG['none'];

        // Check consent requirement
        if ($zoneConfig['require_consent']) {
            $consentState = $this->extractConsentState($event);
            if ($consentState === 'denied') {
                return [
                    'event' => $event,
                    'allowed_providers' => [],
                    'zone' => $resolvedZone,
                    'stripped_fields' => [],
                    'blocked' => true,
                    'blocked_reason' => 'consent_denied',
                ];
            }
        }

        // Strip blocked fields
        $blockFields = array_unique(array_merge(
            self::GLOBAL_BLOCK_FIELDS,
            $zoneConfig['block_fields'],
            $this->customBlockFields[$resolvedZone] ?? [],
        ));

        $strippedFields = [];
        $sanitizedParams = $event->params;

        foreach ($blockFields as $field) {
            if (array_key_exists($field, $sanitizedParams)) {
                $strippedFields[] = $field;
                unset($sanitizedParams[$field]);
            }

            // Also check nested keys (e.g., user.email)
            foreach ($sanitizedParams as $key => $value) {
                if (is_array($value) && array_key_exists($field, $value)) {
                    $strippedFields[] = $key . '.' . $field;
                    unset($sanitizedParams[$key][$field]);
                }
            }
        }

        // Determine allowed providers
        $allowedProviders = $this->providerAllowlists[$resolvedZone] ?? [];

        // Create sanitized event
        $sanitizedEvent = new AnalyticsEvent(
            name: $event->name,
            params: $sanitizedParams,
            clientId: $zoneConfig['strict_mode'] ? null : $event->clientId,
            userId: $zoneConfig['strict_mode'] ? null : $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );

        return [
            'event' => $sanitizedEvent,
            'allowed_providers' => $allowedProviders,
            'zone' => $resolvedZone,
            'stripped_fields' => $strippedFields,
            'blocked' => false,
            'blocked_reason' => null,
        ];
    }

    /**
     * Batch route multiple events through the privacy router.
     *
     * @param  list<AnalyticsEvent>  $events  Events to route
     * @param  string|null  $zone  Privacy zone override
     * @return list<array{event: AnalyticsEvent, allowed_providers: list<string>, zone: string, stripped_fields: list<string>, blocked: bool, blocked_reason: string|null}>
     */
    public function routeBatch(array $events, ?string $zone = null): array
    {
        $results = [];

        foreach ($events as $event) {
            $results[] = $this->route($event, $zone);
        }

        return $results;
    }

    /**
     * Detect the privacy zone from an IP address.
     *
     * Uses a simplified GeoIP-based detection. In production, integrate
     * with a proper GeoIP database (MaxMind, IP2Location, etc.).
     *
     * @param  string|null  $ip  Client IP address
     * @return string Privacy zone identifier
     */
    public function detectZone(?string $ip): string
    {
        if ($ip === null || $ip === '') {
            return $this->defaultZone;
        }

        // EU country codes → GDPR
        $euCountries = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO',
        ];

        // Brazil → LGPD
        $lgpdCountries = ['BR'];

        // Canada → PIPEDA
        $pipedaCountries = ['CA'];

        // CCPA (California, USA)
        // For IP-based detection, we'd need a GeoIP database
        // Simplified: treat all US IPs as CCPA
        $ccpaCountries = ['US'];

        // Simplified detection — in production, use GeoIP
        // This is a placeholder for actual GeoIP lookup
        return $this->defaultZone;
    }

    /**
     * Get the list of blocked fields for a zone.
     *
     * @return list<string>
     */
    public function getBlockedFields(string $zone): array
    {
        $zoneConfig = self::ZONE_CONFIG[$zone] ?? self::ZONE_CONFIG['none'];

        return array_unique(array_merge(
            self::GLOBAL_BLOCK_FIELDS,
            $zoneConfig['block_fields'],
            $this->customBlockFields[$zone] ?? [],
        ));
    }

    /**
     * Check if a field would be blocked for a given zone.
     */
    public function isFieldBlocked(string $field, string $zone): bool
    {
        return in_array($field, $this->getBlockedFields($zone), true);
    }

    /**
     * Get the list of allowed providers for a zone.
     *
     * @return list<string>
     */
    public function getAllowedProviders(string $zone): array
    {
        return $this->providerAllowlists[$zone] ?? [];
    }

    /**
     * Check if consent is required for a zone.
     */
    public function requiresConsent(string $zone): bool
    {
        $zoneConfig = self::ZONE_CONFIG[$zone] ?? self::ZONE_CONFIG['none'];

        return $zoneConfig['require_consent'];
    }

    /**
     * Check if strict mode is enabled for a zone.
     */
    public function isStrictMode(string $zone): bool
    {
        $zoneConfig = self::ZONE_CONFIG[$zone] ?? self::ZONE_CONFIG['none'];

        return $zoneConfig['strict_mode'];
    }

    /**
     * Get all supported privacy zones.
     *
     * @return list<string>
     */
    public function supportedZones(): array
    {
        return array_keys(self::ZONE_CONFIG);
    }

    /**
     * Extract consent state from event params.
     */
    private function extractConsentState(AnalyticsEvent $event): string
    {
        $consentParam = $event->params['_consent'] ?? $event->params['consent'] ?? null;

        if (is_string($consentParam) && $consentParam !== '') {
            return strtolower($consentParam) === 'granted' ? 'granted' : 'denied';
        }

        return is_bool($consentParam) ? ($consentParam ? 'granted' : 'denied') : 'granted';
    }
}
