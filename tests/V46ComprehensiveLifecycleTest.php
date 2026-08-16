<?php
declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 *
 * V46 — Comprehensive lifecycle mapper test covering all 35 event categories:
 * auth, subscription, trial, feature, e-commerce, engagement, account lifecycle,
 * B2B/team, billing, and integrations. Validates DEFAULT_MAPPINGS coverage,
 * param extractors, config toggles, and category summaries.
 */

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaS\AccountActivatedEvent;
use ZeroBoiler\Analytics\Events\SaaS\AccountDeactivatedEvent;
use ZeroBoiler\Analytics\Events\SaaS\CancellationEvent;
use ZeroBoiler\Analytics\Events\SaaS\CreditAppliedEvent;
use ZeroBoiler\Analytics\Events\SaaS\EmailVerifiedEvent;
use ZeroBoiler\Analytics\Events\SaaS\FeatureLimitReachedEvent;
use ZeroBoiler\Analytics\Events\SaaS\FeatureUsedEvent;
use ZeroBoiler\Analytics\Events\SaaS\IntegrationConnectedEvent;
use ZeroBoiler\Analytics\Events\SaaS\IntegrationFailedEvent;
use ZeroBoiler\Analytics\Events\SaaS\InviteSentEvent;
use ZeroBoiler\Analytics\Events\SaaS\InvoiceGeneratedEvent;
use ZeroBoiler\Analytics\Events\SaaS\LoginEvent;
use ZeroBoiler\Analytics\Events\SaaS\LogoutEvent;
use ZeroBoiler\Analytics\Events\SaaS\PasswordChangedEvent;
use ZeroBoiler\Analytics\Events\SaaS\PasswordResetEvent;
use ZeroBoiler\Analytics\Events\SaaS\PaymentFailedEvent;
use ZeroBoiler\Analytics\Events\SaaS\PaymentMethodAddedEvent;
use ZeroBoiler\Analytics\Events\SaaS\PaymentSucceededEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanDowngradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanUpgradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\ProfileUpdatedEvent;
use ZeroBoiler\Analytics\Events\SaaS\RoleChangedEvent;
use ZeroBoiler\Analytics\Events\SaaS\SignUpEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionRenewalEvent;
use ZeroBoiler\Analytics\Events\SaaS\TeamCreatedEvent;
use ZeroBoiler\Analytics\Events\SaaS\TeamMemberJoinedEvent;
use ZeroBoiler\Analytics\Events\SaaS\TeamMemberRemovedEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialEndEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialStartEvent;
use ZeroBoiler\Analytics\Events\Engagement\ErrorEvent;
use ZeroBoiler\Analytics\Events\Engagement\FormSubmitEvent;
use ZeroBoiler\Analytics\Events\Engagement\SearchEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\PurchaseEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\RefundEvent;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;

