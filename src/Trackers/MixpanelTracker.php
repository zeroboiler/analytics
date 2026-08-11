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
 * Mixpanel Analytics — product analytics with user profiling.
 *
 * Tracks events server-side via the Mixpanel /track API endpoint.
 * Supports event properties, user identification ($set), and
 * incremental numeric properties ($add).
 *
 * @since 10.0.0
 */
final class MixpanelTracker implements TrackerInterface
{
    use TrackerHelpers;

    private string $token;

    private string $host;

    private bool $enabled;

    public function __construct(
        string $token,
        string $host = 'https://api.mixpanel.com',
        bool $enabled = false,
    ): void {
        $this->token = $token;
        $this->host = rtrim($host, '/');
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

        $distinctId = $event->userId ?? $event->clientId ?? $this->generateDistinctId();

        $payload = [
            'event' => $event->name,
            'properties' => array_merge([
                'distinct_id' => $distinctId,
                'token' => $this->token,
                'time' => $event->timestamp?->getTimestamp() ?? time(),
                '$lib' => 'zeroboiler-analytics-server',
            ], $event->params),
        ];

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::asJson()->post(
                "{$this->host}/track",
                [$payload],
            );

            if (! $response->successful()) {
                Log::warning('MixpanelTracker: event dispatch failed', [
                    'event' => $event->name,
                    'status' => $response->status(),
                    'body' => (string) $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('MixpanelTracker: event dispatch error', [
                'event' => $event->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Set user profile properties (alias for $set).
     *
     * @param  array<string, mixed>  $properties  User properties to set
     */
    public function setUserProfile(string $userId, array $properties = []): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            Http::asJson()->post("{$this->host}/engage", [
                '$token' => $this->token,
                '$distinct_id' => $userId,
                '$set' => $properties,
            ]);
        } catch (\Throwable $e) {
            Log::error('MixpanelTracker: setUserProfile error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Increment a numeric user property.
     *
     * @param  array<string, int|float>  $properties  Properties to increment
     */
    public function incrementUserProperty(string $userId, array $properties = []): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            Http::asJson()->post("{$this->host}/engage", [
                '$token' => $this->token,
                '$distinct_id' => $userId,
                '$add' => $properties,
            ]);
        } catch (\Throwable $e) {
            Log::error('MixpanelTracker: incrementUserProperty error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Alias one distinct ID to another (for cross-device identity).
     */
    public function alias(string $distinctId, string $aliasId): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            Http::asJson()->post("{$this->host}/track", [[
                'event' => '$create_alias',
                'properties' => [
                    'distinct_id' => $distinctId,
                    'alias' => $aliasId,
                    'token' => $this->token,
                ],
            ]]);
        } catch (\Throwable $e) {
            Log::error('MixpanelTracker: alias error', [
                'distinct_id' => $distinctId,
                'alias' => $aliasId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reset user identity (GDPR right to be forgotten).
     */
    public function reset(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        try {
            Http::asJson()->post("{$this->host}/engage", [
                '$token' => $this->token,
                '$delete' => $this->token,
            ]);
        } catch (\Throwable) {
            // Silent fail — GDPR reset should not throw
        }
    }

    #[\Override]
    public function isEnabled(): bool
    {
        return $this->enabled && $this->token !== '';
    }

    #[\Override]
    public function headScripts(): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        return <<<HTML
<!-- Mixpanel Analytics -->
<script type="text/javascript">
  (function(f,b){if(!b.__SV){var e,g,i,h;window.mixpanel=b;b._i=[];b.init=function(e,f,c){function g(a,d,b){var c=b=="mixpanel"?"mixpanel":b||"";a[c]=a[c]||[];a[c]._q=[];a[c].push=function(){a[c]._q.push([d].concat(Array.prototype.slice.call(arguments,0)))};for(var e=["track","set_group","identify","alias","reset","page","pageview"],g=0;g<e.length;g++)a[c].push(a[c],e[g]);b.SNIPPET_VERSION="4.1.0";return a[c]}}(document,window.mixpanel||[]);mixpanel.init("{$this->token}",{api_host:"{$this->host}"});}
</script>
<!-- End Mixpanel Analytics -->
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

    public function getToken(): string
    {
        return $this->token;
    }

    public function getHost(): string
    {
        return $this->host;
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
