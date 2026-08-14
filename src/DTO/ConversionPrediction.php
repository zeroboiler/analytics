<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing a conversion probability prediction.
 *
 * Captures the predicted likelihood of a user converting based on their
 * behavioral event sequence, engagement score, and lifecycle stage.
 * Used by ConversionPredictor service for funnel optimization.
 *
 * @since 83.0.0
 */
final readonly class ConversionPrediction
{
    /**
     * Prediction confidence levels.
     */
    public const CONFIDENCE_LOW = 'low';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_HIGH = 'high';

    /**
     * Lifecycle stages affecting conversion probability.
     */
    public const STAGE_VISITOR = 'visitor';
    public const STAGE_LEAD = 'lead';
    public const STAGE_TRIAL = 'trial';
    public const STAGE_ACTIVE = 'active';
    public const STAGE_AT_RISK = 'at_risk';
    public const STAGE_CHURNED = 'churned';

    /**
     * @param  string  $identity  User ID or client ID
     * @param  string  $targetEvent  The conversion event being predicted (e.g., 'purchase', 'subscription_created')
     * @param  float  $probability  Predicted conversion probability (0.0–1.0)
     * @param  string  $confidence  Confidence level of the prediction (low, medium, high)
     * @param  string  $stage  Current lifecycle stage (visitor, lead, trial, active, at_risk, churned)
     * @param  int  $eventCount  Number of events analyzed for this prediction
     * @param  float  $engagementScore  Aggregate engagement score (0.0–100.0)
     * @param  list<string>  $contributingEvents  Events that positively contributed to the prediction
     * @param  list<string>  $missingSignals  High-value signals not yet observed (opportunity indicators)
     * @param  array<string, mixed>  $context  Additional prediction context
     */
    public function __construct(
        public string $identity,
        public string $targetEvent,
        public float $probability,
        public string $confidence,
        public string $stage,
        public int $eventCount = 0,
        public float $engagementScore = 0.0,
        public array $contributingEvents = [],
        public array $missingSignals = [],
        public array $context = [],
    ): void {}

    /**
     * Check if the user is likely to convert (>60% probability).
     */
    public function isLikely(): bool
    {
        return $this->probability >= 0.6;
    }

    /**
     * Check if the user is at risk of not converting (<30% probability).
     */
    public function isUnlikely(): bool
    {
        return $this->probability < 0.3;
    }

    /**
     * Get the probability as a percentage (0–100).
     */
    public function probabilityPercent(): float
    {
        return round($this->probability * 100.0, 1);
    }

    /**
     * Get tier label based on probability.
     */
    public function tier(): string
    {
        return match (true) {
            $this->probability >= 0.8 => 'hot',
            $this->probability >= 0.6 => 'warm',
            $this->probability >= 0.3 => 'lukewarm',
            default => 'cold',
        };
    }

    /**
     * Convert to array representation.
     *
     * @return array{identity: string, target_event: string, probability: float, probability_percent: float, confidence: string, stage: string, tier: string, is_likely: bool, is_unlikely: bool, event_count: int, engagement_score: float, contributing_events: list<string>, missing_signals: list<string>, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity,
            'target_event' => $this->targetEvent,
            'probability' => round($this->probability, 4),
            'probability_percent' => $this->probabilityPercent(),
            'confidence' => $this->confidence,
            'stage' => $this->stage,
            'tier' => $this->tier(),
            'is_likely' => $this->isLikely(),
            'is_unlikely' => $this->isUnlikely(),
            'event_count' => $this->eventCount,
            'engagement_score' => round($this->engagementScore, 2),
            'contributing_events' => $this->contributingEvents,
            'missing_signals' => $this->missingSignals,
            'context' => $this->context,
        ];
    }
}
