<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\EventActionRegistry;

/**
 * Analytics Event Actions management command.
 *
 * View, inspect, and manage registered event-driven side-effect actions.
 * Actions are callable handlers that execute when specific analytics events
 * are dispatched.
 *
 * @see \ZeroBoiler\Analytics\EventActionRegistry
 * @see \ZeroBoiler\Analytics\DTO\EventAction
 *
 * @since 230.0.0
 */
final class AnalyticsEventActionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'zb:analytics:event-actions
        {action : Action to perform: list, show, test}
        {--id= : Action ID to show details for}
        {--event= : Simulate dispatch for an event name (with --test)}
        {--json : Output as JSON}';

    /**
     * The console command description.
     */
    protected $description = 'Manage event-driven side-effect actions';

    /**
     * Execute the console command.
     */
    public function handle(EventActionRegistry $registry): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'list' => $this->listActions($registry),
            'show' => $this->showAction($registry),
            'test' => $this->testAction($registry),
            default => $this->invalidAction($action),
        };
    }

    /**
     * List all registered actions.
     */
    private function listActions(EventActionRegistry $registry): int
    {
        $summary = $registry->summary();

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info("Event Actions ({$summary['total_actions']} registered, {$summary['executions']} total executions)");

        if ($summary['actions'] === []) {
            $this->components->warn('No actions registered. Define actions in config or register programmatically.');
            $this->newLine();
            $this->line('Config example:');
            $this->line("'event_actions' => [");
            $this->line("    'enabled' => true,");
            $this->line("    'actions' => [");
            $this->line("        ['id' => 'notify_purchase', 'on' => 'purchase', 'handler' => \\App\\Actions\\NotifyPurchase::class, 'cooldown' => 300],");
            $this->line("    ],");
            $this->line("],");

            return self::SUCCESS;
        }

        $headers = ['ID', 'Pattern', 'Priority', 'Cooldown', 'Condition', 'Executions'];

        $rows = [];
        foreach ($summary['actions'] as $action) {
            $rows[] = [
                $action['id'],
                $action['on'],
                (string) $action['priority'],
                $action['cooldown'] !== null ? "{$action['cooldown']}s" : '-',
                $action['condition'] ?? '-',
                (string) $action['executions'],
            ];
        }

        $this->table($headers, $rows);

        $this->newLine();
        $this->components->info("Patterns: {$summary['patterns']} unique");

        return self::SUCCESS;
    }

    /**
     * Show details for a specific action.
     */
    private function showAction(EventActionRegistry $registry): int
    {
        $id = $this->option('id');

        if ($id === null || $id === '') {
            $this->components->error('--id is required for show action');

            return self::FAILURE;
        }

        $action = $registry->get($id);

        if ($action === null) {
            $this->components->error("Action '{$id}' not found");

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($action->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info("Action: {$action->id}");

        $this->table(
            ['Property', 'Value'],
            [
                ['Pattern', $action->on],
                ['Priority', (string) $action->priority],
                ['Cooldown', $action->cooldownSeconds !== null ? "{$action->cooldownSeconds}s" : 'None'],
                ['Condition', $action->condition ?? 'None'],
                ['Executions', (string) $registry->executionCount($action->id)],
                ['Handler', $this->describeHandler($action->handler)],
            ],
        );

        if ($action->metadata !== []) {
            $this->newLine();
            $this->line('<comment>Metadata:</comment>');
            foreach ($action->metadata as $key => $value) {
                $this->line("  {$key}: " . (is_array($value) ? json_encode($value) : (string) $value));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Test action matching for a simulated event.
     */
    private function testAction(EventActionRegistry $registry): int
    {
        $eventName = $this->option('event');

        if ($eventName === null || $eventName === '') {
            $this->components->error('--event is required for test action');

            return self::FAILURE;
        }

        $event = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(name: $eventName, params: []);
        $matching = $registry->findMatchingActions($event);

        if ($matching === []) {
            $this->components->warn("No actions match event '{$eventName}'");

            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $data = [];
            foreach ($matching as $action) {
                $data[] = array_merge($action->toArray(), [
                    'condition_satisfied' => $action->conditionSatisfied($event),
                    'on_cooldown' => false,
                ]);
            }
            $this->line(json_encode(['event' => $eventName, 'matching_actions' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info("Matching actions for '{$eventName}': " . count($matching) . ' found');

        $headers = ['ID', 'Pattern', 'Priority', 'Condition', 'Matched', 'Runnable'];

        $rows = [];
        foreach ($matching as $action) {
            $conditionMet = $action->conditionSatisfied($event);
            $rows[] = [
                $action->id,
                $action->on,
                (string) $action->priority,
                $action->condition ?? '-',
                $conditionMet ? '✓' : '✗ (condition)',
                $conditionMet ? '✓' : '✗',
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Handle invalid action names.
     */
    private function invalidAction(string $action): int
    {
        $this->components->error("Invalid action '{$action}'. Use: list, show, test");

        return self::FAILURE;
    }

    /**
     * Describe a handler callable.
     */
    private function describeHandler(mixed $handler): string
    {
        if (is_array($handler) && is_object($handler[0])) {
            return get_class($handler[0]) . '@' . $handler[1];
        }

        if (is_object($handler)) {
            return get_class($handler) . '::__invoke';
        }

        if (is_string($handler)) {
            return $handler;
        }

        return 'Closure';
    }
}
