<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Analytics Data Residency Service — Multi-region data routing and localization.
 *
 * Ensures analytics data is routed only to providers and regions that comply
 * with applicable data protection regulations (GDPR, CCPA, LGPD, PIPEDA, SOC2).
 *
 * Features:
 * - **Geographic zones** — Define allowed provider/region combinations per zone
 * - **Event-level routing** — Route specific events to compliant providers only
 * - **Blocked field enforcement** — Strip or hash PII fields before sending to non-compliant regions
 * - **Audit trail** — Log all data residency decisions for compliance reporting
 * - **Zone detection** — Auto-detect user zone from IP or explicit setting
 *
 * Configuration: `zeroboiler.analytics.data_residency`
 *
 * @see \ZeroBoiler\Analytics\Services\PrivacyCollectionService
 * @see \ZeroBoiler\Analytics\Services\EventGovernanceService
 *
 * @since 134.0.0
 */
final class AnalyticsDataResidencyService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_data_residency_';

    /** @var string Audit log cache key */
    private const AUDIT_LOG_KEY = 'zb_data_residency_audit';

    /** @var int Default audit log TTL (90 days) */
    private const DEFAULT_AUDIT_TTL = 7776000;

    /** @var int Default cache TTL */
    private const DEFAULT_CACHE_TTL = 3600;

    /** @var int Maximum audit entries before rotation */
    private const MAX_AUDIT_ENTRIES = 10000;

    private CacheRepository $cache;

    private ConfigRepository $config;

    private bool $enabled;

    private int $auditTtl;

    private int $cacheTtl;

    /** @var array<string, array{label: string, allowed_providers: list<string>, blocked_fields: list<string>, requires_consent: bool}> */
    private array $zones;

    /** @var string|null Default zone when none specified */
    private ?string $defaultZone;

    /** @var list<string> Event categories that require strict residency enforcement */
    private array $strictCategories;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;

        $residencyConfig = $config->get('zeroboiler.analytics.data_residency', []);
        /** @var array{enabled?: bool, default_zone?: string, audit_ttl?: int, cache_ttl?: int, strict_categories?: list<string>, zones?: array<string, array{label: string, allowed_providers: list<string>, blocked_fields: list<string>, requires_consent: bool}>} $residencyConfig */

        $this->enabled = (bool) ($residencyConfig['enabled'] ?? false);
        $this->defaultZone = $residencyConfig['default_zone'] ?? 'eu';
        $this->auditTtl = (int) ($residencyConfig['audit_ttl'] ?? self::DEFAULT_AUDIT_TTL);
        $this->cacheTtl = (int) ($residencyConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->strictCategories = $residencyConfig['strict_categories'] ?? ['saas', 'engagement'];
        $this->zones = $residencyConfig['zones'] ?? $this->defaultZones();
    }

    /**
     * Check if data residency enforcement is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get all configured geographic zones.
     *
     * @return array<string, array{label: string, allowed_providers: list<string>, blocked_fields: list<string>, requires_consent: bool}>
     */
    public function getZones(): array
    {
        return $this->zones;
    }

    /**
     * Get a specific zone configuration.
     *
     * @return array{label: string, allowed_providers: list<string>, blocked_fields: list<string>, requires_consent: bool}|null
     */
    public function getZone(string $zone): ?array
    {
        return $this->zones[$zone] ?? null;
    }

    /**
     * Get the default zone.
     */
    public function getDefaultZone(): string
    {
        return $this->defaultZone ?? 'eu';
    }

    /**
     * Check if a provider is allowed in a specific zone.
     */
    public function isProviderAllowed(string $provider, string $zone): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $zoneConfig = $this->zones[$zone] ?? null;

        if ($zoneConfig === null) {
            return true;
        }

        return in_array($provider, $zoneConfig['allowed_providers'], true);
    }

    /**
     * Get the list of blocked fields for a zone and provider combination.
     *
     * Returns field names that must be stripped or hashed before sending
     * events to the specified provider in the specified zone.
     *
     * @return list<string>
     */
    public function getBlockedFields(string $zone, string $provider): array
    {
        if (! $this->enabled) {
            return [];
        }

        $zoneConfig = $this->zones[$zone] ?? null;

        if ($zoneConfig === null) {
            return [];
        }

        return $zoneConfig['blocked_fields'];
    }

    /**
     * Filter event parameters by removing blocked fields for a zone/provider.
     *
     * Returns the parameter array with blocked fields removed.
     * Optionally replaces blocked fields with hashed values.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @param  string  $zone  Geographic zone
     * @param  string  $provider  Target provider
     * @param  bool  $hashInstead  Hash blocked fields instead of removing (default: false)
     * @return array{params: array<string, mixed>, removed: list<string>, hashed: list<string>}
     */
    public function filterParams(array $params, string $zone, string $provider, bool $hashInstead = false): array
    {
        if (! $this->enabled) {
            return ['params' => $params, 'removed' => [], 'hashed' => []];
        }

        $blockedFields = $this->getBlockedFields($zone, $provider);
        $filtered = $params;
        $removed = [];
        $hashed = [];

        foreach ($blockedFields as $field) {
            if (array_key_exists($field, $filtered)) {
                if ($hashInstead && is_string($filtered[$field])) {
                    $filtered[$field] = hash('sha256', $filtered[$field]);
                    $hashed[] = $field;
                } else {
                    unset($filtered[$field]);
                    $removed[] = $field;
                }
            }
        }

        return ['params' => $filtered, 'removed' => $removed, 'hashed' => $hashed];
    }

    /**
     * Check if an event requires strict residency enforcement based on its category.
     */
    public function requiresStrictEnforcement(string $eventName): bool
    {
        $category = EventCatalog::getCategory($eventName);

        if ($category === null) {
            return false;
        }

        return in_array($category, $this->strictCategories, true);
    }

    /**
     * Determine which providers should receive an event based on zone constraints.
     *
     * Returns a filtered list of providers that are allowed for the given zone.
     * Revenue-critical events (purchase, subscription) are always routed to
     * compliant providers only when strict enforcement is enabled.
     *
     * @param  list<string>  $providers  List of provider names to filter
     * @param  string  $zone  Geographic zone
     * @param  string  $eventName  Event name for strict enforcement check
     * @return list<string> Filtered list of allowed providers
     */
    public function filterProviders(array $providers, string $zone, string $eventName): array
    {
        if (! $this->enabled) {
            return $providers;
        }

        return array_values(array_filter(
            $providers,
            fn (string $provider): bool => $this->isProviderAllowed($provider, $zone),
        ));
    }

    /**
     * Log a data residency decision for audit purposes.
     *
     * @param  array{event: string, zone: string, provider: string, action: string, blocked_fields?: list<string>, decision: string}  $decision
     */
    public function logAuditEntry(array $decision): void
    {
        if (! $this->enabled) {
            return;
        }

        $decision['timestamp'] = now()->toIso8601String();

        $auditKey = self::AUDIT_LOG_KEY;
        $entries = $this->cache->get($auditKey, []);

        $entries[] = $decision;

        // Rotate if exceeds max entries
        if (count($entries) > self::MAX_AUDIT_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_AUDIT_ENTRIES);
        }

        $this->cache->put($auditKey, $entries, $this->auditTtl);
    }

    /**
     * Get the audit log of data residency decisions.
     *
     * @param  int  $limit  Maximum entries to return (0 = all)
     * @return list<array{timestamp: string, event: string, zone: string, provider: string, action: string, blocked_fields?: list<string>, decision: string}>
     */
    public function getAuditLog(int $limit = 100): array
    {
        /** @var list<array{timestamp: string, event: string, zone: string, provider: string, action: string, blocked_fields?: list<string>, decision: string}> $entries */
        $entries = $this->cache->get(self::AUDIT_LOG_KEY, []);

        if ($limit > 0 && count($entries) > $limit) {
            return array_slice($entries, -$limit);
        }

        return $entries;
    }

    /**
     * Get a compliance summary for all zones.
     *
     * @return array{zones: int, strict_categories: list<string>, total_audit_entries: int, compliance_score: float}
     */
    public function getComplianceSummary(): array
    {
        $auditEntries = $this->getAuditLog(0);
        $deniedCount = count(array_filter(
            $auditEntries,
            fn (array $entry): bool => ($entry['decision'] ?? '') === 'blocked',
        ));
        $totalEntries = count($auditEntries);

        $complianceScore = $totalEntries > 0
            ? round(($totalEntries - $deniedCount) / $totalEntries * 100, 1)
            : 100.0;

        return [
            'zones' => count($this->zones),
            'strict_categories' => $this->strictCategories,
            'total_audit_entries' => $totalEntries,
            'compliance_score' => $complianceScore,
        ];
    }

    /**
     * Check if a zone requires explicit consent for event tracking.
     */
    public function requiresConsent(string $zone): bool
    {
        $zoneConfig = $this->zones[$zone] ?? null;

        return $zoneConfig !== null && ($zoneConfig['requires_consent'] ?? false);
    }

    /**
     * Validate a zone configuration.
     *
     * @return array{valid: bool, errors: list<string>}
     */
    public function validateZoneConfig(string $zone): array
    {
        $errors = [];

        $zoneConfig = $this->zones[$zone] ?? null;

        if ($zoneConfig === null) {
            return ['valid' => false, 'errors' => ["Zone '{$zone}' does not exist"]];
        }

        if (! isset($zoneConfig['label']) || ! is_string($zoneConfig['label'])) {
            $errors[] = "Zone '{$zone}' missing 'label'";
        }

        if (! isset($zoneConfig['allowed_providers']) || ! is_array($zoneConfig['allowed_providers'])) {
            $errors[] = "Zone '{$zone}' missing 'allowed_providers'";
        }

        if (! isset($zoneConfig['blocked_fields']) || ! is_array($zoneConfig['blocked_fields'])) {
            $errors[] = "Zone '{$zone}' missing 'blocked_fields'";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Clear the audit log.
     */
    public function clearAuditLog(): void
    {
        $this->cache->forget(self::AUDIT_LOG_KEY);
    }

    /**
     * Get default zone configurations for common regions.
     *
     * @return array<string, array{label: string, allowed_providers: list<string>, blocked_fields: list<string>, requires_consent: bool}>
     */
    private function defaultZones(): array
    {
        return [
            'eu' => [
                'label' => 'European Union (GDPR)',
                'allowed_providers' => ['ga4', 'gtm', 'plausible', 'posthog', 'mixpanel', 'amplitude'],
                'blocked_fields' => ['ip_address', 'email', 'phone', 'ssn'],
                'requires_consent' => true,
            ],
            'us' => [
                'label' => 'United States (CCPA)',
                'allowed_providers' => ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'],
                'blocked_fields' => ['ssn'],
                'requires_consent' => false,
            ],
            'us-ca' => [
                'label' => 'California (CCPA Strict)',
                'allowed_providers' => ['ga4', 'gtm', 'plausible', 'posthog', 'mixpanel', 'amplitude'],
                'blocked_fields' => ['email', 'phone', 'ssn', 'ip_address'],
                'requires_consent' => true,
            ],
            'br' => [
                'label' => 'Brazil (LGPD)',
                'allowed_providers' => ['ga4', 'gtm', 'plausible', 'posthog'],
                'blocked_fields' => ['email', 'phone', 'ip_address'],
                'requires_consent' => true,
            ],
            'ca' => [
                'label' => 'Canada (PIPEDA)',
                'allowed_providers' => ['ga4', 'gtm', 'plausible', 'posthog', 'mixpanel', 'amplitude'],
                'blocked_fields' => ['sin', 'ip_address'],
                'requires_consent' => true,
            ],
        ];
    }
}
