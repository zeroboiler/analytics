<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
/**
 * Event Data Lineage Graph Service — builds a Directed Acyclic Graph (DAG)
 * from event lineage tracking data for visualization and path analysis.
 *
 * Converts flat lineage entries (recorded by EventLineageTrackerService)
 * into a structured DAG where nodes represent processing stages
 * (source, enrichment, provider, delivery) and edges represent data flow.
 *
 * Capabilities:
 * - Topological sort of processing stages
 * - Critical path analysis (longest latency path through the graph)
 * - Bottleneck detection (highest latency stages)
 * - DOT format export (Graphviz) for visualization
 * - JSON export for frontend rendering (D3.js, Cytoscape)
 * - Subgraph extraction (per-event, per-session, per-provider)
 * - Cycle detection (invalid lineage data)
 * - Aggregate graph statistics (node count, edge count, depth)
 *
 * Configuration is read from `zeroboiler.analytics.event_lineage_graph`.
 *
 * @see \ZeroBoiler\Analytics\Services\EventLineageTrackerService
 *
 * @since 236.0.0
 */
final class EventLineageGraphService
{
    /** @var string Cache key prefix for lineage graph data */
    private string $cachePrefix;

    /** @var int Cache TTL for graph data in seconds */
    private int $graphTtl;

    /** @var int Maximum number of nodes in a single graph (memory guard) */
    private int $maxNodes;

    /** @var int Maximum number of edges in a single graph (memory guard) */
    private int $maxEdges;

    /** @var bool Whether the service is enabled */
    private bool $enabled;

    /** @var bool Whether to auto-build graphs on lineage entry */
    private bool $autoBuild;

    /** @var bool Whether to track critical path latency */
    private bool $trackCriticalPath;

    /** @var int Latency threshold (ms) for bottleneck detection */
    private int $bottleneckThresholdMs;

    private CacheRepository $cache;

    /** @var ConfigRepository */
    private ConfigRepository $config;

    /**
     * @param  CacheRepository  $cache  Cache repository instance
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->cache = $cache;
        $this->config = $config;

        $graphConfig = $config->get('zeroboiler.analytics.event_lineage_graph', []);
        /** @var array{enabled?: bool, cache_prefix?: string, graph_ttl?: int, max_nodes?: int, max_edges?: int, auto_build?: bool, track_critical_path?: bool, bottleneck_threshold_ms?: int} $graphConfig */

