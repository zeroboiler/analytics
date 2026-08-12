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
 * LinkedIn Insight Tag & Conversions API tracker.
 *
 * Supports both client-side Insight Tag rendering and server-side
 * Conversions API event tracking for B2B SaaS analytics.
 *
 * LinkedIn Conversions API: https://learn.microsoft.com/en-us/linkedin/marketing/conversions/
 *
 * @since 32.0.0
 */
final class LinkedInTracker implements TrackerInterface
{
    private const CONVERSION_API_URL = 'https://api.linkedin.com/rest/conversions';

    private string $partnerId;

    private string $conversionId;

    private string $accessToken;

    private bool $enabled;

    use TrackerHelpers;

    /**
     * @param  string  $partnerId  LinkedIn Partner ID (from Insight Tag)
     * @param  string  $conversionId  LinkedIn Conversion ID (for CAPI)
     * @param  string  $accessToken  LinkedIn Marketing API access token
     * @param  bool  $enabled  Whether the tracker is enabled
     */
    public function __construct(
        string $partnerId,
        string $conversionId,
        string $accessToken,
        bool $enabled = false,
    ): void {
        $this->partnerId = $partnerId;
        $this->conversionId = $conversionId;
        $this->accessToken = $accessToken;
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

        if ($this->conversionId === '' || $this->accessToken === '') {
            return;
        }

        try {
            \Illuminate\Support\Facades\Http::asJson()->post(self::CONVERSION_API_URL, [
                'conversion' => [
                    'conversionId' => $this->conversionId,
                    'eventName' => $this->mapEventName($event->name),
                    'value' => $this->extractValue($event),
                    'currency' => $this->extractCurrency($event),
                ],
                'userId' => $event->userId,
                'eventId' => $event->idempotencyKey ?? $this->generateEventId($event),
            ])->withHeaders([
                'Authorization' => 'Bearer ' . $this->accessToken,
                'LinkedIn-Version' => '202401',
            ]);
        } catch (\Throwable $e) {
            Log::warning('LinkedInTracker: event dispatch error', [
                'event' => $event->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    #[\Override]
    public function isEnabled(): bool
    {
        return $this->enabled && $this->partnerId !== '';
    }

    #[\Override]
    public function headScripts(): string
    {
        if (! $this->isEnabled()) {
            return '';
        }

        return <<<HTML
<script type="text/javascript">
_linkedin_partner_id = "{$this->partnerId}";
window._linkedin_data_partner_ids = window._linkedin_data_partner_ids || [];
window._linkedin_data_partner_ids.push(_linkedin_partner_id);
(function(l) { if (!l){window.lintrk = function(a,b){window.lintrk.q.push([a,b])}; window.lintrk.q=[]} var s = document.getElementsByTagName("script")[0]; var b = document.createElement("script"); b.type = "text/javascript"; b.async = true; b.src = "https://snap.licdn.com/li.lms-analytics/insight.min.js"; s.parentNode.insertBefore(b, s);})(window.lintrk);
</script>
<noscript><img height="1" width="1" style="display:none;" alt="" src="https://px.ads.linkedin.com/collect/?pid={$this->partnerId}&fmt=gif" /></noscript>
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

    /**
     * Get the LinkedIn Partner ID.
     */
    public function getPartnerId(): string
    {
        return $this->partnerId;
    }

    /**
     * Get the LinkedIn Conversion ID.
     */
    public function getConversionId(): string
    {
        return $this->conversionId;
    }

    /**
     * Map internal event names to LinkedIn standard conversion events.
     *
     * LinkedIn standard events: purchase, sign_up, add_to_cart,
     * generate_lead, complete_registration, submit_form, etc.
     */
    private function mapEventName(string $name): string
    {
        return match ($name) {
            'sign_up', 'register' => 'complete_registration',
            'purchase' => 'purchase',
            'add_to_cart' => 'add_to_cart',
            'begin_checkout' => 'purchase',
            'form_submit' => 'submit_form',
            'form_start' => 'generate_lead',
            'view_item' => 'view_product',
            'click' => 'click_link',
            'subscribe', 'subscription_created', 'start_trial' => 'purchase',
            'plan_upgrade' => 'purchase',
            'search' => 'search',
            default => 'other',
        };
    }

    /**
     * Extract monetary value from event parameters.
     */
    private function extractValue(AnalyticsEvent $event): float
    {
        return (float) ($event->params['value'] ?? $event->params['revenue'] ?? 0);
    }

    /**
     * Extract currency from event parameters.
     */
    private function extractCurrency(AnalyticsEvent $event): string
    {
        return (string) ($event->params['currency'] ?? 'USD');
    }

    /**
     * Generate a deterministic event ID for LinkedIn deduplication.
     */
    private function generateEventId(AnalyticsEvent $event): string
    {
        return hash('xxh128', $event->name . ':' . json_encode($event->params, JSON_THROW_ON_ERROR));
    }
}
