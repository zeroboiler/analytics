<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Campaign ROI analytics service for SaaS marketing attribution.
 *
 * Tracks campaign spend data, correlates it with conversion events,
 * and computes return on investment (ROI), cost per acquisition (CPA),
 * and campaign efficiency metrics. Designed for multi-campaign,
 * multi-channel marketing attribution in SaaS products.
 *
 * Config-driven via `zeroboiler.analytics.campaign_roi`.
 *
 * @see \ZeroBoiler\Analytics\Services\UTMAttributionService
 *
 * @since 1.0.0
 */
final class CampaignRoiService
{
    private bool $enabled;

    private int $cacheTtl;

    /** @var array<string, array{spend: float, currency: string, impressions?: int, clicks?: int, channel: string, start_date: string|null, end_date: string|null}> */
    private array $campaignSpend = [];

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $roiConfig = $config->get('zeroboiler.analytics.campaign_roi', []);
        /** @var array{enabled?: bool, cache_ttl?: int} $roiConfig */

        $this->enabled = (bool) ($roiConfig['enabled'] ?? false);
        $this->cacheTtl = (int) ($roiConfig['cache_ttl'] ?? 86400);
    }

    /**
     * Register campaign spend data for ROI tracking.
     *
     * Stores campaign spend, impressions, and click data in cache for
     * correlation with conversion events tracked via UTM parameters.
     *
     * @param  string  $campaignId  Unique campaign identifier (matches UTM campaign or custom ID)
     * @param  float  $spend  Total spend amount
     * @param  string  $currency  ISO 4217 currency code
     * @param  string  $channel  Marketing channel (google, meta, linkedin, email, referral, organic)
     * @param  int|null  $impressions  Total impressions served
     * @param  int|null  $clicks  Total clicks received
     * @param  string|null  $startDate  Campaign start date (Y-m-d)
     * @param  string|null  $endDate  Campaign end date (Y-m-d)
     */
    public function registerSpend(
        string $campaignId,
        float $spend,
        string $currency = 'USD',
        string $channel = 'unknown',
        ?int $impressions = null,
        ?int $clicks = null,
        ?string $startDate = null,
        ?string $endDate = null,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $this->campaignSpend[$campaignId] = [
            'spend' => $spend,
            'currency' => $currency,
            'channel' => $channel,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];

        $this->persistSpend();
    }

    /**
     * Record a conversion attributed to a campaign.
     *
     * Called when a tracked user converts (sign_up, subscription, purchase, etc.).
     * Increments the conversion counter and total value for the campaign.
     *
     * @param  string  $campaignId  Campaign identifier (typically from UTM)
     * @param  float  $value  Conversion value (e.g., subscription amount, LTV)
     * @param  string  $event  Conversion event name (sign_up, purchase, etc.)
     */
    public function recordConversion(string $campaignId, float $value, string $event = 'conversion'): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->loadSpend();

        if (! isset($this->campaignSpend[$campaignId])) {
            $this->campaignSpend[$campaignId] = [
                'spend' => 0.0,
                'currency' => 'USD',
                'channel' => 'unknown',
                'conversions' => 0,
                'conversion_value' => 0.0,
                'conversion_events' => [],
            ];
        }

        $data = &$this->campaignSpend[$campaignId];
        $data['conversions'] = ($data['conversions'] ?? 0) + 1;
        $data['conversion_value'] = ($data['conversion_value'] ?? 0.0) + $value;

        if (! isset($data['conversion_events'])) {
            $data['conversion_events'] = [];
        }

        $data['conversion_events'][] = [
            'event' => $event,
            'value' => $value,
            'timestamp' => date('c'),
        ];

        $this->persistSpend();
    }

    /**
     * Compute ROI for a specific campaign.
     *
     * ROI = (conversion_value - spend) / spend × 100
     *
     * @param  string  $campaignId
     * @return array{campaign_id: string, spend: float, currency: string, conversions: int, conversion_value: float, roi: float, roas: float, cpa: float, channel: string|null}
     */
    public function roi(string $campaignId): array
    {
        $this->loadSpend();

        $data = $this->campaignSpend[$campaignId] ?? null;

        if ($data === null) {
            return [
                'campaign_id' => $campaignId,
                'spend' => 0.0,
                'currency' => 'USD',
                'conversions' => 0,
                'conversion_value' => 0.0,
                'roi' => 0.0,
                'roas' => 0.0,
                'cpa' => 0.0,
                'channel' => null,
            ];
        }

        $spend = (float) ($data['spend'] ?? 0);
        $conversions = (int) ($data['conversions'] ?? 0);
        $conversionValue = (float) ($data['conversion_value'] ?? 0);

        $roi = $spend > 0 ? (($conversionValue - $spend) / $spend) * 100 : 0.0;
        $roas = $spend > 0 ? $conversionValue / $spend : 0.0;
        $cpa = $conversions > 0 ? $spend / $conversions : 0.0;

        return [
            'campaign_id' => $campaignId,
            'spend' => $spend,
            'currency' => (string) ($data['currency'] ?? 'USD'),
            'conversions' => $conversions,
            'conversion_value' => $conversionValue,
            'roi' => round($roi, 2),
            'roas' => round($roas, 2),
            'cpa' => round($cpa, 2),
            'channel' => $data['channel'] ?? null,
        ];
    }

    /**
     * Get ROI summary across all registered campaigns.
     *
     * @return array{total_campaigns: int, total_spend: float, total_conversions: int, total_conversion_value: float, overall_roi: float, overall_roas: float, average_cpa: float, by_channel: array<string, array{spend: float, conversions: int, conversion_value: float, roi: float}>}
     */
    public function summary(): array
    {
        $this->loadSpend();

        $totalSpend = 0.0;
        $totalConversions = 0;
        $totalConversionValue = 0.0;
        $byChannel = [];

        foreach ($this->campaignSpend as $campaignId => $data) {
            $spend = (float) ($data['spend'] ?? 0);
            $conversions = (int) ($data['conversions'] ?? 0);
            $conversionValue = (float) ($data['conversion_value'] ?? 0);
            $channel = (string) ($data['channel'] ?? 'unknown');

            $totalSpend += $spend;
            $totalConversions += $conversions;
            $totalConversionValue += $conversionValue;

            if (! isset($byChannel[$channel])) {
                $byChannel[$channel] = [
                    'spend' => 0.0,
                    'conversions' => 0,
                    'conversion_value' => 0.0,
                    'roi' => 0.0,
                ];
            }

            $byChannel[$channel]['spend'] += $spend;
            $byChannel[$channel]['conversions'] += $conversions;
            $byChannel[$channel]['conversion_value'] += $conversionValue;

            if ($byChannel[$channel]['spend'] > 0) {
                $byChannel[$channel]['roi'] = round(
                    (($byChannel[$channel]['conversion_value'] - $byChannel[$channel]['spend']) / $byChannel[$channel]['spend']) * 100,
                    2,
                );
            }
        }

        $overallRoi = $totalSpend > 0 ? (($totalConversionValue - $totalSpend) / $totalSpend) * 100 : 0.0;
        $overallRoas = $totalSpend > 0 ? $totalConversionValue / $totalSpend : 0.0;
        $averageCpa = $totalConversions > 0 ? $totalSpend / $totalConversions : 0.0;

        return [
            'total_campaigns' => count($this->campaignSpend),
            'total_spend' => round($totalSpend, 2),
            'total_conversions' => $totalConversions,
            'total_conversion_value' => round($totalConversionValue, 2),
            'overall_roi' => round($overallRoi, 2),
            'overall_roas' => round($overallRoas, 2),
            'average_cpa' => round($averageCpa, 2),
            'by_channel' => $byChannel,
        ];
    }

    /**
     * Get top-performing campaigns by ROI.
     *
     * @param  int  $limit  Number of campaigns to return
     * @return list<array{campaign_id: string, spend: float, conversions: int, roi: float, roas: float}>
     */
    public function topCampaigns(int $limit = 10): array
    {
        $this->loadSpend();

        $campaigns = [];

        foreach ($this->campaignSpend as $campaignId => $data) {
            $roi = $this->roi($campaignId);
            $campaigns[] = [
                'campaign_id' => $campaignId,
                'spend' => $roi['spend'],
                'conversions' => $roi['conversions'],
                'roi' => $roi['roi'],
                'roas' => $roi['roas'],
            ];
        }

        usort($campaigns, fn (array $a, array $b): int => $b['roi'] <=> $a['roi']);

        return array_slice($campaigns, 0, $limit);
    }

    /**
     * Get campaigns grouped by channel.
     *
     * @return array<string, list<array{campaign_id: string, spend: float, conversions: int, roi: float}>>
     */
    public function byChannel(): array
    {
        $this->loadSpend();

        $channels = [];

        foreach ($this->campaignSpend as $campaignId => $data) {
            $channel = (string) ($data['channel'] ?? 'unknown');
            $roi = $this->roi($campaignId);

            if (! isset($channels[$channel])) {
                $channels[$channel] = [];
            }

            $channels[$channel][] = [
                'campaign_id' => $campaignId,
                'spend' => $roi['spend'],
                'conversions' => $roi['conversions'],
                'roi' => $roi['roi'],
            ];
        }

        return $channels;
    }

    /**
     * Remove a campaign from tracking.
     */
    public function removeCampaign(string $campaignId): void
    {
        $this->loadSpend();
        unset($this->campaignSpend[$campaignId]);
        $this->persistSpend();
    }

    /**
     * Check if the campaign ROI service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the total number of tracked campaigns.
     */
    public function campaignCount(): int
    {
        $this->loadSpend();

        return count($this->campaignSpend);
    }

    /**
     * Load campaign spend data from cache.
     */
    private function loadSpend(): void
    {
        if ($this->campaignSpend !== []) {
            return;
        }

        try {
            $cached = $this->cache->get('zb_analytics_campaign_roi');
            $this->campaignSpend = is_array($cached) ? $cached : [];
        } catch (\Throwable) {
            $this->campaignSpend = [];
        }
    }

    /**
     * Persist campaign spend data to cache.
     */
    private function persistSpend(): void
    {
        try {
            $this->cache->put('zb_analytics_campaign_roi', $this->campaignSpend, $this->cacheTtl);
        } catch (\Throwable) {
            // Cache unavailable — data stays in-memory for this request
        }
    }
}
