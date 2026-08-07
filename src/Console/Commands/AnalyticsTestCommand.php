<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Sends a test event to all configured analytics providers.
 *
 * Useful for verifying that API keys, measurement IDs, and access tokens
 * are correctly configured.Validates the response from each provider.
 */
final class AnalyticsTestCommand extends Command
{
    protected $signature = 'zb:analytics:test
        {--event=test_event : Custom event name to send}
        {--validate : Use GA4 debug endpoint instead of live endpoint}';

    protected $description = 'Send a test event to all configured analytics providers';

    private AnalyticsManager $manager;

    public function __construct(AnalyticsManager $manager): void
    {
        parent::__construct();
        $this->manager = $manager;
    }

    #[\Override]
    public function handle(): int
    {
        $this->info('🧪 ZeroBoiler Analytics Test');
        $this->newLine();

        // Check which providers are enabled
        $ga4 = $this->manager->ga4();
        $gtm = $this->manager->gtm();
        $meta = $this->manager->meta();
        $plausible = $this->manager->plausible();
        $posthog = $this->manager->posthog();

        $anyEnabled = $ga4->isEnabled() || $gtm->isEnabled() || $meta->isEnabled() || $plausible->isEnabled() || $posthog->isEnabled();

        if (! $anyEnabled) {
            $this->warn('⚠️  No analytics providers are enabled.');
            $this->line('Check your.env configuration:');
            $this->line('  ANALYTICS_GA4_ENABLED=true');
            $this->line('  ANALYTICS_GA4_MEASUREMENT_ID=G-XXXXXXXXXX');
            $this->line('  ANALYTICS_GA4_API_SECRET=your_secret');

            return self::FAILURE;
        }

        $eventName = (string) $this->option('event');
        $useValidate = (bool) $this->option('validate');

        $event = new AnalyticsEvent(
            name: $eventName,
            params: [
                'source' => 'zb:analytics:test',
                'timestamp' => now()->toIso8601String(),
                'environment' => app()->environment(),
            ],
        );

        // GA4
        $this->section('GA4', $ga4->isEnabled());
        if ($ga4->isEnabled()) {
            $this->line('  Measurement ID: '.$ga4->getMeasurementId());

            if ($useValidate) {
                $this->line('  Using debug/validate endpoint...');
                $result = $ga4->validate($event);
                if ($result !== null) {
                    $this->line('  Response: '.json_encode($result, JSON_PRETTY_PRINT));
                } else {
                    $this->warn('  No response received (check network/credentials)');
                }
            } else {
                $this->line('  Dispatching live event...');
                $ga4->track($event);
                $this->info('  ✅ Event dispatched successfully');
            }
        }

        // GTM
        $this->newLine();
        $this->section('GTM', $gtm->isEnabled());
        if ($gtm->isEnabled()) {
            $this->line('  Container ID: '.$gtm->getContainerId());
            $this->line('  GTM is client-side only (check browser console for events)');
        }

        // Meta Pixel
        $this->newLine();
        $this->section('Meta Pixel', $meta->isEnabled());
        if ($meta->isEnabled()) {
            $this->line('  Pixel ID: '.$meta->getPixelId());
            $meta->track($event);
            $this->info('  ✅ Event dispatched successfully');
        }

        // Plausible
        $this->newLine();
        $this->section('Plausible', $plausible->isEnabled());
        if ($plausible->isEnabled()) {
            $this->line('  Domain: '.$plausible->getDomain());
            $plausible->track($event);
            $this->info('  ✅ Event dispatched successfully');
        }

        // PostHog
        $this->newLine();
        $this->section('PostHog', $posthog->isEnabled());
        if ($posthog->isEnabled()) {
            $this->line('  Host: '.$posthog->getHost());
            $posthog->track($event);
            $this->info('  ✅ Event dispatched successfully');
        }

        // Consent
        $this->newLine();
        $consent = $this->manager->getConsent();
        $this->line('Current consent state:');
        foreach ($consent->toArray() as $signal => $state) {
            $icon = $state === 'granted' ? '✅' : '🚫';
            $this->line("  {$icon} {$signal}: {$state}");
        }

        $this->newLine();
        $this->info('✨ Test complete.');

        return self::SUCCESS;
    }

    private function section(string $name, bool $enabled): void
    {
        $status = $enabled ? '<fg=green>ENABLED</>' : '<fg=yellow>DISABLED</>';
        $this->line("  {$name}: {$status}");
    }
}
