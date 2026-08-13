<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsFunnelPrivacyCommand;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\DeclarativeFunnelService;
use ZeroBoiler\Analytics\Services\PrivacyCollectionService;

beforeEach(function (): void {
    Config::set('zeroboiler.analytics.funnels', [
        'enabled' => true,
        'cache_prefix' => 'zb_funnel_test_',
        'cache_ttl' => 3600,
        'definitions' => [
            'signup' => [
                'steps' => [
                    ['name' => 'visit_landing', 'event' => 'page_view'],
                    ['name' => 'start_registration', 'event' => 'form_start'],
                    ['name' => 'submit_registration', 'event' => 'sign_up'],
                    ['name' => 'verify_email', 'event' => 'email_verified'],
                ],
                'abandonment_timeout' => 3600,
            ],
            'purchase' => [
                'steps' => [
                    ['name' => 'view_product', 'event' => 'view_item'],
                    ['name' => 'add_to_cart', 'event' => 'add_to_cart'],
                    ['name' => 'checkout', 'event' => 'begin_checkout'],
                    ['name' => 'pay', 'event' => 'purchase'],
                ],
                'completion_event' => 'purchase',
            ],
        ],
    ]);

    Config::set('zeroboiler.analytics.privacy_collection', [
        'enabled' => true,
        'hash_algorithm' => 'sha256',
        'salt' => 'test-salt',
        'cache_ttl' => 3600,
        'cache_prefix' => 'zb_privacy_test_',
        'ip_anonymization' => true,
        'signals' => ['ip', 'user_agent', 'accept_language'],
        'max_entries' => 1000,
    ]);
});

// ── DeclarativeFunnelService Tests ────────────────────────────────────────

