<?php

declare(strict_types=1);

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\SdkBridgeService;

beforeEach(function (): void {
    $this->bridge = new SdkBridgeService();
});

describe('V1660 — SDK Bridge Service', function (): void {

    describe('supportedSdks', function (): void {
        test('returns all 9 supported SDK identifiers', function (): void {
            $sdks = $this->bridge->supportedSdks();
            expect($sdks)->toContain('posthog', 'mixpanel', 'segment', 'amplitude', 'plausible', 'ga4', 'meta', 'tiktok', 'linkedin');
            expect(count($sdks))->toBe(9);
        });
    });

    describe('supportsSdk', function (): void {
        test('returns true for supported SDKs', function (): void {
            expect($this->bridge->supportsSdk('posthog'))->toBeTrue();
            expect($this->bridge->supportsSdk('PostHog'))->toBeTrue();
            expect($this->bridge->supportsSdk('MIXPANEL'))->toBeTrue();
            expect($this->bridge->supportsSdk('segment'))->toBeTrue();
            expect($this->bridge->supportsSdk('amplitude'))->toBeTrue();
            expect($this->bridge->supportsSdk('ga4'))->toBeTrue();
        });

        test('returns false for unsupported SDKs', function (): void {
            expect($this->bridge->supportsSdk('heap'))->toBeFalse();
            expect($this->bridge->supportsSdk('hotjar'))->toBeFalse();
            expect($this->bridge->supportsSdk('ga'))->toBeFalse();
        });
    });

    describe('translateInbound — PostHog', function (): void {
        test('translates $pageview to page_view', function (): void {
            $event = $this->bridge->translateInbound('posthog', '$pageview', ['$current_url' => '/dashboard', '$browser' => 'Chrome']);
            expect($event->name)->toBe('page_view');
            expect($event->source)->toBe('sdk_bridge');
            expect($event->params)->toHaveKey('current_url');
            expect($event->params)->not->toHaveKey('$current_url');
            expect($event->params)->not->toHaveKey('$browser');
        });

        test('translates $screen to screen_view', function (): void {
            $event = $this->bridge->translateInbound('posthog', '$screen');
            expect($event->name)->toBe('screen_view');
        });

        test('translates user_signed_up to sign_up', function (): void {
            $event = $this->bridge->translateInbound('posthog', 'user_signed_up', ['method' => 'email']);
            expect($event->name)->toBe('sign_up');
            expect($event->params['method'])->toBe('email');
        });

        test('translates subscription_created to subscription', function (): void {
            $event = $this->bridge->translateInbound('posthog', 'subscription_created', ['plan' => 'pro']);
            expect($event->name)->toBe('subscription');
        });

        test('strips PostHog metadata fields', function (): void {
            $event = $this->bridge->translateInbound('posthog', '$pageview', [
                '$set' => ['name' => 'John'],
                '$set_once' => ['initial_referrer' => 'google'],
                '$unset' => ['old_field'],
                '$lib' => 'posthog-js',
                '$lib_version' => '1.0.0',
            ]);
            expect($event->params)->not->toHaveKey('$set');
            expect($event->params)->not->toHaveKey('$set_once');
            expect($event->params)->not->toHaveKey('$unset');
            expect($event->params)->not->toHaveKey('$lib');
            expect($event->params)->not->toHaveKey('$lib_version');
        });

        test('passes through unknown event names', function (): void {
            $event = $this->bridge->translateInbound('posthog', 'custom_event_xyz', ['key' => 'value']);
            expect($event->name)->toBe('custom_event_xyz');
            expect($event->params['key'])->toBe('value');
        });
    });

    describe('translateInbound — Mixpanel', function (): void {
        test('translates Signup to sign_up', function (): void {
            $event = $this->bridge->translateInbound('mixpanel', 'Signup', ['plan' => 'pro']);
            expect($event->name)->toBe('sign_up');
            expect($event->params['plan'])->toBe('pro');
        });

        test('translates Purchase to purchase', function (): void {
            $event = $this->bridge->translateInbound('mixpanel', 'Purchase', ['revenue' => 99.99]);
            expect($event->name)->toBe('purchase');
        });

        test('strips Mixpanel metadata', function (): void {
            $event = $this->bridge->translateInbound('mixpanel', 'Signup', ['mp_lib' => 'mixpanel-js', 'mp_device_id' => 'abc']);
            expect($event->params)->not->toHaveKey('mp_lib');
            expect($event->params)->not->toHaveKey('mp_device_id');
        });
    });

    describe('translateInbound — Segment', function (): void {
        test('translates page to page_view', function (): void {
            $event = $this->bridge->translateInbound('segment', 'page', ['path' => '/pricing']);
            expect($event->name)->toBe('page_view');
        });

        test('translates Order Completed to purchase', function (): void {
            $event = $this->bridge->translateInbound('segment', 'Order Completed', ['revenue' => 149.99]);
            expect($event->name)->toBe('purchase');
        });

        test('strips Segment metadata', function (): void {
            $event = $this->bridge->translateInbound('segment', 'page', [
                'context' => ['page' => ['url' => '/test']],
                'integrations' => ['GA' => false],
                'messageId' => 'msg-123',
            ]);
            expect($event->params)->not->toHaveKey('context');
            expect($event->params)->not->toHaveKey('integrations');
            expect($event->params)->not->toHaveKey('messageId');
        });
    });

    describe('translateInbound — Amplitude', function (): void {
        test('translates [Amplitude] User Signed Up to sign_up', function (): void {
            $event = $this->bridge->translateInbound('amplitude', '[Amplitude] User Signed Up');
            expect($event->name)->toBe('sign_up');
        });

        test('strips Amplitude metadata', function (): void {
            $event = $this->bridge->translateInbound('amplitude', '[Amplitude] Page Viewed', [
                'device_id' => 'dev-123',
                'library' => 'amplitude-js',
                'os_name' => 'iOS',
            ]);
            expect($event->params)->not->toHaveKey('device_id');
            expect($event->params)->not->toHaveKey('library');
            expect($event->params)->not->toHaveKey('os_name');
        });
    });

    describe('translateInbound — unsupported SDK', function (): void {
        test('throws InvalidArgumentException for unsupported SDK', function (): void {
            expect(fn (): mixed => $this->bridge->translateInbound('heap', 'pageview'))
                ->toThrow(\InvalidArgumentException::class);
        });
    });

    describe('translateOutbound — PostHog', function (): void {
        test('translates page_view to $pageview', function (): void {
            $event = new AnalyticsEvent(name: 'page_view', params: ['path' => '/dashboard']);
            $result = $this->bridge->translateOutbound('posthog', $event);
            expect($result['event'])->toBe('$pageview');
        });

        test('translates sign_up to user_signed_up', function (): void {
            $event = new AnalyticsEvent(name: 'sign_up', params: ['method' => 'email']);
            $result = $this->bridge->translateOutbound('posthog', $event);
            expect($result['event'])->toBe('user_signed_up');
        });

        test('transforms user_id to distinct_id', function (): void {
            $event = new AnalyticsEvent(name: 'sign_up', params: ['user_id' => 'user-123']);
            $result = $this->bridge->translateOutbound('posthog', $event);
            expect($result['properties'])->toHaveKey('distinct_id');
            expect($result['properties']['distinct_id'])->toBe('user-123');
            expect($result['properties'])->not->toHaveKey('user_id');
        });

        test('transforms user_properties to $set', function (): void {
            $event = new AnalyticsEvent(name: 'sign_up', params: [
                'user_properties' => ['name' => 'John', 'plan' => 'pro'],
            ]);
            $result = $this->bridge->translateOutbound('posthog', $event);
            expect($result['properties'])->toHaveKey('$set');
            expect($result['properties']['$set']['name'])->toBe('John');
            expect($result['properties'])->not->toHaveKey('user_properties');
        });
    });

    describe('translateOutbound — Mixpanel', function (): void {
        test('translates sign_up to Signup', function (): void {
            $event = new AnalyticsEvent(name: 'sign_up');
            $result = $this->bridge->translateOutbound('mixpanel', $event);
            expect($result['event'])->toBe('Signup');
        });

        test('translates purchase to Purchase', function (): void {
            $event = new AnalyticsEvent(name: 'purchase');
            $result = $this->bridge->translateOutbound('mixpanel', $event);
            expect($result['event'])->toBe('Purchase');
        });

        test('transforms user_id to distinct_id', function (): void {
            $event = new AnalyticsEvent(name: 'login', params: ['user_id' => 'user-456']);
            $result = $this->bridge->translateOutbound('mixpanel', $event);
            expect($result['properties']['distinct_id'])->toBe('user-456');
        });
    });

    describe('translateOutbound — Segment', function (): void {
        test('translates page_view to page', function (): void {
            $event = new AnalyticsEvent(name: 'page_view');
            $result = $this->bridge->translateOutbound('segment', $event);
            expect($result['event'])->toBe('page');
        });

        test('translates purchase to Order Completed', function (): void {
            $event = new AnalyticsEvent(name: 'purchase');
            $result = $this->bridge->translateOutbound('segment', $event);
            expect($result['event'])->toBe('Order Completed');
        });

        test('transforms user_properties to traits', function (): void {
            $event = new AnalyticsEvent(name: 'sign_up', params: [
                'user_properties' => ['email' => 'test@example.com'],
            ]);
            $result = $this->bridge->translateOutbound('segment', $event);
            expect($result['properties'])->toHaveKey('traits');
            expect($result['properties']['traits']['email'])->toBe('test@example.com');
        });
    });

    describe('translateOutbound — Amplitude', function (): void {
        test('translates page_view to [Amplitude] Page Viewed', function (): void {
            $event = new AnalyticsEvent(name: 'page_view');
            $result = $this->bridge->translateOutbound('amplitude', $event);
            expect($result['event'])->toBe('[Amplitude] Page Viewed');
        });

        test('translates sign_up to [Amplitude] User Signed Up', function (): void {
            $event = new AnalyticsEvent(name: 'sign_up');
            $result = $this->bridge->translateOutbound('amplitude', $event);
            expect($result['event'])->toBe('[Amplitude] User Signed Up');
        });
    });

    describe('compatibilityReport', function (): void {
        test('returns report with total, mapped, unmapped fields', function (): void {
            $report = $this->bridge->compatibilityReport('posthog');
            expect($report)->toHaveKey('total');
            expect($report)->toHaveKey('mapped');
            expect($report)->toHaveKey('unmapped');
            expect($report)->toHaveKey('unmapped_events');
            expect($report)->toHaveKey('mapped_events');
            expect($report)->toHaveKey('warnings');
            expect($report['total'])->toBeGreaterThan(0);
            expect($report['mapped'] + $report['unmapped'])->toBe($report['total']);
        });

        test('throws for unsupported SDK', function (): void {
            expect(fn (): mixed => $this->bridge->compatibilityReport('unknown'))
                ->toThrow(\InvalidArgumentException::class);
        });
    });

    describe('mappingCoverage', function (): void {
        test('returns coverage with percentage and by_category breakdown', function (): void {
            $coverage = $this->bridge->mappingCoverage('posthog');
            expect($coverage)->toHaveKey('coverage_percent');
            expect($coverage)->toHaveKey('total');
            expect($coverage)->toHaveKey('mapped');
            expect($coverage)->toHaveKey('by_category');
            expect($coverage['coverage_percent'])->toBeGreaterThanOrEqual(0.0);
            expect($coverage['coverage_percent'])->toBeLessThanOrEqual(100.0);
        });
    });

    describe('inspectTranslation', function (): void {
        test('returns inspection result for mapped event', function (): void {
            $inspection = $this->bridge->inspectTranslation('posthog', 'page_view', ['path' => '/test']);
            expect($inspection['original'])->toBe('page_view');
            expect($inspection['translated'])->toBe('$pageview');
            expect($inspection['has_mapping'])->toBeTrue();
            expect($inspection['source'])->toBe('explicit_mapping');
        });

        test('returns passthrough for unmapped event', function (): void {
            $inspection = $this->bridge->inspectTranslation('posthog', 'custom_xyz', []);
            expect($inspection['has_mapping'])->toBeFalse();
            expect($inspection['source'])->toBe('catalog_fallback');
        });
    });

    describe('getInboundMap / getOutboundMap', function (): void {
        test('returns PostHog inbound map with expected keys', function (): void {
            $map = $this->bridge->getInboundMap('posthog');
            expect($map)->toHaveKey('$pageview');
            expect($map['$pageview'])->toBe('page_view');
            expect($map)->toHaveKey('user_signed_up');
        });

        test('returns Mixpanel outbound map with expected keys', function (): void {
            $map = $this->bridge->getOutboundMap('mixpanel');
            expect($map)->toHaveKey('sign_up');
            expect($map['sign_up'])->toBe('Signup');
            expect($map)->toHaveKey('purchase');
        });

        test('returns empty map for SDK with no mappings', function (): void {
            $inMap = $this->bridge->getInboundMap('ga4');
            $outMap = $this->bridge->getOutboundMap('ga4');
            expect($inMap)->toBeEmpty();
            expect($outMap)->toBeEmpty();
        });
    });

    describe('registerInboundMapping / registerOutboundMapping', function (): void {
        test('registers custom inbound mapping', function (): void {
            $this->bridge->registerInboundMapping('posthog', 'custom_event', 'my_custom');
            $event = $this->bridge->translateInbound('posthog', 'custom_event');
            expect($event->name)->toBe('my_custom');
        });

        test('registers custom outbound mapping', function (): void {
            $this->bridge->registerOutboundMapping('mixpanel', 'my_custom', 'MyCustom');
            $event = new AnalyticsEvent(name: 'my_custom');
            $result = $this->bridge->translateOutbound('mixpanel', $event);
            expect($result['event'])->toBe('MyCustom');
        });
    });

    describe('registerInboundParamTransformer / registerOutboundParamTransformer', function (): void {
        test('applies custom inbound param transformer', function (): void {
            $this->bridge->registerInboundParamTransformer('amplitude', function (string $name, array $params): array {
                $params['transformed'] = true;
                return $params;
            });

            $event = $this->bridge->translateInbound('amplitude', '[Amplitude] Page Viewed', ['key' => 'val']);
            expect($event->params['transformed'])->toBeTrue();
        });

        test('applies custom outbound param transformer', function (): void {
            $this->bridge->registerOutboundParamTransformer('segment', function (string $name, array $params): array {
                $params['sent_via_bridge'] = true;
                return $params;
            });

            $event = new AnalyticsEvent(name: 'page_view');
            $result = $this->bridge->translateOutbound('segment', $event);
            expect($result['properties']['sent_via_bridge'])->toBeTrue();
        });
    });

    describe('bidirectional roundtrip consistency', function (): void {
        test('PostHog $pageview → page_view → $pageview roundtrip', function (): void {
            // Inbound: PostHog → ZeroBoiler
            $zbEvent = $this->bridge->translateInbound('posthog', '$pageview');
            expect($zbEvent->name)->toBe('page_view');

            // Outbound: ZeroBoiler → PostHog
            $outbound = $this->bridge->translateOutbound('posthog', $zbEvent);
            expect($outbound['event'])->toBe('$pageview');
        });

        test('Mixpanel Signup → sign_up → Signup roundtrip', function (): void {
            $zbEvent = $this->bridge->translateInbound('mixpanel', 'Signup');
            expect($zbEvent->name)->toBe('sign_up');

            $outbound = $this->bridge->translateOutbound('mixpanel', $zbEvent);
            expect($outbound['event'])->toBe('Signup');
        });

        test('Segment page → page_view → page roundtrip', function (): void {
            $zbEvent = $this->bridge->translateInbound('segment', 'page');
            expect($zbEvent->name)->toBe('page_view');

            $outbound = $this->bridge->translateOutbound('segment', $zbEvent);
            expect($outbound['event'])->toBe('page');
        });
    });

    describe('class structure', function (): void {
        test('SdkBridgeService is final', function (): void {
            $ref = new \ReflectionClass(SdkBridgeService::class);
            expect($ref->isFinal())->toBeTrue();
        });

        test('SdkBridgeService uses strict_types', function (): void {
            $contents = file_get_contents((new \ReflectionClass(SdkBridgeService::class))->getFileName());
            expect($contents)->toContain('declare(strict_types=1)');
        });

        test('has MIT license header', function (): void {
            $contents = file_get_contents((new \ReflectionClass(SdkBridgeService::class))->getFileName());
            expect(str_contains($contents, 'MIT license'))->toBeTrue();
        });
    });
});
