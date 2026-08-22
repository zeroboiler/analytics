<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Immutable DTO representing a single cell in the revenue cohort matrix.
 *
 * Tracks MRR contribution for a specific cohort during a specific period.
 * Used by RevenueCohortMatrix for retention analysis and revenue flow visualization.
 *
 * @since 83.0.0
 */
final readonly class RevenueCohortCell
{
    /**
     * @param  string  $cohortId  Cohort identifier (e.g., '2026-W32', '2026-08')
     * @param  int  $periodOffset  Period offset from cohort start (0 = start, 1 = next period, etc.)
     * @param  float  $mrr  Monthly recurring revenue in this period
     * @param  int  $activeUsers  Number of active users in this period
     * @param  int  $cohortSize  Original cohort size (users who started in this cohort)
     * @param  float  $retentionRate  Retention percentage (0.0–100.0)
     * @param  float  $mrrPerUser  Average MRR per active user
     * @param  float  $expansionMrr  MRR from expansion (upsells, plan upgrades)
     * @param  float  $contractionMrr  MRR from contraction (downgrades)
     * @param  float  $churnMrr  MRR lost to churn
     * @param  float  $netRetentionRate  Net revenue retention percentage
     */
    public function __construct(
        public string $cohortId,
        public int $periodOffset,
        public float $mrr = 0.0,
        public int $activeUsers = 0,
        public int $cohortSize = 0,
        public float $retentionRate = 0.0,
        public float $mrrPerUser = 0.0,
        public float $expansionMrr = 0.0,
        public float $contractionMrr = 0.0,
        public float $churnMrr = 0.0,
        public float $netRetentionRate = 0.0,
    ){}

    /**
     * Compute retention rate from active users and cohort size.
     *
     * @return float Retention rate as percentage (0.0–100.0)
     */
    public static function computeRetentionRate(int $activeUsers, int $cohortSize): float
    {
        if ($cohortSize <= 0) {
            return 0.0;
        }

        return round(($activeUsers / $cohortSize) * 100.0, 2);
    }

    /**
     * Check if this cell represents a churned period (no active users).
     */
    public function isChurned(): bool
    {
        return $this->activeUsers === 0 && $this->periodOffset > 0;
    }

    /**
     * Check if this is a growing period (expansion > contraction + churn).
     */
    public function isGrowing(): bool
    {
        return $this->expansionMrr > ($this->contractionMrr + $this->churnMrr);
    }

    /**
     * Convert to array representation.
     *
     * @return array{cohort_id: string, period_offset: int, mrr: float, active_users: int, cohort_size: int, retention_rate: float, mrr_per_user: float, expansion_mrr: float, contraction_mrr: float, churn_mrr: float, net_retention_rate: float, is_churned: bool, is_growing: bool}
     */
    public function toArray(): array
    {
        return [
            'cohort_id' => $this->cohortId,
            'period_offset' => $this->periodOffset,
            'mrr' => round($this->mrr, 2),
            'active_users' => $this->activeUsers,
            'cohort_size' => $this->cohortSize,
            'retention_rate' => $this->retentionRate,
            'mrr_per_user' => round($this->mrrPerUser, 2),
            'expansion_mrr' => round($this->expansionMrr, 2),
            'contraction_mrr' => round($this->contractionMrr, 2),
            'churn_mrr' => round($this->churnMrr, 2),
            'net_retention_rate' => $this->netRetentionRate,
            'is_churned' => $this->isChurned(),
            'is_growing' => $this->isGrowing(),
        ];
    }
}
