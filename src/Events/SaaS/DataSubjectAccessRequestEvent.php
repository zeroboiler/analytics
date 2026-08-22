<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when a data subject requests access to their personal data (GDPR Article 15).
 *
 * Tracks DSAR (Data Subject Access Request) submissions for compliance auditing.
 * Used for GDPR Article 30 record of processing activities.
 *
 * @see https://zeroboiler.dev/docs/analytics/gdpr
 *
 * @since 1.0.0
 */
final readonly class DataSubjectAccessRequestEvent extends AnalyticsEvent
{
    /**
     * @param  string  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     * @param  string|null  $requestType  Type of DSAR (access, rectification, portability)
     * @param  array<string, mixed>  $params  Additional parameters
     */
    public function __construct(
        string $clientId,
        ?string $userId = null,
        ?string $requestType = null,
        array $params = [],
    ){
        parent::__construct(
            name: 'data_subject_access_request',
            params: array_merge([
                'request_type' => $requestType,
            ], $params),
            clientId: $clientId,
            userId: $userId,
        );
    }
}
