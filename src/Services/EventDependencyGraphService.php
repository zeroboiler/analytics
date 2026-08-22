<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Event dependency graph service for SaaS lifecycle validation.
 *
 * Models causal dependencies between analytics events — e.g., `sign_up` must
 * precede `start_trial`, `add_to_cart` must precede `purchase`. Validates
 * real-time event sequences against the graph, detects impossible workflows,
 * and provides funnel guard logic for SaaS products.
 *
 * Graph structure (cache-backed):
 *   zb_edg_nodes    → { event_name: { category, prerequisites: [...], successors: [...] } }
 *   zb_edg_edges    → { "from→to": { confidence, type, created_at } }
 *   zb_edg_violations → { client_id: [{ event, missing, timestamp }] }
 *
 * Edge types:
 *   - `required`:  Source MUST occur before target (hard dependency)
 *   - `expected`:  Source USUALLY occurs before target (soft/optional)
 *   - `exclusive`: Source and target CANNOT both occur (mutual exclusion)
 *
 * @see \ZeroBoiler\Analytics\Services\SaasFunnelService
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 *
 * @since 40.0.0
 */
final class EventDependencyGraphService
{
    /** @var CacheRepository */
    private CacheRepository $cache;

    private string $cachePrefix;

    private int $graphTtl;

    private int $violationTtl;

    private int $maxViolationsPerClient;

    private bool $enabled;

    /** @var array<string, array{prerequisites: list<string>, successors: list<string>}> */
    private array $builtinNodes = [];

    /** @var array<string, array{from: string, to: string, type: string, confidence: float}> */
    private array $builtinEdges = [];

    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->cache = $cache;
        $this->enabled = (bool) $config->get('zeroboiler.analytics.dependency_graph.enabled', true);
        $this->cachePrefix = (string) $config->get('zeroboiler.analytics.dependency_graph.cache_prefix', 'zb_edg_');
        $this->graphTtl = (int) $config->get('zeroboiler.analytics.dependency_graph.cache_ttl', 86400);
        $this->violationTtl = (int) $config->get('zeroboiler.analytics.dependency_graph.violation_ttl', 3600);
        $this->maxViolationsPerClient = (int) $config->get('zeroboiler.analytics.dependency_graph.max_violations', 100);

