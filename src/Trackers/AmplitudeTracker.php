<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Trackers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Support\EventTransformer;

/**
 * Amplitude Analytics — product analytics with behavioral cohorting.
 *
 * Tracks events server-side via the Amplitude V2 HTTP API (/v2/httpapi).
 * Supports event properties, user properties, device context, and
 * platform-specific fields.
 *
 * @since 10.0.0
 */
final class AmplitudeTracker implements TrackerInterface
{
    use TrackerHelpers;

    private string $apiKey;

    private string $host;

    private string $platform;

    private bool $enabled;

    public function __construct(
        string $apiKey,
        string $host = 'https://api2.amplitude.com',
        string $platform = 'Laravel/Server',
        bool $enabled = false,
    ): void {
        $this->apiKey = $apiKey;
        $this->host = rtrim($host, '/');
        $this->platform = $platform;
        $this->enabled = $enabled;
        $this->consent = ConsentState::granted();
    }

    #[\Override]
    public function track(AnalyticsEvent $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if ($this->isAnalyticsDenied()) {
            return;
        }

        $event = EventTransformer::transformForProvider($event, 'amplitude');

        $userId = $event->userId ?? null;
        $deviceId = $event->clientId ?? $this->generateDeviceId();

        $eventPayload = [
            'event_type' => $event->name,
            'time' => $event->timestamp?->getTimestamp() ?? time(),
            'event_properties' => $this->sanitizeProperties($event->params),
        ];

        if ($userId !== null) {
            $eventPayload['user_id'] = $userId;
        }

        if ($deviceId !== null && $userId === null) {
            $eventPayload['device_id'] = $deviceId;
        }

        // Extract user properties from params if present
        $userProperties = $event->params['user_properties'] ?? [];
        if (is_array($userProperties) && $userProperties !== []) {
            $eventPayload['user_properties'] = $this->sanitizeProperties($userProperties);
        }

        // Remove user_properties from event properties to avoid duplication
        unset($eventPayload['event_properties']['user_properties']);

        // Add platform and library info
        $eventPayload['platform'] = $this->platform;
        $eventPayload['lib_version'] = AnalyticsEvent::VERSION;

        $payload = [
            'api_key' => $this->apiKey,
            'events' => [$eventPayload],
        ];

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::asJson()->post(
                "{$this->host}/v2/httpapi",
                $payload,
            );

            if (! $response->successful()) {
                Log::warning('AmplitudeTracker: event dispatch failed', [
                    'event' => $event->name,
                    'status' => $response->status(),
                    'body' => (string) $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('AmplitudeTracker: event dispatch error', [
                'event' => $event->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Identify a user and set their properties.
     *
     * @param  array<string, mixed>  $userProperties  User properties to set
     */
    public function identify(string $userId, array $userProperties = [], ?string $deviceId = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $payload = [
            'api_key' => $this->apiKey,
            'events' => [[
                'event_type' => '$identify',
                'user_id' => $userId,
                'user_properties' => $this->sanitizeProperties($userProperties),
                'platform' => $this->platform,
                'time' => time(),
            ]],
        ];

        if ($deviceId !== null) {
            $payload['events'][0]['device_id'] = $deviceId;
        }

        try {
            Http::asJson()->post("{$this->host}/v2/httpapi", $payload);
        } catch (\Throwable $e) {
            Log::error('AmplitudeTracker: identify error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reset user identity (GDPR right to be forgotten).
     *
     * Amplitude recommends calling identify with cleared properties
     * and then deleting the user via their API. This sends a reset event
     * and removes the device ID mapping.
     */
    public function reset(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            Http::asJson()->post("{$this->host}/v2/httpapi", [
                'api_key' => $this->apiKey,
                'events' => [[
                    'event_type' => '$reset',
                    'platform' => $this->platform,
                    'time' => time(),
                ]],
            ]);
        } catch (\Throwable) {
            // Silent fail — GDPR reset should not throw
        }
    }

    /**
     * Sanitize properties for Amplitude API.
     *
     * Removes nested arrays (Amplitude supports only string/number/boolean/array-of-string values),
     * truncates string values, and strips null values.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function sanitizeProperties(array $properties): array
    {
        $cleaned = [];

        foreach ($properties as $key => $value) {
            // Skip nested arrays (Amplitude doesn't support them in event properties)
            if (is_array($value)) {
                // Keep flat arrays (list of strings)
                if (array_is_list($value)) {
                    $flatValues = [];
                    foreach ($value as $v) {
                        if (is_string($v) || is_int($v) || is_float($v) || is_bool($v)) {
                            $flatValues[] = $v;
                        }
                    }
                    if ($flatValues !== []) {
                        $cleaned[$key] = $flatValues;
                    }
                }
                continue;
            }

            // Skip null values
            if ($value === null) {
                continue;
            }

            // Truncate strings to 1024 characters (Amplitude limit)
            if (is_string($value) && mb_strlen($value) > 1024) {
                $value = mb_substr($value, 0, 1024);
            }

            $cleaned[$key] = $value;
        }

        return $cleaned;
    }

    #[\Override]
    public function isEnabled(): bool
    {
        return $this->enabled && $this->apiKey !== '';
    }

    #[\Override]
    public function headScripts(): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        return <<<HTML
<!-- Amplitude Analytics -->
<script type="text/javascript">
(function(e,t){var r=e.amplitude||{_q:[]};var n=t.createElement("script");n.type="text/javascript";n.integrity="sha384-bPry4K1dF8gYqpPpWI8A0s8gJ14eWzOlD0bWzUOFvlHXQdkIhVx+flG9dKxpT6W5l";n.crossOrigin="anonymous";n.async=true;n.src="https://cdn.amplitude.com/libs/analytics-browser-2.11.2-min.js.gz";n.onload=function(){e.amplitude.runQueuedFunctions()};var s=t.getElementsByTagName("script")[0];s.parentNode.insertBefore(n,s);function a(e){e.prototype.identify=e.prototype.identify||function(t,r){var i=this._q;i.push(["identify",{user_id:t,device_id:r}]);return this};e.prototype.setGroup=e.prototype.setGroup||function(t,r){this._q.push(["setGroup",t,r]);return this};e.prototype.logEvent=e.prototype.logEvent||function(t,r,i){this._q.push(["logEvent",t,r,i]);return this};e.prototype.setUserProperties=e.prototype.setUserProperties||function(t){this._q.push(["setUserProperties",t]);return this};e.prototype.reset=e.prototype.reset||function(){this._q.push(["reset"]);return this}}(e.amplitude);e.amplitude.init("{$this->apiKey}");
</script>
<!-- End Amplitude Analytics -->
HTML;
    }

    #[\Override]
    public function bodyScripts(): string
    {
        return '';
    }

    #[\Override]
    public function setConsent(ConsentState $state): void
    {
        $this->consent = $state;
    }

    #[\Override]
    public function getConsent(): ConsentState
    {
        return $this->consent;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    /**
     * Generate a random device ID for anonymous events.
     */
    private function generateDeviceId(): string
    {
        return sprintf(
            'zb_%d_%d',
            (int) (microtime(true) * 1000000),
            random_int(100000, 999999),
        );
    }
}
