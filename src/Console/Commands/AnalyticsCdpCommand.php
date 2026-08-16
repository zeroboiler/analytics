<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\CDP\CdpProfileService;
use ZeroBoiler\Analytics\Facades\Analytics;

/**
 * CDP Analytics Overview Command — inspect CDP profiles, traits, and segments.
 *
 * Provides a comprehensive overview of the Customer Data Platform state:
 * - Total profiles, trait definitions, segments
 * - Profile details (traits, segments, engagement score)
 * - Segment membership counts
 * - Trait definition listing
 *
 * Usage:
 *   php artisan analytics:cdp                   # Overview
 *   php artisan analytics:cdp --profile=123      # Show specific profile
 *   php artisan analytics:cdp --segments        # List all segments
 *   php artisan analytics:cdp --traits          # List all trait definitions
 *   php artisan analytics:cdp --erase=user_123   # GDPR erase a profile
 *
 * @since 196.0.0
 */
final class AnalyticsCdpCommand extends Command
{
    /** @var string */
    protected $signature = 'analytics:cdp
        {--profile= : Show details for a specific user profile}
        {--segments : List all segment definitions}
        {--traits : List all trait definitions}
        {--erase= : GDPR-erase a user profile}
        {--format=text : Output format (text|json)}';

    /** @var string */
    protected $description = 'CDP profile management — inspect profiles, traits, segments';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $profileService = $this->getCdpProfileService();

        // GDPR erase
        $eraseUser = $this->option('erase');
        if ($eraseUser !== null && $eraseUser !== '') {
            return $this->eraseProfile($profileService, (string) $eraseUser);
        }

        // Show specific profile
        $profileId = $this->option('profile');
        if ($profileId !== null && $profileId !== '') {
            return $this->showProfile($profileService, (string) $profileId);
        }

        // List segments
        if ($this->option('segments')) {
            return $this->showSegments($profileService);
        }

        // List traits
        if ($this->option('traits')) {
            return $this->showTraits($profileService);
        }

