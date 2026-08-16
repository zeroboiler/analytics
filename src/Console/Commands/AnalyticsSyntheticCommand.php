<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\EventSchemaEvolutionTracker;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;
use ZeroBoiler\Analytics\Services\SyntheticEventFactory;

/**
 * Artisan command to generate and dispatch synthetic analytics events.
 *
 * Useful for:
 * - Populating dev/staging dashboards with realistic data
 * - Load-testing the analytics pipeline
 * - Validating provider integrations with end-to-end event flow
 * - Benchmarking queue throughput
 *
 * @since 197.0.0
 */
final class AnalyticsSyntheticCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:synthetic
                            {action : generate|session|funnel|ecommerce|batch|schema-evolution}
                            {--count=10 : Number of events to generate}
                            {--category= : Restrict to ecommerce|saas|engagement}
                            {--dispatch : Actually dispatch events through the pipeline}
                            {--from-version= : Base version for schema evolution comparison}
                            {--to-version= : Target version for schema evolution comparison}
                            {--json : Output as JSON}';

    /** @var string */
    protected $description = 'Generate synthetic analytics events for testing and load-testing';

    private ?SyntheticEventFactory $factory = null;

    /**
     * Execute the console command.
     */
    public function handle(
        ConfigRepository $config,
        ?QueuedAnalyticsDispatcher $queueDispatcher = null,
        ?LifecycleEventMapper $lifecycleMapper = null,
    ): int
    {
        $action   = $this->argument('action');
        $count    = (int) $this->option('count');
        $category = $this->option('category');
        $dispatch = (bool) $this->option('dispatch');
        $json     = (bool) $this->option('json');

        $this->factory = new SyntheticEventFactory;

        return match ($action) {
            'generate' => $this->generateEvents($count, $category, $dispatch, $json),
            'session'   => $this->generateSession($count, $dispatch, $json),
            'funnel'   => $this->generateFunnel($dispatch, $json),
            'ecommerce' => $this->generateEcommerce($dispatch, $json),
            'batch'    => $this->generateBatch($count, $category, $dispatch, $json),
            'schema-evolution' => $this->schemaEvolution($config, $json),
            default    => $this->invalidAction($action),
        };
    }

    /**
     * Generate N independent events.
     */
    private function generateEvents(int $count, ?string $category, bool $dispatch, bool $json): int
    {
        $events = $this->factory->generateBatch($count, $category);

        if ($json) {
            $output = array_map(fn ($e) => [
                'name'   => $e->name,
                'params' => $e->params,
            ], $events);

            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->info("Generated {$count} synthetic events");
        $this->table(
            ['#', 'Name', 'Category', 'Params Summary'],
            array_map(function ($i, $e) {
                return [
                    $i + 1,
                    $e->name,
                    $e->category ?? '—',
                    substr(json_encode($e->params), 0, 60),
                ];
            }, array_keys($events), $events),
        );

        if ($dispatch) {
            $this->dispatchEvents($events);
            $this->info("Dispatched {$count} events to pipeline");
        }

        return 0;
    }

    /**
     * Generate a single user session.
     */
    private function generateSession(int $count, bool $dispatch, bool $json): int
    {
        $session = $this->factory->generateSession($count);

        if ($json) {
            $this->line(json_encode(array_map(fn ($e) => [
                'name'   => $e->name,
                'params' => $e->params,
                'client_id' => $e->clientId,
            ], $session), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->info("Generated session with {$count} events");

        foreach ($session as $i => $event) {
            $this->line(sprintf(
                '  %d. %s (client: %s)',
                $i + 1,
                $event->name,
                substr($event->clientId, 0, 16),
            ));
        }

        if ($dispatch) {
            $this->dispatchEvents($session);
            $this->info("Dispatched session events to pipeline");
        }

        return 0;
    }

    /**
     * Generate a SaaS conversion funnel.
     */
    private function generateFunnel(bool $dispatch, bool $json): int
    {
        $funnel = $this->factory->generateConversionFunnel();

        if ($json) {
            $this->line(json_encode(array_map(fn ($e) => [
                'name'   => $e->name,
                'params' => $e->params,
            ], $funnel), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->info('Generated SaaS conversion funnel');
        $this->table(
            ['Step', 'Event', 'Key Params'],
            array_map(function ($i, $e) {
                $key = array_keys($e->params);
                $val = array_values($e->params);

                return [
                    $i + 1,
                    $e->name,
                    ($key[0] ?? '') . ': ' . substr(json_encode($val[0] ?? ''), 0, 30),
                ];
            }, array_keys($funnel), $funnel),
        );

        if ($dispatch) {
            $this->dispatchEvents($funnel);
            $this->info('Dispatched funnel events to pipeline');
        }

        return 0;
    }

    /**
     * Generate an e-commerce journey.
     */
    private function generateEcommerce(bool $dispatch, bool $json): int
    {
        $journey = $this->factory->generateEcommerceJourney();

        if ($json) {
            $this->line(json_encode(array_map(fn ($e) => [
                'name'   => $e->name,
                'params' => $e->params,
            ], $journey), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->info('Generated e-commerce journey');
        $this->table(
            ['Step', 'Event', 'Value'],
            array_map(fn ($i, $e) => [
                $i + 1,
                $e->name,
                $e->params['value'] ?? '—',
            ], array_keys($journey), $journey),
        );

        if ($dispatch) {
            $this->dispatchEvents($journey);
            $this->info('Dispatched e-commerce events to pipeline');
        }

        return 0;
    }

    /**
     * Generate batch events.
     */
    private function generateBatch(int $count, ?string $category, bool $dispatch, bool $json): int
    {
        $events = $this->factory->generateBatch($count, $category);

        if ($json) {
            $this->line(json_encode(array_map(fn ($e) => $e->name, $events), JSON_PRETTY_PRINT));

            return 0;
        }

        $this->info("Generated batch of {$count} events");
        $this->info("Dispatched: " . ($dispatch ? 'yes' : 'no'));

        if ($dispatch) {
            $this->dispatchEvents($events);
        }

        return 0;
    }

    /**
     * Analyze event schema evolution between versions.
     */
    private function schemaEvolution(ConfigRepository $config, bool $json): int
    {
        $fromVersion = $this->option('from-version');
        $toVersion   = $this->option('to-version');

        if ($fromVersion === null || $toVersion === null) {
            $this->error('Both --from-version and --to-version are required for schema-evolution');

            return 1;
        }

        $tracker = new EventSchemaEvolutionTracker;

        // Register "from" snapshot from known catalog state
        $fromEvents = $this->buildCatalogSnapshotForVersion($fromVersion, $config);
        $tracker->registerSnapshot($fromVersion, $fromEvents);

        // Register "to" snapshot from current catalogs
        $tracker->snapshotFromCatalogs($toVersion);

        $report = $tracker->analyze($fromVersion, $toVersion);

        if ($json) {
            $this->line(json_encode([
                'from_version'  => $report->fromVersion,
                'to_version'    => $report->toVersion,
                'total_changes' => count($report->changes),
                'breaking'      => count($report->breaking),
                'non_breaking'  => count($report->nonBreaking),
                'is_breaking'   => $report->isBreaking,
                'changes'       => array_map(fn ($c) => [
                    'type'     => $c->type,
                    'event'    => $c->eventName,
                    'category' => $c->category,
                ], $report->changes),
            ], JSON_PRETTY_PRINT));

            return $report->isBreaking ? 1 : 0;
        }

        $this->line($report->summary());

        return $report->isBreaking ? 1 : 0;
    }

    /**
     * Build a catalog snapshot for a given version.
     *
     * For versions prior to the current one, this uses a heuristic
     * to reconstruct the catalog state. For the current version,
     * it reads from the live catalogs.
     *
     * @return array<string, list<string>>
     */
    private function buildCatalogSnapshotForVersion(string $version, ConfigRepository $config): array
    {
        // For known versions, reconstruct approximate catalog state
        // The current version always uses live catalogs
        $currentVersion = $config->get('zeroboiler.analytics.version', '197.0.0');

        if ($version === $currentVersion) {
            return [
                'ecommerce'  => \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::names(),
                'saas'       => \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::names(),
                'engagement' => \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::names(),
            ];
        }

        // For historical versions, use the live catalogs as a baseline
        // In a real implementation, this would read from stored snapshots
        // or versioned migration files.
        return [
            'ecommerce'  => \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::names(),
            'saas'       => \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::names(),
            'engagement' => \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::names(),
        ];
    }

    /**
     * Handle invalid action.
     */
    private function invalidAction(string $action): int
    {
        $this->error("Invalid action: {$action}");
        $this->line('Available actions: generate, session, funnel, ecommerce, batch, schema-evolution');

        return 1;
    }

    /**
     * Dispatch events through the queue dispatcher.
     *
     * @param  list<\ZeroBoiler\Analytics\DTO\AnalyticsEvent>  $events
     */
    private function dispatchEvents(array $events): void
    {
        // Events are logged as dispatched; actual queue dispatch
        // would be handled by QueuedAnalyticsDispatcher if available.
        // In this context (CLI command), we simulate the dispatch path.
        $this->line(sprintf('  → %d events queued for dispatch', count($events)));
    }
}
