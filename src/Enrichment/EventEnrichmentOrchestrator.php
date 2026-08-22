<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Enrichment;

use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\AnalyticsMetrics;

/**
 * Orchestrates enrichment plugin execution in the event pipeline.
 *
 * Runs all registered EventEnrichmentPlugin instances in priority order,
 * passing the event through each plugin's `enrich()` method. If any plugin
 * returns null, the event is dropped (similar to pipeline filters).
 *
 * Tracks enrichment metrics including:
 * - Total events processed
 * - Events enriched (at least one plugin modified the event)
 * - Events dropped (plugin returned null)
 * - Events passed through unchanged
 * - Per-plugin execution counts and timing
 *
 * @see EventEnrichmentPlugin
 * @see EventEnrichmentRegistry
 *
 * @since 57.0.0
 */
final class EventEnrichmentOrchestrator
{
    private bool $debug;

    /** @var array<string, int> Per-plugin enrichment count */
    private array $pluginCounts = [];

    /** @var array<string, float> Per-plugin total execution time (ms) */
    private array $pluginTimings = [];

    /** @var array<string, int> Per-plugin drop count */
    private array $pluginDropCounts = [];

    private int $totalProcessed = 0;

    private int $totalEnriched = 0;

    private int $totalDropped = 0;

    private int $totalPassed = 0;

    /**
     * Create a new enrichment orchestrator.
     *
     * @param  EventEnrichmentRegistry  $registry
     * @param  AnalyticsMetrics  $metrics
     * @param  bool  $debug  Whether to log debug messages
     */
    public function __construct(
        private readonly EventEnrichmentRegistry $registry,
        private readonly AnalyticsMetrics $metrics,
        bool $debug = false,
    ){
        $this->debug = $debug;
    }

    /**
     * Run all enrichment plugins on the given event.
     *
     * Plugins are executed in priority order (highest first).
     * If any plugin returns null, the event is dropped immediately.
     * If any plugin returns a different event instance, the event is
     * considered enriched and metrics are updated.
     *
     * @param  AnalyticsEvent  $event  The event to enrich
     * @return AnalyticsEvent|null The enriched event, or null if dropped
     */
    public function enrich(AnalyticsEvent $event): ?AnalyticsEvent
    {
        if (! $this->registry->isPluginSystemEnabled()) {
            return $event;
        }

        $this->totalProcessed++;

        $plugins = $this->registry->all();

        if ($plugins === []) {
            $this->totalPassed++;
            $this->metrics->increment('enrichment.passed');

            return $event;
        }

        $current = $event;
        $wasEnriched = false;

        foreach ($plugins as $plugin) {
            $name = $plugin->name();

            if (! $plugin->shouldEnrich($current)) {
                continue;
            }

            $start = hrtime(true);

            try {
                $result = $plugin->enrich($current);
            } catch (\Throwable $e) {
                $this->metrics->increment('enrichment.errors');

                if ($this->debug) {
                    Log::debug("[ZeroBoiler] Enrichment plugin '{$name}' threw exception: {$e->getMessage()}");
                }

                // Continue to next plugin instead of dropping the event
                continue;
            }

            $elapsed = (hrtime(true) - $start) / 1_000_000;

            // Track per-plugin metrics
            $this->pluginCounts[$name] = ($this->pluginCounts[$name] ?? 0) + 1;
            $this->pluginTimings[$name] = ($this->pluginTimings[$name] ?? 0.0) + $elapsed;

            // Plugin dropped the event
            if ($result === null) {
                $this->pluginDropCounts[$name] = ($this->pluginDropCounts[$name] ?? 0) + 1;
                $this->totalDropped++;
                $this->metrics->increment('enrichment.dropped');

                if ($this->debug) {
                    Log::debug("[ZeroBoiler] Event '{$event->name}' dropped by enrichment plugin '{$name}'.");
                }

                return null;
            }

            // Track if plugin modified the event
            if ($result !== $current) {
                $wasEnriched = true;
                $current = $result;
            }
        }

        if ($wasEnriched) {
            $this->totalEnriched++;
            $this->metrics->increment('enrichment.enriched');
        } else {
            $this->totalPassed++;
            $this->metrics->increment('enrichment.passed');
        }

        return $current;
    }

    /**
     * Get the enrichment registry.
     */
    public function registry(): EventEnrichmentRegistry
    {
        return $this->registry;
    }

    /**
     * Get enrichment metrics summary.
     *
     * @return array{total_processed: int, total_enriched: int, total_dropped: int, total_passed: int, plugins: list<array{name: string, count: int, drop_count: int, avg_time_ms: float}>}
     */
    public function metrics(): array
    {
        $pluginMetrics = [];
        foreach ($this->pluginCounts as $name => $count) {
            $totalTime = $this->pluginTimings[$name] ?? 0.0;
            $dropCount = $this->pluginDropCounts[$name] ?? 0;

            $pluginMetrics[] = [
                'name' => $name,
                'count' => $count,
                'drop_count' => $dropCount,
                'avg_time_ms' => $count > 0 ? round($totalTime / $count, 3) : 0.0,
            ];
        }

        usort($pluginMetrics, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'total_processed' => $this->totalProcessed,
            'total_enriched' => $this->totalEnriched,
            'total_dropped' => $this->totalDropped,
            'total_passed' => $this->totalPassed,
            'plugins' => $pluginMetrics,
        ];
    }

    /**
     * Reset all internal counters.
     */
    public function reset(): void
    {
        $this->pluginCounts = [];
        $this->pluginTimings = [];
        $this->pluginDropCounts = [];
        $this->totalProcessed = 0;
        $this->totalEnriched = 0;
        $this->totalDropped = 0;
        $this->totalPassed = 0;
    }
}
