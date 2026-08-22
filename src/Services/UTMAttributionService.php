<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * UTM attribution service with first-touch, last-touch, and multi-touch models.
 *
 * Captures and persists UTM parameters across sessions to provide comprehensive
 * marketing attribution. Supports first-touch (initial acquisition source),
 * last-touch (conversion source), and linear multi-touch (weighted credit across all touchpoints).
 *
 * Configuration is read from `zeroboiler.analytics.attribution`.
 *
 * @since 1.0.0
 */
final class UTMAttributionService
{
    private CacheRepository $cache;

    private string $model;

    private int $sessionWindowDays;

    private int $cacheTtl;

    private const CACHE_PREFIX = 'zb_analytics_utm_';

    private const TOUCHPOINTS_KEY = 'zb_analytics_utm_touchpoints_';

    private const FIRST_TOUCH_KEY = 'zb_analytics_utm_first_';

    private const LAST_TOUCH_KEY = 'zb_analytics_utm_last_';

    /**
     * Valid attribution models.
     */
    private const MODELS = ['first_touch', 'last_touch', 'multi_touch'];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $attributionConfig = $config->get('zeroboiler.analytics.attribution', []);
        /** @var array{model?: string, session_window_days?: int, cache_ttl?: int, max_touchpoints?: int} $attributionConfig */

