<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\SaaS;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event dispatched when a user shows retention risk signals.
 *
 * Computed by churn prediction models based on usage decline,
 * support ticket volume, login frequency drop, or feature
 * engagement decrease. Used by growth teams for proactive
 * retention outreach.
 *
 * @since 22.0.0
 */
final readonly class RetentionRiskEvent extends AnalyticsEvent
{
    /**
     * Create a retention risk event.
     *
     * @param  string  $riskLevel  Risk level: low, medium, high, critical
     * @param  array<string, mixed>  $signals  Risk signals that triggered this event
     * @param  float  $riskScore  Computed risk score (0.0 - 1.0)
     * @param  array<string, mixed>  $params  Additional parameters
     * @param  string|null  $clientId  Client tracking ID
     * @param  string|null  $userId  Authenticated user ID
     */
    public function __construct(
        public string $riskLevel = 'medium',
        public array $signals = [],
        public float $riskScore = 0.0,
        array $params = [],
        ?string $clientId = null,
        ?string $userId = null,
    ){
        parent::__construct(
            name: 'retention_risk',
            params: array_merge($params, [
                'risk_level' => $riskLevel,
                'risk_score' => $riskScore,
                'signal_count' => count($signals),
                'signals' => $signals,
            ]),
            clientId: $clientId,
            userId: $userId,
            priority: $riskLevel === 'critical' ? 'critical' : 'normal',
            source: 'server',
        );
    }
}