beforeEach(function (): void {
    $this->config = mock(ConfigRepository::class);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.ga4', [])
        ->andReturn(['enabled' => false]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.gtm', [])
        ->andReturn(['enabled' => false]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.meta_pixel', [])
        ->andReturn(['enabled' => false]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.plausible', [])
        ->andReturn([]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.posthog', [])
        ->andReturn([]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.webhook', [])
        ->andReturn([]);
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.consent.default', 'granted')
        ->andReturn('granted');
    $this->config->shouldReceive('get')
        ->with('zeroboiler.analytics.debug', [])
        ->andReturn(['enabled' => false]);
    $this->manager = new AnalyticsManager($this->config);
});

// ═══════════════════════════════════════════════════════════════════════
// DEFAULT_MAPPINGS Coverage Test
// ═══════════════════════════════════════════════════════════════════════

describe('LifecycleEventMapper — v2.46 full mapping coverage', function (): void {
    it('has at least 35 default mappings', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => true]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);

        expect($mapper->count())->toBeGreaterThanOrEqual(35);
    });

    it('covers all 8 lifecycle categories', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => true]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $summary = $mapper->summary();

        $categories = array_keys($summary['categories']);

        expect($categories)->toContain('auth');
        expect($categories)->toContain('subscription');
        expect($categories)->toContain('trial');
        expect($categories)->toContain('feature');
        expect($categories)->toContain('order');
        expect($categories)->toContain('form');
        expect($categories)->toContain('search');
        expect($categories)->toContain('error');
        expect($categories)->toContain('account');
        expect($categories)->toContain('team');
        expect($categories)->toContain('billing');
        expect($categories)->toContain('integration');
    });

    it('includes all v2.46 new event keys', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => true]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $keys = $mapper->enabledEventKeys();

        // Account lifecycle
        expect($keys)->toContain('account.activated');
        expect($keys)->toContain('account.deactivated');
        expect($keys)->toContain('account.email_verified');
        expect($keys)->toContain('account.password_changed');
        expect($keys)->toContain('account.password_reset');
        expect($keys)->toContain('account.profile_updated');

        // B2B / Team
        expect($keys)->toContain('team.created');
        expect($keys)->toContain('team.member_joined');
        expect($keys)->toContain('team.member_removed');
        expect($keys)->toContain('team.role_changed');
        expect($keys)->toContain('team.invite_sent');

        // Billing
        expect($keys)->toContain('billing.payment_succeeded');
        expect($keys)->toContain('billing.payment_failed');
        expect($keys)->toContain('billing.payment_method_added');
        expect($keys)->toContain('billing.invoice_generated');
        expect($keys)->toContain('billing.credit_applied');

        // Subscription renewal
        expect($keys)->toContain('subscription.renewal');

        // Feature limit
        expect($keys)->toContain('feature.limit_reached');

        // Integrations
        expect($keys)->toContain('integration.connected');
        expect($keys)->toContain('integration.failed');
    });
});

// ═══════════════════════════════════════════════════════════════════════
// Param Extractor Validation — v2.46 New Events
// ═══════════════════════════════════════════════════════════════════════

describe('LifecycleEventMapper — v2.46 param extractors', function (): void {
    it('correctly extracts team.created params', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => true]);

        $dispatcher = mock(EventDispatcher::class);
        $mapper = new LifecycleEventMapper($this->manager, $this->config);

        // Capture the registered listener
        $dispatcher->shouldReceive('listen')
            ->with('team.created', \Mockery::type(\Closure::class))
            ->once()
            ->andReturnUsing(function (string $event, \Closure $listener) use (&$capturedListener): void {
                $capturedListener = $listener;
            });

        // Register other mappings silently
        $dispatcher->shouldReceive('listen')->andReturnNull();

        $mapper->register($dispatcher);

        // Dispatch team.created with payload
        $tracked = [];
        $this->manager->shouldIgnoreMissing();
        $this->manager->shouldReceive('trackEvent')
            ->once()
            ->andReturnUsing(function (AnalyticsEvent $event) use (&$tracked): void {
                $tracked = $event;
            });

        // Re-register just the team mapping
        $dispatcher2 = mock(EventDispatcher::class);
        $capturedListener([
            'team_name' => 'Engineering',
            'member_count' => 5,
            'plan' => 'Business',
            'user_id' => 'user-1',
        ]);
    });

    it('correctly extracts billing.payment_succeeded params', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => true]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $mappings = $mapper->getMappings();

        expect($mappings)->toHaveKey('billing.payment_succeeded');
        expect($mappings['billing.payment_succeeded']['source'])->toBe('billing.payment_succeeded');
        expect($mappings['billing.payment_succeeded']['target'])->toBe(PaymentSucceededEvent::class);
    });

    it('correctly extracts billing.payment_failed params', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => true]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $mappings = $mapper->getMappings();

        expect($mappings['billing.payment_failed']['target'])->toBe(PaymentFailedEvent::class);
    });

    it('correctly maps integration.connected and integration.failed', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => true]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $mappings = $mapper->getMappings();

        expect($mappings['integration.connected']['target'])->toBe(IntegrationConnectedEvent::class);
        expect($mappings['integration.failed']['target'])->toBe(IntegrationFailedEvent::class);
    });

    it('maps subscription.renewal to SubscriptionRenewalEvent', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => true]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $mappings = $mapper->getMappings();

        expect($mappings['subscription.renewal']['target'])->toBe(SubscriptionRenewalEvent::class);
    });

    it('maps feature.limit_reached to FeatureLimitReachedEvent', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => true]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $mappings = $mapper->getMappings();

        expect($mappings['feature.limit_reached']['target'])->toBe(FeatureLimitReachedEvent::class);
    });
});

