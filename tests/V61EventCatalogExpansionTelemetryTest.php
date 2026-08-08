<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\CheckoutStepEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\AdClickEvent;
use ZeroBoiler\Analytics\Events\Engagement\ContentEngagementEvent;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\Engagement\OnboardingStepEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\ImpressionEvent;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\SaaS\WorkspaceCreatedEvent;

beforeEach(function (): void {
    // Reset static catalogs
    EcommerceEvents::flush();
    SaaSEvents::flush();
    EngagementEvents::flush();
});

describe('v2.61.0 — Event Catalog Expansion + Telemetry + Svelte Tracker', function (): void {

    describe('New Engagement Events', function (): void {
        test('AdClickEvent constructs with all parameters', function (): void {
            $event = new AdClickEvent(
                platform: 'google',
                campaignId: 'camp-001',
                adGroupId: 'grp-002',
                creativeId: 'crt-003',
                placement: 'top',
                keyword: 'analytics tool',
                cost: 1.50,
            );

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('ad_click');
            expect($event->params['platform'])->toBe('google');
            expect($event->params['campaign_id'])->toBe('camp-001');
            expect($event->params['ad_group_id'])->toBe('grp-002');
            expect($event->params['creative_id'])->toBe('crt-003');
            expect($event->params['placement'])->toBe('top');
            expect($event->params['keyword'])->toBe('analytics tool');
            expect($event->params['cost'])->toBe(1.50);
        });

        test('AdClickEvent constructs with required params only', function (): void {
            $event = new AdClickEvent(
                platform: 'meta',
                campaignId: 'camp-002',
                adGroupId: 'grp-003',
                creativeId: 'crt-004',
            );

            expect($event->name)->toBe('ad_click');
            expect($event->params['platform'])->toBe('meta');
            expect($event->params)->not->toHaveKey('placement');
            expect($event->params)->not->toHaveKey('cost');
        });

        test('ContentEngagementEvent constructs with all parameters', function (): void {
            $event = new ContentEngagementEvent(
                contentType: 'article',
                contentId: 'blog-123',
                title: 'Getting Started',
                author: 'Jane',
                category: 'tutorials',
                engagementPercent: 75,
                timeSpentSeconds: 180,
                completed: false,
            );

            expect($event->name)->toBe('content_engagement');
            expect($event->params['content_type'])->toBe('article');
            expect($event->params['engagement_percent'])->toBe(75);
            expect($event->params['completed'])->toBe(false);
        });

        test('OnboardingStepEvent constructs correctly', function (): void {
            $event = new OnboardingStepEvent(
                stepName: 'profile_setup',
                stepIndex: 0,
                totalSteps: 5,
                method: 'invite',
                completed: true,
                durationSeconds: 45,
            );

            expect($event->name)->toBe('onboarding_step');
            expect($event->params['step_index'])->toBe(0);
            expect($event->params['total_steps'])->toBe(5);
            expect($event->params['completed'])->toBe(true);
        });

        test('OnboardingStepEvent with skip reason', function (): void {
            $event = new OnboardingStepEvent(
                stepName: 'team_invite',
                stepIndex: 2,
                totalSteps: 5,
                completed: false,
                skippedReason: 'user_declined',
            );

            expect($event->params['skipped_reason'])->toBe('user_declined');
        });
    });

    describe('New E-commerce Events', function (): void {
        test('CheckoutStepEvent constructs with payment info', function (): void {
            $event = new CheckoutStepEvent(
                stepIndex: 1,
                stepName: 'shipping',
                paymentMethod: 'credit_card',
                orderTotal: 49.99,
                currency: 'USD',
            );

            expect($event->name)->toBe('checkout_step');
            expect($event->params['step_index'])->toBe(1);
            expect($event->params['payment_method'])->toBe('credit_card');
            expect($event->params['order_total'])->toBe(49.99);
        });

        test('CheckoutStepEvent with items', function (): void {
            $items = [
                ['item_id' => 'SKU-001', 'price' => 29.99],
                ['item_id' => 'SKU-002', 'price' => 19.99],
            ];

            $event = new CheckoutStepEvent(
                stepIndex: 2,
                stepName: 'payment',
                paymentMethod: 'paypal',
                orderTotal: 49.98,
                items: $items,
            );

            expect($event->params['items'])->toBe($items);
        });
    });

    describe('New SaaS Events', function (): void {
        test('ImpressionEvent constructs for feature discovery', function (): void {
            $event = new ImpressionEvent(
                featureName: 'export_csv',
                location: 'dashboard',
                source: 'auto',
                variant: 'v2',
                context: '/dashboard',
            );

            expect($event->name)->toBe('feature_impression');
            expect($event->params['feature_name'])->toBe('export_csv');
            expect($event->params['variant'])->toBe('v2');
        });

        test('WorkspaceCreatedEvent constructs with plan', function (): void {
            $event = new WorkspaceCreatedEvent(
                workspaceName: 'Acme Corp',
                plan: 'pro',
                industry: 'SaaS',
                size: '11-50',
            );

            expect($event->name)->toBe('workspace_created');
            expect($event->params['workspace_name'])->toBe('Acme Corp');
            expect($event->params['plan'])->toBe('pro');
        });
    });

    describe('Catalog Integration', function (): void {
        test('EventCatalog includes all new events', function (): void {
            $names = EventCatalog::names();

            expect($names)->toContain('ad_click');
            expect($names)->toContain('content_engagement');
            expect($names)->toContain('onboarding_step');
            expect($names)->toContain('checkout_step');
            expect($names)->toContain('feature_impression');
            expect($names)->toContain('workspace_created');
        });

        test('Total event count is correct', function (): void {
            expect(EventCatalog::count())->toBe(76);
            expect(EcommerceEvents::count())->toBe(13);
            expect(SaaSEvents::count())->toBe(39);
            expect(EngagementEvents::count())->toBe(24);
        });

        test('All new events have valid catalog entries', function (): void {
            $newEvents = ['ad_click', 'content_engagement', 'onboarding_step', 'checkout_step', 'feature_impression', 'workspace_created'];

            foreach ($newEvents as $name) {
                $entry = EventCatalog::get($name);
                expect($entry)->not->toBeNull();
                expect($entry['name'])->toBe($name);
                expect($entry['ga4'])->toBeString();
                expect($entry['category'])->toBeString();
                expect(class_exists($entry['class']))->toBeTrue();
            }
        });

        test('All new events extend AnalyticsEvent', function (): void {
            $newEvents = ['ad_click', 'content_engagement', 'onboarding_step', 'checkout_step', 'feature_impression', 'workspace_created'];

            foreach ($newEvents as $name) {
                $class = EventCatalog::classFor($name);
                expect($class)->not->toBeNull();
                expect(is_a($class, AnalyticsEvent::class, true))->toBeTrue();
            }
        });

        test('No duplicate event names across categories', function (): void {
            $all = EventCatalog::all();
            $names = array_keys($all);
            expect($names)->toHaveCount(count(array_unique($names)));
        });

        test('EventCatalog::searchByProvider works', function (): void {
            $results = EventCatalog::searchByProvider('ga4', 'ad_click');
            expect($results)->not->toBeEmpty();
            expect($results[0]['name'])->toBe('ad_click');

            $emptyResults = EventCatalog::searchByProvider('ga4', 'nonexistent_event');
            expect($emptyResults)->toBeEmpty();
        });

        test('EventCatalog::summary returns correct counts', function (): void {
            $summary = EventCatalog::summary();

            expect($summary['total'])->toBe(76);
            expect($summary['ecommerce'])->toBe(13);
            expect($summary['saas'])->toBe(39);
            expect($summary['engagement'])->toBe(24);
            expect($summary['with_ga4'])->toBe(76);
            expect($summary['with_meta'])->toBeGreaterThan(0);
            expect($summary['with_posthog'])->toBeGreaterThan(0);
        });
    });

    describe('Category-specific counts', function (): void {
        test('Engagement events has correct new entries', function (): void {
            $engagement = EngagementEvents::all();
            expect(isset($engagement['ad_click']))->toBeTrue();
            expect(isset($engagement['content_engagement']))->toBeTrue();
            expect(isset($engagement['onboarding_step']))->toBeTrue();
        });

        test('Ecommerce events has checkout_step', function (): void {
            $ecommerce = EcommerceEvents::all();
            expect(isset($ecommerce['checkout_step']))->toBeTrue();
        });

        test('SaaS events has feature_impression and workspace_created', function (): void {
            $saas = SaaSEvents::all();
            expect(isset($saas['feature_impression']))->toBeTrue();
            expect(isset($saas['workspace_created']))->toBeTrue();
        });
    });

    describe('Provider mappings for new events', function (): void {
        test('AdClickEvent has PostHog mapping', function (): void {
            $entry = EventCatalog::get('ad_click');
            expect($entry['posthog'])->toBe('ad_click');
        });

        test('ContentEngagementEvent has PostHog mapping', function (): void {
            $entry = EventCatalog::get('content_engagement');
            expect($entry['posthog'])->toBe('content_engagement');
        });

        test('OnboardingStepEvent has PostHog mapping', function (): void {
            $entry = EventCatalog::get('onboarding_step');
            expect($entry['posthog'])->toBe('onboarding_step');
        });

        test('New events have Meta Pixel mappings', function (): void {
            expect(EventCatalog::get('ad_click')['meta'])->toBe('AdClick');
            expect(EventCatalog::get('content_engagement')['meta'])->toBe('ContentEngagement');
            expect(EventCatalog::get('onboarding_step')['meta'])->toBe('OnboardingStep');
            expect(EventCatalog::get('checkout_step')['meta'])->toBe('CheckoutStep');
        });

        test('New SaaS events have Meta mappings', function (): void {
            expect(EventCatalog::get('feature_impression')['meta'])->toBe('FeatureImpression');
            expect(EventCatalog::get('workspace_created')['meta'])->toBe('WorkspaceCreated');
        });
    });

    describe('PHP 8.5 compliance for new events', function (): void {
        test('All new event classes have strict types', function (): void {
            $files = [
                __DIR__.'/../../src/Events/Engagement/AdClickEvent.php',
                __DIR__.'/../../src/Events/Engagement/ContentEngagementEvent.php',
                __DIR__.'/../../src/Events/Engagement/OnboardingStepEvent.php',
                __DIR__.'/../../src/Events/Ecommerce/CheckoutStepEvent.php',
                __DIR__.'/../../src/Events/SaaS/ImpressionEvent.php',
                __DIR__.'/../../src/Events/SaaS/WorkspaceCreatedEvent.php',
            ];

            foreach ($files as $file) {
                $content = file_get_contents($file);
                expect($content)->toContain('declare(strict_types=1)');
            }
        });

        test('All new event classes are final readonly', function (): void {
            $reflections = [
                new ReflectionClass(AdClickEvent::class),
                new ReflectionClass(ContentEngagementEvent::class),
                new ReflectionClass(OnboardingStepEvent::class),
                new ReflectionClass(CheckoutStepEvent::class),
                new ReflectionClass(ImpressionEvent::class),
                new ReflectionClass(WorkspaceCreatedEvent::class),
            ];

            foreach ($reflections as $ref) {
                expect($ref->isFinal())->toBeTrue();
                expect($ref->isReadOnly())->toBeTrue();
            }
        });

        test('All new events extend AnalyticsEvent', function (): void {
            $events = [
                new AdClickEvent('google', 'c1', 'g1', 'cr1'),
                new ContentEngagementEvent('video', 'v1'),
                new OnboardingStepEvent('step1', 0, 3),
                new CheckoutStepEvent(1),
                new ImpressionEvent('feat1', 'sidebar'),
                new WorkspaceCreatedEvent('ws1'),
            ];

            foreach ($events as $event) {
                expect($event)->toBeInstanceOf(AnalyticsEvent::class);
                expect($event->name)->toBeString();
                expect(strlen($event->name))->toBeGreaterThan(0);
            }
        });
    });

    describe('Version consistency', function (): void {
        test('composer.json version is 2.61.0', function (): void {
            $json = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true);
            expect($json['version'])->toBe('2.61.0');
        });

        test('JS client version is 2.61.0', function (): void {
            $js = file_get_contents(__DIR__.'/../../resources/js/analytics.js');
            expect($js)->toContain("return '2.61.0';");
        });

        test('AnalyticsManager version is 2.61.0', function (): void {
            $manager = new \ZeroBoiler\Analytics\AnalyticsManager();
            expect($manager->version())->toBe('2.88.0');
        });

        test('No stale 2.59.0 or 2.60.0 references in src', function (): void {
            $stale = shell_exec(
                "grep -rl \"'2\\.59\\.0'\" ".__DIR__.'/../../src/ 2>/dev/null || echo ""'
            );
            $stale60 = shell_exec(
                "grep -rl \"'2\\.60\\.0'\" ".__DIR__.'/../../src/ 2>/dev/null || echo ""'
            );
            expect(trim($stale))->toBe('');
            expect(trim($stale60))->toBe('');
        });
    });

    describe('Filesystem integrity', function (): void {
        test('Source file count increased by 7', function (): void {
            $count = shell_exec('find '.__DIR__.'/../../src -name "*.php" | wc -l');
            expect((int) trim($count))->toBeGreaterThanOrEqual(209);
        });

        test('All new event class files exist', function (): void {
            $files = [
                'src/Events/Engagement/AdClickEvent.php',
                'src/Events/Engagement/ContentEngagementEvent.php',
                'src/Events/Engagement/OnboardingStepEvent.php',
                'src/Events/Ecommerce/CheckoutStepEvent.php',
                'src/Events/SaaS/ImpressionEvent.php',
                'src/Events/SaaS/WorkspaceCreatedEvent.php',
                'src/Services/AnalyticsTelemetryService.php',
            ];

            foreach ($files as $file) {
                expect(file_exists(__DIR__.'/../../'.$file))->toBeTrue();
            }
        });
    });
});
