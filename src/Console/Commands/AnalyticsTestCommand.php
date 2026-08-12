<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Sends a test event to all configured analytics providers.
 *
 * Validates that API keys, measurement IDs, and access tokens are correctly
 * configured by dispatching a synthetic test event to every enabled provider
 * and reporting the result. Covers all 10 supported providers:
 * GA4, GTM, Meta Pixel, Plausible, PostHog, Mixpanel, Amplitude,
 * Webhook, TikTok Pixel, and LinkedIn Insight Tag.
 *
 * Options:
 *   --event=custom_name   Custom event name (default: zb_test_event)
 *   --validate            Use GA4 debug/validation endpoint instead of live
 *   --json                Output results as JSON
 *   --dry-run             Show what would be dispatched without actually dispatching
 *
 * @since 1.0.0
 * @version 35.0.0
 */
final class AnalyticsTestCommand extends Command
{
    protected $signature = 'zb:analytics:test
        {--event=zb_test_event : Custom event name to send}
        {--validate : Use GA4 debug endpoint instead of live endpoint}
        {--json : Output results as machine-readable JSON}
        {--dry-run : Show what would be dispatched without sending}';

    protected $description = 'Send a test event to all 10 configured analytics providers';

    private AnalyticsManager $manager;

    /** @var list<array{provider: string, enabled: bool, dispatched: bool, error?: string, latency_ms?: float}> */
    private array $results = [];

    public function __construct(AnalyticsManager $manager): void
    {
        parent::__construct();
        $this->manager = $manager;
    }

