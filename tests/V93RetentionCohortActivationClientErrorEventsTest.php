<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Engagement\ClientErrorEvent;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\ActivationEvent;
use ZeroBoiler\Analytics\Events\SaaS\RetentionCohortEvent;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

beforeEach(function () {
    // Catalogs are static — reset via reflection if needed
});

// ── RetentionCohortEvent ─────────────────────────────────────────────

describe('RetentionCohortEvent', function () {
    test('constructs with cohort_day and status params', function () {
        $event = new RetentionCohortEvent('D7', 'retained');

        expect($event)->toBeInstanceOf(AnalyticsEvent::class);
        expect($event->name())->toBe('retention_cohort');
        expect($event->params())->toHaveKey('cohort_day');
        expect($event->params())->toHaveKey('status');
        expect($event->params()['cohort_day'])->toBe('D7');
        expect($event->params()['status'])->toBe('retained');
    });

    test('accepts all retention statuses', function (string $status) {
        $event = new RetentionCohortEvent('D30', $status);

        expect($event->params()['status'])->toBe($status);
    })->with(['retained', 'returning', 'dormant', 'churned']);

    test('merges extra params', function () {
        $event = new RetentionCohortEvent('D1', 'churned', [
            'plan' => 'pro',
            'mrr' => 99.00,
        ]);

        expect($event->params()['plan'])->toBe('pro');
        expect($event->params()['mrr'])->toBe(99.00);
        expect($event->params()['cohort_day'])->toBe('D1');
    });

    test('accepts client ID and user ID', function () {
        $event = new RetentionCohortEvent('D7', 'retained', [], 'cli-123', 'user-456');

        expect($event->clientId())->toBe('cli-123');
        expect($event->userId())->toBe('user-456');
    });

    test('supports week and month cohort markers', function () {
        $w1 = new RetentionCohortEvent('W1', 'retained');
        $m1 = new RetentionCohortEvent('M1', 'returning');

        expect($w1->params()['cohort_day'])->toBe('W1');
        expect($m1->params()['cohort_day'])->toBe('M1');
    });
});

// ── ActivationEvent ────────────────────────────────────────────────────

describe('ActivationEvent', function () {
    test('constructs with action param', function () {
        $event = new ActivationEvent('first_project_created');

        expect($event)->toBeInstanceOf(AnalyticsEvent::class);
        expect($event->name())->toBe('activation');
        expect($event->params())->toHaveKey('action');
        expect($event->params()['action'])->toBe('first_project_created');
    });

    test('accepts time_to_activate as integer seconds', function () {
        $event = new ActivationEvent('first_api_call', 3600);

        expect($event->params()['time_to_activate'])->toBe(3600);
    });

    test('defaults time_to_activate to null', function () {
        $event = new ActivationEvent('team_invited');

        expect($event->params()['time_to_activate'])->toBeNull();
    });

    test('merges extra params', function () {
        $event = new ActivationEvent('first_dashboard_view', 120, [
            'plan' => 'starter',
            'source' => 'onboarding_flow',
        ]);

        expect($event->params()['plan'])->toBe('starter');
        expect($event->params()['source'])->toBe('onboarding_flow');
        expect($event->params()['action'])->toBe('first_dashboard_view');
    });

    test('accepts client ID and user ID', function () {
        $event = new ActivationEvent('first_value', 600, [], 'cli-abc', 'user-xyz');

        expect($event->clientId())->toBe('cli-abc');
        expect($event->userId())->toBe('user-xyz');
    });
});

// ── ClientErrorEvent ─────────────────────────────────────────────────

