<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Privacy Sandbox API integration service.
 *
 * Provides helpers for Chrome's Privacy Sandbox APIs:
 * - Topics API: Contextual interest signals replacing cookie-based interest tracking
 * - Attribution Reporting API: Conversion measurement without cross-site identifiers
 * - Private Aggregation API: Aggregate reporting without individual user data
 *
 * These APIs represent the cookieless future of web analytics.
 * This service bridges ZeroBoiler analytics events with Privacy Sandbox
 * concepts, allowing gradual migration from cookie-based to privacy-preserving tracking.
 *
 * @see https://developer.chrome.com/docs/privacy-sandbox
 * @version 4.6.0
 */
final class PrivacySandboxService
{
    private bool $enabled;

    private string $topicsCachePrefix;

    private int $topicsCacheTtl;

    private int $attributionWindowDays;

    private string $aggregationCachePrefix;

    private int $aggregationCacheTtl;

    /** @var array<string, string> Built-in topic taxonomy mapping */
    private const TOPIC_TAXONOMY = [
        // E-commerce interest topics
        '/Shopping' => 'shopping',
        '/Shopping/Apparel' => 'shopping_apparel',
        '/Shopping/Electronics' => 'shopping_electronics',
        '/Shopping/Home & Garden' => 'shopping_home',
        // SaaS interest topics
        '/Technology & Computing' => 'technology',
        '/Technology & Computing/Software' => 'software',
        '/Business & Industrial' => 'business',
        '/Business & Industrial/Marketing' => 'marketing',
        '/Finance' => 'finance',
        '/Finance/Investing' => 'investing',
        '/Education' => 'education',
        '/Jobs & Education' => 'careers',
        // General engagement
        '/Online Communities' => 'communities',
        '/News' => 'news',
        '/Entertainment' => 'entertainment',
        '/Sports' => 'sports',
        '/Health' => 'health',
        '/Travel' => 'travel',
    ];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $sandboxConfig = $config->get('zeroboiler.analytics.privacy_sandbox', []);
        /** @var array{enabled?: bool, topics_cache_prefix?: string, topics_cache_ttl?: int, attribution_window_days?: int, aggregation_cache_prefix?: string, aggregation_cache_ttl?: int} $sandboxConfig */