        $this->loadBuiltinGraph();
    }

    /**
     * Check if the dependency graph service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Validate an event against the dependency graph.
     *
     * Checks all required prerequisites for the given event have been
     * previously recorded for this client ID. Returns a validation
     * result with passed/failed status, missing prerequisites, and
     * violation details.
     *
     * @param  AnalyticsEvent  $event  The event to validate
     * @param  string|null  $clientId  The client ID (optional, uses event's clientId)
     * @return array{valid: bool, event: string, missing_prerequisites: list<string>, missing_expected: list<string>, exclusive_violations: list<string>, violated: bool}
     */
    public function validate(AnalyticsEvent $event, ?string $clientId = null): array
    {
        if (! $this->enabled) {
            return $this->validResult($event->name);
        }

        $clientId ??= $event->clientId;
        $eventName = $event->name;
        $prerequisites = $this->getPrerequisites($eventName);
        $expected = $this->getExpectedPrerequisites($eventName);
        $exclusive = $this->getExclusiveEvents($eventName);

        $missingRequired = [];
        $missingExpected = [];
        $exclusiveViolations = [];

        // Check required prerequisites
        foreach ($prerequisites as $prereq) {
            if (! $this->hasClientEvent($clientId, $prereq)) {
                $missingRequired[] = $prereq;
            }
        }

        // Check expected (soft) prerequisites
        foreach ($expected as $exp) {
            if (! $this->hasClientEvent($clientId, $exp)) {
                $missingExpected[] = $exp;
            }
        }

        // Check exclusive events (should NOT have occurred)
        foreach ($exclusive as $excl) {
            if ($this->hasClientEvent($clientId, $excl)) {
                $exclusiveViolations[] = $excl;
            }
        }

        $violated = count($missingRequired) > 0 || count($exclusiveViolations) > 0;

        // Record event occurrence for future validation
        $this->recordClientEvent($clientId, $eventName);

        // Record violation if any
        if ($violated && $clientId !== null) {
            $this->recordViolation($clientId, $eventName, $missingRequired, $exclusiveViolations);
        }

        return [
            'valid' => ! $violated,
            'event' => $eventName,
            'missing_prerequisites' => $missingRequired,
            'missing_expected' => $missingExpected,
            'exclusive_violations' => $exclusiveViolations,
            'violated' => $violated,
        ];
    }

    /**
     * Validate a batch of events in sequence order.
     *
     * Processes events in the order they appear, building up the client
     * event history as it goes. Returns individual results plus an
     * aggregate summary.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return array{results: list<array{valid: bool, event: string, missing_prerequisites: list<string>, missing_expected: list<string>, exclusive_violations: list<string>, violated: bool}>, total: int, passed: int, failed: int, pass_rate: float}
     */
    public function validateBatch(array $events): array
    {
        $results = [];
        $passed = 0;
        $failed = 0;

        foreach ($events as $event) {
            $result = $this->validate($event);
            $results[] = $result;

            if ($result['valid']) {
                $passed++;
            } else {
                $failed++;
            }
        }

        $total = count($events);

        return [
            'results' => $results,
            'total' => $total,
            'passed' => $passed,
            'failed' => $failed,
            'pass_rate' => $total > 0 ? round($passed / $total, 4) : 1.0,
        ];
    }

    /**
     * Get all required prerequisites for an event.
     *
     * @return list<string>
     */
    public function getPrerequisites(string $eventName): array
    {
        $node = $this->getNode($eventName);

        return $node['prerequisites'] ?? [];
    }

    /**
     * Get all expected (soft) prerequisites for an event.
     *
     * @return list<string>
     */
    public function getExpectedPrerequisites(string $eventName): array
    {
        $edges = $this->allEdges();

        $expected = [];
        foreach ($edges as $edge) {
            if ($edge['to'] === $eventName && $edge['type'] === 'expected') {
                $expected[] = $edge['from'];
            }
        }

        return $expected;
    }

    /**
     * Get all events that are exclusive to the given event.
     *
     * @return list<string>
     */
    public function getExclusiveEvents(string $eventName): array
    {
        $edges = $this->allEdges();

        $exclusive = [];
        foreach ($edges as $edge) {
            if (($edge['from'] === $eventName || $edge['to'] === $eventName) && $edge['type'] === 'exclusive') {
                $exclusive[] = $edge['from'] === $eventName ? $edge['to'] : $edge['from'];
            }
        }

        return array_values(array_unique($exclusive));
    }

    /**
     * Get all successors (events that depend on this event).
     *
     * @return list<string>
     */
    public function getSuccessors(string $eventName): array
    {
        $node = $this->getNode($eventName);

        return $node['successors'] ?? [];
    }

    /**
     * Get the full dependency graph as an adjacency list.
     *
     * @return array<string, array{prerequisites: list<string>, successors: list<string>, category: string|null}>
     */
    public function getGraph(): array
    {
        $nodes = $this->allNodes();
        $customNodes = $this->getCustomNodes();

        $graph = [];

        foreach (array_merge($nodes, $customNodes) as $name => $data) {
            $graph[$name] = [
                'prerequisites' => $data['prerequisites'] ?? [],
                'successors' => $data['successors'] ?? [],
                'category' => $data['category'] ?? null,
            ];
        }

        return $graph;
    }

    /**
     * Get a topologically sorted list of all events in the graph.
     *
     * Useful for rendering dependency chains and identifying cycles.
     *
     * @return list<string>
     */
    public function topologicalSort(): array
    {
        $graph = $this->getGraph();
        $visited = [];
        $result = [];

        foreach (array_keys($graph) as $node) {
            if (! isset($visited[$node])) {
                $this->topologicalVisit($node, $graph, $visited, $result);
            }
        }

        return array_reverse($result);
    }

    /**
     * Detect cycles in the dependency graph.
     *
     * Returns a list of cycle paths if any are found.
     *
     * @return list<list<string>>
     */
    public function detectCycles(): array
    {
        $graph = $this->getGraph();
        $cycles = [];
        $visited = [];
        $recursionStack = [];
        $path = [];

        foreach (array_keys($graph) as $node) {
            if (! isset($visited[$node])) {
                $this->detectCyclesDFS($node, $graph, $visited, $recursionStack, $path, $cycles);
            }
        }

        return $cycles;
    }

    /**
     * Check if a specific path through events is valid.
     *
     * Validates that each event in the sequence is a valid successor
     * of the previous event.
     *
     * @param  list<string>  $eventSequence
     * @return array{valid: bool, violations: list<array{from: string, to: string, reason: string}>}
     */
    public function validatePath(array $eventSequence): array
    {
        $violations = [];

        for ($i = 1; $i < count($eventSequence); $i++) {
            $from = $eventSequence[$i - 1];
            $to = $eventSequence[$i];

            $successors = $this->getSuccessors($from);
            $prerequisites = $this->getPrerequisites($to);

            // Check if the path follows a known dependency
            $isSuccessor = in_array($to, $successors, true);
            $isPrerequisite = in_array($from, $prerequisites, true);

            if (! $isSuccessor && ! $isPrerequisite) {
                // Check if they're connected at all
                $allEvents = array_keys($this->getGraph());
                if (in_array($from, $allEvents, true) && in_array($to, $allEvents, true)) {
                    $violations[] = [
                        'from' => $from,
                        'to' => $to,
                        'reason' => 'No known dependency edge',
                    ];
                }
            }
        }

        return [
            'valid' => count($violations) === 0,
            'violations' => $violations,
        ];
    }

    /**
     * Get funnel completion probability for a given funnel path.
     *
     * Calculates the probability of completing a funnel based on the
     * number of required edges in the path versus total possible paths.
     *
     * @param  list<string>  $funnelSteps
     * @return array{probability: float, steps_completed: int, total_steps: int, missing_edges: list<string>}
     */
    public function funnelCompletionProbability(array $funnelSteps): array
    {
        $completed = 0;
        $missingEdges = [];

        for ($i = 1; $i < count($funnelSteps); $i++) {
            $from = $funnelSteps[$i - 1];
            $to = $funnelSteps[$i];

            $successors = $this->getSuccessors($from);

            if (in_array($to, $successors, true)) {
                $completed++;
            } else {
                $missingEdges[] = "{$from} → {$to}";
            }
        }

        $totalSteps = max(1, count($funnelSteps) - 1);

        return [
            'probability' => $totalSteps > 0 ? round($completed / $totalSteps, 4) : 1.0,
            'steps_completed' => $completed,
            'total_steps' => $totalSteps,
            'missing_edges' => $missingEdges,
        ];
    }

    /**
     * Get violations for a specific client ID.
     *
     * @param  string|null  $clientId
     * @return list<array{event: string, missing: list<string>, exclusive: list<string>, timestamp: string}>
     */
    public function getViolations(?string $clientId): array
    {
        if ($clientId === null) {
            return [];
        }

        $key = $this->cachePrefix . 'violations_' . $clientId;
        /** @var list<array{event: string, missing: list<string>, exclusive: list<string>, timestamp: string}>|mixed $violations */
        $violations = $this->cache->get($key);

        return is_array($violations) ? $violations : [];
    }

    /**
     * Clear all violation records for a client.
     */
    public function clearViolations(?string $clientId): bool
    {
        if ($clientId === null) {
            return false;
        }

        return $this->cache->forget($this->cachePrefix . 'violations_' . $clientId);
    }

    /**
     * Get statistics about the dependency graph.
     *
     * @return array{nodes: int, edges: int, required_edges: int, expected_edges: int, exclusive_edges: int, cycles: int, has_custom: bool}
     */
    public function statistics(): array
    {
        $graph = $this->getGraph();
        $edges = $this->allEdges();
        $cycles = $this->detectCycles();
        $customNodes = $this->getCustomNodes();

        $required = 0;
        $expected = 0;
        $exclusive = 0;

        foreach ($edges as $edge) {
            match ($edge['type']) {
                'required' => $required++,
                'expected' => $expected++,
                'exclusive' => $exclusive++,
                default => null,
            };
        }

        return [
            'nodes' => count($graph),
            'edges' => count($edges),
            'required_edges' => $required,
            'expected_edges' => $expected,
            'exclusive_edges' => $exclusive,
            'cycles' => count($cycles),
            'has_custom' => count($customNodes) > 0,
        ];
    }

    /**
     * Get a summary of the dependency graph with key metrics.
     *
     * @return array{enabled: bool, statistics: array{nodes: int, edges: int, required_edges: int, expected_edges: int, exclusive_edges: int, cycles: int, has_custom: bool}, critical_paths: list<string>, top_violated_events: list<array{event: string, violation_count: int}>}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'statistics' => $this->statistics(),
            'critical_paths' => $this->getCriticalPaths(),
            'top_violated_events' => [],
        ];
    }

    /**
     * Get the critical paths (longest dependency chains) in the graph.
     *
     * @return list<string>
     */
    public function getCriticalPaths(): array
    {
        $sorted = $this->topologicalSort();
        $graph = $this->getGraph();

        // Find events with no prerequisites (root nodes)
        $roots = array_filter($graph, fn (array $node): bool => count($node['prerequisites']) === 0);
        $rootNames = array_keys($roots);

        // For each root, find the longest path
        $paths = [];
        foreach ($rootNames as $root) {
            $path = $this->findLongestPath($root, $graph);
            $paths[] = implode(' → ', $path);
        }

        return $paths;
    }

    /**
     * Load built-in dependency graph for SaaS lifecycle events.
     */
    private function loadBuiltinGraph(): void
    {
        // Built-in SaaS lifecycle dependencies
        $this->builtinNodes = [
            'sign_up' => ['prerequisites' => [], 'successors' => ['login', 'start_trial', 'subscribe']],
            'login' => ['prerequisites' => ['sign_up'], 'successors' => ['start_trial', 'subscribe', 'plan_upgrade']],
            'start_trial' => ['prerequisites' => ['sign_up'], 'successors' => ['subscribe', 'trial_end', 'trial_converted']],
            'subscribe' => ['prerequisites' => ['sign_up'], 'successors' => ['plan_upgrade', 'plan_downgrade', 'cancellation', 'subscription_renewal']],
            'plan_upgrade' => ['prerequisites' => ['subscribe'], 'successors' => ['cancellation', 'plan_downgrade']],
            'plan_downgrade' => ['prerequisites' => ['subscribe'], 'successors' => ['cancellation']],
            'cancellation' => ['prerequisites' => ['subscribe'], 'successors' => []],
            'trial_end' => ['prerequisites' => ['start_trial'], 'successors' => ['subscribe', 'cancellation']],
            'trial_converted' => ['prerequisites' => ['start_trial'], 'successors' => ['subscribe']],
            'subscription_renewal' => ['prerequisites' => ['subscribe'], 'successors' => ['cancellation', 'plan_upgrade']],
            // E-commerce dependencies
            'view_item' => ['prerequisites' => [], 'successors' => ['add_to_cart', 'select_item']],
            'add_to_cart' => ['prerequisites' => [], 'successors' => ['remove_from_cart', 'view_cart', 'begin_checkout']],
            'remove_from_cart' => ['prerequisites' => ['add_to_cart'], 'successors' => ['add_to_cart']],
            'view_cart' => ['prerequisites' => ['add_to_cart'], 'successors' => ['begin_checkout']],
            'begin_checkout' => ['prerequisites' => ['add_to_cart'], 'successors' => ['add_payment_info', 'purchase']],
            'add_payment_info' => ['prerequisites' => ['begin_checkout'], 'successors' => ['purchase']],
            'purchase' => ['prerequisites' => ['begin_checkout'], 'successors' => ['refund']],
            'refund' => ['prerequisites' => ['purchase'], 'successors' => ['add_to_cart']],
        ];

        $this->builtinEdges = [];
        foreach ($this->builtinNodes as $from => $data) {
            foreach ($data['successors'] as $to) {
                $this->builtinEdges["{$from}→{$to}"] = [
                    'from' => $from,
                    'to' => $to,
                    'type' => 'required',
                    'confidence' => 1.0,
                ];
            }
        }
    }

    /**
     * Get a node from the graph (builtin + custom).
     *
     * @return array{prerequisites: list<string>, successors: list<string>, category?: string|null}
     */
    private function getNode(string $eventName): array
    {
        if (isset($this->builtinNodes[$eventName])) {
            return $this->builtinNodes[$eventName];
        }

        $customNodes = $this->getCustomNodes();

        return $customNodes[$eventName] ?? ['prerequisites' => [], 'successors' => []];
    }

    /**
     * Get all builtin nodes.
     *
     * @return array<string, array{prerequisites: list<string>, successors: list<string>}>
     */
    private function allNodes(): array
    {
        return $this->builtinNodes;
    }

    /**
     * Get custom nodes from config.
     *
     * @return array<string, array{prerequisites: list<string>, successors: list<string>, category?: string}>
     */
    private function getCustomNodes(): array
    {
        /** @var array<string, array{prerequisites?: list<string>, successors?: list<string>, category?: string}> $custom */
        $custom = $this->cache->get($this->cachePrefix . 'custom_nodes', []);

        // Add category information from EventCatalog
        $result = [];
        foreach ($custom as $name => $data) {
            $result[$name] = [
                'prerequisites' => $data['prerequisites'] ?? [],
                'successors' => $data['successors'] ?? [],
                'category' => $data['category'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Get all edges (builtin + custom).
     *
     * @return list<array{from: string, to: string, type: string, confidence: float}>
     */
    private function allEdges(): array
    {
        return array_values($this->builtinEdges);
    }

    /**
     * Check if a client has recorded a specific event.
     */
    private function hasClientEvent(?string $clientId, string $eventName): bool
    {
        if ($clientId === null) {
            return false;
        }

        $key = $this->cachePrefix . 'client_events_' . $clientId;
        /** @var list<string>|mixed $events */
        $events = $this->cache->get($key);

        return is_array($events) && in_array($eventName, $events, true);
    }

    /**
     * Record that a client has fired a specific event.
     */
    private function recordClientEvent(?string $clientId, string $eventName): void
    {
        if ($clientId === null) {
            return;
        }

        $key = $this->cachePrefix . 'client_events_' . $clientId;
        /** @var list<string>|mixed $events */
        $events = $this->cache->get($key);
        $events = is_array($events) ? $events : [];

        if (! in_array($eventName, $events, true)) {
            $events[] = $eventName;
            $this->cache->put($key, $events, $this->graphTtl);
        }
    }

    /**
     * Record a validation violation.
     *
     * @param  list<string>  $missing
     * @param  list<string>  $exclusive
     */
    private function recordViolation(string $clientId, string $eventName, array $missing, array $exclusive): void
    {
        $key = $this->cachePrefix . 'violations_' . $clientId;
        /** @var list<array{event: string, missing: list<string>, exclusive: list<string>, timestamp: string}>|mixed $violations */
        $violations = $this->cache->get($key);
        $violations = is_array($violations) ? $violations : [];

        if (count($violations) >= $this->maxViolationsPerClient) {
            array_shift($violations);
        }

        $violations[] = [
            'event' => $eventName,
            'missing' => $missing,
            'exclusive' => $exclusive,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $this->cache->put($key, $violations, $this->violationTtl);
    }

    /**
     * Return a valid (pass-through) result.
     *
     * @return array{valid: bool, event: string, missing_prerequisites: list<string>, missing_expected: list<string>, exclusive_violations: list<string>, violated: bool}
     */
    private function validResult(string $eventName): array
    {
        return [
            'valid' => true,
            'event' => $eventName,
            'missing_prerequisites' => [],
            'missing_expected' => [],
            'exclusive_violations' => [],
            'violated' => false,
        ];
    }

    /**
     * Depth-first topological sort visitor.
     *
     * @param  array<string, array{prerequisites: list<string>, successors: list<string>}>  $graph
     * @param  array<string, bool>  $visited
     * @param  list<string>  $result
     */
    private function topologicalVisit(
        string $node,
        array $graph,
        array &$visited,
        array &$result,
    ): void {
        $visited[$node] = true;

        $successors = $graph[$node]['successors'] ?? [];
        foreach ($successors as $successor) {
            if (! isset($visited[$successor])) {
                $this->topologicalVisit($successor, $graph, $visited, $result);
            }
        }

        $result[] = $node;
    }

    /**
     * DFS-based cycle detection.
     *
     * @param  array<string, array{prerequisites: list<string>, successors: list<string>}>  $graph
     * @param  array<string, bool>  $visited
     * @param  array<string, bool>  $recursionStack
     * @param  list<string>  $path
     * @param  list<list<string>>  $cycles
     */
    private function detectCyclesDFS(
        string $node,
        array $graph,
        array &$visited,
        array &$recursionStack,
        array &$path,
        array &$cycles,
    ): void {
        $visited[$node] = true;
        $recursionStack[$node] = true;
        $path[] = $node;

        $successors = $graph[$node]['successors'] ?? [];
        foreach ($successors as $successor) {
            if (! isset($visited[$successor])) {
                $this->detectCyclesDFS($successor, $graph, $visited, $recursionStack, $path, $cycles);
            } elseif (isset($recursionStack[$successor])) {
                // Found a cycle
                $cycleStart = array_search($successor, $path, true);
                $cyclePath = array_slice($path, $cycleStart);
                $cyclePath[] = $successor;
                $cycles[] = $cyclePath;
            }
        }

        array_pop($path);
        unset($recursionStack[$node]);
    }

    /**
     * Find the longest path from a given node using DFS.
     *
     * @param  string  $node
     * @param  array<string, array{prerequisites: list<string>, successors: list<string>}>  $graph
     * @return list<string>
     */
    private function findLongestPath(string $node, array $graph): array
    {
        $successors = $graph[$node]['successors'] ?? [];

        if (count($successors) === 0) {
            return [$node];
        }

        $longest = [];
        foreach ($successors as $successor) {
            if (isset($graph[$successor])) {
                $subPath = $this->findLongestPath($successor, $graph);
                if (count($subPath) > count($longest)) {
                    $longest = $subPath;
                }
            }
        }

        return [$node, ...$longest];
    }
}