        // Overview
        return $this->showOverview($profileService);
    }

    /**
     * Show CDP overview.
     *
     * @param  CdpProfileService  $service
     * @return int
     */
    private function showOverview(CdpProfileService $service): int
    {
        $summary = $service->getSummary();

        $this->info('📊 ZeroBoiler CDP Overview');
        $this->newLine();
        $this->line('  Profiles:          <info>' . $summary['total_profiles'] . '</info>');
        $this->line('  Segments:         <info>' . $summary['total_segments'] . '</info>');
        $this->line('  Trait Definitions: <info>' . $summary['total_trait_definitions'] . '</info>');
        $this->line('  Enabled:          <info>' . ($summary['enabled'] ? 'Yes' : 'No') . '</info>');
        $this->newLine();

        $this->info('Options:');
        $this->line('  --profile=ID     Show specific profile details');
        $this->line('  --segments       List segment definitions');
        $this->line('  --traits         List trait definitions');
        $this->line('  --erase=ID       GDPR-erase a profile');

        return self::SUCCESS;
    }

    /**
     * Show a specific user profile.
     *
     * @param  CdpProfileService  $service
     * @param  string  $userId
     * @return int
     */
    private function showProfile(CdpProfileService $service, string $userId): int
    {
        $profile = $service->getProfile($userId);

        $this->info('👤 CDP Profile: ' . $userId);
        $this->newLine();

        if ($profile->email !== null) {
            $this->line('  Email:             <info>' . $profile->email . '</info>');
        }

        if ($profile->createdAt !== null) {
            $this->line('  Created:           <info>' . date('Y-m-d H:i:s', $profile->createdAt) . '</info>');
        }

        if ($profile->updatedAt !== null) {
            $this->line('  Updated:           <info>' . date('Y-m-d H:i:s', $profile->updatedAt) . '</info>');
        }

        $this->line('  Total Events:      <info>' . $profile->totalEvents . '</info>');
        $this->line('  Total Sessions:    <info>' . $profile->totalSessions . '</info>');

        $engagement = $profile->engagementScore();
        $this->line('  Engagement Score:  <info>' . ($engagement ?? 'N/A') . '</info>');

        $daysSinceCreation = $profile->daysSinceCreation();
        $this->line('  Profile Age:       <info>' . ($daysSinceCreation ?? 'N/A') . ' days</info>');

        $daysSinceActivity = $profile->daysSinceLastActivity();
        $this->line('  Last Activity:     <info>' . ($daysSinceActivity ?? 'N/A') . ' days ago</info>');

        // Traits
        $this->newLine();
        $this->info('Traits (' . count($profile->traits) . '):');

        $traits = $profile->traits;
        if ($traits !== []) {
            foreach ($traits as $name => $value) {
                $displayValue = is_array($value) ? json_encode($value) : (string) $value;
                $this->line('  <comment>' . str_pad((string) $name, 28) . '</comment> ' . $displayValue);
            }
        } else {
            $this->line('  <comment>(no traits set)</comment>');
        }

        // Segments
        $this->newLine();
        $this->info('Segments (' . count($profile->segments) . '):');

        if ($profile->segments !== []) {
            foreach ($profile->segments as $segment) {
                $this->line('  ✓ <info>' . $segment . '</info>');
            }
        } else {
            $this->line('  <comment>(no segment memberships)</comment>');
        }

        return self::SUCCESS;
    }

    /**
     * List all segment definitions.
     *
     * @param  CdpProfileService  $service
     * @return int
     */
    private function showSegments(CdpProfileService $service): int
    {
        $segments = $service->segmentService()->getSegments();

        $this->info('📋 CDP Segment Definitions (' . count($segments) . ')');
        $this->newLine();

        foreach ($segments as $name => $segment) {
            $description = $segment['description'] ?? 'No description';
            $ruleCount = count($segment['rules'] ?? []);

            $this->line('  <info>' . $name . '</info> (' . $ruleCount . ' rules)');
            $this->line('    <comment>' . $description . '</comment>');

            foreach ($segment['rules'] ?? [] as $i => $rule) {
                $trait = $rule['trait'] ?? '?';
                $operator = $rule['operator'] ?? '?';
                $value = $rule['value'] ?? $rule['min'] ?? $rule['values'] ?? '?';

                if (is_array($value)) {
                    $value = '[' . implode(', ', $value) . ']';
                }

                $this->line('    ' . ($i + 1) . '. <comment>' . $trait . '</comment> ' . $operator . ' ' . $value);
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * List all trait definitions.
     *
     * @param  CdpProfileService  $service
     * @return int
     */
    private function showTraits(CdpProfileService $service): int
    {
        $definitions = $service->traitComputer()->getTraitDefinitions();

        $this->info('📊 CDP Trait Definitions (' . count($definitions) . ')');
        $this->newLine();

        foreach ($definitions as $name => $definition) {
            $type = strtoupper($definition->type);
            $computed = $definition->computed ? '<comment>computed</comment>' : '<info>static</info>';
            $description = $definition->description ?? '';

            $this->line('  <info>' . str_pad($name, 28) . '</info> ' . $type . ' ' . $computed);
            if ($description !== '') {
                $this->line('    <comment>' . $description . '</comment>');
            }

            if ($definition->computed) {
                $source = $definition->sourceEvent ?? '?';
                $agg = $definition->aggregation ?? '?';
                $field = $definition->sourceField ?? '(self)';
                $this->line('    Source: ' . $source . '.' . $field . ' → ' . $agg);
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }

    /**
     * GDPR-erase a user profile.
     *
     * @param  CdpProfileService  $service
     * @param  string  $userId
     * @return int
     */
    private function eraseProfile(CdpProfileService $service, string $userId): int
    {
        if (! $this->confirm('⚠️  This will permanently erase the CDP profile for user "' . $userId . '". Continue?')) {
            $this->warn('Erasure cancelled.');

            return self::SUCCESS;
        }

        $service->forgetProfile($userId);

        $this->info('✅ Profile erased for user: ' . $userId);

        return self::SUCCESS;
    }

    /**
     * Resolve the CDP profile service from the container.
     *
     * @return CdpProfileService
     */
    private function getCdpProfileService(): CdpProfileService
    {
        /** @var CdpProfileService $service */
        $service = app(CdpProfileService::class);

        return $service;
    }
}
