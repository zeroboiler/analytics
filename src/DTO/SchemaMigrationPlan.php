<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * A generated migration plan for handling schema drift.
 *
 * Provides actionable steps for upgrading consumers to handle
 * a detected schema change. Each step is classified by type
 * (transform, drop, rename, add_default, alert) and urgency.
 *
 * @phpstan-type MigrationStep array{
 *     action: 'transform'|'drop'|'rename'|'add_default'|'alert'|'backward_compat',
 *     field: string|null,
 *     description: string,
 *     code_example: string|null,
 *     urgency: 'critical'|'high'|'medium'|'low',
 *     affected_consumers: list<string>
 * }
 *
 * @since 223.0.0
 */
final readonly class SchemaMigrationPlan
{
    /**
     * @param  string  $eventName  The event this migration plan covers
     * @param  string  $driftId  Unique identifier linking to the SchemaDriftRecord
     * @param  list<MigrationStep>  $steps  Ordered migration steps to execute
     * @param  string  $riskLevel  Overall risk: 'critical'|'high'|'medium'|'low'
     * @param  int  $estimatedImpactConsumers  Number of consumers affected
     * @param  string|null  $rollbackStrategy  Description of how to rollback if needed
     * @param  list<string>  $prerequisites  Prerequisite tasks before migration
     * @param  \DateTimeImmutable  $generatedAt  When this plan was generated
     */
    public function __construct(
        public string $eventName,
        public string $driftId,
        public array $steps,
        public string $riskLevel,
        public int $estimatedImpactConsumers,
        public ?string $rollbackStrategy,
        public array $prerequisites,
        public \DateTimeImmutable $generatedAt,
    ): void  {}

    /**
     * Check if this migration plan contains any breaking changes.
     */
    public function hasBreakingChanges(): bool
    {
        foreach ($this->steps as $step) {
            if (($step['urgency'] ?? '') === 'critical') {
                return true;
            }
        }

        return false;
    }

    /**
     * Get only the critical/high urgency steps.
     *
     * @return list<MigrationStep>
     */
    public function criticalSteps(): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn (array $step): bool => in_array($step['urgency'], ['critical', 'high'], true),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_name' => $this->eventName,
            'drift_id' => $this->driftId,
            'steps' => $this->steps,
            'risk_level' => $this->riskLevel,
            'estimated_impact_consumers' => $this->estimatedImpactConsumers,
            'rollback_strategy' => $this->rollbackStrategy,
            'prerequisites' => $this->prerequisites,
            'has_breaking_changes' => $this->hasBreakingChanges(),
            'critical_steps_count' => count($this->criticalSteps()),
            'generated_at' => $this->generatedAt->format('c'),
        ];
    }
}
