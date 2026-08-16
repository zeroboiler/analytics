<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

/**
 * Bot detection and client ID fingerprint scoring for analytics API endpoints.
 *
 * Analyzes incoming API requests for indicators of automated traffic,
 * bot-like behavior, and client ID rotation patterns. Provides a composite
 * risk score (0-100) that can be used to reject or flag suspicious requests.
 *
 * Detection layers:
 * 1. **User-Agent analysis** — Known bot patterns, empty/missing UAs
 * 2. **Client ID rotation detection** — Multiple distinct client IDs from same IP within window
 * 3. **Velocity anomaly** — Burst event submissions exceeding expected rate
 * 4. **Header fingerprint scoring** — Missing or inconsistent headers
 *
 * Configured via `zeroboiler.analytics.bot_detection`.
 *
 * Inspired by Cloudflare Bot Management, Akamai Bot Manager, and FingerprintJS.
 *
 * @since 8.4.0
 */
final class BotDetectionService
{
    private const CACHE_PREFIX = 'zb_bot_detect_';

    private const DEFAULT_MAX_CLIENT_IDS_PER_IP = 10;

    private const DEFAULT_VELOCITY_BURST_THRESHOLD = 50;

    private const DEFAULT_VELOCITY_WINDOW = 60;

    private const DEFAULT_RISK_THRESHOLD = 70;

    private const DEFAULT_BOT_UA_PATTERNS = [
        'bot', 'crawl', 'spider', 'scraper', 'curl', 'wget', 'python-requests',
        'python-urllib', 'httpclient', 'java/', 'go-http', 'node-fetch',
        'axios', 'fetch/', 'postmanruntime', 'insomnia', 'httpie',
        'apache-httpclient', 'okhttp', 'requests/', 'aiohttp',
    ];

    private bool $enabled;

    private int $riskThreshold;

    private int $maxClientIdsPerIp;

    private int $velocityBurstThreshold;

    private int $velocityWindow;

    /** @var list<string> */
    private array $botUaPatterns;

    private CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache  Application cache
     * @param  ConfigRepository  $config  Analytics config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $botConfig = $config->get('zeroboiler.analytics.bot_detection', []);
        /** @var array{enabled?: bool, risk_threshold?: int, max_client_ids_per_ip?: int, velocity_burst?: int, velocity_window?: int, bot_ua_patterns?: list<string>} $botConfig */

