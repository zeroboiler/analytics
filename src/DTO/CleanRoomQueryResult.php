<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Clean Room Query Result — immutable value object for privacy-safe query outcomes.
 *
 * Represents the result of a clean room aggregate query, including
 * metadata about privacy protections applied (k-anonymity suppression,
 * differential privacy noise).
 *
 * Results never contain raw event data or PII — only aggregate statistics
 * that have passed through privacy filtering.
 *
 * @since 198.0.0
 */
final readonly class CleanRoomQueryResult
{
    /**
     * @param  string  $agreementId  Agreement identifier
     * @param  string  $queryType  Type of aggregate query
     * @param  array<string, mixed>  $result  Aggregate result data
     * @param  bool  $kAnonymityApplied  Whether k-anonymity filtering was applied
     * @param  bool  $privacyNoiseApplied  Whether differential privacy noise was added
     * @param  int|null  $kAnonymityThreshold  k-anonymity threshold used
     * @param  float|null  $privacyEpsilon  Privacy budget (epsilon) used
     * @param  string|null  $privacyMechanism  Privacy mechanism (laplace, gaussian)
     * @param  string  $computedAt  ISO 8601 computation timestamp
     * @param  int  $participantCount  Number of participants whose sketches were used
     */
    public function __construct(
        public string $agreementId,
        public string $queryType,
        public array $result,
        public bool $kAnonymityApplied,
        public bool $privacyNoiseApplied,
        public ?int $kAnonymityThreshold = null,
        public ?float $privacyEpsilon = null,
        public ?string $privacyMechanism = null,
        public string $computedAt = '',
        public int $participantCount = 0,
    ){}

    /**
     * Check if any privacy protection was applied to this result.
     */
    public function hasPrivacyProtection(): bool
    {
        return $this->kAnonymityApplied || $this->privacyNoiseApplied;
    }

    /**
     * Get the privacy protection summary.
     *
     * @return array{protections: list<string>, k_anonymity: int|null, epsilon: float|null, mechanism: string|null}
     */
    public function privacySummary(): array
    {
        $protections = [];
        if ($this->kAnonymityApplied) {
            $protections[] = 'k-anonymity';
        }
        if ($this->privacyNoiseApplied) {
            $protections[] = 'differential_privacy';
        }

        return [
            'protections' => $protections,
            'k_anonymity' => $this->kAnonymityThreshold,
            'epsilon' => $this->privacyEpsilon,
            'mechanism' => $this->privacyMechanism,
        ];
    }

    /**
     * Serialize to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'agreement_id' => $this->agreementId,
            'query_type' => $this->queryType,
            'result' => $this->result,
            'k_anonymity_applied' => $this->kAnonymityApplied,
            'privacy_noise_applied' => $this->privacyNoiseApplied,
            'privacy_protection' => $this->hasPrivacyProtection(),
            'privacy_summary' => $this->privacySummary(),
            'k_anonymity_threshold' => $this->kAnonymityThreshold,
            'privacy_epsilon' => $this->privacyEpsilon,
            'privacy_mechanism' => $this->privacyMechanism,
            'computed_at' => $this->computedAt,
            'participant_count' => $this->participantCount,
        ];
    }
}
