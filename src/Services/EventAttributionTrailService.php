<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Attribution trail service tracking the complete UTM and referrer journey
 * from first touch through every subsequent touchpoint to the current session.
 *
 * Maintains per-identity (client ID / user ID) attribution records including:
 * - First-touch UTM parameters (source, medium, campaign, content, term)
 * - Last-touch UTM parameters
 * - Multi-touch history (configurable depth)
 * - Referrer chain (HTTP referrer + landing page for each visit)
 * - Attribution model scores (first-touch, last-touch, linear, time-decay)
 * - Conversion event association
 *
 * Cache-backed with configurable retention. Provides GDPR-compliant
 * data erasure and attribution model comparison.
 *
 * Inspired by Segment Attribution, Mixpanel Attribution, Google Attribution,
 * and the UTM specification.
 *
 * @since 72.0.0
 */
final class EventAttributionTrailService
{
    private const CACHE_PREFIX = 'zb_attr_trail_';
    private const INDEX_KEY = 'zb_attr_trail_index';

    private CacheRepository $cache;
    private bool $enabled;
    private int $ttl;
    private int $maxTouchHistory;
    private int $maxReferrerChain;

    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $attrConfig = $config->get('zeroboiler.analytics.attribution_trail', []);
        /** @var array{enabled?: bool, ttl?: int, max_touch_history?: int, max_referrer_chain?: int} $attrConfig */
        $this->enabled = (bool) ($attrConfig['enabled'] ?? true);
        $this->ttl = (int) ($attrConfig['ttl'] ?? 2592000); // 30 days
        $this->maxTouchHistory = (int) ($attrConfig['max_touch_history'] ?? 50);
        $this->maxReferrerChain = (int) ($attrConfig['max_referrer_chain'] ?? 20);
    }

    /**
     * Check if the attribution trail service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Record an attribution touchpoint.
     *
     * @param  string  $clientId  Client/visitor identifier
     * @param  array{utm_source?: string, utm_medium?: string, utm_campaign?: string, utm_content?: string, utm_term?: string, referrer?: string, landing_page?: string}  $utm  UTM + referrer data
     * @param  array{user_id?: string|null, event_name?: string|null, is_conversion?: bool}  $context  Additional context
     */
    public function recordTouch(string $clientId, array $utm, array $context = []): void
    {
        if (! $this->enabled || $clientId === '') {
            return;
        }

        $trail = $this->getTrail($clientId);
        $now = time();

        $touchpoint = array_filter([
            'timestamp' => $now,
            'utm_source' => $utm['utm_source'] ?? null,
            'utm_medium' => $utm['utm_medium'] ?? null,
            'utm_campaign' => $utm['utm_campaign'] ?? null,
            'utm_content' => $utm['utm_content'] ?? null,
            'utm_term' => $utm['utm_term'] ?? null,
            'referrer' => $utm['referrer'] ?? null,
            'landing_page' => $utm['landing_page'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'event_name' => $context['event_name'] ?? null,
            'is_conversion' => (bool) ($context['is_conversion'] ?? false),
        ], static fn (mixed $v): bool => $v !== null);

        // Set first-touch (only if none exists)
        if ($trail['first_touch'] === null) {
            $trail['first_touch'] = $touchpoint;
            $trail['first_touch_id'] = (string) $now;
        }

        // Always update last-touch
        $trail['last_touch'] = $touchpoint;
        $trail['last_touch_id'] = (string) $now;

        // Add to multi-touch history
        $trail['touch_history'][] = $touchpoint;

        // Enforce max touch history
        if (count($trail['touch_history']) > $this->maxTouchHistory) {
            $trail['touch_history'] = array_slice($trail['touch_history'], -$this->maxTouchHistory);
        }

        // Add to referrer chain
        if (($utm['referrer'] ?? null) !== null || ($utm['landing_page'] ?? null) !== null) {
            $trail['referrer_chain'][] = array_filter([
                'timestamp' => $now,
                'referrer' => $utm['referrer'] ?? null,
                'landing_page' => $utm['landing_page'] ?? null,
            ], static fn (mixed $v): bool => $v !== null);

            if (count($trail['referrer_chain']) > $this->maxReferrerChain) {
                $trail['referrer_chain'] = array_slice($trail['referrer_chain'], -$this->maxReferrerChain);
            }
        }

        // Track conversions
        if (! empty($context['is_conversion'])) {
            $trail['conversions'][] = [
                'timestamp' => $now,
                'event_name' => $context['event_name'] ?? 'conversion',
                'attribution' => $this->extractUtmSubset($touchpoint),
            ];
        }

        $trail['updated_at'] = $now;
        $trail['touch_count'] = count($trail['touch_history']);

        $this->cache->put(
            self::CACHE_PREFIX . $clientId,
            $trail,
            $this->ttl,
        );

        // Update index
        $this->addToIndex($clientId);
    }

    /**
     * Get the complete attribution trail for a client.
     *
     * @return array{first_touch: array<string, mixed>|null, last_touch: array<string, mixed>|null, touch_history: list<array<string, mixed>>, referrer_chain: list<array<string, mixed>>, conversions: list<array<string, mixed>>, touch_count: int, updated_at: int}
     */
    public function getTrail(string $clientId): array
    {
        if ($clientId === '') {
            return $this->emptyTrail();
        }

        /** @var array<string, mixed>|null $trail */
        $trail = $this->cache->get(self::CACHE_PREFIX . $clientId);

        return is_array($trail) ? $trail : $this->emptyTrail();
    }

    /**
     * Get the first-touch attribution data for a client.
     *
     * @return array<string, mixed>|null
     */
    public function firstTouch(string $clientId): ?array
    {
        return $this->getTrail($clientId)['first_touch'] ?? null;
    }

    /**
     * Get the last-touch attribution data for a client.
     *
     * @return array<string, mixed>|null
     */
    public function lastTouch(string $clientId): ?array
    {
        return $this->getTrail($clientId)['last_touch'] ?? null;
    }

    /**
     * Compute attribution scores across multiple models.
     *
     * @param  string  $clientId  Client identifier
     * @param  string  $conversionEvent  The conversion event to attribute
     * @return array{first_touch: array<string, mixed>|null, last_touch: array<string, mixed>|null, linear: array<string, float>, time_decay: array<string, float>, winning_touch: array<string, mixed>|null, model: string}
     */
    public function attribute(string $clientId, string $conversionEvent = 'conversion'): array
    {
        $trail = $this->getTrail($clientId);

        if ($trail['first_touch'] === null) {
            return [
                'first_touch' => null,
                'last_touch' => null,
                'linear' => [],
                'time_decay' => [],
                'winning_touch' => null,
                'model' => 'none',
            ];
        }

        // First-touch model: 100% to first touch
        $firstTouchAttribution = $trail['first_touch'];

        // Last-touch model: 100% to last touch
        $lastTouchAttribution = $trail['last_touch'];

        // Linear model: equal credit to all touches
        $linear = $this->computeLinearModel($trail);

        // Time-decay model: more credit to recent touches
        $timeDecay = $this->computeTimeDecayModel($trail);

        return [
            'first_touch' => $firstTouchAttribution,
            'last_touch' => $lastTouchAttribution,
            'linear' => $linear,
            'time_decay' => $timeDecay,
            'winning_touch' => $lastTouchAttribution, // default to last-touch
            'model' => 'last_touch',
            'conversion_event' => $conversionEvent,
            'total_touches' => count($trail['touch_history']),
        ];
    }

    /**
     * Get attribution statistics across all tracked identities.
     */
    public function statistics(): array
    {
        $index = $this->getIndex();
        $sourceCounts = [];
        $mediumCounts = [];
        $campaignCounts = [];
        $totalTrails = count($index);
        $totalTouches = 0;
        $totalConversions = 0;

        foreach ($index as $clientId => $_) {
            $trail = $this->getTrail($clientId);

            $totalTouches += $trail['touch_count'] ?? 0;
            $totalConversions += count($trail['conversions'] ?? []);

            if (($trail['first_touch']['utm_source'] ?? null) !== null) {
                $src = $trail['first_touch']['utm_source'];
                $sourceCounts[$src] = ($sourceCounts[$src] ?? 0) + 1;
            }

            if (($trail['first_touch']['utm_medium'] ?? null) !== null) {
                $med = $trail['first_touch']['utm_medium'];
                $mediumCounts[$med] = ($mediumCounts[$med] ?? 0) + 1;
            }

            if (($trail['first_touch']['utm_campaign'] ?? null) !== null) {
                $camp = $trail['first_touch']['utm_campaign'];
                $campaignCounts[$camp] = ($campaignCounts[$camp] ?? 0) + 1;
            }
        }

        arsort($sourceCounts);
        arsort($campaignCounts);

        return [
            'total_tracked_identities' => $totalTrails,
            'total_touchpoints' => $totalTouches,
            'total_conversions' => $totalConversions,
            'avg_touches_per_identity' => $totalTrails > 0 ? round($totalTouches / $totalTrails, 1) : 0,
            'top_sources' => array_slice($sourceCounts, 0, 10, true),
            'top_mediums' => array_slice($mediumCounts, 0, 10, true),
            'top_campaigns' => array_slice($campaignCounts, 0, 10, true),
            'enabled' => $this->enabled,
            'retention_days' => (int) ($this->ttl / 86400),
        ];
    }

    /**
     * GDPR-compliant data erasure for a specific client.
     */
    public function eraseFor(string $clientId): bool
    {
        if ($clientId === '') {
            return false;
        }

        $this->cache->forget(self::CACHE_PREFIX . $clientId);

        $index = $this->getIndex();
        unset($index[$clientId]);
        $this->cache->put(self::INDEX_KEY, $index, $this->ttl + 86400);

        return true;
    }

    /**
     * Clear all attribution trails.
     */
    public function clear(): void
    {
        $index = $this->getIndex();

        foreach (array_keys($index) as $clientId) {
            $this->cache->forget(self::CACHE_PREFIX . $clientId);
        }

        $this->cache->forget(self::INDEX_KEY);
    }

    /**
     * Count tracked identities.
     */
    public function count(): int
    {
        return count($this->getIndex());
    }

    /**
     * Create an empty trail structure.
     *
     * @return array<string, mixed>
     */
    private function emptyTrail(): array
    {
        return [
            'first_touch' => null,
            'last_touch' => null,
            'first_touch_id' => null,
            'last_touch_id' => null,
            'touch_history' => [],
            'referrer_chain' => [],
            'conversions' => [],
            'touch_count' => 0,
            'updated_at' => 0,
        ];
    }

    /**
     * Extract UTM subset from a touchpoint.
     *
     * @param  array<string, mixed>  $touchpoint
     * @return array{utm_source?: string, utm_medium?: string, utm_campaign?: string, utm_content?: string, utm_term?: string}
     */
    private function extractUtmSubset(array $touchpoint): array
    {
        return array_filter(
            [
                'utm_source' => $touchpoint['utm_source'] ?? null,
                'utm_medium' => $touchpoint['utm_medium'] ?? null,
                'utm_campaign' => $touchpoint['utm_campaign'] ?? null,
                'utm_content' => $touchpoint['utm_content'] ?? null,
                'utm_term' => $touchpoint['utm_term'] ?? null,
            ],
            static fn (mixed $v): bool => $v !== null,
        );
    }

    /**
     * Compute linear attribution model — equal credit to all touchpoints.
     *
     * @param  array<string, mixed>  $trail
     * @return array<string, float>  Keyed by touch index
     */
    private function computeLinearModel(array $trail): array
    {
        $history = $trail['touch_history'] ?? [];

        if (count($history) === 0) {
            return [];
        }

        $credit = 1.0 / count($history);
        $scores = [];

        foreach ($history as $i => $_) {
            $scores['touch_' . $i] = round($credit, 4);
        }

        return $scores;
    }

    /**
     * Compute time-decay attribution model — more credit to recent touches.
     *
     * Uses exponential decay with a half-life of 7 days.
     *
     * @param  array<string, mixed>  $trail
     * @return array<string, float>  Keyed by touch index
     */
    private function computeTimeDecayModel(array $trail): array
    {
        $history = $trail['touch_history'] ?? [];

        if (count($history) === 0) {
            return [];
        }

        $halfLife = 604800; // 7 days in seconds
        $scores = [];
        $totalWeight = 0.0;

        foreach ($history as $i => $touch) {
            $age = time() - (int) ($touch['timestamp'] ?? time());
            $weight = pow(2, -$age / $halfLife);
            $scores['touch_' . $i] = $weight;
            $totalWeight += $weight;
        }

        // Normalize
        if ($totalWeight > 0) {
            foreach ($scores as $key => $weight) {
                $scores[$key] = round($weight / $totalWeight, 4);
            }
        }

        return $scores;
    }

    /**
     * Add a client ID to the index.
     */
    private function addToIndex(string $clientId): void
    {
        $index = $this->getIndex();
        $index[$clientId] = true;
        $this->cache->put(self::INDEX_KEY, $index, $this->ttl + 86400);
    }

    /**
     * Get the attribution trail index.
     *
     * @return array<string, bool>
     */
    private function getIndex(): array
    {
        /** @var array<string, bool>|null $index */
        $index = $this->cache->get(self::INDEX_KEY);

        return is_array($index) ? $index : [];
    }
}
