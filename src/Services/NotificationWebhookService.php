<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Http;
/**
 * Notification Webhook Service.
 *
 * Sends analytics alert notifications to external webhook endpoints
 * (Slack, Discord, Microsoft Teams, PagerDuty, custom HTTP).
 *
 * Integrates with the EventAlertRulesService to deliver alert notifications
 * when rule conditions are triggered. Supports message formatting per
 * channel type, rate limiting, retry with backoff, and delivery tracking.
 *
 * Supported channel types:
 * - slack: Posts to Slack incoming webhook with Block Kit formatting
 * - discord: Posts to Discord webhook with embed formatting
 * - teams: Posts to Microsoft Teams webhook with Adaptive Card formatting
 * - generic: Posts raw JSON to any HTTP endpoint
 * - pagerduty: Posts to PagerDuty Events API v2
 *
 * Configuration: `zeroboiler.analytics.notification_webhooks`
 *
 * @see \ZeroBoiler\Analytics\Services\EventAlertRulesService
 * @version 5.0.0
 *
 * @since 1.0.0
 */
final class NotificationWebhookService
{
    private const CACHE_PREFIX = 'zb_notif_';
    private const DEFAULT_TIMEOUT = 10;
    private const DEFAULT_RETRIES = 2;

    /** @var array<string, array{enabled: bool, url: string, channel: string, secret?: string, timeout: int, retries: int, min_severity?: string, events?: string[]}> */
    private array $webhooks;

    private bool $enabled;

    private CacheRepository $cache;

    /** @var array<string, int> Rate limiting: webhook name → last sent timestamp */
    private array $rateLimits;

    private int $rateLimitSeconds;

    /** @var array<string, int> Delivery tracking: webhook name → success count */
    private array $deliveryStats;

    private int $maxDeliveryHistory;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $notifConfig = $config->get('zeroboiler.analytics.notification_webhooks', []);
        /** @var array{enabled?: bool, rate_limit_seconds?: int, max_delivery_history?: int, webhooks?: array<string, mixed>} $notifConfig */

        $this->enabled = (bool) ($notifConfig['enabled'] ?? false);
        $this->rateLimitSeconds = (int) ($notifConfig['rate_limit_seconds'] ?? 60);
        $this->maxDeliveryHistory = (int) ($notifConfig['max_delivery_history'] ?? 1000);

        $configuredWebhooks = (array) ($notifConfig['webhooks'] ?? []);
        $this->webhooks = [];

        foreach ($configuredWebhooks as $name => $webhookConfig) {
            /** @var array{url?: string, channel?: string, enabled?: bool, secret?: string, timeout?: int, retries?: int, min_severity?: string, events?: list<string>} $webhookConfig */
            $url = (string) ($webhookConfig['url'] ?? '');
            $channel = (string) ($webhookConfig['channel'] ?? 'generic');

            if ($url === '') {
                continue;
            }

            $this->webhooks[$name] = [
                'enabled' => (bool) ($webhookConfig['enabled'] ?? true),
                'url' => $url,
                'channel' => $channel,
                'secret' => (string) ($webhookConfig['secret'] ?? ''),
                'timeout' => (int) ($webhookConfig['timeout'] ?? self::DEFAULT_TIMEOUT),
                'retries' => (int) ($webhookConfig['retries'] ?? self::DEFAULT_RETRIES),
                'min_severity' => (string) ($webhookConfig['min_severity'] ?? 'info'),
                'events' => (array) ($webhookConfig['events'] ?? []),
            ];
        }

