<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Security;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Analytics event for data access audit logging.
 *
 * Tracks who accessed what data, when, and from where.
 * Critical for GDPR Article 30 record of processing activities
 * and SOC2 compliance audit trails.
 *
 * @since 90.0.0
 */
final class DataAccessAuditEvent extends AnalyticsEvent
{
    /**
     * @param  string|null  $dataType  Type of data accessed (e.g., 'user_profile', 'analytics_events', 'payment_info')
     * @param  string|null  $accessor  Who accessed the data (user ID or system identifier)
     * @param  string|null  $accessLevel  Level of access (e.g., 'read', 'export', 'delete')
     * @param  array<string, mixed>  $params  Additional event parameters
     */
    public function __construct(
        ?string $dataType = null,
        ?string $accessor = null,
        ?string $accessLevel = null,
        array $params = [],
    ){
        parent::__construct(
            name: 'data_access_audit',
            params: array_filter(array_merge($params, [
                'data_type' => $dataType,
                'accessor' => $accessor,
                'access_level' => $accessLevel,
            ])),
        );
    }
}
