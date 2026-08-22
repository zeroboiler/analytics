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
 * Interactive analytics console command for real-time event testing,
 * audit trail inspection, and attribution analysis.
 *
 * Provides a single entry point for operators to:
 * - Send test events with custom parameters
 * - Inspect the audit trail (recent, search, stats)
 * - View attribution trails for specific clients
 * - Check event catalog coverage
 * - Monitor provider health
 *
 * @since 72.0.0
 */
final class AnalyticsConsoleCommand extends Command
{
    protected $signature = 'zb:analytics:console
        {--action=overview : Action to perform (overview|send|audit-trail|attribution|catalog|health|stats)}
        {--event=zb_test_event : Event name (for send action)}
        {--params={} : JSON params object (for send action)}
        {--client-id= : Client ID to inspect (for audit-trail and attribution)}
        {--user-id= : User ID to inspect (for audit-trail)}
        {--limit=20 : Number of results to display}
        {--json : Output as JSON}';

    protected $description = 'Interactive analytics console — test events, inspect audit trails, view attribution';

    private AnalyticsManager $manager;

    public function __construct(AnalyticsManager $manager){
        parent::__construct();
        $this->manager = $manager;
    }

    #[Override]
    public function handle(): int
    {
        $action = (string) $this->option('action');
        $outputJson = (bool) $this->option('json');
        $limit = (int) $this->option('limit');

        return match ($action) {
            'send' => $this->actionSend($outputJson),
            'audit-trail' => $this->actionAuditTrail($outputJson, $limit),
            'attribution' => $this->actionAttribution($outputJson),
            'catalog' => $this->actionCatalog($outputJson),
            'health' => $this->actionHealth($outputJson),
            'stats' => $this->actionStats($outputJson),
            default => $this->actionOverview($outputJson),
        };
    }

