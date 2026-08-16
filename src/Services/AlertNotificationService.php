<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Alert notification dispatcher — sends analytics alerts to external channels.
 *
 * When EventAlertRulesService triggers an alert, this service dispatches it
 * to configured notification channels: webhook (Slack, Discord, generic HTTP),
 * email (via Laravel notifications), or log channel.
 *
 * Supports per-severity channel routing (e.g., critical → Slack + email,
 * warning → Slack only, info → log only).
 *
 * Includes rate limiting to prevent notification floods and retry with
 * exponential backoff for failed deliveries.
 *
 * Configuration is read from `zeroboiler.analytics.alert_notifications`.
 *
 * @see \ZeroBoiler\Analytics\Services\EventAlertRulesService
 *
 * @since v7.3.0
 */
final class AlertNotificationService
{
    /** @var array<string, mixed> */
    private readonly array $config;

    private readonly bool $enabled;

    private readonly CacheRepository $cache;

    private readonly int $rateLimitWindow;

    private readonly int $rateLimitMax;

    private readonly int $maxRetries;

    private readonly float $retryBaseDelay;

    /** @var array<string, int> Channel → last sent timestamp (cooldown tracking) */
    private array $channelCooldowns = [];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->cache = $cache;

        $notifConfig = $config->get('zeroboiler.analytics.alert_notifications', []);
        /** @var array{enabled?: bool, rate_limit_window?: int, rate_limit_max?: int, max_retries?: int, retry_base_delay?: float, channels?: array<string, mixed>, severity_routing?: array<string, list<string>>} $notifConfig */

        $this->config = $notifConfig;
        $this->enabled = (bool) ($notifConfig['enabled'] ?? false);
        $this->rateLimitWindow = (int) ($notifConfig['rate_limit_window'] ?? 60);
        $this->rateLimitMax = (int) ($notifConfig['rate_limit_max'] ?? 20);
        $this->maxRetries = (int) ($notifConfig['max_retries'] ?? 2);
        $this->retryBaseDelay = (float) ($notifConfig['retry_base_delay'] ?? 1.0);

