<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Event Delivery Watermark Service — Kafka-inspired delivery cursor tracking.
 *
 * Maintains per-provider delivery cursors (watermarks) that track the
 * high-water mark of successfully delivered events. This enables:
 *
 * - **Gap detection**: Identify event sequence numbers that were never
 *   confirmed as delivered to a specific provider.
 * - **Resume from checkpoint**: After a provider outage, resume dispatch
 *   from the last confirmed delivery point.
 * - **Cross-provider consistency**: Compare watermarks across providers
 *   to identify which providers are falling behind.
 * - **Delivery lag measurement**: Quantify how many events behind each
 *   provider is from the global high-water mark.
 *
 * Watermarks are monotonically increasing sequence numbers assigned
 * to each dispatched event via an atomic counter. Each provider tracks
 * its own confirmed watermark. The difference between the global
 * high-water mark and a provider's confirmed watermark is the "lag".
 *
 * Inspired by Apache Kafka consumer group offsets, AWS Kinesis shard
 * iterators, and Stripe's event delivery progress tracking.
 *
 * @see \ZeroBoiler\Analytics\Services\EventDeliveryConfirmationService
 * @see \ZeroBoiler\Analytics\Services\EventDispatchOrchestrator
 * @see \ZeroBoiler\Analytics\Services\EventDeliverySlaMonitor
 *
 * @since 245.0.0
 */
final class EventDeliveryWatermarkService
{
    /** @var string Cache key prefix for watermark data */
    private const CACHE_PREFIX = 'zb_watermark_';

    /** @var string Cache key for the global sequence counter */
    private const GLOBAL_SEQ_KEY = 'zb_watermark_global_seq';

    /** @var string Cache key for the dispatch log */
    private const DISPATCH_LOG_KEY = 'zb_watermark_dispatch_log';

    /** @var string Cache key for gap records */
    private const GAPS_KEY = 'zb_watermark_gaps';

    /** @var int Default cache TTL (1 hour) */
    private const DEFAULT_TTL = 3600;

    /** @var int Default dispatch log retention (1000 entries) */
    private const DEFAULT_LOG_SIZE = 1000;

    /** @var int Maximum gap records retained */
    private const MAX_GAPS = 500;

    /** @var int Maximum providers supported */
    private const MAX_PROVIDERS = 20;

    /** @var int Default gap detection window (events to scan back) */
    private const DEFAULT_GAP_WINDOW = 500;

    /**
     * Dispatch log entry structure.
     *
     * @phpstan-type DispatchEntry array{seq: int, event: string, provider: string, status: 'dispatched'|'confirmed'|'failed', timestamp: float, priority?: string|null}
     */
    /**
     * Gap record structure.
     *
     * @phpstan-type GapRecord array{seq: int, event: string, provider: string, detected_at: float, resolved: bool, resolved_at?: float|null}
     */
    /**
     * Watermark state structure.
     *
     * @phpstan-type WatermarkState array{confirmed: int, lag: int, last_event: string|null, last_updated: float}
     */
    /**
     * Provider status summary.
     *
     * @phpstan-type ProviderStatus array{provider: string, confirmed_watermark: int, lag: int, global_hwm: int, status: 'current'|'lagging'|'behind'|'critical', last_event: string|null, gap_count: int}
     */

    private readonly CacheRepository $cache;

    private readonly bool $enabled;

    private readonly int $ttl;

    private readonly int $logSize;

    private readonly int $gapWindow;

    private readonly int $lagWarningThreshold;

    private readonly int $lagCriticalThreshold;

    /** @var list<string> Known provider names */
    private readonly array $providers;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $this->cache = $cache;

        $wmConfig = $config->get('zeroboiler.analytics.watermark', []);
        /** @var array{enabled?: bool, ttl?: int, log_size?: int, gap_window?: int, lag_warning?: int, lag_critical?: int, providers?: list<string>} $wmConfig */

