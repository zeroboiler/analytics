<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Services\EventReplaySimulator;
use ZeroBoiler\Analytics\Services\EventTtlService;
use ZeroBoiler\Analytics\Services\ReferralTrackingService;
use ZeroBoiler\Analytics\Services\TrafficSpikeShield;

/**
 * Admin command for analytics simulation, referral tracking, TTL management,
 * and traffic spike shield operations.
 *
 * Provides subcommands for:
 *   - simulate: Generate synthetic events for load testing
 *   - simulate:ecommerce: Generate e-commerce scenario events
 *   - simulate:saas: Generate SaaS lifecycle scenario events
 *   - ttl:status: View event TTL metrics
 *   - ttl:reset: Reset TTL metrics
 *   - referral:health: View referral program health metrics
 *   - referral:viral: Calculate viral coefficient (K-factor)
 *   - shield:status: View traffic spike shield status
 *   - shield:cooldown: Trigger/clear cooldown
 *
 * @since 43.0.0
 */
final class AnalyticsSimulationCommand extends Command
{
    protected $signature = 'zb:analytics:simulate
        {action : Action to perform (simulate|ecommerce|saas|ttl:status|ttl:reset|referral:health|referral:viral|shield:status|shield:cooldown)}
        {--count=100 : Number of events to generate (simulate action)}
        {--dry-run : Preview without dispatching events}
        {--json : Output as JSON}
        {--dispatch : Actually dispatch events to providers}';

    protected $description = 'Event replay simulator, referral metrics, TTL management, and traffic spike shield';

    private ?AnalyticsManager $manager = null;

    private ?EventReplaySimulator $simulator = null;

    private ?EventTtlService $ttlService = null;

    private ?ReferralTrackingService $referralService = null;

    private ?TrafficSpikeShield $spikeShield = null;

    /**
     * Get the analytics manager (lazy-loaded for testing).
     */
    private function getManager(): AnalyticsManager
    {
        if ($this->manager === null) {
            $this->manager = app(AnalyticsManager::class);
        }

        return $this->manager;
    }

    /**
     * Get the event replay simulator (lazy-loaded).
     */
    private function getSimulator(): EventReplaySimulator
    {
        if ($this->simulator === null) {
            $this->simulator = app(EventReplaySimulator::class);
        }

        return $this->simulator;
    }

    /**
     * Get the TTL service (lazy-loaded).
     */
    private function getTtlService(): EventTtlService
    {
        if ($this->ttlService === null) {
            $this->ttlService = app(EventTtlService::class);
        }

        return $this->ttlService;
    }

    /**
     * Get the referral service (lazy-loaded).
     */
    private function getReferralService(): ReferralTrackingService
    {
        if ($this->referralService === null) {
            $this->referralService = app(ReferralTrackingService::class);
        }

        return $this->referralService;
    }

    /**
     * Get the traffic spike shield (lazy-loaded).
     */
    private function getSpikeShield(): TrafficSpikeShield
    {
        if ($this->spikeShield === null) {
            $this->spikeShield = app(TrafficSpikeShield::class);
        }

        return $this->spikeShield;
    }