    /**
     * Execute the test command.
     */
    #[\Override]
    public function handle(): int
    {
        $this->results = [];
        $start = microtime(true);

        $eventName = (string) $this->option('event');
        $useValidate = (bool) $this->option('validate');
        $dryRun = (bool) $this->option('dry-run');
        $outputJson = (bool) $this->option('json');

        if (! $outputJson) {
            $this->info('🧪 ZeroBoiler Analytics — Provider Test');
            $this->info("   Version: " . AnalyticsEvent::VERSION);
            $this->newLine();
        }

        // Build test event with diagnostic context
        $event = new AnalyticsEvent(
            name: $eventName,
            params: [
                'source' => 'zb:analytics:test',
                'timestamp' => now()->toIso8601String(),
                'environment' => app()->environment(),
                'test_run' => (string) now()->getTimestamp(),
            ],
        );

        if ($dryRun) {
            $this->line("── DRY RUN MODE (no events dispatched) ──");
            $this->newLine();
        }

        // Test all 10 providers
        $this->testProvider('GA4', $this->manager->ga4()->isEnabled(), function () use ($event, $useValidate, $dryRun): void {
            if ($dryRun) {
                return;
            }
            if ($useValidate) {
                $this->manager->ga4()->validate($event);
            } else {
                $this->manager->ga4()->track($event);
            }
        });

        $this->testProvider('GTM', $this->manager->gtm()->isEnabled(), function () use ($event, $dryRun): void {
            if (! $dryRun) {
                $this->manager->gtm()->track($event);
            }
        });

        $this->testProvider('Meta Pixel', $this->manager->meta()->isEnabled(), function () use ($event, $dryRun): void {
            if (! $dryRun) {
                $this->manager->meta()->track($event);
            }
        });

        $this->testProvider('Plausible', $this->manager->plausible()->isEnabled(), function () use ($event, $dryRun): void {
            if (! $dryRun) {
                $this->manager->plausible()->track($event);
            }
        });

        $this->testProvider('PostHog', $this->manager->posthog()->isEnabled(), function () use ($event, $dryRun): void {
            if (! $dryRun) {
                $this->manager->posthog()->track($event);
            }
        });

        $this->testProvider('Mixpanel', $this->manager->mixpanel()->isEnabled(), function () use ($event, $dryRun): void {
            if (! $dryRun) {
                $this->manager->mixpanel()->track($event);
            }
        });

        $this->testProvider('Amplitude', $this->manager->amplitude()->isEnabled(), function () use ($event, $dryRun): void {
            if (! $dryRun) {
                $this->manager->amplitude()->track($event);
            }
        });

        $this->testProvider('Webhook', $this->manager->webhook()->isEnabled(), function () use ($event, $dryRun): void {
            if (! $dryRun) {
                $this->manager->webhook()->track($event);
            }
        });

        $this->testProvider('TikTok', $this->manager->tiktok()->isEnabled(), function () use ($event, $dryRun): void {
            if (! $dryRun) {
                $this->manager->tiktok()->track($event);
            }
        });

        $this->testProvider('LinkedIn', $this->manager->linkedin()->isEnabled(), function () use ($event, $dryRun): void {
            if (! $dryRun) {
                $this->manager->linkedin()->track($event);
            }
        });

        // Summary
        $elapsed = round((microtime(true) - $start) * 1000);
        $enabled = array_filter($this->results, static fn (array $r): bool => $r['enabled']);
        $dispatched = array_filter($this->results, static fn (array $r): bool => $r['dispatched']);
        $failed = array_filter($this->results, static fn (array $r): bool => isset($r['error']));

        if (! $outputJson) {
            // Consent state
            $this->newLine();
            $consent = $this->manager->getConsent();
            $this->line('Current consent state:');
            foreach ($consent->toArray() as $signal => $state) {
                $icon = $state === 'granted' ? '✅' : '🚫';
                $this->line("  {$icon} {$signal}: {$state}");
            }

            // Catalog summary
            $this->newLine();
            $catalogCount = EventCatalog::count();
            $this->line("Event catalog: {$catalogCount} events across " . count(EventCatalog::byCategory()) . " categories");

            $this->newLine();
            $this->info('═══════════════════════════════════════════════════════');
            $this->info("  Test Complete — {$elapsed}ms");
            $this->info('  Providers: ' . count($this->results) . ' total, ' . count($enabled) . ' enabled, ' . count($dispatched) . ' dispatched');
            if (count($failed) > 0) {
                $this->warn('  ⚠️  ' . count($failed) . ' provider(s) reported errors');
            } else {
                $this->info('  ✅ All enabled providers dispatched successfully');
            }
            $this->info('═══════════════════════════════════════════════════════');

            if (count($enabled) === 0) {
                $this->warn('No providers are enabled. Configure via .env:');
                $this->line('  ANALYTICS_GA4_ENABLED=true');
                $this->line('  ANALYTICS_GA4_MEASUREMENT_ID=G-XXXXXXXXXX');
            }
        }

        if ($outputJson) {
            $this->line(json_encode([
                'version' => AnalyticsEvent::VERSION,
                'event' => $eventName,
                'elapsed_ms' => $elapsed,
                'dry_run' => $dryRun,
                'validate' => $useValidate,
                'total_providers' => count($this->results),
                'enabled' => count($enabled),
                'dispatched' => count($dispatched),
                'failed' => count($failed),
                'results' => $this->results,
                'catalog_events' => EventCatalog::count(),
                'catalog_categories' => count(EventCatalog::byCategory()),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        return count($failed) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Test a single provider and record the result.
     *
     * @param  string  $name  Provider display name
     * @param  bool  $enabled  Whether the provider is enabled
     * @param  callable(): void  $dispatch  The dispatch callback
     */
    private function testProvider(string $name, bool $enabled, callable $dispatch): void
    {
        $outputJson = (bool) $this->option('json');
        $dispatched = false;
        $error = null;
        $latencyMs = 0.0;

        $status = $enabled ? '<fg=green>ENABLED</>' : '<fg=yellow>DISABLED</>';

        if ($enabled) {
            $start = microtime(true);
            try {
                $dispatch();
                $dispatched = true;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
            $latencyMs = round((microtime(true) - $start) * 1000, 2);
        }

        $this->results[] = [
            'provider' => $name,
            'enabled' => $enabled,
            'dispatched' => $dispatched,
            'error' => $error,
            'latency_ms' => $latencyMs,
        ];

        if (! $outputJson) {
            $icon = $dispatched ? '✅' : ($error !== null ? '❌' : '⏭️ ');
            $this->line("  {$icon} {$name}: {$status}" . ($enabled ? " ({$latencyMs}ms)" : ''));
            if ($error !== null) {
                $this->warn("     Error: {$error}");
            }
        }
    }
}
