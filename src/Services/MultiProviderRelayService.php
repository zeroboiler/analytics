<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Multi-provider event relay service for cross-provider event broadcasting.
 *
 * Forwards dispatched analytics events to secondary providers that aren't
 * in the primary dispatch chain. Enables scenarios where a single event
 * should be sent to ALL configured providers regardless of the default routing.
 *
 * Supports:
 * - Per-event provider override rules (event X → also send to provider Y)
 * - Global relay mode (relay all events to all providers)
 * - Provider-specific configuration (endpoint, headers, format transformation)
 * - Relay bypass for events matching exclusion patterns
 * - Metrics tracking for relay dispatch counts and error rates
 *
 * @since 54.0.0
 */
final class MultiProviderRelayService
{
    private const CACHE_PREFIX = 'zb_relay_';

    /** @var array<string, int> Relay dispatch counts by provider */
    private array $dispatchCounts = [];

    /** @var array<string, int> Relay error counts by provider */
    private array $errorCounts = [];

    /** @var array<string, array{enabled: bool, url: string|null, headers: array<string, string>, format: string, retry: int, timeout: int}> */
    private array $relayConfigurations = [];

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly ConfigRepository $config,
    ): void {
        $this->loadRelayConfigurations();
    }

    /**
     * Relay an event to all configured relay providers.
     *
     * Each provider receives the event in its preferred format.
     * Failures are silently logged but don't block the primary dispatch.
     *
     * @param  AnalyticsEvent  $event  The dispatched event
     * @return list<string>  List of provider names that successfully received the relay
     */
    public function relay(AnalyticsEvent $event): array
    {
        $rules = $this->getRelayRules();
        $exclusions = $this->getExclusions();

        // Check if event should be excluded from relay
        if ($this->isExcluded($event->name, $exclusions)) {
            return [];
        }

        // Determine target providers
        $targets = $this->resolveTargets($event->name, $rules);
        if ($targets === []) {
            return [];
        }

        $successful = [];

        foreach ($targets as $provider) {
            $relayConfig = $this->relayConfigurations[$provider] ?? null;
            if ($relayConfig === null || ! $relayConfig['enabled']) {
                continue;
            }

            $result = $this->dispatchToProvider($event, $provider, $relayConfig);
            if ($result) {
                $successful[] = $provider;
            }
        }

        return $successful;
    }

    /**
     * Relay a batch of events to all configured relay providers.
     *
     * More efficient than relaying events one at a time — providers that
     * support batch endpoints receive a single payload.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return array<string, int>  Provider name → successful event count
     */
    public function relayBatch(array $events): array
    {
        $rules = $this->getRelayRules();
        $exclusions = $this->getExclusions();
        $results = [];

        // Group events by their target providers
        $providerEvents = [];
        foreach ($events as $event) {
            if ($this->isExcluded($event->name, $exclusions)) {
                continue;
            }

            $targets = $this->resolveTargets($event->name, $rules);
            foreach ($targets as $provider) {
                $relayConfig = $this->relayConfigurations[$provider] ?? null;
                if ($relayConfig !== null && $relayConfig['enabled']) {
                    $providerEvents[$provider][] = $event;
                }
            }
        }

        // Dispatch to each provider
        foreach ($providerEvents as $provider => $providerEventList) {
            $relayConfig = $this->relayConfigurations[$provider];
            $count = $this->dispatchBatchToProvider($providerEventList, $provider, $relayConfig);
            $results[$provider] = $count;
        }

        return $results;
    }

    /**
     * Get the relay service status and configuration summary.
     *
     * @return array{enabled: bool, providers: list<string>, rules_count: int, exclusions_count: int, metrics: array<string, mixed>}
     */
    public function status(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'providers' => array_keys(array_filter(
                $this->relayConfigurations,
                fn (array $c): bool => $c['enabled'],
            )),
            'rules_count' => count($this->getRelayRules()),
            'exclusions_count' => count($this->getExclusions()),
            'metrics' => $this->getMetrics(),
        ];
    }

    /**
     * Get relay dispatch metrics.
     *
     * @return array{total_dispatched: int, total_errors: int, by_provider: array<string, array{dispatched: int, errors: int}>}
     */
    public function getMetrics(): array
    {
        $byProvider = [];
        foreach (array_keys($this->relayConfigurations) as $provider) {
            $byProvider[$provider] = [
                'dispatched' => $this->dispatchCounts[$provider] ?? 0,
                'errors' => $this->errorCounts[$provider] ?? 0,
            ];
        }

        return [
            'total_dispatched' => array_sum($this->dispatchCounts),
            'total_errors' => array_sum($this->errorCounts),
            'by_provider' => $byProvider,
        ];
    }

    /**
     * Reset relay metrics.
     */
    public function resetMetrics(): void
    {
        $this->dispatchCounts = [];
        $this->errorCounts = [];
    }

    /**
     * Check if the relay service is globally enabled.
     */
    private function isEnabled(): bool
    {
        return (bool) ($this->config->get('zeroboiler.analytics.relay.enabled', false));
    }

    /**
     * Load relay provider configurations from config.
     */
    private function loadRelayConfigurations(): void
    {
        $relayProviders = $this->config->get('zeroboiler.analytics.relay.providers', []);

        foreach ($relayProviders as $name => $settings) {
            $this->relayConfigurations[$name] = [
                'enabled' => (bool) ($settings['enabled'] ?? false),
                'url' => $settings['url'] ?? null,
                'headers' => (array) ($settings['headers'] ?? []),
                'format' => (string) ($settings['format'] ?? 'json'),
                'retry' => (int) ($settings['retry'] ?? 1),
                'timeout' => (int) ($settings['timeout'] ?? 5),
            ];
        }
    }

    /**
     * Get per-event relay rules from config.
     *
     * @return array<string, list<string>>
     */
    private function getRelayRules(): array
    {
        return (array) ($this->config->get('zeroboiler.analytics.relay.rules', []));
    }

    /**
     * Get event exclusion patterns from config.
     *
     * @return list<string>
     */
    private function getExclusions(): array
    {
        return (array) ($this->config->get('zeroboiler.analytics.relay.exclude', []));
    }

    /**
     * Check if an event name matches any exclusion pattern.
     *
     * @param  string  $eventName
     * @param  list<string>  $exclusions
     */
    private function isExcluded(string $eventName, array $exclusions): bool
    {
        foreach ($exclusions as $pattern) {
            if ($pattern === '*') {
                return true;
            }

            if (str_contains($pattern, '*')) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
                if (preg_match($regex, $eventName)) {
                    return true;
                }
            } elseif (strtolower($eventName) === strtolower($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve target providers for an event based on rules.
     *
     * @param  string  $eventName
     * @param  array<string, list<string>>  $rules
     * @return list<string>
     */
    private function resolveTargets(string $eventName, array $rules): array
    {
        $targets = [];

        // Check for global wildcard rule
        if (isset($rules['*'])) {
            $targets = array_merge($targets, $rules['*']);
        }

        // Check for category-level rules (e.g., 'ecommerce:*')
        $category = $this->resolveCategory($eventName);
        $categoryKey = $category . ':*';
        if (isset($rules[$categoryKey])) {
            $targets = array_merge($targets, $rules[$categoryKey]);
        }

        // Check for specific event rules
        if (isset($rules[$eventName])) {
            $targets = array_merge($targets, $rules[$eventName]);
        }

        // Deduplicate while preserving order
        return array_values(array_unique($targets));
    }

    /**
     * Resolve the event category from the catalog.
     */
    private function resolveCategory(string $eventName): string
    {
        $entry = \ZeroBoiler\Analytics\Events\EventCatalog::get($eventName);

        return $entry['category'] ?? 'unknown';
    }

    /**
     * Dispatch a single event to a relay provider.
     *
     * @param  AnalyticsEvent  $event
     * @param  string  $provider
     * @param  array{enabled: bool, url: string|null, headers: array<string, string>, format: string, retry: int, timeout: int}  $relayConfig
     */
    private function dispatchToProvider(AnalyticsEvent $event, string $provider, array $relayConfig): bool
    {
        $url = $relayConfig['url'];

        if ($url === null || $url === '') {
            return false;
        }

        $payload = $this->formatPayload($event, $relayConfig['format'], $provider);

        try {
            $attempts = 0;
            $maxAttempts = $relayConfig['retry'] + 1;

            while ($attempts < $maxAttempts) {
                $attempts++;
                $result = $this->sendHttpRequest($url, $payload, $relayConfig['headers'], $relayConfig['timeout']);

                if ($result) {
                    $this->dispatchCounts[$provider] = ($this->dispatchCounts[$provider] ?? 0) + 1;
                    return true;
                }
            }

            $this->errorCounts[$provider] = ($this->errorCounts[$provider] ?? 0) + 1;
            Log::warning("ZeroBoiler Relay: Failed to relay event '{$event->name}' to '{$provider}' after {$maxAttempts} attempts");

            return false;
        } catch (\Throwable $e) {
            $this->errorCounts[$provider] = ($this->errorCounts[$provider] ?? 0) + 1;
            Log::error("ZeroBoiler Relay: Exception relaying event '{$event->name}' to '{$provider}'", [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Dispatch a batch of events to a relay provider.
     *
     * @param  list<AnalyticsEvent>  $events
     * @param  string  $provider
     * @param  array{enabled: bool, url: string|null, headers: array<string, string>, format: string, retry: int, timeout: int}  $relayConfig
     * @return int  Number of successfully relayed events
     */
    private function dispatchBatchToProvider(array $events, string $provider, array $relayConfig): int
    {
        $url = $relayConfig['url'];

        if ($url === null || $url === '') {
            return 0;
        }

        $batchPayload = [
            'batch' => array_map(
                fn (AnalyticsEvent $e): array => $this->formatPayload($e, $relayConfig['format'], $provider),
                $events,
            ),
            'sent_at' => now()->toIso8601String(),
            'version' => \ZeroBoiler\Analytics\DTO\AnalyticsEvent::VERSION,
        ];

        try {
            $attempts = 0;
            $maxAttempts = $relayConfig['retry'] + 1;

            while ($attempts < $maxAttempts) {
                $attempts++;
                $result = $this->sendHttpRequest($url, $batchPayload, $relayConfig['headers'], $relayConfig['timeout']);

                if ($result) {
                    $count = count($events);
                    $this->dispatchCounts[$provider] = ($this->dispatchCounts[$provider] ?? 0) + $count;
                    return $count;
                }
            }

            $this->errorCounts[$provider] = ($this->errorCounts[$provider] ?? 0) + count($events);
            Log::warning("ZeroBoiler Relay: Failed to relay batch of " . count($events) . " events to '{$provider}' after {$maxAttempts} attempts");

            return 0;
        } catch (\Throwable $e) {
            $this->errorCounts[$provider] = ($this->errorCounts[$provider] ?? 0) + count($events);
            Log::error("ZeroBoiler Relay: Exception relaying batch to '{$provider}'", [
                'error' => $e->getMessage(),
                'event_count' => count($events),
            ]);

            return 0;
        }
    }

    /**
     * Format an event payload for a specific provider format.
     *
     * @param  AnalyticsEvent  $event
     * @param  string  $format  json, ga4, segment, raw
     * @param  string  $provider
     * @return array<string, mixed>
     */
    private function formatPayload(AnalyticsEvent $event, string $format, string $provider): array
    {
        return match ($format) {
            'ga4' => [
                'client_id' => $event->clientId,
                'events' => [[
                    'name' => $event->name,
                    'params' => $event->params,
                ]],
                'timestamp_micros' => (int) (microtime(true) * 1_000_000),
            ],
            'segment' => [
                'type' => 'track',
                'event' => $event->name,
                'anonymousId' => $event->clientId,
                'userId' => $event->userId,
                'properties' => $event->params,
                'context' => [
                    'provider' => $provider,
                    'source' => 'zeroboiler_relay',
                ],
            ],
            'raw' => [
                'name' => $event->name,
                'params' => $event->params,
                'client_id' => $event->clientId,
                'user_id' => $event->userId,
            ],
            default => $event->toArray(),
        };
    }

    /**
     * Send an HTTP POST request to a relay endpoint.
     *
     * @param  string  $url
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @param  int  $timeout
     */
    private function sendHttpRequest(string $url, array $payload, array $headers, int $timeout): bool
    {
        $ch = curl_init();
        if ($ch === false) {
            return false;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            curl_close($ch);
            return false;
        }

        $defaultHeaders = ['Content-Type: application/json', 'Accept: application/json'];
        $allHeaders = array_merge($defaultHeaders, $headers);

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => $allHeaders,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FAILONERROR => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            return false;
        }

        // Consider 2xx responses as successful
        return $httpCode >= 200 && $httpCode < 300;
    }
}
