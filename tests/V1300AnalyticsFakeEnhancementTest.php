<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Support\AnalyticsFake;

beforeEach(function (): void {
    $this->fake = new AnalyticsFake;
    app()->instance('zeroboiler.analytics', $this->fake);
});

// ── Core Tracking ────────────────────────────────────────────────────

describe('AnalyticsFake — core tracking', function (): void {
    test('track captures events', function (): void {
        $this->fake->track('sign_up', ['method' => 'email']);

        expect($this->fake->eventCount())->toBe(1);
        expect($this->fake->trackedEvents('sign_up'))->toHaveCount(1);
        expect($this->fake->trackedEvents('sign_up')[0]->params)->toBe(['method' => 'email']);
    });

    test('trackEvent captures DTO events', function (): void {
        $event = new AnalyticsEvent(name: 'purchase', params: ['value' => 99.99]);
        $this->fake->trackEvent($event);

        expect($this->fake->eventCount())->toBe(1);
        expect($this->fake->trackedEvents('purchase')[0]->name)->toBe('purchase');
    });

    test('directDispatch returns true and tracks', function (): void {
        $event = new AnalyticsEvent(name: 'test_event');
        $result = $this->fake->directDispatch($event);

        expect($result)->toBeTrue();
        expect($this->fake->eventCount())->toBe(1);
    });

    test('trackAsync captures events', function (): void {
        $this->fake->trackAsync('async_event', ['key' => 'value']);

        expect($this->fake->eventCount())->toBe(1);
        expect($this->fake->trackedEvents('async_event'))->toHaveCount(1);
    });
});

// ── SaaS Lifecycle Methods ───────────────────────────────────────────

describe('AnalyticsFake — SaaS lifecycle', function (): void {
    test('signUp tracks event', function (): void {
        $this->fake->signUp('github');
        AnalyticsFake::assertTracked('sign_up');
    });

    test('login tracks event and auto-identifies', function (): void {
        $this->fake->login('user-123', 'client-456', 'oauth');

        AnalyticsFake::assertTracked('login');
        expect($this->fake->identifyCalls())->toHaveCount(1);
    });

    test('logout tracks event', function (): void {
        $this->fake->logout('web');
        AnalyticsFake::assertTracked('logout');
    });

    test('trialStart tracks event', function (): void {
        $this->fake->trialStart('pro', 14);
        AnalyticsFake::assertTracked('start_trial');
    });

    test('trialEnd tracks event', function (): void {
        $this->fake->trialEnd('converted', 'pro');
        AnalyticsFake::assertTracked('trial_end');
    });

    test('subscription tracks event', function (): void {
        $this->fake->subscription('business', 99.99, 'USD', 'monthly');
        AnalyticsFake::assertTracked('subscribe');
    });

    test('subscriptionRenewal tracks event', function (): void {
        $this->fake->subscriptionRenewal('pro', 49.99);
        AnalyticsFake::assertTracked('subscription_renewal');
    });

    test('planUpgrade tracks event', function (): void {
        $this->fake->planUpgrade('starter', 'pro', 30.0);
        AnalyticsFake::assertTracked('plan_upgrade');
    });

    test('planDowngrade tracks event', function (): void {
        $this->fake->planDowngrade('pro', 'starter');
        AnalyticsFake::assertTracked('plan_downgrade');
    });

    test('cancellation tracks event', function (): void {
        $this->fake->cancellation('pro', 'too_expensive');
        AnalyticsFake::assertTracked('cancellation');
    });

    test('trackSaaSIdentity tracks and links', function (): void {
        $this->fake->trackSaaSIdentity('user-1', 'client-1', ['name' => 'John']);

        expect($this->fake->saasIdentityCalls())->toHaveCount(1);
        AnalyticsFake::assertTracked('identify');
        AnalyticsFake::assertTracked('set_user_properties');
    });

    test('trackSaaSAcquisition tracks full funnel', function (): void {
        $this->fake->trackSaaSAcquisition('pro', 99.99, 'USD', ['method' => 'email']);

        AnalyticsFake::assertTracked('sign_up');
        AnalyticsFake::assertTracked('start_trial');
        AnalyticsFake::assertTracked('subscribe');
    });

    test('trackSaaSAcquisition skips trial when skip_trial is true', function (): void {
        $this->fake->trackSaaSAcquisition('pro', 99.99, 'USD', ['method' => 'email', 'skip_trial' => true]);

        AnalyticsFake::assertTracked('sign_up');
        AnalyticsFake::assertNotTracked('start_trial');
        AnalyticsFake::assertTracked('subscribe');
    });
});

