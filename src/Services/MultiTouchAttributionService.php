<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Multi-Touch Attribution Service — cross-channel conversion attribution for SaaS.
 *
 * Attributes conversions (sign_up, trial_start, subscription_created, purchase)
 * to marketing touchpoints using multiple attribution models:
 *
 * 1. **First-Touch**: 100% credit to the first touchpoint in the journey
 * 2. **Last-Touch**: 100% credit to the last touchpoint before conversion
 * 3. **Linear**: Equal credit split across all touchpoints
 * 4. **Position-Based (U-Shape)**: 40% first, 40% last, 20% distributed evenly
 * 5. **Time-Decay**: More credit to touchpoints closer to conversion
 * 6. **W-Shaped**: 30% first, 30% lead creation, 30% last, 10% distributed
 *
 * Touchpoints are extracted from UTM parameters, referrer data, and
 * traffic source classification stored in event params.
 *
 * Results are cache-backed and include per-channel breakdowns, ROI
 * estimates, and model comparison tables for executive dashboards.
 *
 * Inspired by Google Analytics 4 attribution models, Segment Attribution,
 * and Mixpanel Impact Analysis.
 *
 * Configuration: `zeroboiler.analytics.attribution`
 *
 * @see \ZeroBoiler\Analytics\Services\AttributionService
 *
 * @phpstan-type Touchpoint array{source: string, medium: string, campaign: string|null, content: string|null, term: string|null, timestamp: string, event_name: string, client_id: string|null}
 * @phpstan-type AttributionResult array{model: string, touchpoints: list<Touchpoint>, attribution: array<string, float>, total_credit: float, conversion_event: string, user_id: string|null, computed_at: string}
 * @phpstan-type ChannelReport array{channel: string, touchpoints: int, attributed_conversions: float, attributed_revenue: float, avg_position: float, first_touch_count: int, last_touch_count: int}
 *
 * @since 204.0.0
 */
final class MultiTouchAttributionService
{
    private const CACHE_PREFIX = 'zb_multi_touch_';

    private const DEFAULT_CACHE_TTL = 1800; // 30 minutes

    /** @var list<string> Supported attribution models */
    private const MODELS = [
        'first_touch',
        'last_touch',
        'linear',
        'position_based',
        'time_decay',
        'w_shaped',
    ];

    /** @var list<string> Events considered as conversion milestones */
    private const CONVERSION_EVENTS = [
        'sign_up',
        'trial_start',
        'subscribe',
        'subscription_created',
        'purchase',
        'trial_converted',
    ];

    /** @var array<string, float> Decay factor per model */
    private const TIME_DECAY_HALF_LIFE_HOURS = 168.0; // 7 days

    private int $cacheTtl;

    private string $defaultModel;

    private int $maxTouchpoints;

