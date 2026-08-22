<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline\Validation;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Validates event name against catalog membership and naming conventions.
 *
 * Ensures every event is registered in the EventCatalog and follows
 * snake_case naming conventions. First-stage validator (priority 10).
 *
 * @since 69.0.0
 */
final class CatalogMembershipStage implements ValidationStageInterface
{
    private bool $enforceMembership;

    private int $maxNameLength;

    private bool $enforceSnakeCase;

    /**
     * @param  array{enforce_membership?: bool, max_name_length?: int, enforce_snake_case?: bool}  $config
     */
    public function __construct(array $config = []){
        $this->enforceMembership = (bool) ($config['enforce_membership'] ?? true);
        $this->maxNameLength = (int) ($config['max_name_length'] ?? 100);
        $this->enforceSnakeCase = (bool) ($config['enforce_snake_case'] ?? true);
    }

    public function name(): string
    {
        return 'catalog_membership';
    }

    public function priority(): int
    {
        return 10;
    }

    public function enabled(): bool
    {
        return true;
    }

    /**
     * @return array{passed: bool, errors: list<array{code: string, message: string, field?: string, severity: 'error'|'warning'|'info'}>, metrics: array{checked: int, failed: int, skipped: int}}
     */
    public function validate(AnalyticsEvent $event): array
    {
        $errors = [];
        $checked = 0;
        $failed = 0;

        // Check catalog membership
        $checked++;
        if ($this->enforceMembership && ! EventCatalog::has($event->name)) {
            $failed++;
            $errors[] = [
                'code' => 'catalog_not_found',
                'message' => "Event '{$event->name}' is not registered in the EventCatalog",
                'field' => 'name',
                'severity' => 'error',
            ];
        }

        // Check name length
        $checked++;
        if (mb_strlen($event->name) > $this->maxNameLength) {
            $failed++;
            $errors[] = [
                'code' => 'name_too_long',
                'message' => "Event name exceeds max length of {$this->maxNameLength} characters (actual: " . mb_strlen($event->name) . ')',
                'field' => 'name',
                'severity' => 'error',
            ];
        }

        // Check snake_case convention
        $checked++;
        if ($this->enforceSnakeCase && ! preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/', $event->name)) {
            $errors[] = [
                'code' => 'invalid_naming',
                'message' => "Event name '{$event->name}' does not follow snake_case convention",
                'field' => 'name',
                'severity' => 'warning',
            ];
        }

        return [
            'passed' => $failed === 0,
            'errors' => $errors,
            'metrics' => [
                'checked' => $checked,
                'failed' => $failed,
                'skipped' => 0,
            ],
        ];
    }

    public function description(): string
    {
        return 'Validates event name is registered in EventCatalog, follows naming conventions, and has valid length';
    }
}
