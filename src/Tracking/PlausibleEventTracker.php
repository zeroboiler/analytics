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
 * Plausible Analytics server-side event tracker.
 *
 * Sends events to the Plausible custom event API endpoint.
 * Supports custom properties, user agent forwarding, and referrer
 * attribution for accurate server-side tracking.
 *
 * Plausible API docs: https://plausible.io/docs/custom-event-goals
 *
 * Endpoint: POST https://plausible.io/api/event
 *
 * @since 262.0.0
 */
final class PlausibleEventTracker
{
    private const DEFAULT_API_URL = 'https://plausible.io/api/event';

    public function __construct(
        private readonly string $domain,
        private readonly string $apiKey = '',
        private readonly string $apiUrl = self::DEFAULT_API_URL,
    ) {}

    /**
     * Track a single event via the Plausible custom event API.
     *
     * @return bool  True if the request was successful
     */
    public function track(AnalyticsEvent $event): bool
    {
        $plausibleName = EventCatalog::plausibleNameFor($event->name);

        // Skip events without a Plausible mapping
        if ($plausibleName === null) {
            return false;
        }

        $payload = $this->buildPayload($event, $plausibleName);

        try {
            $response = $this->sendRequest($payload);

            if ($response === false) {
                return false;
            }

            Log::debug('ZeroBoiler: Plausible event tracked', [
                'event' => $plausibleName,
                'domain' => $this->domain,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('ZeroBoiler: Plausible event tracking failed', [
                'event' => $plausibleName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build the Plausible API payload.
     *
     * @param  AnalyticsEvent  $event  The analytics event
     * @param  string  $plausibleName  Plausible event name
     * @return array<string, mixed>
     */
    private function buildPayload(AnalyticsEvent $event, string $plausibleName): array
    {
        $payload = [
            'domain' => $this->domain,
            'name' => $plausibleName,
        ];

        // Add URL from params or use current
        if (isset($event->params['page_url'])) {
            $payload['url'] = (string) $event->params['page_url'];
        } elseif (isset($event->params['url'])) {
            $payload['url'] = (string) $event->params['url'];
        }

        // Add referrer
        if (isset($event->params['referrer'])) {
            $payload['referrer'] = (string) $event->params['referrer'];
        }

        // Plausible custom properties (max 100 chars each)
        $props = array_filter(
            $event->params,
            fn (string $key): bool => ! in_array($key, ['url', 'page_url', 'referrer', 'user_agent', 'client_id', 'user_id'], true),
            ARRAY_FILTER_USE_KEY,
        );

        if ($props !== []) {
            $payload['props'] = array_map(static function (mixed $value): string {
                $str = is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);

                return mb_strlen($str) > 100 ? mb_substr($str, 0, 100) : $str;
            }, $props);
        }

        return $payload;
    }

    /**
     * Send the HTTP request to Plausible API.
     *
     * @param  array<string, mixed>  $payload
     * @return bool  True if the request was successful
     */
    private function sendRequest(array $payload): bool
    {
        if (! function_exists('curl_init')) {
            return false;
        }

        $ch = curl_init($this->apiUrl);

        if ($ch === false) {
            return false;
        }

        $headers = ['Content-Type: application/json'];

        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => $headers,
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
        return $this->domain !== '';
    }
}
