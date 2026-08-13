<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Analytics dispatch metrics for observability.
 *
 * Tracks event dispatch counts, success/failure rates, and per-provider
 * statistics. Useful for monitoring analytics health, debugging dispatch
 * issues, and building internal dashboards.
 *
 * All counters are stored in memory (per-request) and can be flushed
 * to the log or retrieved programmatically. In queued dispatch, each
 * queue job maintains its own counter instance.
 *
 * @since 1.0.0
 */
final class AnalyticsMetrics
{
    /** @var array<string, int> */
    private array $dispatched = [];

    /** @var array<string, int> */
    private array $failed = [];

    /** @var array<string, int> */
    private array $filtered = [];

    /** @var array<string, int> */
    private array $deduplicated = [];

    /** @var array<string, int> Generic counters for extensibility (enrichment, custom metrics) */
    private array $counters = [];

    private bool $enabled;

    private bool $logOnFlush;

    /**
     * @param  ConfigRepository|null  $config  Optional config for testing
     */
    public function __construct(?ConfigRepository $config = null): void
    {
        if ($config !== null) {
            $metricsConfig = $config->get('zeroboiler.analytics.metrics', []);
            /** @var array{enabled?: bool, log_on_flush?: bool} $metricsConfig */
            $this->enabled = (bool) ($metricsConfig['enabled'] ?? false);
            $this->logOnFlush = (bool) ($metricsConfig['log_on_flush'] ?? false);
        } else {
            $this->enabled = false;
            $this->logOnFlush = false;
        }
    }

    /**
     * Record a successful event dispatch to a specific provider.
     *
     * @param  string  $provider  Provider name (ga4, gtm, meta, plausible, posthog, webhook)
     */
    public function recordDispatch(string $provider): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->dispatched[$provider] = ($this->dispatched[$provider] ?? 0) + 1;
    }

    /**
     * Record a failed event dispatch to a specific provider.
     *
     * @param  string  $provider  Provider name
     * @param  string  $reason  Failure reason (optional, for debugging)
     */
    public function recordFailure(string $provider, string $reason = ''): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->failed[$provider] = ($this->failed[$provider] ?? 0) + 1;

        if ($reason !== '' && $this->logOnFlush) {
            Log::debug('ZeroBoiler Analytics: dispatch failure', [
                'provider' => $provider,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * Record a filtered event (consent denied, pipeline filtered).
     */
    public function recordFiltered(): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->filtered['total'] = ($this->filtered['total'] ?? 0) + 1;
    }

    /**
     * Record a deduplicated event (same event within window).
     */
    public function recordDeduplicated(): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->deduplicated['total'] = ($this->deduplicated['total'] ?? 0) + 1;
    }

    /**
     * Increment a generic counter by name.
     *
     * Used by extension services (enrichment orchestrator, custom plugins)
     * to track arbitrary metrics without modifying the core counters.
     *
     * @param  string  $key  Counter name (e.g. 'enrichment.enriched', 'enrichment.dropped')
     * @param  int  $amount  Amount to increment (default: 1)
     */
    public function increment(string $key, int $amount = 1): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->counters[$key] = ($this->counters[$key] ?? 0) + $amount;
    }

    /**
     * Get the value of a generic counter.
     */
    public function counter(string $key): int
    {
        return $this->counters[$key] ?? 0;
    }

    /**
     * Get all generic counters.
     *
     * @return array<string, int>
     */
    public function counters(): array
    {
        return $this->counters;
    }

    /**
     * Get total dispatched events across all providers.
     */
    public function totalDispatched(): int
    {
        return array_sum($this->dispatched);
    }

    /**
     * Get total failed events across all providers.
     */
    public function totalFailed(): int
    {
        return array_sum($this->failed);
    }

    /**
     * Get total filtered events.
     */
    public function totalFiltered(): int
    {
        return $this->filtered['total'] ?? 0;
    }

    /**
     * Get total deduplicated events.
     */
    public function totalDeduplicated(): int
    {
        return $this->deduplicated['total'] ?? 0;
    }

    /**
     * Get per-provider dispatch counts.
     *
     * @return array<string, int>
     */
    public function dispatchedByProvider(): array
    {
        return $this->dispatched;
    }

    /**
     * Get per-provider failure counts.
     *
     * @return array<string, int>
     */
    public function failuresByProvider(): array
    {
        return $this->failed;
    }

    /**
     * Get a full metrics summary.
     *
     * @return array{dispatched: array<string, int>, failed: array<string, int>, filtered: int, deduplicated: int, total_dispatched: int, total_failed: int, counters: array<string, int>}
     */
    public function summary(): array
    {
        return [
            'dispatched' => $this->dispatched,
            'failed' => $this->failed,
            'filtered' => $this->filtered['total'] ?? 0,
            'deduplicated' => $this->deduplicated['total'] ?? 0,
            'total_dispatched' => $this->totalDispatched(),
            'total_failed' => $this->totalFailed(),
            'counters' => $this->counters,
        ];
    }

    /**
     * Flush all metrics (reset counters to zero).
     */
    public function flush(): void
    {
        $this->dispatched = [];
        $this->failed = [];
        $this->filtered = [];
        $this->deduplicated = [];
        $this->counters = [];
    }

    /**
     * Flush metrics and optionally log the summary.
     */
    public function flushAndLog(): void
    {
        if ($this->logOnFlush && ($this->totalDispatched() > 0 || $this->totalFailed() > 0)) {
            Log::debug('ZeroBoiler Analytics: metrics flushed', $this->summary());
        }

        $this->flush();
    }

    /**
     * Check if metrics tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Enable or disable metrics tracking.
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }
}
