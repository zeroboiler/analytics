<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;

/**
 * Deterministic session fingerprint service for bot detection and quality scoring.
 *
 * Generates a stable fingerprint from browser characteristics (user agent,
 * screen resolution, color depth, timezone, language, platform) that persists
 * across page loads within a session. Used for:
 *
 * - Bot/spam detection (known bot fingerprints get blocked)
 * - Session quality scoring (unique fingerprints = real users)
 * - Event quality enrichment (attach fingerprint hash to events)
 * - Cross-request identity linking
 *
 * Uses the cache driver for fingerprint tracking with configurable TTL.
 * Fingerprints are stored with a score (0-100) based on entropy and uniqueness.
 *
 * Inspired by FingerprintJS, Imperva bot detection, and Cloudflare Bot Management.
 *
 * @since 25.0.0
 */
final class SessionFingerprintService
{
    /** @var non-empty-string */
    private string $cachePrefix;

    /** @var positive-int */
    private int $fingerprintTtl;

    /** @var positive-int */
    private int $maxFingerprintsPerClient;

    private CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache
     * @param  array{cache_prefix?: string, fingerprint_ttl?: int, max_fingerprints_per_client?: int}  $config
     */
    public function __construct(CacheRepository $cache, array $config = []){
        $this->cache = $cache;
        $this->cachePrefix = $config['cache_prefix'] ?? 'zb_fp_';
        $this->fingerprintTtl = $config['fingerprint_ttl'] ?? 3600; // 1 hour
        $this->maxFingerprintsPerClient = $config['max_fingerprints_per_client'] ?? 10;
    }

    /**
     * Generate a fingerprint from the given browser characteristics.
     *
     * Produces a deterministic SHA-256 hash from a normalized set of browser
     * signals. The same input always produces the same hash.
     *
     * @param  array{user_agent?: string, screen_width?: int, screen_height?: int, color_depth?: int, timezone?: string, language?: string, platform?: string, canvas_hash?: string}  $signals  Browser signals (typically from JS client)
     * @return string 64-character hex SHA-256 hash
     */
    public function generateFingerprint(array $signals): string
    {
        $normalized = $this->normalizeSignals($signals);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * Generate a fingerprint from an HTTP request (server-side only signals).
     *
     * Uses HTTP headers available on the server: User-Agent, Accept-Language,
     * and Sec-CH-UA (Client Hints) when available.
     *
     * @return string 64-character hex SHA-256 hash
     */
    public function generateFromRequest(Request $request): string
    {
        $signals = [
            'user_agent' => $request->userAgent() ?? '',
            'language' => $request->header('Accept-Language', ''),
            'platform' => $request->header('Sec-CH-UA-Platform', ''),
            'mobile' => $request->header('Sec-CH-UA-Mobile', ''),
        ];

        return $this->generateFingerprint($signals);
    }

    /**
     * Record a fingerprint for a client with quality scoring.
     *
     * Stores the fingerprint in cache and computes a quality score (0-100)
     * based on signal entropy, uniqueness, and detection patterns.
     *
     * @return array{fingerprint: string, score: int, is_suspicious: bool, risk_factors: list<string>}
     */
    public function recordFingerprint(string $clientId, string $fingerprint): array
    {
        $cacheKey = $this->cachePrefix . $fingerprint;
        $clientKey = $this->cachePrefix . 'client_' . $clientId;

        // Check if this fingerprint was seen before (uniqueness scoring)
        $existing = $this->cache->get($cacheKey);
        $clientFingerprints = $this->cache->get($clientKey, []);

        $isFirstSeen = $existing === null;
        $fingerprintCount = is_array($clientFingerprints) ? count($clientFingerprints) : 0;

        // Store fingerprint
        $this->cache->put($cacheKey, [
            'client_id' => $clientId,
            'first_seen' => now()->toIso8601String(),
            'last_seen' => now()->toIso8601String(),
            'seen_count' => ($existing['seen_count'] ?? 0) + 1,
        ], $this->fingerprintTtl);

        // Track fingerprints per client
        if (! is_array($clientFingerprints)) {
            $clientFingerprints = [];
        }

        $clientFingerprints[$fingerprint] = now()->timestamp;

        if (count($clientFingerprints) > $this->maxFingerprintsPerClient) {
            asort($clientFingerprints);
            $clientFingerprints = array_slice($clientFingerprints, -$this->maxFingerprintsPerClient, null, true);
        }

        $this->cache->put($clientKey, $clientFingerprints, $this->fingerprintTtl);

        // Compute quality score and risk factors
        $riskFactors = [];
        $score = 100;

        if ($fingerprintCount > 3) {
            $score -= 20;
            $riskFactors[] = 'multiple_fingerprints';
        }

        if (! $isFirstSeen) {
            $score -= 5;
            $riskFactors[] = 'shared_fingerprint';
        }

        if ($existing !== null && ($existing['seen_count'] ?? 0) > 50) {
            $score -= 30;
            $riskFactors[] = 'high_frequency_fingerprint';
        }

        $score = max(0, $score);

        return [
            'fingerprint' => $fingerprint,
            'score' => $score,
            'is_suspicious' => $score < 60,
            'risk_factors' => $riskFactors,
        ];
    }

    /**
     * Check if a fingerprint is known to be suspicious/bot-like.
     */
    public function isSuspicious(string $fingerprint): bool
    {
        $cacheKey = $this->cachePrefix . $fingerprint;
        $data = $this->cache->get($cacheKey);

        if ($data === null) {
            return false;
        }

        return ($data['seen_count'] ?? 0) > 100;
    }

    /**
     * Get fingerprint statistics.
     *
     * @return array{cache_prefix: string, ttl: int, max_per_client: int}
     */
    public function stats(): array
    {
        return [
            'cache_prefix' => $this->cachePrefix,
            'ttl' => $this->fingerprintTtl,
            'max_per_client' => $this->maxFingerprintsPerClient,
        ];
    }

    /**
     * Normalize browser signals for consistent hashing.
     *
     * Lowercases string values, defaults missing values, sorts keys.
     *
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function normalizeSignals(array $signals): array
    {
        $defaults = [
            'user_agent' => '',
            'screen_width' => 0,
            'screen_height' => 0,
            'color_depth' => 0,
            'timezone' => '',
            'language' => '',
            'platform' => '',
            'canvas_hash' => '',
        ];

        $merged = array_merge($defaults, $signals);

        // Normalize string values
        $merged['user_agent'] = strtolower(trim((string) $merged['user_agent']));
        $merged['timezone'] = strtolower(trim((string) $merged['timezone']));
        $merged['language'] = strtolower(trim((string) $merged['language']));
        $merged['platform'] = strtolower(trim((string) $merged['platform']));

        // Ensure numeric types
        $merged['screen_width'] = (int) $merged['screen_width'];
        $merged['screen_height'] = (int) $merged['screen_height'];
        $merged['color_depth'] = (int) $merged['color_depth'];

        ksort($merged);

        return $merged;
    }
}
