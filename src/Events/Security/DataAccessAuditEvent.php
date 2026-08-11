<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Security;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a data access audit event occurs.
 *
 * Tracks access to sensitive data for GDPR Art.30 compliance records
 * and internal audit trails. Includes resource type, action, and outcome.
 *
 * @since 9.9.0
 */
final class DataAccessAuditEvent extends AnalyticsEvent
{
    /**
     * @param  string  $resource  Resource type accessed (user_data, financial_records, pii, health_data, etc.)
     * @param  string  $action  Action performed (read, export, modify, delete, bulk_export)
     * @param  string|null  $actorId  ID of the user who performed the action
     * @param  string|null  $targetId  ID of the user whose data was accessed
     */
    public function __construct(
        string $resource = 'user_data',
        string $action = 'read',
        ?string $actorId = null,
        ?string $targetId = null,
    ): void {
        parent::__construct('data_access_audit', array_filter([
            'resource' => $resource,
            'action' => $action,
            'actor_id' => $actorId,
            'target_id' => $targetId,
        ]));
    }
}