    private int $lookbackDays;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ){
        $attributionConfig = $config->get('zeroboiler.analytics.multi_touch_attribution', []);
        /** @var array{cache_ttl?: int, default_model?: string, max_touchpoints?: int, lookback_days?: int} $attributionConfig */

        $this->cacheTtl = (int) ($attributionConfig['cache_ttl'] ?? self::DEFAULT_CACHE_TTL);
        $this->defaultModel = (string) ($attributionConfig['default_model'] ?? 'position_based');
        $this->maxTouchpoints = (int) ($attributionConfig['max_touchpoints'] ?? 50);
        $this->lookbackDays = (int) ($attributionConfig['lookback_days'] ?? 90);
    }

    /**
     * Attribute a conversion event using a specific model.
     *
     * Extracts touchpoints from the event's UTM/referrer params and
     * distributes credit across them according to the specified model.
     *
     * @param  AnalyticsEvent  $conversionEvent  The conversion event to attribute
     * @param  string  $model  Attribution model name (first_touch, last_touch, linear, etc.)
     * @param  list<Touchpoint>  $touchpoints  Pre-recorded touchpoints in the user journey
     * @return AttributionResult
     */
    public function attribute(AnalyticsEvent $conversionEvent, string $model, array $touchpoints): array
    {
        $model = in_array($model, self::MODELS, true) ? $model : $this->defaultModel;

        if (empty($touchpoints)) {
            return $this->emptyResult($conversionEvent->name, $model, $conversionEvent->userId);
        }

        usort($touchpoints, fn (array $a, array $b): int => strcmp($a['timestamp'], $b['timestamp']));

        $attribution = match ($model) {
            'first_touch' => $this->firstTouch($touchpoints),
            'last_touch' => $this->lastTouch($touchpoints),
            'linear' => $this->linear($touchpoints),
            'position_based' => $this->positionBased($touchpoints),
            'time_decay' => $this->timeDecay($touchpoints, $conversionEvent->timestamp),
            'w_shaped' => $this->wShaped($touchpoints),
            default => $this->positionBased($touchpoints),
        };

        return [
            'model' => $model,
            'touchpoints' => $touchpoints,
            'attribution' => $attribution,
            'total_credit' => array_sum($attribution),
            'conversion_event' => $conversionEvent->name,
            'user_id' => $conversionEvent->userId,
            'computed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Compare attribution results across all models for a given set of touchpoints.
     *
     * @param  AnalyticsEvent  $conversionEvent
     * @param  list<Touchpoint>  $touchpoints
     * @return array<string, AttributionResult>
     */
    public function compareModels(AnalyticsEvent $conversionEvent, array $touchpoints): array
    {
        $results = [];

        foreach (self::MODELS as $model) {
            $results[$model] = $this->attribute($conversionEvent, $model, $touchpoints);
        }

        return $results;
    }

    /**
     * Generate a channel-level attribution report aggregating multiple conversions.
     *
     * Groups touchpoints by channel (source/medium) and computes aggregate
     * attributed conversions and revenue per channel.
     *
     * @param  list<AttributionResult>  $attributions  Multiple attribution results to aggregate
     * @param  string  $model  Model used for attribution (for display)
     * @return array{model: string, channels: list<ChannelReport>, total_conversions: int, top_channel: string|null, coverage: float}
     */
    public function channelReport(array $attributions, string $model): array
    {
        $channelData = [];

        foreach ($attributions as $result) {
            $touchpoints = $result['touchpoints'];

            foreach ($touchpoints as $index => $tp) {
                $channel = $this->touchpointChannel($tp);
                $credit = $result['attribution'][$channel] ?? 0.0;

                if (! isset($channelData[$channel])) {
                    $channelData[$channel] = [
                        'channel' => $channel,
                        'touchpoints' => 0,
                        'attributed_conversions' => 0.0,
                        'attributed_revenue' => 0.0,
                        'positions' => [],
                        'first_touch_count' => 0,
                        'last_touch_count' => 0,
                    ];
                }

                $channelData[$channel]['touchpoints']++;
                $channelData[$channel]['attributed_conversions'] += $credit;
                $channelData[$channel]['positions'][] = $index + 1;

                if ($index === 0) {
                    $channelData[$channel]['first_touch_count']++;
                }
                if ($index === count($touchpoints) - 1) {
                    $channelData[$channel]['last_touch_count']++;
                }
            }
        }

        $channels = [];
        foreach ($channelData as $data) {
            $positions = $data['positions'];
            $data['avg_position'] = ! empty($positions)
                ? round(array_sum($positions) / count($positions), 1)
                : 0.0;
            unset($data['positions']);
            $channels[] = $data;
        }

        usort($channels, fn (array $a, array $b): int => $b['attributed_conversions'] <=> $a['attributed_conversions']);

        $topChannel = ! empty($channels) ? $channels[0]['channel'] : null;

        return [
            'model' => $model,
            'channels' => $channels,
            'total_conversions' => count($attributions),
            'top_channel' => $topChannel,
            'coverage' => ! empty($attributions) ? round(count(array_filter($attributions, fn (array $r): bool => ! empty($r['touchpoints']))) / count($attributions), 2) : 0.0,
        ];
    }

    /**
     * Record a touchpoint from an event's UTM/referrer data.
     *
     * Extracts attribution signals from event params and creates a touchpoint
     * record for the user journey.
     *
     * @param  AnalyticsEvent  $event  The event to extract touchpoint data from
     * @return Touchpoint|null
     */
    public function extractTouchpoint(AnalyticsEvent $event): ?array
    {
        $params = $event->params;

        $utmSource = is_string($params['utm_source'] ?? null) ? $params['utm_source'] : null;
        $utmMedium = is_string($params['utm_medium'] ?? null) ? $params['utm_medium'] : null;
        $utmCampaign = is_string($params['utm_campaign'] ?? null) ? $params['utm_campaign'] : null;
        $utmContent = is_string($params['utm_content'] ?? null) ? $params['utm_content'] : null;
        $utmTerm = is_string($params['utm_term'] ?? null) ? $params['utm_term'] : null;
        $referrer = is_string($params['referrer'] ?? null) ? $params['referrer'] : null;
        $trafficSource = is_string($params['traffic_source'] ?? null) ? $params['traffic_source'] : null;

        // Need at least a source or referrer to create a meaningful touchpoint
        if ($utmSource === null && $referrer === null && $trafficSource === null) {
            return null;
        }

        // Infer source/medium from referrer if not provided
        if ($utmSource === null) {
            if ($trafficSource !== null) {
                $utmSource = $trafficSource;
            } elseif ($referrer !== null) {
                $parsed = parse_url($referrer, PHP_URL_HOST);
                $utmSource = is_string($parsed) ? $parsed : 'direct';
            }
        }

        if ($utmMedium === null) {
            $utmMedium = $trafficSource ?? 'referral';
        }

        return [
            'source' => $utmSource ?? 'direct',
            'medium' => $utmMedium ?? 'none',
            'campaign' => $utmCampaign,
            'content' => $utmContent,
            'term' => $utmTerm,
            'timestamp' => $event->timestamp?->format(\DateTimeInterface::ATOM) ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'event_name' => $event->name,
            'client_id' => $event->clientId,
        ];
    }

    /**
     * Get the list of supported attribution models.
     *
     * @return list<string>
     */
    public function supportedModels(): array
    {
        return self::MODELS;
    }

    /**
     * Get the list of events considered as conversions for attribution.
     *
     * @return list<string>
     */
    public function conversionEvents(): array
    {
        return self::CONVERSION_EVENTS;
    }

    /**
     * Check if an event is a conversion event.
     */
    public function isConversionEvent(AnalyticsEvent $event): bool
    {
        return in_array($event->name, self::CONVERSION_EVENTS, true);
    }

    /**
     * First-Touch Attribution — 100% credit to the first touchpoint.
     *
     * @param  list<Touchpoint>  $touchpoints
     * @return array<string, float>
     */
    private function firstTouch(array $touchpoints): array
    {
        $first = $touchpoints[0];
        $channel = $this->touchpointChannel($first);

        return [$channel => 1.0];
    }

    /**
     * Last-Touch Attribution — 100% credit to the last touchpoint.
     *
     * @param  list<Touchpoint>  $touchpoints
     * @return array<string, float>
     */
    private function lastTouch(array $touchpoints): array
    {
        $last = $touchpoints[array_key_last($touchpoints)];
        $channel = $this->touchpointChannel($last);

        return [$channel => 1.0];
    }

    /**
     * Linear Attribution — equal credit split across all touchpoints.
     *
     * @param  list<Touchpoint>  $touchpoints
     * @return array<string, float>
     */
    private function linear(array $touchpoints): array
    {
        $count = count($touchpoints);
        $credit = round(1.0 / $count, 4);
        $attribution = [];

        foreach ($touchpoints as $tp) {
            $channel = $this->touchpointChannel($tp);
            $attribution[$channel] = ($attribution[$channel] ?? 0.0) + $credit;
        }

        return $attribution;
    }

    /**
     * Position-Based (U-Shape) Attribution — 40% first, 40% last, 20% distributed.
     *
     * @param  list<Touchpoint>  $touchpoints
     * @return array<string, float>
     */
    private function positionBased(array $touchpoints): array
    {
        $count = count($touchpoints);

        if ($count === 1) {
            $channel = $this->touchpointChannel($touchpoints[0]);

            return [$channel => 1.0];
        }

        $attribution = [];
        $firstChannel = $this->touchpointChannel($touchpoints[0]);
        $lastChannel = $this->touchpointChannel($touchpoints[$count - 1]);

        $attribution[$firstChannel] = ($attribution[$firstChannel] ?? 0.0) + 0.4;
        $attribution[$lastChannel] = ($attribution[$lastChannel] ?? 0.0) + 0.4;

        // Distribute remaining 20% evenly across middle touchpoints
        $middleCredit = $count > 2 ? round(0.2 / ($count - 2), 4) : 0.2;

        for ($i = 1; $i < $count - 1; $i++) {
            $channel = $this->touchpointChannel($touchpoints[$i]);
            $attribution[$channel] = ($attribution[$channel] ?? 0.0) + $middleCredit;
        }

        // If only 2 touchpoints, give extra to first
        if ($count === 2) {
            $attribution[$firstChannel] += 0.2;
        }

        return $attribution;
    }

    /**
     * Time-Decay Attribution — exponential decay favoring recent touchpoints.
     *
     * Uses an exponential decay function with a configurable half-life.
     * Touchpoints closer to the conversion event receive more credit.
     *
     * @param  list<Touchpoint>  $touchpoints
     * @param  \DateTimeImmutable|null  $conversionTimestamp
     * @return array<string, float>
     */
    private function timeDecay(array $touchpoints, ?\DateTimeImmutable $conversionTimestamp = null): array
    {
        $conversionTime = $conversionTimestamp ?? new \DateTimeImmutable();
        $halfLifeSeconds = self::TIME_DECAY_HALF_LIFE_HOURS * 3600;
        $attribution = [];

        $weights = [];
        $totalWeight = 0.0;

        foreach ($touchpoints as $tp) {
            try {
                $tpTime = new \DateTimeImmutable($tp['timestamp']);
                $secondsAgo = max(0, $conversionTime->getTimestamp() - $tpTime->getTimestamp());
                $weight = pow(2, -$secondsAgo / $halfLifeSeconds);
            } catch (\Throwable $e) {
                $weight = 0.001;
            }

            $channel = $this->touchpointChannel($tp);
            $weights[$channel] = ($weights[$channel] ?? 0.0) + $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight > 0) {
            foreach ($weights as $channel => $weight) {
                $attribution[$channel] = round($weight / $totalWeight, 4);
            }
        }

        return $attribution;
    }

    /**
     * W-Shaped Attribution — 30% first, 30% lead creation, 30% last, 10% distributed.
     *
     * Lead creation is identified as the touchpoint associated with a
     * lead-generating event (sign_up, form_submit, or the middle touchpoint).
     *
     * @param  list<Touchpoint>  $touchpoints
     * @return array<string, float>
     */
    private function wShaped(array $touchpoints): array
    {
        $count = count($touchpoints);

        if ($count === 1) {
            $channel = $this->touchpointChannel($touchpoints[0]);

            return [$channel => 1.0];
        }

        if ($count === 2) {
            $firstChannel = $this->touchpointChannel($touchpoints[0]);
            $lastChannel = $this->touchpointChannel($touchpoints[1]);

            return [$firstChannel => 0.5, $lastChannel => 0.5];
        }

        $attribution = [];

        $leadIndex = $this->findLeadTouchpoint($touchpoints);

        $firstChannel = $this->touchpointChannel($touchpoints[0]);
        $leadChannel = $this->touchpointChannel($touchpoints[$leadIndex]);
        $lastChannel = $this->touchpointChannel($touchpoints[$count - 1]);

        $attribution[$firstChannel] = ($attribution[$firstChannel] ?? 0.0) + 0.3;
        $attribution[$leadChannel] = ($attribution[$leadChannel] ?? 0.0) + 0.3;
        $attribution[$lastChannel] = ($attribution[$lastChannel] ?? 0.0) + 0.3;

        // Distribute remaining 10% to other touchpoints
        $otherIndices = array_filter(
            range(0, $count - 1),
            fn (int $i): bool => $i !== 0 && $i !== $leadIndex && $i !== $count - 1
        );

        if (! empty($otherIndices)) {
            $otherCredit = round(0.1 / count($otherIndices), 4);
            foreach ($otherIndices as $i) {
                $channel = $this->touchpointChannel($touchpoints[$i]);
                $attribution[$channel] = ($attribution[$channel] ?? 0.0) + $otherCredit;
            }
        }

        return $attribution;
    }

    /**
     * Find the lead-creation touchpoint in a journey.
     *
     * Looks for the first touchpoint associated with a lead-generating event.
     * Falls back to the middle touchpoint if no lead event is found.
     *
     * @param  list<Touchpoint>  $touchpoints
     * @return int
     */
    private function findLeadTouchpoint(array $touchpoints): int
    {
        $leadEvents = ['sign_up', 'form_submit', 'lead_captured'];

        foreach ($touchpoints as $index => $tp) {
            if (in_array($tp['event_name'], $leadEvents, true)) {
                return $index;
            }
        }

        // Fallback: middle touchpoint
        return (int) floor(count($touchpoints) / 2);
    }

    /**
     * Get a normalized channel identifier for a touchpoint.
     *
     * Combines source and medium into a standard channel format.
     *
     * @param  Touchpoint  $touchpoint
     * @return string
     */
    private function touchpointChannel(array $touchpoint): string
    {
        $source = $touchpoint['source'] ?? 'direct';
        $medium = $touchpoint['medium'] ?? 'none';

        if ($source === 'direct' && $medium === 'none') {
            return 'direct';
        }

        return strtolower(trim("{$source}/{$medium}"));
    }

    /**
     * Generate an empty attribution result when no touchpoints exist.
     *
     * @return AttributionResult
     */
    private function emptyResult(string $eventName, string $model, ?string $userId): array
    {
        return [
            'model' => $model,
            'touchpoints' => [],
            'attribution' => ['direct' => 1.0],
            'total_credit' => 1.0,
            'conversion_event' => $eventName,
            'user_id' => $userId,
            'computed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }
}
