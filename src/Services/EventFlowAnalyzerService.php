<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Event flow pattern analyzer — detects causal chains and bottleneck events.
 *
 * Analyzes the event dependency graph to identify:
 * - Causal flow patterns (which events typically precede which events)
 * - Bottleneck events (events with high incoming but low outgoing edges)
 * - Bridge events (events that connect otherwise disconnected flow segments)
 * - Flow clusters (groups of tightly-coupled events)
 * - Critical path analysis for SaaS, ecommerce, and engagement funnels
 *
 * Results are cache-backed for dashboard performance. Cache TTL is
 * configurable via `zeroboiler.analytics.flow_analyzer.cache_ttl`.
 *
 * @see \ZeroBoiler\Analytics\Events\EventCatalog::causalEdges()
 * @see \ZeroBoiler\Analytics\Events\EventCatalog::eventDependencyGraph()
 *
 * @since 202.0.0
 */
final class EventFlowAnalyzerService
{
    private const CACHE_PREFIX = 'zb_flow_analyzer:';

    private readonly CacheRepository $cache;

    private readonly int $cacheTtl;

    private readonly bool $enabled;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;

        $flowConfig = $config->get('zeroboiler.analytics.flow_analyzer', []);
        /** @var array{enabled?: bool, cache_ttl?: int} $flowConfig */
        $this->enabled = (bool) ($flowConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($flowConfig['cache_ttl'] ?? 1800);
    }

    /**
     * Check if the flow analyzer is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the full flow analysis report.
     *
     * Aggregates graph metrics, bottleneck analysis, bridge detection,
     * funnel critical paths, and flow clusters into a single report.
     * Results are cached for the configured TTL.
     *
     * @return array{timestamp: string, graph: array<string, mixed>, bottlenecks: list<array<string, mixed>>, bridges: list<string>, clusters: list<list<string>>, critical_paths: array<string, mixed>, flow_health: array<string, mixed>}
     */
    public function analyze(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'full_report';

        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $report = [
            'timestamp' => now()->toIso8601String(),
            'graph' => $this->graphMetrics(),
            'bottlenecks' => $this->detectBottlenecks(),
            'bridges' => $this->detectBridges(),
            'clusters' => $this->detectClusters(),
            'critical_paths' => $this->criticalPathsReport(),
            'flow_health' => $this->flowHealthScore(),
        ];

        $this->cache->put($cacheKey, $report, $this->cacheTtl);

        return $report;
    }

    /**
     * Get basic graph metrics.
     *
     * @return array{nodes: int, edges: int, density: float, avg_out_degree: float, max_out_degree: int, avg_in_degree: float, max_in_degree: int, isolated_nodes: list<string>}
     */
    public function graphMetrics(): array
    {
        $graph = EventCatalog::eventDependencyGraph();
        $forward = $graph['forward'];
        $reverse = $graph['reverse'];
        $nodes = $graph['nodes'];
        $nodeCount = count($nodes);

        if ($nodeCount === 0) {
            return [
                'nodes' => 0,
                'edges' => 0,
                'density' => 0.0,
                'avg_out_degree' => 0.0,
                'max_out_degree' => 0,
                'avg_in_degree' => 0.0,
                'max_in_degree' => 0,
                'isolated_nodes' => [],
            ];
        }

        $maxPossibleEdges = $nodeCount * ($nodeCount - 1);

        // Compute degree distributions
        $outDegrees = [];
        $inDegrees = [];
        $totalOut = 0;
        $totalIn = 0;
        $isolated = [];

        foreach ($nodes as $node) {
            $outDeg = count($forward[$node] ?? []);
            $inDeg = count($reverse[$node] ?? []);
            $outDegrees[$node] = $outDeg;
            $inDegrees[$node] = $inDeg;
            $totalOut += $outDeg;
            $totalIn += $inDeg;

            if ($outDeg === 0 && $inDeg === 0) {
                $isolated[] = $node;
            }
        }

        return [
            'nodes' => $nodeCount,
            'edges' => $graph['edge_count'],
            'density' => $maxPossibleEdges > 0
                ? round($graph['edge_count'] / $maxPossibleEdges, 4)
                : 0.0,
            'avg_out_degree' => round($totalOut / $nodeCount, 2),
            'max_out_degree' => max($outDegrees),
            'avg_in_degree' => round($totalIn / $nodeCount, 2),
            'max_in_degree' => max($inDegrees),
            'isolated_nodes' => $isolated,
        ];
    }

