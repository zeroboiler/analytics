<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline\Validation;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Contract for a single validation stage in the event validation pipeline.
 *
 * Each stage performs one specific validation check (schema, naming, PII, etc.)
 * and returns a structured result indicating pass/fail with detailed diagnostics.
 *
 * @since 69.0.0
 */
interface ValidationStageInterface
{
    /**
     * Get the unique stage name.
     */
    public function name(): string;

    /**
     * Get the priority (lower = runs first).
     */
    public function priority(): int;

    /**
     * Whether this stage is enabled.
     */
    public function enabled(): bool;

    /**
     * Validate an event against this stage's rules.
     *
     * @return array{passed: bool, errors: list<array{code: string, message: string, field?: string, severity: 'error'|'warning'|'info'}>, metrics: array{checked: int, failed: int, skipped: int}}
     */
    public function validate(AnalyticsEvent $event): array;

    /**
     * Get a human-readable description of what this stage validates.
     */
    public function description(): string;
}
