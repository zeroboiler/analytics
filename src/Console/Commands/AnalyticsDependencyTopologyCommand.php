<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Analytics Service Dependency Topology Command.
 *
 * Performs static analysis of the ServiceProvider's singleton registrations
 * to map constructor dependencies between analytics services. Detects:
 * - Service registration order and dependency graph
 * - Circular dependency chains (A → B → C → A)
 * - Orphan services (registered but never injected anywhere)
 * - Heavy dependency services (most depended-upon)
 * - Leaf services (no dependencies, terminal nodes)
 * - Registration count summary
 *
 * Useful for understanding the 420+ service architecture, debugging
 * resolution failures, and planning refactoring.
 *
 * @since 154.0.0
 */
final class AnalyticsDependencyTopologyCommand extends Command
{
    protected $signature = 'zb:analytics:topology
        {--json : Output as JSON}
        {--circular : Show only circular dependency warnings}
        {--orphans : Show only orphan (unreferenced) services}
        {--heavy : Show top 10 most-dependency-heavy services}
        {--service= : Analyze a specific service class}
        {--depth=3 : Max traversal depth for dependency chain analysis}';

    protected $description = 'Map service dependency topology — detect circular deps, orphans, and heavy services';

    /** @var array<string, list<string>> Service FQCN → list of constructor dependency FQCNs */
    private array $dependencyMap = [];

    /** @var array<string, list<string>> Reverse map: Service FQCN → list of services that depend on it */
    private array $reverseMap = [];

    /** @var list<string> All registered analytics service FQCNs */
    private array $services = [];

    /** @var list<array{chain: list<string>, type: string}> */
    private array $circularChains = [];

    /** @var list<string> Services registered but not injected by any other analytics service */
    private array $orphans = [];

    private int $depth;

