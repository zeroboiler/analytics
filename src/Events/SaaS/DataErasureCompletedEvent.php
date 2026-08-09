<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a data erasure / right-to-be-forgotten request is completed (GDPR Article 17).
 *
 * Tracks the completion of erasure for compliance auditing and verification.
 * Indicates which data categories were erased.
 *
 * @see https://zeroboiler.dev/docs/analytics/gdpr
 *
 * @since 1.0.0
 */
final class DataErasureCompletedEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $userId  User ID whose data was erased
     * @param  array<string>  $categoriesErased  Data categories erased (e.g. ['analytics', 'profile'])
     * @param  string|null  $requestId  Original DSAR reference ID
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        ?string $userId = null,
        array $categoriesErased = [],
        ?string $requestId = null,
        array $params = [],
    ): void {
        parent::__construct(
            name: 'data_erasure_completed',
            params: array_merge([
                'categories_erased' => $categoriesErased,
                'categories_count' => count($categoriesErased),
                'request_id' => $requestId,
            ], $params),
            clientId: '',
            userId: $userId,
        );
    }
}
