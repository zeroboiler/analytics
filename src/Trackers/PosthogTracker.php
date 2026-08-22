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

/**
 * PostHog Analytics — product analytics with feature flags and session replay.
 *
 * Tracks events server-side via the PostHog capture endpoint.
 * Supports event properties, user identification, feature flags,
 * and GDPR-compliant identity reset.
 *
 * @since 1.0.0
 * @see https://posthog.com/docs/api/capture
 */
final class PosthogTracker implements TrackerInterface
{
    use TrackerHelpers;

    private string $apiKey;

    private string $host;

    private string $projectId;

    private bool $enabled;

    /** @var bool Whether to use the Conversions API (server-side capture with $set person properties) */
    private bool $capiEnabled;

    /** @var string Capture path for API endpoint */
    private string $capturePath;

    public function __construct(
        string $apiKey,
        string $host = 'https://eu.posthog.com',
        string $projectId = '',
        bool $enabled = false,
        bool $capiEnabled = true,
        string $capturePath = '/capture/',
    ){
        $this->apiKey = $apiKey;
        $this->host = rtrim($host, '/');
        $this->projectId = $projectId;
        $this->enabled = $enabled;
        $this->capiEnabled = $capiEnabled;
        $this->capturePath = $capturePath;
        $this->consent = ConsentState::granted();
    }

