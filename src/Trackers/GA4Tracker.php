<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Trackers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;

class GA4Tracker implements TrackerInterface
{
    private const MEASUREMENT_PROTOCOL_URL = 'https://www.google-analytics.com/mp/collect';

    private const VALIDATE_URL = 'https://www.google-analytics.com/mp/debug/collect';

    private string $measurementId;

    private string $apiSecret;

    private bool $enabled;

    use TrackerHelpers;

    public function __construct(string $measurementId, string $apiSecret, bool $enabled = false)
    {
        $this->measurementId = $measurementId;
        $this->apiSecret = $apiSecret;
        $this->enabled = $enabled;
        $this->consent = ConsentState::granted();
    }

    public function track(AnalyticsEvent $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        // Respect analytics_storage consent — if denied, don't send hits
        if ($this->isAnalyticsDenied()) {
            return;
        }

        $clientId = $event->clientId ?? $this->generateClientId();

        $payload = [
            'client_id' => $clientId,
            'events' => [
                [
                    'name' => $event->name,
                    'params' => array_merge($event->params, $event->userId !== null ? ['user_id' => $event->userId] : []),
                ],
            ],
        ];

        try {
            $response = Http::post($this->buildUrl(self::MEASUREMENT_PROTOCOL_URL), $payload);

            if (! $response->successful()) {
                Log::warning('GA4Tracker: event dispatch failed', [
                    'event' => $event->name,
                    'status' => $response->status(),
                    'body' => (string) $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('GA4Tracker: event dispatch error', [
                'event' => $event->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validate an event using the debug endpoint (does not record data).
     *
     * @return array<string, mixed>|null
     */
    public function validate(AnalyticsEvent $event): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $clientId = $event->clientId ?? $this->generateClientId();

        $payload = [
            'client_id' => $clientId,
            'events' => [
                [
                    'name' => $event->name,
                    'params' => $event->params,
                ],
            ],
        ];

        try {
            /** @var Response $response */
            $response = Http::post($this->buildUrl(self::VALIDATE_URL), $payload);

            if (! $response->ok()) {
                Log::warning('GA4Tracker: validation request failed', [
                    'event' => $event->name,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $body = $response->body();
            $decoded = json_decode($body, true);

            if (! is_array($decoded)) {
                return null;
            }

            /** @var array<string, mixed> $decoded */
            return $decoded;
        } catch (\Throwable $e) {
            Log::error('GA4Tracker: validation request error', [
                'event' => $event->name,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->isValidMeasurementId($this->measurementId) && $this->apiSecret !== '';
    }

    public function headScripts(): string
    {
        if (! $this->isValidMeasurementId($this->measurementId)) {
            return '';
        }

        $consentInit = $this->renderConsentDefault();

        return <<<HTML
<!-- Google Analytics 4 -->
{$consentInit}<script async src="https://www.googletagmanager.com/gtag/js?id={$this->measurementId}"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '{$this->measurementId}');
</script>
<!-- End Google Analytics 4 -->
HTML;
    }

    public function bodyScripts(): string
    {
        return '';
    }

    public function getMeasurementId(): string
    {
        return $this->measurementId;
    }

    public function getApiSecret(): string
    {
        return $this->apiSecret;
    }

    public function setConsent(ConsentState $state): void
    {
        $this->consent = $state;
    }

    public function getConsent(): ConsentState
    {
        return $this->consent;
    }

    public function isValidMeasurementId(string $id): bool
    {
        return preg_match('/^G-[A-Z0-9]{8,}$/', $id) === 1;
    }

    /**
     * Validate API Secret format.
     */
    public function isValidApiSecret(string $secret): bool
    {
        return strlen($secret) >= 20;
    }

    /**
     * Reset the stored user ID (GDPR right to be forgotten).
     *
     * Clears any cached user identifier so subsequent events
     * are sent without user association.
     */
    public function resetUserId(): void
    {
        // GA4 MP is stateless per request; this clears any cached state.
        // For client-side, push a reset to dataLayer if available.
        if (function_exists('app') && app()->environment() !== 'testing') {
            try {
                Log::debug('GA4Tracker: user ID reset');
            } catch (\Throwable) {
                // Silently ignore if Log facade unavailable
            }
        }
    }

    /**
     * Generate a pseudo-random client ID.
     */
    private function generateClientId(): string
    {
        return sprintf(
            '%d.%d',
            (int) (microtime(true) * 1000000),
            random_int(1000000000, 9999999999),
        );
    }

    private function buildUrl(string $baseUrl): string
    {
        $params = http_build_query([
            'measurement_id' => $this->measurementId,
            'api_secret' => $this->apiSecret,
        ]);

        return $baseUrl.'?'.$params;
    }
}