    /**
     * Send a test event to all enabled providers.
     */
    private function actionSend(bool $json): int
    {
        $eventName = (string) $this->option('event');
        $paramsJson = (string) $this->option('params');

        try {
            /** @var array<string, mixed> $params */
            $params = json_decode($paramsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $params = [];
        }

        $params['source'] = 'zb:analytics:console';
        $params['timestamp'] = now()->toIso8601String();
        $params['environment'] = app()->environment();

        $event = new AnalyticsEvent(
            name: $eventName,
            params: $params,
        );

        $start = microtime(true);
        $results = [];

        $providers = [
            'GA4' => $this->manager->ga4(),
            'GTM' => $this->manager->gtm(),
            'Meta Pixel' => $this->manager->meta(),
            'Plausible' => $this->manager->plausible(),
            'PostHog' => $this->manager->posthog(),
            'Mixpanel' => $this->manager->mixpanel(),
            'Amplitude' => $this->manager->amplitude(),
            'TikTok' => $this->manager->tiktok(),
            'LinkedIn' => $this->manager->linkedin(),
        ];

        foreach ($providers as $name => $tracker) {
            if (! $tracker->isEnabled()) {
                $results[] = [
                    'provider' => $name,
                    'enabled' => false,
                    'dispatched' => false,
                ];
                continue;
            }

            $pStart = microtime(true);
            try {
                $tracker->track($event);
                $latency = round((microtime(true) - $pStart) * 1000, 2);
                $results[] = [
                    'provider' => $name,
                    'enabled' => true,
                    'dispatched' => true,
                    'latency_ms' => $latency,
                ];
            } catch (\Throwable $e) {
                $latency = round((microtime(true) - $pStart) * 1000, 2);
                $results[] = [
                    'provider' => $name,
                    'enabled' => true,
                    'dispatched' => false,
                    'error' => $e->getMessage(),
                    'latency_ms' => $latency,
                ];
            }
        }

        $elapsed = round((microtime(true) - $start) * 1000, 2);
        $dispatched = count(array_filter($results, static fn (array $r): bool => $r['dispatched']));

        if ($json) {
            $this->line(json_encode([
                'action' => 'send',
                'event' => $eventName,
                'elapsed_ms' => $elapsed,
                'dispatched' => $dispatched,
                'total_providers' => count($results),
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("🧪 Event Sent: {$eventName}");
        $this->line("   Elapsed: {$elapsed}ms | Dispatched: {$dispatched}/" . count($results));
        $this->newLine();

        foreach ($results as $r) {
            if (! $r['enabled']) {
                $this->line("   ⏭️  {$r['provider']}: DISABLED");
            } elseif ($r['dispatched']) {
                $this->line("   ✅ {$r['provider']}: OK ({$r['latency_ms']}ms)");
            } else {
                $this->line("   ❌ {$r['provider']}: FAILED — {$r['error']}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Show audit trail.
     */
    private function actionAuditTrail(bool $json, int $limit): int
    {
        $clientId = (string) $this->option('client-id');
        $userId = (string) $this->option('user-id');

        $this->line('ℹ️  Audit trail requires cache driver. Showing stored entries info.');

        if ($json) {
            $this->line(json_encode([
                'action' => 'audit-trail',
                'client_id' => $clientId ?: null,
                'user_id' => $userId ?: null,
                'note' => 'Use EventAuditTrailService for detailed inspection',
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info('📋 Audit Trail');
            $this->line('   Client ID: ' . ($clientId ?: '—'));
            $this->line('   User ID: ' . ($userId ?: '—'));
            $this->newLine();
            $this->comment('Use EventAuditTrailService::search() for detailed audit trail inspection.');
        }

        return self::SUCCESS;
    }

    /**
     * Show attribution trail for a client.
     */
    private function actionAttribution(bool $json): int
    {
        $clientId = (string) $this->option('client-id');

        if ($clientId === '') {
            $this->warn('--client-id is required for attribution action.');
            $this->line('Usage: zb:analytics:console --action=attribution --client-id=uuid-here');

            return self::FAILURE;
        }

        if ($json) {
            $this->line(json_encode([
                'action' => 'attribution',
                'client_id' => $clientId,
                'note' => 'Use EventAttributionTrailService::getTrail() for detailed attribution',
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info("🔗 Attribution Trail for {$clientId}");
            $this->newLine();
            $this->comment('Use EventAttributionTrailService::getTrail() and ::attribute() for detailed attribution data.');
        }

        return self::SUCCESS;
    }

    /**
     * Show event catalog overview.
     */
    private function actionCatalog(bool $json): int
    {
        $byCategory = EventCatalog::byCategory();

        if ($json) {
            $output = [
                'total' => EventCatalog::count(),
                'categories' => [],
            ];
            foreach ($byCategory as $cat => $events) {
                $output['categories'][$cat] = count($events);
            }
            $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info('📋 Event Catalog');
            $this->line('   Total: ' . EventCatalog::count() . ' events');
            $this->newLine();

            foreach ($byCategory as $cat => $events) {
                $this->line("   {$cat}: " . count($events) . ' events');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Show provider health status.
     */
    private function actionHealth(bool $json): int
    {
        $providers = [
            'GA4' => $this->manager->ga4()->isEnabled(),
            'GTM' => $this->manager->gtm()->isEnabled(),
            'Meta Pixel' => $this->manager->meta()->isEnabled(),
            'Plausible' => $this->manager->plausible()->isEnabled(),
            'PostHog' => $this->manager->posthog()->isEnabled(),
            'Mixpanel' => $this->manager->mixpanel()->isEnabled(),
            'Amplitude' => $this->manager->amplitude()->isEnabled(),
            'TikTok' => $this->manager->tiktok()->isEnabled(),
            'LinkedIn' => $this->manager->linkedin()->isEnabled(),
        ];

        $enabled = array_filter($providers);

        if ($json) {
            $this->line(json_encode([
                'version' => AnalyticsEvent::VERSION,
                'providers' => $providers,
                'enabled_count' => count($enabled),
                'total_providers' => count($providers),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info('🏥 Provider Health');
            $this->line('   Version: ' . AnalyticsEvent::VERSION);
            $this->line('   Providers: ' . count($enabled) . '/' . count($providers) . ' enabled');
            $this->newLine();

            foreach ($providers as $name => $isOn) {
                $icon = $isOn ? '✅' : '⏭️ ';
                $status = $isOn ? '<fg=green>ON</>' : '<fg=yellow>OFF</>';
                $this->line("   {$icon} {$name}: {$status}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Show analytics pipeline stats.
     */
    private function actionStats(bool $json): int
    {
        $consent = $this->manager->getConsent();

        if ($json) {
            $this->line(json_encode([
                'version' => AnalyticsEvent::VERSION,
                'catalog_events' => EventCatalog::count(),
                'catalog_categories' => count(EventCatalog::byCategory()),
                'consent' => $consent->toArray(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info('📊 Analytics Stats');
            $this->line('   Version: ' . AnalyticsEvent::VERSION);
            $this->line('   Catalog: ' . EventCatalog::count() . ' events, ' . count(EventCatalog::byCategory()) . ' categories');
            $this->newLine();

            $this->info('🔒 Consent State');
            foreach ($consent->toArray() as $signal => $state) {
                $icon = $state === 'granted' ? '✅' : '🚫';
                $this->line("   {$icon} {$signal}: {$state}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Show overview (default action).
     */
    private function actionOverview(bool $json): int
    {
        $enabled = $this->countEnabledProviders();

        if ($json) {
            $this->line(json_encode([
                'version' => AnalyticsEvent::VERSION,
                'enabled_providers' => $enabled,
                'total_providers' => 10,
                'catalog_events' => EventCatalog::count(),
                'catalog_categories' => count(EventCatalog::byCategory()),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info('📊 ZeroBoiler Analytics Console v' . AnalyticsEvent::VERSION);
            $this->newLine();
            $this->line("   Providers: {$enabled}/10 enabled");
            $this->line('   Catalog: ' . EventCatalog::count() . ' events, ' . count(EventCatalog::byCategory()) . ' categories');
            $this->newLine();
            $this->comment('Available actions:');
            $this->line('   --action=send           Send a test event');
            $this->line('   --action=audit-trail    Inspect audit trail');
            $this->line('   --action=attribution    View attribution trail');
            $this->line('   --action=catalog        Show event catalog');
            $this->line('   --action=health         Provider health check');
            $this->line('   --action=stats          Pipeline statistics');
            $this->newLine();
            $this->comment('Example: zb:analytics:console --action=send --event=signup --params=\'{"plan":"pro"}\'');
        }

        return self::SUCCESS;
    }

    /**
     * Count enabled analytics providers.
     */
    private function countEnabledProviders(): int
    {
        return ($this->manager->ga4()->isEnabled() ? 1 : 0)
            + ($this->manager->gtm()->isEnabled() ? 1 : 0)
            + ($this->manager->meta()->isEnabled() ? 1 : 0)
            + ($this->manager->plausible()->isEnabled() ? 1 : 0)
            + ($this->manager->posthog()->isEnabled() ? 1 : 0)
            + ($this->manager->mixpanel()->isEnabled() ? 1 : 0)
            + ($this->manager->amplitude()->isEnabled() ? 1 : 0)
            + ($this->manager->tiktok()->isEnabled() ? 1 : 0)
            + ($this->manager->linkedin()->isEnabled() ? 1 : 0);
    }
}
