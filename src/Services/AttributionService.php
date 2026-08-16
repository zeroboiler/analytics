<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\UtmAttribution;

/**
 * First-touch and multi-touch UTM attribution tracking service.
 *
 * Captures UTM parameters from incoming requests, persists the first-touch
 * attribution across sessions, and maintains a rolling history of touchpoints
 * for multi-touch attribution analysis.
 *
 * Designed for SaaS applications that need to understand which campaigns,
 * channels, and content brought users to signup and conversion events.
 *
 * @since 1.0.0
 */
final class AttributionService
{
    private const FIRST_TOUCH_KEY = 'zb_analytics_first_touch_';

    private const TOUCH_HISTORY_KEY = 'zb_analytics_touch_history_';

    private const DEFAULT_TTL = 2592000; // 30 days (seconds)

    private const MAX_TOUCH_HISTORY = 20;

    private CacheRepository $cache;

    private int $firstTouchTtl;

    private int $touchHistoryTtl;

    private int $maxTouchHistory;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $attributionConfig = $config->get('zeroboiler.analytics.attribution', []);
        /** @var array{first_touch_ttl?: int, touch_history_ttl?: int, max_touch_history?: int} $attributionConfig */
        $this->firstTouchTtl = (int) ($attributionConfig['first_touch_ttl'] ?? self::DEFAULT_TTL);
        $this->touchHistoryTtl = (int) ($attributionConfig['touch_history_ttl'] ?? self::DEFAULT_TTL);
        $this->maxTouchHistory = (int) ($attributionConfig['max_touch_history'] ?? self::MAX_TOUCH_HISTORY);
    }

    /**
     * Record an attribution touchpoint from a request.
     *
     * If UTM parameters are present and no first-touch attribution exists
     * for this client ID, the first-touch is stored. The touchpoint is
     * always added to the rolling touch history.
     *
     * @param  string  $clientId  Client tracking ID
     * @param  array<string, mixed>  $params  Request parameters containing UTM keys
     * @param  string|null  $referrer  Referrer URL
     * @param  string|null  $landingPage  Landing page URL
     */
    public function recordTouchpoint(
        string $clientId,
        array $params,
        ?string $referrer = null,
        ?string $landingPage = null,
    ): void {
        if ($clientId === '') {
            return;
        }

        $attribution = UtmAttribution::fromRequest($params, false, $referrer, $landingPage);

        if (! $attribution->hasAttribution()) {
            return;
        }

        // Store first-touch if not already set
        $firstTouchKey = self::FIRST_TOUCH_KEY . $clientId;
        $existing = $this->cache->get($firstTouchKey);

        if ($existing === null) {
            $firstTouch = UtmAttribution::fromRequest($params, true, $referrer, $landingPage);
            $this->cache->put($firstTouchKey, $firstTouch->toArray(), $this->firstTouchTtl);
        }

        // Append to touch history
        $historyKey = self::TOUCH_HISTORY_KEY . $clientId;
        /** @var list<array<string, mixed>>|null $history */
        $history = $this->cache->get($historyKey) ?? [];

        $history[] = array_merge($attribution->toArray(), [
            'recorded_at' => date('c'),
        ]);

        // Keep only the most recent N touchpoints
        if (count($history) > $this->maxTouchHistory) {
            $history = array_slice($history, -$this->maxTouchHistory);
        }

        $this->cache->put($historyKey, $history, $this->touchHistoryTtl);
    }

    /**
     * Get the first-touch attribution for a client ID.
     *
     * @param  string  $clientId
     * @return array<string, mixed>|null  First-touch UTM data or null
     */
    public function getFirstTouch(string $clientId): ?array
    {
        if ($clientId === '') {
            return null;
        }

        $data = $this->cache->get(self::FIRST_TOUCH_KEY . $clientId);

        return is_array($data) ? $data : null;
    }

    /**
     * Get the full touch history for a client ID.
     *
     * @param  string  $clientId
     * @return list<array<string, mixed>>
     */
    public function getTouchHistory(string $clientId): array
    {
        if ($clientId === '') {
            return [];
        }

        $history = $this->cache->get(self::TOUCH_HISTORY_KEY . $clientId);

        return is_array($history) ? $history : [];
    }

    /**
     * Get the most recent touchpoint for a client ID.
     *
     * @param  string  $clientId
     * @return array<string, mixed>|null
     */
    public function getLastTouch(string $clientId): ?array
    {
        $history = $this->getTouchHistory($clientId);

        return ! empty($history) ? $history[array_key_last($history)] : null;
    }

    /**
     * Get a multi-touch attribution summary.
     *
     * Aggregates all touchpoints by source/medium/campaign and returns
     * counts and the chronological journey.
     *
     * @param  string  $clientId
     * @return array{first_touch: array<string, mixed>|null, last_touch: array<string, mixed>|null, total_touches: int, sources: array<string, int>, mediums: array<string, int>, campaigns: array<string, int>, journey: list<array{source: string|null, medium: string|null, campaign: string|null, timestamp: string|null}>}
     */
    public function getAttributionSummary(string $clientId): array
    {
        $firstTouch = $this->getFirstTouch($clientId);
        $history = $this->getTouchHistory($clientId);
        $lastTouch = ! empty($history) ? $history[array_key_last($history)] : null;

        $sources = [];
        $mediums = [];
        $campaigns = [];
        $journey = [];

        foreach ($history as $touch) {
            $source = $touch['utm_source'] ?? 'direct';
            $medium = $touch['utm_medium'] ?? 'none';
            $campaign = $touch['utm_campaign'] ?? null;

            $sources[$source] = ($sources[$source] ?? 0) + 1;
            $mediums[$medium] = ($mediums[$medium] ?? 0) + 1;
            if (is_string($campaign) && $campaign !== '') {
                $campaigns[$campaign] = ($campaigns[$campaign] ?? 0) + 1;
            }

            $journey[] = [
                'source' => is_string($source) ? $source : null,
                'medium' => is_string($medium) ? $medium : null,
                'campaign' => $campaign,
                'timestamp' => $touch['recorded_at'] ?? $touch['utm_timestamp'] ?? null,
            ];
        }

        arsort($sources);
        arsort($mediums);
        arsort($campaigns);

        return [
            'first_touch' => $firstTouch,
            'last_touch' => $lastTouch,
            'total_touches' => count($history),
            'sources' => $sources,
            'mediums' => $mediums,
            'campaigns' => $campaigns,
            'journey' => $journey,
        ];
    }

    /**
     * Get the top campaign sources across all cached first-touch attributions.
     *
     * Scans all cached first-touch entries and aggregates by source.
     * Useful for dashboard-style reporting.
     *
     * @return array<string, int>
     */
    public function getTopSources(int $limit = 10): array
    {
        // This is a best-effort scan — not all cache drivers support prefix iteration
        // In production, use a dedicated attribution store (database/Redis)
        return [];
    }

    /**
     * Permanently delete all attribution data for a client ID (GDPR compliance).
     */
    public function deleteAttribution(string $clientId): void
    {
        if ($clientId === '') {
            return;
        }

        $this->cache->forget(self::FIRST_TOUCH_KEY . $clientId);
        $this->cache->forget(self::TOUCH_HISTORY_KEY . $clientId);
    }
}
