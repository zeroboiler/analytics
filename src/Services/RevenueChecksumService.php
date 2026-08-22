<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Revenue event integrity verification service.
 *
 * Generates and validates HMAC-SHA256 checksums for revenue-critical events
 * (purchases, subscriptions, refunds, plan changes). Prevents replay attacks
 * and ensures data integrity between client-side and server-side event dispatch.
 *
 * Each checksum covers:
 * - Transaction ID
 * - Revenue value
 * - Currency code
 * - Timestamp (minute-granularity to allow for clock drift)
 * - Salt (configurable secret)
 *
 * Inspired by Stripe's webhook signature verification and Shopify's HMAC order verification.
 *
 * Configuration: `zeroboiler.analytics.revenue_checksum`
 *
 * @since 88.0.0
 */
final class RevenueChecksumService
{
    /** @var string Cache prefix for storing seen checksums (replay prevention) */
    private const CACHE_PREFIX = 'zb_rev_checksum_';

    /** @var int Default TTL for replay-prevention cache (24 hours) */
    private const DEFAULT_REPLAY_TTL = 86400;

    private bool $enabled;

    private string $secret;

    private int $replayTtl;

    private bool $requireChecksum;

    private CacheRepository $cache;

    /**
     * @param  ConfigRepository  $config
     * @param  CacheRepository  $cache
     */
    public function __construct(ConfigRepository $config, CacheRepository $cache){
        $checksumConfig = $config->get('zeroboiler.analytics.revenue_checksum', []);
        /** @var array{enabled?: bool, secret?: string, replay_ttl?: int, require_checksum?: bool} $checksumConfig */

        $this->enabled = (bool) ($checksumConfig['enabled'] ?? true);
        $this->secret = (string) ($checksumConfig['secret'] ?? '');
        $this->replayTtl = (int) ($checksumConfig['replay_ttl'] ?? self::DEFAULT_REPLAY_TTL);
        $this->requireChecksum = (bool) ($checksumConfig['require_checksum'] ?? false);
        $this->cache = $cache;

        // Auto-generate secret if not configured
        if ($this->secret === '' && $this->enabled) {
            $this->secret = $config->get('app.key', '');
            if ($this->secret === '') {
                $this->enabled = false;
            }
        }
    }

    /**
     * Generate an HMAC-SHA256 checksum for a revenue event.
     *
     * @param  string  $transactionId  Transaction/order ID
     * @param  float  $value  Revenue amount
     * @param  string  $currency  ISO 4217 currency code
     * @param  string|null  $eventType  Event type (purchase, subscription, refund, plan_change)
     * @param  int|null  $timestamp  Unix timestamp (defaults to current time, minute-granularity)
     * @return string  Hex-encoded HMAC checksum
     */
    public function generate(
        string $transactionId,
        float $value,
        string $currency,
        ?string $eventType = null,
        ?int $timestamp = null,
    ): string {
        if (! $this->enabled) {
            return '';
        }

        $ts = $timestamp ?? time();
        // Minute-granularity: floor to minute to tolerate clock drift
        $minuteTs = (int) floor($ts / 60) * 60;

        $payload = implode('|', [
            $transactionId,
            number_format($value, 2, '.', ''),
            strtoupper($currency),
            $eventType ?? '',
            (string) $minuteTs,
        ]);

        return hash_hmac('sha256', $payload, $this->secret);
    }

    /**
     * Validate a revenue event checksum.
     *
     * Checks both the HMAC validity and replay protection (deduplication).
     * Returns the validation result with detailed status.
     *
     * @param  string  $transactionId  Transaction/order ID
     * @param  float  $value  Revenue amount
     * @param  string  $currency  ISO 4217 currency code
     * @param  string  $checksum  The checksum to validate
     * @param  string|null  $eventType  Event type
     * @param  int|null  $timestamp  Unix timestamp used for generation
     * @return array{valid: bool, reason: string, replay: bool}
     */
    public function validate(
        string $transactionId,
        float $value,
        string $currency,
        string $checksum,
        ?string $eventType = null,
        ?int $timestamp = null,
    ): array {
        if (! $this->enabled) {
            return ['valid' => true, 'reason' => 'checksum_disabled', 'replay' => false];
        }

        if ($checksum === '') {
            return ['valid' => ! $this->requireChecksum, 'reason' => 'empty_checksum', 'replay' => false];
        }

        $cacheKey = self::CACHE_PREFIX . $checksum;
        if ($this->cache->has($cacheKey)) {
            return ['valid' => false, 'reason' => 'replay_detected', 'replay' => true];
        }

        $ts = $timestamp ?? time();
        $validForAnyMinute = false;

        for ($offset = -1; $offset <= 1; $offset++) {
            $adjustedTs = $ts + ($offset * 60);
            $expected = $this->generate($transactionId, $value, $currency, $eventType, $adjustedTs);
            if (hash_equals($expected, $checksum)) {
                $validForAnyMinute = true;
                break;
            }
        }

        if (! $validForAnyMinute) {
            return ['valid' => false, 'reason' => 'checksum_mismatch', 'replay' => false];
        }

        $this->cache->put($cacheKey, true, $this->replayTtl);

        return ['valid' => true, 'reason' => 'valid', 'replay' => false];
    }

    /**
     * Generate a revenue event with embedded checksum.
     *
     * Convenience method that attaches the checksum to the event parameters.
     *
     * @param  array<string, mixed>  $eventParams  Revenue event parameters
     * @param  string|null  $eventType  Event type
     * @return array{params: array<string, mixed>, checksum: string}
     */
    public function signEvent(array $eventParams, ?string $eventType = null): array
    {
        $transactionId = (string) ($eventParams['transaction_id'] ?? '');
        $value = (float) ($eventParams['value'] ?? 0);
        $currency = (string) ($eventParams['currency'] ?? 'USD');

        $checksum = $this->generate($transactionId, $value, $currency, $eventType);

        return [
            'params' => array_merge($eventParams, [
                '_revenue_checksum' => $checksum,
                '_revenue_checksum_ts' => time(),
            ]),
            'checksum' => $checksum,
        ];
    }

    /**
     * Validate a revenue event that contains an embedded checksum.
     *
     * @param  array<string, mixed>  $eventParams  Revenue event parameters with embedded checksum
     * @param  string|null  $eventType  Event type
     * @return array{valid: bool, reason: string, replay: bool}
     */
    public function validateSignedEvent(array $eventParams, ?string $eventType = null): array
    {
        $checksum = (string) ($eventParams['_revenue_checksum'] ?? '');
        $originalTs = isset($eventParams['_revenue_checksum_ts'])
            ? (int) $eventParams['_revenue_checksum_ts']
            : null;

        return $this->validate(
            (string) ($eventParams['transaction_id'] ?? ''),
            (float) ($eventParams['value'] ?? 0),
            (string) ($eventParams['currency'] ?? 'USD'),
            $checksum,
            $eventType,
            $originalTs,
        );
    }

    /**
     * Check if checksum verification is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Check if checksum is required for revenue events.
     */
    public function isRequired(): bool
    {
        return $this->requireChecksum;
    }

    /**
     * Get the configured replay TTL in seconds.
     */
    public function getReplayTtl(): int
    {
        return $this->replayTtl;
    }
}
