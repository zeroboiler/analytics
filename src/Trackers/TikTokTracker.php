<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Trackers;

use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;

/**
 * TikTok Pixel & Events API tracker.
 *
 * Supports both client-side pixel rendering (via ttq) and server-side
 * Conversions API (CAPI) for server-to-server event tracking.
 *
 * TikTok Events API: https://ads.tiktok.com/help/article/tiktok-events-api-advanced-matching
 *
 * Server-side CAPI sends events to: https://business-api.tiktok.com/open_api/v1.3/pixel/track/
 *
 * @since 32.0.0
 */
final class TikTokTracker implements TrackerInterface
{
    private const CAPI_URL = 'https://business-api.tiktok.com/open_api/v1.3/pixel/track/';

    private string $pixelId;

    private string $accessToken;

    private bool $enabled;

    private string $apiVersion;

    use TrackerHelpers;

    /**
     * @param  string  $pixelId  TikTok Pixel ID (e.g., "CA1234567890")
     * @param  string  $accessToken  TikTok Events API access token
     * @param  bool  $enabled  Whether the tracker is enabled
     * @param  string  $apiVersion  TikTok API version (default: v1.3)
     */
    public function __construct(
        string $pixelId,
        string $accessToken,
        bool $enabled = false,
        string $apiVersion = 'v1.3',
    ): void {
        $this->pixelId = $pixelId;
        $this->accessToken = $accessToken;
        $this->enabled = $enabled;
        $this->apiVersion = $apiVersion;
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

        try {
            \Illuminate\Support\Facades\Http::asJson()->post(self::CAPI_URL, [
                'pixel_code' => $this->pixelId,
                'event' => $this->mapEventName($event->name),
                'event_id' => $event->idempotencyKey ?? $this->generateEventId($event),
                'properties' => $this->mapEventParams($event),
                'timestamp' => $event->timestamp?->toIso8601String() ?? now()->toIso8601String(),
                'user_agent' => request()->userAgent() ?? '',
                'ip' => request()->ip() ?? '',
            ])->withHeaders([
                'Access-Token' => $this->accessToken,
            ]);
        } catch (\Throwable $e) {
            Log::warning('TikTokTracker: event dispatch error', [
                'event' => $event->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->pixelId !== '';
    }

    public function headScripts(): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        return <<<HTML
<script>
!function (w, t) { w.ttq = function (t, e) { w.ttq.invokeMethod ? w.ttq.invokeMethod(t, e) : w.ttq.queue.push(t, e) }; w.ttq.load = function (e) { var n = document.createElement("script"); n.type = "text/javascript", n.async = !0, n.src = "https://analytics.tiktok.com/i18n/pixel/sdk.js?sdkid=" + e, n.onload = function () { for (var t = window.ttq; t.queue.length;) { var n = t.queue.shift(); t.invokeMethod(n[0], n[1]) } }; var a = document.getElementsByTagName("script")[0]; a.parentNode.insertBefore(n, a) }; w.ttq.queue = []; w.ttq.load('{$this->pixelId}');
}(window, document);
</script>
HTML;
    }

    public function bodyScripts(): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

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

    /**
     * Get the TikTok Pixel ID.
     */
    public function getPixelId(): string
    {
        return $this->pixelId;
    }

    /**
     * Get the TikTok Events API access token.
     */
    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * Map internal event names to TikTok standard event names.
     *
     * TikTok Standard Events: ClickButton, ViewContent, SubmitForm, CompleteRegistration,
     * Subscribe, Purchase, AddToCart, InitiateCheckout, CompletePayment, Search, etc.
     *
     * @see https://ads.tiktok.com/help/article/tiktok-pixel-standard-events-parameters
     */
    private function mapEventName(string $name): string
    {
        return match ($name) {
            'page_view' => 'Pageview',
            'view_item' => 'ViewContent',
            'add_to_cart' => 'AddToCart',
            'remove_from_cart' => 'ClickButton',
            'begin_checkout' => 'InitiateCheckout',
            'add_payment_info' => 'ClickButton',
            'purchase' => 'CompletePayment',
            'refund' => 'ClickButton',
            'sign_up', 'register' => 'CompleteRegistration',
            'login' => 'ClickButton',
            'click' => 'ClickButton',
            'form_submit' => 'SubmitForm',
            'form_start' => 'ClickButton',
            'search' => 'Search',
            'share' => 'ClickButton',
            'subscribe', 'subscription_created' => 'Subscribe',
            'view_cart' => 'ViewContent',
            'add_to_wishlist' => 'AddToWishlist',
            'start_trial' => 'Subscribe',
            'plan_upgrade' => 'Subscribe',
            'cancellation' => 'ClickButton',
            default => 'ClickButton',
        };
    }

    /**
     * Map event parameters to TikTok CAPI properties format.
     *
     * @return array<string, mixed>
     */
    private function mapEventParams(AnalyticsEvent $event): array
    {
        $params = $event->params;
        $tiktokParams = [];

        // E-commerce parameters
        if (isset($params['value']) || isset($params['currency'])) {
            $tiktokParams['value'] = (float) ($params['value'] ?? 0);
            $tiktokParams['currency'] = (string) ($params['currency'] ?? 'USD');
        }

        // Items → contents
        if (isset($params['items']) && is_array($params['items'])) {
            $tiktokParams['contents'] = array_map(
                fn (array $item): array => [
                    'content_id' => (string) ($item['item_id'] ?? ''),
                    'content_name' => (string) ($item['item_name'] ?? ''),
                    'content_category' => (string) ($item['item_category'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'price' => (float) ($item['price'] ?? 0),
                ],
                $params['items'],
            );
        }

        // Search query
        if (isset($params['search_term'])) {
            $tiktokParams['query'] = (string) $params['search_term'];
        }

        // Copy standard params
        foreach (['content_id', 'content_name', 'content_category', 'quantity', 'price'] as $key) {
            if (isset($params[$key])) {
                $tiktokParams[$key] = $params[$key];
            }
        }

        // User identity (for advanced matching)
        if ($event->userId !== null) {
            $tiktokParams['user_id'] = $event->userId;
        }

        // Client-side identifiers
        if ($event->clientId !== null) {
            $tiktokParams['ttclid'] = $event->clientId;
        }

        return $tiktokParams;
    }

    /**
     * Generate a deterministic event ID for TikTok deduplication.
     */
    private function generateEventId(AnalyticsEvent $event): string
    {
        return hash('xxh128', $event->name . ':' . json_encode($event->params, JSON_THROW_ON_ERROR));
    }

    public function trackBatch(array $events): int
    {
        return $this->defaultTrackBatch($events);
    }

    public function identify(string $userId, array $traits = []): void
    {
        // TikTok Events API has no dedicated identify endpoint.
        // User identification is handled via advanced matching parameters
        // (email, phone, etc.) on individual event payloads. No-op is intentional.
    }

    public function providerName(): string
    {
        return 'tiktok';
    }
}
