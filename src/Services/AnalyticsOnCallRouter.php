<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Routes analytics incidents to on-call responders based on escalation policies.
 *
 * Implements a tiered escalation model inspired by PagerDuty:
 * - Level 0: Auto-remediation (no human notification)
 * - Level 1: Notify primary on-call (P3/P4 incidents)
 * - Level 2: Escalate to secondary on-call (P2 after timeout)
 * - Level 3: Page engineering manager (P1 or P2 after 2nd timeout)
 *
 * Supports configurable escalation windows, notification channels
 * (log, webhook, email, slack), and scheduling rotations.
 *
 * This service is notification-agnostic — it determines WHO to notify
 * and WHEN, but delegates actual delivery to registered notifiers.
 *
 * Config: `zeroboiler.analytics.on_call`
 *
 * @since 262.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsIncidentService
 */
final class AnalyticsOnCallRouter
{
    private const ESCALATION_KEY = 'zb_oncall_escalation_';

    private const SCHEDULE_KEY = 'zb_oncall_schedule';

    private CacheRepository $cache;

    private bool $enabled;

    /** @var array{level_1_timeout: int, level_2_timeout: int, channels: list<string>, webhook_url: string|null, slack_webhook_url: string|null, email_recipients: list<string>, rotation_enabled: bool, rotation_minutes: int} */
    private array $settings;

    /** @var array<string, list<string>> Routing rules: severity => [notifier class names] */
    private array $routingRules;

    /** @var list<callable(array<string, mixed>): void> Runtime-registered notifiers */
    private array $notifiers = [];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $onCallConfig = $config->get('zeroboiler.analytics.on_call', []);
        /** @var array{enabled?: bool, level_1_timeout?: int, level_2_timeout?: int, channels?: list<string>, webhook_url?: string|null, slack_webhook_url?: string|null, email_recipients?: list<string>, routing?: array<string, list<string>>, rotation_enabled?: bool, rotation_minutes?: int} $onCallConfig */

        $this->enabled = (bool) ($onCallConfig['enabled'] ?? false);
        $this->settings = [
            'level_1_timeout' => (int) ($onCallConfig['level_1_timeout'] ?? 300), // 5 minutes
            'level_2_timeout' => (int) ($onCallConfig['level_2_timeout'] ?? 900), // 15 minutes
            'channels' => (array) ($onCallConfig['channels'] ?? ['log']),
            'webhook_url' => $onCallConfig['webhook_url'] ?? null,
            'slack_webhook_url' => $onCallConfig['slack_webhook_url'] ?? null,
            'email_recipients' => (array) ($onCallConfig['email_recipients'] ?? []),
            'rotation_enabled' => (bool) ($onCallConfig['rotation_enabled'] ?? false),
            'rotation_minutes' => (int) ($onCallConfig['rotation_minutes'] ?? 10080), // 1 week
        ];