    /**
     * Detect bottleneck events — high incoming, low outgoing edges.
     *
     * Bottlenecks are events that receive many causal predecessors but
     * have few causal successors. These represent convergence points
     * in the event flow where many paths converge before diverging.
     *
     * @return list<array{event: string, in_degree: int, out_degree: int, bottleneck_score: float, severity: string}>
     */
    public function detectBottlenecks(): array
    {
        $graph = EventCatalog::eventDependencyGraph();
        $forward = $graph['forward'];
        $reverse = $graph['reverse'];
        $nodes = $graph['nodes'];
        $maxIn = 0;

        // First pass: find max in-degree for normalization
        foreach ($nodes as $node) {
            $inDeg = count($reverse[$node] ?? []);
            if ($inDeg > $maxIn) {
                $maxIn = $inDeg;
            }
        }

        $bottlenecks = [];

        foreach ($nodes as $node) {
            $inDeg = count($reverse[$node] ?? []);
            $outDeg = count($forward[$node] ?? []);

            // Only consider nodes with at least 1 incoming edge
            if ($inDeg === 0) {
                continue;
            }

            // Bottleneck score: high in-degree, low out-degree
            // Range: 0.0 (not a bottleneck) to 1.0 (maximum bottleneck)
            $inNorm = $maxIn > 0 ? $inDeg / $maxIn : 0.0;
            $outRatio = $inDeg > 0 ? $outDeg / $inDeg : 0.0;
            $score = round($inNorm * max(0.0, 1.0 - $outRatio), 3);

            if ($score >= 0.3) {
                $severity = match (true) {
                    $score >= 0.8 => 'critical',
                    $score >= 0.5 => 'warning',
                    default => 'info',
                };

                $bottlenecks[] = [
                    'event' => $node,
                    'in_degree' => $inDeg,
                    'out_degree' => $outDeg,
                    'bottleneck_score' => $score,
                    'severity' => $severity,
                ];
            }
        }

        // Sort by bottleneck score descending
        usort($bottlenecks, fn (array $a, array $b): int => $b['bottleneck_score'] <=> $a['bottleneck_score']);

        return $bottlenecks;
    }

    /**
     * Detect bridge events — events whose removal would disconnect flow segments.
     *
     * Bridge events are articulation points in the causal graph. They sit
     * between two otherwise disconnected subgraphs. Removing them would
     * break causal paths between events in different segments.
     *
     * Uses a simple bridge detection heuristic: events that are the sole
     * predecessor of at least one other event.
     *
     * @return list<string>
     */
    public function detectBridges(): array
    {
        $graph = EventCatalog::eventDependencyGraph();
        $reverse = $graph['reverse'];
        $bridges = [];

        foreach ($reverse as $node => $predecessors) {
            if (count($predecessors) === 1) {
                $predecessor = $predecessors[0];

                // Check if the predecessor has other successors
                $forward = $graph['forward'][$predecessor] ?? [];
                if (count($forward) > 1) {
                    // This predecessor has multiple successors, but $node
                    // only has one predecessor — predecessor is a bridge
                    if (! in_array($predecessor, $bridges, true)) {
                        $bridges[] = $predecessor;
                    }
                }
            }
        }

        return $bridges;
    }

    /**
     * Detect flow clusters — groups of tightly-coupled events.
     *
     * Uses simple connected component analysis on the undirected
     * version of the causal graph. Each cluster represents a group
     * of events that are causally connected.
     *
     * @return list<list<string>>  List of clusters, each cluster is a list of event names
     */
    public function detectClusters(): array
    {
        $graph = EventCatalog::eventDependencyGraph();
        $forward = $graph['forward'];
        $reverse = $graph['reverse'];
        $nodes = $graph['nodes'];

        // Build undirected adjacency list
        /** @var array<string, list<string>> $adjacent */
        $adjacent = [];
        foreach ($nodes as $node) {
            $adjacent[$node] = [];
        }

        foreach ($forward as $source => $targets) {
            foreach ($targets as $target) {
                $adjacent[$source][] = $target;
                $adjacent[$target][] = $source;
            }
        }

        // BFS to find connected components
        $visited = [];
        $clusters = [];

        foreach ($nodes as $node) {
            if (isset($visited[$node])) {
                continue;
            }

            // BFS
            $cluster = [];
            $queue = [$node];
            $visited[$node] = true;

            while ($queue !== []) {
                $current = array_shift($queue);
                $cluster[] = $current;

                foreach ($adjacent[$current] ?? [] as $neighbor) {
                    if (! isset($visited[$neighbor])) {
                        $visited[$neighbor] = true;
                        $queue[] = $neighbor;
                    }
                }
            }

            if ($cluster !== []) {
                $clusters[] = $cluster;
            }
        }

        // Sort clusters by size descending
        usort($clusters, fn (array $a, array $b): int => count($b) <=> count($a));

        return $clusters;
    }