// ── E-Commerce ──────────────────────────────────────────────────────

describe('AnalyticsFake — e-commerce', function (): void {
    test('trackEcommerce captures call', function (): void {
        $this->fake->trackEcommerce('purchase', ['value' => 49.99], ['currency' => 'USD']);

        expect($this->fake->ecommerceCalls())->toHaveCount(1);
        AnalyticsFake::assertTracked('purchase');
    });

    test('purchase tracks event', function (): void {
        $this->fake->purchase('txn-123', 99.99);
        AnalyticsFake::assertTracked('purchase');
    });

    test('wishlist tracks event', function (): void {
        $this->fake->wishlist(['item_id' => 'prod-1', 'price' => 29.99]);
        AnalyticsFake::assertTracked('add_to_wishlist');
    });

    test('selectItem tracks event', function (): void {
        $this->fake->selectItem([['item_id' => 'prod-1']], 'list-1', 'Related');
        AnalyticsFake::assertTracked('select_item');
    });

    test('selectPromotion tracks event', function (): void {
        $this->fake->selectPromotion('promo-1', 'Summer Sale');
        AnalyticsFake::assertTracked('select_promotion');
    });

    test('viewPromotion tracks event', function (): void {
        $this->fake->viewPromotion('promo-1', 'Summer Sale');
        AnalyticsFake::assertTracked('view_promotion');
    });

    test('formatEcommerceForMeta returns correct structure', function (): void {
        $items = [
            ['item_id' => 'prod-1', 'quantity' => 2, 'price' => 19.99, 'item_name' => 'Widget', 'item_category' => 'Gadgets'],
        ];

        $result = $this->fake->formatEcommerceForMeta($items);

        expect($result)->toHaveKey('content_ids');
        expect($result)->toHaveKey('contents');
        expect($result)->toHaveKey('num_items');
        expect($result['content_ids'])->toBe(['prod-1']);
        expect($result['num_items'])->toBe(2);
        expect($result['contents'][0]['id'])->toBe('prod-1');
    });
});

// ── Engagement ──────────────────────────────────────────────────────

describe('AnalyticsFake — engagement', function (): void {
    test('trackError tracks event', function (): void {
        $this->fake->trackError('Something broke', 'app.js', 42);
        AnalyticsFake::assertTracked('error');
    });

    test('abTestExposure tracks event', function (): void {
        $this->fake->abTestExposure('exp-1', 'variant-b');
        AnalyticsFake::assertTracked('ab_test_exposure');
    });

    test('notification tracks event', function (): void {
        $this->fake->notification('email', 'opened', 'welcome');
        AnalyticsFake::assertTracked('notification');
    });

    test('fileDownload tracks event', function (): void {
        $this->fake->fileDownload('report.pdf', 'pdf');
        AnalyticsFake::assertTracked('file_download');
    });

    test('videoPlay tracks event', function (): void {
        $this->fake->videoPlay('Intro', 'youtube');
        AnalyticsFake::assertTracked('video_play');
    });

    test('inviteSent tracks event', function (): void {
        $this->fake->inviteSent('team_member', 'admin');
        AnalyticsFake::assertTracked('invite_sent');
    });

    test('integrationConnected tracks event', function (): void {
        $this->fake->integrationConnected('slack');
        AnalyticsFake::assertTracked('integration_connected');
    });
});

// ── Identity ─────────────────────────────────────────────────────────

describe('AnalyticsFake — identity', function (): void {
    test('identify captures calls', function (): void {
        $this->fake->identify('user-1', 'client-1', ['name' => 'John']);

        expect($this->fake->identifyCalls())->toHaveCount(1);
        expect($this->fake->identifyCalls()[0]['userId'])->toBe('user-1');
        expect($this->fake->identifyCalls()[0]['clientId'])->toBe('client-1');
    });

    test('alias tracks event', function (): void {
        $this->fake->alias('anon-123', 'user-456');
        AnalyticsFake::assertTracked('alias');
    });

    test('setUserProperties tracks event', function (): void {
        $this->fake->setUserProperties(['plan' => 'pro'], 'user-1');
        AnalyticsFake::assertTracked('set_user_properties');
    });

    test('resetIdentity is no-op', function (): void {
        $this->fake->resetIdentity(); // Should not throw
        expect($this->fake->eventCount())->toBe(0);
    });
});

