<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Event deprecation lifecycle management service.
 *
 * Manages the deprecation of analytics events following a structured lifecycle:
 * Active → Deprecated → Retired. Provides replacement suggestions,
 * sunset period enforcement, and deprecation warnings for consumers.
 *
 * When an event is deprecated:
 * 1. A replacement event may be specified
 * 2. A sunset period begins (configurable, default 30 days)
 * 3. Deprecation warnings are logged for every dispatch
 * 4. After the sunset period, the event should be retired
 *
 * Configuration is read from `zeroboiler.analytics.governance.deprecation`.
 *
 * @phpstan-type DeprecationEntry array{event: string, replacement: string|null, deprecated_at: string, sunset_days: int, dispatch_count: int, status: 'active'|'expired'}
 *
 * @since 1.0.0
 */
final class EventDeprecationService
{
    private const CACHE_PREFIX = 'zb_deprecation_';

    private readonly int $defaultSunsetDays;

    /** @var array<string, DeprecationEntry> */
    private array $deprecations = [];

    /** @var array<string, int> In-memory dispatch counters for deprecated events */
    private array $dispatchCounts = [];

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ): void {
        $deprecationConfig = $config->get('zeroboiler.analytics.governance.deprecation', []);
        /** @var array{default_sunset_days?: int} $deprecationConfig */

        $this->defaultSunsetDays = (int) ($deprecationConfig['default_sunset_days'] ?? 30);

        $this->loadDeprecations();
    }

    /**
     * Set a replacement event for a deprecated event.
     *
     * @param  string  $event  Deprecated event name
     * @param  string  $replacement  Suggested replacement event name
     */
    public function setReplacement(string $event, string $replacement): void
    {
        if (! isset($this->deprecations[$event])) {
            $this->deprecations[$event] = [
                'event' => $event,
                'replacement' => null,
                'deprecated_at' => date('c'),
                'sunset_days' => $this->defaultSunsetDays,
                'dispatch_count' => 0,
                'status' => 'active',
            ];
        }

        $this->deprecations[$event]['replacement'] = $replacement;
        $this->persistDeprecations();
    }

    /**
     * Deprecate an event with optional replacement.
     *
     * @param  string  $event  Event name to deprecate
     * @param  string|null  $replacement  Replacement event name
     * @param  int|null  $sunsetDays  Custom sunset period (null = use default)
     * @return array{success: bool, error: string|null}
     */
    public function deprecate(string $event, ?string $replacement = null, ?int $sunsetDays = null): array
    {
        if (isset($this->deprecations[$event])) {
            return ['success' => false, 'error' => "Event '{$event}' is already deprecated"];
        }

        $this->deprecations[$event] = [
            'event' => $event,
            'replacement' => $replacement,
            'deprecated_at' => date('c'),
            'sunset_days' => $sunsetDays ?? $this->defaultSunsetDays,
            'dispatch_count' => 0,
            'status' => 'active',
        ];

        $this->persistDeprecations();

        return ['success' => true, 'error' => null];
    }

    /**
     * Record a dispatch of a deprecated event (for tracking).
     *
     * @param  string  $event  Event name
     * @return array{deprecated: bool, replacement: string|null, sunset_expired: bool, days_until_sunset: int|null}
     */
    public function trackDispatch(string $event): array
    {
        if (! isset($this->deprecations[$event])) {
            return ['deprecated' => false, 'replacement' => null, 'sunset_expired' => false, 'days_until_sunset' => null];
        }

        // Increment dispatch counter
        $this->dispatchCounts[$event] = ($this->dispatchCounts[$event] ?? 0) + 1;
        $this->deprecations[$event]['dispatch_count'] = $this->dispatchCounts[$event];

        $entry = $this->deprecations[$event];
        $deprecatedAt = strtotime($entry['deprecated_at']);
        $sunsetEnd = $deprecatedAt + ($entry['sunset_days'] * 86400);
        $now = time();
        $daysUntilSunset = (int) ceil(($sunsetEnd - $now) / 86400);
        $expired = $now > $sunsetEnd;

        if ($expired && $entry['status'] === 'active') {
            $this->deprecations[$event]['status'] = 'expired';
            $this->persistDeprecations();
        }

        return [
            'deprecated' => true,
            'replacement' => $entry['replacement'],
            'sunset_expired' => $expired,
            'days_until_sunset' => max(0, $daysUntilSunset),
        ];
    }

    /**
     * Get the replacement event for a deprecated event.
     */
    public function getReplacement(string $event): ?string
    {
        return $this->deprecations[$event]['replacement'] ?? null;
    }

    /**
     * Check if an event is deprecated.
     */
    public function isDeprecated(string $event): bool
    {
        return isset($this->deprecations[$event]);
    }

    /**
     * Check if a deprecated event has passed its sunset period.
     */
    public function isSunsetExpired(string $event): bool
    {
        $entry = $this->deprecations[$event] ?? null;

        if ($entry === null) {
            return false;
        }

        $deprecatedAt = strtotime($entry['deprecated_at']);
        $sunsetEnd = $deprecatedAt + ($entry['sunset_days'] * 86400);

        return time() > $sunsetEnd;
    }

    /**
     * Remove a deprecation entry (undeprecate — use with caution).
     *
     * @return array{success: bool, error: string|null}
     */
    public function undeprecate(string $event): array
    {
        if (! isset($this->deprecations[$event])) {
            return ['success' => false, 'error' => "Event '{$event}' is not deprecated"];
        }

        unset($this->deprecations[$event]);
        unset($this->dispatchCounts[$event]);
        $this->persistDeprecations();

        return ['success' => true, 'error' => null];
    }

    /**
     * Get all deprecated events.
     *
     * @param  string|null  $status  Filter by status ('active'|'expired'|null=all)
     * @return list<DeprecationEntry>
     */
    public function list(?string $status = null): array
    {
        if ($status === null) {
            return array_values($this->deprecations);
        }

        return array_values(array_filter(
            $this->deprecations,
            fn (array $entry): bool => $entry['status'] === $status,
        ));
    }

    /**
     * Get deprecation warnings for events dispatched in the last N days.
     *
     * @param  int  $days  Look-back period
     * @return list<array{event: string, deprecated_at: string|null, replacement: string|null, dispatch_count: int}>
     */
    public function warnings(int $days = 30): array
    {
        $results = [];
        $cutoff = time() - ($days * 86400);

        foreach ($this->deprecations as $entry) {
            $deprecatedAt = strtotime($entry['deprecated_at']);

            if ($deprecatedAt >= $cutoff || $entry['dispatch_count'] > 0) {
                $results[] = [
                    'event' => $entry['event'],
                    'deprecated_at' => $entry['deprecated_at'],
                    'replacement' => $entry['replacement'],
                    'dispatch_count' => $entry['dispatch_count'],
                ];
            }
        }

        return $results;
    }

    /**
     * Get events that have passed their sunset period (need retirement).
     *
     * @return list<array{event: string, deprecated_at: string, replacement: string|null, dispatch_count: int, days_expired: int}>
     */
    public function expired(): array
    {
        $results = [];

        foreach ($this->deprecations as $entry) {
            if ($this->isSunsetExpired($entry['event'])) {
                $deprecatedAt = strtotime($entry['deprecated_at']);
                $sunsetEnd = $deprecatedAt + ($entry['sunset_days'] * 86400);

                $results[] = [
                    'event' => $entry['event'],
                    'deprecated_at' => $entry['deprecated_at'],
                    'replacement' => $entry['replacement'],
                    'dispatch_count' => $entry['dispatch_count'],
                    'days_expired' => max(0, (int) floor((time() - $sunsetEnd) / 86400)),
                ];
            }
        }

        return $results;
    }

    /**
     * Get a summary of the deprecation service state.
     *
     * @return array{total_deprecated: int, active: int, expired: int, with_replacement: int, total_dispatches: int}
     */
    public function summary(): array
    {
        $active = 0;
        $expired = 0;
        $withReplacement = 0;
        $totalDispatches = 0;

        foreach ($this->deprecations as $entry) {
            if ($entry['status'] === 'active') {
                $active++;
            } else {
                $expired++;
            }

            if ($entry['replacement'] !== null) {
                $withReplacement++;
            }

            $totalDispatches += $entry['dispatch_count'];
        }

        return [
            'total_deprecated' => count($this->deprecations),
            'active' => $active,
            'expired' => $expired,
            'with_replacement' => $withReplacement,
            'total_dispatches' => $totalDispatches,
        ];
    }

    /**
     * Load deprecations from cache.
     */
    private function loadDeprecations(): void
    {
        try {
            $cached = $this->cache->get(self::CACHE_PREFIX . 'entries');

            if (is_array($cached)) {
                $this->deprecations = $cached;
            }
        } catch (\Throwable) {
            // Cache unavailable
        }
    }

    /**
     * Persist deprecations to cache.
     */
    private function persistDeprecations(): void
    {
        try {
            $this->cache->put(
                self::CACHE_PREFIX . 'entries',
                $this->deprecations,
                86400, // 24 hours
            );
        } catch (\Throwable) {
            // Cache unavailable
        }
    }
}
