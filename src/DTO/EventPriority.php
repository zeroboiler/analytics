<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\DTO;

/**
 * Enum representing analytics event priority levels.
 *
 * Priority levels determine dispatch behavior:
 * - Critical: Always dispatched, bypasses sampling, dedup, and rate limits.
 *   Used for revenue events (purchase, subscription, payment_succeeded).
 * - Normal: Standard dispatch with all pipeline filters applied.
 *   Default for most events (page_view, click, form_submit).
 * - Low: Subject to sampling, rate limits, and performance budget checks.
 *   Used for high-volume non-essential events (scroll_depth, outbound_click, timing).
 * - Background: Deferrable events — queued even when queue is disabled,
 *   subject to aggressive sampling. Used for telemetry, heartbeats, ping events.
 *
 * @see \ZeroBoiler\Analytics\Services\EventPriorityGate
 * @see \ZeroBoiler\Analytics\Pipeline\PriorityAwareFilter
 *
 * @since 1.0.0
 */
enum EventPriority: string
{
    /** Critical events — always dispatched, revenue-impacting */
    case Critical = 'critical';

    /** Normal events — standard pipeline processing */
    case Normal = 'normal';

    /** Low priority — subject to sampling and budget checks */
    case Low = 'low';

    /** Background — deferrable, aggressive sampling */
    case Background = 'background';

    /**
     * Numeric weight for comparison (higher = more important).
     *
     * @return int 0-3, where 3 = critical, 0 = background
     */
    public function weight(): int
    {
        return match ($this) {
            self::Critical => 3,
            self::Normal => 2,
            self::Low => 1,
            self::Background => 0,
        };
    }

    /**
     * Whether this priority bypasses all filters.
     */
    public function bypassesFilters(): bool
    {
        return $this === self::Critical;
    }

    /**
     * Whether this priority is subject to sampling.
     */
    public function subjectToSampling(): bool
    {
        return $this === self::Low || $this === self::Background;
    }

    /**
     * Whether this priority is subject to performance budget checks.
     */
    public function subjectToBudget(): bool
    {
        return $this === self::Low || $this === self::Background;
    }

    /**
     * Whether events at this priority should be deferred (queued even when sync is default).
     */
    public function deferrable(): bool
    {
        return $this === self::Background;
    }

    /**
     * Resolve priority from a string value.
     *
     * Returns null for unrecognised values.
     */
    public static function fromString(string $value): ?self
    {
        return self::tryFrom(strtolower($value));
    }

    /**
     * Get all priority values as a list of strings.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $p): string => $p->value, self::cases());
    }
}
