<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Middleware\PiiSanitizationMiddleware;
use ZeroBoiler\Analytics\Pipeline\SamplingFilter;
use ZeroBoiler\Analytics\Tracking\AnonymousIdTracker;

describe('AnalyticsMetrics', function () {
    it('records dispatch counts per provider', function () {
        $metrics = new AnalyticsMetrics;
        $metrics->setEnabled(true);

        $metrics->recordDispatch('ga4');
        $metrics->recordDispatch('ga4');
        $metrics->recordDispatch('meta');

        expect($metrics->totalDispatched())->toBe(3);
        expect($metrics->dispatchedByProvider())->toBe([
            'ga4' => 2,
            'meta' => 1,
        ]);
    });

    it('records failures per provider', function () {
        $metrics = new AnalyticsMetrics;
        $metrics->setEnabled(true);

        $metrics->recordFailure('ga4', 'timeout');
        $metrics->recordFailure('webhook', 'connection refused');

        expect($metrics->totalFailed())->toBe(2);
        expect($metrics->failuresByProvider())->toBe([
            'ga4' => 1,
            'webhook' => 1,
        ]);
    });

    it('records filtered and deduplicated events', function () {
        $metrics = new AnalyticsMetrics;
        $metrics->setEnabled(true);

        $metrics->recordFiltered();
        $metrics->recordFiltered();
        $metrics->recordDeduplicated();

        expect($metrics->totalFiltered())->toBe(2);
        expect($metrics->totalDeduplicated())->toBe(1);
    });

    it('returns full summary', function () {
        $metrics = new AnalyticsMetrics;
        $metrics->setEnabled(true);

        $metrics->recordDispatch('ga4');
        $metrics->recordDispatch('meta');
        $metrics->recordFailure('webhook', 'dns error');
        $metrics->recordFiltered();
        $metrics->recordDeduplicated();

        $summary = $metrics->summary();

        expect($summary)->toHaveKeys([
            'dispatched', 'failed', 'filtered', 'deduplicated',
            'total_dispatched', 'total_failed',
        ]);
        expect($summary['total_dispatched'])->toBe(2);
        expect($summary['total_failed'])->toBe(1);
        expect($summary['filtered'])->toBe(1);
        expect($summary['deduplicated'])->toBe(1);
    });

    it('flushes all counters', function () {
        $metrics = new AnalyticsMetrics;
        $metrics->setEnabled(true);

        $metrics->recordDispatch('ga4');
        $metrics->recordFailure('meta', 'error');

        $metrics->flush();

        expect($metrics->totalDispatched())->toBe(0);
        expect($metrics->totalFailed())->toBe(0);
        expect($metrics->dispatchedByProvider())->toBe([]);
    });

    it('does not record when disabled', function () {
        $metrics = new AnalyticsMetrics;
        $metrics->setEnabled(false);

        $metrics->recordDispatch('ga4');
        $metrics->recordFailure('meta', 'error');
        $metrics->recordFiltered();
        $metrics->recordDeduplicated();

        expect($metrics->totalDispatched())->toBe(0);
        expect($metrics->totalFailed())->toBe(0);
    });

    it('isEnabled returns current state', function () {
        $metrics = new AnalyticsMetrics;

        expect($metrics->isEnabled())->toBeFalse();

        $metrics->setEnabled(true);

        expect($metrics->isEnabled())->toBeTrue();
    });
});

