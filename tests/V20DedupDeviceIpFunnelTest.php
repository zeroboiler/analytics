<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Services\EventDeduplicationService;
use ZeroBoiler\Analytics\Services\DeviceContextService;
use ZeroBoiler\Analytics\Services\IpAnonymizationService;
use ZeroBoiler\Analytics\Services\SaasFunnelService;
use ZeroBoiler\Analytics\AnalyticsManager;

beforeEach(function (): void {
    // Reset singleton state between tests
    app()->forgetInstance(EventDeduplicationService::class);
    app()->forgetInstance(DeviceContextService::class);
    app()->forgetInstance(IpAnonymizationService::class);
    app()->forgetInstance(SaasFunnelService::class);
});

// ─── EventDeduplicationService ─────────────────────────────────────

describe('EventDeduplicationService', function (): void {
    it('computes deterministic fingerprints', function (): void {
        $service = new EventDeduplicationService;

        $fp1 = $service->computeFingerprint('page_view', 'client-123', 'user-456', ['key' => 'value']);
        $fp2 = $service->computeFingerprint('page_view', 'client-123', 'user-456', ['key' => 'value']);

        expect($fp1)->toBe($fp2)
            ->and($fp1)->toHaveLength(64);
    });

    it('produces different fingerprints for different events', function (): void {
        $service = new EventDeduplicationService;

        $fp1 = $service->computeFingerprint('page_view', 'client-123', 'user-456', []);
        $fp2 = $service->computeFingerprint('button_click', 'client-123', 'user-456', []);
        $fp3 = $service->computeFingerprint('page_view', 'client-789', 'user-456', []);
        $fp4 = $service->computeFingerprint('page_view', 'client-123', 'user-999', []);

        expect($fp1)->not->toBe($fp2)
            ->and($fp1)->not->toBe($fp3)
            ->and($fp1)->not->toBe($fp4);
    });

    it('is deterministic regardless of param key order', function (): void {
        $service = new EventDeduplicationService;

        $fp1 = $service->computeFingerprint('event', null, null, ['z' => 1, 'a' => 2]);
        $fp2 = $service->computeFingerprint('event', null, null, ['a' => 2, 'z' => 1]);

        expect($fp1)->toBe($fp2);
    });

    it('returns false for non-duplicate when no cache provided', function (): void {
        $service = new EventDeduplicationService;

        expect($service->isDuplicate('page_view', 'client-1', null, []))->toBeFalse();
    });

    it('returns false when disabled via config', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.validation', [])
            ->andReturn(['deduplication_window' => 10, 'max_recent_events' => 500]);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.dedup.enabled', true)
            ->andReturn(false);

        $service = new EventDeduplicationService($config, null);

        expect($service->isEnabled())->toBeFalse();
        expect($service->isDuplicate('page_view', 'client-1', null, []))->toBeFalse();
    });

    it('reports correct configuration', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.validation', [])
            ->andReturn(['deduplication_window' => 30, 'max_recent_events' => 1000]);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.dedup.enabled', true)
            ->andReturn(true);

        $service = new EventDeduplicationService($config);

        expect($service->isEnabled())->toBeTrue()
            ->and($service->getWindow())->toBe(30)
            ->and($service->getMaxRecentEvents())->toBe(1000);
    });

    it('handles null client and user IDs', function (): void {
        $service = new EventDeduplicationService;

        $fp1 = $service->computeFingerprint('page_view', null, null, []);
        $fp2 = $service->computeFingerprint('page_view', null, null, []);

        expect($fp1)->toBe($fp2);
    });
});

// ─── DeviceContextService ────────────────────────────────────────────