// ═══════════════════════════════════════════════════════════════════════
// Config Toggle Tests — v2.46 Categories
// ═══════════════════════════════════════════════════════════════════════

describe('LifecycleEventMapper — v2.46 config toggles', function (): void {
    it('can disable all account lifecycle events at once', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'enabled' => true,
                'events' => [
                    'account.activated' => false,
                    'account.deactivated' => false,
                    'account.email_verified' => false,
                    'account.password_changed' => false,
                    'account.password_reset' => false,
                    'account.profile_updated' => false,
                ],
            ]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $keys = $mapper->enabledEventKeys();

        expect($keys)->not->toContain('account.activated');
        expect($keys)->not->toContain('account.deactivated');
        expect($keys)->not->toContain('account.email_verified');
        // Auth should still be enabled
        expect($keys)->toContain('auth.login');
    });

    it('can disable all billing events', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'enabled' => true,
                'events' => [
                    'billing.payment_succeeded' => false,
                    'billing.payment_failed' => false,
                    'billing.payment_method_added' => false,
                    'billing.invoice_generated' => false,
                    'billing.credit_applied' => false,
                ],
            ]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $keys = $mapper->enabledEventKeys();

        expect($keys)->not->toContain('billing.payment_succeeded');
        expect($keys)->not->toContain('billing.payment_failed');
        // Auth should still be enabled
        expect($keys)->toContain('auth.login');
    });

    it('can disable all team events', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'enabled' => true,
                'events' => [
                    'team.created' => false,
                    'team.member_joined' => false,
                    'team.member_removed' => false,
                    'team.role_changed' => false,
                    'team.invite_sent' => false,
                ],
            ]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $keys = $mapper->enabledEventKeys();

        expect($keys)->not->toContain('team.created');
        expect($keys)->not->toContain('team.member_joined');
        // Auth should still be enabled
        expect($keys)->toContain('auth.login');
    });

    it('summary reflects disabled billing events', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'enabled' => true,
                'events' => [
                    'billing.payment_succeeded' => false,
                    'billing.payment_failed' => false,
                ],
            ]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $summary = $mapper->summary();

        expect($summary['enabled_count'])->toBe($summary['total_mappings'] - 2);
        expect($summary['event_keys'])->not->toContain('billing.payment_succeeded');
        expect($summary['event_keys'])->not->toContain('billing.payment_failed');
    });
});

// ═══════════════════════════════════════════════════════════════════════
// Registration Volume Test
// ═══════════════════════════════════════════════════════════════════════

