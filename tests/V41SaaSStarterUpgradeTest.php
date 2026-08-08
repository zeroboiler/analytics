<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Support\AnalyticsConfig;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Support\EventTransformer;
use ZeroBoiler\Analytics\Services\ConsentLogService;

beforeEach(function (): void {
    $this->config = mock(Illuminate\Contracts\Config\Repository::class);
});

// ── Version Consistency ─────────────────────────────────────────────

describe('v2.42.0 Version Consistency', function (): void {
    test('version is 2.42.0 in AnalyticsManager', function (): void {
        $manager = new \ZeroBoiler\Analytics\AnalyticsManager(null);
        expect($manager->version())->toBe('2.95.0');
    });

    test('version is 2.42.0 in composer.json', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($composer['version'])->toBe('2.95.0');
    });

    test('version is 2.42.0 in JS client', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain("'2.95.0'");
        expect($js)->toContain('@version 2.42.0');
    });

    test('version is 2.42.0 in TypeScript definitions', function (): void {
        $dts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
        expect($dts)->toContain('2.95.0');
    });

    test('version is 2.42.0 in controller catalog endpoint', function (): void {
        $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');
        $count = substr_count($controller, "'version' => '2.95.0'");
        expect($count)->toBeGreaterThan(0);
    });
});

// ── Account Lifecycle Events ──────────────────────────────────────────

describe('Account Lifecycle Events', function (): void {
    test('account_activated exists in SaaS catalog', function (): void {
        expect(SaaSEvents::has('account_activated'))->toBeTrue();
    });

    test('account_activated event class exists and is instantiable', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\AccountActivatedEvent('email');
        expect($event->name)->toBe('account_activated');
        expect($event->params['method'])->toBe('email');
    });

    test('account_activated event with null params', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\AccountActivatedEvent();
        expect($event->name)->toBe('account_activated');
        expect($event->params)->toBeEmpty();
    });

    test('account_deactivated exists in SaaS catalog', function (): void {
        expect(SaaSEvents::has('account_deactivated'))->toBeTrue();
    });

    test('account_deactivated event tracks reason and permanence', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\AccountDeactivatedEvent('user_request', false);
        expect($event->name)->toBe('account_deactivated');
        expect($event->params['reason'])->toBe('user_request');
        expect($event->params['permanent'])->toBeFalse();
    });

    test('password_changed exists in SaaS catalog', function (): void {
        expect(SaaSEvents::has('password_changed'))->toBeTrue();
    });

    test('password_changed event', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\PasswordChangedEvent('settings');
        expect($event->name)->toBe('password_changed');
        expect($event->params['method'])->toBe('settings');
    });

    test('password_reset exists in SaaS catalog', function (): void {
        expect(SaaSEvents::has('password_reset'))->toBeTrue();
    });

    test('password_reset event tracks success status', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\PasswordResetEvent('email', true);
        expect($event->params['success'])->toBeTrue();
    });

    test('profile_updated exists in SaaS catalog', function (): void {
        expect(SaaSEvents::has('profile_updated'))->toBeTrue();
    });

    test('profile_updated event tracks changed fields', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\ProfileUpdatedEvent(['name', 'email']);
        expect($event->params['fields_count'])->toBe(2);
    });

    test('profile_updated with empty fields produces no params', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\ProfileUpdatedEvent();
        expect($event->params)->toBeEmpty();
    });

    test('email_verified exists in SaaS catalog', function (): void {
        expect(SaaSEvents::has('email_verified'))->toBeTrue();
    });

    test('email_verified event', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\EmailVerifiedEvent('otp');
        expect($event->params['method'])->toBe('otp');
    });
});

// ── B2B / Team Events ────────────────────────────────────────────────

describe('B2B / Team Events', function (): void {
    test('team_created exists in SaaS catalog', function (): void {
        expect(SaaSEvents::has('team_created'))->toBeTrue();
    });

    test('team_created event', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\TeamCreatedEvent('Acme Corp', 5, 'pro');
        expect($event->name)->toBe('team_created');
        expect($event->params['team_name'])->toBe('Acme Corp');
        expect($event->params['member_count'])->toBe(5);
        expect($event->params['plan'])->toBe('pro');
    });

    test('team_member_joined exists in SaaS catalog', function (): void {
        expect(SaaSEvents::has('team_member_joined'))->toBeTrue();
    });

    test('team_member_joined event', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\TeamMemberJoinedEvent('admin', 'invite');
        expect($event->name)->toBe('team_member_joined');
        expect($event->params['role'])->toBe('admin');
        expect($event->params['invite_method'])->toBe('invite');
    });

    test('team_member_removed exists in SaaS catalog', function (): void {
        expect(SaaSEvents::has('team_member_removed'))->toBeTrue();
    });

    test('team_member_removed event', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\TeamMemberRemovedEvent('member', 'voluntary');
        expect($event->name)->toBe('team_member_removed');
        expect($event->params['reason'])->toBe('voluntary');
    });

    test('role_changed exists in SaaS catalog', function (): void {
        expect(SaaSEvents::has('role_changed'))->toBeTrue();
    });

    test('role_changed event tracks from/to roles', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\RoleChangedEvent('member', 'admin', 'admin');
        expect($event->params['from_role'])->toBe('member');
        expect($event->params['to_role'])->toBe('admin');
        expect($event->params['changed_by'])->toBe('admin');
    });
});

