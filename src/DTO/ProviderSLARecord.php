<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Provider SLA record — tracks uptime, latency, and breach history per provider.
 *
 * Each record represents a time-window snapshot of a provider's performance
 * against its configured SLA targets. Used by ProviderSLAMonitor for breach
 * detection, uptime calculation, and reporting.
 *
 * @since 84.0.0
 *
 * @see \ZeroBoiler\Analytics\Services\ProviderSLAMonitor
 */
final class ProviderSLARecord
{
    /**
     * Create a new SLA record.
     *
     * @param  string  $provider  Provider identifier (ga4, meta_pixel, posthog, etc.)
     * @param  string  $window  Time window key (e.g., '2026-08-14_hourly')
     * @param  int  $totalDispatches  Total events dispatched to this provider
     * @param  int  $successfulDispatches  Events successfully delivered
     * @param  int  $failedDispatches  Events that failed delivery
     * @param  float  $avgLatencyMs  Average dispatch latency in milliseconds
     * @param  float  $p99LatencyMs  99th percentile dispatch latency in milliseconds
     * @param  float  $uptimePercentage  Calculated uptime percentage (0-100)
     * @param  int  $breachCount  Number of SLA threshold breaches in this window
     * @param  bool  $slaMet  Whether all SLA targets were met in this window
     * @param  array<string, mixed>  $metadata  Additional context (error types, retry counts, etc.)
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $window,
        public readonly int $totalDispatches,
        public readonly int $successfulDispatches,
        public readonly int $failedDispatches,
        public readonly float $avgLatencyMs,
        public readonly float $p99LatencyMs,
        public readonly float $uptimePercentage,
        public readonly int $breachCount,
        public readonly bool $slaMet,
        public readonly array $metadata = [],
    ): void {}

    /**
     * Get the failure rate as a percentage.
     */
    public function failureRate(): float
    {
        if ($this->totalDispatches === 0) {
            return 0.0;
        }

        return ($this->failedDispatches / $this->totalDispatches) * 100;
    }

    /**
     * Get the success rate as a percentage.
     */
    public function successRate(): float
    {
        return 100.0 - $this->failureRate();
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'window' => $this->window,
            'total_dispatches' => $this->totalDispatches,
            'successful_dispatches' => $this->successfulDispatches,
            'failed_dispatches' => $this->failedDispatches,
            'avg_latency_ms' => $this->avgLatencyMs,
            'p99_latency_ms' => $this->p99LatencyMs,
            'uptime_percentage' => $this->uptimePercentage,
            'breach_count' => $this->breachCount,
            'sla_met' => $this->slaMet,
            'failure_rate' => $this->failureRate(),
            'success_rate' => $this->successRate(),
            'metadata' => $this->metadata,
        ];
    }
}