// ── Page Views ──────────────────────────────────────────────────────

describe('AnalyticsFake — page views', function (): void {
    test('pageView tracks event', function (): void {
        $this->fake->pageView('Home', 'https://example.com', '');
        AnalyticsFake::assertTracked('page_view');
        expect($this->fake->pageViews())->toHaveCount(1);
    });

    test('serverSidePageView tracks event with identity', function (): void {
        $this->fake->serverSidePageView('Dashboard', '/', '', 'client-1', 'user-1');

        AnalyticsFake::assertTracked('page_view');
        $pv = $this->fake->pageViews()[0];
        expect($pv->clientId)->toBe('client-1');
        expect($pv->userId)->toBe('user-1');
    });

    test('screenView tracks event', function (): void {
        $this->fake->screenView('Dashboard', 'MainScreen');
        AnalyticsFake::assertTracked('screen_view');
    });
});

// ── Consent ──────────────────────────────────────────────────────────

describe('AnalyticsFake — consent', function (): void {
    test('setConsent records state', function (): void {
        $this->fake->setConsent(ConsentState::denied());
        expect($this->fake->getConsent()->isDenied())->toBeTrue();
    });

    test('grantConsent sets granted', function (): void {
        $this->fake->grantConsent();
        expect($this->fake->getConsent()->isGranted())->toBeTrue();
    });

    test('denyConsent sets denied', function (): void {
        $this->fake->denyConsent();
        expect($this->fake->getConsent()->isDenied())->toBeTrue();
    });

    test('default consent is granted', function (): void {
        expect($this->fake->getConsent()->isGranted())->toBeTrue();
    });
});

// ── Preferences ────────────────────────────────────────────────────

describe('AnalyticsFake — preferences', function (): void {
    test('isTrackingAllowed returns true when consent granted', function (): void {
        expect($this->fake->isTrackingAllowed())->toBeTrue();
    });

    test('isTrackingAllowed returns false when consent denied', function (): void {
        $this->fake->denyConsent();
        expect($this->fake->isTrackingAllowed())->toBeFalse();
    });

    test('optOut is no-op', function (): void {
        $this->fake->optOut('user-1'); // Should not throw
    });

    test('optIn is no-op', function (): void {
        $this->fake->optIn('user-1');
    });

    test('suppressClient is no-op', function (): void {
        $this->fake->suppressClient('client-1');
    });

    test('transferClientToUser returns false', function (): void {
        expect($this->fake->transferClientToUser('client-1', 'user-1'))->toBeFalse();
    });
});

// ── Revenue & PLG ────────────────────────────────────────────────────

describe('AnalyticsFake — revenue & PLG', function (): void {
    test('mrr tracks event', function (): void {
        $this->fake->mrr(5000.0, 42);
        AnalyticsFake::assertTracked('revenue_tracked');
    });

    test('featureAdopted tracks event', function (): void {
        $this->fake->featureAdopted('export', 'core');
        AnalyticsFake::assertTracked('feature_adopted');
    });

    test('expansionRevenue tracks event', function (): void {
        $this->fake->expansionRevenue(120.0, 'seat_expansion');
        AnalyticsFake::assertTracked('expansion_revenue');
    });

    test('exportEvent tracks event', function (): void {
        $this->fake->exportEvent('csv', 'users', 500);
        AnalyticsFake::assertTracked('export');
    });

    test('importEvent tracks event', function (): void {
        $this->fake->importEvent('json', 'contacts', 100, true);
        AnalyticsFake::assertTracked('import');
    });
});

// ── Funnel Tracking ─────────────────────────────────────────────────

