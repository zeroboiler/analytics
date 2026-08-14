<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;

describe('Tracker Enhancements — v90.0.0', function () {
    describe('PlausibleTracker — trackCustomEvent', function () {
        it('sends custom event with properties to Plausible API', function () {
            Http::fake([
                '*' => Http::response('', 202),
            ]);

            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'test-api-key',
            );

            // Use reflection to enable the tracker (bypassing isEnabled check)
            $ref = new ReflectionClass($tracker);
            $prop = $ref->getProperty('enabled');
            $prop->setAccessible(true);
            $prop->setValue($tracker, true);

            $tracker->trackCustomEvent(
                name: 'Signup',
                url: 'https://example.com/signup',
                props: ['plan' => 'pro', 'source' => 'homepage'],
                referrer: 'https://google.com',
            );

            Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
                $body = $request->data();
                return $request->url() === 'https://plausible.io/api/event'
                    && ($body['name'] ?? null) === 'Signup'
                    && ($body['domain'] ?? null) === 'example.com'
                    && ($body['url'] ?? null) === 'https://example.com/signup'
                    && ($body['props']['plan'] ?? null) === 'pro'
                    && ($body['referrer'] ?? null) === 'https://google.com';
            });
        });

        it('sends custom event without referrer', function () {
            Http::fake([
                '*' => Http::response('', 202),
            ]);

            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'test-api-key',
            );

            $ref = new ReflectionClass($tracker);
            $prop = $ref->getProperty('enabled');
            $prop->setAccessible(true);
            $prop->setValue($tracker, true);

            $tracker->trackCustomEvent(
                name: 'Purchase',
                url: 'https://example.com/checkout',
                props: ['value' => 99.99],
            );

            Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
                $body = $request->data();
                return ($body['name'] ?? null) === 'Purchase'
                    && ! isset($body['referrer']);
            });
        });

        it('does nothing when disabled', function () {
            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'test-api-key',
            );

            // Tracker is disabled by default (no config)
            $tracker->trackCustomEvent(
                name: 'Test',
                url: 'https://example.com',
            );

            Http::assertNothingSent();
        });

        it('logs error on HTTP failure', function () {
            Http::fake([
                '*' => Http::response('Bad Request', 400),
            ]);

            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'test-api-key',
            );

            $ref = new ReflectionClass($tracker);
            $prop = $ref->getProperty('enabled');
            $prop->setAccessible(true);
            $prop->setValue($tracker, true);

            // Should not throw — logs warning instead
            $tracker->trackCustomEvent(
                name: 'ErrorTest',
                url: 'https://example.com',
            );

            Http::assertSentCount(1);
        });

        it('filters empty values from payload', function () {
            Http::fake([
                '*' => Http::response('', 202),
            ]);

            $tracker = new PlausibleTracker(
                domain: 'example.com',
                apiKey: 'test-api-key',
            );

            $ref = new ReflectionClass($tracker);
            $prop = $ref->getProperty('enabled');
            $prop->setAccessible(true);
            $prop->setValue($tracker, true);

            $tracker->trackCustomEvent(
                name: 'Test',
                url: 'https://example.com',
                props: [],
                referrer: '',
            );

            Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
                $body = $request->data();
                return ! isset($body['referrer']) && $body['props'] === [];
            });
        });
    });

    describe('PosthogTracker — deletePerson', function () {
        it('sends DELETE request to PostHog API', function () {
            Http::fake([
                '*' => Http::response('', 200),
            ]);

            $tracker = new PosthogTracker(
                host: 'https://app.posthog.com',
                apiKey: 'test-api-key',
            );

            $ref = new ReflectionClass($tracker);
            $prop = $ref->getProperty('enabled');
            $prop->setAccessible(true);
            $prop->setValue($tracker, true);

            $result = $tracker->deletePerson('distinct-user-123');

            expect($result)->toBeTrue();

            Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
                return $request->method() === 'DELETE'
                    && str_contains($request->url(), '/api/persons/distinct-user-123')
                    && $request->header('Authorization')[0] === 'Bearer test-api-key';
            });
        });

        it('returns false when disabled', function () {
            $tracker = new PosthogTracker(
                host: 'https://app.posthog.com',
                apiKey: 'test-api-key',
            );

            $result = $tracker->deletePerson('user-123');

            expect($result)->toBeFalse();
        });

        it('returns false and logs on HTTP error', function () {
            Http::fake([
                '*' => Http::response('Not Found', 404),
            ]);

            $tracker = new PosthogTracker(
                host: 'https://app.posthog.com',
                apiKey: 'test-api-key',
            );

            $ref = new ReflectionClass($tracker);
            $prop = $ref->getProperty('enabled');
            $prop->setAccessible(true);
            $prop->setValue($tracker, true);

            $result = $tracker->deletePerson('nonexistent-user');

            expect($result)->toBeFalse();
        });

        it('handles exceptions gracefully', function () {
            Http::fake(function () {
                throw new \RuntimeException('Connection refused');
            });

            $tracker = new PosthogTracker(
                host: 'https://app.posthog.com',
                apiKey: 'test-api-key',
            );

            $ref = new ReflectionClass($tracker);
            $prop = $ref->getProperty('enabled');
            $prop->setAccessible(true);
            $prop->setValue($tracker, true);

            $result = $tracker->deletePerson('user-456');

            expect($result)->toBeFalse();
        });
    });
});
