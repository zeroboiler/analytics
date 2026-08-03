<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Trackers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;

class MetaPixelTracker implements TrackerInterface
{
    private const GRAPH_API_URL = 'https://graph.facebook.com/v18.0';

    private string $pixelId;

    private string $accessToken;

    private bool $enabled;

    private ConsentState $consent;

    /**
     * Standard Meta Pixel events mapping.
     */
    private const STANDARD_EVENTS = [
        'PageView',
        'ViewContent',
        'Search',
        'AddToCart',
        'AddToWishlist',
        'InitiateCheckout',
        'AddPaymentInfo',
        'Purchase',
        'Lead',
        'CompleteRegistration',
        'Contact',
        'CustomizeProduct',
        'Donate',
        'FindLocation',
        'Schedule',
        'StartTrial',
        'SubmitApplication',
        'Subscribe',
    ];

    public function __construct(string $pixelId, string $accessToken, bool $enabled = false)
    {
        $this->pixelId = $pixelId;
        $this->accessToken = $accessToken;
        $this->enabled = $enabled;
        $this->consent = ConsentState::granted();
    }

    public function track(AnalyticsEvent $event): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        // Respect analytics_storage consent — if denied, don't send server-side events
        if ($this->consent->isDenied('analytics_storage')) {
            return;
        }

        $eventName = $this->isStandardEvent($event->name) ? $event->name : 'CustomEvent';

        /** @var Request $request */
        $request = app(Request::class);

        $payload = [
            'data' => [
                [
                    'event_name' => $eventName,
                    'event_time' => time(),
                    'event_id' => $event->params['event_id'] ?? uniqid('', true),
                    'action_source' => 'website',
                    'event_source_url' => $event->params['url'] ?? $request->fullUrl(),
                    'user_data' => [
                        'client_ip_address' => $request->ip() ?? '127.0.0.1',
                        'client_user_agent' => $request->userAgent() ?? '',
                    ],
                    'custom_data' => array_diff_key($event->params, array_flip(['event_id', 'url'])),
                ],
            ],
        ];

        Http::withToken($this->accessToken)
            ->post($this->buildUrl(), $payload);
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->isValidPixelId($this->pixelId) && $this->accessToken !== '';
    }

    public function headScripts(): string
    {
        if (! $this->isValidPixelId($this->pixelId)) {
            return '';
        }

        // If consent is denied, revoke before init
        $consentScript = '';
        if ($this->consent->isDenied('analytics_storage')) {
            $consentScript = "\n  fbq('consent', 'revoke');";
        }

        return <<<HTML
<!-- Meta Pixel Code -->
<script>
  !function(f,b,e,v,n,t,s)
  {{if(f.fbq)return;n=f.fbq=function(){{n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)}};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}}(window, document,'script','https://connect.facebook.net/en_US/fbevents.js');{$consentScript}
  fbq('init', '{$this->pixelId}');
  fbq('track', 'PageView');
</script>
<!-- End Meta Pixel Code -->
HTML;
    }

    public function bodyScripts(): string
    {
        if (! $this->isValidPixelId($this->pixelId)) {
            return '';
        }

        return <<<HTML
<!-- Meta Pixel Code (noscript) -->
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id={$this->pixelId}&ev=PageView&noscript=1" alt="" /></noscript>
<!-- End Meta Pixel Code (noscript) -->
HTML;
    }

    public function getPixelId(): string
    {
        return $this->pixelId;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function setConsent(ConsentState $state): void
    {
        $this->consent = $state;
    }

    public function getConsent(): ConsentState
    {
        return $this->consent;
    }

    /**
     * Validate Meta Pixel ID format (numeric).
     */
    public function isValidPixelId(string $id): bool
    {
        return preg_match('/^\d{10,}$/', $id) === 1;
    }

    /**
     * Check if an event name is a standard Meta Pixel event.
     */
    public function isStandardEvent(string $eventName): bool
    {
        return in_array($eventName, self::STANDARD_EVENTS, true);
    }

    /**
     * Get all standard events.
     *
     * @return array<int, string>
     */
    public function getStandardEvents(): array
    {
        return self::STANDARD_EVENTS;
    }

    private function buildUrl(): string
    {
        return self::GRAPH_API_URL."/{$this->pixelId}/events";
    }
}