describe('DeviceContextService', function (): void {
    it('detects Chrome browser', function (): void {
        $service = new DeviceContextService;

        expect($service->detectBrowser('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125.0.0.0'))
            ->toBe('Chrome');
    });

    it('detects Chrome version', function (): void {
        $service = new DeviceContextService;

        expect($service->detectBrowserVersion('Mozilla/5.0 Chrome/125.0.6422.76'))
            ->toBe('125.0.6422.76');
    });

    it('detects Firefox browser', function (): void {
        $service = new DeviceContextService;

        expect($service->detectBrowser('Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15) Gecko/20100101 Firefox/128.0'))
            ->toBe('Firefox');
    });

    it('detects Safari browser (but not Chrome)', function (): void {
        $service = new DeviceContextService;

        // Safari without Chrome token
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.5 Safari/605.1.15';
        expect($service->detectBrowser($ua))->toBe('Safari');
    });

    it('detects Edge browser', function (): void {
        $service = new DeviceContextService;

        expect($service->detectBrowser('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Edg/125.0.2535.79'))
            ->toBe('Edge');
    });

    it('detects mobile device', function (): void {
        $service = new DeviceContextService;

        expect($service->isMobile('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15'))
            ->toBeTrue();
    });

    it('detects tablet device', function (): void {
        $service = new DeviceContextService;

        expect($service->isTablet('Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15'))
            ->toBeTrue();
    });

    it('detects desktop device', function (): void {
        $service = new DeviceContextService;

        expect($service->isDesktop('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125.0.0.0'))
            ->toBeTrue();
    });

    it('detects bot/crawler', function (): void {
        $service = new DeviceContextService;

        expect($service->isBot('Googlebot/2.1 (+http://www.google.com/bot.html)'))->toBeTrue();
        expect($service->isBot('facebookexternalhit/1.1'))->toBeTrue();
        expect($service->isBot('Slackbot-LinkExpanding 1.0')).toBeTrue();
    });

    it('detects Windows OS', function (): void {
        $service = new DeviceContextService;

        expect($service->detectOS('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'))->toBe('Windows');
    });

    it('detects macOS', function (): void {
        $service = new DeviceContextService;

        expect($service->detectOS('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)'))->toBe('macOS');
    });

    it('detects Android', function (): void {
        $service = new DeviceContextService;

        expect($service->detectOS('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36'))->toBe('Android');
    });

    it('detects iOS', function (): void {
        $service = new DeviceContextService;

        expect($service->detectOS('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X)'))->toBe('iOS');
    });

    it('detects OS version for Windows', function (): void {
        $service = new DeviceContextService;

        expect($service->detectOSVersion('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'))->toBe('10.0');
    });

    it('detects device brand', function (): void {
        $service = new DeviceContextService;

        expect($service->detectDeviceBrand('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5)'))->toBe('Apple');
        expect($service->detectDeviceBrand('Mozilla/5.0 (Linux; Android 14; Pixel 8)'))->toBe('Google');
        expect($service->detectDeviceBrand('Mozilla/5.0 (Linux; Android 14; SM-S921B)'))->toBe('Samsung');
    });

    it('detects device type as mobile', function (): void {
        $service = new DeviceContextService;

        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15';
        expect($service->detectDeviceType($ua))->toBe('mobile');
    });

    it('detects device type as desktop', function (): void {
        $service = new DeviceContextService;

        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125.0.0.0';
        expect($service->detectDeviceType($ua))->toBe('desktop');
    });

    it('detects device type as bot', function (): void {
        $service = new DeviceContextService;

        expect($service->detectDeviceType('Googlebot/2.1'))->toBe('bot');
    });

    it('handles empty User-Agent gracefully', function (): void {
        $service = new DeviceContextService;

        $result = $service->parseUserAgent('');

        expect($result['browser'])->toBeNull()
            ->and($result['device_type'])->toBe('unknown')
            ->and($result['is_mobile'])->toBeFalse()
            ->and($result['is_desktop'])->toBeFalse()
            ->and($result['is_bot'])->toBeFalse();
    });

    it('returns full parsed context', function (): void {
        $service = new DeviceContextService;

        $result = $service->parseUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/125.0.0.0');

        expect($result)->toHaveKeys([
            'browser', 'browser_version', 'os', 'os_version',
            'device_type', 'device_brand', 'is_mobile', 'is_tablet', 'is_desktop', 'is_bot',
        ]);
    });
});

// ─── IpAnonymizationService ────────────────────────────────────────