        $this->rateLimits = [];
        $this->deliveryStats = [];
        $this->loadRateLimitState();
        $this->loadDeliveryStats();
    }

    /**
     * Check if notification webhooks are enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Send an alert notification to all matching webhooks.
     *
     * Filters by severity threshold and event allowlist.
     * Respects per-webhook rate limits.
     *
     * @param  array{rule: string, event: string, severity: string, message: string, triggered_at: string, value?: float, threshold?: float}  $alert
     * @return array{sent: int, failed: int, skipped: int, results: array<string, array{status: string, error?: string}>}
     */
    public function sendAlert(array $alert): array
    {
        if (! $this->enabled) {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'results' => []];
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $results = [];

        foreach ($this->webhooks as $name => $webhook) {
            if (! $webhook['enabled']) {
                $skipped++;
                $results[$name] = ['status' => 'disabled'];
                continue;
            }

            if (! $this->meetsSeverityThreshold($alert['severity'] ?? 'info', $webhook['min_severity'])) {
                $skipped++;
                $results[$name] = ['status' => 'severity_filtered'];
                continue;
            }

            if (! $this->matchesEventFilter($alert['event'] ?? '', $webhook['events'])) {
                $skipped++;
                $results[$name] = ['status' => 'event_filtered'];
                continue;
            }

            if (! $this->canSend($name)) {
                $skipped++;
                $results[$name] = ['status' => 'rate_limited'];
                continue;
            }

            $result = $this->dispatch($name, $webhook, $alert);

            if ($result['status'] === 'sent') {
                $sent++;
                $this->recordRateLimit($name);
                $this->recordDelivery($name, true);
            } else {
                $failed++;
                $this->recordDelivery($name, false);
            }

            $results[$name] = $result;
        }

        $this->persistDeliveryStats();

        return ['sent' => $sent, 'failed' => $failed, 'skipped' => $skipped, 'results' => $results];
    }

    /**
     * Send a custom notification message to a specific webhook.
     *
     * Bypasses severity and event filtering but respects rate limits.
     *
     * @param  string  $webhookName  Webhook configuration name
     * @param  string  $message  Notification message
     * @param  array<string, mixed>  $context  Additional context data
     * @return array{status: string, error?: string}
     */
    public function sendCustom(string $webhookName, string $message, array $context = []): array
    {
        if (! $this->enabled) {
            return ['status' => 'disabled'];
        }

        $webhook = $this->webhooks[$webhookName] ?? null;

        if ($webhook === null || ! $webhook['enabled']) {
            return ['status' => 'not_found'];
        }

        if (! $this->canSend($webhookName)) {
            return ['status' => 'rate_limited'];
        }

        $alert = [
            'rule' => 'custom',
            'event' => $context['event'] ?? 'custom',
            'severity' => $context['severity'] ?? 'info',
            'message' => $message,
            'triggered_at' => date('c'),
        ];

        $result = $this->dispatch($webhookName, $webhook, $alert);

        if ($result['status'] === 'sent') {
            $this->recordRateLimit($webhookName);
            $this->recordDelivery($webhookName, true);
        } else {
            $this->recordDelivery($webhookName, false);
        }

        $this->persistDeliveryStats();

        return $result;
    }

    /**
     * Test a webhook connection by sending a ping message.
     *
     * @return array{status: string, response_code?: int, response_body?: string, error?: string, latency_ms?: float}
     */
    public function testWebhook(string $webhookName): array
    {
        $webhook = $this->webhooks[$webhookName] ?? null;

        if ($webhook === null) {
            return ['status' => 'not_found'];
        }

        $payload = $this->formatPayload($webhook['channel'], [
            'rule' => 'test',
            'event' => 'ping',
            'severity' => 'info',
            'message' => '🔔 ZeroBoiler Analytics webhook test — connection verified',
            'triggered_at' => date('c'),
        ]);

        $start = microtime(true);

        try {
            $response = Http::timeout(5)
                ->withHeaders($this->buildHeaders($webhook))
                ->post($webhook['url'], $payload);

            $latency = (microtime(true) - $start) * 1000;

            return [
                'status' => $response->successful() ? 'sent' : 'failed',
                'response_code' => $response->status(),
                'response_body' => $response->body(),
                'latency_ms' => round($latency, 2),
            ];
        } catch (\Throwable $e) {
            $latency = (microtime(true) - $start) * 1000;

            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'latency_ms' => round($latency, 2),
            ];
        }
    }

    /**
     * Get delivery statistics for all webhooks.
     *
     * @return array{webhooks: array<string, array{sent: int, failed: int, success_rate: float, last_sent?: string}>, total_sent: int, total_failed: int}
     */
    public function deliveryStats(): array
    {
        $stats = [];
        $totalSent = 0;
        $totalFailed = 0;

        foreach ($this->webhooks as $name => $webhook) {
            $sent = $this->deliveryStats[$name]['sent'] ?? 0;
            $failed = $this->deliveryStats[$name]['failed'] ?? 0;
            $total = $sent + $failed;

            $stats[$name] = [
                'sent' => $sent,
                'failed' => $failed,
                'success_rate' => $total > 0 ? round($sent / $total, 4) : 0.0,
                'last_sent' => $this->deliveryStats[$name]['last_sent'] ?? null,
            ];

            $totalSent += $sent;
            $totalFailed += $failed;
        }

        return [
            'webhooks' => $stats,
            'total_sent' => $totalSent,
            'total_failed' => $totalFailed,
        ];
    }

    /**
     * Get configured webhooks.
     *
     * @return array<string, array{enabled: bool, channel: string, min_severity: string, url_masked: string}>
     */
    public function getWebhooks(): array
    {
        $list = [];

        foreach ($this->webhooks as $name => $webhook) {
            $url = $webhook['url'];
            $parsed = parse_url($url);
            $host = $parsed['host'] ?? 'unknown';
            $maskedUrl = $parsed['scheme'] ?? 'https' . '://' . $host . '/***';

            $list[$name] = [
                'enabled' => $webhook['enabled'],
                'channel' => $webhook['channel'],
                'min_severity' => $webhook['min_severity'],
                'url_masked' => $maskedUrl,
            ];
        }

        return $list;
    }

    /**
     * Dispatch a notification with retry.
     *
     * @param  string  $name  Webhook name
     * @param  array{url: string, channel: string, secret: string, timeout: int, retries: int}  $webhook
     * @param  array{rule: string, event: string, severity: string, message: string, triggered_at: string}  $alert
     * @return array{status: string, error?: string}
     */
    private function dispatch(string $name, array $webhook, array $alert): array
    {
        $payload = $this->formatPayload($webhook['channel'], $alert);
        $headers = $this->buildHeaders($webhook);
        $attempts = $webhook['retries'];

        for ($attempt = 0; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout($webhook['timeout'])
                    ->withHeaders($headers)
                    ->post($webhook['url'], $payload);

                if ($response->successful()) {
                    return ['status' => 'sent'];
                }

                // Non-successful response, retry if attempts remain
                if ($attempt === $attempts) {
                    return [
                        'status' => 'failed',
                        'error' => "HTTP {$response->status()}: {$response->body()}",
                    ];
                }
            } catch (\Throwable $e) {
                if ($attempt === $attempts) {
                    return ['status' => 'failed', 'error' => $e->getMessage()];
                }
            }

            // Exponential backoff: 1s, 2s, 4s...
            $delay = (int) pow(2, $attempt);
            usleep($delay * 1_000_000);
        }

        return ['status' => 'failed', 'error' => 'Max retries exceeded'];
    }

    /**
     * Format alert payload for a specific channel type.
     *
     * @param  string  $channel  Channel type (slack, discord, teams, generic, pagerduty)
     * @param  array{rule: string, event: string, severity: string, message: string, triggered_at: string}  $alert
     * @return array<string, mixed>
     */
    private function formatPayload(string $channel, array $alert): array
    {
        $severityEmoji = match ($alert['severity']) {
            'critical', 'elevated' => '🔴',
            'warning', 'warn' => '🟡',
            default => '🟢',
        };

        $title = "[{$alert['severity']}] {$alert['message']}";

        return match ($channel) {
            'slack' => [
                'text' => $title,
                'blocks' => [
                    [
                        'type' => 'header',
                        'text' => ['type' => 'plain_text', 'text' => "⚡ ZeroBoiler Analytics Alert"],
                    ],
                    [
                        'type' => 'section',
                        'fields' => [
                            ['type' => 'mrkdwn', 'text' => "*Severity:*\n{$severityEmoji} {$alert['severity']}"],
                            ['type' => 'mrkdwn', 'text' => "*Rule:*\n{$alert['rule']}"],
                            ['type' => 'mrkdwn', 'text' => "*Event:*\n{$alert['event']}"],
                            ['type' => 'mrkdwn', 'text' => "*Triggered:*\n{$alert['triggered_at']}"],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'text' => ['type' => 'mrkdwn', 'text' => $alert['message']],
                    ],
                ],
            ],
            'discord' => [
                'username' => 'ZeroBoiler Analytics',
                'embeds' => [
                    [
                        'title' => '⚡ Analytics Alert',
                        'description' => $alert['message'],
                        'color' => match ($alert['severity']) {
                            'critical', 'elevated' => 15158332,
                            'warning', 'warn' => 16776960,
                            default => 8311585,
                        },
                        'fields' => [
                            ['name' => 'Severity', 'value' => "{$severityEmoji} {$alert['severity']}", 'inline' => true],
                            ['name' => 'Rule', 'value' => $alert['rule'], 'inline' => true],
                            ['name' => 'Event', 'value' => $alert['event'], 'inline' => true],
                            ['name' => 'Triggered At', 'value' => $alert['triggered_at']],
                        ],
                        'timestamp' => time(),
                    ],
                ],
            ],
            'teams' => [
                '@type' => 'MessageCard',
                '@context' => 'http://schema.org/extensions',
                'themeColor' => match ($alert['severity']) {
                    'critical', 'elevated' => 'FF0000',
                    'warning', 'warn' => 'FFFF00',
                    default => '00FF00',
                },
                'summary' => 'ZeroBoiler Analytics Alert',
                'sections' => [
                    [
                        'activityTitle' => '⚡ Analytics Alert',
                        'activitySubtitle' => $alert['message'],
                        'facts' => [
                            ['name' => 'Severity', 'value' => $alert['severity']],
                            ['name' => 'Rule', 'value' => $alert['rule']],
                            ['name' => 'Event', 'value' => $alert['event']],
                            ['name' => 'Triggered At', 'value' => $alert['triggered_at']],
                        ],
                    ],
                ],
            ],
            'pagerduty' => [
                'routing_key' => '', // Populated from secret
                'event_action' => 'trigger',
                'payload' => [
                    'summary' => $alert['message'],
                    'severity' => match ($alert['severity']) {
                        'critical' => 'critical',
                        'elevated', 'warning', 'warn' => 'warning',
                        default => 'info',
                    },
                    'source' => 'zeroboiler-analytics',
                    'component' => $alert['rule'],
                    'group' => 'analytics',
                    'class' => $alert['event'],
                    'custom_details' => [
                        'triggered_at' => $alert['triggered_at'],
                    ],
                ],
            ],
            default => [
                'source' => 'zeroboiler-analytics',
                'alert' => $alert,
                'formatted_at' => date('c'),
            ],
        };
    }

    /**
     * Build HTTP headers for a webhook request.
     *
     * @param  array{secret: string}  $webhook
     * @return array<string, string>
     */
    private function buildHeaders(array $webhook): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'ZeroBoiler-Analytics/4.4.0',
        ];

        if ($webhook['secret'] !== '') {
            $headers['Authorization'] = 'Bearer ' . $webhook['secret'];
        }

        return $headers;
    }

    /**
     * Check if an alert severity meets the webhook's minimum threshold.
     *
     * Severity levels: critical > elevated > warning > info > debug
     */
    private function meetsSeverityThreshold(string $severity, string $minSeverity): bool
    {
        $levels = [
            'debug' => 0,
            'info' => 1,
            'warning' => 2,
            'warn' => 2,
            'elevated' => 3,
            'critical' => 4,
        ];

        return ($levels[strtolower($severity)] ?? 0) >= ($levels[strtolower($minSeverity)] ?? 0);
    }

    /**
     * Check if an event name matches the webhook's event filter.
     *
     * Empty filter = match all events.
     *
     * @param  string  $event  Event name
     * @param  list<string>  $filter  Event name filter list
     */
    private function matchesEventFilter(string $event, array $filter): bool
    {
        if (count($filter) === 0) {
            return true;
        }

        foreach ($filter as $pattern) {
            if ($pattern === '*') {
                return true;
            }

            if (str_contains($pattern, '*')) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';

                if (preg_match($regex, $event)) {
                    return true;
                }
            } elseif (strtolower($event) === strtolower($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a webhook can be sent (rate limit check).
     */
    private function canSend(string $name): bool
    {
        $lastSent = $this->rateLimits[$name] ?? 0;

        return (time() - $lastSent) >= $this->rateLimitSeconds;
    }

    /**
     * Record a webhook send for rate limiting.
     */
    private function recordRateLimit(string $name): void
    {
        $this->rateLimits[$name] = time();
        $this->cache->put(self::CACHE_PREFIX . 'rate_' . $name, time(), $this->rateLimitSeconds * 2);
    }

    /**
     * Record delivery result for statistics.
     */
    private function recordDelivery(string $name, bool $success): void
    {
        if (! isset($this->deliveryStats[$name])) {
            $this->deliveryStats[$name] = ['sent' => 0, 'failed' => 0];
        }

        if ($success) {
            $this->deliveryStats[$name]['sent']++;
            $this->deliveryStats[$name]['last_sent'] = date('c');
        } else {
            $this->deliveryStats[$name]['failed']++;
        }
    }

    /**
     * Load rate limit state from cache.
     */
    private function loadRateLimitState(): void
    {
        foreach (array_keys($this->webhooks) as $name) {
            $cached = $this->cache->get(self::CACHE_PREFIX . 'rate_' . $name);

            if ($cached !== null) {
                $this->rateLimits[$name] = (int) $cached;
            }
        }
    }

    /**
     * Load delivery statistics from cache.
     */
    private function loadDeliveryStats(): void
    {
        $cached = $this->cache->get(self::CACHE_PREFIX . 'delivery_stats');

        if (is_array($cached)) {
            /** @var array<string, array{sent: int, failed: int, last_sent?: string}> $cached */
            $this->deliveryStats = $cached;
        }
    }

    /**
     * Persist delivery statistics to cache.
     */
    private function persistDeliveryStats(): void
    {
        $this->cache->put(self::CACHE_PREFIX . 'delivery_stats', $this->deliveryStats, 86400); // 24 hours
    }
}
