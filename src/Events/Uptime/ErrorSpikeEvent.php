<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Uptime;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Fired when an error rate spike is detected.
 *
 * Tracks sudden increases in error rates for proactive alerting.
 * Includes the error type, rate change, and baseline comparison.
 *
 * @since 9.9.0
 */
final readonly class ErrorSpikeEvent extends AnalyticsEvent
{
    /**
     * @param  string  $errorType  Error classification (http_5xx, exception, timeout, validation)
     * @param  float  $currentRate  Current error rate (errors per minute)
     * @param  float  $baselineRate  Baseline/normal error rate for comparison
     * @param  float  $spikeMultiplier  How many times the current rate exceeds baseline
     */
    public function __construct(
        string $errorType = 'http_5xx',
        float $currentRate = 0.0,
        float $baselineRate = 0.0,
        float $spikeMultiplier = 1.0,
    ){
        parent::__construct('error_spike', [
            'error_type' => $errorType,
            'current_rate' => $currentRate,
            'baseline_rate' => $baselineRate,
            'spike_multiplier' => $spikeMultiplier,
        ]);
    }
}
