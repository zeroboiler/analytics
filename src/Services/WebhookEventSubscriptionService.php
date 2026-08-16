<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Real-time event subscription webhook service.
 *
 * Pushes analytics events to external webhook endpoints (Slack, Teams, Discord,
 * custom) in real-time when trigger events occur. Supports event filtering,
 * payload transformation, retry with backoff, and rate limiting.
 *
 * Webhooks are configured via `zeroboiler.analytics.webhook_subscriptions`
 * and can target different events to different endpoints.
 *
 * Inspired by Stripe Webhooks, Segment Destinations, and PostHog Integrations.
 *
 * @since 23.0.0
 */
final class WebhookEventSubscriptionService
{
    private bool $enabled;

    /** @var array<int, array{url: string, secret?: string, events: list<string>, headers?: array<string, string>, timeout?: int, retries?: int, format?: string, enabled?: bool}> */
    private array $subscriptions;

    private int $defaultTimeout;

    private int $defaultRetries;

    private int $rateLimitPerMinute;

    /** @var array<string, int> Rate limit counter */
    private array $rateCounter = [];

    /** @var int Rate limit window start timestamp */
    private int $rateWindowStart = 0;

    /**
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(ConfigRepository $config): void
    {
        $subConfig = $config->get('zeroboiler.analytics.webhook_subscriptions', []);
        /** @var array{enabled?: bool, subscriptions?: list<array{url: string, secret?: string, events?: list<string>, headers?: array<string, string>, timeout?: int, retries?: int, format?: string, enabled?: bool}>, default_timeout?: int, default_retries?: int, rate_limit_per_minute?: int} $subConfig */
        $this->enabled = (bool) ($subConfig['enabled'] ?? false);
        $this->subscriptions = $subConfig['subscriptions'] ?? [];
        $this->defaultTimeout = (int) ($subConfig['default_timeout'] ?? 5);
        $this->defaultRetries = (int) ($subConfig['default_retries'] ?? 2);
        $this->rateLimitPerMinute = (int) ($subConfig['rate_limit_per_minute'] ?? 60);
    }

    /**
     * Dispatch an event to matching webhook subscriptions.
     *
     * Finds all subscriptions whose `events` list includes the event name
     * (or includes '*' for catch-all) and pushes the event payload.
     *
     * Dispatch is non-blocking and failures are logged but never thrown.
     *
     * @param  AnalyticsEvent  $event  The event to dispatch
     * @return int Number of webhooks that were triggered
     */
    public function dispatch(AnalyticsEvent $event): int
    {
        if (! $this->enabled) {
            return 0;
        }

        if (! $this->checkRateLimit()) {
            Log::warning('ZeroBoiler Analytics: webhook subscription rate limit exceeded');

            return 0;
        }

        $matchingSubs = $this->findMatchingSubscriptions($event->name);
        $triggered = 0;

        foreach ($matchingSubs as $subscription) {
            try {
                $this->sendWebhook($subscription, $event);
                $triggered++;
            } catch (\Throwable $e) {
                Log::warning('ZeroBoiler Analytics: webhook subscription dispatch failed', [
                    'url' => $subscription['url'],
                    'event' => $event->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $triggered;
    }

    /**
     * Test a webhook subscription by sending a ping event.
     *
     * Useful for verifying webhook configuration before going live.
     *
     * @param  string  $url  Webhook URL to test
     * @return array{success: bool, status: int|null, error: string|null}
     */
    public function test(string $url): array
    {
        try {
            $response = Http::timeout($this->defaultTimeout)
                ->post($url, [
                    'type' => 'webhook_test',
                    'event' => 'ping',
                    'timestamp' => now()->toIso8601String(),
                    'source' => 'zeroboiler-analytics',
                ]);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'error' => $response->successful() ? null : $response->body(),
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'status' => null,
                'error' => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get all configured subscriptions.
     *
     * @return array<int, array{url: string, events: list<string>, format: string, enabled: bool}>
     */
    public function getSubscriptions(): array
    {
        return array_map(
            fn (array $sub): array => [
                'url' => $sub['url'],
                'events' => $sub['events'] ?? ['*'],
                'format' => $sub['format'] ?? 'json',
                'enabled' => $sub['enabled'] ?? true,
            ],
            $this->subscriptions,
        );
    }

    /**
     * Get subscriptions that match a specific event name.
     *
     * @return array<int, array{url: string, events: list<string>}>
     */
    public function getSubscriptionsForEvent(string $eventName): array
    {
        return $this->findMatchingSubscriptions($eventName);
    }

    /**
     * Check if webhook subscriptions are enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the total number of configured subscriptions.
     */
    public function subscriptionCount(): int
    {
        return count($this->subscriptions);
    }

    /**
     * Find subscriptions matching an event name.
     *
     * @param  string  $eventName  Event name to match
     * @return array<int, array{url: string, secret?: string, headers?: array<string, string>, timeout?: int, retries?: int, format?: string}>
     */
    private function findMatchingSubscriptions(string $eventName): array
    {
        $matching = [];

        foreach ($this->subscriptions as $subscription) {
            $enabled = $subscription['enabled'] ?? true;

            if (! $enabled) {
                continue;
            }

            $events = $subscription['events'] ?? ['*'];

            if (in_array('*', $events, true) || in_array($eventName, $events, true)) {
                $matching[] = $subscription;
            }
        }

        return $matching;
    }

    /**
     * Send an event payload to a webhook endpoint.
     *
     * @param  array{url: string, secret?: string, headers?: array<string, string>, timeout?: int, retries?: int, format?: string}  $subscription
     * @param  AnalyticsEvent  $event  The event to send
     * @throws \Throwable On unrecoverable failure after retries
     */
    private function sendWebhook(array $subscription, AnalyticsEvent $event): void
    {
        $url = $subscription['url'];
        $secret = $subscription['secret'] ?? null;
        $customHeaders = $subscription['headers'] ?? [];
        $timeout = (int) ($subscription['timeout'] ?? $this->defaultTimeout);
        $retries = (int) ($subscription['retries'] ?? $this->defaultRetries);
        $format = (string) ($subscription['format'] ?? 'json');

        $payload = $this->buildPayload($event, $format);
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'User-Agent' => 'ZeroBoiler-Analytics/23.0.0',
            'X-ZB-Event' => $event->name,
            'X-ZB-Timestamp' => (string) now()->timestamp,
        ], $customHeaders);

        // HMAC signature if secret is configured
        if ($secret !== null && $secret !== '') {
            $signature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret);
            $headers['X-ZB-Signature'] = 'sha256=' . $signature;
        }

        $attempt = 0;

        while ($attempt <= $retries) {
            try {
                $response = Http::timeout($timeout)
                    ->withHeaders($headers)
                    ->post($url, $payload);

                if ($response->successful()) {
                    Log::debug('ZeroBoiler Analytics: webhook subscription delivered', [
                        'url' => $url,
                        'event' => $event->name,
                        'status' => $response->status(),
                    ]);

                    return;
                }

                // Non-success status, retry if attempts remain
                $attempt++;

                if ($attempt > $retries) {
                    Log::warning('ZeroBoiler Analytics: webhook subscription failed after retries', [
                        'url' => $url,
                        'event' => $event->name,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return;
                }
            } catch (ConnectionException $e) {
                $attempt++;

                if ($attempt > $retries) {
                    throw $e;
                }

                // Exponential backoff: 100ms, 200ms, 400ms...
                usleep((int) (100_000 * pow(2, $attempt - 1)));
            }
        }
    }

    /**
     * Build the webhook payload for an event.
     *
     * @param  AnalyticsEvent  $event  The event
     * @param  string  $format  Payload format (json, slack, teams, discord)
     * @return array<string, mixed> Payload data
     */
    private function buildPayload(AnalyticsEvent $event, string $format): array
    {
        $basePayload = [
            'event' => $event->name,
            'params' => $event->params,
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
            'timestamp' => $event->timestamp?->format(\DateTimeInterface::ATOM) ?? now()->toIso8601String(),
            'source' => 'zeroboiler-analytics',
            'version' => '23.0.0',
        ];

        return match ($format) {
            'slack' => $this->formatSlack($event, $basePayload),
            'teams' => $this->formatTeams($event, $basePayload),
            'discord' => $this->formatDiscord($event, $basePayload),
            default => $basePayload,
        };
    }

    /**
     * Format payload for Slack incoming webhook.
     *
     * @param  AnalyticsEvent  $event  The event
     * @param  array<string, mixed>  $base  Base payload
     * @return array<string, mixed> Slack-formatted payload
     */
    private function formatSlack(AnalyticsEvent $event, array $base): array
    {
        return [
            'text' => "📊 Analytics Event: {$event->name}",
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*Analytics Event: `{$event->name}`*",
                    ],
                ],
                [
                    'type' => 'section',
                    'fields' => array_values(array_map(
                        fn (string $key, mixed $value): array => [
                            'type' => 'mrkdwn',
                            'text' => "*{$key}:*\n" . (string) $value,
                        ],
                        array_keys($base),
                        $base,
                    )),
                ],
            ],
        ];
    }

    /**
     * Format payload for Microsoft Teams incoming webhook.
     *
     * @param  AnalyticsEvent  $event  The event
     * @param  array<string, mixed>  $base  Base payload
     * @return array<string, mixed> Teams-formatted payload
     */
    private function formatTeams(AnalyticsEvent $event, array $base): array
    {
        $facts = [];

        foreach ($base as $key => $value) {
            $facts[] = [
                'name' => $key,
                'value' => is_array($value) ? json_encode($value) : (string) $value,
            ];
        }

        return [
            '@type' => 'MessageCard',
            '@context' => 'http://schema.org/extensions',
            'themeColor' => '0078D7',
            'summary' => "Analytics Event: {$event->name}",
            'sections' => [
                [
                    'activityTitle' => "📊 {$event->name}",
                    'activitySubtitle' => 'ZeroBoiler Analytics',
                    'facts' => $facts,
                ],
            ],
        ];
    }

    /**
     * Format payload for Discord webhook.
     *
     * @param  AnalyticsEvent  $event  The event
     * @param  array<string, mixed>  $base  Base payload
     * @return array<string, mixed> Discord-formatted payload
     */
    private function formatDiscord(AnalyticsEvent $event, array $base): array
    {
        $fields = [];

        foreach ($base as $key => $value) {
            $fields[] = [
                'name' => $key,
                'value' => is_array($value)
                    ? json_encode($value, JSON_UNESCAPED_SLASHES)
                    : (string) $value,
                'inline' => true,
            ];
        }

        return [
            'username' => 'ZeroBoiler Analytics',
            'embeds' => [
                [
                    'title' => "📊 {$event->name}",
                    'color' => 5814783, // Blue
                    'fields' => $fields,
                    'footer' => [
                        'text' => 'ZeroBoiler Analytics v23.0.0',
                    ],
                    'timestamp' => now()->toIso8601String(),
                ],
            ],
        ];
    }

    /**
     * Check rate limit before dispatching.
     *
     * Uses a sliding window counter approach.
     */
    private function checkRateLimit(): bool
    {
        $now = time();

        // Reset window if expired
        if ($now - $this->rateWindowStart >= 60) {
            $this->rateCounter = [];
            $this->rateWindowStart = $now;
        }

        $totalCalls = array_sum($this->rateCounter);

        if ($totalCalls >= $this->rateLimitPerMinute) {
            return false;
        }

        // Increment counter for this second
        $secondKey = (string) $now;
        $this->rateCounter[$secondKey] = ($this->rateCounter[$secondKey] ?? 0) + 1;

        return true;
    }
}
