<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Cross-platform event attribution service.
 *
 * Provides unified attribution across GA4, Meta, Plausible, PostHog,
 * and custom webhook providers. Normalizes attribution data from
 * different providers into a common format, enabling cross-platform
 * reporting and deduplication of conversion events.
 *
 * Supports multiple attribution models: first-touch, last-touch,
 * linear multi-touch, time-decay, and position-based (U-shaped).
 * Handles provider-specific identifier reconciliation (GA4 client ID,
 * Meta pixel fbc/fbp, PostHog distinct ID, Plausible domain ref).
 *
 * Configuration is read from `zeroboiler.analytics.cross_platform_attribution`.
 *
 * @since 115.0.0
 */
final class CrossPlatformAttributionService
{
    private CacheRepository $cache;

    private ConfigRepository $config;

    private string $attributionModel;

    private int $lookbackWindowDays;

    private int $cacheTtl;

    /** @var array<string, string> Provider display names */
    private const PROVIDER_NAMES = [
        'ga4' => 'Google Analytics 4',
        'meta' => 'Meta Pixel',
        'plausible' => 'Plausible',
        'posthog' => 'PostHog',
        'webhook' => 'Webhook',
    ];

    /** @var list<string> Supported attribution models */
    private const SUPPORTED_MODELS = [
        'first_touch',
        'last_touch',
        'linear',
        'time_decay',
        'position_based',
    ];

    private const CACHE_PREFIX = 'zb_attribution_cross_';

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;
        $this->config = $config;

        $xpaConfig = $config->get('zeroboiler.analytics.cross_platform_attribution', []);
        /** @var array{model?: string, lookback_window_days?: int, cache_ttl?: int} $xpaConfig */