describe('IpAnonymizationService', function (): void {
    it('returns IP unchanged when disabled', function (): void {
        $service = new IpAnonymizationService;

        expect($service->anonymize('192.168.1.100'))->toBe('192.168.1.100');
    });

    it('anonymizes IPv4 with default mask (2 octets)', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr', [])
            ->andReturn(['anonymize_ip' => true, 'ip_mask_v4' => 2, 'ip_mask_v6' => 48]);

        $service = new IpAnonymizationService($config);

        expect($service->anonymize('192.168.1.100'))->toBe('192.168.0.0');
    });

    it('anonymizes IPv4 with mask=1', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr', [])
            ->andReturn(['anonymize_ip' => true, 'ip_mask_v4' => 1, 'ip_mask_v6' => 48]);

        $service = new IpAnonymizationService($config);

        expect($service->anonymize('192.168.1.100'))->toBe('192.0.0.0');
    });

    it('returns null for null input', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr', [])
            ->andReturn(['anonymize_ip' => true, 'ip_mask_v4' => 2, 'ip_mask_v6' => 48]);

        $service = new IpAnonymizationService($config);

        expect($service->anonymize(null))->toBeNull();
    });

    it('returns null for empty string', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr', [])
            ->andReturn(['anonymize_ip' => true, 'ip_mask_v4' => 2, 'ip_mask_v6' => 48]);

        $service = new IpAnonymizationService($config);

        expect($service->anonymize(''))->toBeNull();
    });

    it('handles IPv6 addresses', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr', [])
            ->andReturn(['anonymize_ip' => true, 'ip_mask_v4' => 2, 'ip_mask_v6' => 48]);

        $service = new IpAnonymizationService($config);

        $result = $service->anonymize('2001:0db8:85a3:0000:0000:8a2e:0370:7334');

        // Should keep first 3 groups (48 bits), mask the rest
        expect($result)->toStartWith('2001:db8:85a3');
        expect($result)->not->toBe('2001:0db8:85a3:0000:0000:8a2e:0370:7334');
    });

    it('handles ::ffff: mapped IPv4 as IPv4', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr', [])
            ->andReturn(['anonymize_ip' => true, 'ip_mask_v4' => 2, 'ip_mask_v6' => 48]);

        $service = new IpAnonymizationService($config);

        expect($service->anonymize('::ffff:192.168.1.100'))->toBe('192.168.0.0');
    });

    it('reports enabled state', function (): void {
        $service = new IpAnonymizationService;

        expect($service->isEnabled())->toBeFalse();

        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.gdpr', [])
            ->andReturn(['anonymize_ip' => true, 'ip_mask_v4' => 3, 'ip_mask_v6' => 64]);

        $enabledService = new IpAnonymizationService($config);

        expect($enabledService->isEnabled())->toBeTrue()
            ->and($enabledService->getIpv4Mask())->toBe(3)
            ->and($enabledService->getIpv6Mask())->toBe(64);
    });
});

// ─── SaasFunnelService ──────────────────────────────────────────────

