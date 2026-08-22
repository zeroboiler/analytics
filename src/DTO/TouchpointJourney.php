<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing a user's touchpoint journey.
 *
 * Captures the chronological sequence of interactions a user has
 * before and after a conversion event. Used by TouchpointAggregator
 * for attribution modeling and journey analysis.
 *
 * @since 83.0.0
 */
final readonly class TouchpointJourney
{
    /**
     * @param  string  $identity  User ID or client ID
     * @param  list<array{timestamp: \DateTimeImmutable, event: string, source: string|null, campaign: string|null, channel: string|null, page: string|null}>  $touchpoints  Chronological list of touchpoints
     * @param  string|null  $conversionEvent  The conversion event name (if converted)
     * @param  \DateTimeImmutable|null  $convertedAt  When the conversion occurred
     * @param  int  $totalTouchpoints  Total number of touchpoints in the journey
     * @param  float|null  $timeToConversion  Seconds from first touch to conversion
     * @param  array<string, mixed>  $metadata  Additional journey metadata
     */
    public function __construct(
        public string $identity,
        public array $touchpoints,
        public ?string $conversionEvent = null,
        public ?\DateTimeImmutable $convertedAt = null,
        public int $totalTouchpoints = 0,
        public ?float $timeToConversion = null,
        public array $metadata = [],
    ){
        $this->totalTouchpoints = count($this->touchpoints);
    }

    /**
     * Get the first touchpoint in the journey.
     *
     * @return array{timestamp: \DateTimeImmutable, event: string, source: string|null, campaign: string|null, channel: string|null, page: string|null}|null
     */
    public function firstTouch(): ?array
    {
        return $this->touchpoints[0] ?? null;
    }

    /**
     * Get the last touchpoint in the journey.
     *
     * @return array{timestamp: \DateTimeImmutable, event: string, source: string|null, campaign: string|null, channel: string|null, page: string|null}|null
     */
    public function lastTouch(): ?array
    {
        return $this->touchpoints[count($this->touchpoints) - 1] ?? null;
    }

    /**
     * Check if this journey resulted in a conversion.
     */
    public function isConverted(): bool
    {
        return $this->conversionEvent !== null && $this->convertedAt !== null;
    }

    /**
     * Get unique channels visited during this journey.
     *
     * @return list<string>
     */
    public function uniqueChannels(): array
    {
        $channels = array_filter(array_map(
            fn(array $tp): ?string => $tp['channel'] ?? null,
            $this->touchpoints,
        ));

        return array_values(array_unique(array_filter($channels)));
    }

    /**
     * Convert journey to array representation.
     *
     * @return array{identity: string, touchpoints: list<array<string, mixed>>, conversion_event: string|null, converted_at: string|null, total_touchpoints: int, time_to_conversion: float|null, unique_channels: list<string>, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity,
            'touchpoints' => array_map(fn(array $tp): array => [
                'timestamp' => $tp['timestamp']->format('c'),
                'event' => $tp['event'],
                'source' => $tp['source'],
                'campaign' => $tp['campaign'],
                'channel' => $tp['channel'],
                'page' => $tp['page'],
            ], $this->touchpoints),
            'conversion_event' => $this->conversionEvent,
            'converted_at' => $this->convertedAt?->format('c'),
            'total_touchpoints' => $this->totalTouchpoints,
            'time_to_conversion' => $this->timeToConversion,
            'unique_channels' => $this->uniqueChannels(),
            'metadata' => $this->metadata,
        ];
    }
}