    /**
     * Get critical path analysis for all funnel types.
     *
     * @return array{saas: array<string, mixed>, ecommerce: array<string, mixed>, engagement: array<string, mixed>, summary: array{saas_depth: int|null, ecommerce_depth: int|null, engagement_depth: int|null}}
     */
    public function criticalPathsReport(): array
    {
        $saas = EventCatalog::funnelCriticalPaths('saas');
        $ecommerce = EventCatalog::funnelCriticalPaths('ecommerce');
        $engagement = EventCatalog::funnelCriticalPaths('engagement');

        return [
            'saas' => $saas,
            'ecommerce' => $ecommerce,
            'engagement' => $engagement,
            'summary' => [
                'saas_depth' => $saas['max_depth'] ?? null,
                'ecommerce_depth' => $ecommerce['max_depth'] ?? null,
                'engagement_depth' => $engagement['max_depth'] ?? null,
            ],
        ];
    }

    /**
     * Compute an overall flow health score.
     *
     * Combines graph density, bottleneck severity, and cluster count
     * into a single health score (0-100). Higher scores indicate
     * a healthier, more connected event flow graph.
     *
     * @return array{score: int, grade: string, density_score: float, bottleneck_penalty: float, connectivity_score: float, issues: list<string>}
     */
    public function flowHealthScore(): array
    {
        $metrics = $this->graphMetrics();
        $bottlenecks = $this->detectBottlenecks();
        $bridges = $this->detectBridges();
        $clusters = $this->detectClusters();
        $nodeCount = $metrics['nodes'];

        if ($nodeCount === 0) {
            return [
                'score' => 0,
                'grade' => 'N/A',
                'density_score' => 0.0,
                'bottleneck_penalty' => 0.0,
                'connectivity_score' => 0.0,
                'issues' => ['Empty event catalog'],
            ];
        }

        // Density score (0-40): how connected is the graph?
        $densityScore = min(40.0, $metrics['density'] * 100);

        // Connectivity score (0-30): fewer isolated nodes, reasonable cluster count
        $isolatedRatio = count($metrics['isolated_nodes']) / $nodeCount;
        $connectivityScore = max(0.0, 30.0 * (1.0 - $isolatedRatio));

        // Bottleneck penalty (-30 to 0): severe bottlenecks reduce health
        $criticalBottlenecks = count(array_filter(
            $bottlenecks,
            fn (array $b): bool => ($b['severity'] ?? '') === 'critical',
        ));
        $warningBottlenecks = count(array_filter(
            $bottlenecks,
            fn (array $b): bool => ($b['severity'] ?? '') === 'warning',
        ));
        $bottleneckPenalty = min(30.0, ($criticalBottlenecks * 10) + ($warningBottlenecks * 5));

        // Raw score
        $rawScore = $densityScore + $connectivityScore - $bottleneckPenalty;
        $score = max(0, min(100, (int) round($rawScore)));

        // Grade
        $grade = match (true) {
            $score >= 80 => 'A',
            $score >= 60 => 'B',
            $score >= 40 => 'C',
            $score >= 20 => 'D',
            default => 'F',
        };

        // Issues
        $issues = [];
        if ($criticalBottlenecks > 0) {
            $issues[] = "{$criticalBottlenecks} critical bottleneck(s) detected";
        }
        if ($warningBottlenecks > 0) {
            $issues[] = "{$warningBottlenecks} warning-level bottleneck(s) detected";
        }
        if (count($bridges) > 0) {
            $issues[] = count($bridges) . " bridge event(s) — single points of flow dependency";
        }
        if (count($metrics['isolated_nodes']) > 0) {
            $issues[] = count($metrics['isolated_nodes']) . " isolated event(s) with no causal connections";
        }
        if ($issues === []) {
            $issues[] = 'No issues detected';
        }

        return [
            'score' => $score,
            'grade' => $grade,
            'density_score' => round($densityScore, 2),
            'bottleneck_penalty' => round($bottleneckPenalty, 2),
            'connectivity_score' => round($connectivityScore, 2),
            'issues' => $issues,
        ];
    }

    /**
     * Get causal ancestors for an event (events that typically precede it).
     *
     * @param  string  $event  Event name
     * @param  int  $depth  How many hops back to traverse (default: 1)
     * @return list<string>
     */
    public function ancestorsOf(string $event, int $depth = 1): array
    {
        return EventCatalog::causalAncestors($event, $depth);
    }

    /**
     * Get causal descendants for an event (events that typically follow it).
     *
     * @param  string  $event  Event name
     * @param  int  $depth  How many hops forward to traverse (default: 1)
     * @return list<string>
     */
    public function descendantsOf(string $event, int $depth = 1): array
    {
        return EventCatalog::causalDescendants($event, $depth);
    }

    /**
     * Find all causal paths between two events.
     *
     * @param  string  $from  Source event name
     * @param  string  $to  Target event name
     * @param  int  $maxDepth  Maximum path length (default: 8)
     * @return list<list<string>>
     */
    public function pathsBetween(string $from, string $to, int $maxDepth = 8): array
    {
        return EventCatalog::causalPaths($from, $to, $maxDepth);
    }

    /**
     * Clear the analysis cache.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache->forget(self::CACHE_PREFIX . 'full_report');
    }
}
