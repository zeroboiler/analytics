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
 * Supports custom events with properties.
 *
 * @since 1.0.0
 */
final class PlausibleTracker implements TrackerInterface
{
    use TrackerHelpers;

    private string $domain;

    private string $apiKey;

    private string $baseUrl;

    private bool $enabled;

    public function __construct(
        string $domain,
        string $apiKey = '',
        string $baseUrl = 'https://plausible.io/api/event',
        bool $enabled = false,
    ): void {
        $this->domain = $domain;
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
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

        $payload = [
            'domain' => $this->domain,
            'name' => $event->name,
            'url' => $event->params['page_location'] ?? $event->params['url'] ?? null,
            'referrer' => $event->params['page_referrer'] ?? $event->params['referrer'] ?? null,
            'props' => $event->params,
        ];

        // Clean up props — remove internal fields
        unset($payload['props']['page_location'], $payload['props']['page_referrer'], $payload['props']['url'], $payload['props']['referrer']);

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
}
