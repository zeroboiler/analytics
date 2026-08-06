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
 * PostHog Analytics — product analytics with feature flags.
 *
 * Tracks events server-side via the PostHog capture endpoint.
 * Supports event properties and user identification.
 */
class PosthogTracker implements TrackerInterface
{
    use TrackerHelpers;

    private string $apiKey;

    private string $host;

    private string $projectId;

    private bool $enabled;

    public function __construct(
        string $apiKey,
        string $host = 'https://eu.posthog.com',
        string $projectId = '',
        bool $enabled = false,
    ) {
        $this->apiKey = $apiKey;
        $this->host = rtrim($host, '/');
        $this->projectId = $projectId;
        $this->enabled = $enabled;
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

        // Set PostHog project if configured
        if ($this->projectId !== '') {
            $payload['project_id'] = $this->projectId;
        }

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::post(
                "{$this->host}/capture",
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
            Http::post("{$this->host}/capture", [
                'api_key' => $this->apiKey,
                'event' => '$reset',
                'properties' => [
                    '$lib' => 'zeroboiler-analytics-server',
                ],
            ]);
        } catch (\Throwable) {
            // Silent fail — GDPR reset should not throw
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
}
