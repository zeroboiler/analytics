<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Security\DataAccessAuditEvent;
use ZeroBoiler\Analytics\Events\Security\LoginAttemptEvent;
use ZeroBoiler\Analytics\Events\Security\MfaChallengeEvent;
use ZeroBoiler\Analytics\Events\Security\RateLimitExceededEvent;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Security\SuspiciousActivityEvent;
use ZeroBoiler\Analytics\Events\Uptime\ApiLatencyEvent;
use ZeroBoiler\Analytics\Events\Uptime\DeploymentEvent;
use ZeroBoiler\Analytics\Events\Uptime\ErrorSpikeEvent;
use ZeroBoiler\Analytics\Events\Uptime\ServiceDownEvent;
use ZeroBoiler\Analytics\Events\Uptime\ServiceUpEvent;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;

describe('Security Events', function () {
    it('has all 5 security events in the catalog', function () {
        expect(SecurityEvents::count())->toBe(5);
        expect(SecurityEvents::names())->toBe([
            'login_attempt',
            'suspicious_activity',
            'data_access_audit',
            'rate_limit_exceeded',
            'mfa_challenge',
        ]);
    });

    it('provides correct event entries with all required keys', function () {
        $entry = SecurityEvents::get('login_attempt');

        expect($entry)->not->toBeNull();
        expect($entry['name'])->toBe('login_attempt');
        expect($entry['class'])->toBe(LoginAttemptEvent::class);
        expect($entry['ga4'])->toBe('login_attempt');
        expect($entry['category'])->toBeNull(); // Category not set in sub-catalog
        expect(isset($entry['posthog']))->toBeTrue();
        expect(isset($entry['plausible']))->toBeTrue();
    });

    it('LoginAttemptEvent builds with correct params', function () {
        $event = new LoginAttemptEvent(
            method: 'oauth',
            successful: false,
            reason: 'invalid_credentials',
        );

        expect($event->name)->toBe('login_attempt');
        expect($event->params['method'])->toBe('oauth');
        expect($event->params['successful'])->toBeFalse();
        expect($event->params['reason'])->toBe('invalid_credentials');
    });

    it('SuspiciousActivityEvent builds with severity', function () {
        $event = new SuspiciousActivityEvent(
            type: 'brute_force',
            severity: 'critical',
        );

        expect($event->name)->toBe('suspicious_activity');
        expect($event->params['type'])->toBe('brute_force');
        expect($event->params['severity'])->toBe('critical');
    });

    it('DataAccessAuditEvent builds with resource and action', function () {
        $event = new DataAccessAuditEvent(
            resource: 'financial_records',
            action: 'export',
            actorId: 'user_123',
            targetId: 'user_456',
        );

        expect($event->name)->toBe('data_access_audit');
        expect($event->params['resource'])->toBe('financial_records');
        expect($event->params['action'])->toBe('export');
        expect($event->params['actor_id'])->toBe('user_123');
        expect($event->params['target_id'])->toBe('user_456');
    });

    it('RateLimitExceededEvent builds with limit details', function () {
        $event = new RateLimitExceededEvent(
            endpoint: '/api/events',
            clientId: '192.168.1.1',
            limit: 120,
            window: 60,
        );

        expect($event->name)->toBe('rate_limit_exceeded');
        expect($event->params['endpoint'])->toBe('/api/events');
        expect($event->params['limit'])->toBe(120);
        expect($event->params['window'])->toBe(60);
    });

    it('MfaChallengeEvent builds with method and outcome', function () {
        $event = new MfaChallengeEvent(
            method: 'totp',
            outcome: 'completed',
        );

        expect($event->name)->toBe('mfa_challenge');
        expect($event->params['method'])->toBe('totp');
        expect($event->params['outcome'])->toBe('completed');
    });

    it('all security events extend AnalyticsEvent', function () {
        foreach (SecurityEvents::all() as $entry) {
            $event = new ($entry['class']);
            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
        }
    });

    it('has() correctly identifies security events', function () {
        expect(SecurityEvents::has('login_attempt'))->toBeTrue();
        expect(SecurityEvents::has('suspicious_activity'))->toBeTrue();
        expect(SecurityEvents::has('data_access_audit'))->toBeTrue();
        expect(SecurityEvents::has('rate_limit_exceeded'))->toBeTrue();
        expect(SecurityEvents::has('mfa_challenge'))->toBeTrue();
        expect(SecurityEvents::has('nonexistent'))->toBeFalse();
    });
});

