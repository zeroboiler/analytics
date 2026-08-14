<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\CustomerSuccessEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEventSubCategories;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Services\CustomerSuccessAnalyticsService;
use ZeroBoiler\Analytics\Services\FeatureGatingAnalyticsService;

beforeEach(function (): void {
    $this->version = '144.0.0';
});

describe('Phase 37 — Version & Metadata Consistency', function (): void {
    test('AnalyticsEvent VERSION matches package version', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe($this->version);
    });

    test('composer.json version matches package version', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['version'])->toBe($this->version);
    });

    test('composer.json requires PHP ^8.5', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['require']['php'])->toBe('^8.5');
    });

    test('composer.json requires illuminate/contracts ^13.0', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
        expect($composer['require']['illuminate/contracts'])->toBe('^13.0');
    });
});

describe('Phase 37 — Customer Success Event Timestamp Type Safety', function (): void {
    test('all 7 customer success events accept DateTimeImmutable timestamp', function (): void {
        $timestamp = new \DateTimeImmutable('2025-01-15T10:30:00Z');
        $events = [
            new \ZeroBoiler\Analytics\Events\SaaS\SupportTicketCreatedEvent(timestamp: $timestamp),
            new \ZeroBoiler\Analytics\Events\SaaS\NpsSubmittedEvent(timestamp: $timestamp),
            new \ZeroBoiler\Analytics\Events\SaaS\HealthScoreChangedEvent(timestamp: $timestamp),
            new \ZeroBoiler\Analytics\Events\SaaS\RenewalReminderSentEvent(timestamp: $timestamp),
            new \ZeroBoiler\Analytics\Events\SaaS\ChurnInterviewEvent(timestamp: $timestamp),
            new \ZeroBoiler\Analytics\Events\SaaS\CustomerReviewEvent(timestamp: $timestamp),
            new \ZeroBoiler\Analytics\Events\SaaS\OnboardingCallCompletedEvent(timestamp: $timestamp),
        ];

        foreach ($events as $i => $event) {
            $name = $event->name;
            expect($event->timestamp)->toBeInstanceOf(\DateTimeImmutable::class, "Event at index {$i} ({$name}) should have DateTimeImmutable timestamp");
            expect($event->timestamp->format('Y-m-d\TH:i:s\Z'))->toBe('2025-01-15T10:30:00Z');
        }
    });

    test('all 7 CS events accept null timestamp (default)', function (): void {
        $events = [
            new \ZeroBoiler\Analytics\Events\SaaS\SupportTicketCreatedEvent(),
            new \ZeroBoiler\Analytics\Events\SaaS\NpsSubmittedEvent(),
            new \ZeroBoiler\Analytics\Events\SaaS\HealthScoreChangedEvent(),
            new \ZeroBoiler\Analytics\Events\SaaS\RenewalReminderSentEvent(),
            new \ZeroBoiler\Analytics\Events\SaaS\ChurnInterviewEvent(),
            new \ZeroBoiler\Analytics\Events\SaaS\CustomerReviewEvent(),
            new \ZeroBoiler\Analytics\Events\SaaS\OnboardingCallCompletedEvent(),
        ];

        foreach ($events as $event) {
            expect($event->timestamp)->toBeNull();
        }
    });
});