describe('AnalyticsFake — funnel tracking', function (): void {
    test('trackFunnel tracks funnel_step', function (): void {
        $this->fake->trackFunnel('signup', 'form_start', 1, 3);
        AnalyticsFake::assertTracked('funnel_step');
    });

    test('funnelProgress tracks and returns result', function (): void {
        $result = $this->fake->funnelProgress('checkout', 'payment', 'user-1', 3, 5);

        expect($result['funnel_name'])->toBe('checkout');
        expect($result['step_number'])->toBe(3);
        expect($result['total_steps'])->toBe(5);
        expect($result['completion_pct'])->toBe(60.0);
        expect($result['is_complete'])->toBeFalse();
        AnalyticsFake::assertTracked('funnel_step');
        expect($this->fake->funnelProgressCalls())->toHaveCount(1);
    });

    test('funnelProgress detects completion', function (): void {
        $result = $this->fake->funnelProgress('signup', 'done', 'user-1', 3, 3);

        expect($result['is_complete'])->toBeTrue();
        expect($result['completion_pct'])->toBe(100.0);
    });
});

// ── B2B Groups ──────────────────────────────────────────────────────

describe('AnalyticsFake — B2B groups', function (): void {
    test('group tracks event', function (): void {
        $this->fake->group('company-1', ['name' => 'Acme']);
        AnalyticsFake::assertTracked('group_identify');
    });

    test('groupAddMember tracks event', function (): void {
        $this->fake->groupAddMember('user-1', 'company-1', 'admin');
        AnalyticsFake::assertTracked('group_member_added');
    });

    test('getGroup returns default structure', function (): void {
        $result = $this->fake->getGroup('company-1');

        expect($result['group_id'])->toBe('company-1');
        expect($result['member_count'])->toBe(0);
        expect($result['traits'])->toBe([]);
    });
});

// ── Debug & Metrics ────────────────────────────────────────────────

describe('AnalyticsFake — debug & metrics', function (): void {
    test('isDebug defaults to false', function (): void {
        expect($this->fake->isDebug())->toBeFalse();
    });

    test('setDebug toggles state', function (): void {
        $this->fake->setDebug(true);
        expect($this->fake->isDebug())->toBeTrue();
    });

    test('shouldLogEvents returns false', function (): void {
        expect($this->fake->shouldLogEvents())->toBeFalse();
    });

    test('metrics returns AnalyticsMetrics instance', function (): void {
        expect($this->fake->metrics())->toBeInstanceOf(\ZeroBoiler\Analytics\AnalyticsMetrics::class);
    });

    test('flushMetrics returns snapshot', function (): void {
        $this->fake->track('event_1');
        $this->fake->track('event_2');

        $snapshot = $this->fake->flushMetrics();
        expect($snapshot['dispatched'])->toBe(2);
    });
});

// ── Interceptors ────────────────────────────────────────────────────

describe('AnalyticsFake — interceptors', function (): void {
    test('before interceptor can cancel event', function (): void {
        $this->fake->interceptBefore(function (AnalyticsEvent $event): ?AnalyticsEvent {
            return null; // Cancel
        });

        $this->fake->track('blocked_event');
        expect($this->fake->eventCount())->toBe(0);
    });

    test('before interceptor can modify event', function (): void {
        $this->fake->interceptBefore(function (AnalyticsEvent $event): ?AnalyticsEvent {
            return new AnalyticsEvent(
                name: 'modified_' . $event->name,
                params: $event->params,
            );
        });

        $this->fake->track('original');
        expect($this->fake->trackedEvents('modified_original'))->toHaveCount(1);
        expect($this->fake->trackedEvents('original'))->toHaveCount(0);
    });

    test('after interceptor fires', function (): void {
        $afterFired = false;

        $this->fake->interceptAfter(function (AnalyticsEvent $event, bool $success) use (&$afterFired): void {
            $afterFired = true;
        });

        $this->fake->track('test_event');
        expect($afterFired)->toBeTrue();
    });

    test('interceptors accessor returns registry', function (): void {
        expect($this->fake->interceptors())->toBeInstanceOf(\ZeroBoiler\Analytics\EventInterceptorRegistry::class);
    });
});

// ── Tracker Accessors ──────────────────────────────────────────────