        $this->loadChannelCooldowns();
    }

    /**
     * Dispatch an alert to the appropriate notification channels.
     *
     * Routes based on severity mapping. Respects rate limits and cooldowns.
     * Failed deliveries are retried with exponential backoff.
     *
     * @param  array{rule: string, event: string, severity: string, message: string, triggered_at: string, value: float|null, threshold: float|null}  $alert
     * @return array{dispatched: list<string>, failed: list<string>, skipped: list<string>, total_channels: int}
     */
    public function notify(array $alert): array
    {
        if (! $this->enabled) {
            return ['dispatched' => [], 'failed' => [], 'skipped' => [], 'total_channels' => 0];
        }

        // Check global rate limit
        if (! $this->checkRateLimit()) {
            return ['dispatched' => [], 'failed' => [], 'skipped' => ['rate_limited'], 'total_channels' => 0];
        }

        $severity = $alert['severity'] ?? 'warning';
        $channels = $this->resolveChannels($severity);
        $dispatched = [];
        $failed = [];
        $skipped = [];

        foreach ($channels as $channelName) {
            if ($this->isChannelInCooldown($channelName)) {
                $skipped[] = $channelName;

                continue;
            }

            $result = $this->sendToChannel($channelName, $alert);

            if ($result) {
                $dispatched[] = $channelName;
                $this->markChannelSent($channelName);
            } else {
                $failed[] = $channelName;
            }
        }

        return [
            'dispatched' => $dispatched,
            'failed' => $failed,
            'skipped' => $skipped,
            'total_channels' => count($channels),
        ];
    }

    /**
     * Send an alert to a specific channel.
     *
     * @param  string  $channelName
     * @param  array{rule: string, event: string, severity: string, message: string, triggered_at: string, value: float|null, threshold: float|null}  $alert
     */
    public function sendToChannel(string $channelName, array $alert): bool
    {
        $channelConfig = $this->getChannelConfig($channelName);

        if ($channelConfig === null || ($channelConfig['enabled'] ?? true) === false) {
            return false;
        }

        $type = $channelConfig['type'] ?? 'webhook';

        return match ($type) {
            'webhook', 'slack', 'discord', 'teams' => $this->sendWebhook($channelName, $alert, $channelConfig),
            'log' => $this->sendLog($channelName, $alert),
            default => false,
        };
    }

    /**
     * Send a test notification to all configured channels.
     *
     * Useful for verifying channel configuration during setup.
     *
     * @return array{results: array<string, bool>, channels: list<string>}
     */
    public function testChannels(): array
    {
        $testAlert = [
            'rule' => '_test',
            'event' => '_test',
            'severity' => 'info',
            'message' => 'ZeroBoiler Analytics test notification — if you see this, your alert channel is working.',
            'triggered_at' => date('c'),
            'value' => null,
            'threshold' => null,
        ];

        $allChannels = $this->getAllChannelNames();
        $results = [];

        foreach ($allChannels as $channelName) {
            $results[$channelName] = $this->sendToChannel($channelName, $testAlert);
        }

        return [
            'results' => $results,
            'channels' => $allChannels,
        ];
    }

    /**
     * Get the notification summary for admin dashboards.
     *
     * @return array{enabled: bool, channels: int, severity_routing: array<string, list<string>>, rate_limit: array{window: int, max: int, current: int}}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'channels' => count($this->getAllChannelNames()),
            'severity_routing' => $this->config['severity_routing'] ?? [
                'critical' => ['slack', 'webhook'],
                'elevated' => ['slack'],
                'warning' => ['log'],
                'info' => ['log'],
            ],
            'rate_limit' => [
                'window' => $this->rateLimitWindow,
                'max' => $this->rateLimitMax,
                'current' => $this->getCurrentRateLimitCount(),
            ],
        ];
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get all configured channel names.
     *
     * @return list<string>
     */
    public function getAllChannelNames(): array
    {
        $channels = $this->config['channels'] ?? [];

        return array_keys(array_filter(
            $channels,
            fn (array $ch): bool => ($ch['enabled'] ?? true) === true,
        ));
    }

    /**
     * Get the config for a specific channel.
     *
     * @return array{type: string, url?: string, secret?: string, timeout?: int, enabled?: bool}|null
     */
    private function getChannelConfig(string $channelName): ?array
    {
        $channels = $this->config['channels'] ?? [];

        $config = $channels[$channelName] ?? null;

        if (! is_array($config)) {
            return null;
        }

        /** @var array{type?: string, url?: string, secret?: string, timeout?: int, enabled?: bool} $config */
        return $config;
    }

    /**
     * Resolve which channels should receive an alert based on severity.
     *
     * Falls back to ['log'] if no routing is configured for the severity.
     *
     * @param  string  $severity
     * @return list<string>
     */
    private function resolveChannels(string $severity): array
    {
        $routing = $this->config['severity_routing'] ?? [];

        $channels = $routing[$severity] ?? $routing['default'] ?? ['log'];

        // Filter to only channels that are actually configured
        $configured = $this->config['channels'] ?? [];

        return array_values(array_filter(
            $channels,
            fn (string $ch): bool => isset($configured[$ch]),
        ));
    }

    /**
     * Send an alert via webhook (HTTP POST).
     *
     * Supports Slack, Discord, Microsoft Teams, and generic webhook formats.
     * Payload format is auto-detected based on channel type.
     *
     * @param  string  $channelName
     * @param  array{rule: string, event: string, severity: string, message: string, triggered_at: string, value: float|null, threshold: float|null}  $alert
     * @param  array{type: string, url: string, secret?: string, timeout?: int}  $channelConfig
     */
    private function sendWebhook(string $channelName, array $alert, array $channelConfig): bool
    {
        $url = $channelConfig['url'] ?? '';

        if ($url === '') {
            return false;
        }

        $type = $channelConfig['type'] ?? 'webhook';
        $timeout = (int) ($channelConfig['timeout'] ?? 5);
        $secret = $channelConfig['secret'] ?? '';

        $payload = $this->buildWebhookPayload($type, $alert);
        $headers = $this->buildWebhookHeaders($type, $secret, $payload);

        $attempt = 0;

        while ($attempt <= $this->maxRetries) {
            try {
                $response = Http::timeout($timeout)
                    ->withHeaders($headers)
                    ->post($url, $payload);

                if ($response->successful()) {
                    return true;
                }

                // Non-retryable status codes
                if ($response->status() >= 400 && $response->status() < 500) {
                    try {
                        Log::warning('AlertNotificationService: non-retryable webhook error', [
                            'channel' => $channelName,
                            'status' => $response->status(),
                            'alert' => $alert['rule'] ?? null,
                        ]);
                    } catch (\Throwable) {
                        // Log may not be available
                    }

                    return false;
                }

                // Server error — retry with backoff
                $attempt++;
                if ($attempt <= $this->maxRetries) {
                    $delay = $this->retryBaseDelay * (2 ** ($attempt - 1));
                    usleep((int) ($delay * 1_000_000));
                }
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt > $this->maxRetries) {
                    try {
                        Log::warning('AlertNotificationService: webhook delivery failed', [
                            'channel' => $channelName,
                            'error' => $e->getMessage(),
                            'alert' => $alert['rule'] ?? null,
                        ]);
                    } catch (\Throwable) {
                        // Log may not be available
                    }

                    return false;
                }

                $delay = $this->retryBaseDelay * (2 ** ($attempt - 1));
                usleep((int) ($delay * 1_000_000));
            }
        }

        return false;
    }

    /**
     * Build the webhook payload based on channel type.
     *
     * @param  string  $type  Channel type (slack, discord, teams, webhook)
     * @param  array{rule: string, event: string, severity: string, message: string, triggered_at: string, value: float|null, threshold: float|null}  $alert
     * @return array<string, mixed>
     */
    private function buildWebhookPayload(string $type, array $alert): array
    {
        $emoji = match ($alert['severity']) {
            'critical' => '🔴',
            'elevated' => '🟠',
            'warning' => '🟡',
            default => '🔵',
        };

        $severityLabel = strtoupper($alert['severity']);

        return match ($type) {
            'slack' => [
                'text' => "{$emoji} [{$severityLabel}] {$alert['message']}",
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => [
                            'text' => "{$emoji} *ZeroBoiler Analytics Alert*",
                            'type' => 'mrkdwn',
                        ],
                    ],
                    [
                        'type' => 'section',
                        'fields' => [
                            ['title' => 'Rule', 'value' => $alert['rule'], 'short' => true],
                            ['title' => 'Severity', 'value' => $alert['severity'], 'short' => true],
                            ['title' => 'Event', 'value' => $alert['event'], 'short' => true],
                            ['title' => 'Value', 'value' => $alert['value'] !== null ? (string) $alert['value'] : 'N/A', 'short' => true],
                        ],
                    ],
                    [
                        'type' => 'section',
                        'text' => [
                            'text' => $alert['message'],
                            'type' => 'mrkdwn',
                        ],
                    ],
                    [
                        'type' => 'context',
                        'elements' => [
                            [
                                'text' => 'Triggered at ' . $alert['triggered_at'],
                                'type' => 'mrkdwn',
                            ],
                        ],
                    ],
                ],
            ],
            'discord' => [
                'content' => "{$emoji} **[{$severityLabel}]** {$alert['message']}",
                'embeds' => [
                    [
                        'title' => 'ZeroBoiler Analytics Alert',
                        'color' => match ($alert['severity']) {
                            'critical' => 15158588,
                            'elevated' => 15196608,
                            'warning' => 15185536,
                            default => 4886754,
                        },
                        'fields' => [
                            ['name' => 'Rule', 'value' => $alert['rule'], 'inline' => true],
                            ['name' => 'Severity', 'value' => $alert['severity'], 'inline' => true],
                            ['name' => 'Event', 'value' => $alert['event'], 'inline' => true],
                            ['name' => 'Value', 'value' => $alert['value'] !== null ? (string) $alert['value'] : 'N/A', 'inline' => true],
                            ['name' => 'Threshold', 'value' => $alert['threshold'] !== null ? (string) $alert['threshold'] : 'N/A', 'inline' => true],
                        ],
                        'footer' => [
                            'text' => 'Triggered at ' . $alert['triggered_at'],
                        ],
                    ],
                ],
            ],
            'teams' => [
                'type' => 'message',
                'attachments' => [
                    [
                        'contentType' => 'application/vnd.microsoft.card.adaptive',
                        'contentUrl' => null,
                        'content' => [
                            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                            'type' => 'AdaptiveCard',
                            'version' => '1.4',
                            'body' => [
                                [
                                    'type' => 'TextBlock',
                                    'text' => "{$emoji} {$alert['severity']} Alert",
                                    'weight' => 'Bolder',
                                    'size' => 'Large',
                                ],
                                [
                                    'type' => 'FactSet',
                                    'facts' => [
                                        ['title' => 'Rule', 'value' => $alert['rule']],
                                        ['title' => 'Event', 'value' => $alert['event']],
                                        ['title' => 'Value', 'value' => $alert['value'] !== null ? (string) $alert['value'] : 'N/A'],
                                        ['title' => 'Threshold', 'value' => $alert['threshold'] !== null ? (string) $alert['threshold'] : 'N/A'],
                                    ],
                                ],
                                [
                                    'type' => 'TextBlock',
                                    'text' => $alert['message'],
                                    'wrap' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            default => [
                'alert' => $alert,
                'source' => 'zeroboiler-analytics',
                'version' => '7.3.0',
                'timestamp' => $alert['triggered_at'],
            ],
        };
    }

    /**
     * Build webhook headers including optional HMAC signature.
     *
     * @param  string  $type
     * @param  string  $secret
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function buildWebhookHeaders(string $type, string $secret, array $payload): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'ZeroBoiler-Analytics/7.3.0',
        ];

        if ($secret !== '') {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $signature = hash_hmac('sha256', $body, $secret);
            $headers['X-ZB-Signature'] = "sha256={$signature}";
        }

        return $headers;
    }

    /**
     * Send an alert to the log channel.
     *
     * @param  string  $channelName
     * @param  array{rule: string, event: string, severity: string, message: string, triggered_at: string, value: float|null, threshold: float|null}  $alert
     */
    private function sendLog(string $channelName, array $alert): bool
    {
        try {
            $logLevel = match ($alert['severity']) {
                'critical' => 'error',
                'elevated' => 'warning',
                default => 'info',
            };

            Log::{$logLevel}("[Analytics Alert] {$alert['message']}", [
                'channel' => $channelName,
                'rule' => $alert['rule'],
                'event' => $alert['event'],
                'severity' => $alert['severity'],
                'value' => $alert['value'],
                'threshold' => $alert['threshold'],
                'triggered_at' => $alert['triggered_at'],
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check the global rate limit for notification dispatching.
     */
    private function checkRateLimit(): bool
    {
        $cacheKey = 'zb_alert_notif_rate';
        $current = (int) ($this->cache->get($cacheKey, 0));

        if ($current >= $this->rateLimitMax) {
            return false;
        }

        $this->cache->put($cacheKey, $current + 1, $this->rateLimitWindow);

        return true;
    }

    /**
     * Get the current rate limit counter value.
     */
    private function getCurrentRateLimitCount(): int
    {
        return (int) ($this->cache->get('zb_alert_notif_rate', 0));
    }

    /**
     * Check if a channel is in cooldown.
     */
    private function isChannelInCooldown(string $channelName): bool
    {
        $lastSent = $this->channelCooldowns[$channelName] ?? 0;
        $cooldownSeconds = (int) ($this->config['channel_cooldown'] ?? 30);

        return (time() - $lastSent) < $cooldownSeconds;
    }

    /**
     * Mark a channel as having just sent a notification.
     */
    private function markChannelSent(string $channelName): void
    {
        $this->channelCooldowns[$channelName] = time();
        $this->persistChannelCooldowns();
    }

    /**
     * Load channel cooldown state from cache.
     */
    private function loadChannelCooldowns(): void
    {
        try {
            $cached = $this->cache->get('zb_alert_notif_cooldowns', []);

            if (is_array($cached)) {
                $this->channelCooldowns = $cached;
            }
        } catch (\Throwable) {
            $this->channelCooldowns = [];
        }
    }

    /**
     * Persist channel cooldown state to cache.
     */
    private function persistChannelCooldowns(): void
    {
        try {
            $this->cache->put('zb_alert_notif_cooldowns', $this->channelCooldowns, 120);
        } catch (\Throwable) {
            // Cache may not be available
        }
    }
}