describe('SaasFunnelService', function (): void {
    it('has defined funnel steps', function (): void {
        $funnels = SaasFunnelService::getFunnels();

        expect($funnels)->toHaveKey('signup_funnel')
            ->and($funnels)->toHaveKey('trial_funnel')
            ->and($funnels)->toHaveKey('conversion_funnel')
            ->and($funnels)->toHaveKey('retention_funnel')
            ->and($funnels)->toHaveKey('expansion_funnel');
    });

    it('has correct signup funnel steps', function (): void {
        $steps = SaasFunnelService::getFunnelSteps('signup_funnel');

        expect($steps)->toContain('landing_page')
            ->and($steps)->toContain('signup_view')
            ->and($steps)->toContain('signup_form_start')
            ->and($steps)->toContain('signup_form_submit')
            ->and($steps)->toContain('signup_confirm');
    });

    it('returns empty array for unknown funnel', function (): void {
        expect(SaasFunnelService::getFunnelSteps('nonexistent'))->toBe([]);
    });

    it('tracks funnel steps with disabled funnel service', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.funnels', [])
            ->andReturn(['enabled' => false]);

        $manager = Mockery::mock(AnalyticsManager::class);
        $manager->shouldNotReceive('trackEvent');

        $queue = Mockery::mock(ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);

        $service = new SaasFunnelService($manager, $queue, $config);

        expect($service->isEnabled())->toBeFalse();
        // These should be no-ops
        $service->signupLandingPage();
        $service->trialStart('pro');
        $service->pricingView();
    });

    it('tracks funnel steps when enabled', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.funnels', [])
            ->andReturn(['enabled' => true]);

        $manager = Mockery::mock(AnalyticsManager::class);
        $manager->shouldReceive('trackEvent')
            ->once()
            ->withArgs(function (ZeroBoiler\Analytics\DTO\AnalyticsEvent $event): bool {
                return $event->name === 'funnel_signup_funnel_landing_page'
                    && ($event->params['funnel'] ?? '') === 'signup_funnel'
                    && ($event->params['funnel_step'] ?? '') === 'landing_page'
                    && ($event->params['source'] ?? '') === 'organic';
            });

        $queue = Mockery::mock(ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);

        $service = new SaasFunnelService($manager, $queue, $config);
        $service->signupLandingPage('organic');
    });

    it('tracks trial funnel start step', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.funnels', [])
            ->andReturn(['enabled' => true]);

        $manager = Mockery::mock(AnalyticsManager::class);
        $manager->shouldReceive('trackEvent')
            ->once()
            ->withArgs(function (ZeroBoiler\Analytics\DTO\AnalyticsEvent $event): bool {
                return $event->name === 'funnel_trial_funnel_trial_start'
                    && ($event->params['plan'] ?? '') === 'pro'
                    && ($event->params['trial_days'] ?? 0) === 14;
            });

        $queue = Mockery::mock(ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);

        $service = new SaasFunnelService($manager, $queue, $config);
        $service->trialStart('pro', 14);
    });

    it('tracks conversion funnel checkout complete', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.funnels', [])
            ->andReturn(['enabled' => true]);

        $manager = Mockery::mock(AnalyticsManager::class);
        $manager->shouldReceive('trackEvent')
            ->once()
            ->withArgs(function (ZeroBoiler\Analytics\DTO\AnalyticsEvent $event): bool {
                return $event->name === 'funnel_conversion_funnel_checkout_complete'
                    && ($event->params['plan'] ?? '') === 'enterprise'
                    && ($event->params['value'] ?? 0) === 299.99
                    && ($event->params['currency'] ?? '') === 'USD';
            });

        $queue = Mockery::mock(ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);

        $service = new SaasFunnelService($manager, $queue, $config);
        $service->checkoutComplete('enterprise', 299.99, 'USD');
    });

    it('tracks expansion funnel upgrade complete', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.funnels', [])
            ->andReturn(['enabled' => true]);

        $manager = Mockery::mock(AnalyticsManager::class);
        $manager->shouldReceive('trackEvent')
            ->once()
            ->withArgs(function (ZeroBoiler\Analytics\DTO\AnalyticsEvent $event): bool {
                return $event->name === 'funnel_expansion_funnel_upgrade_complete'
                    && ($event->params['from_plan'] ?? '') === 'starter'
                    && ($event->params['to_plan'] ?? '') === 'pro';
            });

        $queue = Mockery::mock(ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);

        $service = new SaasFunnelService($manager, $queue, $config);
        $service->upgradeComplete('starter', 'pro', 49.99);
    });

    it('all funnel types have correct step count', function (): void {
        $funnels = SaasFunnelService::getFunnels();

        expect(count($funnels['signup_funnel']))->toBe(5)
            ->and(count($funnels['trial_funnel']))->toBe(4)
            ->and(count($funnels['conversion_funnel']))->toBe(4)
            ->and(count($funnels['retention_funnel']))->toBe(4)
            ->and(count($funnels['expansion_funnel']))->toBe(4);
    });

    it('tracks retention funnel renewal complete', function (): void {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.funnels', [])
            ->andReturn(['enabled' => true]);

        $manager = Mockery::mock(AnalyticsManager::class);
        $manager->shouldReceive('trackEvent')
            ->once()
            ->withArgs(function (ZeroBoiler\Analytics\DTO\AnalyticsEvent $event): bool {
                return $event->name === 'funnel_retention_funnel_renewal_complete'
                    && ($event->params['value'] ?? 0) === 99.0;
            });

        $queue = Mockery::mock(ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class);

        $service = new SaasFunnelService($manager, $queue, $config);
        $service->renewalComplete('pro', 99.0, 'USD');
    });
});