describe('Phase 37 — CustomerSuccessEvents Catalog Integrity', function (): void {
    test('has exactly 7 events', function (): void {
        expect(CustomerSuccessEvents::count())->toBe(7);
    });

    test('all events exist in the unified EventCatalog', function (): void {
        $catalog = EventCatalog::all();
        foreach (CustomerSuccessEvents::names() as $name) {
            expect($catalog)->toHaveKey($name);
            expect($catalog[$name]['category'])->toBe('saas');
        }
    });

    test('each event entry has all 10 required provider fields', function (): void {
        $requiredFields = ['name', 'class', 'ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

        foreach (CustomerSuccessEvents::all() as $eventName => $entry) {
            foreach ($requiredFields as $field) {
                expect($entry)->toHaveKey($field, "Event '{$eventName}' missing field '{$field}'");
            }
        }
    });

    test('event classes are instantiable and extend AnalyticsEvent', function (): void {
        foreach (CustomerSuccessEvents::all() as $eventName => $entry) {
            $class = $entry['class'];
            expect(class_exists($class))->toBeTrue("Class {$class} for event '{$eventName}' must exist");
            $event = new $class();
            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe($eventName);
        }
    });
});

describe('Phase 37 — SaaSEventSubCategories Completeness', function (): void {
    test('password_reset_requested is in auth sub-category', function (): void {
        expect(SaaSEventSubCategories::belongsTo('password_reset_requested', 'auth'))->toBeTrue();
    });

    test('payment_method_removed is in billing sub-category', function (): void {
        expect(SaaSEventSubCategories::belongsTo('payment_method_removed', 'billing'))->toBeTrue();
    });

    test('customer_success sub-category has exactly 7 events', function (): void {
        expect(SaaSEventSubCategories::events('customer_success'))->toHaveCount(7);
    });

    test('all CS events are in customer_success sub-category', function (): void {
        foreach (CustomerSuccessEvents::names() as $name) {
            expect(SaaSEventSubCategories::belongsTo($name, 'customer_success'))->toBeTrue();
        }
    });

    test('all sub-category events exist in SaaS catalog', function (): void {
        foreach (SaaSEventSubCategories::all() as $subcategory => $events) {
            foreach ($events as $event) {
                expect(SaaSEvents::has($event))->toBeTrue("Event '{$event}' in sub-category '{$subcategory}' must exist in SaaS catalog");
            }
        }
    });
});

describe('Phase 37 — CustomerSuccessAnalyticsService', function (): void {
    test('catalogSummary returns expected structure', function (): void {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $service = new CustomerSuccessAnalyticsService($cache);
        $summary = $service->catalogSummary();

        expect($summary)->toHaveKey('count');
        expect($summary)->toHaveKey('events');
        expect($summary)->toHaveKey('categories');
        expect($summary['count'])->toBe(7);
    });

    test('classifyNps boundaries are correct', function (): void {
        expect(CustomerSuccessAnalyticsService::classifyNps(9))->toBe('promoter');
        expect(CustomerSuccessAnalyticsService::classifyNps(10))->toBe('promoter');
        expect(CustomerSuccessAnalyticsService::classifyNps(7))->toBe('passive');
        expect(CustomerSuccessAnalyticsService::classifyNps(8))->toBe('passive');
        expect(CustomerSuccessAnalyticsService::classifyNps(6))->toBe('detractor');
        expect(CustomerSuccessAnalyticsService::classifyNps(0))->toBe('detractor');
    });

    test('calculateNps is mathematically correct', function (): void {
        expect(CustomerSuccessAnalyticsService::calculateNps([]))->toBe(0);
        expect(CustomerSuccessAnalyticsService::calculateNps([9, 10, 10]))->toBe(100);
        expect(CustomerSuccessAnalyticsService::calculateNps([0, 3, 6]))->toBe(-100);
    });

    test('computeHealthSignal clamps to [-1.0, 1.0]', function (): void {
        $signal = CustomerSuccessAnalyticsService::computeHealthSignal([
            'churn_interview' => 100,
            'support_ticket_created' => 100,
        ]);
        expect($signal)->toBeGreaterThanOrEqual(-1.0);
        expect($signal)->toBeLessThanOrEqual(1.0);
    });

    test('assessChurnRisk returns valid structure with correct types', function (): void {
        $result = CustomerSuccessAnalyticsService::assessChurnRisk(0.5, 8, 3);
        expect($result)->toHaveKeys(['level', 'score', 'factors']);
        expect($result['level'])->toBeString();
        expect($result['score'])->toBeFloat();
        expect($result['factors'])->toBeArray();
        expect(in_array($result['level'], ['low', 'medium', 'high', 'critical'], true))->toBeTrue();
    });

    test('kpiSummary returns complete 4-section structure', function (): void {
        $summary = CustomerSuccessAnalyticsService::kpiSummary([
            'avg_nps' => 42, 'total_tickets_30d' => 90,
            'avg_health_score' => 72.5, 'renewal_rate' => 0.92, 'churn_rate' => 0.05,
        ]);

        expect($summary)->toHaveKeys(['nps', 'support_velocity', 'health', 'retention']);
        expect($summary['nps'])->toHaveKeys(['value', 'classification']);
        expect($summary['support_velocity'])->toHaveKeys(['total_30d', 'daily_avg']);
        expect($summary['health'])->toHaveKeys(['avg_score', 'trend']);
        expect($summary['retention'])->toHaveKeys(['renewal_rate', 'churn_rate']);
    });
});

describe('Phase 37 — FeatureGatingAnalyticsService', function (): void {
    test('disabled by default allows all events', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->andReturn([]);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnNull();

        $service = new FeatureGatingAnalyticsService($config, $cache);
        expect($service->isEnabled())->toBeFalse();
        expect($service->isEventAllowed('any_event', 'any_plan'))->toBeTrue();
    });

    test('ungated events are always allowed', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->andReturn([]);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnNull();

        $service = new FeatureGatingAnalyticsService($config, $cache);
        expect($service->getUngatedEvents())->toHaveCount(16);
    });

    test('plan hierarchy is correct', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->andReturn([]);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnNull();

        $service = new FeatureGatingAnalyticsService($config, $cache);
        expect($service->getPlanHierarchy())->toEqual(['free', 'starter', 'pro', 'enterprise']);
    });

    test('isPlanAtOrAbove returns correct comparisons', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->andReturn([]);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnNull();

        $service = new FeatureGatingAnalyticsService($config, $cache);
        expect($service->isPlanAtOrAbove('pro', 'free'))->toBeTrue();
        expect($service->isPlanAtOrAbove('pro', 'pro'))->toBeTrue();
        expect($service->isPlanAtOrAbove('pro', 'enterprise'))->toBeFalse();
        expect($service->isPlanAtOrAbove('enterprise', 'free'))->toBeTrue();
    });

    test('summary returns complete structure', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->andReturn([]);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnNull();

        $service = new FeatureGatingAnalyticsService($config, $cache);
        $summary = $service->summary();
        expect($summary)->toHaveKeys(['enabled', 'plan_count', 'ungated_count', 'premium_categories', 'hierarchy']);
        expect($summary['plan_count'])->toBe(4);
    });

    test('filterAllowedEvents returns all when disabled', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->andReturn([]);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnNull();

        $service = new FeatureGatingAnalyticsService($config, $cache);
        $events = ['a', 'b', 'c'];
        expect($service->filterAllowedEvents($events, 'free'))->toEqual($events);
    });
});

