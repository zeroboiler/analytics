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
use ZeroBoiler\Analytics\Events\SaaS\SupportTicketCreatedEvent;
use ZeroBoiler\Analytics\Events\SaaS\NpsSubmittedEvent;
use ZeroBoiler\Analytics\Events\SaaS\HealthScoreChangedEvent;
use ZeroBoiler\Analytics\Events\SaaS\RenewalReminderSentEvent;
use ZeroBoiler\Analytics\Events\SaaS\ChurnInterviewEvent;
use ZeroBoiler\Analytics\Events\SaaS\CustomerReviewEvent;
use ZeroBoiler\Analytics\Events\SaaS\OnboardingCallCompletedEvent;
use ZeroBoiler\Analytics\Services\CustomerSuccessAnalyticsService;
use ZeroBoiler\Analytics\Services\FeatureGatingAnalyticsService;

beforeEach(function (): void {
    $this->catalog = SaaSEvents::all();
    $this->subCategories = SaaSEventSubCategories::all();
});

describe('CustomerSuccessEvents Catalog', function (): void {
    test('has 7 events in the catalog', function (): void {
        expect(CustomerSuccessEvents::count())->toBe(7);
    });

    test('contains all expected event names', function (): void {
        $names = CustomerSuccessEvents::names();
        expect($names)->toContain(
            'support_ticket_created',
            'nps_submitted',
            'health_score_changed',
            'renewal_reminder_sent',
            'churn_interview',
            'customer_review',
            'onboarding_call_completed',
        );
    });

    test('each event has all required provider fields', function (): void {
        $requiredFields = ['name', 'class', 'ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

        foreach (CustomerSuccessEvents::all() as $eventName => $entry) {
            foreach ($requiredFields as $field) {
                expect($entry)->toHaveKey($field);
            }
        }
    });

    test('events have correct class references', function (): void {
        expect(CustomerSuccessEvents::classFor('support_ticket_created'))->toBe(SupportTicketCreatedEvent::class);
        expect(CustomerSuccessEvents::classFor('nps_submitted'))->toBe(NpsSubmittedEvent::class);
        expect(CustomerSuccessEvents::classFor('health_score_changed'))->toBe(HealthScoreChangedEvent::class);
        expect(CustomerSuccessEvents::classFor('renewal_reminder_sent'))->toBe(RenewalReminderSentEvent::class);
        expect(CustomerSuccessEvents::classFor('churn_interview'))->toBe(ChurnInterviewEvent::class);
        expect(CustomerSuccessEvents::classFor('customer_review'))->toBe(CustomerReviewEvent::class);
        expect(CustomerSuccessEvents::classFor('onboarding_call_completed'))->toBe(OnboardingCallCompletedEvent::class);
    });

    test('category name is customer_success', function (): void {
        expect(CustomerSuccessEvents::category())->toBe('customer_success');
    });

    test('get returns null for unknown event', function (): void {
        expect(CustomerSuccessEvents::get('nonexistent_event'))->toBeNull();
    });

    test('has returns correct boolean', function (): void {
        expect(CustomerSuccessEvents::has('support_ticket_created'))->toBeTrue();
        expect(CustomerSuccessEvents::has('nonexistent_event'))->toBeFalse();
    });
});

describe('Customer Success Event Classes', function (): void {
    test('SupportTicketCreatedEvent instantiates with default name', function (): void {
        $event = new SupportTicketCreatedEvent();
        expect($event->name)->toBe('support_ticket_created');
        expect($event)->toBeInstanceOf(AnalyticsEvent::class);
    });

    test('NpsSubmittedEvent accepts score params', function (): void {
        $event = new NpsSubmittedEvent(params: ['score' => 9, 'category' => 'promoter']);
        expect($event->name)->toBe('nps_submitted');
        expect($event->params)->toBe(['score' => 9, 'category' => 'promoter']);
    });

    test('HealthScoreChangedEvent accepts previous and new scores', function (): void {
        $event = new HealthScoreChangedEvent(params: [
            'previous_score' => 65.0,
            'new_score' => 80.0,
            'reason' => 'increased_engagement',
        ]);
        expect($event->name)->toBe('health_score_changed');
        expect($event->params['new_score'])->toBe(80.0);
    });

    test('RenewalReminderSentEvent accepts channel and timing params', function (): void {
        $event = new RenewalReminderSentEvent(params: [
            'channel' => 'email',
            'days_until_renewal' => 14,
        ]);
        expect($event->name)->toBe('renewal_reminder_sent');
        expect($event->params['channel'])->toBe('email');
    });

    test('ChurnInterviewEvent accepts reason and feedback', function (): void {
        $event = new ChurnInterviewEvent(params: [
            'reason' => 'pricing',
            'feedback' => 'Too expensive for our team size',
            'competitor' => 'competitor_x',
        ]);
        expect($event->name)->toBe('churn_interview');
        expect($event->params['reason'])->toBe('pricing');
    });

    test('CustomerReviewEvent accepts rating and platform', function (): void {
        $event = new CustomerReviewEvent(params: [
            'rating' => 5,
            'platform' => 'g2',
            'public' => true,
        ]);
        expect($event->name)->toBe('customer_review');
        expect($event->params['rating'])->toBe(5);
    });

    test('OnboardingCallCompletedEvent accepts duration and outcome', function (): void {
        $event = new OnboardingCallCompletedEvent(params: [
            'duration_minutes' => 45,
            'outcome' => 'successful',
            'cs_rep' => 'jane_doe',
        ]);
        expect($event->name)->toBe('onboarding_call_completed');
        expect($event->params['duration_minutes'])->toBe(45);
    });

    test('all events accept clientId and userId', function (): void {
        $event = new SupportTicketCreatedEvent(
            params: ['priority' => 'high'],
            clientId: 'client-123',
            userId: 'user-456',
        );
        expect($event->clientId)->toBe('client-123');
        expect($event->userId)->toBe('user-456');
    });
});

describe('SaaS Sub-Categories Integration', function (): void {
    test('customer_success sub-category exists', function (): void {
        expect(SaaSEventSubCategories::names())->toContain('customer_success');
    });

    test('customer_success sub-category has 7 events', function (): void {
        expect(SaaSEventSubCategories::events('customer_success'))->toHaveCount(7);
    });

    test('each customer success event belongs to correct sub-category', function (): void {
        $csEvents = [
            'support_ticket_created', 'nps_submitted', 'health_score_changed',
            'renewal_reminder_sent', 'churn_interview', 'customer_review',
            'onboarding_call_completed',
        ];

        foreach ($csEvents as $event) {
            expect(SaaSEventSubCategories::belongsTo($event, 'customer_success'))->toBeTrue();
            expect(SaaSEventSubCategories::subcategoryFor($event))->toBe('customer_success');
        }
    });

    test('customer success events are NOT in other sub-categories', function (): void {
        $csEvents = CustomerSuccessEvents::names();
        $otherCategories = array_filter(
            SaaSEventSubCategories::names(),
            fn (string $cat): bool => $cat !== 'customer_success',
        );

        foreach ($csEvents as $event) {
            foreach ($otherCategories as $cat) {
                expect(SaaSEventSubCategories::belongsTo($event, $cat))->toBeFalse();
            }
        }
    });
});

describe('SaaS Events Catalog Integration', function (): void {
    test('all 7 customer success events are in the main SaaS catalog', function (): void {
        $csEvents = CustomerSuccessEvents::names();
        $saasCatalog = SaaSEvents::all();

        foreach ($csEvents as $event) {
            expect(SaaSEvents::has($event))->toBeTrue("Event '{$event}' should be in SaaS catalog");
            expect($saasCatalog[$event])->not->toBeNull();
        }
    });

    test('all 7 customer success events are in the unified EventCatalog', function (): void {
        $csEvents = CustomerSuccessEvents::names();
        $unifiedCatalog = EventCatalog::all();

        foreach ($csEvents as $event) {
            expect($unifiedCatalog)->toHaveKey($event);
            expect($unifiedCatalog[$event]['category'])->toBe('saas');
        }
    });

    test('SaaS catalog count increased by 7', function (): void {
        // The SaaS catalog should now include the 7 new CS events
        expect(SaaSEvents::count())->toBeGreaterThanOrEqual(75);
    });
});

describe('CustomerSuccessAnalyticsService', function (): void {
    test('catalogSummary returns correct structure', function (): void {
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $service = new CustomerSuccessAnalyticsService($cache);

        $summary = $service->catalogSummary();
        expect($summary)->toHaveKey('count');
        expect($summary)->toHaveKey('events');
        expect($summary)->toHaveKey('categories');
        expect($summary['count'])->toBe(7);
        expect($summary['categories'])->toContain('support', 'satisfaction', 'health');
    });

    test('classifyNps correctly categorizes scores', function (): void {
        expect(CustomerSuccessAnalyticsService::classifyNps(10))->toBe('promoter');
        expect(CustomerSuccessAnalyticsService::classifyNps(9))->toBe('promoter');
        expect(CustomerSuccessAnalyticsService::classifyNps(8))->toBe('passive');
        expect(CustomerSuccessAnalyticsService::classifyNps(7))->toBe('passive');
        expect(CustomerSuccessAnalyticsService::classifyNps(6))->toBe('detractor');
        expect(CustomerSuccessAnalyticsService::classifyNps(0))->toBe('detractor');
    });

    test('calculateNps returns correct NPS value', function (): void {
        // All promoters (10, 9) and one detractor (4)
        $nps = CustomerSuccessAnalyticsService::calculateNps([10, 9, 9, 4]);
        // 3 promoters out of 4 = 75%, 1 detractor out of 4 = 25%
        // NPS = 75 - 25 = 50
        expect($nps)->toBe(50);
    });

    test('calculateNps returns 0 for empty scores', function (): void {
        expect(CustomerSuccessAnalyticsService::calculateNps([]))->toBe(0);
    });

    test('calculateNps with all promoters returns 100', function (): void {
        expect(CustomerSuccessAnalyticsService::calculateNps([9, 10, 10]))->toBe(100);
    });

    test('calculateNps with all detractors returns -100', function (): void {
        expect(CustomerSuccessAnalyticsService::calculateNps([0, 3, 6]))->toBe(-100);
    });

    test('computeHealthSignal returns 0 for empty events', function (): void {
        expect(CustomerSuccessAnalyticsService::computeHealthSignal([]))->toBe(0.0);
    });

    test('computeHealthSignal is positive for positive signals', function (): void {
        $signal = CustomerSuccessAnalyticsService::computeHealthSignal([
            'customer_review' => 3,
            'onboarding_call_completed' => 2,
        ]);
        expect($signal)->toBeGreaterThan(0.0);
    });

    test('computeHealthSignal is negative for negative signals', function (): void {
        $signal = CustomerSuccessAnalyticsService::computeHealthSignal([
            'support_ticket_created' => 5,
            'churn_interview' => 1,
        ]);
        expect($signal)->toBeLessThan(0.0);
    });

    test('assessChurnRisk returns correct structure', function (): void {
        $result = CustomerSuccessAnalyticsService::assessChurnRisk(0.0, 5, 0);
        expect($result)->toHaveKey('level');
        expect($result)->toHaveKey('score');
        expect($result)->toHaveKey('factors');
        expect($result['level'])->toBeString();
        expect($result['score'])->toBeFloat();
        expect($result['factors'])->toBeArray();
    });

    test('assessChurnRisk returns low for healthy signals', function (): void {
        $result = CustomerSuccessAnalyticsService::assessChurnRisk(0.8, 9, 0);
        expect($result['level'])->toBe('low');
        expect($result['score'])->toBeGreaterThanOrEqual(75.0);
    });

    test('assessChurnRisk returns critical for very negative signals', function (): void {
        $result = CustomerSuccessAnalyticsService::assessChurnRisk(-1.0, 2, 15);
        expect($result['level'])->toBe('critical');
    });

    test('assessChurnRisk identifies correct risk factors', function (): void {
        $result = CustomerSuccessAnalyticsService::assessChurnRisk(-0.5, 4, 8);
        expect($result['factors'])->toContain('negative_health_signal');
        expect($result['factors'])->toContain('low_nps');
        expect($result['factors'])->toContain('high_ticket_volume');
    });

    test('kpiSummary returns correct structure', function (): void {
        $summary = CustomerSuccessAnalyticsService::kpiSummary([
            'avg_nps' => 42,
            'total_tickets_30d' => 90,
            'avg_health_score' => 72.5,
            'renewal_rate' => 0.92,
            'churn_rate' => 0.05,
        ]);

        expect($summary)->toHaveKey('nps');
        expect($summary)->toHaveKey('support_velocity');
        expect($summary)->toHaveKey('health');
        expect($summary)->toHaveKey('retention');
        expect($summary['nps']['value'])->toBe(42);
        expect($summary['nps']['classification'])->toBe('promoter');
        expect($summary['support_velocity']['total_30d'])->toBe(90);
        expect($summary['support_velocity']['daily_avg'])->toBe(3.0);
        expect($summary['health']['trend'])->toBe('healthy');
        expect($summary['retention']['renewal_rate'])->toBe(0.92);
    });
});

describe('FeatureGatingAnalyticsService', function (): void {
    test('is disabled by default', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.feature_gating', [])->andReturn([]);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnNull();
        $cache->shouldReceive('put')->andReturnTrue();

        $service = new FeatureGatingAnalyticsService($config, $cache);
        expect($service->isEnabled())->toBeFalse();
    });

    test('allows all events when disabled', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.feature_gating', [])->andReturn([]);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnNull();

        $service = new FeatureGatingAnalyticsService($config, $cache);
        expect($service->isEventAllowed('cohort_retention', 'free'))->toBeTrue();
        expect($service->isEventAllowed('any_event', 'any_plan'))->toBeTrue();
    });

    test('ungated events are always allowed', function (): void {
        $ungated = (new \ReflectionClass(FeatureGatingAnalyticsService::class))
            ->getConstant('UNGATED_EVENTS');

        expect($ungated)->toBeArray();
        expect($ungated)->toContain('page_view');
        expect($ungated)->toContain('sign_up');
        expect($ungated)->toContain('login');
        expect($ungated)->toContain('click');
        expect($ungated)->toContain('scroll_depth');
        expect($ungated)->toContain('error');
        expect($ungated)->toContain('form_start');
        expect($ungated)->toContain('form_submit');
        expect($ungated)->toContain('search');
        expect($ungated)->toContain('share');
        expect($ungated)->toContain('session_start');
        expect($ungated)->toContain('session_end');
        expect($ungated)->toContain('time_on_page');
        expect($ungated)->toContain('js_error');
        expect($ungated)->toContain('logout');
        expect($ungated)->toContain('email_verified');
    });

    test('has correct plan hierarchy', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.feature_gating', [])->andReturn([]);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnNull();

        $service = new FeatureGatingAnalyticsService($config, $cache);
        $hierarchy = $service->getPlanHierarchy();
        expect($hierarchy)->toEqual(['free', 'starter', 'pro', 'enterprise']);
    });

    test('isPlanAtOrAbove works correctly', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.feature_gating', [])->andReturn([]);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnNull();

        $service = new FeatureGatingAnalyticsService($config, $cache);

        expect($service->isPlanAtOrAbove('pro', 'free'))->toBeTrue();
        expect($service->isPlanAtOrAbove('pro', 'pro'))->toBeTrue();
        expect($service->isPlanAtOrAbove('pro', 'enterprise'))->toBeFalse();
        expect($service->isPlanAtOrAbove('enterprise', 'starter'))->toBeTrue();
    });

    test('isPlanAtOrAbove returns true for unknown plans', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.feature_gating', [])->andReturn([]);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnNull();

        $service = new FeatureGatingAnalyticsService($config, $cache);
        expect($service->isPlanAtOrAbove('unknown', 'enterprise'))->toBeTrue();
        expect($service->isPlanAtOrAbove('free', 'unknown'))->toBeTrue();
    });

    test('summary returns correct structure', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.feature_gating', [])->andReturn([]);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnNull();

        $service = new FeatureGatingAnalyticsService($config, $cache);
        $summary = $service->summary();

        expect($summary)->toHaveKey('enabled');
        expect($summary)->toHaveKey('plan_count');
        expect($summary)->toHaveKey('ungated_count');
        expect($summary)->toHaveKey('premium_categories');
        expect($summary)->toHaveKey('hierarchy');
        expect($summary['plan_count'])->toBe(4);
    });

    test('getUngatedEvents returns list', function (): void {
        $config = mock(\Illuminate\Contracts\Config\Repository::class);
        $config->shouldReceive('get')->with('zeroboiler.analytics.feature_gating', [])->andReturn([]);
        $cache = mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->andReturnNull();

        $service = new FeatureGatingAnalyticsService($config, $cache);
        expect($service->getUngatedEvents())->toBeArray();
        expect($service->getUngatedEvents())->toHaveCount(16);
    });
});