        $this->enabled = (bool) ($graphConfig['enabled'] ?? true);
        $this->cachePrefix = (string) ($graphConfig['cache_prefix'] ?? 'zb_lineage_graph_');
        $this->graphTtl = (int) ($graphConfig['graph_ttl'] ?? 3600);
        $this->maxNodes = (int) ($graphConfig['max_nodes'] ?? 5000);
        $this->maxEdges = (int) ($graphConfig['max_edges'] ?? 20000);
        $this->autoBuild = (bool) ($graphConfig['auto_build'] ?? false);
        $this->trackCriticalPath = (bool) ($graphConfig['track_critical_path'] ?? true);
        $this->bottleneckThresholdMs = (int) ($graphConfig['bottleneck_threshold_ms'] ?? 100);
    }

    /**
     * Build a lineage DAG from a set of lineage entries.
     *
     * Each lineage entry represents a processing stage in the event lifecycle.
     * Nodes are stage identifiers (e.g., "source:api", "enrichment:utm", "provider:ga4").
     * Edges represent data flow between consecutive stages.
     *
     * @param  list<array{stage: string, type: string, latency_ms?: float|null, timestamp?: string|null, metadata?: array<string, mixed>}|array<string, mixed>  $lineageEntries
     * @return array{nodes: list<array{id: string, label: string, type: string, latency_ms: float|null, metadata: array<string, mixed>}>, edges: list<array{from: string, to: string, latency_ms: float|null, weight: int}>, metadata: array{event_count: int, total_latency_ms: float, depth: int, has_cycles: bool, critical_path: list<string>, bottlenecks: list<string>}}
     */
    public function buildGraph(array $lineageEntries): array
    {
        if (! $this->enabled) {
            return $this->emptyGraph();
        }

        $nodes = [];
        $edges = [];
        $nodeIndex = [];
        $totalLatency = 0.0;
        $prevId = null;

        foreach ($lineageEntries as $entry) {
            $stage = (string) ($entry['stage'] ?? 'unknown');
            $type = (string) ($entry['type'] ?? 'generic');
            $latency = isset($entry['latency_ms']) ? (float) $entry['latency_ms'] : null;
            $metadata = (array) ($entry['metadata'] ?? []);

            // Truncate if exceeding node limit
            if (count($nodes) >= $this->maxNodes) {
                break;
            }

            $nodeId = $this->normalizeNodeId($stage, $type);

            // Deduplicate nodes — keep first occurrence
            if (! isset($nodeIndex[$nodeId])) {
                $nodes[] = [
                    'id' => $nodeId,
                    'label' => $this->nodeLabel($stage, $type),
                    'type' => $type,
                    'latency_ms' => $latency,
                    'metadata' => $metadata,
                ];
                $nodeIndex[$nodeId] = array_key_last($nodes);
            }

            // Create edge from previous node
            if ($prevId !== null && $prevId !== $nodeId) {
                if (count($edges) < $this->maxEdges) {
                    $edgeKey = $prevId . '→' . $nodeId;
                    $edges[] = [
                        'from' => $prevId,
                        'to' => $nodeId,
                        'latency_ms' => $latency,
                        'weight' => 1,
                        'edge_key' => $edgeKey,
                    ];
                }
            }

            $prevId = $nodeId;

            if ($latency !== null) {
                $totalLatency += $latency;
            }
        }

        $hasCycles = $this->detectCycles($edges);
        $topoOrder = $hasCycles ? [] : $this->topologicalSort($nodes, $edges);
        $criticalPath = $this->trackCriticalPath ? $this->findCriticalPath($nodes, $edges) : [];
        $bottlenecks = $this->findBottlenecks($nodes);
        $depth = $this->calculateDepth($nodes, $edges);

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'metadata' => [
                'event_count' => count($lineageEntries),
                'total_latency_ms' => $totalLatency,
                'depth' => $depth,
                'has_cycles' => $hasCycles,
                'critical_path' => $criticalPath,
                'bottlenecks' => $bottlenecks,
            ],
        ];
    }

    /**
     * Build and cache a lineage graph for a specific event.
     *
     * @param  string  $eventId  The lineage/event ID
     * @param  list<array{stage: string, type: string, latency_ms?: float|null, metadata?: array<string, mixed>}>  $lineageEntries
     * @return array{nodes: list<array>, edges: list<array>, metadata: array<string, mixed>}
     */
    public function buildAndCacheGraph(string $eventId, array $lineageEntries): array
    {
        $graph = $this->buildGraph($lineageEntries);

        $this->cache->put(
            $this->cachePrefix . 'event_' . $eventId,
            $graph,
            $this->graphTtl,
        );

        return $graph;
    }

    /**
     * Retrieve a cached lineage graph for a specific event.
     *
     * @param  string  $eventId  The lineage/event ID
     * @return array{nodes: list<array>, edges: list<array>, metadata: array<string, mixed>}|null
     */
    public function getCachedGraph(string $eventId): ?array
    {
        /** @var array{nodes: list<array>, edges: list<array>, metadata: array<string, mixed>}|null $cached */
        $cached = $this->cache->get($this->cachePrefix . 'event_' . $eventId);

        return is_array($cached) ? $cached : null;
    }

    /**
     * Build an aggregate graph from multiple event lineage entries.
     *
     * Merges stages across events into a single graph with aggregated
     * latency statistics and edge weights (frequency counts).
     *
     * @param  array<string, list<array{stage: string, type: string, latency_ms?: float|null, metadata?: array<string, mixed>}>>  $eventLineages  Event ID → lineage entries
     * @return array{nodes: list<array{id: string, label: string, type: string, avg_latency_ms: float, max_latency_ms: float, min_latency_ms: float, event_count: int}>, edges: list<array{from: string, to: string, avg_latency_ms: float, weight: int}>, metadata: array{total_events: int, avg_total_latency_ms: float, depth: int, has_cycles: bool, critical_path: list<string>, bottlenecks: list<string>}}
     */
    public function buildAggregateGraph(array $eventLineages): array
    {
        if (! $this->enabled || empty($eventLineages)) {
            return $this->emptyAggregateGraph();
        }

        $nodeStats = [];
        $edgeStats = [];
        $totalEventLatencies = [];
        $prevIdsPerEvent = [];

        foreach ($eventLineages as $eventId => $entries) {
            $prevId = null;
            $eventLatency = 0.0;

            foreach ($entries as $entry) {
                $stage = (string) ($entry['stage'] ?? 'unknown');
                $type = (string) ($entry['type'] ?? 'generic');
                $latency = isset($entry['latency_ms']) ? (float) $entry['latency_ms'] : null;
                $nodeId = $this->normalizeNodeId($stage, $type);

                // Aggregate node stats
                if (! isset($nodeStats[$nodeId])) {
                    $nodeStats[$nodeId] = [
                        'id' => $nodeId,
                        'label' => $this->nodeLabel($stage, $type),
                        'type' => $type,
                        'latencies' => [],
                        'event_count' => 0,
                    ];
                }
                $nodeStats[$nodeId]['event_count']++;
                if ($latency !== null) {
                    $nodeStats[$nodeId]['latencies'][] = $latency;
                    $eventLatency += $latency;
                }

                // Aggregate edge stats
                if ($prevId !== null && $prevId !== $nodeId) {
                    $edgeKey = $prevId . '→' . $nodeId;
                    if (! isset($edgeStats[$edgeKey])) {
                        $edgeStats[$edgeKey] = [
                            'from' => $prevId,
                            'to' => $nodeId,
                            'latencies' => [],
                            'weight' => 0,
                        ];
                    }
                    $edgeStats[$edgeKey]['weight']++;
                    if ($latency !== null) {
                        $edgeStats[$edgeKey]['latencies'][] = $latency;
                    }
                }

                $prevId = $nodeId;
            }

            $totalEventLatencies[] = $eventLatency;
        }

        // Build final nodes with aggregated stats
        $nodes = [];
        foreach ($nodeStats as $stat) {
            $lats = $stat['latencies'];
            $nodes[] = [
                'id' => $stat['id'],
                'label' => $stat['label'],
                'type' => $stat['type'],
                'avg_latency_ms' => ! empty($lats) ? round(array_sum($lats) / count($lats), 2) : 0.0,
                'max_latency_ms' => ! empty($lats) ? round(max($lats), 2) : 0.0,
                'min_latency_ms' => ! empty($lats) ? round(min($lats), 2) : 0.0,
                'event_count' => $stat['event_count'],
            ];
        }

        // Build final edges with aggregated stats
        $edges = [];
        foreach ($edgeStats as $stat) {
            $lats = $stat['latencies'];
            $edges[] = [
                'from' => $stat['from'],
                'to' => $stat['to'],
                'avg_latency_ms' => ! empty($lats) ? round(array_sum($lats) / count($lats), 2) : 0.0,
                'weight' => $stat['weight'],
            ];
        }

        $hasCycles = $this->detectCycles($edges);
        $criticalPath = $this->trackCriticalPath ? $this->findCriticalPathByLatency($nodes, $edges) : [];
        $bottleneckIds = [];
        foreach ($nodes as $node) {
            if ($node['avg_latency_ms'] >= $this->bottleneckThresholdMs) {
                $bottleneckIds[] = $node['id'];
            }
        }
        $depth = $this->calculateDepth($nodes, $edges);

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'metadata' => [
                'total_events' => count($eventLineages),
                'avg_total_latency_ms' => ! empty($totalEventLatencies)
                    ? round(array_sum($totalEventLatencies) / count($totalEventLatencies), 2)
                    : 0.0,
                'depth' => $depth,
                'has_cycles' => $hasCycles,
                'critical_path' => $criticalPath,
                'bottlenecks' => $bottleneckIds,
            ],
        ];
    }

    /**
     * Export a graph to DOT format (Graphviz).
     *
     * Generates a directed graph DOT string suitable for rendering
     * with Graphviz (dot, neato, etc.) or embedding in documentation.
     *
     * @param  array{nodes: list<array{id: string, label: string, type: string, latency_ms?: float|null, avg_latency_ms?: float}>, edges: list<array{from: string, to: string, latency_ms?: float|null, avg_latency_ms?: float, weight?: int}>, metadata?: array<string, mixed>}  $graph
     * @return string  DOT format graph definition
     */
    public function exportDot(array $graph): string
    {
        $lines = ['digraph EventLineage {'];
        $lines[] = '    rankdir=TB;';
        $lines[] = '    node [shape=box, style=filled, fontname="Helvetica"];';

        $typeColors = [
            'source' => '#4CAF50',
            'enrichment' => '#2196F3',
            'validation' => '#FF9800',
            'provider' => '#9C27B0',
            'delivery' => '#F44336',
            'generic' => '#607D8B',
        ];

        foreach ($graph['nodes'] as $node) {
            $color = $typeColors[$node['type']] ?? $typeColors['generic'];
            $latency = $node['latency_ms'] ?? $node['avg_latency_ms'] ?? null;
            $label = $node['label'];
            if ($latency !== null) {
                $label .= sprintf(' (%.1fms)', $latency);
            }
            $lines[] = sprintf(
                '    "%s" [label="%s", fillcolor="%s"];',
                $node['id'],
                addslashes($label),
                $color,
            );
        }

        foreach ($graph['edges'] as $edge) {
            $label = '';
            if (isset($edge['weight']) && $edge['weight'] > 1) {
                $label = sprintf(' [label="×%d"]', $edge['weight']);
            }
            $lines[] = sprintf(
                '    "%s" -> "%s"%s;',
                $edge['from'],
                $edge['to'],
                $label,
            );
        }

        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * Export a graph to JSON format for frontend rendering.
     *
     * Output is compatible with D3.js force layout and Cytoscape.js.
     *
     * @param  array{nodes: list<array{id: string, label: string, type: string}>, edges: list<array{from: string, to: string}>}  $graph
     * @return string  JSON-encoded graph data
     */
    public function exportJson(array $graph): string
    {
        $output = [
            'directed' => true,
            'multigraph' => false,
            'graph' => $graph['metadata'] ?? [],
            'nodes' => array_map(
                fn (array $n): array => [
                    'id' => $n['id'],
                    'label' => $n['label'],
                    'type' => $n['type'],
                    'latency_ms' => $n['latency_ms'] ?? $n['avg_latency_ms'] ?? null,
                ],
                $graph['nodes'],
            ),
            'links' => array_map(
                fn (array $e): array => [
                    'source' => $e['from'],
                    'target' => $e['to'],
                    'weight' => $e['weight'] ?? 1,
                ],
                $graph['edges'],
            ),
        ];

        return json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Extract a subgraph for a specific processing stage type.
     *
     * Returns only nodes and edges matching the given type filter.
     *
     * @param  array{nodes: list<array{id: string, type: string, label: string}>, edges: list<array{from: string, to: string}>}  $graph
     * @param  string  $type  Stage type to filter (e.g., 'provider', 'enrichment')
     * @return array{nodes: list<array>, edges: list<array>, metadata: array{filtered_type: string, original_node_count: int, original_edge_count: int}}
     */
    public function extractSubgraph(array $graph, string $type): array
    {
        $nodeIds = [];
        $filteredNodes = [];

        foreach ($graph['nodes'] as $node) {
            if ($node['type'] === $type) {
                $nodeIds[$node['id']] = true;
                $filteredNodes[] = $node;
            }
        }

        $filteredEdges = [];
        foreach ($graph['edges'] as $edge) {
            if (isset($nodeIds[$edge['from']]) && isset($nodeIds[$edge['to']])) {
                $filteredEdges[] = $edge;
            }
        }

        return [
            'nodes' => $filteredNodes,
            'edges' => $filteredEdges,
            'metadata' => [
                'filtered_type' => $type,
                'original_node_count' => count($graph['nodes']),
                'original_edge_count' => count($graph['edges']),
            ],
        ];
    }

    /**
     * Get summary statistics for a lineage graph.
     *
     * @param  array{nodes: list<array>, edges: list<array>, metadata: array<string, mixed>}  $graph
     * @return array{node_count: int, edge_count: int, depth: int, has_cycles: bool, critical_path_length: int, bottleneck_count: int, avg_node_latency_ms: float, max_node_latency_ms: float}
     */
    public function graphSummary(array $graph): array
    {
        $latencies = [];

        foreach ($graph['nodes'] as $node) {
            $lat = $node['latency_ms'] ?? $node['avg_latency_ms'] ?? null;
            if ($lat !== null) {
                $latencies[] = (float) $lat;
            }
        }

        return [
            'node_count' => count($graph['nodes']),
            'edge_count' => count($graph['edges']),
            'depth' => $graph['metadata']['depth'] ?? 0,
            'has_cycles' => $graph['metadata']['has_cycles'] ?? false,
            'critical_path_length' => count($graph['metadata']['critical_path'] ?? []),
            'bottleneck_count' => count($graph['metadata']['bottlenecks'] ?? []),
            'avg_node_latency_ms' => ! empty($latencies) ? round(array_sum($latencies) / count($latencies), 2) : 0.0,
            'max_node_latency_ms' => ! empty($latencies) ? round(max($latencies), 2) : 0.0,
        ];
    }

    /**
     * Check if the service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Clear all cached lineage graphs.
     */
    public function clearCache(): void
    {
        // Clear known event-level graph caches
        $this->cache->flush();
    }

    /**
     * Normalize a stage identifier into a graph node ID.
     *
     * @param  string  $stage  Stage name
     * @param  string  $type  Stage type
     * @return string  Normalized node ID
     */
    private function normalizeNodeId(string $stage, string $type): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '_', $type . ':' . $stage) ?? $type . '_' . $stage);
    }

    /**
     * Generate a human-readable label for a node.
     *
     * @param  string  $stage  Stage name
     * @param  string  $type  Stage type
     * @return string  Human-readable label
     */
    private function nodeLabel(string $stage, string $type): string
    {
        $typeLabels = [
            'source' => '📥 Source',
            'enrichment' => '🔧 Enrichment',
            'validation' => '✅ Validation',
            'provider' => '📡 Provider',
            'delivery' => '📤 Delivery',
            'generic' => '⚙️ Stage',
        ];

        $prefix = $typeLabels[$type] ?? $typeLabels['generic'];

        return $prefix . ': ' . $stage;
    }

    /**
     * Detect cycles in the edge list using DFS.
     *
     * @param  list<array{from: string, to: string}>  $edges
     * @return bool  True if a cycle was detected
     */
    private function detectCycles(array $edges): bool
    {
        $adjacency = [];
        foreach ($edges as $edge) {
            $adjacency[$edge['from']][] = $edge['to'];
        }

        $visited = [];
        $recursionStack = [];

        foreach (array_keys($adjacency) as $node) {
            if ($this->hasCycleDfs($node, $adjacency, $visited, $recursionStack)) {
                return true;
            }
        }

        return false;
    }

    /**
     * DFS helper for cycle detection.
     *
     * @param  string  $node
     * @param  array<string, list<string>>  $adjacency
     * @param  array<string, bool>  $visited
     * @param  array<string, bool>  $recursionStack
     * @return bool
     */
    private function hasCycleDfs(string $node, array $adjacency, array &$visited, array &$recursionStack): bool
    {
        if (isset($recursionStack[$node])) {
            return true;
        }

        if (isset($visited[$node])) {
            return false;
        }

        $visited[$node] = true;
        $recursionStack[$node] = true;

        foreach ($adjacency[$node] ?? [] as $neighbor) {
            if ($this->hasCycleDfs($neighbor, $adjacency, $visited, $recursionStack)) {
                return true;
            }
        }

        $recursionStack[$node] = false;

        return false;
    }

    /**
     * Topological sort of graph nodes using Kahn's algorithm.
     *
     * @param  list<array{id: string}>  $nodes
     * @param  list<array{from: string, to: string}>  $edges
     * @return list<string>  Node IDs in topological order
     */
    private function topologicalSort(array $nodes, array $edges): array
    {
        $inDegree = [];
        $adjacency = [];

        foreach ($nodes as $node) {
            $inDegree[$node['id']] = 0;
        }

        foreach ($edges as $edge) {
            if (isset($inDegree[$edge['from']])) {
                $adjacency[$edge['from']][] = $edge['to'];
                if (isset($inDegree[$edge['to']])) {
                    $inDegree[$edge['to']]++;
                }
            }
        }

        $queue = [];
        foreach ($inDegree as $id => $degree) {
            if ($degree === 0) {
                $queue[] = $id;
            }
        }

        $result = [];
        while (! empty($queue)) {
            $current = array_shift($queue);
            $result[] = $current;

            foreach ($adjacency[$current] ?? [] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        return $result;
    }

    /**
     * Find the critical path (longest latency path) through the graph.
     *
     * Uses dynamic programming: for each node, calculate the longest
     * path from source to that node by summing edge latencies.
     *
     * @param  list<array{id: string, latency_ms: float|null}>  $nodes
     * @param  list<array{from: string, to: string, latency_ms: float|null}>  $edges
     * @return list<string>  Node IDs along the critical path
     */
    private function findCriticalPath(array $nodes, array $edges): array
    {
        if (empty($nodes)) {
            return [];
        }

        $dist = [];
        $prev = [];

        foreach ($nodes as $node) {
            $dist[$node['id']] = 0.0;
            $prev[$node['id']] = null;
        }

        // Build adjacency with latencies
        $adjacency = [];
        foreach ($edges as $edge) {
            $lat = $edge['latency_ms'] ?? 0.0;
            $adjacency[$edge['from']][$edge['to']] = $lat;
        }

        // Process in topological order
        $topoOrder = $this->topologicalSort($nodes, $edges);

        foreach ($topoOrder as $u) {
            foreach ($adjacency[$u] ?? [] as $v => $latency) {
                $newDist = $dist[$u] + $latency;
                if ($newDist > $dist[$v]) {
                    $dist[$v] = $newDist;
                    $prev[$v] = $u;
                }
            }
        }

        // Find the node with maximum distance
        $maxDist = 0.0;
        $endNode = null;

        foreach ($dist as $id => $d) {
            if ($d > $maxDist) {
                $maxDist = $d;
                $endNode = $id;
            }
        }

        if ($endNode === null) {
            return [];
        }

        // Trace back from end node
        $path = [];
        $current = $endNode;

        while ($current !== null) {
            array_unshift($path, $current);
            $current = $prev[$current];
        }

        return $path;
    }

    /**
     * Find critical path in aggregate graph using average latencies.
     *
     * @param  list<array{id: string, avg_latency_ms: float}>  $nodes
     * @param  list<array{from: string, to: string, avg_latency_ms: float}>  $edges
     * @return list<string>
     */
    private function findCriticalPathByLatency(array $nodes, array $edges): array
    {
        if (empty($nodes)) {
            return [];
        }

        $dist = [];
        $prev = [];

        foreach ($nodes as $node) {
            $dist[$node['id']] = 0.0;
            $prev[$node['id']] = null;
        }

        $adjacency = [];
        foreach ($edges as $edge) {
            $lat = $edge['avg_latency_ms'] ?? 0.0;
            $adjacency[$edge['from']][$edge['to']] = $lat;
        }

        $topoOrder = $this->topologicalSort($nodes, $edges);

        foreach ($topoOrder as $u) {
            foreach ($adjacency[$u] ?? [] as $v => $latency) {
                $newDist = $dist[$u] + $latency;
                if ($newDist > $dist[$v]) {
                    $dist[$v] = $newDist;
                    $prev[$v] = $u;
                }
            }
        }

        $maxDist = 0.0;
        $endNode = null;

        foreach ($dist as $id => $d) {
            if ($d > $maxDist) {
                $maxDist = $d;
                $endNode = $id;
            }
        }

        if ($endNode === null) {
            return [];
        }

        $path = [];
        $current = $endNode;

        while ($current !== null) {
            array_unshift($path, $current);
            $current = $prev[$current];
        }

        return $path;
    }

    /**
     * Find bottleneck nodes (stages with latency exceeding threshold).
     *
     * @param  list<array{id: string, latency_ms: float|null, avg_latency_ms?: float|null}>  $nodes
     * @return list<string>  Node IDs of bottleneck stages
     */
    private function findBottlenecks(array $nodes): array
    {
        $bottlenecks = [];

        foreach ($nodes as $node) {
            $lat = $node['latency_ms'] ?? $node['avg_latency_ms'] ?? null;
            if ($lat !== null && $lat >= $this->bottleneckThresholdMs) {
                $bottlenecks[] = $node['id'];
            }
        }

        return $bottlenecks;
    }

    /**
     * Calculate the maximum depth of the graph (longest path length in nodes).
     *
     * @param  list<array{id: string}>  $nodes
     * @param  list<array{from: string, to: string}>  $edges
     * @return int  Maximum depth (0 if no edges)
     */
    private function calculateDepth(array $nodes, array $edges): int
    {
        if (empty($edges) || empty($nodes)) {
            return 0;
        }

        $inDegree = [];
        foreach ($nodes as $node) {
            $inDegree[$node['id']] = 0;
        }

        foreach ($edges as $edge) {
            if (isset($inDegree[$edge['to']])) {
                $inDegree[$edge['to']]++;
            }
        }

        $queue = [];
        foreach ($inDegree as $id => $degree) {
            if ($degree === 0) {
                $queue[] = $id;
            }
        }

        $adjacency = [];
        foreach ($edges as $edge) {
            $adjacency[$edge['from']][] = $edge['to'];
        }

        $depth = [];
        foreach ($inDegree as $id => $_) {
            $depth[$id] = 0;
        }

        while (! empty($queue)) {
            $current = array_shift($queue);
            foreach ($adjacency[$current] ?? [] as $neighbor) {
                $newDepth = $depth[$current] + 1;
                if ($newDepth > $depth[$neighbor]) {
                    $depth[$neighbor] = $newDepth;
                }
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        return max($depth) + 1;
    }

    /**
     * Return an empty graph structure.
     *
     * @return array{nodes: list<array{id: string, label: string, type: string, latency_ms: null, metadata: array<string, mixed>}>, edges: list<array{from: string, to: string, latency_ms: null, weight: int}>, metadata: array<string, mixed>}
     */
    private function emptyGraph(): array
    {
        return [
            'nodes' => [],
            'edges' => [],
            'metadata' => [
                'event_count' => 0,
                'total_latency_ms' => 0.0,
                'depth' => 0,
                'has_cycles' => false,
                'critical_path' => [],
                'bottlenecks' => [],
            ],
        ];
    }

    /**
     * Return an empty aggregate graph structure.
     *
     * @return array{nodes: list<array{id: string, label: string, type: string, avg_latency_ms: float, max_latency_ms: float, min_latency_ms: float, event_count: int}>, edges: list<array{from: string, to: string, avg_latency_ms: float, weight: int}>, metadata: array<string, mixed>}
     */
    private function emptyAggregateGraph(): array
    {
        return [
            'nodes' => [],
            'edges' => [],
            'metadata' => [
                'total_events' => 0,
                'avg_total_latency_ms' => 0.0,
                'depth' => 0,
                'has_cycles' => false,
                'critical_path' => [],
                'bottlenecks' => [],
            ],
        ];
    }
}
