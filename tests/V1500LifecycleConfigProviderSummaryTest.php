<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Support\AnalyticsFake;

/**
 * V15.0.0 — Lifecycle config section, provider summary completeness,
 * trialConverted convenience, and version sweep verification.
 *
 * @covers \ZeroBoiler\Analytics\AnalyticsManager
 * @covers \ZeroBoiler\Analytics\Support\AnalyticsFake
 */
final class V1500LifecycleConfigProviderSummaryTest extends TestCase
{
    // ── Version Consistency ───────────────────────────────────────────

    public function test_version_is_15_0_0(): void
    {
        $this->assertSame('15.0.0', AnalyticsEvent::VERSION);
    }

    // ── trialConverted Convenience Method ──────────────────────────────

    public function test_trial_converted_tracks_correct_event_name(): void
    {
        $fake = new AnalyticsFake;
        $fake->trialConverted('pro', 49.99, 'USD');

        $fake->assertTracked('trial_converted');
    }

    public function test_trial_converted_with_all_params(): void
    {
        $fake = new AnalyticsFake;
        $fake->trialConverted('enterprise', 199.00, 'EUR', ['source' => 'trial_banner']);

        $fake->assertTrackedOnce('trial_converted');
        $fake->assertTrackedAtLeast('trial_converted', 1);
    }

    public function test_trial_converted_with_minimal_params(): void
    {
        $fake = new AnalyticsFake;
        $fake->trialConverted();

        $fake->assertTracked('trial_converted');
    }

    public function test_trial_converted_default_currency_is_usd(): void
    {
        $fake = new AnalyticsFake;
        $fake->trialConverted('pro', 29.99);

        $calls = $fake->trackedCalls();
        $this->assertCount(1, $calls);

        $event = $calls[0];
        $this->assertSame('trial_converted', $event['name']);
        $this->assertSame('pro', $event['params']['plan_name']);
        $this->assertSame(29.99, $event['params']['amount']);
        $this->assertSame('USD', $event['params']['currency']);
    }

    public function test_trial_converted_custom_currency(): void
    {
        $fake = new AnalyticsFake;
        $fake->trialConverted('starter', 19.00, 'EUR');

        $calls = $fake->trackedCalls();
        $this->assertSame('EUR', $calls[0]['params']['currency']);
    }

    public function test_trial_converted_with_extra_params(): void
    {
        $fake = new AnalyticsFake;
        $fake->trialConverted('pro', 49.99, 'USD', [
            'trial_days' => 14,
            'conversion_source' => 'in_app_prompt',
        ]);

        $calls = $fake->trackedCalls();
        $this->assertSame(14, $calls[0]['params']['trial_days']);
        $this->assertSame('in_app_prompt', $calls[0]['params']['conversion_source']);
    }

    public function test_trial_converted_null_params_filtered(): void
    {
        $fake = new AnalyticsFake;
        $fake->trialConverted(null, null, 'USD');

        $calls = $fake->trackedCalls();
        // plan_name and amount should be null and thus filtered out by array_filter
        $this->assertArrayNotHasKey('plan_name', $calls[0]['params']);
        $this->assertArrayNotHasKey('amount', $calls[0]['params']);
        $this->assertSame('USD', $calls[0]['params']['currency']);
    }

    public function test_trial_conversion_sequence_in_full_lifecycle(): void
    {
        $fake = new AnalyticsFake;
        $fake->signUp('email');
        $fake->trialStart('pro', 14);
        $fake->trialConverted('pro', 49.99, 'USD');
        $fake->subscription('pro', 49.99, 'USD', 'monthly');

        $fake->assertEventSequence([
            'sign_up',
            'start_trial',
            'trial_converted',
            'subscribe',
        ]);
    }

    // ── Provider Summary Completeness ───────────────────────────────────

    public function test_provider_summary_has_all_8_providers(): void
    {
        $fake = new AnalyticsFake;
        $summary = $fake->providerSummary();

        $this->assertCount(8, $summary);
        $this->assertArrayHasKey('ga4', $summary);
        $this->assertArrayHasKey('gtm', $summary);
        $this->assertArrayHasKey('meta', $summary);
        $this->assertArrayHasKey('plausible', $summary);
        $this->assertArrayHasKey('posthog', $summary);
        $this->assertArrayHasKey('webhook', $summary);
        $this->assertArrayHasKey('mixpanel', $summary);
        $this->assertArrayHasKey('amplitude', $summary);
    }