describe('ClientErrorEvent', function () {
    test('constructs with message and type', function () {
        $event = new ClientErrorEvent('Cannot read property of undefined', 'TypeError');

        expect($event)->toBeInstanceOf(AnalyticsEvent::class);
        expect($event->name())->toBe('client_error');
        expect($event->params()['error_message'])->toBe('Cannot read property of undefined');
        expect($event->params()['error_type'])->toBe('TypeError');
    });

    test('defaults type to Error', function () {
        $event = new ClientErrorEvent('Something broke');

        expect($event->params()['error_type'])->toBe('Error');
    });

    test('tracks unhandled flag', function () {
        $unhandled = new ClientErrorEvent('Crash', 'RuntimeError', true);
        $handled = new ClientErrorEvent('Caught error', 'TypeError', false);

        expect($unhandled->params()['unhandled'])->toBeTrue();
        expect($handled->params()['unhandled'])->toBeFalse();
    });

    test('defaults unhandled to false', function () {
        $event = new ClientErrorEvent('test');

        expect($event->params()['unhandled'])->toBeFalse();
    });

    test('merges extra params like filename and lineno', function () {
        $event = new ClientErrorEvent('syntax error', 'SyntaxError', false, [
            'filename' => '/app.js',
            'lineno' => 42,
            'colno' => 10,
            'stack' => 'Error: syntax error\n    at /app.js:42:10',
        ]);

        expect($event->params()['filename'])->toBe('/app.js');
        expect($event->params()['lineno'])->toBe(42);
        expect($event->params()['colno'])->toBe(10);
        expect($event->params()['stack'])->toBe('Error: syntax error\n    at /app.js:42:10');
    });

    test('accepts client ID and user ID', function () {
        $event = new ClientErrorEvent('err', 'Error', true, [], 'cid', 'uid');

        expect($event->clientId())->toBe('cid');
        expect($event->userId())->toBe('uid');
    });
});

// ── Catalog Integration ──────────────────────────────────────────────

describe('Catalog integration for v93.0.0 events', function () {
    test('retention_cohort is registered in SaaS catalog', function () {
        $entry = SaaSEvents::get('retention_cohort');

        expect($entry)->not->toBeNull();
        expect($entry['class'])->toBe(RetentionCohortEvent::class);
        expect($entry['ga4'])->toBe('retention_cohort');
        expect($entry['posthog'])->toBe('retention_cohort');
    });

    test('activation is registered in SaaS catalog', function () {
        $entry = SaaSEvents::get('activation');

        expect($entry)->not->toBeNull();
        expect($entry['class'])->toBe(ActivationEvent::class);
        expect($entry['meta'])->toBe('CompleteRegistration');
        expect($entry['plausible'])->toBe('activation');
    });

    test('client_error is registered in Engagement catalog', function () {
        $entry = EngagementEvents::get('client_error');

        expect($entry)->not->toBeNull();
        expect($entry['class'])->toBe(ClientErrorEvent::class);
        expect($entry['ga4'])->toBe('client_error');
        expect($entry['posthog'])->toBe('$exception');
    });

    test('SaaS catalog count increased', function () {
        // Ensure the new events are present in the catalog
        expect(SaaSEvents::has('retention_cohort'))->toBeTrue();
        expect(SaaSEvents::has('activation'))->toBeTrue();

        // Verify they appear in names()
        $names = SaaSEvents::names();
        expect(in_array('retention_cohort', $names, true))->toBeTrue();
        expect(in_array('activation', $names, true))->toBeTrue();
    });

    test('Engagement catalog count includes client_error', function () {
        expect(EngagementEvents::has('client_error'))->toBeTrue();
        $names = EngagementEvents::names();
        expect(in_array('client_error', $names, true))->toBeTrue();
    });

    test('new events have valid provider mappings', function () {
        // retention_cohort: all providers except tiktok, linkedin
        $retention = SaaSEvents::get('retention_cohort');
        expect($retention['ga4'])->not->toBeNull();
        expect($retention['meta'])->not->toBeNull();
        expect($retention['posthog'])->not->toBeNull();
        expect($retention['mixpanel'])->not->toBeNull();
        expect($retention['amplitude'])->not->toBeNull();

        // activation: includes plausible and tiktok
        $activation = SaaSEvents::get('activation');
        expect($activation['plausible'])->not->toBeNull();
        expect($activation['tiktok'])->toBe('CompleteRegistration');

        // client_error: only ga4, posthog, mixpanel, amplitude
        $clientError = EngagementEvents::get('client_error');
        expect($clientError['ga4'])->not->toBeNull();
        expect($clientError['meta'])->toBeNull();
        expect($clientError['posthog'])->not->toBeNull();
    });
});
