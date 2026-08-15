<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\EventSamplingStrategyService;

/**
 * Admin CLI for event sampling strategy management.
 *
 * Inspect and manage event sampling configuration, rates, metrics,
 * and adaptive counters.
 *
 * @since 46.0.0
 */
final class AnalyticsSamplingCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:sampling
        {action : Action to perform (status|metrics|summary|set-global|set-event|set-category|remove-event|reset-metrics|reset-adaptive|list-overrides|preview)}
        {--rate= : Sampling rate (0.0-1.0) for set-global/set-event/set-category}
        {--event= : Event name for set-event/remove-event/preview}
        {--category= : Category name for set-category}
        {--json : Output as JSON}';

    /** @var string */
    protected $description = 'Manage event sampling strategies — inspect rates, metrics, and adaptive counters';

    /**
     * Execute the command.
     */
    #[Override]
    public function handle(CacheRepository $cache, ConfigRepository $config): int
    {
        $action = $this->argument('action');
        $service = new EventSamplingStrategyService($cache, $config);

        return match ($action) {
            'status' => $this->status($service),
            'metrics' => $this->metrics($service),
            'summary' => $this->summary($service),
            'set-global' => $this->setGlobal($service),
            'set-event' => $this->setEvent($service),
            'set-category' => $this->setCategory($service),
            'remove-event' => $this->removeEvent($service),
            'reset-metrics' => $this->resetMetrics($service),
            'reset-adaptive' => $this->resetAdaptive($service),
            'list-overrides' => $this->listOverrides($service),
            'preview' => $this->preview($service),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Show sampling status (enabled/disabled, strategy, global rate).
     */
    private function status(EventSamplingStrategyService $service): int
    {
        $output = [
            'enabled' => $service->isEnabled(),
            'strategy' => $service->getStrategy(),
            'global_rate' => $service->getGlobalRate(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($output, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Sampling: %s | Strategy: %s | Global Rate: %s',
            $service->isEnabled() ? '✅ Enabled' : '❌ Disabled',
            $service->getStrategy(),
            number_format($service->getGlobalRate(), 2),
        ));

        return self::SUCCESS;
    }

    /**
     * Show sampling metrics (passed, dropped, total).
     */
    private function metrics(EventSamplingStrategyService $service): int
    {
        $metrics = $service->getMetrics();

        if ($this->option('json')) {
            $this->line(json_encode($metrics, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Event Sampling Metrics');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Passed', $metrics['passed']],
                ['Dropped', $metrics['dropped']],
                ['Total Evaluated', $metrics['total']],
                ['Critical (Bypassed)', $metrics['critical_passed']],
                ['Current Rate', number_format($metrics['rate'], 4)],
                ['Strategy', $metrics['strategy']],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * Show sampling summary.
     */
    private function summary(EventSamplingStrategyService $service): int
    {
        $summary = $service->summary();

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Sampling Configuration Summary');
        $this->table(
            ['Key', 'Value'],
            [
                ['Enabled', $summary['enabled'] ? 'Yes' : 'No'],
                ['Strategy', $summary['strategy']],
                ['Global Rate', number_format($summary['global_rate'], 4)],
                ['Event Overrides', (string) $summary['event_overrides_count']],
                ['Category Overrides', (string) $summary['category_overrides_count']],
            ],
        );

        if (! empty($summary['effective_rates'])) {
            $this->info('Effective Rates:');
            $this->table(
                ['Key', 'Rate'],
                array_map(
                    fn (string $key, float $rate): array => [$key, number_format($rate, 4)],
                    array_keys($summary['effective_rates']),
                    array_values($summary['effective_rates']),
                ),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Set the global sampling rate.
     */
    private function setGlobal(EventSamplingStrategyService $service): int
    {
        $rate = $this->option('rate');

        if ($rate === null) {
            $this->error('--rate is required for set-global (e.g. --rate=0.1)');

            return self::FAILURE;
        }

        $rateFloat = (float) $rate;

        if ($rateFloat < 0.0 || $rateFloat > 1.0) {
            $this->error('Rate must be between 0.0 and 1.0');

            return self::FAILURE;
        }

        $service->setGlobalRate($rateFloat);
        $this->info("Global sampling rate set to " . number_format($rateFloat, 4));

        return self::SUCCESS;
    }

    /**
     * Set a per-event sampling rate override.
     */
    private function setEvent(EventSamplingStrategyService $service): int
    {
        $event = $this->option('event');
        $rate = $this->option('rate');

        if ($event === null) {
            $this->error('--event is required for set-event');

            return self::FAILURE;
        }

        if ($rate === null) {
            $this->error('--rate is required for set-event');

            return self::FAILURE;
        }

        $rateFloat = (float) $rate;

        if ($rateFloat < 0.0 || $rateFloat > 1.0) {
            $this->error('Rate must be between 0.0 and 1.0');

            return self::FAILURE;
        }

        $service->setEventRate($event, $rateFloat);
        $this->info("Event '{$event}' sampling rate set to " . number_format($rateFloat, 4));

        return self::SUCCESS;
    }

    /**
     * Set a per-category sampling rate override.
     */
    private function setCategory(EventSamplingStrategyService $service): int
    {
        $category = $this->option('category');
        $rate = $this->option('rate');

        if ($category === null) {
            $this->error('--category is required for set-category');

            return self::FAILURE;
        }

        if ($rate === null) {
            $this->error('--rate is required for set-category');

            return self::FAILURE;
        }

        $rateFloat = (float) $rate;

        if ($rateFloat < 0.0 || $rateFloat > 1.0) {
            $this->error('Rate must be between 0.0 and 1.0');

            return self::FAILURE;
        }

        $service->setCategoryRate($category, $rateFloat);
        $this->info("Category '{$category}' sampling rate set to " . number_format($rateFloat, 4));

        return self::SUCCESS;
    }

    /**
     * Remove a per-event sampling rate override.
     */
    private function removeEvent(EventSamplingStrategyService $service): int
    {
        $event = $this->option('event');

        if ($event === null) {
            $this->error('--event is required for remove-event');

            return self::FAILURE;
        }

        $service->removeEventRate($event);
        $this->info("Event '{$event}' sampling override removed");

        return self::SUCCESS;
    }

    /**
     * Reset sampling metrics counters.
     */
    private function resetMetrics(EventSamplingStrategyService $service): int
    {
        $service->resetMetrics();
        $this->info('Sampling metrics reset');

        return self::SUCCESS;
    }

    /**
     * Reset adaptive volume counters.
     */
    private function resetAdaptive(EventSamplingStrategyService $service): int
    {
        $service->resetAdaptiveCounters();
        $this->info('Adaptive volume counters reset');

        return self::SUCCESS;
    }

    /**
     * List all event and category overrides.
     */
    private function listOverrides(EventSamplingStrategyService $service): int
    {
        $eventOverrides = $service->getEventOverrides();
        $categoryOverrides = $service->getCategoryOverrides();

        if ($this->option('json')) {
            $this->line(json_encode([
                'event_overrides' => $eventOverrides,
                'category_overrides' => $categoryOverrides,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (empty($eventOverrides) && empty($categoryOverrides)) {
            $this->info('No sampling overrides configured');

            return self::SUCCESS;
        }

        if (! empty($eventOverrides)) {
            $this->info('Event Overrides:');
            $this->table(
                ['Event', 'Rate'],
                array_map(
                    fn (string $event, float $rate): array => [$event, number_format($rate, 4)],
                    array_keys($eventOverrides),
                    array_values($eventOverrides),
                ),
            );
        }

        if (! empty($categoryOverrides)) {
            $this->info('Category Overrides:');
            $this->table(
                ['Category', 'Rate'],
                array_map(
                    fn (string $cat, float $rate): array => [$cat, number_format($rate, 4)],
                    array_keys($categoryOverrides),
                    array_values($categoryOverrides),
                ),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Preview the resolved sampling rate for a specific event.
     */
    private function preview(EventSamplingStrategyService $service): int
    {
        $event = $this->option('event');

        if ($event === null) {
            $this->error('--event is required for preview');

            return self::FAILURE;
        }

        $dto = new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(name: $event);
        $rate = $service->resolveRate($dto);

        if ($this->option('json')) {
            $this->line(json_encode([
                'event' => $event,
                'resolved_rate' => $rate,
                'global_rate' => $service->getGlobalRate(),
                'strategy' => $service->getStrategy(),
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Event: %s | Resolved Rate: %s | Strategy: %s',
            $event,
            number_format($rate, 4),
            $service->getStrategy(),
        ));

        return self::SUCCESS;
    }

    /**
     * Handle invalid action.
     */
    private function invalidAction(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line('Valid actions: status, metrics, summary, set-global, set-event, set-category, remove-event, reset-metrics, reset-adaptive, list-overrides, preview');

        return self::FAILURE;
    }
}
