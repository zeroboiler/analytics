<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event forwarding service for dispatching analytics events to external platforms.
 *
 * Supports forwarding to Segment, Mixpanel, Amplitude, Custom webhooks, and
 * any HTTP-based analytics backend. Each forwarder has independent configuration,
 * enable/disable toggle, and retry strategy.
 *
 * Configuration is read from `zeroboiler.analytics.forwarding`.
 *
 * @see \ZeroBoiler\Analytics\AnalyticsManager
 */
final class EventForwardingService
{
    /** @var array<string, mixed> */
    private array $config;

    private CacheRepository $cache;

    private bool $enabled;

    private int $timeout;

    private int $retries;

    private int $rateLimitPerMinute;

    private const CACHE_PREFIX = 'zb_analytics_fwd_';

    private const RATE_LIMIT_CACHE_KEY = 'zb_analytics_fwd_rate';

    /**
     * Built-in forwarder type definitions with parameter transformers.
     *
     * @var array<string, array{endpoint: string, method: string, headers: array<string, string>, payload_transformer: string, required_config: list<string>}>
     */
    private const FORWARDER_TYPES = [
        'segment' => [
            'endpoint' => 'https://api.segment.io/v1/track',
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'payload_transformer' => 'transformForSegment',
            'required_config' => ['write_key'],
        ],
        'mixpanel' => [
            'endpoint' => 'https://api.mixpanel.com/track',
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'payload_transformer' => 'transformForMixpanel',
            'required_config' => ['token'],
        ],
        'amplitude' => [
            'endpoint' => 'https://api2.amplitude.com/2/httpapi',
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'payload_transformer' => 'transformForAmplitude',
            'required_config' => ['api_key'],
        ],
        'custom' => [
            'endpoint' => '',
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'payload_transformer' => 'transformForCustom',
            'required_config' => ['url'],
        ],
    ];

    /**
     * @param  CacheRepository  $cache  Cache repository for rate limiting and dedup
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
: void {
        $this->cache = $cache;
        $forwardingConfig = $config->get('zeroboiler.analytics.forwarding', []);
        /** @var array{enabled?: bool, timeout?: int, retries?: int, rate_limit_per_minute?: int, forwarders?: array<string, mixed>} $forwardingConfig */