describe('PiiSanitizationMiddleware', function () {
    it('hashes email fields by default', function () {
        $middleware = new PiiSanitizationMiddleware;

        $event = new AnalyticsEvent(
            name: 'user_signup',
            params: [
                'email' => 'test@example.com',
                'plan' => 'pro',
            ],
        );

        $result = $middleware->process($event);

        expect($result->params['email'])->toBe(hash('sha256', 'test@example.com'));
        expect($result->params['plan'])->toBe('pro');
    });

    it('removes PII fields with remove strategy', function () {
        $middleware = new PiiSanitizationMiddleware(
            strategy: PiiSanitizationMiddleware::STRATEGY_REMOVE,
        );

        $event = new AnalyticsEvent(
            name: 'form_submit',
            params: [
                'email' => 'user@test.com',
                'phone' => '+1234567890',
                'message' => 'hello',
            ],
        );

        $result = $middleware->process($event);

        expect($result->params['email'])->toBeNull();
        expect($result->params['phone'])->toBeNull();
        expect($result->params['message'])->toBe('hello');
    });

    it('masks PII fields with mask strategy', function () {
        $middleware = new PiiSanitizationMiddleware(
            strategy: PiiSanitizationMiddleware::STRATEGY_MASK,
        );

        $event = new AnalyticsEvent(
            name: 'contact_form',
            params: [
                'email' => 'longemailaddress@example.com',
                'name' => 'John Doe',
            ],
        );

        $result = $middleware->process($event);

        expect($result->params['email'])->toBeString();
        expect($result->params['email'])->toContain('*');
        expect($result->params['name'])->toBeString();
        expect($result->params['name'])->toContain('*');
    });

    it('detects PII-like values by pattern', function () {
        $middleware = new PiiSanitizationMiddleware;

        $event = new AnalyticsEvent(
            name: 'custom_event',
            params: [
                'contact_info' => 'user@domain.com',
                'role' => 'admin',
            ],
        );

        $result = $middleware->process($event);

        // contact_info contains an email, should be hashed
        expect($result->params['contact_info'])->toBeString();
        expect($result->params['contact_info'])->not->toBe('user@domain.com');
        expect($result->params['role'])->toBe('admin');
    });

    it('uses custom PII fields when provided', function () {
        $middleware = new PiiSanitizationMiddleware(
            piiFields: ['custom_identifier', 'secret_code'],
            strategy: PiiSanitizationMiddleware::STRATEGY_REMOVE,
        );

        $event = new AnalyticsEvent(
            name: 'test',
            params: [
                'custom_identifier' => 'should-be-removed',
                'email' => 'keep@test.com', // Not in custom fields, keep as-is
            ],
        );

        $result = $middleware->process($event);

        expect($result->params['custom_identifier'])->toBeNull();
        expect($result->params['email'])->toBe('keep@test.com');
    });

    it('preserves clientId and userId', function () {
        $middleware = new PiiSanitizationMiddleware;

        $event = new AnalyticsEvent(
            name: 'test',
            params: ['email' => 'user@test.com'],
            clientId: 'client-123',
            userId: 'user-456',
        );

        $result = $middleware->process($event);

        expect($result->clientId)->toBe('client-123');
        expect($result->userId)->toBe('user-456');
    });

    it('returns correct priority', function () {
        $middleware = new PiiSanitizationMiddleware(priority: 75);

        expect($middleware->getPriority())->toBe(75);
    });
});

describe('SamplingFilter', function () {
    it('passes all events at 100% rate', function () {
        $filter = new SamplingFilter(sampleRate: 1.0);

        for ($i = 0; $i < 100; $i++) {
            $event = new AnalyticsEvent(name: "event_{$i}");
            expect($filter->shouldSample($event))->toBeTrue();
        }
    });

    it('drops all events at 0% rate', function () {
        $filter = new SamplingFilter(sampleRate: 0.0);

        for ($i = 0; $i < 100; $i++) {
            $event = new AnalyticsEvent(name: "event_{$i}");
            expect($filter->shouldSample($event))->toBeFalse();
        }
    });

    it('is deterministic by default', function () {
        $filter = new SamplingFilter(sampleRate: 0.1);

        // Same event name should always return the same result
        $event = new AnalyticsEvent(name: 'purchase');
        $results = [];

        for ($i = 0; $i < 50; $i++) {
            $results[] = $filter->shouldSample($event);
        }

        // All results should be the same (all true or all false)
        $uniqueResults = array_unique($results);
        expect(count($uniqueResults))->toBe(1);
    });

    it('is non-deterministic when configured', function () {
        $filter = new SamplingFilter(sampleRate: 0.5, deterministic: false);

        $results = [];
        for ($i = 0; $i < 200; $i++) {
            $event = new AnalyticsEvent(name: 'button_click');
            $results[] = $filter->shouldSample($event) ? 1 : 0;
        }

        $average = array_sum($results) / count($results);

        // With 50% rate and randomness, we should get roughly 50%
        expect($average)->toBeGreaterThan(0.3);
        expect($average)->toBeLessThan(0.7);
    });

    it('clamps rate to 0.0–1.0 range', function () {
        $overMax = new SamplingFilter(sampleRate: 5.0);
        expect($overMax->getSampleRate())->toBe(1.0);

        $underMin = new SamplingFilter(sampleRate: -0.5);
        expect($underMin->getSampleRate())->toBe(0.0);
    });

    it('different events sample differently in deterministic mode', function () {
        $filter = new SamplingFilter(sampleRate: 0.1);

        $events = [];
        for ($i = 0; $i < 100; $i++) {
            $event = new AnalyticsEvent(name: "event_name_{$i}");
            $events[] = $filter->shouldSample($event);
        }

        // With 10% sampling and 100 unique events, should have some true and some false
        $sampled = count(array_filter($events));

        // Not all should be the same
        expect($sampled)->toBeGreaterThan(0);
        expect($sampled)->toBeLessThan(100);
    });
});

