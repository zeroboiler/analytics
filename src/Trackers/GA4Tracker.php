<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Trackers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

class GA4Tracker implements TrackerInterface
{
    private const MEASUREMENT_PROTOCOL_URL = 'https://www.google-analytics.com/mp/collect';

    private const VALIDATE_URL = 'https://www.google-analytics.com/mp/debug/collect';

    private string $measurementId;

    private string $apiSecret;

    private bool $enabled;

    public function __construct(string $measurementId, string $apiSecret, bool $enabled = false)
    {
        $this->measurementId = $measurementId;
        $this->apiSecret = $apiSecret;
        $this->enabled = $enabled;
    }

    public function track(AnalyticsEvent $event): void
    {
        if (! $this->isEnabled()) {
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

        Http::post($this->buildUrl(self::MEASUREMENT_PROTOCOL_URL), $payload);
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

        /** @var Response $response */
        $response = Http::post($this->buildUrl(self::VALIDATE_URL), $payload);

        if (! $response->ok()) {
            return null;
        }

        $body = $response->body();
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
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

        return <<<HTML
<!-- Google Analytics 4 -->
<script async src="https://www.googletagmanager.com/gtag/js?id={$this->measurementId}"></script>
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

    /**
     * Validate GA4 Measurement ID format (G-XXXXXXXX).
     */
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