    #[Override]
    #[Override]
    public function handle(): int
    {
        $action = $this->argument('action');
        $outputJson = (bool) $this->option('json');

        return match ($action) {
            'simulate' => $this->handleSimulate($outputJson),
            'ecommerce' => $this->handleEcommerceSimulate($outputJson),
            'saas' => $this->handleSaaSSimulate($outputJson),
            'ttl:status' => $this->handleTtlStatus($outputJson),
            'ttl:reset' => $this->handleTtlReset(),
            'referral:health' => $this->handleReferralHealth($outputJson),
            'referral:viral' => $this->handleReferralViral($outputJson),
            'shield:status' => $this->handleShieldStatus($outputJson),
            'shield:cooldown' => $this->handleShieldCooldown(),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Handle the simulate action — generate synthetic events.
     *
     * @param  bool  $outputJson  Output as JSON
     * @return int Exit code
     */
    private function handleSimulate(bool $outputJson): int
    {
        $count = (int) $this->option('count');
        $dryRun = (bool) $this->option('dry-run');
        $dispatch = (bool) $this->option('dispatch');

        if ($outputJson) {
            $result = $this->getSimulator()->generateBatch($count);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("Generating {$count} synthetic events" . ($dryRun ? ' (dry-run)' : '') . '...');

        $dispatcher = null;
        if ($dispatch && ! $dryRun) {
            $manager = $this->getManager();
            $dispatcher = static function (mixed $event) use ($manager): void {
                $manager->track($event);
            };
        }

        $result = $this->getSimulator()->generateBatch($count, $dispatcher);

        $this->newLine();
        $this->info("  Generated:  {$result['generated']} events");
        $this->info("  Dispatched: {$result['dispatched']} events");
        $this->info("  Duration:   {$result['duration_ms']} ms");

        if (! empty($result['by_event'])) {
            $this->newLine();
            $this->info('  Event breakdown:');
            foreach ($result['by_event'] as $eventName => $eventCount) {
                $pct = round(($eventCount / max($result['generated'], 1)) * 100, 1);
                $this->line("    {$eventName}: {$eventCount} ({$pct}%)");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Handle the ecommerce simulate action.
     *
     * @param  bool  $outputJson  Output as JSON
     * @return int Exit code
     */
    private function handleEcommerceSimulate(bool $outputJson): int
    {
        $clientId = 'sim_ecom_' . substr(md5((string) mt_rand()), 0, 12);

        $dispatcher = null;
        if ($this->option('dispatch')) {
            $manager = $this->getManager();
            $dispatcher = static function (mixed $event) use ($manager): void {
                $manager->track($event);
            };
        }

        $result = $this->getSimulator()->generateEcommerceScenario($clientId, $dispatcher);

        if ($outputJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("E-commerce scenario for client: {$clientId}");
        $this->info("  Steps:   {$result['steps']}");
        $this->info("  Events:  " . implode(' → ', $result['events']));
        $this->info("  Revenue: \${$result['revenue']}");

        return self::SUCCESS;
    }

    /**
     * Handle the SaaS simulate action.
     *
     * @param  bool  $outputJson  Output as JSON
     * @return int Exit code
     */
    private function handleSaaSSimulate(bool $outputJson): int
    {
        $clientId = 'sim_saas_' . substr(md5((string) mt_rand()), 0, 12);

        $dispatcher = null;
        if ($this->option('dispatch')) {
            $manager = $this->getManager();
            $dispatcher = static function (mixed $event) use ($manager): void {
                $manager->track($event);
            };
        }

        $result = $this->getSimulator()->generateSaaSLifecycleScenario($clientId, $dispatcher);

        if ($outputJson) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info("SaaS lifecycle scenario for client: {$clientId}");
        $this->info("  Steps:     {$result['steps']}");
        $this->info("  Journey:   " . implode(' → ', $result['events']));
        $this->info("  Converted: " . ($result['converted'] ? 'Yes' : 'No'));
        $this->info("  Plan:      " . ($result['plan'] ?? 'N/A'));

        return self::SUCCESS;
    }

    /**
     * Handle TTL status action.
     *
     * @param  bool  $outputJson  Output as JSON
     * @return int Exit code
     */
    private function handleTtlStatus(bool $outputJson): int
    {
        $metrics = $this->getTtlService()->getMetrics();

        if ($outputJson) {
            $this->line(json_encode($metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Event TTL Metrics');
        $this->info("  Default TTL:      {$metrics['default_ttl']}s (" . round($metrics['default_ttl'] / 3600, 1) . 'h)');
        $this->info("  Drop Expired:     " . ($metrics['drop_expired'] ? 'Yes' : 'No'));
        $this->info("  Total Expired:    {$metrics['total_expired']}");
        $this->info("  Last Expired At:  " . ($metrics['last_expired_at'] ? date('Y-m-d H:i:s', $metrics['last_expired_at']) : 'Never'));

        if (! empty($metrics['by_event'])) {
            $this->newLine();
            $this->info('  Expired by Event:');
            foreach ($metrics['by_event'] as $eventName => $count) {
                $this->line("    {$eventName}: {$count}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Handle TTL reset action.
     *
     * @return int Exit code
     */
    private function handleTtlReset(): int
    {
        $this->getTtlService()->resetMetrics();
        $this->info('TTL metrics reset.');

        return self::SUCCESS;
    }

    /**
     * Handle referral health action.
     *
     * @param  bool  $outputJson  Output as JSON
     * @return int Exit code
     */
    private function handleReferralHealth(bool $outputJson): int
    {
        $health = $this->getReferralService()->getHealthMetrics();

        if ($outputJson) {
            $this->line(json_encode($health, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Referral Program Health');
        $this->info("  K-Factor:          {$health['viral_coefficient']}");
        $this->info("  Total Referrers:    {$health['total_referrers']}");
        $this->info("  Total Conversions:  {$health['total_conversions']}");

        if (! empty($health['funnel'])) {
            $funnel = $health['funnel'];
            $this->newLine();
            $this->info('  Referral Funnel:');
            $this->line("    Invites → Clicks → Signups");
            $this->line("    {$funnel['invites']} → {$funnel['clicks']} → {$funnel['signups']}");
            $this->line("    Click Rate:       {$funnel['click_rate']}");
            $this->line("    Conversion Rate:  {$funnel['conversion_rate']}");
        }

        if (! empty($health['top_referrers'])) {
            $this->newLine();
            $this->info('  Top Referrers:');
            foreach ($health['top_referrers'] as $ref) {
                $this->line("    {$ref['user_id']}: {$ref['conversions']} conversions, {$ref['clicks']} clicks ({$ref['rate']} rate)");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Handle referral viral coefficient action.
     *
     * @param  bool  $outputJson  Output as JSON
     * @return int Exit code
     */
    private function handleReferralViral(bool $outputJson): int
    {
        $viral = $this->getReferralService()->calculateViralCoefficient();

        if ($outputJson) {
            $this->line(json_encode($viral, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Viral Coefficient (K-Factor)');
        $this->info("  K-Factor:          {$viral['k_factor']}");
        $this->info("  Total Conversions: {$viral['total_conversions']}");
        $this->info("  Total Referrers:    {$viral['total_referrers']}");

        if ($viral['k_factor'] >= 1.0) {
            $this->warn('  ⚡ Viral growth detected (K ≥ 1.0)');
        } elseif ($viral['k_factor'] >= 0.5) {
            $this->info('  ✅ Moderate viral growth');
        } else {
            $this->comment('  ⚠ Below viral threshold (K < 0.5)');
        }

        return self::SUCCESS;
    }

    /**
     * Handle shield status action.
     *
     * @param  bool  $outputJson  Output as JSON
     * @return int Exit code
     */
    private function handleShieldStatus(bool $outputJson): int
    {
        $status = $this->getSpikeShield()->getStatus();

        if ($outputJson) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Traffic Spike Shield');
        $this->info("  Enabled:           " . ($status['enabled'] ? 'Yes' : 'No'));
        $this->info("  In Cooldown:       " . ($status['in_cooldown'] ? 'Yes' : 'No'));

        if ($status['cooldown_remaining'] !== null) {
            $this->info("  Cooldown Remaining: {$status['cooldown_remaining']}s");
        }

        $this->info("  Normal Threshold:  {$status['normal_threshold']} events/{$status['window_size']}s");
        $this->info("  Spike Threshold:   {$status['spike_threshold']} events/{$status['window_size']}s");
        $this->info("  Throttle Ratio:    {$status['throttle_ratio']}");
        $this->info("  Accepted:          {$status['total_accepted']}");
        $this->info("  Throttled:         {$status['total_throttled']}");
        $this->info("  Spike Count:       {$status['total_spikes']}");
        $this->info("  Current Window:    {$status['current_window_total']} events");

        return self::SUCCESS;
    }

    /**
     * Handle shield cooldown action.
     *
     * @return int Exit code
     */
    private function handleShieldCooldown(): int
    {
        $shield = $this->getSpikeShield();

        if ($shield->isInCooldown()) {
            $shield->clearCooldown();
            $this->info('Cooldown cleared.');
        } else {
            $shield->triggerCooldown();
            $this->info('Cooldown triggered.');
        }

        return self::SUCCESS;
    }

    /**
     * Handle invalid action.
     *
     * @param  string  $action  Invalid action name
     * @return int Exit code
     */
    private function invalidAction(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->newLine();
        $this->line('Available actions:');
        $this->line('  simulate         Generate synthetic events');
        $this->line('  ecommerce         Generate e-commerce scenario');
        $this->line('  saas              Generate SaaS lifecycle scenario');
        $this->line('  ttl:status       View TTL expiry metrics');
        $this->line('  ttl:reset        Reset TTL metrics');
        $this->line('  referral:health   Referral program health');
        $this->line('  referral:viral    Viral coefficient (K-factor)');
        $this->line('  shield:status    Traffic spike shield status');
        $this->line('  shield:cooldown  Trigger/clear cooldown');

        return self::FAILURE;
    }
}
