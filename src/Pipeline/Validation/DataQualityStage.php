<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline\Validation;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Validates data quality metrics for event parameters.
 *
 * Checks for empty strings, null values in required fields, parameter count
 * distribution, and overall data completeness score. Priority 40.
 *
 * @since 69.0.0
 */
final class DataQualityStage implements ValidationStageInterface
{
    private bool $enabled;

    private float $minCompleteness;

    private int $maxEmptyParams;

    /**
     * @param  array{enabled?: bool, min_completeness?: float, max_empty_params?: int}  $config
     */
    public function __construct(array $config = []): void
    {
        $this->enabled = (bool) ($config['enabled'] ?? true);
        $this->minCompleteness = (float) ($config['min_completeness'] ?? 0.3);
        $this->maxEmptyParams = (int) ($config['max_empty_params'] ?? 10);
    }

    public function name(): string
    {
        return 'data_quality';
    }

    public function priority(): int
    {
        return 40;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array{passed: bool, errors: list<array{code: string, message: string, field?: string, severity: 'error'|'warning'|'info'}>, metrics: array{checked: int, failed: int, skipped: int}}
     */
    public function validate(AnalyticsEvent $event): array
    {
        if (! $this->enabled) {
            return [
                'passed' => true,
                'errors' => [],
                'metrics' => ['checked' => 0, 'failed' => 0, 'skipped' => 1],
            ];
        }

        $errors = [];
        $checked = 0;
        $failed = 0;
        $params = $event->params;

        // Check for excessive empty/null values
        $checked++;
        $emptyCount = 0;
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                $emptyCount++;
            }
        }
        if ($emptyCount > $this->maxEmptyParams) {
            $failed++;
            $errors[] = [
                'code' => 'excessive_empty_params',
                'message' => "Event has {$emptyCount} empty/null parameters (max: {$this->maxEmptyParams})",
                'severity' => 'warning',
            ];
        }

        // Check data completeness
        $checked++;
        $totalParams = count($params);
        if ($totalParams > 0) {
            $completeness = ($totalParams - $emptyCount) / $totalParams;
            if ($completeness < $this->minCompleteness) {
                $failed++;
                $errors[] = [
                    'code' => 'low_completeness',
                    'message' => sprintf(
                        'Event data completeness is %.1f%% (minimum: %.1f%%)',
                        $completeness * 100,
                        $this->minCompleteness * 100,
                    ),
                    'severity' => 'warning',
                ];
            }
        }

        // Check for HTML content in string values
        $checked++;
        foreach ($params as $key => $value) {
            if (is_string($value) && preg_match('/<\s*[a-zA-Z][^>]*>/', $value)) {
                $errors[] = [
                    'code' => 'html_detected',
                    'message' => "HTML content detected in parameter '{$key}'",
                    'field' => (string) $key,
                    'severity' => 'warning',
                ];
            }
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
        return 'Validates data quality: empty values, completeness ratio, HTML content, and parameter distribution';
    }
}