        $model = (string) ($xpaConfig['model'] ?? 'last_touch');
        $this->attributionModel = in_array($model, self::SUPPORTED_MODELS, true) ? $model : 'last_touch';
        $this->lookbackWindowDays = (int) ($xpaConfig['lookback_window_days'] ?? 90);
        $this->cacheTtl = (int) ($xpaConfig['cache_ttl'] ?? 86400);
    }

    /**
     * Record a cross-platform touchpoint.
     *
     * @param  string  $identity  Client ID or user ID
     * @param  string  $provider  Provider identifier (ga4, meta, plausible, posthog, webhook)
     * @param  array<string, mixed>  $data  Provider-specific attribution data
     * @return void
     */
    public function recordTouchpoint(string $identity, string $provider, array $data): void
    {
        if (! isset(self::PROVIDER_NAMES[$provider])) {
            return;
        }

        $cacheKey = self::CACHE_PREFIX . 'touchpoints_' . $identity;
        /** @var list<array{provider: string, data: array<string, mixed>, timestamp: string}> $touchpoints */
        $touchpoints = $this->cache->get($cacheKey, []);

        $touchpoints[] = [
            'provider' => $provider,
            'data' => $data,
            'timestamp' => now()->toIso8601String(),
        ];

        // Keep only touchpoints within the lookback window
        $cutoff = now()->subDays($this->lookbackWindowDays);
        $touchpoints = array_values(array_filter(
            $touchpoints,
            static fn (array $tp): bool => now()->parse($tp['timestamp'])->gt($cutoff),
        ));

        $this->cache->put($cacheKey, $touchpoints, $this->cacheTtl);
    }

    /**
     * Get all touchpoints for an identity.
     *
     * @param  string  $identity  Client ID or user ID
     * @return list<array{provider: string, provider_name: string, data: array<string, mixed>, timestamp: string}>
     */
    public function getTouchpoints(string $identity): array
    {
        $cacheKey = self::CACHE_PREFIX . 'touchpoints_' . $identity;
        /** @var list<array{provider: string, data: array<string, mixed>, timestamp: string}> $raw */
        $raw = $this->cache->get($cacheKey, []);

        return array_values(array_map(function (array $tp): array {
            return [
                'provider' => $tp['provider'],
                'provider_name' => self::PROVIDER_NAMES[$tp['provider']] ?? $tp['provider'],
                'data' => $tp['data'],
                'timestamp' => $tp['timestamp'],
            ];
        }, $raw));
    }

    /**
     * Compute attribution for an identity using the configured model.
     *
     * @param  string  $identity  Client ID or user ID
     * @return array{model: string, attributed: list<array{provider: string, provider_name: string, credit: float, data: array<string, mixed>, timestamp: string}>, total_credit: float}
     */
    public function attribute(string $identity): array
    {
        $touchpoints = $this->getTouchpoints($identity);

        if ($touchpoints === []) {
            return [
                'model' => $this->attributionModel,
                'attributed' => [],
                'total_credit' => 0.0,
            ];
        }

        $count = count($touchpoints);

        return match ($this->attributionModel) {
            'first_touch' => $this->attributeFirstTouch($touchpoints),
            'last_touch' => $this->attributeLastTouch($touchpoints),
            'linear' => $this->attributeLinear($touchpoints, $count),
            'time_decay' => $this->attributeTimeDecay($touchpoints),
            'position_based' => $this->attributePositionBased($touchpoints, $count),
            default => $this->attributeLastTouch($touchpoints),
        };
    }

    /**
     * Get provider breakdown for an identity — event count per provider.
     *
     * @param  string  $identity  Client ID or user ID
     * @return array<string, int>
     */
    public function providerBreakdown(string $identity): array
    {
        $touchpoints = $this->getTouchpoints($identity);
        $breakdown = [];

        foreach (self::PROVIDER_NAMES as $key => $name) {
            $breakdown[$key] = 0;
        }

        foreach ($touchpoints as $tp) {
            $provider = $tp['provider'];
            $breakdown[$provider] = ($breakdown[$provider] ?? 0) + 1;
        }

        return $breakdown;
    }

    /**
     * Normalize a GA4 attribution payload into the common format.
     *
     * @param  array<string, mixed>  $ga4Data
     * @return array<string, mixed>
     */
    public static function normalizeGa4(array $ga4Data): array
    {
        return [
            'source' => (string) ($ga4Data['source'] ?? $ga4Data['utm_source'] ?? '(direct)'),
            'medium' => (string) ($ga4Data['medium'] ?? $ga4Data['utm_medium'] ?? '(none)'),
            'campaign' => (string) ($ga4Data['campaign'] ?? $ga4Data['utm_campaign'] ?? ''),
            'term' => (string) ($ga4Data['term'] ?? $ga4Data['utm_term'] ?? ''),
            'content' => (string) ($ga4Data['content'] ?? $ga4Data['utm_content'] ?? ''),
            'session_id' => (string) ($ga4Data['session_id'] ?? ''),
            'client_id' => (string) ($ga4Data['client_id'] ?? ''),
        ];
    }

    /**
     * Normalize a Meta Pixel attribution payload into the common format.
     *
     * @param  array<string, mixed>  $metaData
     * @return array<string, mixed>
     */
    public static function normalizeMeta(array $metaData): array
    {
        return [
            'source' => (string) ($metaData['utm_source'] ?? $metaData['source'] ?? '(direct)'),
            'medium' => (string) ($metaData['utm_medium'] ?? $metaData['medium'] ?? '(none)'),
            'campaign' => (string) ($metaData['utm_campaign'] ?? $metaData['campaign'] ?? ''),
            'fbc' => (string) ($metaData['fbc'] ?? ''),
            'fbp' => (string) ($metaData['fbp'] ?? ''),
            'event_id' => (string) ($metaData['event_id'] ?? ''),
        ];
    }

    /**
     * Normalize a Plausible attribution payload into the common format.
     *
     * @param  array<string, mixed>  $plausibleData
     * @return array<string, mixed>
     */
    public static function normalizePlausible(array $plausibleData): array
    {
        return [
            'source' => (string) ($plausibleData['referrer'] ?? $plausibleData['source'] ?? '(direct)'),
            'medium' => (string) ($plausibleData['utm_medium'] ?? 'referral'),
            'campaign' => (string) ($plausibleData['utm_campaign'] ?? ''),
            'domain' => (string) ($plausibleData['domain'] ?? ''),
            'path' => (string) ($plausibleData['path'] ?? ''),
        ];
    }

    /**
     * Normalize a PostHog attribution payload into the common format.
     *
     * @param  array<string, mixed>  $posthogData
     * @return array<string, mixed>
     */
    public static function normalizePosthog(array $posthogData): array
    {
        return [
            'source' => (string) ($posthogData['utm_source'] ?? $posthogData['referrer'] ?? '(direct)'),
            'medium' => (string) ($posthogData['utm_medium'] ?? $posthogData['medium'] ?? '(none)'),
            'campaign' => (string) ($posthogData['utm_campaign'] ?? ''),
            'distinct_id' => (string) ($posthogData['distinct_id'] ?? ''),
            '$current_url' => (string) ($posthogData['$current_url'] ?? ''),
        ];
    }

    /**
     * Clear all touchpoints for an identity.
     *
     * @param  string  $identity  Client ID or user ID
     * @return bool
     */
    public function clearTouchpoints(string $identity): bool
    {
        return $this->cache->forget(self::CACHE_PREFIX . 'touchpoints_' . $identity);
    }

    /**
     * Get the current attribution model name.
     *
     * @return string
     */
    public function getModel(): string
    {
        return $this->attributionModel;
    }

    /**
     * Get supported attribution models.
     *
     * @return list<string>
     */
    public static function supportedModels(): array
    {
        return self::SUPPORTED_MODELS;
    }

    /**
     * Get supported providers.
     *
     * @return array<string, string>
     */
    public static function supportedProviders(): array
    {
        return self::PROVIDER_NAMES;
    }

    /**
     * First-touch attribution: 100% credit to the earliest touchpoint.
     *
     * @param  list<array{provider: string, provider_name: string, data: array<string, mixed>, timestamp: string}>  $touchpoints
     * @return array{model: string, attributed: list<array{provider: string, provider_name: string, credit: float, data: array<string, mixed>, timestamp: string}>, total_credit: float}
     */
    private function attributeFirstTouch(array $touchpoints): array
    {
        $first = $touchpoints[0];

        return [
            'model' => 'first_touch',
            'attributed' => [[
                'provider' => $first['provider'],
                'provider_name' => $first['provider_name'],
                'credit' => 1.0,
                'data' => $first['data'],
                'timestamp' => $first['timestamp'],
            ]],
            'total_credit' => 1.0,
        ];
    }

    /**
     * Last-touch attribution: 100% credit to the most recent touchpoint.
     *
     * @param  list<array{provider: string, provider_name: string, data: array<string, mixed>, timestamp: string}>  $touchpoints
     * @return array{model: string, attributed: list<array{provider: string, provider_name: string, credit: float, data: array<string, mixed>, timestamp: string}>, total_credit: float}
     */
    private function attributeLastTouch(array $touchpoints): array
    {
        $last = $touchpoints[array_key_last($touchpoints)];

        return [
            'model' => 'last_touch',
            'attributed' => [[
                'provider' => $last['provider'],
                'provider_name' => $last['provider_name'],
                'credit' => 1.0,
                'data' => $last['data'],
                'timestamp' => $last['timestamp'],
            ]],
            'total_credit' => 1.0,
        ];
    }

    /**
     * Linear attribution: equal credit across all touchpoints.
     *
     * @param  list<array{provider: string, provider_name: string, data: array<string, mixed>, timestamp: string}>  $touchpoints
     * @param  int  $count
     * @return array{model: string, attributed: list<array{provider: string, provider_name: string, credit: float, data: array<string, mixed>, timestamp: string}>, total_credit: float}
     */
    private function attributeLinear(array $touchpoints, int $count): array
    {
        $credit = $count > 0 ? 1.0 / $count : 0.0;

        $attributed = array_map(function (array $tp) use ($credit): array {
            return [
                'provider' => $tp['provider'],
                'provider_name' => $tp['provider_name'],
                'credit' => $credit,
                'data' => $tp['data'],
                'timestamp' => $tp['timestamp'],
            ];
        }, $touchpoints);

        return [
            'model' => 'linear',
            'attributed' => $attributed,
            'total_credit' => 1.0,
        ];
    }

    /**
     * Time-decay attribution: more credit to recent touchpoints.
     *
     * Uses exponential decay: credit_i = 2^(n-i-1) / (2^n - 1) for n touchpoints.
     *
     * @param  list<array{provider: string, provider_name: string, data: array<string, mixed>, timestamp: string}>  $touchpoints
     * @return array{model: string, attributed: list<array{provider: string, provider_name: string, credit: float, data: array<string, mixed>, timestamp: string}>, total_credit: float}
     */
    private function attributeTimeDecay(array $touchpoints): array
    {
        $n = count($touchpoints);
        if ($n === 0) {
            return ['model' => 'time_decay', 'attributed' => [], 'total_credit' => 0.0];
        }

        $denominator = (2 ** $n) - 1;
        $attributed = [];

        for ($i = 0; $i < $n; $i++) {
            $tp = $touchpoints[$i];
            $credit = $denominator > 0 ? (2 ** ($n - $i - 1)) / $denominator : 0.0;
            $attributed[] = [
                'provider' => $tp['provider'],
                'provider_name' => $tp['provider_name'],
                'credit' => $credit,
                'data' => $tp['data'],
                'timestamp' => $tp['timestamp'],
            ];
        }

        return [
            'model' => 'time_decay',
            'attributed' => $attributed,
            'total_credit' => 1.0,
        ];
    }

    /**
     * Position-based (U-shaped) attribution: 40% first, 20% middle, 40% last.
     *
     * @param  list<array{provider: string, provider_name: string, data: array<string, mixed>, timestamp: string}>  $touchpoints
     * @param  int  $count
     * @return array{model: string, attributed: list<array{provider: string, provider_name: string, credit: float, data: array<string, mixed>, timestamp: string}>, total_credit: float}
     */
    private function attributePositionBased(array $touchpoints, int $count): array
    {
        if ($count === 0) {
            return ['model' => 'position_based', 'attributed' => [], 'total_credit' => 0.0];
        }

        if ($count === 1) {
            return $this->attributeLastTouch($touchpoints);
        }

        $attributed = [];
        $middleCredit = $count > 2 ? 0.2 / ($count - 2) : 0.0;

        for ($i = 0; $i < $count; $i++) {
            $tp = $touchpoints[$i];
            $credit = match (true) {
                $i === 0 => 0.4,
                $i === $count - 1 => 0.4,
                default => $middleCredit,
            };
            $attributed[] = [
                'provider' => $tp['provider'],
                'provider_name' => $tp['provider_name'],
                'credit' => $credit,
                'data' => $tp['data'],
                'timestamp' => $tp['timestamp'],
            ];
        }

        return [
            'model' => 'position_based',
            'attributed' => $attributed,
            'total_credit' => 1.0,
        ];
    }
}