describe('AnalyticsFake — tracker accessors', function (): void {
    test('ga4 returns disabled tracker', function (): void {
        expect($this->fake->ga4())->toBeInstanceOf(\ZeroBoiler\Analytics\Trackers\GA4Tracker::class);
        expect($this->fake->ga4()->isEnabled())->toBeFalse();
    });

    test('gtm returns disabled tracker', function (): void {
        expect($this->fake->gtm()->isEnabled())->toBeFalse();
    });

    test('meta returns disabled tracker', function (): void {
        expect($this->fake->meta()->isEnabled())->toBeFalse();
    });

    test('plausible returns disabled tracker', function (): void {
        expect($this->fake->plausible()->isEnabled())->toBeFalse();
    });

    test('posthog returns disabled tracker', function (): void {
        expect($this->fake->posthog()->isEnabled())->toBeFalse();
    });

    test('webhook returns disabled tracker', function (): void {
        expect($this->fake->webhook()->isEnabled())->toBeFalse();
    });

    test('mixpanel returns disabled tracker', function (): void {
        expect($this->fake->mixpanel()->isEnabled())->toBeFalse();
    });

    test('amplitude returns disabled tracker', function (): void {
        expect($this->fake->amplitude()->isEnabled())->toBeFalse();
    });
});

// ── Script Generation ──────────────────────────────────────────────

describe('AnalyticsFake — script generation', function (): void {
    test('headScripts returns empty string', function (): void {
        expect($this->fake->headScripts())->toBe('');
    });

    test('bodyScripts returns empty string', function (): void {
        expect($this->fake->bodyScripts())->toBe('');
    });
});

// ── Data Layer ─────────────────────────────────────────────────────

describe('AnalyticsFake — data layer', function (): void {
    test('push is no-op', function (): void {
        $this->fake->push(['event' => 'test']);
        expect($this->fake->eventCount())->toBe(0);
    });
});

// ── Catalog Queries ────────────────────────────────────────────────

describe('AnalyticsFake — catalog queries', function (): void {
    test('eventCatalogSummary returns structure', function (): void {
        $summary = $this->fake->eventCatalogSummary();

        expect($summary)->toHaveKeys(['ecommerce', 'saas', 'engagement', 'total']);
        expect($summary['total'])->toBeGreaterThan(0);
    });

    test('eventExists checks catalog', function (): void {
        expect($this->fake->eventExists('sign_up'))->toBeTrue();
        expect($this->fake->eventExists('nonexistent_event_xyz'))->toBeFalse();
    });

    test('eventCategory returns category', function (): void {
        expect($this->fake->eventCategory('sign_up'))->toBe('saas');
        expect($this->fake->eventCategory('purchase'))->toBe('ecommerce');
    });

    test('totalEventCount returns catalog count', function (): void {
        expect($this->fake->totalEventCount())->toBeGreaterThan(0);
    });

    test('validateCatalog returns valid structure', function (): void {
        $result = $this->fake->validateCatalog();

        expect($result)->toHaveKeys(['valid', 'errors', 'warnings']);
    });

    test('resolveEventName returns input as-is', function (): void {
        expect($this->fake->resolveEventName('custom_event'))->toBe('custom_event');
    });

    test('trackWithAlias tracks event', function (): void {
        $this->fake->trackWithAlias('my_event', ['key' => 'value']);
        AnalyticsFake::assertTracked('my_event');
    });

    test('version returns current version', function (): void {
        expect($this->fake->version())->toBe(AnalyticsEvent::VERSION);
    });

    test('providerSummary returns structure', function (): void {
        $summary = $this->fake->providerSummary();

        expect($summary)->toHaveKeys(['ga4', 'gtm', 'meta', 'plausible', 'posthog', 'webhook']);
        expect($summary['ga4']['enabled'])->toBeFalse();
    });

    test('reportSummary returns structure', function (): void {
        $this->fake->track('e1');
        $summary = $this->fake->reportSummary();

        expect($summary)->toHaveKeys(['events', 'dispatched', 'failed', 'success_rate', 'top_event']);
    });

    test('dlqSummary returns structure', function (): void {
        $summary = $this->fake->dlqSummary();

        expect($summary)->toHaveKeys(['enabled', 'strategy', 'total', 'buffered']);
        expect($summary['enabled'])->toBeFalse();
    });
});

// ── Profile & Health ────────────────────────────────────────────────