    /**
     * Execute the topology analysis.
     */
    #[Override]
    public function handle(): int
    {
        $this->depth = (int) $this->option('depth');
        $outputJson = (bool) $this->option('json');
        $filterCircular = (bool) $this->option('circular');
        $filterOrphans = (bool) $this->option('orphans');
        $filterHeavy = (bool) $this->option('heavy');
        $specificService = (string) $this->option('service');

        if (! $outputJson) {
            $this->info('🗺️  ZeroBoiler Analytics — Service Dependency Topology');
            $this->newLine();
        }

        // Phase 1: Scan ServiceProvider for singleton registrations
        $this->scanServiceProvider();

        if ($this->services === []) {
            $this->warn('No analytics service registrations found in ServiceProvider.');

            return self::SUCCESS;
        }

        // Phase 2: Build dependency graph from constructor analysis
        $this->buildDependencyGraph();

        // Phase 3: Detect circular dependencies
        $this->detectCircularDependencies();

        // Phase 4: Identify orphans
        $this->identifyOrphans();

        // Phase 5: Output results
        if ($specificService !== '') {
            $this->outputServiceDetail($specificService, $outputJson);

            return self::SUCCESS;
        }

        if ($filterCircular) {
            return $this->outputCircularOnly($outputJson);
        }

        if ($filterOrphans) {
            return $this->outputOrphansOnly($outputJson);
        }

        if ($filterHeavy) {
            return $this->outputHeavyOnly($outputJson);
        }

        if ($outputJson) {
            $this->line(json_encode($this->buildFullReport(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->outputFullReport();

        return self::SUCCESS;
    }

    /**
     * Scan the ServiceProvider for singleton bindings.
     *
     * Uses reflection to find all $this->app->singleton() calls in register().
     */
    private function scanServiceProvider(): void
    {
        $providerClass = \ZeroBoiler\Analytics\AnalyticsServiceProvider::class;

        if (! class_exists($providerClass)) {
            return;
        }

        $reflection = new ReflectionClass($providerClass);
        $content = file_get_contents($reflection->getFileName());

        if ($content === false) {
            return;
        }

        // Extract FQCNs from singleton() and bind() calls
        // Matches patterns like: $this->app->singleton(SomeClass::class, ...) or $this->app->singleton('alias', SomeClass::class)
        $pattern = '/(?:singleton|bind)\s*\(\s*(?:[\'"](?:[\w\\\/.]+)[\'"]\s*,\s*)?(?:function\s*\([^)]*\)\s*:\s*([A-Z][\w\\]*)|([A-Z][\w\\\\]*))::class/';

        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        $seen = [];

        foreach ($matches as $match) {
            $className = $match[1] ?? $match[2] ?? '';

            if ($className === '' || $className === 'Application' || $className === 'ConfigRepository') {
                continue;
            }

            // Resolve short class names to FQCN using imports
            $fqcn = $this->resolveClassName($className, $content);

            if ($fqcn !== '' && ! isset($seen[$fqcn])) {
                $seen[$fqcn] = true;
                $this->services[] = $fqcn;
            }
        }

        // Also extract from explicit use statements and singleton calls with FQCN
        $patternFqcn = '/(?:singleton|bind)\s*\(\s*([A-Z][\w\\\\]*)::class/';

        preg_match_all($patternFqcn, $content, $matchesFqcn, PREG_SET_ORDER);

        foreach ($matchesFqcn as $match) {
            $fqcn = $this->resolveClassName($match[1], $content);

            if ($fqcn !== '' && ! isset($seen[$fqcn])) {
                $seen[$fqcn] = true;
                $this->services[] = $fqcn;
            }
        }

        sort($this->services);
    }

    /**
     * Resolve a short class name to its FQCN using use statements in the file.
     *
     * @return string Fully qualified class name, or empty string if not resolvable
     */
    private function resolveClassName(string $shortName, string $fileContent): string
    {
        // Try matching against use statements
        $usePattern = '/use\s+([A-Z][\w\\\\]*)\s*(?:as\s+(\w+))?\s*;/';

        preg_match_all($usePattern, $fileContent, $useMatches, PREG_SET_ORDER);

        $aliases = [];

        foreach ($useMatches as $useMatch) {
            $fqcn = $useMatch[1];
            $alias = $useMatch[2] ?? null;
            $parts = explode('\\', $fqcn);
            $baseName = $parts[array_key_last($parts)];

            if ($alias !== null) {
                $aliases[$alias] = $fqcn;
            }

            $aliases[$baseName] = $fqcn;
        }

        return $aliases[$shortName] ?? '';
    }

    /**
     * Analyze constructors of all registered services to build dependency graph.
     */
    private function buildDependencyGraph(): void
    {
        $analyticsNs = 'ZeroBoiler\\Analytics\\';

        foreach ($this->services as $service) {
            if (! class_exists($service)) {
                $this->dependencyMap[$service] = [];

                continue;
            }

            $deps = $this->getConstructorDependencies($service, $analyticsNs);
            $this->dependencyMap[$service] = $deps;

            foreach ($deps as $dep) {
                if (! isset($this->reverseMap[$dep])) {
                    $this->reverseMap[$dep] = [];
                }

                $this->reverseMap[$dep][] = $service;
            }
        }
    }

    /**
     * Get constructor dependency FQCNs for a service class.
     *
     * Only returns dependencies within the ZeroBoiler\Analytics namespace.
     *
     * @return list<string>
     */
    private function getConstructorDependencies(string $class, string $namespace): array
    {
        try {
            $reflection = new ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return [];
            }

            $deps = [];

            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();

                if ($type === null) {
                    continue;
                }

                $typeName = $this->getTypeName($type);

                if ($typeName === '' || $typeName === 'Application' || $typeName === 'Container') {
                    continue;
                }

                // Resolve to FQCN if it's a short name
                $resolved = $typeName;

                if (! str_contains($typeName, '\\')) {
                    $resolved = $this->resolveClassName($typeName, file_get_contents($reflection->getFileName()) ?: '');
                }

                // Only track analytics-namespace dependencies
                if (str_starts_with($resolved, $namespace) && $resolved !== $class) {
                    $deps[] = $resolved;
                }
            }

            return $deps;
        } catch (\ReflectionException) {
            return [];
        }
    }

    /**
     * Get the class name from a reflection type.
     */
    private function getTypeName(\ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            // Return the first non-built-in type
            foreach ($type->getTypes() as $unionType) {
                if ($unionType instanceof ReflectionNamedType && ! $unionType->isBuiltin()) {
                    return $unionType->getName();
                }
            }
        }

        return '';
    }

    /**
     * Detect circular dependency chains using DFS.
     */
    private function detectCircularDependencies(): void
    {
        $visited = [];
        $recursionStack = [];
        $path = [];

        foreach ($this->services as $service) {
            if (! isset($visited[$service])) {
                $this->dfsCircular($service, $visited, $recursionStack, $path);
            }
        }
    }

    /**
     * DFS traversal for circular dependency detection.
     *
     * @param array<string, bool> $visited
     * @param array<string, bool> $recursionStack
     * @param list<string> $path
     */
    private function dfsCircular(string $node, array &$visited, array &$recursionStack, array &$path): void
    {
        $visited[$node] = true;
        $recursionStack[$node] = true;
        $path[] = $node;

        $deps = $this->dependencyMap[$node] ?? [];

        foreach ($deps as $dep) {
            if (! isset($visited[$dep])) {
                $this->dfsCircular($dep, $visited, $recursionStack, $path);
            } elseif (isset($recursionStack[$dep])) {
                // Found a cycle — extract it
                $cycleStart = array_search($dep, $path, true);

                if ($cycleStart !== false) {
                    $chain = array_slice($path, $cycleStart);
                    $chain[] = $dep; // Close the cycle

                    // Normalize: start from the alphabetically smallest node
                    $minIndex = 0;

                    for ($i = 1; $i < count($chain) - 1; $i++) {
                        if ($chain[$i] < $chain[$minIndex]) {
                            $minIndex = $i;
                        }
                    }

                    $normalized = array_merge(
                        array_slice($chain, $minIndex),
                        array_slice($chain, 0, $minIndex),
                    );

                    $chainKey = implode(' → ', $normalized);

                    // Deduplicate
                    $alreadyFound = false;

                    foreach ($this->circularChains as $existing) {
                        if ($existing['chain'] === $normalized) {
                            $alreadyFound = true;
                            break;
                        }
                    }

                    if (! $alreadyFound) {
                        $this->circularChains[] = [
                            'chain' => $normalized,
                            'type' => 'circular',
                            'length' => count($chain) - 1,
                        ];
                    }
                }
            }
        }

        array_pop($path);
        $recursionStack[$node] = false;
    }

    /**
     * Identify services that are registered but never referenced as dependencies.
     */
    private function identifyOrphans(): void
    {
        // Services that no other analytics service depends on
        $referenced = array_keys($this->reverseMap);

        foreach ($this->services as $service) {
            if (! in_array($service, $referenced, true)) {
                $this->orphans[] = $service;
            }
        }
    }

    /**
     * Build the full topology report as an array.
     *
     * @return array{services_count: int, dependency_edges: int, circular_chains: list<array{chain: list<string>, type: string, length: int}>, orphan_count: int, orphans: list<string>, leaf_services: list<string>, heavy_services: list<array{service: string, dependency_count: int, depended_by_count: int}>, top_dependents: list<array{service: string, count: int}>}
     */
    private function buildFullReport(): array
    {
        // Leaf services: no analytics dependencies
        $leaves = [];

        foreach ($this->services as $service) {
            if (($this->dependencyMap[$service] ?? []) === []) {
                $leaves[] = $service;
            }
        }

        // Heavy services: most dependencies
        $heavy = [];

        foreach ($this->services as $service) {
            $depCount = count($this->dependencyMap[$service] ?? []);
            $dependedBy = count($this->reverseMap[$service] ?? []);

            if ($depCount > 0) {
                $heavy[] = [
                    'service' => $service,
                    'dependency_count' => $depCount,
                    'depended_by_count' => $dependedBy,
                ];
            }
        }

        usort($heavy, fn (array $a, array $b): int => $b['dependency_count'] <=> $a['dependency_count']);
        $heavy = array_slice($heavy, 0, 15);

        // Top depended-upon services (most depended by others)
        $dependents = [];

        foreach ($this->reverseMap as $service => $dependentsList) {
            $dependents[] = [
                'service' => $service,
                'count' => count($dependentsList),
            ];
        }

        usort($dependents, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $topDependents = array_slice($dependents, 0, 15);

        return [
            'services_count' => count($this->services),
            'dependency_edges' => array_sum(array_map(fn (array $deps): int => count($deps), $this->dependencyMap)),
            'circular_chains' => $this->circularChains,
            'orphan_count' => count($this->orphans),
            'orphans' => $this->orphans,
            'leaf_services' => $leaves,
            'leaf_count' => count($leaves),
            'heavy_services' => $heavy,
            'top_dependents' => $topDependents,
        ];
    }

    /**
     * Output the full topology report to the console.
     */
    private function outputFullReport(): void
    {
        $report = $this->buildFullReport();

        // Overview
        $this->section('Overview');
        $this->line("  Total Services:    <fg=cyan>{$report['services_count']}</>");
        $this->line("  Dependency Edges:  <fg=cyan>{$report['dependency_edges']}</>");
        $this->line("  Leaf Services:     <fg=green>{$report['leaf_count']}</>");
        $this->line("  Orphan Services:  <fg=yellow>" . count($this->orphans) . '</>');
        $this->line("  Circular Chains:   " . (count($this->circularChains) > 0 ? '<fg=red>' . count($this->circularChains) . '</>' : '<fg=green>0</>'));

        // Circular dependencies
        $this->newLine();
        $this->section('Circular Dependency Detection');

        if ($this->circularChains === []) {
            $this->line('  <fg=green>✓ No circular dependencies detected</>');
        } else {
            foreach ($this->circularChains as $chain) {
                $shortNames = array_map(fn (string $fqn): string => $this->shortName($fqn), $chain['chain']);
                $this->line('  <fg=red>⚠ ' . implode(' → ', $shortNames) . '</>  (length: ' . $chain['length'] . ')');
            }
        }

        // Top depended-upon services (bottleneck candidates)
        $this->newLine();
        $this->section('Top 10 Most-Depended-Upon Services (Bottleneck Candidates)');

        foreach (array_slice($report['top_dependents'], 0, 10) as $item) {
            $bar = str_repeat('█', min($item['count'], 50));
            $this->line("  <fg=cyan>{$item['count']:>3}</>  {$bar}  <fg=white>" . $this->shortName($item['service']) . '</>');
        }

        // Heavy services
        $this->newLine();
        $this->section('Top 10 Heaviest Services (Most Dependencies)');

        foreach (array_slice($report['heavy_services'], 0, 10) as $item) {
            $bar = str_repeat('▓', min($item['dependency_count'], 30));
            $this->line("  <fg=yellow>{$item['dependency_count']:>2}</> deps  {$bar}  <fg=white>" . $this->shortName($item['service']) . '</>');
        }

        // Orphans
        if ($this->orphans !== []) {
            $this->newLine();
            $this->section('Orphan Services (Not Referenced by Other Analytics Services)');

            foreach ($this->orphans as $orphan) {
                $deps = count($this->dependencyMap[$orphan] ?? []);
                $this->line("  <fg=yellow>○</> {$this->shortName($orphan)}  <fg=gray>({$deps} deps)</>");
            }
        }

        // Leaf services
        $this->newLine();
        $this->section('Leaf Services (No Analytics Dependencies)');
        $leafCount = count($report['leaf_services']);
        $this->line("  <fg=green>{$leafCount}</> leaf services detected (terminal nodes in dependency graph)");
    }

    /**
     * Output only circular dependency information.
     */
    private function outputCircularOnly(bool $json): int
    {
        if ($json) {
            $this->line(json_encode([
                'circular_chains' => $this->circularChains,
                'count' => count($this->circularChains),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($this->circularChains === []) {
            $this->info('✓ No circular dependencies detected.');

            return self::SUCCESS;
        }

        $this->error(count($this->circularChains) . ' circular dependency chain(s) detected:');

        foreach ($this->circularChains as $chain) {
            $shortNames = array_map(fn (string $fqn): string => $this->shortName($fqn), $chain['chain']);
            $this->line('  → ' . implode(' → ', $shortNames));
        }

        return self::FAILURE;
    }

    /**
     * Output only orphan services.
     */
    private function outputOrphansOnly(bool $json): int
    {
        if ($json) {
            $this->line(json_encode([
                'orphans' => $this->orphans,
                'count' => count($this->orphans),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($this->orphans === []) {
            $this->info('✓ No orphan services detected.');

            return self::SUCCESS;
        }

        $this->warn(count($this->orphans) . ' orphan service(s):');

        foreach ($this->orphans as $orphan) {
            $deps = count($this->dependencyMap[$orphan] ?? []);
            $this->line("  ○ {$this->shortName($orphan)} ({$deps} deps)");
        }

        return self::SUCCESS;
    }

    /**
     * Output only the heaviest services.
     */
    private function outputHeavyOnly(bool $json): int
    {
        $heavy = [];

        foreach ($this->services as $service) {
            $depCount = count($this->dependencyMap[$service] ?? []);
            $dependedBy = count($this->reverseMap[$service] ?? []);

            $heavy[] = [
                'service' => $service,
                'short' => $this->shortName($service),
                'dependency_count' => $depCount,
                'depended_by_count' => $dependedBy,
            ];
        }

        usort($heavy, fn (array $a, array $b): int => $b['dependency_count'] <=> $a['dependency_count']);
        $heavy = array_slice($heavy, 0, 10);

        if ($json) {
            $this->line(json_encode($heavy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Top 10 Heaviest Services:');

        foreach ($heavy as $item) {
            $bar = str_repeat('▓', min($item['dependency_count'], 30));
            $this->line("  {$item['dependency_count']:>2} deps  {$bar}  {$item['short']}  (depended by: {$item['depended_by_count']})");
        }

        return self::SUCCESS;
    }

    /**
     * Output detailed analysis for a specific service.
     */
    private function outputServiceDetail(string $serviceFqcn, bool $json): void
    {
        // Try to resolve short name
        $resolved = $serviceFqcn;

        if (! str_contains($serviceFqcn, '\\')) {
            foreach ($this->services as $s) {
                if ($this->shortName($s) === $serviceFqcn || str_ends_with($s, '\\' . $serviceFqcn)) {
                    $resolved = $s;
                    break;
                }
            }
        }

        if (! in_array($resolved, $this->services, true)) {
            $this->error("Service '{$serviceFqcn}' not found in analytics service registrations.");

            return;
        }

        $deps = $this->dependencyMap[$resolved] ?? [];
        $dependedBy = $this->reverseMap[$resolved] ?? [];

        if ($json) {
            $this->line(json_encode([
                'service' => $resolved,
                'short_name' => $this->shortName($resolved),
                'dependencies' => $deps,
                'dependency_count' => count($deps),
                'depended_by' => $dependedBy,
                'depended_by_count' => count($dependedBy),
                'is_leaf' => count($deps) === 0,
                'is_orphan' => count($dependedBy) === 0,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->section("Service: {$this->shortName($resolved)}");
        $this->line("  FQCN:            <fg=gray>{$resolved}</>");
        $this->line("  Dependencies:    <fg=cyan>" . count($deps) . '</>');
        $this->line("  Depended By:     <fg=cyan>" . count($dependedBy) . '</>');
        $this->line("  Is Leaf:         ' . (count($deps) === 0 ? '<fg=green>yes</>' : '<fg=yellow>no</>'));
        $this->line("  Is Orphan:       ' . (count($dependedBy) === 0 ? '<fg=yellow>yes</>' : '<fg=green>no</>'));

        if ($deps !== []) {
            $this->newLine();
            $this->line('  <fg=white>Depends On:</>');
            foreach ($deps as $dep) {
                $this->line("    <fg=gray>→</> {$this->shortName($dep)}");
            }
        }

        if ($dependedBy !== []) {
            $this->newLine();
            $this->line('  <fg=white>Depended By:</>');
            foreach ($dependedBy as $dependent) {
                $this->line("    <fg=gray>←</> {$this->shortName($dependent)}");
            }
        }
    }

    /**
     * Get a short class name from an FQCN.
     */
    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return $parts[array_key_last($parts)] ?? $fqcn;
    }

    /**
     * Print a section header.
     */
    private function section(string $title): void
    {
        $this->line("<fg=blue;options=bold>{$title}</>");
    }
}
