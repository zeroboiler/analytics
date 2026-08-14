<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Emitted when an NPS (Net Promoter Score) survey is submitted.
 *
 * Tracks customer satisfaction signals for retention analysis and
 * customer success health scoring. Score range: -100 to +100.
 * Promoters (9-10), Passives (7-8), Detractors (0-6).
 *
 * @since 135.0.0
 */
final class NpsSubmittedEvent extends AnalyticsEvent
{
    /**
     * @param  array<string, mixed>  $params  Event parameters. Expected: score (int), category (string)
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     */
    public function __construct(
        string $name = 'nps_submitted',
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
        ?string $timestamp = null,
    ) {
        parent::__construct(
            name: $name,
            params: $params,
            clientId: $clientId,
            userId: $userId,
            timestamp: $timestamp,
        );
    }
}