describe('AnalyticsFake — profile & health', function (): void {
    test('getProfile returns default structure', function (): void {
        $profile = $this->fake->getProfile('user-1');

        expect($profile)->toHaveKeys(['event_counts', 'total_events', 'total_value', 'first_seen', 'last_seen']);
        expect($profile['total_events'])->toBe(0);
    });

    test('getProfileSummary returns default structure', function (): void {
        $summary = $this->fake->getProfileSummary('user-1');

        expect($summary)->toHaveKey('user_id');
        expect($summary['user_id'])->toBe('user-1');
    });

    test('quickStartEvents returns structure', function (): void {
        $result = $this->fake->quickStartEvents();

        expect($result)->toHaveKeys(['events', 'count', 'categories', 'funnel_coverage']);
    });

    test('plgEvents returns list', function (): void {
        $events = $this->fake->plgEvents();

        expect($events)->toBeArray();
        expect(count($events))->toBeGreaterThan(0);
    });

    test('healthCheck returns structure', function (): void {
        $result = $this->fake->healthCheck();

        expect($result)->toHaveKeys(['status', 'version', 'overall_score']);
        expect($result['status'])->toBe('ok');
    });

    test('ping returns structure', function (): void {
        $result = $this->fake->ping();

        expect($result)->toHaveKeys(['status', 'version', 'providers_configured', 'catalog_size']);
    });

    test('maturityScore returns structure', function (): void {
        $result = $this->fake->maturityScore();

        expect($result)->toHaveKeys(['score', 'grade', 'details']);
    });

    test('onboardingChecklist returns structure', function (): void {
        $result = $this->fake->onboardingChecklist();

        expect($result)->toHaveKeys(['checklist', 'summary']);
    });

    test('funnelReadiness returns structure', function (): void {
        $result = $this->fake->funnelReadiness();

        expect($result)->toHaveKeys(['signup_funnel', 'purchase_funnel', 'subscription_funnel', 'overall']);
    });
});

// ── Orchestration & PLG ──────────────────────────────────────────────

describe('AnalyticsFake — orchestration & PLG', function (): void {
    test('orchestrate returns structure', function (): void {
        $result = $this->fake->orchestrate('onboarding', 'client-1');

        expect($result)->toHaveKeys(['pipeline', 'status', 'started_at']);
        expect($result['status'])->toBe('started');
    });

    test('orchestrateAdvance returns structure', function (): void {
        $result = $this->fake->orchestrateAdvance('onboarding', 'step-1', 'client-1');

        expect($result)->toHaveKeys(['step', 'event', 'pipeline_status', 'is_complete']);
    });

    test('orchestrateProgress returns 0', function (): void {
        expect($this->fake->orchestrateProgress('onboarding', 'client-1'))->toBe(0.0);
    });

    test('insightReport returns structure', function (): void {
        $result = $this->fake->insightReport();

        expect($result)->toHaveKeys(['generated_at', 'insights', 'summary']);
    });

    test('plgScore returns structure', function (): void {
        $result = $this->fake->plgScore('user-1');

        expect($result)->toHaveKeys(['score', 'grade', 'activation', 'engagement', 'retention']);
        expect($result['identity'])->toBe('user-1');
    });

    test('plgAggregate returns structure', function (): void {
        $result = $this->fake->plgAggregate();

        expect($result)->toHaveKeys(['avg_score', 'total_cached', 'grade_distribution']);
    });

    test('plgInvalidate is no-op', function (): void {
        $this->fake->plgInvalidate('user-1');
    });

    test('timeSeries returns structure', function (): void {
        $result = $this->fake->timeSeries('1h');

        expect($result)->toHaveKeys(['total_events', 'unique_identities', 'top_events', 'period']);
    });

    test('timeSeriesDashboard returns array', function (): void {
        expect($this->fake->timeSeriesDashboard())->toBeArray();
    });

    test('timeSeriesCompare returns structure', function (): void {
        $result = $this->fake->timeSeriesCompare('1h', '1h');

        expect($result)->toHaveKeys(['current', 'previous', 'delta']);
    });
});

// ── Assertion API ───────────────────────────────────────────────────

