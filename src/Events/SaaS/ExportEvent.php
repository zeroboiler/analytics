<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a user data export action.
 *
 * Used for GDPR compliance tracking, audit trails, and understanding
 * user behavior around data portability. Signals potential churn
 * when users export their data before leaving.
 *
 * GA4: file_download
 * Meta: ExportData
 *
 * @since 1.0.0
 */
final readonly class ExportEvent extends AnalyticsEvent
{
    /**
     * @param  string  $format  Export format (e.g. 'csv', 'json', 'pdf', 'xlsx')
     * @param  string|null  $resource  Type of data exported (e.g. 'reports', 'users', 'transactions', 'analytics')
     * @param  int|null  $recordCount  Number of records exported
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $format,
        ?string $resource = null,
        ?int $recordCount = null,
        array $params = [],
    ): void {
        parent::__construct('export', array_filter([
            'format' => $format,
            'resource' => $resource,
            'record_count' => $recordCount,
        ] + $params, fn (mixed $v): bool => $v !== null && $v !== ''));
    }
}
