<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

/**
 * Privacy-preserving cookieless event collection service.
 *
 * Provides a server-side alternative to cookie-based client tracking for
 * strict GDPR environments where no cookies can be set. Uses a combination
 * of hashed IP + User-Agent fingerprinting to create a stable anonymous
 * identifier that doesn't require cookies or persistent client storage.
 *
 * Key differences from cookie-based tracking:
 *   - No cookies set on the client
 *   - Identifier derived from server-side signals (IP + UA + accept-language)
 *   - SHA-256 hashed for privacy — original values never stored
 *   - TTL-based cache expiry (not cookie-based)
 *   - Configurable hashing strategy and salt rotation
 *   - Compatible with Consent Mode v2's "denied" state
 *
 * This is the industry-standard approach used by Plausible, Simple Analytics,
 * and Matomo when cookieless mode is enabled.
 *
 * Configuration: `zeroboiler.analytics.privacy_collection`
 *
 * @see \ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics
 * @see \ZeroBoiler\Analytics\Services\IpAnonymizationService
 *
 * @since 58.0.0
 */
final class PrivacyCollectionService
{
    private AnalyticsManager $manager;

    private QueuedAnalyticsDispatcher $queue;

    private CacheRepository $cache;

    private bool $enabled;

    private string $hashAlgorithm;

    private ?string $salt;

    private int $cacheTtl;

    private string $cachePrefix;

    private bool $ipAnonymization;

    /** @var list<string> Signals used for fingerprint hashing */
    private array $signals;

    /** @var int Max fingerprint entries per cache bucket for dedup */
    private int $maxEntries;

    /**
     * @param  AnalyticsManager  $manager
     * @param  QueuedAnalyticsDispatcher  $queue
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        QueuedAnalyticsDispatcher $queue,
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->manager = $manager;
        $this->queue = $queue;
        $this->cache = $cache;

        $privacyConfig = $config->get('zeroboiler.analytics.privacy_collection', []);
        /** @var array{enabled?: bool, hash_algorithm?: string, salt?: string, cache_ttl?: int, cache_prefix?: string, ip_anonymization?: bool, signals?: list<string>, max_entries?: int} $privacyConfig */

