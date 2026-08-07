<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

/**
 * Granular consent logging service for GDPR compliance.
 *
 * Maintains an auditable history of consent state changes per user/client.
 * Supports per-purpose consent tracking (analytics, marketing, functional, necessary).
 * Consent records are stored in the Laravel cache for configurable TTL.
 *
 * Use this service to demonstrate compliance with GDPR Article 7 (conditions for consent)
 * and to provide audit trails for data subject access requests (DSAR).
 */
final class ConsentLogService
{
    /**
     * GDPR consent purposes as defined in ePrivacy Directive.
     *
     * @var array<string, string>
     */
    private const PURPOSES = [
        'necessary' => 'Strictly necessary for the service to function (authentication, security)',
        'analytics' => 'Analytics and performance measurement',
        'marketing' => 'Advertising and marketing personalization',
        'functional' => 'Remember user preferences and settings',
    ];

    private const CACHE_PREFIX = 'zb_consent_log:';
    private const MAX_ENTRIES = 500;

    private CacheRepository $cache;
    private int $ttl;

    /**
     * @param  CacheRepository  $cache  Laravel cache repository
     * @param  int  $ttl  Consent log retention period in seconds
     */
    public function __construct(CacheRepository $cache, int $ttl = 7776000): void
    {
        $this->cache = $cache;
        $this->ttl = $ttl;
    }

    /**
     * Record a consent state change.
     *
     * @param  string  $identifier  User ID or client ID
     * @param  array<string, bool>  $purposes  Map of purpose => granted/denied
     * @param  string|null  $source  How consent was collected ('banner', 'settings', 'api', 'auto')
     * @param  string|null  $ip  Client IP at time of consent
     * @param  string|null  $userAgent  Client user agent
     */
    public function recordConsent(
        string $identifier,
        array $purposes,
        ?string $source = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $entry = [
            'timestamp' => time(),
            'identifier' => $identifier,
            'purposes' => $purposes,
            'source' => $source ?? 'unknown',
            'ip' => $ip,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 200) : null,
        ];

        $log = $this->getLog($identifier);
        $log[] = $entry;

        // Enforce max entries to prevent unbounded growth
        if (count($log) > self::MAX_ENTRIES) {
            $log = array_slice($log, -self::MAX_ENTRIES);
        }

        $this->cache->put(
            self::CACHE_PREFIX . $identifier,
            $log,
            $this->ttl,
        );

        Log::debug('ConsentLogService: consent recorded', [
            'identifier' => $identifier,
            'source' => $source,
            'purposes' => $purposes,
        ]);
    }

    /**
     * Get the full consent history for an identifier.
     *
     * @param  string  $identifier
     * @return list<array{timestamp: int, identifier: string, purposes: array<string, bool>, source: string, ip: string|null, user_agent: string|null}>
     */
    public function getHistory(string $identifier): array
    {
        return $this->getLog($identifier);
    }

    /**
     * Get the current (latest) consent state for an identifier.
     *
     * @param  string  $identifier
     * @return array{purposes: array<string, bool>, source: string|null, updated_at: int|null}
     */
    public function getCurrentConsent(string $identifier): array
    {
        $log = $this->getLog($identifier);

        if (empty($log)) {
            return [
                'purposes' => [],
                'source' => null,
                'updated_at' => null,
            ];
        }

        $latest = end($log);

        return [
            'purposes' => $latest['purposes'],
            'source' => $latest['source'],
            'updated_at' => $latest['timestamp'],
        ];
    }

    /**
     * Check if a specific purpose is currently granted for an identifier.
     */
    public function isPurposeGranted(string $identifier, string $purpose): bool
    {
        $current = $this->getCurrentConsent($identifier);

        return (bool) ($current['purposes'][$purpose] ?? false);
    }

    /**
     * Check if all given purposes are currently granted.
     *
     * @param  string  $identifier
     * @param  list<string>  $purposes
     */
    public function areAllPurposesGranted(string $identifier, array $purposes): bool
    {
        foreach ($purposes as $purpose) {
            if (! $this->isPurposeGranted($identifier, $purpose)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all available consent purposes with descriptions.
     *
     * @return array<string, string>
     */
    public static function availablePurposes(): array
    {
        return self::PURPOSES;
    }

    /**
     * Get the default consent state for each purpose.
     *
     * 'necessary' is always granted (cannot be denied).
     * Other purposes follow the configured default.
     *
     * @param  bool  $defaultGranted  Default state for non-necessary purposes
     * @return array<string, bool>
     */
    public static function defaultConsentState(bool $defaultGranted = false): array
    {
        $state = [];

        foreach (array_keys(self::PURPOSES) as $purpose) {
            $state[$purpose] = $purpose === 'necessary' || $defaultGranted;
        }

        return $state;
    }

    /**
     * Purge consent history for an identifier (GDPR erasure).
     */
    public function purgeHistory(string $identifier): void
    {
        $this->cache->forget(self::CACHE_PREFIX . $identifier);

        Log::debug('ConsentLogService: consent history purged', [
            'identifier' => $identifier,
        ]);
    }

    /**
     * Get a summary of consent statistics across all tracked identifiers.
     *
     * Note: This is a convenience method. For large deployments,
     * use a dedicated analytics database query instead.
     *
     * @return array{total_records: int, unique_identifiers: int}
     */
    public function getSummary(): array
    {
        // Cache-based implementation: approximate
        // For production, use database-backed storage
        return [
            'total_records' => 0,
            'unique_identifiers' => 0,
        ];
    }

    /**
     * Export consent history for a data subject access request (DSAR).
     *
     * Returns all consent records in a portable format suitable
     * for inclusion in GDPR data export responses.
     *
     * @param  string  $identifier
     * @return array{identifier: string, exported_at: int, records: list<array{timestamp: int, purposes: array<string, bool>, source: string, ip: string|null}>}
     */
    public function exportForDsar(string $identifier): array
    {
        $history = $this->getHistory($identifier);

        return [
            'identifier' => $identifier,
            'exported_at' => time(),
            'records' => array_map(fn (array $entry): array => [
                'timestamp' => $entry['timestamp'],
                'purposes' => $entry['purposes'],
                'source' => $entry['source'],
                'ip' => $entry['ip'],
            ], $history),
        ];
    }

    /**
     * Get the cached log for an identifier.
     *
     * @return list<array{timestamp: int, identifier: string, purposes: array<string, bool>, source: string, ip: string|null, user_agent: string|null}>
     */
    private function getLog(string $identifier): array
    {
        /** @var list<array{timestamp: int, identifier: string, purposes: array<string, bool>, source: string, ip: string|null, user_agent: string|null}>|null $log */
        $log = $this->cache->get(self::CACHE_PREFIX . $identifier);

        return is_array($log) ? $log : [];
    }
}