describe('AnalyticsFake — assertion API', function (): void {
    test('assertTracked passes for tracked event', function (): void {
        $this->fake->track('sign_up');
        AnalyticsFake::assertTracked('sign_up');
    });

    test('assertTracked fails for untracked event', function (): void {
        $this->fake->track('other_event');
        expect(fn () => AnalyticsFake::assertTracked('sign_up'))->toThrow(\AssertionError::class);
    });

    test('assertTracked with callback filters', function (): void {
        $this->fake->track('sign_up', ['method' => 'email']);

        AnalyticsFake::assertTracked('sign_up', function (AnalyticsEvent $e): bool {
            return ($e->params['method'] ?? '') === 'email';
        });

        expect(fn () => AnalyticsFake::assertTracked('sign_up', function (AnalyticsEvent $e): bool {
            return ($e->params['method'] ?? '') === 'github';
        }))->toThrow(\AssertionError::class);
    });

    test('assertNotTracked passes for untracked event', function (): void {
        $this->fake->track('other');
        AnalyticsFake::assertNotTracked('sign_up');
    });

    test('assertNotTracked fails for tracked event', function (): void {
        $this->fake->track('sign_up');
        expect(fn () => AnalyticsFake::assertNotTracked('sign_up'))->toThrow(\AssertionError::class);
    });

    test('assertTrackedTimes passes for exact count', function (): void {
        $this->fake->track('sign_up');
        $this->fake->track('sign_up');
        $this->fake->track('sign_up');

        AnalyticsFake::assertTrackedTimes('sign_up', 3);
    });

    test('assertTrackedTimes fails for wrong count', function (): void {
        $this->fake->track('sign_up');

        expect(fn () => AnalyticsFake::assertTrackedTimes('sign_up', 2))->toThrow(\AssertionError::class);
    });

    test('assertTrackedOnce passes for single event', function (): void {
        $this->fake->track('sign_up');

        AnalyticsFake::assertTrackedOnce('sign_up');
    });

    test('assertTrackedOnce fails for multiple', function (): void {
        $this->fake->track('sign_up');
        $this->fake->track('sign_up');

        expect(fn () => AnalyticsFake::assertTrackedOnce('sign_up'))->toThrow(\AssertionError::class);
    });

    test('assertTrackedAtLeast passes for sufficient count', function (): void {
        $this->fake->track('sign_up');
        $this->fake->track('sign_up');
        $this->fake->track('sign_up');

        AnalyticsFake::assertTrackedAtLeast('sign_up', 3);
        AnalyticsFake::assertTrackedAtLeast('sign_up', 2);
        AnalyticsFake::assertTrackedAtLeast('sign_up', 1);
    });

    test('assertTrackedAtLeast fails for insufficient count', function (): void {
        $this->fake->track('sign_up');

        expect(fn () => AnalyticsFake::assertTrackedAtLeast('sign_up', 2))->toThrow(\AssertionError::class);
    });

    test('assertNothingTracked passes for empty', function (): void {
        AnalyticsFake::assertNothingTracked();
    });

    test('assertNothingTracked fails when events tracked', function (): void {
        $this->fake->track('sign_up');

        expect(fn () => AnalyticsFake::assertNothingTracked())->toThrow(\AssertionError::class);
    });

    test('assertIdentified passes for identified user', function (): void {
        $this->fake->identify('user-1', 'client-1');

        AnalyticsFake::assertIdentified('user-1');
    });

    test('assertIdentified fails for non-identified user', function (): void {
        expect(fn () => AnalyticsFake::assertIdentified('user-99'))->toThrow(\AssertionError::class);
    });

    test('assertIdentified with callback', function (): void {
        $this->fake->identify('user-1', 'client-1', ['plan' => 'pro']);

        AnalyticsFake::assertIdentified('user-1', function (array $call): bool {
            return ($call['traits']['plan'] ?? '') === 'pro';
        });
    });

    test('assertPageViewTracked passes', function (): void {
        $this->fake->pageView('Home', 'https://example.com');
        AnalyticsFake::assertPageViewTracked();
    });

    test('assertPageViewTracked fails for no page views', function (): void {
        $this->fake->track('sign_up');
        expect(fn () => AnalyticsFake::assertPageViewTracked())->toThrow(\AssertionError::class);
    });

    test('assertEventSequence passes for correct order', function (): void {
        $this->fake->track('sign_up');
        $this->fake->track('login');
        $this->fake->track('purchase');

        AnalyticsFake::assertEventSequence(['sign_up', 'login', 'purchase']);
    });

    test('assertEventSequence ignores unrelated events', function (): void {
        $this->fake->track('sign_up');
        $this->fake->track('error'); // unrelated
        $this->fake->track('login');

        AnalyticsFake::assertEventSequence(['sign_up', 'login']);
    });

    test('assertEventSequence fails for wrong order', function (): void {
        $this->fake->track('login');
        $this->fake->track('sign_up');

        expect(fn () => AnalyticsFake::assertEventSequence(['sign_up', 'login']))->toThrow(\AssertionError::class);
    });

    test('assertEventBatch passes for all present', function (): void {
        $this->fake->track('sign_up');
        $this->fake->track('login');
        $this->fake->track('purchase');

        AnalyticsFake::assertEventBatch(['sign_up', 'purchase']);
    });

    test('assertEventBatch fails for missing', function (): void {
        $this->fake->track('sign_up');

        expect(fn () => AnalyticsFake::assertEventBatch(['sign_up', 'purchase']))->toThrow(\AssertionError::class);
    });

    test('assertSaaSIdentityLinked passes', function (): void {
        $this->fake->trackSaaSIdentity('user-1', 'client-1');

        AnalyticsFake::assertSaaSIdentityLinked('user-1');
    });

    test('assertSaaSIdentityLinked fails for missing', function (): void {
        expect(fn () => AnalyticsFake::assertSaaSIdentityLinked('user-99'))->toThrow(\AssertionError::class);
    });

    test('assertFunnelProgressTracked passes', function (): void {
        $this->fake->funnelProgress('signup', 'form_start', 'user-1', 1, 3);

        AnalyticsFake::assertFunnelProgressTracked('signup');
    });

    test('assertFunnelProgressTracked fails for missing', function (): void {
        expect(fn () => AnalyticsFake::assertFunnelProgressTracked('nonexistent'))->toThrow(\AssertionError::class);
    });
});