describe('Phase 37 — ServiceProvider Registration', function (): void {
    test('ServiceProvider file references CustomerSuccessAnalyticsService', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/AnalyticsServiceProvider.php');
        expect($content)->toContain('CustomerSuccessAnalyticsService');
    });

    test('ServiceProvider file references FeatureGatingAnalyticsService', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/AnalyticsServiceProvider.php');
        expect($content)->toContain('FeatureGatingAnalyticsService');
    });

    test('ServiceProvider registers both services as singletons', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/AnalyticsServiceProvider.php');
        expect($content)->toContain("singleton(CustomerSuccessAnalyticsService::class");
        expect($content)->toContain("singleton(FeatureGatingAnalyticsService::class");
    });
});

describe('Phase 37 — Controller Method Existence', function (): void {
    test('AnalyticsEventController has customerSuccessCatalog method', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Http/Controllers/AnalyticsEventController.php');
        expect($content)->toContain('public function customerSuccessCatalog()');
    });

    test('AnalyticsEventController has customerSuccessKpi method', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Http/Controllers/AnalyticsEventController.php');
        expect($content)->toContain('public function customerSuccessKpi(');
    });

    test('AnalyticsEventController has customerSuccessChurnRisk method', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Http/Controllers/AnalyticsEventController.php');
        expect($content)->toContain('public function customerSuccessChurnRisk(');
    });

    test('AnalyticsEventController has featureGatingEligibility method', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Http/Controllers/AnalyticsEventController.php');
        expect($content)->toContain('public function featureGatingEligibility(');
    });

    test('AnalyticsEventController has featureGatingPlans method', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Http/Controllers/AnalyticsEventController.php');
        expect($content)->toContain('public function featureGatingPlans()');
    });

    test('AnalyticsEventController has featureGatingCheck method', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Http/Controllers/AnalyticsEventController.php');
        expect($content)->toContain('public function featureGatingCheck(');
    });
});

describe('Phase 37 — Config Structure', function (): void {
    test('config has feature_gating section', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config)->toHaveKey('analytics');
        expect($config['analytics'])->toHaveKey('feature_gating');
    });

    test('feature_gating has enabled, plan_hierarchy, premium_categories, plans', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        $fg = $config['analytics']['feature_gating'];
        expect($fg)->toHaveKeys(['enabled', 'plan_hierarchy', 'premium_categories', 'plans']);
    });

    test('config has customer_success section', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config['analytics'])->toHaveKey('customer_success');
    });

    test('customer_success has enabled, nps, health_score, renewal', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        $cs = $config['analytics']['customer_success'];
        expect($cs)->toHaveKeys(['enabled', 'nps', 'health_score', 'renewal']);
    });

    test('feature_gating plan_hierarchy has 4 tiers', function (): void {
        $config = include __DIR__ . '/../../config/zeroboiler.php';
        expect($config['analytics']['feature_gating']['plan_hierarchy'])->toHaveCount(4);
    });
});

