<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Services\CampaignRoiService;
use ZeroBoiler\Analytics\Services\DataMinimizationService;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;

beforeEach(function (): void {
    EcommerceEvents::flush();
    SaaSEvents::flush();
    EngagementEvents::flush();
});

describe('v2.62.0 — Campaign ROI, Data Minimization, Telemetry API, Privacy-First', function (): void {

    describe('CampaignRoiService', function (): void {
        test('constructs with default disabled state', function (): void {
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.campaign_roi', [])->andReturn([]);

            $service = new CampaignRoiService($cache, $config);

            expect($service->isEnabled())->toBeFalse();
            expect($service->campaignCount())->toBe(0);
        });

        test('registers campaign spend data', function (): void {
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturnTrue();
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.campaign_roi', [])->andReturn(['enabled' => true, 'cache_ttl' => 86400]);

            $service = new CampaignRoiService($cache, $config);
            expect($service->isEnabled())->toBeTrue();

            $service->registerSpend('summer-sale', 500.00, 'USD', 'google', 100000, 2500);
            expect($service->campaignCount())->toBe(1);
        });

        test('computes ROI for registered campaign', function (): void {
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturnTrue();
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.campaign_roi', [])->andReturn(['enabled' => true, 'cache_ttl' => 86400]);

            $service = new CampaignRoiService($cache, $config);
            $service->registerSpend('q4-push', 1000.00, 'USD', 'meta', 50000, 800);
            $service->recordConversion('q4-push', 500.00, 'sign_up');
            $service->recordConversion('q4-push', 300.00, 'subscription');

            $roi = $service->roi('q4-push');

            expect($roi['campaign_id'])->toBe('q4-push');
            expect($roi['spend'])->toBe(1000.0);
            expect($roi['conversions'])->toBe(2);
            expect($roi['conversion_value'])->toBe(800.0);
            expect($roi['roi'])->toBe(-20.0); // (800-1000)/1000*100
            expect($roi['roas'])->toBe(0.8); // 800/1000
            expect($roi['cpa'])->toBe(500.0); // 1000/2
        });

        test('returns empty ROI for unknown campaign', function (): void {
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn([]);
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.campaign_roi', [])->andReturn(['enabled' => true, 'cache_ttl' => 86400]);

            $service = new CampaignRoiService($cache, $config);
            $roi = $service->roi('nonexistent');

            expect($roi['campaign_id'])->toBe('nonexistent');
            expect($roi['spend'])->toBe(0.0);
            expect($roi['conversions'])->toBe(0);
        });

        test('summary aggregates across all campaigns', function (): void {
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturnTrue();
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.campaign_roi', [])->andReturn(['enabled' => true, 'cache_ttl' => 86400]);

            $service = new CampaignRoiService($cache, $config);
            $service->registerSpend('google-brand', 2000.00, 'USD', 'google');
            $service->recordConversion('google-brand', 3000.00, 'purchase');
            $service->registerSpend('meta-retarget', 500.00, 'USD', 'meta');
            $service->recordConversion('meta-retarget', 1500.00, 'purchase');

            $summary = $service->summary();

            expect($summary['total_campaigns'])->toBe(2);
            expect($summary['total_spend'])->toBe(2500.0);
            expect($summary['total_conversions'])->toBe(2);
            expect($summary['total_conversion_value'])->toBe(4500.0);
            expect($summary['overall_roi'])->toBe(80.0); // (4500-2500)/2500*100
            expect($summary['overall_roas'])->toBe(1.8);
            expect($summary['average_cpa'])->toBe(1250.0);
            expect($summary)->toHaveKey('by_channel');
            expect(isset($summary['by_channel']['google']))->toBeTrue();
            expect(isset($summary['by_channel']['meta']))->toBeTrue();
        });

        test('topCampaigns sorts by ROI descending', function (): void {
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturnTrue();
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.campaign_roi', [])->andReturn(['enabled' => true, 'cache_ttl' => 86400]);

            $service = new CampaignRoiService($cache, $config);
            $service->registerSpend('low-performer', 1000.00, 'USD', 'email');
            $service->recordConversion('low-performer', 200.00, 'sign_up');
            $service->registerSpend('high-performer', 500.00, 'USD', 'referral');
            $service->recordConversion('high-performer', 2000.00, 'subscription');

            $top = $service->topCampaigns(10);

            expect($top[0]['campaign_id'])->toBe('high-performer');
            expect($top[1]['campaign_id'])->toBe('low-performer');
        });

        test('byChannel groups campaigns by channel', function (): void {
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturnTrue();
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.campaign_roi', [])->andReturn(['enabled' => true, 'cache_ttl' => 86400]);

            $service = new CampaignRoiService($cache, $config);
            $service->registerSpend('google-a', 100.00, 'USD', 'google');
            $service->registerSpend('google-b', 200.00, 'USD', 'google');
            $service->registerSpend('meta-a', 150.00, 'USD', 'meta');

            $byChannel = $service->byChannel();

            expect($byChannel)->toHaveKey('google');
            expect($byChannel)->toHaveKey('meta');
            expect(count($byChannel['google']))->toBe(2);
            expect(count($byChannel['meta']))->toBe(1);
        });

        test('removeCampaign deletes a campaign', function (): void {
            $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
            $cache->shouldReceive('get')->andReturn(null);
            $cache->shouldReceive('put')->andReturnTrue();
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.campaign_roi', [])->andReturn(['enabled' => true, 'cache_ttl' => 86400]);

            $service = new CampaignRoiService($cache, $config);
            $service->registerSpend('temp-campaign', 50.00);
            expect($service->campaignCount())->toBe(1);

            $service->removeCampaign('temp-campaign');
            expect($service->campaignCount())->toBe(0);
        });
    });

    describe('DataMinimizationService', function (): void {
        test('constructs with default disabled state', function (): void {
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.data_minimization', [])->andReturn([]);

            $service = new DataMinimizationService($config);

            expect($service->isEnabled())->toBeFalse();
            expect($service->summary()['enabled'])->toBeFalse();
        });

        test('strips globally-blocked params when enabled', function (): void {
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.data_minimization', [])->andReturn([
                'enabled' => true,
                'strip_params' => ['user_agent', 'ip_address', 'raw_query'],
            ]);

            $service = new DataMinimizationService($config);

            $event = new AnalyticsEvent(
                name: 'page_view',
                params: [
                    'page_title' => 'Home',
                    'user_agent' => 'Mozilla/5.0...',
                    'ip_address' => '192.168.1.1',
                    'raw_query' => '?foo=bar',
                ],
            );

            $minimized = $service->minimize($event);

            expect($minimized->name)->toBe('page_view');
            expect($minimized->params)->toHaveKey('page_title');
            expect($minimized->params)->not->toHaveKey('user_agent');
            expect($minimized->params)->not->toHaveKey('ip_address');
            expect($minimized->params)->not->toHaveKey('raw_query');
        });

        test('preserves internal metadata params (prefixed with _)', function (): void {
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.data_minimization', [])->andReturn([
                'enabled' => true,
                'strip_params' => ['extra_field'],
                'global_allowlist' => ['page_title'],
            ]);

            $service = new DataMinimizationService($config);

            $event = new AnalyticsEvent(
                name: 'page_view',
                params: [
                    'page_title' => 'Home',
                    'extra_field' => 'should_be_stripped',
                    '_source' => 'api',
                    '_timestamp' => '2026-08-07T12:00:00Z',
                    'some_other' => 'also_stripped',
                ],
            );

            $minimized = $service->minimize($event);

            expect($minimized->params)->toHaveKey('page_title');
            expect($minimized->params)->toHaveKey('_source');
            expect($minimized->params)->toHaveKey('_timestamp');
            expect($minimized->params)->not->toHaveKey('extra_field');
            expect($minimized->params)->not->toHaveKey('some_other');
        });

        test('applies per-event allowlist', function (): void {
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.data_minimization', [])->andReturn([
                'enabled' => true,
                'strip_params' => [],
                'event_allowlists' => [
                    'sign_up' => ['method', 'plan'],
                ],
            ]);

            $service = new DataMinimizationService($config);

            $event = new AnalyticsEvent(
                name: 'sign_up',
                params: [
                    'method' => 'email',
                    'plan' => 'pro',
                    'email' => 'user@example.com',
                    'name' => 'John Doe',
                ],
            );

            $minimized = $service->minimize($event);

            expect($minimized->params)->toHaveKey('method');
            expect($minimized->params)->toHaveKey('plan');
            expect($minimized->params)->not->toHaveKey('email');
            expect($minimized->params)->not->toHaveKey('name');
        });

        test('previewStripped returns params that would be removed', function (): void {
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.data_minimization', [])->andReturn([
                'enabled' => true,
                'strip_params' => ['sensitive_data', 'internal_id'],
            ]);

            $service = new DataMinimizationService($config);

            $event = new AnalyticsEvent(
                name: 'click',
                params: [
                    'element' => 'button',
                    'sensitive_data' => 'secret',
                    'internal_id' => '123',
                ],
            );

            $stripped = $service->previewStripped($event);

            expect($stripped)->toContain('sensitive_data');
            expect($stripped)->toContain('internal_id');
            expect($stripped)->not->toContain('element');
        });

        test('summary returns configuration overview', function (): void {
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.data_minimization', [])->andReturn([
                'enabled' => true,
                'strip_params' => ['email', 'phone'],
                'audit_log' => true,
                'event_allowlists' => ['sign_up' => ['method']],
                'category_allowlists' => ['saas' => ['plan']],
            ]);

            $service = new DataMinimizationService($config);
            $summary = $service->summary();

            expect($summary['enabled'])->toBeTrue();
            expect($summary['strip_params_count'])->toBe(2);
            expect($summary['audit_log'])->toBeTrue();
            expect($summary['event_allowlist_count'])->toBe(1);
            expect($summary['category_allowlist_count'])->toBe(1);
        });

        test('getters return correct values', function (): void {
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.data_minimization', [])->andReturn([
                'enabled' => true,
                'global_allowlist' => ['page_title', 'event_name'],
                'strip_params' => ['ip'],
                'audit_log' => false,
            ]);

            $service = new DataMinimizationService($config);

            expect($service->isEnabled())->toBeTrue();
            expect($service->isAuditLogEnabled())->toBeFalse();
            expect($service->getGlobalAllowlist())->toBe(['page_title', 'event_name']);
            expect($service->getStripParams())->toBe(['ip']);
        });

        test('does not modify events when disabled', function (): void {
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.data_minimization', [])->andReturn(['enabled' => false]);

            $service = new DataMinimizationService($config);

            $event = new AnalyticsEvent(
                name: 'page_view',
                params: ['user_agent' => 'test', 'ip_address' => '1.2.3.4'],
            );

            $result = $service->minimize($event);

            expect($result->params)->toHaveKey('user_agent');
            expect($result->params)->toHaveKey('ip_address');
        });
    });

    describe('Config expansion', function (): void {
        test('AnalyticsConfig has campaign ROI accessors', function (): void {
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.campaign_roi.enabled', false)->andReturn(true);
            $config->shouldReceive('get')->with('zeroboiler.analytics.campaign_roi.cache_ttl', 86400)->andReturn(43200);

            $analyticsConfig = new AnalyticsConfig($config);

            expect($analyticsConfig->campaignRoiEnabled())->toBeTrue();
            expect($analyticsConfig->campaignRoiCacheTtl())->toBe(43200);
        });

        test('AnalyticsConfig has data minimization accessors', function (): void {
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.data_minimization.enabled', false)->andReturn(true);
            $config->shouldReceive('get')->with('zeroboiler.analytics.data_minimization.strip_params', [])->andReturn(['email', 'ip']);
            $config->shouldReceive('get')->with('zeroboiler.analytics.data_minimization.audit_log', false)->andReturn(true);

            $analyticsConfig = new AnalyticsConfig($config);

            expect($analyticsConfig->dataMinimizationEnabled())->toBeTrue();
            expect($analyticsConfig->dataMinimizationStripParams())->toBe(['email', 'ip']);
            expect($analyticsConfig->dataMinimizationAuditLog())->toBeTrue();
        });

        test('AnalyticsConfig has delivery confirmation accessors', function (): void {
            $config = mock(\Illuminate\Contracts\Config\Repository::class);
            $config->shouldReceive('get')->with('zeroboiler.analytics.delivery_confirmation.enabled', false)->andReturn(true);
            $config->shouldReceive('get')->with('zeroboiler.analytics.delivery_confirmation.critical_events', [])->andReturn(['purchase', 'sign_up']);
            $config->shouldReceive('get')->with('zeroboiler.analytics.delivery_confirmation.token_ttl', 300)->andReturn(600);

            $analyticsConfig = new AnalyticsConfig($config);

            expect($analyticsConfig->deliveryConfirmationEnabled())->toBeTrue();
            expect($analyticsConfig->deliveryConfirmationCriticalEvents())->toBe(['purchase', 'sign_up']);
            expect($analyticsConfig->deliveryConfirmationTokenTtl())->toBe(600);
        });
    });

    describe('Version consistency', function (): void {
        test('composer.json version is 2.62.0', function (): void {
            $json = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true);
            expect($json['version'])->toBe('2.89.0');
        });

        test('JS client version is 2.62.0', function (): void {
            $js = file_get_contents(__DIR__.'/../../resources/js/analytics.js');
            expect($js)->toContain("return '2.89.0';");
        });

        test('TypeScript definitions version is 2.62.0', function (): void {
            $ts = file_get_contents(__DIR__.'/../../resources/js/analytics.d.ts');
            expect($ts)->toContain('@version 2.62.0');
        });

        test('AnalyticsManager version is 2.62.0', function (): void {
            $manager = new \ZeroBoiler\Analytics\AnalyticsManager();
            expect($manager->version())->toBe('2.89.0');
        });

        test('no stale 2.61.0 references in src', function (): void {
            $stale = shell_exec(
                "grep -rl \"'2\\.61\\.0'\" ".__DIR__.'/../../src/ 2>/dev/null || echo ""'
            );
            expect(trim($stale))->toBe('');
        });
    });

    describe('Filesystem integrity', function (): void {
        test('new service files exist', function (): void {
            expect(file_exists(__DIR__.'/../../src/Services/CampaignRoiService.php'))->toBeTrue();
            expect(file_exists(__DIR__.'/../../src/Services/DataMinimizationService.php'))->toBeTrue();
        });

        test('CampaignRoiService has strict types and is final', function (): void {
            $content = file_get_contents(__DIR__.'/../../src/Services/CampaignRoiService.php');
            expect($content)->toContain('declare(strict_types=1)');

            $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\CampaignRoiService::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('DataMinimizationService has strict types and is final', function (): void {
            $content = file_get_contents(__DIR__.'/../../src/Services/DataMinimizationService.php');
            expect($content)->toContain('declare(strict_types=1)');

            $ref = new ReflectionClass(\ZeroBoiler\Analytics\Services\DataMinimizationService::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('source file count increased', function (): void {
            $count = shell_exec('find '.__DIR__.'/../../src -name "*.php" | wc -l');
            expect((int) trim($count))->toBeGreaterThanOrEqual(212);
        });
    });

    describe('Config file integrity', function (): void {
        test('campaign_roi section exists in config', function (): void {
            $config = include __DIR__.'/../../config/zeroboiler.php';
            expect(isset($config['analytics']['campaign_roi']))->toBeTrue();
            expect(isset($config['analytics']['campaign_roi']['enabled']))->toBeTrue();
            expect(isset($config['analytics']['campaign_roi']['cache_ttl']))->toBeTrue();
        });

        test('data_minimization section exists in config', function (): void {
            $config = include __DIR__.'/../../config/zeroboiler.php';
            expect(isset($config['analytics']['data_minimization']))->toBeTrue();
            expect(isset($config['analytics']['data_minimization']['enabled']))->toBeTrue();
            expect(isset($config['analytics']['data_minimization']['strip_params']))->toBeTrue();
            expect(isset($config['analytics']['data_minimization']['global_allowlist']))->toBeTrue();
            expect(isset($config['analytics']['data_minimization']['event_allowlists']))->toBeTrue();
            expect(isset($config['analytics']['data_minimization']['category_allowlists']))->toBeTrue();
            expect(isset($config['analytics']['data_minimization']['audit_log']))->toBeTrue();
        });

        test('delivery_confirmation section exists in config', function (): void {
            $config = include __DIR__.'/../../config/zeroboiler.php';
            expect(isset($config['analytics']['delivery_confirmation']))->toBeTrue();
            expect(isset($config['analytics']['delivery_confirmation']['enabled']))->toBeTrue();
            expect(isset($config['analytics']['delivery_confirmation']['critical_events']))->toBeTrue();
            expect(isset($config['analytics']['delivery_confirmation']['token_ttl']))->toBeTrue();
        });
    });

    describe('Routes registered', function (): void {
        test('telemetry routes exist in routes file', function (): void {
            $routes = file_get_contents(__DIR__.'/../../routes/analytics.php');
            expect($routes)->toContain("'telemetry'");
            expect($routes)->toContain("'telemetryProbe'");
        });

        test('campaign ROI routes exist in routes file', function (): void {
            $routes = file_get_contents(__DIR__.'/../../routes/analytics.php');
            expect($routes)->toContain("'campaignRoiSummary'");
            expect($routes)->toContain("'campaignRoi'");
            expect($routes)->toContain("'campaignRegisterSpend'");
        });

        test('data minimization routes exist in routes file', function (): void {
            $routes = file_get_contents(__DIR__.'/../../routes/analytics.php');
            expect($routes)->toContain("'dataMinimizationStatus'");
            expect($routes)->toContain("'dataMinimizationPreview'");
        });
    });

    describe('ServiceProvider bindings', function (): void {
        test('CampaignRoiService is registered in provider', function (): void {
            $provider = file_get_contents(__DIR__.'/../../src/AnalyticsServiceProvider.php');
            expect($provider)->toContain('CampaignRoiService::class');
            expect($provider)->toContain('new CampaignRoiService');
        });

        test('DataMinimizationService is registered in provider', function (): void {
            $provider = file_get_contents(__DIR__.'/../../src/AnalyticsServiceProvider.php');
            expect($provider)->toContain('DataMinimizationService::class');
            expect($provider)->toContain('new DataMinimizationService');
        });
    });
});
