<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event sampling filter for high-traffic applications.
 *
 * Drops events probabilistically based on a configured sample rate.
 * For example, at 10% sample rate, only ~1 in 10 events are processed.
 * Useful for controlling analytics volume during traffic spikes.
 *
 * Supports deterministic sampling by event name for consistent results
 * across requests.
 *
 * @since 1.0.0
 */
final class SamplingFilter
{
    private float $sampleRate;

    private bool $deterministic;

    private ?string $salt;

    /**
     * @param  float  $sampleRate  Sample rate between 0.0 and 1.0 (1.0 = no sampling, 0.1 = 10%)
     * @param  bool  $deterministic  Use event name for consistent sampling (same event always included/excluded)
     * @param  string|null  $salt  Optional salt for deterministic hash (prevents predictable sampling)
     */
    public function __construct(
        float $sampleRate = 1.0,
        bool $deterministic = true,
        ?string $salt = null,
    ){
        $this->sampleRate = max(0.0, min(1.0, $sampleRate));
        $this->deterministic = $deterministic;
        $this->salt = $salt;
    }

    /**
     * Determine if an event should be sampled (included).
     *
     * Returns true if the event should be processed, false if it should be dropped.
     */
    public function shouldSample(AnalyticsEvent $event): bool
    {
        if ($this->sampleRate >= 1.0) {
            return true;
        }

        if ($this->sampleRate <= 0.0) {
            return false;
        }

        if (! $this->deterministic) {
            return (mt_rand() / mt_getrandmax()) <= $this->sampleRate;
        }

        // Deterministic: hash event name and check against rate
        $hash = $this->hashEventName($event->name);
        $bucket = (int) ($hash / (float) 0xFFFFFFFF);

        return ($bucket % 100) < (int) ($this->sampleRate * 100);
    }

    /**
     * Get the current sample rate.
     */
    public function getSampleRate(): float
    {
        return $this->sampleRate;
    }

    /**
     * Hash an event name to a 32-bit integer for deterministic bucketing.
     */
    private function hashEventName(string $name): int
    {
        $seed = $this->salt !== null ? $name . $this->salt : $name;

        return crc32($seed) & 0xFFFFFFFF;
    }
}