describe('Phase 37 — Route Registration', function (): void {
    test('routes file has cs/catalog endpoint', function (): void {
        $content = file_get_contents(__DIR__ . '/../../routes/analytics.php');
        expect($content)->toContain("'cs/catalog'");
    });

    test('routes file has cs/kpi endpoint', function (): void {
        $content = file_get_contents(__DIR__ . '/../../routes/analytics.php');
        expect($content)->toContain("'cs/kpi'");
    });

    test('routes file has cs/churn-risk endpoint', function (): void {
        $content = file_get_contents(__DIR__ . '/../../routes/analytics.php');
        expect($content)->toContain("'cs/churn-risk'");
    });

    test('routes file has feature-gating endpoints', function (): void {
        $content = file_get_contents(__DIR__ . '/../../routes/analytics.php');
        expect($content)->toContain("'feature-gating/eligibility'");
        expect($content)->toContain("'feature-gating/plans'");
        expect($content)->toContain("'feature-gating/check'");
    });
});

describe('Phase 37 — Strict Types Coverage', function (): void {
    test('all 7 new CS event files have declare(strict_types=1)', function (): void {
        $files = [
            'SupportTicketCreatedEvent.php', 'NpsSubmittedEvent.php',
            'HealthScoreChangedEvent.php', 'RenewalReminderSentEvent.php',
            'ChurnInterviewEvent.php', 'CustomerReviewEvent.php',
            'OnboardingCallCompletedEvent.php',
        ];

        foreach ($files as $file) {
            $path = __DIR__ . '/../../src/Events/SaaS/' . $file;
            $content = file_get_contents($path);
            expect($content)->toContain('declare(strict_types=1)', "{$file} must have strict_types");
        }
    });

    test('CustomerSuccessEvents catalog has strict_types', function (): void {
        $content = file_get_contents(__DIR__ . '/../../src/Events/SaaS/CustomerSuccessEvents.php');
        expect($content)->toContain('declare(strict_types=1)');
    });

    test('both new services have strict_types', function (): void {
        expect(file_get_contents(__DIR__ . '/../../src/Services/CustomerSuccessAnalyticsService.php'))->toContain('declare(strict_types=1)');
        expect(file_get_contents(__DIR__ . '/../../src/Services/FeatureGatingAnalyticsService.php'))->toContain('declare(strict_types=1)');
    });
});

describe('Phase 37 — License Headers', function (): void {
    test('all new source files have MIT license header', function (): void {
        $files = [
            'src/Events/SaaS/SupportTicketCreatedEvent.php',
            'src/Events/SaaS/NpsSubmittedEvent.php',
            'src/Events/SaaS/HealthScoreChangedEvent.php',
            'src/Events/SaaS/RenewalReminderSentEvent.php',
            'src/Events/SaaS/ChurnInterviewEvent.php',
            'src/Events/SaaS/CustomerReviewEvent.php',
            'src/Events/SaaS/OnboardingCallCompletedEvent.php',
            'src/Events/SaaS/CustomerSuccessEvents.php',
            'src/Events/SaaS/SaaSEventSubCategories.php',
            'src/Services/CustomerSuccessAnalyticsService.php',
            'src/Services/FeatureGatingAnalyticsService.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents(__DIR__ . '/../../' . $file);
            expect($content)->toContain('licensed under the MIT license', "{$file} must have MIT license header");
        }
    });
});

describe('Phase 37 — TODO/FIXME Absence', function (): void {
    test('no TODO or FIXME markers in new files', function (): void {
        $files = [
            'src/Events/SaaS/SupportTicketCreatedEvent.php',
            'src/Events/SaaS/NpsSubmittedEvent.php',
            'src/Events/SaaS/HealthScoreChangedEvent.php',
            'src/Events/SaaS/RenewalReminderSentEvent.php',
            'src/Events/SaaS/ChurnInterviewEvent.php',
            'src/Events/SaaS/CustomerReviewEvent.php',
            'src/Events/SaaS/OnboardingCallCompletedEvent.php',
            'src/Events/SaaS/CustomerSuccessEvents.php',
            'src/Services/CustomerSuccessAnalyticsService.php',
            'src/Services/FeatureGatingAnalyticsService.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents(__DIR__ . '/../../' . $file);
            expect($content)->not->toContain('TODO', "{$file} should not contain TODO");
            expect($content)->not->toContain('FIXME', "{$file} should not contain FIXME");
        }
    });
});
