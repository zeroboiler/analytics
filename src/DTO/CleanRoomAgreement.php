<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Clean Room Agreement — immutable value object for privacy data clean room contracts.
 *
 * Represents a bilateral or multilateral agreement between participants
 * to share privacy-safe aggregate analytics data within a clean room.
 *
 * Agreements define:
 * - Which participants are involved
 * - What data dimensions can be shared
 * - Which aggregate query types are permitted
 * - Time-to-live and k-anonymity constraints
 * - Revocation status and audit metadata
 *
 * @since 198.0.0
 */
final readonly class CleanRoomAgreement
{
    /**
     * @param  string  $agreementId  Unique agreement identifier
     * @param  list<string>  $participants  Participant identifiers
     * @param  list<string>  $scope  Allowed data scope (event_counts, cohorts, etc.)
     * @param  list<string>  $dimensions  Allowed aggregate dimensions
     * @param  list<string>  $allowedAggregations  Allowed query types (count, sum, avg, cohort_overlap, frequency, funnel, histogram)
     * @param  string  $createdAt  ISO 8601 creation timestamp
     * @param  string  $expiresAt  ISO 8601 expiration timestamp
     * @param  string  $status  Agreement status (active, revoked, expired)
     * @param  int  $kAnonymity  k-anonymity threshold for result filtering
     * @param  string|null  $revokedAt  ISO 8601 revocation timestamp (null if active)
     */
    public function __construct(
        public string $agreementId,
        public array $participants,
        public array $scope,
        public array $dimensions,
        public array $allowedAggregations,
        public string $createdAt,
        public string $expiresAt,
        public string $status,
        public int $kAnonymity,
        public ?string $revokedAt = null,
    ): void {}

    /**
     * Check if the agreement is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the agreement is expired.
     */
    public function isExpired(): bool
    {
        return $this->status === 'expired'
            || strtotime($this->expiresAt) < time();
    }

    /**
     * Check if the agreement is revoked.
     */
    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }

    /**
     * Check if a participant is part of this agreement.
     */
    public function hasParticipant(string $participantId): bool
    {
        return in_array($participantId, $this->participants, true);
    }

    /**
     * Check if a query type is allowed by this agreement.
     */
    public function allowsAggregation(string $type): bool
    {
        return in_array($type, $this->allowedAggregations, true);
    }

    /**
     * Check if a dimension is within the agreement scope.
     */
    public function allowsDimension(string $dimension): bool
    {
        return in_array($dimension, $this->dimensions, true);
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
            'participants' => $this->participants,
            'scope' => $this->scope,
            'dimensions' => $this->dimensions,
            'allowed_aggregations' => $this->allowedAggregations,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            'status' => $this->status,
            'k_anonymity' => $this->kAnonymity,
            'revoked_at' => $this->revokedAt,
            'is_active' => $this->isActive(),
            'is_expired' => $this->isExpired(),
            'is_revoked' => $this->isRevoked(),
        ];
    }

    /**
     * Create from array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            agreementId: (string) ($data['agreement_id'] ?? $data['agreementId'] ?? ''),
            participants: (array) ($data['participants'] ?? []),
            scope: (array) ($data['scope'] ?? []),
            dimensions: (array) ($data['dimensions'] ?? []),
            allowedAggregations: (array) ($data['allowed_aggregations'] ?? $data['allowedAggregations'] ?? []),
            createdAt: (string) ($data['created_at'] ?? $data['createdAt'] ?? ''),
            expiresAt: (string) ($data['expires_at'] ?? $data['expiresAt'] ?? ''),
            status: (string) ($data['status'] ?? 'active'),
            kAnonymity: (int) ($data['k_anonymity'] ?? $data['kAnonymity'] ?? 5),
            revokedAt: isset($data['revoked_at']) || isset($data['revokedAt'])
                ? (string) ($data['revoked_at'] ?? $data['revokedAt'])
                : null,
        );
    }
}