describe('Uptime Events', function () {
    it('has all 5 uptime events in the catalog', function () {
        expect(UptimeEvents::count())->toBe(5);
        expect(UptimeEvents::names())->toBe([
            'service_up',
            'service_down',
            'api_latency',
            'error_spike',
            'deployment',
        ]);
    });

    it('ServiceUpEvent builds with downtime duration', function () {
        $event = new ServiceUpEvent(
            service: 'database',
            downtimeSeconds: 120.5,
        );

        expect($event->name)->toBe('service_up');
        expect($event->params['service'])->toBe('database');
        expect($event->params['downtime_seconds'])->toBe(120.5);
    });

    it('ServiceDownEvent builds with impact level', function () {
        $event = new ServiceDownEvent(
            service: 'api',
            error: 'Connection refused',
            impact: 'full',
        );

        expect($event->name)->toBe('service_down');
        expect($event->params['service'])->toBe('api');
        expect($event->params['error'])->toBe('Connection refused');
        expect($event->params['impact'])->toBe('full');
    });

    it('ApiLatencyEvent builds with threshold comparison', function () {
        $event = new ApiLatencyEvent(
            endpoint: '/api/analytics/events',
            responseTimeMs: 2500.0,
            thresholdMs: 1000.0,
            method: 'POST',
        );

        expect($event->name)->toBe('api_latency');
        expect($event->params['response_time_ms'])->toBe(2500.0);
        expect($event->params['threshold_ms'])->toBe(1000.0);
        expect($event->params['method'])->toBe('POST');
    });

    it('ErrorSpikeEvent builds with spike analysis', function () {
        $event = new ErrorSpikeEvent(
            errorType: 'http_5xx',
            currentRate: 50.0,
            baselineRate: 5.0,
            spikeMultiplier: 10.0,
        );

        expect($event->name)->toBe('error_spike');
        expect($event->params['current_rate'])->toBe(50.0);
        expect($event->params['baseline_rate'])->toBe(5.0);
        expect($event->params['spike_multiplier'])->toBe(10.0);
    });

    it('DeploymentEvent builds with strategy', function () {
        $event = new DeploymentEvent(
            environment: 'production',
            version: 'v9.9.0',
            strategy: 'blue_green',
            service: 'api',
        );

        expect($event->name)->toBe('deployment');
        expect($event->params['environment'])->toBe('production');
        expect($event->params['version'])->toBe('v9.9.0');
        expect($event->params['strategy'])->toBe('blue_green');
    });

    it('all uptime events extend AnalyticsEvent', function () {
        foreach (UptimeEvents::all() as $entry) {
            $event = new ($entry['class']);
            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
        }
    });

    it('has() correctly identifies uptime events', function () {
        expect(UptimeEvents::has('service_up'))->toBeTrue();
        expect(UptimeEvents::has('service_down'))->toBeTrue();
        expect(UptimeEvents::has('api_latency'))->toBeTrue();
        expect(UptimeEvents::has('error_spike'))->toBeTrue();
        expect(UptimeEvents::has('deployment'))->toBeTrue();
        expect(UptimeEvents::has('nonexistent'))->toBeFalse();
    });
});