// ── Billing Events ────────────────────────────────────────────────────

describe('Billing Events', function (): void {
    test('payment_failed exists in SaaS catalog', function (): void {
        expect(SaaSEvents::has('payment_failed'))->toBeTrue();
    });

    test('payment_failed event tracks reason and amount', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\PaymentFailedEvent('card_declined', 29.99, 'USD', 'card');
        expect($event->name)->toBe('payment_failed');
        expect($event->params['reason'])->toBe('card_declined');
        expect($event->params['amount'])->toBe(29.99);
        expect($event->params['currency'])->toBe('USD');
        expect($event->params['payment_method'])->toBe('card');
    });

    test('payment_succeeded exists in SaaS catalog', function (): void {
        expect(SaaSEvents::has('payment_succeeded'))->toBeTrue();
    });

    test('payment_succeeded event', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\PaymentSucceededEvent(99.00, 'EUR', 'paypal', 'INV-001');
        expect($event->params['amount'])->toBe(99.0);
        expect($event->params['invoice_id'])->toBe('INV-001');
    });

    test('payment_method_added exists in SaaS catalog', function (): void {
        expect(SaaSEvents::has('payment_method_added'))->toBeTrue();
    });

    test('payment_method_added event', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\PaymentMethodAddedEvent('card', 'visa', true);
        expect($event->params['brand'])->toBe('visa');
        expect($event->params['is_default'])->toBeTrue();
    });

    test('invoice_generated exists in SaaS catalog', function (): void {
        expect(SaaSEvents::has('invoice_generated'))->toBeTrue();
    });

    test('invoice_generated event', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\InvoiceGeneratedEvent('INV-123', 149.99, 'USD', 'open');
        expect($event->params['status'])->toBe('open');
    });

    test('credit_applied exists in SaaS catalog', function (): void {
        expect(SaaSEvents::has('credit_applied'))->toBeTrue();
    });

    test('credit_applied event', function (): void {
        $event = new \ZeroBoiler\Analytics\Events\SaaS\CreditAppliedEvent(25.00, 'USD', 'referral', 'REF-CODE');
        expect($event->params['reason'])->toBe('referral');
        expect($event->params['source'])->toBe('REF-CODE');
    });
});

// ── PostHog Mappings ─────────────────────────────────────────────────

describe('PostHog Mapping Coverage', function (): void {
    test('all 16 new events have PostHog mappings', function (): void {
        $posthogMap = EventTransformer::saasToPosthogEventMap();
        $newEvents = [
            'account_activated', 'account_deactivated', 'password_changed', 'password_reset',
            'profile_updated', 'email_verified', 'team_created', 'team_member_joined',
            'team_member_removed', 'role_changed', 'payment_failed', 'payment_succeeded',
            'payment_method_added', 'invoice_generated', 'credit_applied',
        ];

        foreach ($newEvents as $event) {
            expect(isset($posthogMap[$event]))->toBeTrue("`{$event}` missing PostHog mapping");
        }
    });
});

// ── Event Catalog Integrity ───────────────────────────────────────────

describe('Event Catalog integrity v2.42.0', function (): void {
    test('SaaS event count increased to 35', function (): void {
        expect(SaaSEvents::count())->toBe(35);
    });

    test('total event count is at least 68', function (): void {
        expect(EventCatalog::count())->toBeGreaterThanOrEqual(68);
    });

    test('all new events have ga4 and meta entries', function (): void {
        $newEvents = [
            'account_activated', 'account_deactivated', 'password_changed', 'password_reset',
            'profile_updated', 'email_verified', 'team_created', 'team_member_joined',
            'team_member_removed', 'role_changed', 'payment_failed', 'payment_succeeded',
            'payment_method_added', 'invoice_generated', 'credit_applied',
        ];

        foreach ($newEvents as $event) {
            $entry = SaaSEvents::get($event);
            expect($entry)->not->toBeNull("`{$event}` not in catalog");
            expect($entry['ga4'])->toBe($event);
            expect($entry['meta'])->not->toBeNull("`{$event}` missing Meta mapping");
        }
    });
});

// ── ConsentLogService ─────────────────────────────────────────────────