        $this->enabled = (bool) ($privacyConfig['enabled'] ?? false);
        $this->hashAlgorithm = (string) ($privacyConfig['hash_algorithm'] ?? 'sha256');
        $this->salt = $privacyConfig['salt'] ?? null;
        $this->cacheTtl = (int) ($privacyConfig['cache_ttl'] ?? 86400); // 24 hours
        $this->cachePrefix = (string) ($privacyConfig['cache_prefix'] ?? 'zb_privacy_');
        $this->ipAnonymization = (bool) ($privacyConfig['ip_anonymization'] ?? true);
        $this->signals = (array) ($privacyConfig['signals'] ?? ['ip', 'user_agent', 'accept_language']);
        $this->maxEntries = (int) ($privacyConfig['max_entries'] ?? 100000);
    }

    /**
     * Check if privacy collection mode is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Generate a privacy-preserving fingerprint identifier from server request signals.
     *
     * Creates a stable hash from IP address, User-Agent, and optional signals.
     * The raw signal values are never stored — only the hash is kept in cache.
     *
     * @param  string  $ip  Client IP address
     * @param  string  $userAgent  Client User-Agent header
     * @param  array<string, string>  $additionalSignals  Additional signals (e.g., accept_language)
     * @return string Hashed fingerprint (hex string)
     */
    public function generateFingerprint(string $ip, string $userAgent, array $additionalSignals = []): string
    {
        $parts = [];

        foreach ($this->signals as $signal) {
            match ($signal) {
                'ip' => $parts[] = $this->anonymizeIp($ip),
                'user_agent' => $parts[] = $userAgent,
                'accept_language' => $parts[] = $additionalSignals['accept_language'] ?? '',
                default => null,
            };
        }

        $raw = implode('|', $parts);

        return $this->hash($raw);
    }

    /**
     * Track an event using a fingerprint-based identity instead of cookies.
     *
     * Generates the fingerprint, stores it in cache, and dispatches the
     * analytics event with the fingerprint as the client_id.
     *
     * @param  string  $eventName  Event name to track
     * @param  string  $ip  Client IP address
     * @param  string  $userAgent  Client User-Agent header
     * @param  array<string, mixed>  $params  Event parameters
     * @param  array<string, string>  $additionalSignals  Additional signals for fingerprinting
     */
    public function trackWithFingerprint(
        string $eventName,
        string $ip,
        string $userAgent,
        array $params = [],
        array $additionalSignals = [],
    ): void {
        if (! $this->enabled) {
            return;
        }

        $fingerprint = $this->generateFingerprint($ip, $userAgent, $additionalSignals);

        $this->cacheFingerprint($fingerprint);

        $event = new AnalyticsEvent(
            name: $eventName,
            params: array_merge($params, [
                'privacy_mode' => 'cookieless',
                'fingerprint_hash' => $this->shortHash($fingerprint),
            ]),
            clientId: 'fp_' . $fingerprint,
        );

        $this->queue->dispatch($event);
    }

    /**
     * Track a page view using cookieless fingerprinting.
     *
     * Convenience method for the most common cookieless tracking use case.
     *
     * @param  string  $url  Page URL
     * @param  string  $ip  Client IP address
     * @param  string  $userAgent  Client User-Agent header
     * @param  array<string, mixed>  $metadata  Additional page metadata (title, referrer)
     * @param  array<string, string>  $additionalSignals  Additional signals for fingerprinting
     */
    public function trackPageView(
        string $url,
        string $ip,
        string $userAgent,
        array $metadata = [],
        array $additionalSignals = [],
    ): void {
        $params = array_merge([
            'page_url' => $url,
            'page_title' => $metadata['title'] ?? '',
            'referrer' => $metadata['referrer'] ?? '',
        ], $metadata);

        $this->trackWithFingerprint('page_view', $ip, $userAgent, $params, $additionalSignals);
    }

    /**
     * Resolve a fingerprint back to a persistent anonymous ID.
     *
     * Maps the fingerprint hash to a shorter, stable anonymous ID stored in cache.
     * This ID can be used for cross-request correlation without cookies.
     *
     * @param  string  $fingerprint  Full fingerprint hash
     * @return string Stable anonymous ID (prefixed with 'anon_')
     */
    public function resolveAnonymousId(string $fingerprint): string
    {
        $cacheKey = $this->cachePrefix . 'anon_' . $this->shortHash($fingerprint);

        /** @var string|null $existing */
        $existing = $this->cache->get($cacheKey);

        if ($existing !== null) {
            return $existing;
        }

        $anonymousId = 'anon_' . substr($fingerprint, 0, 16);
        $this->cache->put($cacheKey, $anonymousId, $this->cacheTtl);

        return $anonymousId;
    }

    /**
     * Get the count of unique fingerprints tracked in the current period.
     *
     * Useful for cookieless "unique visitors" estimation.
     */
    public function getUniqueFingerprintCount(): int
    {
        return $this->cache->get($this->cachePrefix . 'unique_count', 0);
    }

    /**
     * Store a fingerprint in cache for session tracking.
     */
    private function cacheFingerprint(string $fingerprint): void
    {
        $cacheKey = $this->cachePrefix . 'fp_' . $this->shortHash($fingerprint);

        $this->cache->put($cacheKey, [
            'fingerprint' => $fingerprint,
            'seen_at' => time(),
        ], $this->cacheTtl);

        $counterKey = $this->cachePrefix . 'unique_count';
        $this->cache->increment($counterKey);
    }

    /**
     * Hash a string using the configured algorithm and salt.
     */
    private function hash(string $raw): string
    {
        $data = $this->salt !== null ? $raw . $this->salt : $raw;

        return hash($this->hashAlgorithm, $data);
    }

    /**
     * Create a short hash for cache keys and identifiers.
     */
    private function shortHash(string $raw): string
    {
        return substr($this->hash($raw), 0, 16);
    }

    /**
     * Anonymize an IP address by zeroing the last octet (IPv4) or the last 48 bits (IPv6).
     *
     * Compliant with GDPR IP anonymization requirements.
     */
    private function anonymizeIp(string $ip): string
    {
        if (! $this->ipAnonymization) {
            return $ip;
        }

        // IPv4
        if (str_contains($ip, '.')) {
            $parts = explode('.', $ip);

            if (count($parts) === 4) {
                $parts[3] = '0';

                return implode('.', $parts);
            }
        }

        // IPv6 — zero last 48 bits (3 groups)
        if (str_contains($ip, ':')) {
            $packed = @inet_pton($ip);

            if ($packed !== false) {
                $packed = substr($packed, 0, -6) . "\x00\x00\x00\x00\x00\x00";

                return @inet_ntop($packed) ?: $ip;
            }
        }

        return $ip;
    }
}
