<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Emitted when a churn exit interview is conducted.
 *
 * Captures the primary reason for churn and feedback from departing
 * customers. Critical input for product roadmap prioritization and
 * retention strategy optimization.
 *
 * @since 135.0.0
 */
final class ChurnInterviewEvent extends AnalyticsEvent
{
    /**
     * @param  array<string, mixed>  $params  Event parameters. Expected: reason (string), feedback (string), competitor (string|null)
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     */
    public function __construct(
        string $name = 'churn_interview',
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
        ?\DateTimeImmutable $timestamp = null,
    ){
        parent::__construct(
            name: $name,
            params: $params,
            clientId: $clientId,
            userId: $userId,
            timestamp: $timestamp,
        );
    }
}
