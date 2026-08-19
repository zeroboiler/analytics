<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tracking;

use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * PostHog server-side event tracker.
 *
 * Sends events to the PostHog ingestion API using the capture endpoint.
 * Supports event properties, user identification, and group properties.
 * Works with both PostHog Cloud and self-hosted PostHog instances.
 *
 * PostHog API docs: https://posthog.com/docs/api/capture
 *
 * Endpoint: POST https://<host>/capture/
 *
 * @since 262.0.0
 */
final class PostHogEventTracker
{
    /** @var string PostHog API key / project API key */
    private readonly string $apiKey;

    /** @var string PostHog host (e.g. 'https://app.posthog.com') */
    private readonly string $host;

    /**
     * @param  string  $apiKey  PostHog project API key
     * @param  string  $host  PostHog host URL (with trailing slash removed)
     */
    public function __construct(string $apiKey, string $host): void
    {
        $this->apiKey = $apiKey;
        $this->host = rtrim($host, '/');
    }

    /**
     * Track a single event via the PostHog capture API.
     *
     * @return bool  True if the request was successful
     */
    public function track(AnalyticsEvent $event): bool
    {
        $posthogName = EventCatalog::posthogNameFor($event->name);

        $payload = $this->buildPayload($event, $posthogName);

        try {
            $success = $this->sendRequest($payload);

            if ($success) {
                Log::debug('ZeroBoiler: PostHog event tracked', [
                    'event' => $posthogName,
                    'distinct_id' => $payload['distinct_id'] ?? 'unknown',
                ]);
            }

            return $success;
        } catch (\Throwable $e) {
            Log::warning('ZeroBoiler: PostHog event tracking failed', [
                'event' => $posthogName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Identify a user in PostHog.
     *
     * Sets user properties that persist across all future events.
     *
     * @param  string  $distinctId  User ID or client ID
     * @param  array<string, mixed>  $properties  User properties to set
     * @return bool  True if the request was successful
     */
    public function identify(string $distinctId, array $properties = []): bool
    {
        $payload = [
            'api_key' => $this->apiKey,
            'event' => '$identify',
            'properties' => [
                '$set' => $properties,
            ],
            'distinct_id' => $distinctId,
            'timestamp' => now()->toIso8601String(),
        ];

        try {
            return $this->sendRequest($payload);
        } catch (\Throwable $e) {
            Log::warning('ZeroBoiler: PostHog identify failed', [
                'distinct_id' => $distinctId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build the PostHog capture API payload.
     *
     * @param  AnalyticsEvent  $event  The analytics event
     * @param  string  $posthogName  PostHog event name
     * @return array<string, mixed>
     */
    private function buildPayload(AnalyticsEvent $event, string $posthogName): array
    {
        $distinctId = $event->userId ?? $event->clientId ?? 'anonymous';

        $payload = [
            'api_key' => $this->apiKey,
            'event' => $posthogName,
            'properties' => $event->params,
            'distinct_id' => (string) $distinctId,
        ];

        // Add server-side timestamp if not present
        $payload['timestamp'] = $event->timestamp?->toIso8601String()
            ?? now()->toIso8601String();

        // Set client IP and user agent if available in params
        if (isset($event->params['$ip'])) {
            $payload['send_instantly'] = true;
        }

        return $payload;
    }

    /**
     * Send the HTTP request to PostHog capture API.
     *
     * @param  array<string, mixed>  $payload
     * @return bool  True if the request was successful
     */
    private function sendRequest(array $payload): bool
    {
        if (! function_exists('curl_init')) {
            return false;
        }

        $url = $this->host . '/capture/';
        $ch = curl_init($url);

        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $response !== false && $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Check if the tracker is properly configured.
     */
    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->host !== '';
    }
}