    public function track(AnalyticsEvent $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if ($this->isAnalyticsDenied()) {
            return;
        }

        $distinctId = $event->userId ?? $event->clientId ?? $this->generateDistinctId();

        $payload = [
            'api_key' => $this->apiKey,
            'event' => $event->name,
            'properties' => array_merge([
                'distinct_id' => $distinctId,
                '$lib' => 'zeroboiler-analytics-server',
            ], $event->params),
        ];

        if ($this->projectId !== '') {
            $payload['project_id'] = $this->projectId;
        }

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::post(
                "{$this->host}{$this->capturePath}",
                $payload,
            );

            if (! $response->successful()) {
                Log::warning('PosthogTracker: event dispatch failed', [
                    'event' => $event->name,
                    'status' => $response->status(),
                    'body' => (string) $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('PosthogTracker: event dispatch error', [
                'event' => $event->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Track an event with person properties (CAPI mode).
     *
     * When CAPI is enabled, sends $set person properties alongside the event
     * for server-side user identity management. Bypasses ad blockers.
     *
     * @param array<string, mixed> $eventProps Event-level properties
     * @param array<string, mixed> $personProps Person-level properties ($set)
     */
    public function trackWithPerson(string $event, string $distinctId, array $eventProps = [], array $personProps = []): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if ($this->isAnalyticsDenied()) {
            return;
        }

        $payload = [
            'api_key' => $this->apiKey,
            'event' => $event,
            'properties' => array_merge([
                'distinct_id' => $distinctId,
                '$lib' => 'zeroboiler-analytics-server',
            ], $eventProps),
        ];

        // Attach person properties via $set
        if (! empty($personProps) && $this->capiEnabled) {
            $payload['properties']['$set'] = $personProps;
        }

        if ($this->projectId !== '') {
            $payload['project_id'] = $this->projectId;
        }

        try {
            Http::post("{$this->host}{$this->capturePath}", $payload);
        } catch (\Throwable $e) {
            Log::error('PosthogTracker: trackWithPerson error', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Identify a user with properties (server-side $set).
     *
     * Sets person properties on the identified user without creating an event.
     * Useful for server-side user profile updates.
     *
     * @param array<string, mixed> $properties Person properties to set
     */
    public function identify(string $userId, array $traits = []): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $payload = [
            'api_key' => $this->apiKey,
            'event' => '$identify',
            'properties' => array_merge([
                'distinct_id' => $userId,
                '$lib' => 'zeroboiler-analytics-server',
            ], $traits),
        ];

        if ($this->projectId !== '') {
            $payload['project_id'] = $this->projectId;
        }

        try {
            Http::post("{$this->host}{$this->capturePath}", $payload);
        } catch (\Throwable $e) {
            Log::error('PosthogTracker: identify error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Batch capture multiple events in a single API call.
     *
     * PostHog's /capture/ endpoint supports batch JSON payloads with
     * multiple events in one request. This reduces HTTP overhead for
     * high-throughput event pipelines.
     *
     * @param  list<AnalyticsEvent>  $events  Events to capture (max 50)
     * @return int Number of events successfully queued for capture
     *
     * @since 163.0.0
     */
    public function batchCapture(array $events): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        if ($this->isAnalyticsDenied()) {
            return 0;
        }

        $events = array_slice($events, 0, 50);

        if ($events === []) {
            return 0;
        }

        $batch = [];
        foreach ($events as $event) {
            $distinctId = $event->userId ?? $event->clientId ?? $this->generateDistinctId();

            $payload = [
                'event' => $event->name,
                'properties' => array_merge([
                    'distinct_id' => $distinctId,
                    '$lib' => 'zeroboiler-analytics-server',
                ], $event->params),
            ];

            if ($this->projectId !== '') {
                $payload['project_id'] = $this->projectId;
            }

            $batch[] = $payload;
        }

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::post(
                "{$this->host}{$this->capturePath}batch/",
                [
                    'api_key' => $this->apiKey,
                    'batch' => $batch,
                ],
            );

            if (! $response->successful()) {
                Log::warning('PosthogTracker: batch capture failed', [
                    'count' => count($batch),
                    'status' => $response->status(),
                ]);

                return 0;
            }

            return count($batch);
        } catch (\Throwable $e) {
            Log::error('PosthogTracker: batch capture error', [
                'count' => count($batch),
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Alias a user's identity — merge two distinct IDs.
     *
     * Used when linking an anonymous device ID to an authenticated user ID.
     */
    public function alias(string $previousId, string $newId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $payload = [
            'api_key' => $this->apiKey,
            'event' => '$create_alias',
            'properties' => [
                'distinct_id' => $previousId,
                'alias' => $newId,
                '$lib' => 'zeroboiler-analytics-server',
            ],
        ];

        if ($this->projectId !== '') {
            $payload['project_id'] = $this->projectId;
        }

        try {
            Http::post("{$this->host}{$this->capturePath}", $payload);
        } catch (\Throwable $e) {
            Log::error('PosthogTracker: alias error', [
                'previous_id' => $previousId,
                'new_id' => $newId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Track a page view event.
     *
     * Sends a $pageview event with URL, referrer, and title properties.
     */
    public function trackPageView(string $distinctId, string $url, ?string $referrer = null, ?string $title = null): void
    {
        $props = ['$current_url' => $url];

        if ($referrer !== null) {
            $props['$referrer'] = $referrer;
        }

        if ($title !== null) {
            $props['$title'] = $title;
        }

        $event = new AnalyticsEvent(
            name: '$pageview',
            params: $props,
            clientId: $distinctId,
        );

        $this->track($event);
    }

    /**
     * Check if a feature flag is enabled for a user (server-side evaluation).
     *
     * Calls the PostHog feature flag evaluation endpoint.
     * Returns the flag value (bool or string) or null if the flag is not found.
     *
     * @return bool|string|null Flag value (true/false for boolean flags, variant string for multivariate)
     */
    public function isFeatureEnabled(string $flagKey, string $distinctId, array $personProperties = []): bool|string|null
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::get("{$this->host}/api/feature_flag/eval", [
                'api_key' => $this->apiKey,
                'feature_flag' => $flagKey,
                'distinct_id' => $distinctId,
                'person_properties' => json_encode($personProperties, JSON_THROW_ON_ERROR),
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return $data['evaluation'] ?? null;
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('PosthogTracker: feature flag check error', [
                'flag' => $flagKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->apiKey !== '';
    }

    public function headScripts(): string
    {
        if (! $this->isEnabled() || $this->projectId === '') {
            return '';
        }

        return <<<HTML
<!-- PostHog Analytics -->
<script>
  !function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]);t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.crossOrigin="anonymous",p.async=!0,p.src=s.api_host.replace(".i.",".js.")+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="capture identify alias people.set people.set_once set_config register register_once unregister opt_out_capturing has_opted_out_capturing opt_in_capturing reset isFeatureEnabled onFeatureFlags getFeatureFlag getFeatureFlagPayload reloadFeatureFlags updateEarlyAccessFeatureDevMode getEarlyAccessFeatures onSessionId getSurveys getActiveMatchingSurveys".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);
  posthog.init('{$this->apiKey}', {api_host: '{$this->host}'});
</script>
<!-- End PostHog Analytics -->
HTML;
    }

    public function bodyScripts(): string
    {
        return '';
    }

    public function setConsent(ConsentState $state): void
    {
        $this->consent = $state;
    }

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

    public function isCapiEnabled(): bool
    {
        return $this->capiEnabled;
    }

    /**
     * Reset user identity (GDPR right to be forgotten).
     *
     * Sends a reset event to the PostHog API to disassociate
     * future events from the current user.
     */
    public function reset(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            Http::post("{$this->host}{$this->capturePath}", [
                'api_key' => $this->apiKey,
                'event' => '$reset',
                'properties' => [
                    '$lib' => 'zeroboiler-analytics-server',
                ],
            ]);
        } catch (\Throwable $e) {
            // Silent fail — GDPR reset should not throw
        }
    }

    /**
     * Delete a person and all their events (GDPR Article 17).
     *
     * Permanently removes a person and all their associated events
     * from PostHog. This is irreversible and should only be called
     * in response to a verified GDPR erasure request.
     *
     * @param  string  $distinctId  The distinct ID of the person to delete
     * @return bool Whether the deletion was successful
     *
     * @since 90.0.0
     */
    public function deletePerson(string $distinctId): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ])->delete(
                "{$this->host}/api/persons/{$distinctId}",
            );

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('PosthogTracker: delete person error', [
                'distinct_id' => $distinctId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Generate a random distinct ID for anonymous events.
     */
    private function generateDistinctId(): string
    {
        return sprintf(
            '%d_%d',
            (int) (microtime(true) * 1000000),
            random_int(100000, 999999),
        );
    }

    public function trackBatch(array $events): int
    {
        if ($events === [] || ! $this->isEnabled()) {
            return 0;
        }

        if ($this->isAnalyticsDenied()) {
            return 0;
        }

        $batch = [];
        foreach ($events as $event) {
            $distinctId = $event->userId ?? $event->clientId ?? $this->generateDistinctId();

            $payload = [
                'event' => $event->name,
                'properties' => array_merge([
                    'distinct_id' => $distinctId,
                    '$lib' => 'zeroboiler-analytics-server',
                ], $event->params),
            ];

            if ($this->projectId !== '') {
                $payload['project_id'] = $this->projectId;
            }

            $batch[] = $payload;
        }

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = \Illuminate\Support\Facades\Http::post(
                "{$this->host}{$this->capturePath}batch/",
                [
                    'api_key' => $this->apiKey,
                    'batch' => $batch,
                ],
            );

            if (! $response->successful()) {
                Log::warning('PosthogTracker: batch dispatch failed', [
                    'count' => count($batch),
                    'status' => $response->status(),
                ]);

                return 0;
            }

            return count($batch);
        } catch (\Throwable $e) {
            Log::error('PosthogTracker: batch dispatch error', [
                'count' => count($batch),
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    public function providerName(): string
    {
        return 'posthog';
    }
}