        $this->routingRules = (array) ($onCallConfig['routing'] ?? [
            'P1' => ['log', 'webhook', 'slack'],
            'P2' => ['log', 'webhook'],
            'P3' => ['log'],
            'P4' => ['log'],
        ]);
    }

    /**
     * Check if on-call routing is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Route an incident to the appropriate on-call responder.
     *
     * Determines escalation level based on incident severity and time open,
     * then dispatches notifications through configured channels.
     *
     * @param  array{id: string, type: string, provider: string, severity: string, status: string, description: string, created_at: int}  $incident
     * @return array{routed: bool, escalation_level: int, channels: list<string>, notified: list<string>}
     */
    public function routeIncident(array $incident): array
    {
        if (! $this->enabled) {
            return ['routed' => false, 'escalation_level' => 0, 'channels' => [], 'notified' => []];
        }

        $severity = $incident['severity'];
        $timeOpen = time() - $incident['created_at'];
        $escalationLevel = $this->computeEscalationLevel($severity, $timeOpen);

        $cacheKey = self::ESCALATION_KEY . $incident['id'] . '_' . $escalationLevel;
        $alreadyNotified = $this->cache->get($cacheKey);

        if ($alreadyNotified === true) {
            return ['routed' => true, 'escalation_level' => $escalationLevel, 'channels' => [], 'notified' => []];
        }

        $channels = $this->routingRules[$severity] ?? ['log'];
        $notified = [];

        foreach ($channels as $channel) {
            $success = $this->notify($channel, $incident, $escalationLevel);
            if ($success) {
                $notified[] = $channel;
            }
        }

        $ttl = max(3600, $timeOpen + 3600);
        $this->cache->put($cacheKey, true, $ttl);

        return [
            'routed' => true,
            'escalation_level' => $escalationLevel,
            'channels' => $channels,
            'notified' => $notified,
        ];
    }

    /**
     * Register a custom notifier callback.
     *
     * The callback receives the incident array and should return
     * void. Exceptions are caught and logged.
     *
     * @param  callable(array<string, mixed>): void  $notifier
     */
    public function registerNotifier(callable $notifier): void
    {
        $this->notifiers[] = $notifier;
    }

    /**
     * Get the current on-call schedule/roster.
     *
 * @return array{enabled: bool, rotation_minutes: int, channels: list<string>, routing: array<string, list<string>>, level_1_timeout: int, level_2_timeout: int}
     */
    public function getSchedule(): array
    {
        return [
            'enabled' => $this->settings['rotation_enabled'],
            'rotation_minutes' => $this->settings['rotation_minutes'],
            'channels' => $this->settings['channels'],
            'routing' => $this->routingRules,
            'level_1_timeout' => $this->settings['level_1_timeout'],
            'level_2_timeout' => $this->settings['level_2_timeout'],
        ];
    }

    /**
     * Get the escalation level for a severity and time-open.
     *
     * - Level 0: Auto-remediation (first N seconds for P3/P4)
     * - Level 1: Primary on-call (after level_1_timeout for P1/P2, or immediately for P1)
     * - Level 2: Secondary on-call (after level_2_timeout)
     * - Level 3: Manager escalation (P1 after extended time)
     */
    private function computeEscalationLevel(string $severity, int $timeOpen): int
    {
        if ($severity === 'P1') {
            if ($timeOpen < 60) {
                return 1; // Page immediately
            }
            if ($timeOpen < $this->settings['level_2_timeout']) {
                return 1;
            }

            return 3; // Escalate to manager
        }

        if ($severity === 'P2') {
            if ($timeOpen < $this->settings['level_1_timeout']) {
                return 0; // Auto-remediation window
            }

            return $timeOpen < $this->settings['level_2_timeout'] ? 1 : 2;
        }

        // P3/P4: auto-remediate first, then notify
        if ($timeOpen < $this->settings['level_1_timeout'] * 2) {
            return 0;
        }

        return 1;
    }

    /**
     * Send a notification through a specific channel.
     *
     * @param  string  $channel  Channel name (log, webhook, slack, email)
     * @param  array<string, mixed>  $incident  Incident data
     * @param  int  $escalationLevel  Current escalation level
     * @return bool  Whether notification was sent successfully
     */
    private function notify(string $channel, array $incident, int $escalationLevel): bool
    {
        try {
            return match ($channel) {
                'log' => $this->notifyLog($incident, $escalationLevel),
                'webhook' => $this->notifyWebhook($incident, $escalationLevel),
                'slack' => $this->notifySlack($incident, $escalationLevel),
                'email' => $this->notifyEmail($incident, $escalationLevel),
                default => $this->notifyCustom($channel, $incident, $escalationLevel),
            };
        } catch (\Throwable $e) {
            Log::error("[ZeroBoiler] On-call notification failed on {$channel}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Log notification (always available).
     */
    private function notifyLog(array $incident, int $level): bool
    {
        $method = $level >= 2 ? 'error' : 'warning';
        Log::$method(
            "[ZeroBoiler] Incident {$incident['id']} (L{$level}): "
            . "{$incident['severity']} {$incident['type']} on {$incident['provider']} — "
            . $incident['description'],
        );

        return true;
    }

    /**
     * Webhook notification.
     */
    private function notifyWebhook(array $incident, int $level): bool
    {
        $url = $this->settings['webhook_url'];
        if (! is_string($url) || $url === '') {
            return false;
        }

        $payload = json_encode([
            'incident_id' => $incident['id'],
            'severity' => $incident['severity'],
            'type' => $incident['type'],
            'provider' => $incident['provider'],
            'description' => $incident['description'],
            'escalation_level' => $level,
            'timestamp' => time(),
            'source' => 'zeroboiler-analytics',
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $result = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $result !== false && $statusCode >= 200 && $statusCode < 300;
    }

    /**
     * Slack webhook notification.
     */
    private function notifySlack(array $incident, int $level): bool
    {
        $url = $this->settings['slack_webhook_url'];
        if (! is_string($url) || $url === '') {
            return false;
        }

        $emoji = match ($incident['severity']) {
            'P1' => ':rotating_light:',
            'P2' => ':warning:',
            'P3' => ':yellow_circle:',
            'P4' => ':white_circle:',
            default => ':grey_question:',
        };

        $payload = json_encode([
            'text' => sprintf(
                '%s *[%s] Incident %s*: %s on `%s` (L%d)\n>%s',
                $emoji,
                $incident['severity'],
                $incident['id'],
                $incident['type'],
                $incident['provider'],
                $level,
                $incident['description'],
            ),
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $result = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $result !== false && $statusCode >= 200 && $statusCode < 300;
    }

    /**
     * Email notification (placeholder — delegates to Laravel notification system).
     */
    private function notifyEmail(array $incident, int $level): bool
    {
        $recipients = $this->settings['email_recipients'];
        if ($recipients === []) {
            return false;
        }

        Log::info(
            "[ZeroBoiler] Email notification for incident {$incident['id']} "
            . "would be sent to: " . implode(', ', $recipients),
            ['incident' => $incident, 'escalation_level' => $level],
        );

        return true;
    }

    /**
     * Custom channel notification via registered notifiers.
     */
    private function notifyCustom(string $channel, array $incident, int $level): bool
    {
        foreach ($this->notifiers as $notifier) {
            $notifier(array_merge($incident, ['channel' => $channel, 'escalation_level' => $level]));
        }

        return true;
    }
}
