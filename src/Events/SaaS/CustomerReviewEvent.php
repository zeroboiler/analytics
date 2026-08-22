<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Emitted when a customer submits a product review or testimonial.
 *
 * Tracks customer advocacy signals — reviews indicate high satisfaction
 * and are strong predictors of referral activity and expansion revenue.
 *
 * @since 135.0.0
 */
final class CustomerReviewEvent extends AnalyticsEvent
{
    /**
     * @param  array<string, mixed>  $params  Event parameters. Expected: rating (int), platform (string), public (bool)
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     */
    public function __construct(
        string $name = 'customer_review',
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
        ?string $timestamp = null,
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