        $this->enabled = (bool) ($botConfig['enabled'] ?? true);
        $this->riskThreshold = (int) ($botConfig['risk_threshold'] ?? self::DEFAULT_RISK_THRESHOLD);
        $this->maxClientIdsPerIp = (int) ($botConfig['max_client_ids_per_ip'] ?? self::DEFAULT_MAX_CLIENT_IDS_PER_IP);
        $this->velocityBurstThreshold = (int) ($botConfig['velocity_burst'] ?? self::DEFAULT_VELOCITY_BURST_THRESHOLD);
        $this->velocityWindow = (int) ($botConfig['velocity_window'] ?? self::DEFAULT_VELOCITY_WINDOW);
        $this->botUaPatterns = (array) ($botConfig['bot_ua_patterns'] ?? self::DEFAULT_BOT_UA_PATTERNS);
    }

    /**
     * Analyze a request and return a bot detection result.
     *
     * @param  Request  $request  Incoming HTTP request
     * @param  string|null  $clientId  Client tracking ID
     * @return array{score: int, is_bot: bool, signals: array{user_agent: int|null, client_rotation: int|null, velocity: int|null, header_score: int|null}, details: list<string>}
     */
    public function analyze(Request $request, ?string $clientId = null): array
    {
        if (! $this->enabled) {
            return $this->cleanResult();
        }

        $signals = [
            'user_agent' => $this->scoreUserAgent($request),
            'client_rotation' => $this->scoreClientRotation($request, $clientId),
            'velocity' => $this->scoreVelocity($request, $clientId),
            'header_score' => $this->scoreHeaders($request),
        ];

        // Weighted composite score
        $score = (int) round(
            ($signals['user_agent'] ?? 0) * 0.30
            + ($signals['client_rotation'] ?? 0) * 0.30
            + ($signals['velocity'] ?? 0) * 0.25
            + ($signals['header_score'] ?? 0) * 0.15
        );

        $details = $this->buildDetails($signals);

        // Cache result for metrics
        $this->recordAnalysis($request, $clientId, $score);

        return [
            'score' => $score,
            'is_bot' => $score >= $this->riskThreshold,
            'signals' => $signals,
            'details' => $details,
        ];
    }

    /**
     * Score user-agent for bot patterns.
     *
     * @param  Request  $request  HTTP request
     * @return int|null  Score (0-100), null if not applicable
     */
    private function scoreUserAgent(Request $request): ?int
    {
        $ua = $request->userAgent();

        if ($ua === '' || $ua === null) {
            return 80; // Missing UA is highly suspicious
        }

        $lowerUa = strtolower($ua);

        foreach ($this->botUaPatterns as $pattern) {
            if (str_contains($lowerUa, strtolower($pattern))) {
                // Strong bot indicator
                return 90;
            }
        }

        // Check for extremely short UAs (typically bots)
        if (strlen($ua) < 20) {
            return 50;
        }

        return 0;
    }

    /**
     * Score client ID rotation (same IP using many different client IDs).
     *
     * @param  Request  $request  HTTP request
     * @param  string|null  $clientId  Client tracking ID
     * @return int|null  Score (0-100), null if not applicable
     */
    private function scoreClientRotation(Request $request, ?string $clientId): ?int
    {
        if ($clientId === null || $clientId === '') {
            return null;
        }

        $ip = $request->ip() ?? 'unknown';
        $key = self::CACHE_PREFIX . 'client_ids:' . $ip;

        /** @var list<string> $knownIds */
        $knownIds = $this->cache->get($key, []);

        // Add current client ID
        if (! in_array($clientId, $knownIds, true)) {
            $knownIds[] = $clientId;
            $this->cache->put($key, $knownIds, 3600); // 1 hour window
        }

        $uniqueCount = count($knownIds);

        if ($uniqueCount > $this->maxClientIdsPerIp * 2) {
            return 100; // Extreme rotation
        }

        if ($uniqueCount > $this->maxClientIdsPerIp) {
            return 70; // Suspicious rotation
        }

        if ($uniqueCount > 3) {
            return 20; // Mildly suspicious
        }

        return 0;
    }

    /**
     * Score request velocity (burst submissions).
     *
     * @param  Request  $request  HTTP request
     * @param  string|null  $clientId  Client tracking ID
     * @return int|null  Score (0-100), null if not applicable
     */
    private function scoreVelocity(Request $request, ?string $clientId): ?int
    {
        $identifier = $clientId ?? $request->ip() ?? 'unknown';
        $key = self::CACHE_PREFIX . 'velocity:' . $identifier;
        $windowKey = self::CACHE_PREFIX . 'velocity_window:' . $identifier;

        $count = (int) $this->cache->get($key, 0);
        $windowStart = (int) $this->cache->get($windowKey, 0);
        $now = time();

        // Reset window if expired
        if ($now - $windowStart >= $this->velocityWindow) {
            $count = 0;
            $this->cache->put($windowKey, $now, $this->velocityWindow + 10);
        }

        $this->cache->increment($key);
        $this->cache->put($key, $count + 1, $this->velocityWindow + 10);

        if ($count > $this->velocityBurstThreshold * 2) {
            return 100; // Extreme burst
        }

        if ($count > $this->velocityBurstThreshold) {
            return 60; // Suspicious burst
        }

        if ($count > $this->velocityBurstThreshold / 2) {
            return 20; // Mild burst
        }

        return 0;
    }

    /**
     * Score HTTP header completeness.
     *
     * @param  Request  $request  HTTP request
     * @return int  Score (0-100)
     */
    private function scoreHeaders(Request $request): int
    {
        $score = 0;

        // Check for expected browser headers
        if (! $request->headers->has('Accept')) {
            $score += 15;
        }
        if (! $request->headers->has('Accept-Language')) {
            $score += 10;
        }
        if (! $request->headers->has('Accept-Encoding')) {
            $score += 10;
        }
        if (! $request->headers->has('Referer') && $request->headers->has('User-Agent')) {
            // API direct calls without referer are expected, but score mildly
            $score += 5;
        }

        // Check for automation-specific headers
        if ($request->headers->has('X-Requested-With') && strtolower($request->headers->get('X-Requested-With', '')) === 'xmlhttprequest') {
            // AJAX calls are normal for analytics API
        }

        // Extremely fast requests (< 50ms round trip) — possible headless browser
        $requestTime = $request->server->get('REQUEST_TIME_FLOAT');
        if ($requestTime !== null) {
            $elapsed = microtime(true) - (float) $requestTime;
            if ($elapsed < 0.05 && $elapsed >= 0) {
                $score += 20;
            }
        }

        return min(100, $score);
    }

    /**
     * Build human-readable details from signal scores.
     *
     * @param  array{user_agent: int|null, client_rotation: int|null, velocity: int|null, header_score: int|null}  $signals
     * @return list<string>
     */
    private function buildDetails(array $signals): array
    {
        $details = [];

        if (($signals['user_agent'] ?? 0) >= 70) {
            $details[] = 'User-Agent matches known bot pattern or is missing';
        }
        if (($signals['client_rotation'] ?? 0) >= 50) {
            $details[] = 'Client ID rotation detected (multiple IDs from same source)';
        }
        if (($signals['velocity'] ?? 0) >= 40) {
            $details[] = 'High event submission velocity (possible burst attack)';
        }
        if (($signals['header_score'] ?? 0) >= 30) {
            $details[] = 'Incomplete HTTP headers (automation indicator)';
        }

        return $details;
    }

    /**
     * Record analysis result for metrics.
     *
     * @param  Request  $request  HTTP request
     * @param  string|null  $clientId  Client tracking ID
     * @param  int  $score  Risk score
     */
    private function recordAnalysis(Request $request, ?string $clientId, int $score): void
    {
        $statsKey = self::CACHE_PREFIX . 'stats';
        $stats = $this->cache->get($statsKey, [
            'total' => 0,
            'bot' => 0,
            'human' => 0,
            'avg_score' => 0.0,
        ]);
        /** @var array{total: int, bot: int, human: int, avg_score: float} $stats */

        $stats['total']++;
        if ($score >= $this->riskThreshold) {
            $stats['bot']++;
        } else {
            $stats['human']++;
        }
        $stats['avg_score'] = round(
            (($stats['avg_score'] * ($stats['total'] - 1)) + $score) / $stats['total'],
            2,
        );

        $this->cache->put($statsKey, $stats, 3600);
    }

    /**
     * Get bot detection statistics.
     *
     * @return array{total: int, bot: int, human: int, avg_score: float, bot_rate: float}
     */
    public function getStats(): array
    {
        $statsKey = self::CACHE_PREFIX . 'stats';
        $stats = $this->cache->get($statsKey, [
            'total' => 0,
            'bot' => 0,
            'human' => 0,
            'avg_score' => 0.0,
        ]);
        /** @var array{total: int, bot: int, human: int, avg_score: float} $stats */

        return [
            'total' => $stats['total'],
            'bot' => $stats['bot'],
            'human' => $stats['human'],
            'avg_score' => $stats['avg_score'],
            'bot_rate' => $stats['total'] > 0
                ? round(($stats['bot'] / $stats['total']) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * Check if bot detection is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the configured risk threshold.
     */
    public function getRiskThreshold(): int
    {
        return $this->riskThreshold;
    }

    /**
     * Get client ID rotation data for an IP.
     *
     * @param  string  $ip  IP address
     * @return array{count: int, client_ids: list<string>}
     */
    public function getClientRotationData(string $ip): array
    {
        $key = self::CACHE_PREFIX . 'client_ids:' . $ip;
        /** @var list<string> $clientIds */
        $clientIds = $this->cache->get($key, []);

        return [
            'count' => count($clientIds),
            'client_ids' => array_slice($clientIds, -10), // Last 10 for security
        ];
    }

    /**
     * Get velocity data for an identifier.
     *
     * @param  string  $identifier  Client ID or IP
     * @return array{count: int, window_seconds: int, threshold: int}
     */
    public function getVelocityData(string $identifier): array
    {
        $key = self::CACHE_PREFIX . 'velocity:' . $identifier;
        $count = (int) $this->cache->get($key, 0);

        return [
            'count' => $count,
            'window_seconds' => $this->velocityWindow,
            'threshold' => $this->velocityBurstThreshold,
        ];
    }

    /**
     * Reset all bot detection cache.
     */
    public function resetCache(): void
    {
        // Stats are cache-backed, resetting effectively clears on next write cycle
        $this->cache->forget(self::CACHE_PREFIX . 'stats');
    }

    /**
     * Return a clean (no-bot) result for disabled state.
     *
     * @return array{score: int, is_bot: bool, signals: array<string, null>, details: list<string>}
     */
    private function cleanResult(): array
    {
        return [
            'score' => 0,
            'is_bot' => false,
            'signals' => [
                'user_agent' => null,
                'client_rotation' => null,
                'velocity' => null,
                'header_score' => null,
            ],
            'details' => [],
        ];
    }
}
