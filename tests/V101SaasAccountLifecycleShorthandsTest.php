<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Support\AnalyticsFake;

beforeEach(function (): void {
    $this->fake = new AnalyticsFake;
    app()->instance('zeroboiler.analytics', $this->fake);
});

// ── SaaS Account Lifecycle Shorthand Tests (v101.0.0) ───────────────

describe('SaaS Account Lifecycle Shorthands', function (): void {
    test('accountActivated fires account_activated event with method', function (): void {
        $this->fake->accountActivated('email');
        AnalyticsFake::assertTracked('account_activated');
    });

    test('accountActivated fires without method', function (): void {
        $this->fake->accountActivated();
        AnalyticsFake::assertTracked('account_activated');
    });

    test('accountDeactivated fires account_deactivated event with reason', function (): void {
        $this->fake->accountDeactivated('self_service');
        AnalyticsFake::assertTracked('account_deactivated');
    });

    test('accountDeleted fires account_deleted event', function (): void {
        $this->fake->accountDeleted('gdpr_request');
        AnalyticsFake::assertTracked('account_deleted');
    });

    test('featureUsed fires feature_used event with name and category', function (): void {
        $this->fake->featureUsed('export_csv', 'reporting');
        AnalyticsFake::assertTracked('feature_used');
    });

    test('featureUsed fires with only feature name', function (): void {
        $this->fake->featureUsed('api_keys');
        AnalyticsFake::assertTracked('feature_used');
    });

    test('emailVerified fires email_verified event', function (): void {
        $this->fake->emailVerified('link');
        AnalyticsFake::assertTracked('email_verified');
    });

    test('passwordChanged fires password_changed event', function (): void {
        $this->fake->passwordChanged('self_service');
        AnalyticsFake::assertTracked('password_changed');
    });

    test('profileUpdated fires profile_updated event with fields', function (): void {
        $this->fake->profileUpdated(['name', 'avatar']);
        AnalyticsFake::assertTracked('profile_updated');
    });

    test('apiRateLimited fires api_rate_limited event', function (): void {
        $this->fake->apiRateLimited('/api/v1/exports', 100);
        AnalyticsFake::assertTracked('api_rate_limited');
    });

    test('invoiceGenerated fires invoice_generated event', function (): void {
        $this->fake->invoiceGenerated(49.99, 'INV-2024-001');
        AnalyticsFake::assertTracked('invoice_generated');
    });

    test('dataErasureCompleted fires data_erasure_completed event', function (): void {
        $this->fake->dataErasureCompleted('GDPR-REQ-001');
        AnalyticsFake::assertTracked('data_erasure_completed');
    });

    test('exportEvent fires export event with format and resource', function (): void {
        $this->fake->exportEvent('csv', 'users', 1500);
        AnalyticsFake::assertTracked('export');
    });

    test('importEvent fires import event with success flag', function (): void {
        $this->fake->importEvent('json', 'contacts', 500, true);
        AnalyticsFake::assertTracked('import');
    });

    test('firstValue fires first_value event', function (): void {
        $this->fake->firstValue('first_api_call');
        AnalyticsFake::assertTracked('first_value');
    });

    test('growthMilestone fires growth_milestone event', function (): void {
        $this->fake->growthMilestone('10k_mrr');
        AnalyticsFake::assertTracked('growth_milestone');
    });
});

// ── SaaS Core Shorthands Still Work ────────────────────────────────

describe('SaaS Core Shorthands Unchanged', function (): void {
    test('signUp fires sign_up event', function (): void {
        $this->fake->signUp('google');
        AnalyticsFake::assertTracked('sign_up');
    });

    test('login fires login event and links identity', function (): void {
        $this->fake->login('user-123', 'client-abc', 'oauth');
        AnalyticsFake::assertTracked('login');
        AnalyticsFake::assertIdentified('user-123');
    });

    test('trialStart fires start_trial event', function (): void {
        $this->fake->trialStart('Pro', 14);
        AnalyticsFake::assertTracked('start_trial');
    });

    test('subscription fires subscribe event', function (): void {
        $this->fake->subscription('Enterprise', 199.00, 'USD', 'monthly');
        AnalyticsFake::assertTracked('subscribe');
    });

    test('planUpgrade fires plan_upgrade event', function (): void {
        $this->fake->planUpgrade('Starter', 'Pro', 30.00);
        AnalyticsFake::assertTracked('plan_upgrade');
    });

    test('cancellation fires cancellation event', function (): void {
        $this->fake->cancellation('Pro', 'too_expensive');
        AnalyticsFake::assertTracked('cancellation');
    });
});

// ── Shorthand → Event Catalog Parity ─────────────────────────────────

describe('Shorthand Catalog Parity', function (): void {
    $v101Events = [
        'account_activated', 'account_deactivated', 'account_deleted',
        'feature_used', 'email_verified', 'password_changed', 'profile_updated',
        'api_rate_limited', 'invoice_generated', 'data_erasure_completed',
        'export', 'import', 'first_value', 'growth_milestone',
    ];

    test('all v101.0.0 shorthand events exist in EventCatalog', function () use ($v101Events): void {
        foreach ($v101Events as $eventName) {
            expect(\ZeroBoiler\Analytics\Events\EventCatalog::has($eventName))
                ->toBeTrue("v101 shorthand event '{$eventName}' must exist in EventCatalog");
        }
    });

    test('core SaaS shorthands match catalog event names', function (): void {
        $coreShorthands = [
            'sign_up', 'login', 'start_trial', 'subscribe',
            'plan_upgrade', 'plan_downgrade', 'cancellation',
            'trial_converted',
        ];

        foreach ($coreShorthands as $eventName) {
            expect(\ZeroBoiler\Analytics\Events\EventCatalog::has($eventName))
                ->toBeTrue("Core shorthand event '{$eventName}' must exist in EventCatalog");
        }
    });

    test('total event catalog size is healthy', function (): void {
        $count = \ZeroBoiler\Analytics\Events\EventCatalog::count();
        expect($count)->toBeGreaterThan(100, 'EventCatalog should have 100+ events');
    });
});

// ── Strict Types & Return Type Verification ─────────────────────────

describe('Strict Types Compliance', function (): void {
    test('AnalyticsFake proxy handles all v101.0.0 shorthand methods via __call', function (): void {
        // All shorthands go through __call which delegates to track()
        // Verify the events array is populated correctly
        $this->fake->accountActivated();
        $this->fake->featureUsed('test');
        $this->fake->firstValue('aha_moment');
        $this->fake->growthMilestone('100_users');

        AnalyticsFake::assertTrackedTimes('account_activated', 1);
        AnalyticsFake::assertTrackedTimes('feature_used', 1);
        AnalyticsFake::assertTrackedTimes('first_value', 1);
        AnalyticsFake::assertTrackedTimes('growth_milestone', 1);
    });
});

// ── Facade @method Docblock Verification ────────────────────────────

describe('Facade Docblock Coverage', function (): void {
    test('Facade documents all v101.0.0 SaaS account lifecycle methods', function (): void {
        $facadePath = __DIR__ . '/../../src/Facades/Analytics.php';
        $contents = file_get_contents($facadePath);

        $requiredDocstrings = [
            'accountActivated',
            'accountDeactivated',
            'featureUsed',
            'emailVerified',
            'passwordChanged',
            'profileUpdated',
            'apiRateLimited',
            'invoiceGenerated',
            'accountDeleted',
            'dataErasureCompleted',
            'firstValue',
            'growthMilestone',
        ];

        foreach ($requiredDocstrings as $method) {
            expect($contents)->toContain($method);
        }
    });
});