describe('EventCatalog Integration', function () {
    it('includes security and uptime in all()', function () {
        $all = EventCatalog::all();

        expect(isset($all['login_attempt']))->toBeTrue();
        expect(isset($all['suspicious_activity']))->toBeTrue();
        expect(isset($all['data_access_audit']))->toBeTrue();
        expect(isset($all['rate_limit_exceeded']))->toBeTrue();
        expect(isset($all['mfa_challenge']))->toBeTrue();
        expect(isset($all['service_up']))->toBeTrue();
        expect(isset($all['service_down']))->toBeTrue();
        expect(isset($all['api_latency']))->toBeTrue();
        expect(isset($all['error_spike']))->toBeTrue();
        expect(isset($all['deployment']))->toBeTrue();
    });

    it('byCategory() includes security and uptime', function () {
        $byCategory = EventCatalog::byCategory();

        expect(isset($byCategory['security']))->toBeTrue();
        expect(isset($byCategory['uptime']))->toBeTrue();
        expect(count($byCategory['security']))->toBe(5);
        expect(count($byCategory['uptime']))->toBe(5);
    });

    it('getCategory() returns correct category for new events', function () {
        expect(EventCatalog::getCategory('login_attempt'))->toBe('security');
        expect(EventCatalog::getCategory('suspicious_activity'))->toBe('security');
        expect(EventCatalog::getCategory('data_access_audit'))->toBe('security');
        expect(EventCatalog::getCategory('rate_limit_exceeded'))->toBe('security');
        expect(EventCatalog::getCategory('mfa_challenge'))->toBe('security');
        expect(EventCatalog::getCategory('service_up'))->toBe('uptime');
        expect(EventCatalog::getCategory('service_down'))->toBe('uptime');
        expect(EventCatalog::getCategory('api_latency'))->toBe('uptime');
        expect(EventCatalog::getCategory('error_spike'))->toBe('uptime');
        expect(EventCatalog::getCategory('deployment'))->toBe('uptime');
    });

    it('has() finds security and uptime events', function () {
        expect(EventCatalog::has('login_attempt'))->toBeTrue();
        expect(EventCatalog::has('deployment'))->toBeTrue();
        expect(EventCatalog::has('nonexistent_event'))->toBeFalse();
    });

    it('classFor() returns correct class for new events', function () {
        expect(EventCatalog::classFor('login_attempt'))->toBe(LoginAttemptEvent::class);
        expect(EventCatalog::classFor('deployment'))->toBe(DeploymentEvent::class);
        expect(EventCatalog::classFor('nonexistent'))->toBeNull();
    });

    it('category() returns events by name', function () {
        $security = EventCatalog::category('security');
        $uptime = EventCatalog::category('uptime');

        expect(count($security))->toBe(5);
        expect(count($uptime))->toBe(5);
        expect($security['login_attempt']['category'])->toBe('security');
        expect($uptime['deployment']['category'])->toBe('uptime');
    });

    it('count() includes new events', function () {
        $previousCount = EventCatalog::count();
        // Total should be: ecommerce(15) + saas(~60) + engagement(~30) + security(5) + uptime(5)
        expect($previousCount)->toBeGreaterThan(100);
        expect(SecurityEvents::count() + UptimeEvents::count())->toBe(10);
    });

    it('search() finds security and uptime events', function () {
        $loginResults = EventCatalog::search('login_attempt');
        expect(count($loginResults))->toBeGreaterThan(0);
        expect($loginResults[0]['name'])->toBe('login_attempt');

        $deployResults = EventCatalog::search('deployment');
        expect(count($deployResults))->toBeGreaterThan(0);
        expect($deployResults[0]['name'])->toBe('deployment');
    });

    it('get() returns annotated entries with category', function () {
        $entry = EventCatalog::get('login_attempt');

        expect($entry)->not->toBeNull();
        expect($entry['category'])->toBe('security');
        expect($entry['class'])->toBe(LoginAttemptEvent::class);
    });

    it('allGa4Names() includes security and uptime names', function () {
        $ga4Names = EventCatalog::allGa4Names();

        expect(in_array('login_attempt', $ga4Names, true))->toBeTrue();
        expect(in_array('deployment', $ga4Names, true))->toBeTrue();
    });

    it('validate() passes for all events including new ones', function () {
        $result = EventCatalog::validate();

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });
});

describe('Version Consistency', function () {
    it('AnalyticsEvent VERSION is 9.9.0', function () {
        expect(AnalyticsEvent::VERSION)->toBe('9.9.0');
    });
});