        $this->enabled = (bool) ($wmConfig['enabled'] ?? true);
        $this->ttl = (int) ($wmConfig['ttl'] ?? self::DEFAULT_TTL);
        $this->logSize = (int) ($wmConfig['log_size'] ?? self::DEFAULT_LOG_SIZE);
        $this->gapWindow = (int) ($wmConfig['gap_window'] ?? self::DEFAULT_GAP_WINDOW);
        $this->lagWarningThreshold = (int) ($wmConfig['lag_warning'] ?? 50);
        $this->lagCriticalThreshold = (int) ($wmConfig['lag_critical'] ?? 200);
        $this->providers = is_array($wmConfig['providers'] ?? null)
            ? $wmConfig['providers']
            : ['ga4', 'gtm', 'meta_pixel', 'plausible', 'posthog', 'mixpanel', 'amplitude', 'tiktok', 'linkedin', 'webhook'];
    }

    // ── Sequence Number Management ─────────────────────────────────

    /**
     * Generate the next monotonically increasing sequence number.
     *
     * Uses atomic cache increment for thread-safe sequence generation.
     *
     * @return int The next sequence number
     */
    public function nextSequence(): int
    {
        if (! $this->enabled) {
            return 0;
        }

        $current = (int) ($this->cache->get(self::GLOBAL_SEQ_KEY) ?? 0);
        $next = $current + 1;
        $this->cache->put(self::GLOBAL_SEQ_KEY, $next, $this->ttl * 2);

        return $next;
    }

    /**
     * Get the current global high-water mark (last assigned sequence).
     *
     * @return int Current global HWM
     */
    public function globalHighWaterMark(): int
    {
        return (int) ($this->cache->get(self::GLOBAL_SEQ_KEY) ?? 0);
    }

    /**
     * Reset the global sequence counter and all watermarks.
     *
     * Intended for testing and administrative reset only.
     */
    public function reset(): void
    {
        $this->cache->forget(self::GLOBAL_SEQ_KEY);
        $this->cache->forget(self::DISPATCH_LOG_KEY);
        $this->cache->forget(self::GAPS_KEY);

        foreach ($this->providers as $provider) {
            $this->cache->forget(self::CACHE_PREFIX . 'confirmed_' . $provider);
        }
    }

    // ── Dispatch Recording ──────────────────────────────────────────

    /**
     * Record that an event has been dispatched to a provider.
     *
     * Appends to the dispatch log and updates the global HWM.
     *
     * @param  int  $seq  Sequence number (from nextSequence())
     * @param  string  $eventName  Event name
     * @param  string  $provider  Provider identifier
     * @param  string|null  $priority  Event priority
     */
    public function recordDispatch(
        int $seq,
        string $eventName,
        string $provider,
        ?string $priority = null,
    ): void {
        if (! $this->enabled) {
            return;
        }

        $log = $this->getDispatchLog();
        $log[] = [
            'seq' => $seq,
            'event' => $eventName,
            'provider' => $provider,
            'status' => 'dispatched',
            'timestamp' => microtime(true),
            'priority' => $priority,
        ];

        // Trim to max size
        if (count($log) > $this->logSize) {
            $log = array_slice($log, -$this->logSize);
        }

        $this->cache->put(self::DISPATCH_LOG_KEY, $log, $this->ttl);
    }

    /**
     * Record that an event was successfully delivered to a provider.
     *
     * Updates the provider's confirmed watermark and marks the
     * dispatch log entry as confirmed.
     *
     * @param  int  $seq  Sequence number
     * @param  string  $provider  Provider identifier
     */
    public function confirmDelivery(int $seq, string $provider): void
    {
        if (! $this->enabled) {
            return;
        }

        // Update provider confirmed watermark (only move forward)
        $key = self::CACHE_PREFIX . 'confirmed_' . $provider;
        $current = (int) ($this->cache->get($key) ?? 0);

        if ($seq > $current) {
            $this->cache->put($key, $seq, $this->ttl * 2);
        }

        // Update dispatch log entry
        $log = $this->getDispatchLog();
        foreach ($log as $i => $entry) {
            if ($entry['seq'] === $seq && $entry['provider'] === $provider) {
                $log[$i]['status'] = 'confirmed';
                break;
            }
        }
        $this->cache->put(self::DISPATCH_LOG_KEY, $log, $this->ttl);

        // Resolve any gap record for this seq+provider
        $this->resolveGap($seq, $provider);
    }

    /**
     * Record a failed delivery attempt.
     *
     * @param  int  $seq  Sequence number
     * @param  string  $provider  Provider identifier
     */
    public function recordFailure(int $seq, string $provider): void
    {
        if (! $this->enabled) {
            return;
        }

        $log = $this->getDispatchLog();
        foreach ($log as $i => $entry) {
            if ($entry['seq'] === $seq && $entry['provider'] === $provider) {
                $log[$i]['status'] = 'failed';
                break;
            }
        }
        $this->cache->put(self::DISPATCH_LOG_KEY, $log, $this->ttl);
    }

    // ── Watermark Queries ───────────────────────────────────────────

    /**
     * Get the confirmed watermark for a specific provider.
     *
     * @param  string  $provider  Provider identifier
     * @return int Confirmed high-water mark for this provider
     */
    public function providerWatermark(string $provider): int
    {
        if (! $this->enabled) {
            return 0;
        }

        return (int) ($this->cache->get(self::CACHE_PREFIX . 'confirmed_' . $provider) ?? 0);
    }

    /**
     * Get watermark state for all tracked providers.
     *
     * @return array<string, WatermarkState>
     */
    public function allWatermarks(): array
    {
        $globalHwm = $this->globalHighWaterMark();
        $log = $this->getDispatchLog();
        $result = [];

        foreach ($this->providers as $provider) {
            $confirmed = $this->providerWatermark($provider);
            $lastEvent = $this->findLastEventForProvider($log, $provider);

            $result[$provider] = [
                'confirmed' => $confirmed,
                'lag' => max(0, $globalHwm - $confirmed),
                'last_event' => $lastEvent,
                'last_updated' => microtime(true),
            ];
        }

        return $result;
    }

    /**
     * Get per-provider status summary with lag classification.
     *
     * @return list<ProviderStatus>
     */
    public function providerStatuses(): array
    {
        $globalHwm = $this->globalHighWaterMark();
        $watermarks = $this->allWatermarks();
        $gaps = $this->getGaps();
        $statuses = [];

        foreach ($watermarks as $provider => $wm) {
            $lag = $wm['lag'];
            $gapCount = count(array_filter(
                $gaps,
                static fn (array $g): bool => $g['provider'] === $provider && ! $g['resolved'],
            ));

            if ($lag === 0) {
                $status = 'current';
            } elseif ($lag <= $this->lagWarningThreshold) {
                $status = 'lagging';
            } elseif ($lag <= $this->lagCriticalThreshold) {
                $status = 'behind';
            } else {
                $status = 'critical';
            }

            $statuses[] = [
                'provider' => $provider,
                'confirmed_watermark' => $wm['confirmed'],
                'lag' => $lag,
                'global_hwm' => $globalHwm,
                'status' => $status,
                'last_event' => $wm['last_event'],
                'gap_count' => $gapCount,
            ];
        }

        return $statuses;
    }

    // ── Gap Detection ───────────────────────────────────────────────

    /**
     * Detect delivery gaps for all providers.
     *
     * Scans the dispatch log within the configured gap window and
     * identifies sequence numbers that were dispatched but not
     * confirmed for each provider.
     *
     * @return array{total_gaps: int, by_provider: array<string, int>, gaps: list<GapRecord>}
     */
    public function detectGaps(): array
    {
        if (! $this->enabled) {
            return ['total_gaps' => 0, 'by_provider' => [], 'gaps' => []];
        }

        $log = $this->getDispatchLog();
        $existingGaps = $this->getGaps();
        $existingResolvedKeys = [];

        // Build lookup of existing gaps
        foreach ($existingGaps as $gap) {
            $key = $gap['seq'] . ':' . $gap['provider'];
            if ($gap['resolved']) {
                $existingResolvedKeys[$key] = true;
            }
        }

        // Find dispatched-but-not-confirmed entries
        $newGaps = [];
        foreach ($log as $entry) {
            if ($entry['status'] !== 'dispatched') {
                continue;
            }

            $key = $entry['seq'] . ':' . $entry['provider'];

            // Skip if already tracked as a gap
            if (isset($existingResolvedKeys[$key])) {
                continue;
            }

            // Check if we already have an unresolved gap for this
            $alreadyTracked = false;
            foreach ($existingGaps as $gap) {
                if ($gap['seq'] === $entry['seq'] && $gap['provider'] === $entry['provider'] && ! $gap['resolved']) {
                    $alreadyTracked = true;
                    break;
                }
            }

            if (! $alreadyTracked) {
                $newGaps[] = [
                    'seq' => $entry['seq'],
                    'event' => $entry['event'],
                    'provider' => $entry['provider'],
                    'detected_at' => microtime(true),
                    'resolved' => false,
                    'resolved_at' => null,
                ];
            }
        }

        // Merge new gaps with existing unresolved ones
        $allGaps = array_filter(
            $existingGaps,
            static fn (array $g): bool => ! $g['resolved'],
        );
        $allGaps = array_values(array_merge($allGaps, $newGaps));

        // Trim to max
        if (count($allGaps) > self::MAX_GAPS) {
            $allGaps = array_slice($allGaps, -self::MAX_GAPS);
        }

        $this->cache->put(self::GAPS_KEY, $allGaps, $this->ttl);

        // Build by_provider summary
        $byProvider = [];
        foreach ($allGaps as $gap) {
            $byProvider[$gap['provider']] = ($byProvider[$gap['provider']] ?? 0) + 1;
        }

        return [
            'total_gaps' => count($allGaps),
            'by_provider' => $byProvider,
            'gaps' => $allGaps,
        ];
    }

    /**
     * Get gaps for a specific provider.
     *
     * @param  string  $provider  Provider identifier
     * @return list<GapRecord>
     */
    public function gapsForProvider(string $provider): array
    {
        $gaps = $this->getGaps();

        return array_values(array_filter(
            $gaps,
            static fn (array $g): bool => $g['provider'] === $provider && ! $g['resolved'],
        ));
    }

    /**
     * Get sequence numbers that can be replayed for a provider.
     *
     * Returns dispatch log entries from the provider's confirmed watermark
     * onward that were dispatched but not confirmed (gaps).
     *
     * @param  string  $provider  Provider identifier
     * @return list<array{seq: int, event: string, timestamp: float}>
     */
    public function replayableGaps(string $provider): array
    {
        $confirmed = $this->providerWatermark($provider);
        $log = $this->getDispatchLog();
        $replayable = [];

        foreach ($log as $entry) {
            if (
                $entry['provider'] === $provider
                && $entry['seq'] > $confirmed
                && $entry['status'] === 'dispatched'
            ) {
                $replayable[] = [
                    'seq' => $entry['seq'],
                    'event' => $entry['event'],
                    'timestamp' => $entry['timestamp'],
                ];
            }
        }

        return $replayable;
    }

    /**
     * Get the checkpoint (resume point) for a provider.
     *
     * Returns the sequence number after the last confirmed delivery,
     * which is where replay should start from.
     *
     * @param  string  $provider  Provider identifier
     * @return int Resume-from sequence number
     */
    public function resumeCheckpoint(string $provider): int
    {
        return $this->providerWatermark($provider) + 1;
    }

    // ── Cross-Provider Consistency ───────────────────────────────────

    /**
     * Compute cross-provider delivery consistency metrics.
     *
     * Measures how evenly events are being delivered across all
     * providers. High consistency means all providers are at similar
     * watermarks; low consistency means some providers are significantly
     * behind.
     *
     * @return array{consistency_score: float, max_lag: int, min_lag: int, avg_lag: float, lag_std_dev: float, most_behind: string|null, most_current: string|null, provider_count: int, status: 'consistent'|'moderate'|'inconsistent'|'critical'}
     */
    public function consistencyReport(): array
    {
        $statuses = $this->providerStatuses();

        if ($statuses === []) {
            return [
                'consistency_score' => 100.0,
                'max_lag' => 0,
                'min_lag' => 0,
                'avg_lag' => 0.0,
                'lag_std_dev' => 0.0,
                'most_behind' => null,
                'most_current' => null,
                'provider_count' => 0,
                'status' => 'consistent',
            ];
        }

        $lags = array_column($statuses, 'lag');
        $maxLag = max($lags);
        $minLag = min($lags);
        $avgLag = array_sum($lags) / count($lags);

        // Standard deviation
        $variance = 0.0;
        foreach ($lags as $lag) {
            $variance += ($lag - $avgLag) ** 2;
        }
        $stdDev = (float) sqrt($variance / count($lags));

        // Consistency score: 100 if max_lag is 0, degrades linearly
        $globalHwm = $this->globalHighWaterMark();
        $consistencyScore = $globalHwm > 0
            ? round(max(0.0, 100.0 - (($maxLag / max(1, $globalHwm)) * 100.0)), 1)
            : 100.0;

        // Status classification
        if ($consistencyScore >= 95.0) {
            $status = 'consistent';
        } elseif ($consistencyScore >= 80.0) {
            $status = 'moderate';
        } elseif ($consistencyScore >= 60.0) {
            $status = 'inconsistent';
        } else {
            $status = 'critical';
        }

        // Find most behind / most current
        $mostBehind = null;
        $mostCurrent = null;
        $highestLag = -1;
        $lowestLag = PHP_INT_MAX;

        foreach ($statuses as $s) {
            if ($s['lag'] > $highestLag) {
                $highestLag = $s['lag'];
                $mostBehind = $s['provider'];
            }
            if ($s['lag'] < $lowestLag) {
                $lowestLag = $s['lag'];
                $mostCurrent = $s['provider'];
            }
        }

        return [
            'consistency_score' => $consistencyScore,
            'max_lag' => $maxLag,
            'min_lag' => $minLag,
            'avg_lag' => round($avgLag, 1),
            'lag_std_dev' => round($stdDev, 2),
            'most_behind' => $mostBehind,
            'most_current' => $mostCurrent,
            'provider_count' => count($statuses),
            'status' => $status,
        ];
    }

    // ── Dispatch Log ────────────────────────────────────────────────

    /**
     * Get the recent dispatch log.
     *
     * @param  int  $limit  Maximum entries to return
     * @return list<DispatchEntry>
     */
    public function dispatchLog(int $limit = 50): array
    {
        $log = $this->getDispatchLog();

        return array_slice($log, -$limit);
    }

    /**
     * Get dispatch log filtered by provider.
     *
     * @param  string  $provider  Provider identifier
     * @param  int  $limit  Maximum entries
     * @return list<DispatchEntry>
     */
    public function dispatchLogForProvider(string $provider, int $limit = 50): array
    {
        $log = $this->getDispatchLog();
        $filtered = array_values(array_filter(
            $log,
            static fn (array $e): bool => $e['provider'] === $provider,
        ));

        return array_slice($filtered, -$limit);
    }

    /**
     * Get dispatch statistics summary.
     *
     * @return array{total_dispatched: int, total_confirmed: int, total_failed: int, confirmation_rate: float, providers_tracked: int}
     */
    public function dispatchStats(): array
    {
        $log = $this->getDispatchLog();
        $dispatched = 0;
        $confirmed = 0;
        $failed = 0;
        $providersSeen = [];

        foreach ($log as $entry) {
            $providersSeen[$entry['provider']] = true;

            match ($entry['status']) {
                'dispatched' => $dispatched++,
                'confirmed' => $confirmed++,
                'failed' => $failed++,
                default => null,
            };
        }

        $total = $dispatched + $confirmed + $failed;

        return [
            'total_dispatched' => $total,
            'total_confirmed' => $confirmed,
            'total_failed' => $failed,
            'confirmation_rate' => $total > 0 ? round(($confirmed / $total) * 100.0, 1) : 100.0,
            'providers_tracked' => count($providersSeen),
        ];
    }

    // ── Dashboard Summary ───────────────────────────────────────────

    /**
     * Get a comprehensive dashboard summary of the watermark system.
     *
     * Combines watermarks, consistency, gaps, and dispatch stats
     * into a single response for admin dashboards.
     *
     * @return array{enabled: bool, global_hwm: int, providers: list<ProviderStatus>, consistency: array{consistency_score: float, status: string}, gaps: array{total: int, by_provider: array<string, int>}, dispatch_stats: array{total_dispatched: int, confirmation_rate: float}, config: array{ttl: int, log_size: int, gap_window: int, lag_warning: int, lag_critical: int}}
     */
    public function dashboard(): array
    {
        $consistency = $this->consistencyReport();
        $gaps = $this->detectGaps();
        $stats = $this->dispatchStats();

        return [
            'enabled' => $this->enabled,
            'global_hwm' => $this->globalHighWaterMark(),
            'providers' => $this->providerStatuses(),
            'consistency' => [
                'consistency_score' => $consistency['consistency_score'],
                'status' => $consistency['status'],
            ],
            'gaps' => [
                'total' => $gaps['total_gaps'],
                'by_provider' => $gaps['by_provider'],
            ],
            'dispatch_stats' => [
                'total_dispatched' => $stats['total_dispatched'],
                'confirmation_rate' => $stats['confirmation_rate'],
            ],
            'config' => [
                'ttl' => $this->ttl,
                'log_size' => $this->logSize,
                'gap_window' => $this->gapWindow,
                'lag_warning' => $this->lagWarningThreshold,
                'lag_critical' => $this->lagCriticalThreshold,
            ],
        ];
    }

    // ── Internal Helpers ─────────────────────────────────────────────

    /**
     * Get the dispatch log from cache.
     *
     * @return list<DispatchEntry>
     */
    private function getDispatchLog(): array
    {
        $log = $this->cache->get(self::DISPATCH_LOG_KEY);

        return is_array($log) ? $log : [];
    }

    /**
     * Get the gap records from cache.
     *
     * @return list<GapRecord>
     */
    private function getGaps(): array
    {
        $gaps = $this->cache->get(self::GAPS_KEY);

        return is_array($gaps) ? $gaps : [];
    }

    /**
     * Resolve a gap record when delivery is confirmed.
     *
     * @param  int  $seq  Sequence number
     * @param  string  $provider  Provider identifier
     */
    private function resolveGap(int $seq, string $provider): void
    {
        $gaps = $this->getGaps();
        $changed = false;

        foreach ($gaps as $i => $gap) {
            if ($gap['seq'] === $seq && $gap['provider'] === $provider && ! $gap['resolved']) {
                $gaps[$i]['resolved'] = true;
                $gaps[$i]['resolved_at'] = microtime(true);
                $changed = true;
            }
        }

        if ($changed) {
            $this->cache->put(self::GAPS_KEY, $gaps, $this->ttl);
        }
    }

    /**
     * Find the last event name dispatched to a provider from the log.
     *
     * @param  list<DispatchEntry>  $log  Dispatch log entries
     * @param  string  $provider  Provider identifier
     * @return string|null Last event name or null
     */
    private function findLastEventForProvider(array $log, string $provider): ?string
    {
        $lastEvent = null;

        foreach ($log as $entry) {
            if ($entry['provider'] === $provider) {
                $lastEvent = $entry['event'];
            }
        }

        return $lastEvent;
    }

    /**
     * Check if the watermark service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the list of tracked provider names.
     *
     * @return list<string>
     */
    public function trackedProviders(): array
    {
        return $this->providers;
    }
}