describe('DeclarativeFunnelService', function (): void {
    it('can be instantiated with config', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new DeclarativeFunnelService($manager, $queue, $cache, $config);

        expect($service->isEnabled())->toBeTrue();
        expect($service->getFunnelNames())->toBe(['signup', 'purchase']);
    });

    it('returns null for unknown funnel definition', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new DeclarativeFunnelService($manager, $queue, $cache, $config);

        expect($service->getDefinition('nonexistent'))->toBeNull();
    });

    it('returns default state when no funnel has been started', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new DeclarativeFunnelService($manager, $queue, $cache, $config);
        $state = $service->getFunnelState('signup', 'user-123');

        expect($state)->toHaveKey('completed_steps');
        expect($state['completed'])->toBeFalse();
        expect($state['abandoned'])->toBeFalse();
        expect($state['current_step'])->toBeNull();
    });

    it('advances funnel steps on matching events', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $queue->shouldReceive('dispatch')->times(3); // funnel_entered + funnel_step_completed + funnel_completed (partial)
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new DeclarativeFunnelService($manager, $queue, $cache, $config);

        // First step — enters funnel
        $event1 = new AnalyticsEvent(name: 'page_view', params: ['url' => '/landing'], clientId: 'client-1');
        $service->processEvent($event1, 'user-1');

        $state1 = $service->getFunnelState('signup', 'user-1');
        expect($state1['current_step'])->toBe('visit_landing');
        expect($state1['completed_steps'])->toContain('visit_landing');

        // Second step — advances
        $event2 = new AnalyticsEvent(name: 'form_start', params: ['form_id' => 'register'], clientId: 'client-1');
        $service->processEvent($event2, 'user-1');

        $state2 = $service->getFunnelState('signup', 'user-1');
        expect($state2['current_step'])->toBe('start_registration');
        expect($state2['completed_steps'])->toHaveCount(2);
    });

    it('does not skip steps (forward progression only)', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $queue->shouldReceive('dispatch')->once(); // funnel_entered only
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new DeclarativeFunnelService($manager, $queue, $cache, $config);

        // First step
        $event1 = new AnalyticsEvent(name: 'page_view', params: [], clientId: 'client-1');
        $service->processEvent($event1, 'user-1');

        // Try to skip to step 3 — should be rejected
        $event2 = new AnalyticsEvent(name: 'sign_up', params: [], clientId: 'client-1');
        $service->processEvent($event2, 'user-1');

        $state = $service->getFunnelState('signup', 'user-1');
        expect($state['current_step'])->toBe('visit_landing');
        expect($state['completed_steps'])->toHaveCount(1);
    });

    it('does not duplicate completed steps', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $queue->shouldReceive('dispatch')->once(); // funnel_entered
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new DeclarativeFunnelService($manager, $queue, $cache, $config);

        $event = new AnalyticsEvent(name: 'page_view', params: [], clientId: 'client-1');
        $service->processEvent($event, 'user-1');

        // Send same event again
        $service->processEvent($event, 'user-1');

        $state = $service->getFunnelState('signup', 'user-1');
        expect($state['completed_steps'])->toHaveCount(1);
    });

    it('resets funnel state on reset', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $queue->shouldReceive('dispatch')->once();
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new DeclarativeFunnelService($manager, $queue, $cache, $config);

        $event = new AnalyticsEvent(name: 'page_view', params: [], clientId: 'client-1');
        $service->processEvent($event, 'user-1');
        $service->resetFunnel('signup', 'user-1');

        $state = $service->getFunnelState('signup', 'user-1');
        expect($state['current_step'])->toBeNull();
        expect($state['completed_steps'])->toBe([]);
    });

    it('handles disabled service gracefully', function (): void {
        Config::set('zeroboiler.analytics.funnels.enabled', false);

        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new DeclarativeFunnelService($manager, $queue, $cache, $config);

        expect($service->isEnabled())->toBeFalse();
        expect($service->getFunnelNames())->toBe([]);

        // Should not process events when disabled
        $event = new AnalyticsEvent(name: 'page_view', params: [], clientId: 'client-1');
        $service->processEvent($event, 'user-1'); // Should be no-op
    });

    it('ignores events that match no funnel step', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new DeclarativeFunnelService($manager, $queue, $cache, $config);

        // Event that doesn't match any funnel step
        $event = new AnalyticsEvent(name: 'custom_action', params: [], clientId: 'client-1');
        $service->processEvent($event, 'user-1');

        $state = $service->getFunnelState('signup', 'user-1');
        expect($state['current_step'])->toBeNull();
    });

    it('returns analytics summary structure', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new DeclarativeFunnelService($manager, $queue, $cache, $config);
        $summary = $service->getAnalyticsSummary();

        expect($summary)->toHaveKey('signup');
        expect($summary)->toHaveKey('purchase');
        expect($summary['signup'])->toHaveKeys([
            'total_entries', 'completed', 'in_progress', 'abandoned',
            'step_distribution', 'conversion_rate',
        ]);
    });
});

// ── PrivacyCollectionService Tests ───────────────────────────────────────

