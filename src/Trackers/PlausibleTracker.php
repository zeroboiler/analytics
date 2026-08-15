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
 * Plausible Analytics — privacy-focused, cookie-free analytics.
 *
 * Tracks events server-side via the Plausible API event endpoint.
 * Supports custom events with properties, self-hosted instances,
 * and automatic script URL generation for custom domains.
 *
 * @since 1.0.0
 * @see https://plausible.io/docs/api-event
 */
final class PlausibleTracker implements TrackerInterface
{
    use TrackerHelpers;

    private string $domain;

    private string $apiKey;

    private string $baseUrl;

    private bool $enabled;

    /** @var string|null Custom script URL for self-hosted instances (e.g., 'stats.example.com/js/script.js') */
    private ?string $customScriptUrl;

    public function __construct(
        string $domain,
        string $apiKey = '',
        string $baseUrl = 'https://plausible.io/api/event',
        bool $enabled = false,
        ?string $customScriptUrl = null,
    ): void {
        $this->domain = $domain;
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
        $this->enabled = $enabled;
        $this->customScriptUrl = $customScriptUrl;
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

        $payload = [
            'domain' => $this->domain,
            'name' => $event->name,
            'url' => $event->params['page_location'] ?? $event->params['url'] ?? null,
            'referrer' => $event->params['page_referrer'] ?? $event->params['referrer'] ?? null,
            'props' => $event->params,
        ];

        // Clean up props — remove internal fields
        unset(
            $payload['props']['page_location'],
            $payload['props']['page_referrer'],
            $payload['props']['url'],
            $payload['props']['referrer'],
        );

        // Remove null/empty values
        $payload = array_filter($payload, fn (mixed $v): bool => $v !== null && $v !== '');

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl, $payload);

            if (! $response->successful()) {
                Log::warning('PlausibleTracker: event dispatch failed', [
                    'event' => $event->name,
                    'status' => $response->status(),
                    'body' => (string) $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('PlausibleTracker: event dispatch error', [
                'event' => $event->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Track a custom Plausible goal/event with specific page URL.
     *
     * Useful for tracking events on SPA routes where the page URL
     * differs from the server-rendered URL.
     *
     * @param array<string, mixed> $props Custom event properties
     */
    public function trackGoal(string $name, string $url, array $props = []): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $event = new AnalyticsEvent(
            name: $name,
            params: array_merge($props, ['url' => $url]),
        );

        $this->track($event);
    }

    /**
     * Track a custom event with custom properties.
     *
     * Plausible's custom events support additional properties
     * that can be used for segmentation and filtering in the dashboard.
     *
     * @param  string  $name  Custom event name (e.g., 'Signup', 'Purchase')
     * @param  string  $url  Page URL where the event occurred
     * @param  array<string, mixed>  $props  Additional event properties
     * @param  string|null  $referrer  Optional referrer URL
     *
     * @since 90.0.0
     */
    public function trackCustomEvent(string $name, string $url, array $props = [], ?string $referrer = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if ($this->isAnalyticsDenied()) {
            return;
        }

        $payload = [
            'domain' => $this->domain,
            'name' => $name,
            'url' => $url,
            'props' => $props,
        ];

        if ($referrer !== null) {
            $payload['referrer'] = $referrer;
        }

        $payload = array_filter($payload, fn (mixed $v): bool => $v !== null && $v !== '');

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl, $payload);

            if (! $response->successful()) {
                Log::warning('PlausibleTracker: custom event dispatch failed', [
                    'event' => $name,
                    'status' => $response->status(),
                    'body' => (string) $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('PlausibleTracker: custom event dispatch error', [
                'event' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Track multiple events in a single batch API call.
     *
     * Reduces HTTP overhead by sending up to N events in one request.
     * Plausible's event endpoint accepts repeated form-encoded payloads;
     * this method sends them sequentially in a single HTTP client instance.
     *
     * @param  list<AnalyticsEvent>  $events  Events to track (max 50)
     * @return int Number of events successfully dispatched
     *
     * @since 162.0.0
     */
    public function batchTrack(array $events): int
    {
        if (! $this->isEnabled()) {
            return 0;
        }

        if ($this->isAnalyticsDenied()) {
            return 0;
        }

        $events = array_slice($events, 0, 50);
        $dispatched = 0;

        foreach ($events as $event) {
            try {
                $payload = [
                    'domain' => $this->domain,
                    'name' => $event->name,
                    'url' => $event->params['page_location'] ?? $event->params['url'] ?? null,
                    'referrer' => $event->params['page_referrer'] ?? $event->params['referrer'] ?? null,
                    'props' => $event->params,
                ];

                unset(
                    $payload['props']['page_location'],
                    $payload['props']['page_referrer'],
                    $payload['props']['url'],
                    $payload['props']['referrer'],
                );

                $payload = array_filter($payload, fn (mixed $v): bool => $v !== null && $v !== '');

                /** @var \Illuminate\Http\Client\Response $response */
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                ])->post($this->baseUrl, $payload);

                if ($response->successful()) {
                    $dispatched++;
                }
            } catch (\Throwable $e) {
                Log::warning('PlausibleTracker: batch event failed', [
                    'event' => $event->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $dispatched;
    }

    /**
     * Track a 404 page view.
     *
     * Sends a 404 event with the requested path as a custom property.
     * Useful for monitoring broken links and tracking user navigation errors.
     *
     * @param  string  $requestedPath  The URL path the user requested (e.g., '/old-page')
     * @param  string|null  $referrer  Optional referrer URL
     *
     * @since 162.0.0
     */
    public function track404Page(string $requestedPath, ?string $referrer = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $fullUrl = rtrim($this->domain !== '' ? "https://{$this->domain}" : '', '/') . '/' . ltrim($requestedPath, '/');
        $params = ['url' => $fullUrl, 'path' => $requestedPath];

        if ($referrer !== null) {
            $params['referrer'] = $referrer;
        }

        $event = new AnalyticsEvent(
            name: '404',
            params: $params,
        );

        $this->track($event);
    }

    /**
     * Track a page view with a specific URL.
     *
     * Sends a standard pageview event to Plausible.
     * Useful for server-side pageview tracking in SSR or API contexts.
     */
    public function trackPageView(string $url, ?string $referrer = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $params = ['url' => $url];
        if ($referrer !== null) {
            $params['referrer'] = $referrer;
        }

        $event = new AnalyticsEvent(
            name: 'pageview',
            params: $params,
        );

        $this->track($event);
    }

    #[\Override]
    public function isEnabled(): bool
    {
        return $this->enabled
            && $this->domain !== ''
            && $this->apiKey !== '';
    }

    #[\Override]
    public function headScripts(): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        if ($this->customScriptUrl !== null && $this->customScriptUrl !== '') {
            return <<<HTML
<!-- Plausible Analytics (Self-Hosted) -->
<script defer data-domain="{$this->domain}" src="{$this->customScriptUrl}"></script>
<!-- End Plausible Analytics -->
HTML;
        }

        return <<<HTML
<!-- Plausible Analytics -->
<script defer data-domain="{$this->domain}" src="https://plausible.io/js/script.js"></script>
<!-- End Plausible Analytics -->
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

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getCustomScriptUrl(): ?string
    {
        return $this->customScriptUrl;
    }

    /**
     * Check if this tracker is using a self-hosted Plausible instance.
     */
    public function isSelfHosted(): bool
    {
        return $this->customScriptUrl !== null && $this->customScriptUrl !== '';
    }
}