        $this->config = $forwardingConfig['forwarders'] ?? [];
        $this->enabled = (bool) ($forwardingConfig['enabled'] ?? false);
        $this->timeout = (int) ($forwardingConfig['timeout'] ?? 5);
        $this->retries = (int) ($forwardingConfig['retries'] ?? 1);
        $this->rateLimitPerMinute = (int) ($forwardingConfig['rate_limit_per_minute'] ?? 1000);
    }

    /**
     * Check if event forwarding is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the list of configured forwarder names.
     *
     * @return list<string>
     */
    public function forwarderNames(): array
    {
        return array_keys($this->config);
    }

    /**
     * Check if a specific forwarder is configured and enabled.
     */
    public function hasForwarder(string $name): bool
    {
        return isset($this->config[$name]) && (bool) ($this->config[$name]['enabled'] ?? false);
    }

    /**
     * Get configuration for a specific forwarder.
     *
     * @return array<string, mixed>|null
     */
    public function getForwarderConfig(string $name): ?array
    {
        return $this->config[$name] ?? null;
    }

    /**
     * Forward an analytics event to all enabled forwarders.
     *
     * @return array{success: list<string>, failed: list<string>, skipped: list<string>}
     */
    public function forwardEvent(AnalyticsEvent $event): array
    {
        if (! $this->enabled) {
            return ['success' => [], 'failed' => [], 'skipped' => $this->forwarderNames()];
        }

        if (! $this->checkRateLimit()) {
            return ['success' => [], 'failed' => [], 'skipped' => $this->forwarderNames()];
        }

        $results = ['success' => [], 'failed' => [], 'skipped' => []];

        foreach ($this->config as $name => $forwarderConfig) {
            /** @var array{enabled?: bool, type?: string} $forwarderConfig */
            if (! (bool) ($forwarderConfig['enabled'] ?? false)) {
                $results['skipped'][] = $name;

                continue;
            }

            $type = (string) ($forwarderConfig['type'] ?? 'custom');
            if (! isset(self::FORWARDER_TYPES[$type])) {
                $results['skipped'][] = $name;

                continue;
            }

            // Check required config
            $missing = $this->getMissingRequiredConfig($type, $forwarderConfig);
            if ($missing !== []) {
                $results['skipped'][] = $name;

                continue;
            }

            try {
                $this->sendToForwarder($name, $type, $event, $forwarderConfig);
                $results['success'][] = $name;
            } catch (\Throwable $e) {
                $results['failed'][] = $name;
                Log::debug("ZeroBoiler Analytics: Forwarding to '{$name}' failed: {$e->getMessage()}");
            }
        }

        return $results;
    }

    /**
     * Forward a batch of events to all enabled forwarders.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return array{total: int, success: int, failed: int, skipped: int}
     */
    public function forwardBatch(array $events): array
    {
        $total = count($events);
        $success = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($events as $event) {
            $result = $this->forwardEvent($event);
            $success += count($result['success']);
            $failed += count($result['failed']);
            $skipped += count($result['skipped']);
        }

        return [
            'total' => $total,
            'success' => $success,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }

    /**
     * Forward to a specific forwarder only.
     *
     * @return bool True if forwarding succeeded
     */
    public function forwardTo(AnalyticsEvent $event, string $forwarderName): bool
    {
        if (! $this->hasForwarder($forwarderName)) {
            return false;
        }

        $forwarderConfig = $this->config[$forwarderName];
        /** @var array{type?: string} $forwarderConfig */
        $type = (string) ($forwarderConfig['type'] ?? 'custom');

        try {
            $this->sendToForwarder($forwarderName, $type, $event, $forwarderConfig);

            return true;
        } catch (\Throwable $e) {
            Log::debug("ZeroBoiler Analytics: Forwarding to '{$forwarderName}' failed: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Get forwarding statistics from cache.
     *
     * @return array{total_sent: int, total_failed: int, total_skipped: int, rate_limit_hits: int}
     */
    public function stats(): array
    {
        return [
            'total_sent' => (int) $this->cache->get(self::CACHE_PREFIX . 'total_sent', 0),
            'total_failed' => (int) $this->cache->get(self::CACHE_PREFIX . 'total_failed', 0),
            'total_skipped' => (int) $this->cache->get(self::CACHE_PREFIX . 'total_skipped', 0),
            'rate_limit_hits' => (int) $this->cache->get(self::CACHE_PREFIX . 'rate_limit_hits', 0),
        ];
    }

    /**
     * Reset forwarding statistics.
     */
    public function resetStats(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'total_sent');
        $this->cache->forget(self::CACHE_PREFIX . 'total_failed');
        $this->cache->forget(self::CACHE_PREFIX . 'total_skipped');
        $this->cache->forget(self::CACHE_PREFIX . 'rate_limit_hits');
    }

    /**
     * Test a forwarder connection by sending a test event.
     *
     * @return array{success: bool, forwarder: string, error?: string, response_code?: int}
     */
    public function testForwarder(string $name): array
    {
        if (! $this->hasForwarder($name)) {
            return ['success' => false, 'forwarder' => $name, 'error' => 'Forwarder not found or disabled'];
        }

        $testEvent = new AnalyticsEvent(
            name: '_test_event',
            params: ['source' => 'zeroboiler_analytics_test', 'timestamp' => time()],
            clientId: 'test_client',
            userId: 'test_user',
        );

        $forwarderConfig = $this->config[$name];
        /** @var array{type?: string} $forwarderConfig */
        $type = (string) ($forwarderConfig['type'] ?? 'custom');

        try {
            $response = $this->sendToForwarder($name, $type, $testEvent, $forwarderConfig);

            return ['success' => true, 'forwarder' => $name, 'response_code' => $response];
        } catch (\Throwable $e) {
            return ['success' => false, 'forwarder' => $name, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send an event to a specific forwarder.
     *
     * @param  array<string, mixed>  $forwarderConfig
     * @return int HTTP status code
     */
    private function sendToForwarder(string $name, string $type, AnalyticsEvent $event, array $forwarderConfig): int
    {
        $typeConfig = self::FORWARDER_TYPES[$type];
        $transformer = $typeConfig['payload_transformer'];
        $payload = $this->$transformer($event, $forwarderConfig);
        $endpoint = (string) ($forwarderConfig['url'] ?? $typeConfig['endpoint']);

        if ($endpoint === '') {
            throw new \RuntimeException("No endpoint configured for forwarder '{$name}'");
        }

        $headers = $this->buildHeaders($name, $type, $forwarderConfig, $typeConfig);
        $method = (string) ($forwarderConfig['method'] ?? $typeConfig['method']);

        $attempts = 0;
        $lastException = null;

        while ($attempts <= $this->retries) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders($headers)
                    ->$method($endpoint, $payload);

                if ($response->successful()) {
                    $this->incrementStat('total_sent');

                    return $response->status();
                }

                $lastException = new \RuntimeException("HTTP {$response->status()}: {$response->body()}");
            } catch (\Throwable $e) {
                $lastException = $e;
            }

            $attempts++;

            if ($attempts <= $this->retries) {
                usleep((int) (100_000 * pow(2, $attempts - 1))); // exponential backoff
            }
        }

        $this->incrementStat('total_failed');
        throw $lastException ?? new \RuntimeException("Forwarding to '{$name}' failed after {$attempts} attempts");
    }

    /**
     * Build HTTP headers for a forwarder request.
     *
     * @param  array<string, mixed>  $forwarderConfig
     * @param  array{headers: array<string, string>}  $typeConfig
     * @return array<string, string>
     */
    private function buildHeaders(string $name, string $type, array $forwarderConfig, array $typeConfig): array
    {
        $headers = $typeConfig['headers'];

        // Add authentication headers based on type
        if ($type === 'segment') {
            $writeKey = (string) ($forwarderConfig['write_key'] ?? '');
            $headers['Authorization'] = 'Basic ' . base64_encode($writeKey . ':');
        } elseif ($type === 'amplitude') {
            $apiKey = (string) ($forwarderConfig['api_key'] ?? '');
            $headers['Authorization'] = 'Basic ' . base64_encode($apiKey . ':');
        }

        // Merge custom headers from config
        $customHeaders = $forwarderConfig['headers'] ?? [];
        /** @var array<string, string> $customHeaders */
        foreach ($customHeaders as $key => $value) {
            $headers[$key] = (string) $value;
        }

        return $headers;
    }

    /**
     * Transform event payload for Segment API format.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function transformForSegment(AnalyticsEvent $event, array $config): array
    {
        return [
            'userId' => $event->userId ?? 'anonymous',
            'event' => $event->name,
            'properties' => $event->params,
            'timestamp' => date('c'),
            'context' => [
                'library' => ['name' => 'zeroboiler-analytics', 'version' => '2.58.0'],
            ],
        ];
    }

    /**
     * Transform event payload for Mixpanel API format.
     *
     * @param  array<string, mixed>  $config
     * @return string URL-encoded JSON payload (Mixpanel format)
     */
    private function transformForMixpanel(AnalyticsEvent $event, array $config): string
    {
        $token = (string) ($config['token'] ?? '');
        $payload = [
            'event' => $event->name,
            'properties' => array_merge(
                [
                    'token' => $token,
                    'time' => (int) (microtime(true) * 1000),
                    'distinct_id' => $event->userId ?? $event->clientId ?? 'anonymous',
                ],
                $event->params,
            ),
        ];

        return 'data=' . urlencode(json_encode($payload));
    }

    /**
     * Transform event payload for Amplitude API format.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function transformForAmplitude(AnalyticsEvent $event, array $config): array
    {
        $apiKey = (string) ($config['api_key'] ?? '');

        return [
            'api_key' => $apiKey,
            'events' => [
                [
                    'event_type' => $event->name,
                    'event_properties' => $event->params,
                    'user_id' => $event->userId ?? null,
                    'device_id' => $event->clientId ?? null,
                    'time' => (int) (microtime(true) * 1000),
                    'library' => 'zeroboiler-analytics/2.52.0',
                ],
            ],
        ];
    }

    /**
     * Transform event payload for custom webhook format.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function transformForCustom(AnalyticsEvent $event, array $config): array
    {
        return [
            'event' => $event->name,
            'params' => $event->params,
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
            'timestamp' => date('c'),
            'source' => 'zeroboiler-analytics',
            'version' => '2.58.0',
        ];
    }

    /**
     * Check rate limit before sending.
     */
    private function checkRateLimit(): bool
    {
        $current = (int) $this->cache->get(self::RATE_LIMIT_CACHE_KEY, 0);

        if ($current >= $this->rateLimitPerMinute) {
            $this->incrementStat('rate_limit_hits');

            return false;
        }

        $this->cache->put(self::RATE_LIMIT_CACHE_KEY, $current + 1, 60);

        return true;
    }

    /**
     * Increment a forwarding statistic counter.
     */
    private function incrementStat(string $key): void
    {
        $this->cache->increment(self::CACHE_PREFIX . $key);
    }

    /**
     * Get missing required configuration keys for a forwarder type.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function getMissingRequiredConfig(string $type, array $config): array
    {
        $required = self::FORWARDER_TYPES[$type]['required_config'] ?? [];
        $missing = [];

        foreach ($required as $key) {
            if (! isset($config[$key]) || $config[$key] === '' || $config[$key] === null) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