describe('PrivacyCollectionService', function (): void {
    it('can be instantiated with config', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new PrivacyCollectionService($manager, $queue, $cache, $config);

        expect($service->isEnabled())->toBeTrue();
    });

    it('generates a stable fingerprint from the same inputs', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new PrivacyCollectionService($manager, $queue, $cache, $config);

        $fp1 = $service->generateFingerprint('192.168.1.100', 'Mozilla/5.0', ['accept_language' => 'en-US']);
        $fp2 = $service->generateFingerprint('192.168.1.100', 'Mozilla/5.0', ['accept_language' => 'en-US']);

        expect($fp1)->toBe($fp2);
        expect(strlen($fp1))->toBe(64); // SHA-256 = 64 hex chars
    });

    it('generates different fingerprints for different inputs', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new PrivacyCollectionService($manager, $queue, $cache, $config);

        $fp1 = $service->generateFingerprint('192.168.1.100', 'Mozilla/5.0', []);
        $fp2 = $service->generateFingerprint('10.0.0.1', 'Mozilla/5.0', []);
        $fp3 = $service->generateFingerprint('192.168.1.100', 'Safari/537', []);

        expect($fp1)->not->toBe($fp2);
        expect($fp1)->not->toBe($fp3);
    });

    it('anonymizes IPv4 addresses', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new PrivacyCollectionService($manager, $queue, $cache, $config);

        $fp1 = $service->generateFingerprint('192.168.1.100', 'UA', []);
        $fp2 = $service->generateFingerprint('192.168.1.200', 'UA', []);

        // Same /24 subnet should produce same fingerprint (last octet zeroed)
        expect($fp1)->toBe($fp2);
    });

    it('generates different fingerprints with different salts', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $cache = app(CacheRepository::class);

        $config1 = Mockery::mock(ConfigRepository::class);
        $config1->shouldReceive('get')
            ->with('zeroboiler.analytics.privacy_collection', [])
            ->andReturn([
                'enabled' => true,
                'hash_algorithm' => 'sha256',
                'salt' => 'salt-a',
                'cache_ttl' => 3600,
                'cache_prefix' => 'zb_test_',
                'ip_anonymization' => false,
                'signals' => ['ip', 'user_agent'],
                'max_entries' => 1000,
            ]);

        $config2 = Mockery::mock(ConfigRepository::class);
        $config2->shouldReceive('get')
            ->with('zeroboiler.analytics.privacy_collection', [])
            ->andReturn([
                'enabled' => true,
                'hash_algorithm' => 'sha256',
                'salt' => 'salt-b',
                'cache_ttl' => 3600,
                'cache_prefix' => 'zb_test_',
                'ip_anonymization' => false,
                'signals' => ['ip', 'user_agent'],
                'max_entries' => 1000,
            ]);

        $service1 = new PrivacyCollectionService($manager, $queue, $cache, $config1);
        $service2 = new PrivacyCollectionService($manager, $queue, $cache, $config2);

        $fp1 = $service1->generateFingerprint('10.0.0.1', 'UA', []);
        $fp2 = $service2->generateFingerprint('10.0.0.1', 'UA', []);

        expect($fp1)->not->toBe($fp2);
    });

    it('tracks events with fingerprint identity', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $queue->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (AnalyticsEvent $event): bool {
                return str_starts_with($event->clientId ?? '', 'fp_')
                    && $event->params['privacy_mode'] === 'cookieless';
            });
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new PrivacyCollectionService($manager, $queue, $cache, $config);

        $service->trackWithFingerprint('page_view', '10.0.0.1', 'Mozilla/5.0', [
            'url' => '/pricing',
        ]);
    });

    it('tracks page views via convenience method', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $queue->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'page_view'
                    && ($event->params['page_url'] ?? '') === '/pricing';
            });
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new PrivacyCollectionService($manager, $queue, $cache, $config);

        $service->trackPageView('/pricing', '10.0.0.1', 'Mozilla/5.0', [
            'title' => 'Pricing',
        ]);
    });

    it('resolves anonymous IDs consistently', function (): void {
        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new PrivacyCollectionService($manager, $queue, $cache, $config);

        $fp = $service->generateFingerprint('10.0.0.1', 'Mozilla/5.0', []);
        $anonId1 = $service->resolveAnonymousId($fp);
        $anonId2 = $service->resolveAnonymousId($fp);

        expect($anonId1)->toBe($anonId2);
        expect($anonId1)->toStartWith('anon_');
    });

    it('returns disabled when config is false', function (): void {
        Config::set('zeroboiler.analytics.privacy_collection.enabled', false);

        $manager = Mockery::mock(AnalyticsManager::class);
        $queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $cache = app(CacheRepository::class);
        $config = app(ConfigRepository::class);

        $service = new PrivacyCollectionService($manager, $queue, $cache, $config);

        expect($service->isEnabled())->toBeFalse();

        // Should not dispatch when disabled
        $service->trackWithFingerprint('page_view', '10.0.0.1', 'Mozilla/5.0');
    });
});

// ── AnalyticsFunnelPrivacyCommand Tests ───────────────────────────────────

describe('AnalyticsFunnelPrivacyCommand', function (): void {
    it('has correct signature', function (): void {
        $command = new AnalyticsFunnelPrivacyCommand;

        expect($command->getSignature())->toContain('zb:analytics:funnel-privacy');
    });
});