describe('ConsentLogService', function (): void {
    test('availablePurposes returns 4 GDPR purposes', function (): void {
        $purposes = ConsentLogService::availablePurposes();
        expect($purposes)->toHaveKeys(['necessary', 'analytics', 'marketing', 'functional']);
    });

    test('defaultConsentState grants necessary always', function (): void {
        $state = ConsentLogService::defaultConsentState(false);
        expect($state['necessary'])->toBeTrue();
        expect($state['analytics'])->toBeFalse();
        expect($state['marketing'])->toBeFalse();
    });

    test('defaultConsentState grants all when defaultGranted is true', function (): void {
        $state = ConsentLogService::defaultConsentState(true);
        expect($state['necessary'])->toBeTrue();
        expect($state['analytics'])->toBeTrue();
        expect($state['marketing'])->toBeTrue();
        expect($state['functional'])->toBeTrue();
    });

    test('getCurrentConsent returns empty for unknown identifier', function (): void {
        $cache = mock(Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturn(null);
        $service = new ConsentLogService($cache);

        $current = $service->getCurrentConsent('unknown-user');
        expect($current['purposes'])->toBeEmpty();
        expect($current['source'])->toBeNull();
        expect($current['updated_at'])->toBeNull();
    });
});

// ── Config Expansion ──────────────────────────────────────────────────

describe('v2.41.0 Config Expansion', function (): void {
    test('consent.purposes config exists', function (): void {
        $configArray = require __DIR__ . '/../config/zeroboiler.php';
        expect(isset($configArray['analytics']['consent']['purposes']))->toBeTrue();
        expect(isset($configArray['analytics']['consent']['purposes']['necessary']))->toBeTrue();
        expect(isset($configArray['analytics']['consent']['purposes']['analytics']))->toBeTrue();
        expect(isset($configArray['analytics']['consent']['purposes']['marketing']))->toBeTrue();
        expect(isset($configArray['analytics']['consent']['purposes']['functional']))->toBeTrue();
    });

    test('consent.log_enabled config exists', function (): void {
        $configArray = require __DIR__ . '/../config/zeroboiler.php';
        expect(isset($configArray['analytics']['consent']['log_enabled']))->toBeTrue();
        expect($configArray['analytics']['consent']['log_enabled'])->toBeFalse();
    });

    test('consent.log_ttl config exists with 90 day default', function (): void {
        $configArray = require __DIR__ . '/../config/zeroboiler.php';
        expect(isset($configArray['analytics']['consent']['log_ttl']))->toBeTrue();
        expect($configArray['analytics']['consent']['log_ttl'])->toBe(7776000);
    });

    test('necessary purpose is required and defaulted to true', function (): void {
        $configArray = require __DIR__ . '/../config/zeroboiler.php';
        expect($configArray['analytics']['consent']['purposes']['necessary']['required'])->toBeTrue();
        expect($configArray['analytics']['consent']['purposes']['necessary']['default'])->toBeTrue();
    });

    test('marketing purpose defaults to false', function (): void {
        $configArray = require __DIR__ . '/../config/zeroboiler.php';
        expect($configArray['analytics']['consent']['purposes']['marketing']['default'])->toBeFalse();
    });
});

// ── AnalyticsConfig v2.41.0 Accessors ──────────────────────────────────

describe('AnalyticsConfig v2.41.0 accessors', function (): void {
    test('consentPurposes returns array', function (): void {
        $config = new AnalyticsConfig($this->config);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent.purposes', [])
            ->andReturn([
                'necessary' => ['label' => 'Necessary', 'required' => true, 'default' => true],
                'analytics' => ['label' => 'Analytics', 'required' => false, 'default' => true],
            ]);

        $purposes = $config->consentPurposes();
        expect($purposes)->toHaveCount(2);
        expect($purposes['necessary']['required'])->toBeTrue();
    });

    test('consentLogEnabled returns bool', function (): void {
        $config = new AnalyticsConfig($this->config);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent.log_enabled', false)
            ->andReturn(true);

        expect($config->consentLogEnabled())->toBeTrue();
    });

    test('consentLogTtl returns int', function (): void {
        $config = new AnalyticsConfig($this->config);
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent.log_ttl', 7776000)
            ->andReturn(2592000);

        expect($config->consentLogTtl())->toBe(2592000);
    });
});

// ── Inertia Middleware Consent Purposes ──────────────────────────────

describe('Inertia middleware consent purposes exposure', function (): void {
    test('Inertia middleware includes consentPurposes prop logic', function (): void {
        $middleware = file_get_contents(__DIR__ . '/../src/Inertia/HandleInertiaAnalytics.php');
        expect($middleware)->toContain('consentPurposes');
        expect($middleware)->toContain('consent.purposes');
    });
});
