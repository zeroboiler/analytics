<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing a pruning recommendation for a low-SNR event.
 *
 * Each recommendation includes the event to prune, the rationale, estimated
 * savings, and suggested alternatives or consolidation options.
 *
 * @readonly
 *
 * @since 220.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\EventPruningAdvisorService
 */
final class EventPruningRecommendation
{
    /**
     * Create a new EventPruningRecommendation.
     *
     * @param  string  $eventName  Canonical event name to prune
     * @param  string  $category  Event category
     * @param  string  $action  Recommended action: 'remove', 'reduce_frequency', 'merge_with', 'sample_only'
     * @param  string  $rationale  Human-readable explanation of why this event should be pruned
     * @param  float  $currentCost  Current monthly cost of this event
     * @param  float  $estimatedSavings  Estimated monthly savings from pruning
     * @param  float  $snr  Current SNR score of the event
     * @param  string|null  $mergeTarget  If action is 'merge_with', the target event name
     * @param  int|null  $suggestedSampleRate  If action is 'sample_only', suggested sample rate (0-100%)
     * @param  string  $priority  Priority: 'high' (immediate savings), 'medium', 'low' (marginal savings)
     * @param  list<string>  $alternatives  Alternative events that provide similar or better signal
     */
    public function __construct(
        public readonly string $eventName,
        public readonly string $category,
        public readonly string $action,
        public readonly string $rationale,
        public readonly float $currentCost,
        public readonly float $estimatedSavings,
        public readonly float $snr,
        public readonly ?string $mergeTarget = null,
        public readonly ?int $suggestedSampleRate = null,
        public readonly string $priority = 'medium',
        public readonly array $alternatives = [],
    ) {}

    /**
     * Convert to array for API/CLI output.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'event_name' => $this->eventName,
            'category' => $this->category,
            'action' => $this->action,
            'rationale' => $this->rationale,
            'current_cost' => round($this->currentCost, 4),
            'estimated_savings' => round($this->estimatedSavings, 4),
            'snr' => round($this->snr, 2),
            'priority' => $this->priority,
            'alternatives' => $this->alternatives,
        ];

        if ($this->mergeTarget !== null) {
            $result['merge_target'] = $this->mergeTarget;
        }

        if ($this->suggestedSampleRate !== null) {
            $result['suggested_sample_rate'] = $this->suggestedSampleRate;
        }

        return $result;
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
            action: (string) $data['action'],
            rationale: (string) $data['rationale'],
            currentCost: (float) $data['current_cost'],
            estimatedSavings: (float) $data['estimated_savings'],
            snr: (float) $data['snr'],
            mergeTarget: isset($data['merge_target']) ? (string) $data['merge_target'] : null,
            suggestedSampleRate: isset($data['suggested_sample_rate']) ? (int) $data['suggested_sample_rate'] : null,
            priority: (string) ($data['priority'] ?? 'medium'),
            alternatives: (array) ($data['alternatives'] ?? []),
        );
    }

    /**
     * Check if this is a high-priority recommendation.
     */
    public function isHighPriority(): bool
    {
        return $this->priority === 'high';
    }

    /**
     * Savings percentage relative to current cost.
     */
    public function savingsPercentage(): float
    {
        if ($this->currentCost <= 0.0) {
            return 0.0;
        }

        return ($this->estimatedSavings / $this->currentCost) * 100.0;
    }
}