describe('AnonymousIdTracker', function () {
    it('generates a new UUID when no cookie exists', function () {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.identity', [])
            ->andReturn([
                'cookie_name' => 'zb_test_id',
                'cookie_ttl' => 525600,
                'cookie_secure' => true,
                'cookie_samesite' => 'Lax',
            ]);

        $tracker = new AnonymousIdTracker($config);
        $result = $tracker->resolve(null);

        expect($result['is_new'])->toBeTrue();
        expect($result['anonymous_id'])->not->toBeEmpty();
        expect($tracker->isValidUuid($result['anonymous_id']))->toBeTrue();
    });

    it('reuses existing valid cookie UUID', function () {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.identity', [])
            ->andReturn([
                'cookie_name' => 'zb_test_id',
                'cookie_ttl' => 525600,
                'cookie_secure' => true,
                'cookie_samesite' => 'Lax',
            ]);

        $tracker = new AnonymousIdTracker($config);
        $existingId = '550e8400-e29b-41d4-a716-446655440000';
        $result = $tracker->resolve($existingId);

        expect($result['is_new'])->toBeFalse();
        expect($result['anonymous_id'])->toBe($existingId);
    });

    it('generates new ID for invalid cookie format', function () {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.identity', [])
            ->andReturn([
                'cookie_name' => 'zb_test_id',
                'cookie_ttl' => 525600,
                'cookie_secure' => true,
                'cookie_samesite' => 'Lax',
            ]);

        $tracker = new AnonymousIdTracker($config);
        $result = $tracker->resolve('not-a-uuid');

        expect($result['is_new'])->toBeTrue();
        expect($tracker->isValidUuid($result['anonymous_id']))->toBeTrue();
    });

    it('generates new ID for empty cookie', function () {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.identity', [])
            ->andReturn([
                'cookie_name' => 'zb_test_id',
                'cookie_ttl' => 525600,
                'cookie_secure' => true,
                'cookie_samesite' => 'Lax',
            ]);

        $tracker = new AnonymousIdTracker($config);
        $result = $tracker->resolve('');

        expect($result['is_new'])->toBeTrue();
    });

    it('validates UUID format correctly', function () {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.identity', [])
            ->andReturn([]);

        $tracker = new AnonymousIdTracker($config);

        expect($tracker->isValidUuid('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue();
        expect($tracker->isValidUuid('invalid-uuid'))->toBeFalse();
        expect($tracker->isValidUuid(''))->toBeFalse();
    });

    it('returns correct cookie name', function () {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.identity', [])
            ->andReturn([
                'cookie_name' => 'my_analytics',
                'cookie_prefix' => 'app',
            ]);

        $tracker = new AnonymousIdTracker($config);

        expect($tracker->getCookieName())->toBe('app_my_analytics');
    });

    it('returns cookie params', function () {
        $config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')
            ->with('zeroboiler.analytics.identity', [])
            ->andReturn([
                'cookie_name' => 'zb_id',
                'cookie_ttl' => 1440,
                'cookie_secure' => false,
                'cookie_samesite' => 'Strict',
            ]);

        $tracker = new AnonymousIdTracker($config);
        $params = $tracker->getCookieParams();

        expect($params)->toHaveKey('name');
        expect($params['minutes'])->toBe(1440);
        expect($params['secure'])->toBeFalse();
        expect($params['sameSite'])->toBe('Strict');
        expect($params['httpOnly'])->toBeTrue();
    });
});
