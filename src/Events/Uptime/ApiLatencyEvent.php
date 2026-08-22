<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Uptime;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when API latency exceeds a configured threshold.
 *
 * Tracks performance degradation for proactive monitoring and SLA reporting.
 * Includes endpoint, response time, and threshold details.
 *
 * @since 9.9.0
 */
final class ApiLatencyEvent extends AnalyticsEvent
{
    /**
     * @param  string  $endpoint  API endpoint or route that exceeded the threshold
     * @param  float  $responseTimeMs  Actual response time in milliseconds
     * @param  float  $thresholdMs  The configured threshold in milliseconds
     * @param  string  $method  HTTP method (GET, POST, etc.)
     */
    public function __construct(
        string $endpoint = '',
        float $responseTimeMs = 0.0,
        float $thresholdMs = 1000.0,
        string $method = 'GET',
    ){
        parent::__construct('api_latency', [
            'endpoint' => $endpoint,
            'response_time_ms' => $responseTimeMs,
            'threshold_ms' => $thresholdMs,
            'method' => $method,
        ]);
    }
}