        $model = (string) ($attributionConfig['model'] ?? 'last_touch');
        $this->model = in_array($model, self::MODELS, true) ? $model : 'last_touch';
        $this->sessionWindowDays = (int) ($attributionConfig['session_window_days'] ?? 30);
        $this->cacheTtl = (int) ($attributionConfig['cache_ttl'] ?? 86400);
    }

    /**
     * Record UTM parameters for a user/client identifier.
     *
     * Persists the touchpoint and updates first/last touch attribution.
     *
     * @param  array{utm_source?: string, utm_medium?: string, utm_campaign?: string, utm_term?: string, utm_content?: string}  $utmParams
     * @param  array<string, mixed>  $context  Additional context (referrer, landing_page, etc.)
     */
    public function recordTouchpoint(string $identifier, array $utmParams, array $context = []): void
    {
        // Skip if no UTM parameters present
        if (empty($utmParams['utm_source']) && empty($utmParams['utm_medium'])) {
            return;
        }

        $normalizedParams = $this->normalizeUtmParams($utmParams);
        $touchpoint = [
            'params' => $normalizedParams,
            'context' => $context,
            'timestamp' => time(),
        ];

        // Update last touch
        $this->cache->put(
            self::LAST_TOUCH_KEY . $identifier,
            $touchpoint,
            $this->sessionWindowDays * 86400,
        );

        // Update first touch only if not already set
        $firstTouch = $this->cache->get(self::FIRST_TOUCH_KEY . $identifier);
        if ($firstTouch === null) {
            $this->cache->put(
                self::FIRST_TOUCH_KEY . $identifier,
                $touchpoint,
                $this->sessionWindowDays * 86400,
            );
        }

        // Append to multi-touch history
        $touchpoints = $this->getTouchpoints($identifier);
        $touchpoints[] = $touchpoint;

        $this->cache->put(
            self::TOUCHPOINTS_KEY . $identifier,
            $touchpoints,
            $this->sessionWindowDays * 86400,
        );
    }

    /**
     * Get the attributed UTM source for a user/client identifier.
     *
     * Returns attribution based on the configured model.
     *
     * @return array{params: array<string, string|null>, model: string, touchpoint_count: int, first_touch?: array<string, mixed>, last_touch?: array<string, mixed>}
     */
    public function getAttribution(string $identifier): array
    {
        $firstTouch = $this->cache->get(self::FIRST_TOUCH_KEY . $identifier);
        $lastTouch = $this->cache->get(self::LAST_TOUCH_KEY . $identifier);
        $touchpoints = $this->getTouchpoints($identifier);

        $params = match ($this->model) {
            'first_touch' => ($firstTouch['params'] ?? []) + $this->emptyUtmParams(),
            'last_touch' => ($lastTouch['params'] ?? []) + $this->emptyUtmParams(),
            'multi_touch' => $this->computeMultiTouchAttribution($touchpoints),
            default => ($lastTouch['params'] ?? []) + $this->emptyUtmParams(),
        };

        return [
            'params' => $params,
            'model' => $this->model,
            'touchpoint_count' => count($touchpoints),
            'first_touch' => $firstTouch,
            'last_touch' => $lastTouch,
        ];
    }

    /**
     * Get all touchpoints for a user/client identifier.
     *
     * @return list<array{params: array<string, string|null>, context: array<string, mixed>, timestamp: int}>
     */
    public function getTouchpoints(string $identifier): array
    {
        $touchpoints = $this->cache->get(self::TOUCHPOINTS_KEY . $identifier);

        return is_array($touchpoints) ? $touchpoints : [];
    }

    /**
     * Get the first-touch attribution.
     *
     * @return array{params: array<string, string|null>, context: array<string, mixed>, timestamp: int}|null
     */
    public function getFirstTouch(string $identifier): ?array
    {
        return $this->cache->get(self::FIRST_TOUCH_KEY . $identifier);
    }

    /**
     * Get the last-touch attribution.
     *
     * @return array{params: array<string, string|null>, context: array<string, mixed>, timestamp: int}|null
     */
    public function getLastTouch(string $identifier): ?array
    {
        return $this->cache->get(self::LAST_TOUCH_KEY . $identifier);
    }

    /**
     * Compute linear multi-touch attribution across all touchpoints.
     *
     * Gives equal weight to each touchpoint's source and campaign.
     *
     * @param  list<array{params: array<string, string|null>, timestamp: int}>  $touchpoints
     * @return array<string, string|null>
     */
    public function computeMultiTouchAttribution(array $touchpoints): array
    {
        if ($touchpoints === []) {
            return $this->emptyUtmParams();
        }

        $sources = [];
        $mediums = [];
        $campaigns = [];

        foreach ($touchpoints as $tp) {
            $params = $tp['params'] ?? [];
            if (! empty($params['utm_source'])) {
                $sources[] = $params['utm_source'];
            }
            if (! empty($params['utm_medium'])) {
                $mediums[] = $params['utm_medium'];
            }
            if (! empty($params['utm_campaign'])) {
                $campaigns[] = $params['utm_campaign'];
            }
        }

        return [
            'utm_source' => $this->mostFrequent($sources),
            'utm_medium' => $this->mostFrequent($mediums),
            'utm_campaign' => $this->mostFrequent($campaigns),
            'utm_term' => $this->mostFrequent(
                array_column($touchpoints, 'params.utm_term'),
            ),
            'utm_content' => $this->mostFrequent(
                array_column($touchpoints, 'params.utm_content'),
            ),
        ];
    }

    /**
     * Clear all attribution data for a user/client identifier.
     */
    public function clearAttribution(string $identifier): void
    {
        $this->cache->forget(self::FIRST_TOUCH_KEY . $identifier);
        $this->cache->forget(self::LAST_TOUCH_KEY . $identifier);
        $this->cache->forget(self::TOUCHPOINTS_KEY . $identifier);
    }

    /**
     * Get the configured attribution model.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Check if an identifier has any attribution data.
     */
    public function hasAttribution(string $identifier): bool
    {
        return $this->cache->has(self::FIRST_TOUCH_KEY . $identifier)
            || $this->cache->has(self::LAST_TOUCH_KEY . $identifier);
    }

    /**
     * Get attribution summary for a batch of identifiers.
     *
     * @param  list<string>  $identifiers
     * @return array<string, array{params: array<string, string|null>, model: string, touchpoint_count: int}>
     */
    public function batchAttribution(array $identifiers): array
    {
        $results = [];

        foreach ($identifiers as $id) {
            $results[$id] = $this->getAttribution($id);
        }

        return $results;
    }

    /**
     * Normalize and validate UTM parameters.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string|null>
     */
    private function normalizeUtmParams(array $params): array
    {
        return [
            'utm_source' => isset($params['utm_source']) && is_string($params['utm_source']) && $params['utm_source'] !== ''
                ? mb_substr($params['utm_source'], 0, 200)
                : null,
            'utm_medium' => isset($params['utm_medium']) && is_string($params['utm_medium']) && $params['utm_medium'] !== ''
                ? mb_substr($params['utm_medium'], 0, 100)
                : null,
            'utm_campaign' => isset($params['utm_campaign']) && is_string($params['utm_campaign']) && $params['utm_campaign'] !== ''
                ? mb_substr($params['utm_campaign'], 0, 200)
                : null,
            'utm_term' => isset($params['utm_term']) && is_string($params['utm_term']) && $params['utm_term'] !== ''
                ? mb_substr($params['utm_term'], 0, 200)
                : null,
            'utm_content' => isset($params['utm_content']) && is_string($params['utm_content']) && $params['utm_content'] !== ''
                ? mb_substr($params['utm_content'], 0, 200)
                : null,
        ];
    }

    /**
     * Get empty UTM params structure.
     *
     * @return array<string, null>
     */
    private function emptyUtmParams(): array
    {
        return [
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'utm_term' => null,
            'utm_content' => null,
        ];
    }

    /**
     * Get the most frequent value from an array.
     *
     * @param  list<string>  $values
     * @return string|null
     */
    private function mostFrequent(array $values): ?string
    {
        $filtered = array_filter($values, static fn ($v): bool => is_string($v) && $v !== '');

        if ($filtered === []) {
            return null;
        }

        $counts = array_count_values($filtered);
        arsort($counts);

        return array_key_first($counts);
    }
}