// ── Inspection Methods ─────────────────────────────────────────────

describe('AnalyticsFake — inspection', function (): void {
    test('allEvents returns all tracked events', function (): void {
        $this->fake->track('e1');
        $this->fake->track('e2');

        expect($this->fake->allEvents())->toHaveCount(2);
    });

    test('eventCounts groups by name', function (): void {
        $this->fake->track('sign_up');
        $this->fake->track('sign_up');
        $this->fake->track('login');

        $counts = $this->fake->eventCounts();
        expect($counts['sign_up'])->toBe(2);
        expect($counts['login'])->toBe(1);
    });

    test('ecommerceCalls returns calls', function (): void {
        $this->fake->trackEcommerce('purchase', ['value' => 49]);

        expect($this->fake->ecommerceCalls())->toHaveCount(1);
        expect($this->fake->ecommerceCalls()[0]['eventName'])->toBe('purchase');
    });

    test('saasIdentityCalls returns calls', function (): void {
        $this->fake->trackSaaSIdentity('user-1', 'client-1');

        expect($this->fake->saasIdentityCalls())->toHaveCount(1);
    });

    test('funnelProgressCalls returns calls', function (): void {
        $this->fake->funnelProgress('checkout', 'payment', 'user-1', 3, 5);

        expect($this->fake->funnelProgressCalls())->toHaveCount(1);
    });
});

// ── Reset ──────────────────────────────────────────────────────────

describe('AnalyticsFake — reset', function (): void {
    test('reset clears all captured state', function (): void {
        $this->fake->track('e1');
        $this->fake->identify('user-1');
        $this->fake->setConsent(ConsentState::denied());
        $this->fake->trackEcommerce('purchase', []);
        $this->fake->funnelProgress('f', 's', 'u', 1, 3);
        $this->fake->trackSaaSIdentity('u', 'c');

        expect($this->fake->eventCount())->toBeGreaterThan(0);

        $this->fake->reset();

        expect($this->fake->eventCount())->toBe(0);
        expect($this->fake->identifyCalls())->toBeEmpty();
        expect($this->fake->pageViews())->toBeEmpty();
        expect($this->fake->ecommerceCalls())->toBeEmpty();
        expect($this->fake->funnelProgressCalls())->toBeEmpty();
        expect($this->fake->saasIdentityCalls())->toBeEmpty();
        expect($this->fake->getConsent()->isGranted())->toBeTrue(); // Reset to default
    });
});

// ── Version Consistency ────────────────────────────────────────────

describe('AnalyticsFake — version consistency', function (): void {
    test('version matches AnalyticsEvent::VERSION', function (): void {
        expect($this->fake->version())->toBe(AnalyticsEvent::VERSION);
    });
});