        $this->enabled = (bool) ($sandboxConfig['enabled'] ?? false);
        $this->topicsCachePrefix = (string) ($sandboxConfig['topics_cache_prefix'] ?? 'zb_topics_');
        $this->topicsCacheTtl = (int) ($sandboxConfig['topics_cache_ttl'] ?? 604800); // 7 days
        $this->attributionWindowDays = (int) ($sandboxConfig['attribution_window_days'] ?? 30);
        $this->aggregationCachePrefix = (string) ($sandboxConfig['aggregation_cache_prefix'] ?? 'zb_agg_');
        $this->aggregationCacheTtl = (int) ($sandboxConfig['aggregation_cache_ttl'] ?? 86400); // 24 hours
    }

    /**
     * Check if Privacy Sandbox features are enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    // ── Topics API Bridge ────────────────────────────────────────────

    /**
     * Map an event name or category to Privacy Sandbox topics.
     *
     * Translates ZeroBoiler event names into Chrome Topics API taxonomy
     * for context-based interest inference without cookies.
     *
     * @param  string  $eventName  ZeroBoiler event name
     * @return list<string> Mapped topic identifiers
     */
    public function eventToTopics(string $eventName): array
    {
        if (! $this->enabled) {
            return [];
        }

        $topics = [];

        // Direct category mapping
        if (str_contains($eventName, 'cart') || str_contains($eventName, 'purchase') || str_contains($eventName, 'wishlist')) {
            $topics[] = '/Shopping';
        }

        if (str_contains($eventName, 'trial') || str_contains($eventName, 'subscription') || str_contains($eventName, 'plan')) {
            $topics[] = '/Business & Industrial';
            $topics[] = '/Finance';
        }

        if (str_contains($eventName, 'search')) {
            $topics[] = '/Technology & Computing';
        }

        if (str_contains($eventName, 'form') || str_contains($eventName, 'share') || str_contains($eventName, 'feedback')) {
            $topics[] = '/Online Communities';
        }

        return array_values(array_unique($topics));
    }

    /**
     * Record observed topics for a client ID.
     *
     * Aggregates topic observations per client, mimicking the Topics API's
     * per-domain topic calculation. Useful for building topic profiles that
     * replace cookie-based interest segments.
     *
     * @param  string  $clientId  Client tracking ID
     * @param  list<string>  $topics  Observed topic identifiers
     */
    public function recordTopicsForClient(string $clientId, array $topics): void
    {
        if (! $this->enabled || empty($topics)) {
            return;
        }

        $cacheKey = $this->topicsCachePrefix . $clientId;
        $existing = app(CacheRepository::class)->get($cacheKey, []);
        /** @var array<string, int> $existing */

        foreach ($topics as $topic) {
            $existing[$topic] = ($existing[$topic] ?? 0) + 1;
        }

        // Keep top 20 topics by observation count
        arsort($existing);
        $existing = array_slice($existing, 0, 20, true);

        app(CacheRepository::class)->put($cacheKey, $existing, $this->topicsCacheTtl);
    }

    /**
     * Get the observed topics for a client ID.
     *
     * Returns topics sorted by observation frequency (most observed first).
     *
     * @param  string  $clientId  Client tracking ID
     * @return array<string, int> Topic → observation count
     */
    public function getTopicsForClient(string $clientId): array
    {
        if (! $this->enabled) {
            return [];
        }

        $cacheKey = $this->topicsCachePrefix . $clientId;
        $data = app(CacheRepository::class)->get($cacheKey, []);

        return is_array($data) ? $data : [];
    }

    /**
     * Get the top N topics for a client.
     *
     * @param  string  $clientId  Client tracking ID
     * @param  int  $limit  Maximum topics to return
     * @return list<string> Top topic identifiers
     */
    public function getTopTopicsForClient(string $clientId, int $limit = 5): array
    {
        $topics = $this->getTopicsForClient($clientId);
        arsort($topics);

        return array_keys(array_slice($topics, 0, $limit, true));
    }

    /**
     * Get the full topic taxonomy mapping.
     *
     * @return array<string, string> Chrome topic path → ZeroBoiler topic key
     */
    public function topicTaxonomy(): array
    {
        return self::TOPIC_TAXONOMY;
    }

    /**
     * Map a Chrome topic path to a ZeroBoiler topic key.
     *
     * @param  string  $topicPath  Chrome Topics API topic path (e.g. '/Shopping/Apparel')
     * @return string|null ZeroBoiler topic key or null
     */
    public function mapTopic(string $topicPath): ?string
    {
        return self::TOPIC_TAXONOMY[$topicPath] ?? null;
    }

    // ── Attribution Reporting API Bridge ─────────────────────────────

    /**
     * Build an attribution source registration payload.
     *
     * Creates the data structure for a Privacy Sandbox Attribution Reporting API
     * source registration. This replaces traditional click-through attribution
     * with privacy-preserving measurement.
     *
     * @param  string  $eventId  Trigger event ID (e.g. ad click ID)
     * @param  string  $conversionGoal  Conversion event to measure (e.g. 'purchase', 'sign_up')
     * @param  float|null  $priority  Source priority (0.0 - 1.0)
     * @return array<string, mixed> Attribution source data
     */
    public function buildAttributionSource(
        string $eventId,
        string $conversionGoal,
        ?float $priority = null,
    ): array {
        return [
            'event_id' => $eventId,
            'conversion_goal' => $conversionGoal,
            'priority' => $priority,
            'expiry' => $this->attributionWindowDays * 86400, // seconds
            'attribution_window_days' => $this->attributionWindowDays,
            'type' => 'event',
        ];
    }

    /**
     * Build an attribution trigger (conversion) payload.
     *
     * Creates the data structure for reporting a conversion event
     * back to the Attribution Reporting API.
     *
     * @param  string  $sourceEventId  Original source event ID
     * @param  string  $triggerData  Conversion event name
     * @param  array<string, mixed>  $params  Additional conversion parameters
     * @return array<string, mixed> Attribution trigger data
     */
    public function buildAttributionTrigger(
        string $sourceEventId,
        string $triggerData,
        array $params = [],
    ): array {
        return array_merge([
            'source_event_id' => $sourceEventId,
            'trigger_data' => $triggerData,
            'type' => 'conversion',
        ], $params);
    }

    /**
     * Get the configured attribution window in days.
     */
    public function attributionWindowDays(): int
    {
        return $this->attributionWindowDays;
    }

    // ── Private Aggregation API Bridge ───────────────────────────────

    /**
     * Record an aggregate contribution for private aggregation.
     *
     * Simulates the Private Aggregation API by accumulating
     * histogram bucket contributions in cache. This allows
     * privacy-preserving metric counting without exposing
     * individual user events.
     *
     * @param  string  $metricName  Metric identifier
     * @param  string  $bucket  Histogram bucket key
     * @param  int  $value  Contribution value (typically 0 or 1)
     */
    public function recordAggregateContribution(string $metricName, string $bucket, int $value = 1): void
    {
        if (! $this->enabled) {
            return;
        }

        $cacheKey = $this->aggregationCachePrefix . $metricName;
        $data = app(CacheRepository::class)->get($cacheKey, []);
        /** @var array<string, int> $data */

        $data[$bucket] = ($data[$bucket] ?? 0) + $value;

        app(CacheRepository::class)->put($cacheKey, $data, $this->aggregationCacheTtl);
    }

    /**
     * Get aggregate histogram for a metric.
     *
     * Returns bucket → count mapping for the given metric.
     *
     * @param  string  $metricName  Metric identifier
     * @return array<string, int> Bucket → count
     */
    public function getAggregateHistogram(string $metricName): array
    {
        if (! $this->enabled) {
            return [];
        }

        $cacheKey = $this->aggregationCachePrefix . $metricName;
        $data = app(CacheRepository::class)->get($cacheKey, []);

        return is_array($data) ? $data : [];
    }

    /**
     * Clear aggregate data for a metric.
     */
    public function clearAggregate(string $metricName): void
    {
        app(CacheRepository::class)->forget($this->aggregationCachePrefix . $metricName);
    }

    // ── Migration Helpers ───────────────────────────────────────────

    /**
     * Generate a cookieless tracking context for an event.
     *
     * Creates privacy-safe context data that can be attached to events
     * instead of relying on cookies. Uses Topics API signals and
     * contextual attributes.
     *
     * @param  string  $clientId  Client tracking ID
     * @param  string  $eventName  Event name for topic inference
     * @param  array<string, mixed>  $context  Additional context
     * @return array<string, mixed> Privacy-safe event context
     */
    public function cookielessContext(string $clientId, string $eventName, array $context = []): array
    {
        if (! $this->enabled) {
            return $context;
        }

        $topics = $this->eventToTopics($eventName);
        $topTopics = $this->getTopTopicsForClient($clientId, 3);

        return array_merge($context, [
            '_privacy_sandbox' => true,
            '_inferred_topics' => $topics,
            '_client_topics' => $topTopics,
            '_attribution_mode' => 'sandbox',
        ]);
    }
}