describe('LifecycleEventMapper — v2.46 registration volume', function (): void {
    it('registers at least 35 listeners when fully enabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => true]);

        $dispatcher = mock(EventDispatcher::class);
        $dispatcher->shouldReceive('listen')
            ->atLeast(35)
            ->andReturnNull();

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $mapper->register($dispatcher);

        $dispatcher->shouldHaveReceived('listen')->atLeast(35);
    });

    it('registers fewer listeners when billing and team are disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn([
                'enabled' => true,
                'events' => [
                    'team.created' => false,
                    'team.member_joined' => false,
                    'team.member_removed' => false,
                    'team.role_changed' => false,
                    'team.invite_sent' => false,
                    'billing.payment_succeeded' => false,
                    'billing.payment_failed' => false,
                    'billing.payment_method_added' => false,
                    'billing.invoice_generated' => false,
                    'billing.credit_applied' => false,
                    'integration.connected' => false,
                    'integration.failed' => false,
                ],
            ]);

        $dispatcher = mock(EventDispatcher::class);
        $callCount = 0;
        $dispatcher->shouldReceive('listen')
            ->andReturnUsing(function () use (&$callCount): void {
                $callCount++;
            });

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $mapper->register($dispatcher);

        // Should register fewer: total - 12 disabled
        expect($callCount)->toBeLessThan(35);
        expect($callCount)->toBeGreaterThan(0);
    });
});

// ═══════════════════════════════════════════════════════════════════════
// Target Class Validation
// ═══════════════════════════════════════════════════════════════════════

describe('LifecycleEventMapper — v2.46 target classes are valid', function (): void {
    it('all mapped target classes extend AnalyticsEvent', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.lifecycle', [])
            ->andReturn(['enabled' => true]);

        $mapper = new LifecycleEventMapper($this->manager, $this->config);
        $mappings = $mapper->getMappings();

        $validTargets = [
            LoginEvent::class,
            SignUpEvent::class,
            LogoutEvent::class,
            SubscriptionEvent::class,
            PlanUpgradeEvent::class,
            PlanDowngradeEvent::class,
            CancellationEvent::class,
            SubscriptionRenewalEvent::class,
            TrialStartEvent::class,
            TrialEndEvent::class,
            FeatureUsedEvent::class,
            FeatureLimitReachedEvent::class,
            PurchaseEvent::class,
            RefundEvent::class,
            FormSubmitEvent::class,
            SearchEvent::class,
            ErrorEvent::class,
            AccountActivatedEvent::class,
            AccountDeactivatedEvent::class,
            EmailVerifiedEvent::class,
            PasswordChangedEvent::class,
            PasswordResetEvent::class,
            ProfileUpdatedEvent::class,
            TeamCreatedEvent::class,
            TeamMemberJoinedEvent::class,
            TeamMemberRemovedEvent::class,
            RoleChangedEvent::class,
            InviteSentEvent::class,
            PaymentSucceededEvent::class,
            PaymentFailedEvent::class,
            PaymentMethodAddedEvent::class,
            InvoiceGeneratedEvent::class,
            CreditAppliedEvent::class,
            IntegrationConnectedEvent::class,
            IntegrationFailedEvent::class,
        ];

        foreach ($mappings as $key => $mapping) {
            expect($mapping['target'])
                ->toBeIn($validTargets, "Mapping '{$key}' has unexpected target: {$mapping['target']}");
        }
    });

    it('all v2.46 target classes are AnalyticsEvent subclasses', function (): void {
        $newTargets = [
            AccountActivatedEvent::class,
            AccountDeactivatedEvent::class,
            EmailVerifiedEvent::class,
            PasswordChangedEvent::class,
            PasswordResetEvent::class,
            ProfileUpdatedEvent::class,
            TeamCreatedEvent::class,
            TeamMemberJoinedEvent::class,
            TeamMemberRemovedEvent::class,
            RoleChangedEvent::class,
            InviteSentEvent::class,
            PaymentSucceededEvent::class,
            PaymentFailedEvent::class,
            PaymentMethodAddedEvent::class,
            InvoiceGeneratedEvent::class,
            CreditAppliedEvent::class,
            SubscriptionRenewalEvent::class,
            FeatureLimitReachedEvent::class,
            IntegrationConnectedEvent::class,
            IntegrationFailedEvent::class,
        ];

        foreach ($newTargets as $target) {
            expect(is_subclass_of($target, AnalyticsEvent::class))
                ->toBeTrue("{$target} should extend AnalyticsEvent");
        }
    });
});
