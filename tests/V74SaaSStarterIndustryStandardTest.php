<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\DTO\EventPriority;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Context\EventContextBuilder;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionPausedEvent;
use ZeroBoiler\Analytics\Events\Engagement\FeatureRequestEvent;

describe('v2.74.0 — Industry Standard SaaS Starter Upgrade', function () {
    describe('Version consistency', function () {
        it('AnalyticsEvent VERSION is 2.74.0', function () {
            expect(AnalyticsEvent::VERSION)->toBe('5.9.0');
        });

        it('composer.json version matches AnalyticsEvent VERSION', function () {
            $composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
            expect($composer['version'])->toBe('5.9.0');
        });
    });

    describe('AnalyticsManager version', function () {
        it('version returns 2.74.0', function () {
            $manager = new \ZeroBoiler\Analytics\AnalyticsManager(null);

            expect($manager->version())->toBe('5.9.0');
        });
    });

    describe('SubscriptionPausedEvent — new SaaS event', function () {
        it('constructs with required plan parameter', function () {
            $event = new SubscriptionPausedEvent(plan: 'Pro');

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('subscription_paused');
            expect($event->params['plan'])->toBe('Pro');
        });

        it('includes optional parameters', function () {
            $event = new SubscriptionPausedEvent(
                plan: 'Enterprise',
                reason: 'seasonal',
                pauseDurationDays: 90,
                userId: 'user_123',
            );

            expect($event->params['reason'])->toBe('seasonal');
            expect($event->params['pause_duration_days'])->toBe(90);
            expect($event->userId)->toBe('user_123');
        });

        it('is registered in SaaSEvents catalog', function () {
            expect(SaaSEvents::has('subscription_paused'))->toBeTrue();

            $entry = SaaSEvents::get('subscription_paused');
            expect($entry['class'])->toBe(SubscriptionPausedEvent::class);
            expect($entry['ga4'])->toBe('subscription_paused');
            expect($entry['meta'])->toBe('SubscriptionPaused');
            expect($entry['posthog'])->toBe('subscription_paused');
        });

        it('is readonly', function () {
            $ref = new ReflectionClass(SubscriptionPausedEvent::class);
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('is in EventCatalog all()', function () {
            expect(EventCatalog::has('subscription_paused'))->toBeTrue();

            $entry = EventCatalog::get('subscription_paused');
            expect($entry['category'])->toBe('saas');
        });
    });

    describe('FeatureRequestEvent — new engagement event', function () {
        it('constructs with required description', function () {
            $event = new FeatureRequestEvent(featureDescription: 'Dark mode support');

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('feature_request');
            expect($event->params['feature_description'])->toBe('Dark mode support');
        });

        it('includes all optional parameters', function () {
            $event = new FeatureRequestEvent(
                featureDescription: 'API rate limit dashboard',
                category: 'reporting',
                source: 'in_app_modal',
                voteCount: 42,
                requestId: 'req_abc123',
                pageUrl: 'https://app.example.com/dashboard',
            );

            expect($event->params['category'])->toBe('reporting');
            expect($event->params['source'])->toBe('in_app_modal');
            expect($event->params['vote_count'])->toBe(42);
            expect($event->params['request_id'])->toBe('req_abc123');
            expect($event->params['page_url'])->toBe('https://app.example.com/dashboard');
        });

        it('is registered in EngagementEvents catalog', function () {
            expect(EngagementEvents::has('feature_request'))->toBeTrue();

            $entry = EngagementEvents::get('feature_request');
            expect($entry['class'])->toBe(FeatureRequestEvent::class);
            expect($entry['ga4'])->toBe('feature_request');
            expect($entry['meta'])->toBe('FeatureRequest');
            expect($entry['posthog'])->toBe('feature_request');
        });

        it('is readonly', function () {
            $ref = new ReflectionClass(FeatureRequestEvent::class);
            expect($ref->isReadOnly())->toBeTrue();
        });

        it('is in EventCatalog all()', function () {
            expect(EventCatalog::has('feature_request'))->toBeTrue();

            $entry = EventCatalog::get('feature_request');
            expect($entry['category'])->toBe('engagement');
        });
    });

    describe('EventCatalog — new helper methods', function () {
        it('engagementEvents returns filtered engagement events', function () {
            $events = EventCatalog::engagementEvents();

            expect(is_array($events))->toBeTrue();
            expect(count($events))->toBeGreaterThanOrEqual(15);

            $names = array_map(fn (array $e): string => $e['name'], $events);
            expect($names)->toContain('page_view');
            expect($names)->toContain('feature_request');
            expect($names)->toContain('onboarding_step');
        });

        it('saasFunnelEvents returns SaaS funnel events', function () {
            $events = EventCatalog::saasFunnelEvents();

            expect(is_array($events))->toBeTrue();
            expect(count($events))->toBeGreaterThanOrEqual(8);

            $names = array_map(fn (array $e): string => $e['name'], $events);
            expect($names)->toContain('sign_up');
            expect($names)->toContain('subscribe');
            expect($names)->toContain('subscription_paused');
            expect($names)->toContain('milestone_reached');
        });

        it('engagementEvents only contains engagement category', function () {
            $events = EventCatalog::engagementEvents();

            foreach ($events as $entry) {
                expect($entry['category'])->toBe('engagement');
            }
        });

        it('saasFunnelEvents only contains saas category', function () {
            $events = EventCatalog::saasFunnelEvents();

            foreach ($events as $entry) {
                expect($entry['category'])->toBe('saas');
            }
        });
    });

    describe('EventContextBuilder — new methods', function () {
        it('withReferrer returns self for chaining', function () {
            $builder = new EventContextBuilder(null);

            $result = $builder->withReferrer();

            expect($result)->toBeInstanceOf(EventContextBuilder::class);
        });

        it('withReferrer parses referrer URL components', function () {
            $builder = new EventContextBuilder(null);
            $builder->withReferrer('https://www.google.com/search?q=laravel+analytics&hl=en');

            expect($builder->get('referrer_url'))->toBe('https://www.google.com/search?q=laravel+analytics&hl=en');
            expect($builder->get('referrer_host'))->toBe('www.google.com');
            expect($builder->get('referrer_search_term'))->toBe('laravel analytics');
            expect($builder->get('referrer_search_engine'))->toBe('Google');
        });

        it('withReferrer detects Bing search', function () {
            $builder = new EventContextBuilder(null);
            $builder->withReferrer('https://www.bing.com/search?q=php+package');

            expect($builder->get('referrer_search_engine'))->toBe('Bing');
            expect($builder->get('referrer_search_term'))->toBe('php package');
        });

        it('withReferrer handles null/empty gracefully', function () {
            $builder = new EventContextBuilder(null);
            $builder->withReferrer(null);

            expect($builder->get('referrer_url'))->toBeNull();
            expect($builder->get('referrer_host'))->toBeNull();
        });

        it('withTenancy returns self for chaining', function () {
            $builder = new EventContextBuilder(null);

            $result = $builder->withTenancy();

            expect($result)->toBeInstanceOf(EventContextBuilder::class);
        });

        it('withTenancy accepts override values', function () {
            $builder = new EventContextBuilder(null);
            $builder->withTenancy('tenant_42', 'Acme Corp');

            expect($builder->get('tenant_id'))->toBe('tenant_42');
            expect($builder->get('tenant_name'))->toBe('Acme Corp');
        });

        it('withTenancy with only tenantId sets correctly', function () {
            $builder = new EventContextBuilder(null);
            $builder->withTenancy('org_99');

            expect($builder->get('tenant_id'))->toBe('org_99');
            expect($builder->get('tenant_name'))->toBeNull();
        });

        it('buildFull includes withReferrer and withTenancy', function () {
            $builder = new EventContextBuilder(null);
            $context = $builder->withReferrer('https://google.com/search?q=test')
                ->withTenancy('t1', 'Tenant 1')
                ->getContext();

            expect($context['referrer_url'])->toBe('https://google.com/search?q=test');
            expect($context['tenant_id'])->toBe('t1');
            expect($context['tenant_name'])->toBe('Tenant 1');
        });
    });

    describe('Inertia middleware — consentVersion prop', function () {
        it('HandleInertiaAnalytics class has handle method', function () {
            expect(method_exists(
                \ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class,
                'handle',
            ))->toBeTrue();
        });
    });

    describe('Config — revenue.subscription_tiers', function () {
        it('revenue config has subscription_tiers key', function () {
            $config = require __DIR__.'/../config/zeroboiler.php';
            $revenue = $config['analytics']['revenue'];

            expect(array_key_exists('subscription_tiers', $revenue))->toBeTrue();
            expect(is_array($revenue['subscription_tiers']))->toBeTrue();
        });

        it('revenue config has currency and billing_cycle_default', function () {
            $config = require __DIR__.'/../config/zeroboiler.php';
            $revenue = $config['analytics']['revenue'];

            expect($revenue['currency'])->toBe('USD');
            expect($revenue['billing_cycle_default'])->toBe('monthly');
        });
    });

    describe('ServerSideTracker — subscription.paused mapping', function () {
        it('ServerSideTracker has subscription.paused in customEventMap', function () {
            $mapProperty = new ReflectionProperty(
                \ZeroBoiler\Analytics\Tracking\ServerSideTracker::class,
                'customEventMap',
            );

            $tracker = new \ZeroBoiler\Analytics\Tracking\ServerSideTracker(
                new \ZeroBoiler\Analytics\AnalyticsManager(null),
                app(\Illuminate\Contracts\Config\Repository::class),
            );

            $customMap = $mapProperty->getValue($tracker);
            expect(array_key_exists('subscription.paused', $customMap))->toBeTrue();
            expect($customMap['subscription.paused'])->toBe(SubscriptionPausedEvent::class);
        });
    });

    describe('Event counts updated', function () {
        it('SaaSEvents has 43 events', function () {
            expect(SaaSEvents::count())->toBeGreaterThanOrEqual(43);
        });

        it('EngagementEvents has 25 events', function () {
            expect(EngagementEvents::count())->toBeGreaterThanOrEqual(25);
        });

        it('EventCatalog total is 81', function () {
            expect(EventCatalog::count())->toBeGreaterThanOrEqual(81);
        });

        it('EventCatalog validate passes', function () {
            $result = EventCatalog::validate();

            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toBeEmpty();
        });
    });

    describe('JS client version consistency', function () {
        it('JS client version is 2.74.0', function () {
            $js = file_get_contents(__DIR__.'/../resources/js/analytics.js');
            expect(str_contains($js, "'5.9.0'"))->toBeTrue();
            // Should have 3 version references
            $count = substr_count($js, "'5.9.0'");
            expect($count)->toBeGreaterThanOrEqual(3);
        });

        it('TypeScript definitions version is 2.74.0', function () {
            $ts = file_get_contents(__DIR__.'/../resources/js/analytics.d.ts');
            expect(str_contains($ts, '5.9.0'))->toBeTrue();
        });

        it('TypeScript definitions have EcommerceConfig interface', function () {
            $ts = file_get_contents(__DIR__.'/../resources/js/analytics.d.ts');
            expect(str_contains($ts, 'EcommerceConfig'))->toBeTrue();
            expect(str_contains($ts, 'consentVersion'))->toBeTrue();
        });
    });

    describe('Full SaaS Starter Checklist — updated', function () {
        it('1. Event Catalog — 3 categories with typed classes (81 total)', function () {
            expect(EcommerceEvents::count())->toBe(13);
            expect(SaaSEvents::count())->toBeGreaterThanOrEqual(43);
            expect(EngagementEvents::count())->toBeGreaterThanOrEqual(25);

            $total = EventCatalog::count();
            expect($total)->toBeGreaterThanOrEqual(81);
        });

        it('2. Server-Side Lifecycle Tracker — config-driven with pause mapping', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Tracking\ServerSideTracker::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Services\LifecycleEventMapper::class))->toBeTrue();
        });

        it('3. Inertia middleware — full props with consent version', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class))->toBeTrue();
        });

        it('4. API controller + routes', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class))->toBeTrue();
            expect(file_exists(__DIR__.'/../routes/analytics.php'))->toBeTrue();

            $routeContent = file_get_contents(__DIR__.'/../routes/analytics.php');
            expect(str_contains($routeContent, 'events'))->toBeTrue();
            expect(str_contains($routeContent, 'batch'))->toBeTrue();
            expect(str_contains($routeContent, 'identify'))->toBeTrue();
            expect(str_contains($routeContent, 'consent'))->toBeTrue();
        });

        it('5. JS client with updated version', function () {
            expect(file_exists(__DIR__.'/../resources/js/analytics.js'))->toBeTrue();
            expect(file_exists(__DIR__.'/../resources/js/analytics.d.ts'))->toBeTrue();

            $js = file_get_contents(__DIR__.'/../resources/js/analytics.js');
            expect(strlen($js))->toBeGreaterThan(5000);
        });

        it('6. Event queue', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Queue\EventReplayQueue::class))->toBeTrue();
        });

        it('7. User identity linking', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Tracking\UserIdentityTracker::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Tracking\AnonymousIdTracker::class))->toBeTrue();
        });

        it('8. E-commerce helpers', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Support\EcommerceFormatConverter::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Services\EcommerceAnalyticsService::class))->toBeTrue();
        });

        it('9. Admin commands', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthCommand::class))->toBeTrue();
        });

        it('10. Config expansion — all required sections including revenue.subscription_tiers', function () {
            $config = require __DIR__.'/../config/zeroboiler.php';
            $analytics = $config['analytics'];

            $requiredSections = [
                'ga4', 'gtm', 'meta_pixel', 'consent', 'auto_track',
                'queue', 'identity', 'ecommerce', 'api', 'plausible',
                'posthog', 'webhook', 'debug', 'validation', 'pipeline',
                'sampling', 'pii_sanitization', 'replay', 'metrics',
                'stream', 'client_auto_track', 'performance',
                'tracking_preference', 'dedup', 'gdpr', 'attribution',
                'profile', 'inbound_webhook', 'funnels', 'alerts',
                'lifecycle', 'correlation', 'retention', 'source_tagging',
                'broadcast', 'tenant', 'reporting', 'dead_letter_queue',
                'realtime', 'ab_tests', 'snapshots', 'saas_kpi',
            ];

            foreach ($requiredSections as $section) {
                expect(array_key_exists($section, $analytics))
                    ->toBeTrue(), "Missing config section: {$section}";
            }
        });

        it('11. Optional providers — Plausible + PostHog', function () {
            expect(class_exists(\ZeroBoiler\Analytics\Trackers\PlausibleTracker::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Trackers\PosthogTracker::class))->toBeTrue();
        });

        it('12. Tests — 80+ test files', function () {
            $testFiles = glob(__DIR__.'/*.php');
            expect(count($testFiles))->toBeGreaterThan(80);
        });
    });

    describe('Cross-cutting quality checks', function () {
        it('all PHP files use strict types', function () {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $violations = [];

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                if (! str_contains($content, 'declare(strict_types=1)')) {
                    $violations[] = str_replace(__DIR__.'/../', '', $file);
                }
            }

            expect($violations)->toBeEmpty(), implode(', ', $violations);
        });

        it('all PHP files have license header', function () {
            $srcFiles = glob(__DIR__.'/../src/**/*.php');
            $violations = [];

            foreach ($srcFiles as $file) {
                $content = file_get_contents($file);
                if (! str_contains($content, 'ZeroBoiler, licensed under the MIT license')) {
                    $violations[] = str_replace(__DIR__.'/../', '', $file);
                }
            }

            expect($violations)->toBeEmpty(), implode(', ', $violations);
        });

        it('key classes are final', function () {
            $finalClasses = [
                \ZeroBoiler\Analytics\AnalyticsManager::class,
                \ZeroBoiler\Analytics\DTO\AnalyticsEvent::class,
                \ZeroBoiler\Analytics\DTO\ConsentState::class,
                \ZeroBoiler\Analytics\Events\EventCatalog::class,
                \ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics::class,
                \ZeroBoiler\Analytics\Tracking\ServerSideTracker::class,
                \ZeroBoiler\Analytics\Context\EventContextBuilder::class,
            ];

            foreach ($finalClasses as $class) {
                $ref = new ReflectionClass($class);
                expect($ref->isFinal())
                    ->toBeTrue(), "Class {$class} should be final";
            }
        });

        it('new event classes are final readonly', function () {
            $ref = new ReflectionClass(SubscriptionPausedEvent::class);
            expect($ref->isFinal())->toBeTrue();
            expect($ref->isReadOnly())->toBeTrue();

            $ref2 = new ReflectionClass(FeatureRequestEvent::class);
            expect($ref2->isFinal())->toBeTrue();
            expect($ref2->isReadOnly())->toBeTrue();
        });
    });
});