    public function test_provider_summary_structure(): void
    {
        $fake = new AnalyticsFake;
        $summary = $fake->providerSummary();

        foreach (['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook', 'mixpanel', 'amplitude'] as $provider) {
            $this->assertArrayHasKey('enabled', $summary[$provider], "Missing 'enabled' key for {$provider}");
            $this->assertArrayHasKey('id', $summary[$provider], "Missing 'id' key for {$provider}");
            $this->assertIsBool($summary[$provider]['enabled']);
        }
    }

    public function test_provider_summary_disabled_trackers_have_null_id(): void
    {
        $fake = new AnalyticsFake;
        $summary = $fake->providerSummary();

        // AnalyticsFake returns disabled tracker stubs, so all IDs should be null
        foreach ($summary as $provider => $data) {
            $this->assertFalse($data['enabled'], "{$provider} should be disabled in fake");
            $this->assertNull($data['id'], "{$provider} id should be null when disabled");
        }
    }

    // ── Lifecycle Config ────────────────────────────────────────────────

    public function test_lifecycle_config_key_documented(): void
    {
        // Verify the lifecycle config section exists in the published config
        $configPath = __DIR__ . '/../../config/zeroboiler.php';
        $this->assertFileExists($configPath);

        $config = require $configPath;
        $this->assertArrayHasKey('analytics', $config);
        $this->assertArrayHasKey('lifecycle', $config['analytics']);
    }

    public function test_lifecycle_config_has_expected_keys(): void
    {
        $configPath = __DIR__ . '/../../config/zeroboiler.php';
        $config = require $configPath;

        $lifecycle = $config['analytics']['lifecycle'];
        $this->assertArrayHasKey('enabled', $lifecycle);
        $this->assertArrayHasKey('events', $lifecycle);
        $this->assertArrayHasKey('custom_mappings', $lifecycle);
        $this->assertArrayHasKey('override_defaults', $lifecycle);
    }

    public function test_lifecycle_config_events_is_array(): void
    {
        $configPath = __DIR__ . '/../../config/zeroboiler.php';
        $config = require $configPath;

        $this->assertIsArray($config['analytics']['lifecycle']['events']);
        $this->assertIsArray($config['analytics']['lifecycle']['custom_mappings']);
        $this->assertIsBool($config['analytics']['lifecycle']['override_defaults']);
    }

    // ── Fake Reset Verification ────────────────────────────────────────

    public function test_fake_reset_clears_trial_converted(): void
    {
        $fake = new AnalyticsFake;
        $fake->trialConverted('pro', 49.99);
        $this->assertCount(1, $fake->trackedCalls());

        $fake->reset();
        $this->assertCount(0, $fake->trackedCalls());
    }

    // ── SaaS Lifecycle Shorthand Coverage ──────────────────────────────

    public function test_all_saas_shorthands_are_callable(): void
    {
        $fake = new AnalyticsFake;

        // Core lifecycle
        $fake->signUp('email');
        $fake->login('user_1', 'client_1', 'web');
        $fake->logout('web');
        $fake->trialStart('pro', 14);
        $fake->trialEnd('converted', 'pro');
        $fake->trialConverted('pro', 49.99, 'USD');
        $fake->subscription('pro', 49.99, 'USD', 'monthly');
        $fake->subscriptionRenewal('pro', 49.99, 'USD', 'monthly');
        $fake->planUpgrade('starter', 'pro', 30.00);
        $fake->planDowngrade('pro', 'starter');
        $fake->cancellation('pro', 'unused');

        // Identity
        $fake->identify('user_1', 'client_1', ['plan' => 'pro']);
        $fake->alias('client_1', 'user_1');

        // Revenue
        $fake->mrr(10000, 500);
        $fake->expansionRevenue(99.00, 'seat_expansion');

        // All events should be tracked without errors
        $calls = $fake->trackedCalls();
        $this->assertCount(15, $calls);
    }

    // ── Event Catalog Integrity ────────────────────────────────────────

    public function test_trial_converted_exists_in_saas_catalog(): void
    {
        $catalog = \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::get('trial_converted');

        $this->assertNotNull($catalog);
        $this->assertSame('trial_converted', $catalog['name']);
        $this->assertSame('Subscribe', $catalog['meta']);
        $this->assertSame('trial_converted', $catalog['posthog']);
    }
}
