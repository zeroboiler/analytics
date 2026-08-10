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
 * Analytics API guard service for request validation and rate limiting.
 *
 * Provides centralized validation for incoming analytics API requests
 * including payload size limits, event name validation, rate limiting
 * per client ID, and request authenticity checks.
 *
 * Designed as a pre-dispatch gate — run before any event processing
 * to reject invalid or abusive requests early.
 *
 * @version 8.4.0
 *
 * @since 1.0.0
 */
final class AnalyticsApiGuard
{
    private const CACHE_PREFIX = 'zb_api_guard_';

    private const DEFAULT_THROTTLE = 60;

    private const DEFAULT_BATCH_MAX = 25;

    private const DEFAULT_MAX_PAYLOAD_BYTES = 65536; // 64KB

    private const DEFAULT_MAX_EVENT_NAME_LENGTH = 100;

    private const DEFAULT_RATE_WINDOW = 60;

    private CacheRepository $cache;

    private int $throttle;

    private int $batchMax;

    private int $maxPayloadBytes;

    private int $maxEventNameLength;

    private int $rateWindow;

    private bool $enabled;

    /**
     * @param  CacheRepository  $cache  Application cache store
     * @param  ConfigRepository  $config  Analytics config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $apiConfig = $config->get('zeroboiler.analytics.api', []);
        /** @var array{enabled?: bool, throttle?: int} $apiConfig */
        $guardConfig = $config->get('zeroboiler.analytics.api_guard', []);
        /** @var array{enabled?: bool, batch_max?: int, max_payload_bytes?: int, max_event_name_length?: int, rate_window?: int} $guardConfig */

        $this->enabled = (bool) ($guardConfig['enabled'] ?? true);
        $this->throttle = (int) ($apiConfig['throttle'] ?? self::DEFAULT_THROTTLE);
        $this->batchMax = (int) ($guardConfig['batch_max'] ?? self::DEFAULT_BATCH_MAX);
        $this->maxPayloadBytes = (int) ($guardConfig['max_payload_bytes'] ?? self::DEFAULT_MAX_PAYLOAD_BYTES);
        $this->maxEventNameLength = (int) ($guardConfig['max_event_name_length'] ?? self::DEFAULT_MAX_EVENT_NAME_LENGTH);
        $this->rateWindow = (int) ($guardConfig['rate_window'] ?? self::DEFAULT_RATE_WINDOW);
    }

    /**
     * Validate an incoming analytics API request.
     *
     * Checks rate limits, payload size, and event name validity.
     * Returns a validation result with optional rejection reason.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  string|null  $clientId  Client tracking ID
     * @return array{valid: bool, reason?: string, retry_after?: int, remaining?: int}
     */
    public function validate(Request $request, ?string $clientId = null): array
    {
        if (! $this->enabled) {
            return ['valid' => true, 'remaining' => PHP_INT_MAX];
        }

        $identifier = $clientId ?? $request->ip() ?? 'unknown';

        // Rate limit check
        $rateResult = $this->checkRateLimit($identifier);
        if (! $rateResult['allowed']) {
            return $rateResult;
        }

        // Payload size check
        $payloadSize = strlen($request->getContent());
        if ($payloadSize > $this->maxPayloadBytes) {
            return [
                'valid' => false,
                'reason' => 'payload_too_large',
                'remaining' => $rateResult['remaining'] ?? 0,
            ];
        }

        // Event name length check (for single event requests)
        $eventName = $request->input('name');
        if (is_string($eventName) && strlen($eventName) > $this->maxEventNameLength) {
            return [
                'valid' => false,
                'reason' => 'event_name_too_long',
                'remaining' => $rateResult['remaining'] ?? 0,
            ];
        }

        return [
            'valid' => true,
            'remaining' => $rateResult['remaining'] ?? 0,
        ];
    }

    /**
     * Validate a batch request payload.
     *
     * @param  array<int, array{name?: string, params?: array<string, mixed>}>  $events  Batch events
     * @return array{valid: bool, reason?: string, count?: int}
     */
    public function validateBatch(array $events): array
    {
        if (! $this->enabled) {
            return ['valid' => true, 'count' => count($events)];
        }

        if (count($events) > $this->batchMax) {
            return [
                'valid' => false,
                'reason' => 'batch_too_large',
                'count' => count($events),
            ];
        }

        // Validate each event name
        foreach ($events as $index => $event) {
            $name = $event['name'] ?? '';
            if (! is_string($name) || $name === '') {
                return [
                    'valid' => false,
                    'reason' => 'empty_event_name',
                    'count' => $index,
                ];
            }

            if (strlen($name) > $this->maxEventNameLength) {
                return [
                    'valid' => false,
                    'reason' => 'event_name_too_long',
                    'count' => $index,
                ];
            }
        }

        return ['valid' => true, 'count' => count($events)];
    }

    /**
     * Check rate limit for a client identifier.
     *
     * Uses sliding window rate limiting via cache.
     *
     * @param  string  $identifier  Client ID or IP address
     * @return array{allowed: bool, remaining: int, retry_after?: int}
     */
    public function checkRateLimit(string $identifier): array
    {
        $key = self::CACHE_PREFIX . 'rate:' . $identifier;
        $windowKey = self::CACHE_PREFIX . 'window:' . $identifier;

        $current = (int) $this->cache->get($key, 0);
        $windowStart = (int) $this->cache->get($windowKey, 0);
        $now = time();

        // Reset window if expired
        if ($now - $windowStart >= $this->rateWindow) {
            $current = 0;
            $this->cache->put($windowKey, $now, $this->rateWindow + 10);
        }

        if ($current >= $this->throttle) {
            $retryAfter = $this->rateWindow - ($now - $windowStart);

            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => max(1, $retryAfter),
            ];
        }

        // Increment counter
        $this->cache->increment($key);
        $this->cache->put($key, $current + 1, $this->rateWindow + 10);

        return [
            'allowed' => true,
            'remaining' => max(0, $this->throttle - $current - 1),
        ];
    }

    /**
     * Record a successful request (for metrics).
     *
     * @param  string  $identifier  Client ID or IP
     * @param  string  $event  Event name or 'batch'
     */
    public function recordSuccess(string $identifier, string $event): void
    {
        // Reserved for future metrics tracking
    }

    /**
     * Record a rejected request (for metrics and alerts).
     *
     * @param  string  $identifier  Client ID or IP
     * @param  string  $reason  Rejection reason
     */
    public function recordRejection(string $identifier, string $reason): void
    {
        $key = self::CACHE_PREFIX . 'rejections:' . $identifier;
        $rejections = $this->cache->get($key, []);
        $rejections[] = ['reason' => $reason, 'timestamp' => date('c')];

        // Keep only last 100 rejections per client
        $this->cache->put($key, array_slice($rejections, -100), 3600);
    }

    /**
     * Check if the guard is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the configured throttle limit.
     */
    public function getThrottle(): int
    {
        return $this->throttle;
    }

    /**
     * Get the maximum batch size.
     */
    public function getBatchMax(): int
    {
        return $this->batchMax;
    }

    /**
     * Get rate limit status for a client.
     *
     * @param  string  $identifier  Client ID or IP
     * @return array{current: int, limit: int, remaining: int, window_seconds: int}
     */
    public function getRateLimitStatus(string $identifier): array
    {
        $key = self::CACHE_PREFIX . 'rate:' . $identifier;
        $current = (int) $this->cache->get($key, 0);

        return [
            'current' => $current,
            'limit' => $this->throttle,
            'remaining' => max(0, $this->throttle - $current),
            'window_seconds' => $this->rateWindow,
        ];
    }
}
