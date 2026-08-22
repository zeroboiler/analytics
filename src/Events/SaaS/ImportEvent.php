<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a user data import action.
 *
 * Tracks bulk data import operations for product analytics,
 * onboarding flow optimization, and data migration monitoring.
 * High import counts signal active power users.
 *
 * GA4: file_upload
 * Meta: ImportData
 *
 * @since 1.0.0
 */
final readonly class ImportEvent extends AnalyticsEvent
{
    /**
     * @param  string  $format  Import format (e.g. 'csv', 'json', 'xlsx')
     * @param  string|null  $resource  Type of data imported (e.g. 'contacts', 'products', 'transactions')
     * @param  int|null  $recordCount  Number of records imported
     * @param  bool|null  $success  Whether the import succeeded
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $format,
        ?string $resource = null,
        ?int $recordCount = null,
        ?bool $success = null,
        array $params = [],
    ){
        parent::__construct('import', array_filter([
            'format' => $format,
            'resource' => $resource,
            'record_count' => $recordCount,
            'success' => $success,
        ] + $params, fn (mixed $v): bool => $v !== null && $v !== ''));
    }
}
