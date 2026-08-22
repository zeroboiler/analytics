<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing the Signal-to-Noise Ratio (SNR) result for a single event.
 *
 * SNR measures how much actionable insight an event provides relative to its
 * operational cost (dispatch volume × per-event cost). Events with high SNR
 * are valuable; events with low SNR are noise candidates for pruning.
 *
 * @readonly
 *
 * @since 220.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\EventSNRCalculatorService
 */
final class EventSNRResult
{
    /**
     * Create a new EventSNRResult.
     *
     * @param  string  $eventName  Canonical event name
     * @param  string  $category  Event category (ecommerce, saas, engagement, etc.)
     * @param  int  $dispatchCount  Total dispatch count in the analysis window
     * @param  float  $dispatchShare  Percentage of total dispatch volume (0-100)
     * @param  float  $actionabilityScore  How actionable is this event's data (0-100)
     * @param  float  $correlationScore  Correlation with conversion/retention outcomes (0-100)
     * @param  float  $uniquenessScore  How unique is this event vs. others in its category (0-100)
     * @param  float  $costPerDispatch  Estimated cost per event dispatch
     * @param  float  $totalCost  Total cost = dispatchCount × costPerDispatch
     * @param  float  $snr  Signal-to-Noise Ratio (0-100)
     * @param  string  $grade  Letter grade (A+, A, B, C, D, F)
     * @param  string  $verdict  Classification: 'signal', 'moderate', 'noise_candidate', 'noise'
     */
    public function __construct(
        public readonly string $eventName,
        public readonly string $category,
        public readonly int $dispatchCount,
        public readonly float $dispatchShare,
        public readonly float $actionabilityScore,
        public readonly float $correlationScore,
        public readonly float $uniquenessScore,
        public readonly float $costPerDispatch,
        public readonly float $totalCost,
        public readonly float $snr,
        public readonly string $grade,
        public readonly string $verdict,
    ){}

    /**
     * Convert to array for API/CLI output.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_name' => $this->eventName,
            'category' => $this->category,
            'dispatch_count' => $this->dispatchCount,
            'dispatch_share' => round($this->dispatchShare, 2),
            'actionability_score' => round($this->actionabilityScore, 2),
            'correlation_score' => round($this->correlationScore, 2),
            'uniqueness_score' => round($this->uniquenessScore, 2),
            'cost_per_dispatch' => round($this->costPerDispatch, 6),
            'total_cost' => round($this->totalCost, 4),
            'snr' => round($this->snr, 2),
            'grade' => $this->grade,
            'verdict' => $this->verdict,
        ];
    }

    /**
     * Create from array (for cache deserialization).
     *
     * @param  array<string, mixed>  $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            eventName: (string) $data['event_name'],
            category: (string) $data['category'],
            dispatchCount: (int) $data['dispatch_count'],
            dispatchShare: (float) $data['dispatch_share'],
            actionabilityScore: (float) $data['actionability_score'],
            correlationScore: (float) $data['correlation_score'],
            uniquenessScore: (float) $data['uniqueness_score'],
            costPerDispatch: (float) $data['cost_per_dispatch'],
            totalCost: (float) $data['total_cost'],
            snr: (float) $data['snr'],
            grade: (string) $data['grade'],
            verdict: (string) $data['verdict'],
        );
    }

    /**
     * Check if this event is classified as noise.
     */
    public function isNoise(): bool
    {
        return $this->verdict === 'noise';
    }

    /**
     * Check if this event is a noise candidate (low SNR but not yet confirmed noise).
     */
    public function isNoiseCandidate(): bool
    {
        return $this->verdict === 'noise_candidate';
    }

    /**
     * Check if this event provides strong signal.
     */
    public function isSignal(): bool
    {
        return $this->verdict === 'signal';
    }

    /**
     * Cost-efficiency ratio: SNR per dollar spent.
     */
    public function costEfficiency(): float
    {
        if ($this->totalCost <= 0.0) {
            return 0.0;
        }

        return $this->snr / $this->totalCost;
    }
}
