<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Trackers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;

/**
 * Generic HTTP webhook tracker for custom analytics backends.
 *
 * Sends analytics events to a configurable HTTP endpoint as POST requests.
 * Supports custom headers, signed payloads (HMAC-SHA256), and retry logic.
 * Ideal for forwarding events to internal data warehouses, custom dashboards,
 * or third-party analytics services not natively supported.
 *
 * @see \ZeroBoiler\Analytics\Trackers\TrackerInterface
 *
 * @since 1.0.0
 */
final class WebhookTracker implements TrackerInterface
{
    use TrackerHelpers;

    private string $webhookUrl;

    private string $secret;

    private bool $enabled;

    private int $timeout;

    private int $retries;

    /** @var array<string, string> */
    private array $headers;

    private bool $signPayloads;

    /**
     * @param  string  $webhookUrl  Target URL for event dispatch
     * @param  string  $secret  HMAC secret for payload signing (empty = unsigned)
     * @param  bool  $enabled  Whether the tracker is active
     * @param  int  $timeout  HTTP request timeout in seconds
     * @param  int  $retries  Number of retry attempts on failure
     * @param  array<string, string>  $headers  Additional HTTP headers
     * @param  bool  $signPayloads  Whether to sign payloads with HMAC-SHA256
     */
    public function __construct(
        string $webhookUrl = '',
        string $secret = '',
        bool $enabled = false,
        int $timeout = 5,
        int $retries = 1,
        array $headers = [],
        bool $signPayloads = false,
    ): void {
        $this->webhookUrl = $webhookUrl;
        $this->secret = $secret;
        $this->enabled = $enabled;
        $this->timeout = $timeout;
        $this->retries = $retries;
        $this->headers = $headers;
        $this->signPayloads = $signPayloads;
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

        $payload = $this->buildPayload($event);

        try {
            $this->httpClient()
                ->timeout($this->timeout)
                ->retry($this->retries, 100)
                ->post($this->webhookUrl, $payload);
        } catch (\Throwable) {
            // Webhook failures should not block the application
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled
            && $this->webhookUrl !== '';
    }

    public function setConsent(ConsentState $state): void
    {
        $this->consent = $state;

        // Webhook respects analytics_storage consent
        if ($state->isDenied('analytics_storage')) {
            $this->enabled = false;
        } else {
            $this->enabled = $this->webhookUrl !== '';
        }
    }

    public function getConsent(): ConsentState
    {
        return $this->consent;
    }

    public function headScripts(): string
    {
        // Webhook tracker is server-side only — no client scripts
        return '';
    }

    public function bodyScripts(): string
    {
        // Webhook tracker is server-side only — no client scripts
        return '';
    }

    /**
     * Get the webhook URL.
     */
    public function getWebhookUrl(): string
    {
        return $this->webhookUrl;
    }

    /**
     * Build the payload to send to the webhook.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(AnalyticsEvent $event): array
    {
        $payload = [
            'event' => $event->name,
            'params' => $event->params,
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($this->signPayloads && $this->secret !== '') {
            $payload['signature'] = $this->signPayload($payload);
        }

        return $payload;
    }

    /**
     * Sign a payload with HMAC-SHA256.
     *
     * @param  array<string, mixed>  $payload
     */
    private function signPayload(array $payload): string
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $body, $this->secret);
    }

    /**
     * Create an HTTP client with configured headers.
     */
    private function httpClient(): PendingRequest
    {
        $client = Http::asJson();

        foreach ($this->headers as $key => $value) {
            $client = $client->withHeader($key, $value);
        }

        return $client;
    }

    public function trackBatch(array $events): int
    {
        return $this->defaultTrackBatch($events);
    }

    public function identify(string $userId, array $traits = []): void
    {
        // Webhook tracker is a generic relay — no native identity system.
        // If the consumer needs identify events, they should use track() with
        // event name 'identify' and include traits in the params.
    }

    public function providerName(): string
    {
        return 'webhook';
    }
}