describe('End-to-End SaaS Lifecycle Integration', function (): void {
    test('full signup-to-expansion lifecycle event chain is valid', function (): void {
        $lifecycleEvents = [
            'sign_up',
            'email_verified',
            'login',
            'onboarding_started',
            'first_value',
            'activation',
            'start_trial',
            'trial_converted',
            'subscribe',
            'feature_used',
            'feature_adopted',
            'plan_upgrade',
            'expansion_revenue',
            'milestone_reached',
        ];

        $catalog = EventCatalog::all();

        foreach ($lifecycleEvents as $event) {
            expect($catalog)->toHaveKey($event, "Lifecycle event '{$event}' must exist in catalog");
            expect($catalog[$event]['category'])->toBe('saas');
        }
    });

    test('churn lifecycle event chain is valid', function (): void {
        $churnEvents = [
            'retention_risk',
            'support_ticket_created',
            'health_score_changed',
            'churn_interview',
            'cancellation',
            'subscription_cancelled',
            'account_deleted',
            'data_erasure_completed',
        ];

        $catalog = EventCatalog::all();

        foreach ($churnEvents as $event) {
            expect($catalog)->toHaveKey($event, "Churn event '{$event}' must exist in catalog");
            expect($catalog[$event]['category'])->toBe('saas');
        }
    });

    test('customer success workflow is valid', function (): void {
        $csWorkflow = [
            'onboarding_call_completed',
            'support_ticket_created',
            'nps_submitted',
            'health_score_changed',
            'renewal_reminder_sent',
            'customer_review',
        ];

        $catalog = EventCatalog::all();

        foreach ($csWorkflow as $event) {
            expect($catalog)->toHaveKey($event);
            expect(SaaSEventSubCategories::belongsTo($event, 'customer_success'))->toBeTrue();
        }
    });

    test('ecommerce full funnel events are valid', function (): void {
        $funnelEvents = [
            'view_item',
            'select_item',
            'add_to_cart',
            'view_cart',
            'begin_checkout',
            'add_payment_info',
            'purchase',
            'refund',
        ];

        $catalog = EventCatalog::all();

        foreach ($funnelEvents as $event) {
            expect($catalog)->toHaveKey($event, "Ecommerce funnel event '{$event}' must exist");
            expect($catalog[$event]['category'])->toBe('ecommerce');
        }
    });

    test('engagement tracking events cover all core interactions', function (): void {
        $engagementCore = [
            'page_view', 'scroll_depth', 'click', 'form_start', 'form_submit',
            'search', 'share', 'error', 'time_on_page', 'session_start',
            'session_end', 'outbound_click', 'file_download', 'video_play',
        ];

        $catalog = EventCatalog::all();

        foreach ($engagementCore as $event) {
            expect($catalog)->toHaveKey($event, "Engagement event '{$event}' must exist");
            expect($catalog[$event]['category'])->toBe('engagement');
        }
    });

    test('EventCatalog total count is at least 210', function (): void {
        expect(EventCatalog::count())->toBeGreaterThanOrEqual(210);
    });

    test('SaaS catalog has at least 75 events', function (): void {
        expect(SaaSEvents::count())->toBeGreaterThanOrEqual(75);
    });

    test('all events have non-empty name field', function (): void {
        foreach (EventCatalog::all() as $name => $entry) {
            expect($entry['name'])->toBe($name);
            expect($entry['name'])->not->toBeEmpty();
        }
    });

    test('all events have a valid class reference string', function (): void {
        foreach (EventCatalog::all() as $entry) {
            expect($entry['class'])->toBeString();
            expect($entry['class'])->not->toBeEmpty();
        }
    });
});
