<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\AnalyticsMetrics;
use ZeroBoiler\Analytics\AnalyticsServiceProvider;
use ZeroBoiler\Analytics\EventInterceptorRegistry;
use ZeroBoiler\Analytics\Blade\Directives\AnalyticsDirectives;
use ZeroBoiler\Analytics\Bus\AnalyticsDataBus;
use ZeroBoiler\Analytics\Context\EventContextBuilder;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController;
use ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareStack;
use ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareInterface;
use ZeroBoiler\Analytics\Middleware\AuditLogMiddleware;
use ZeroBoiler\Analytics\Middleware\ConsentGateMiddleware;
use ZeroBoiler\Analytics\Middleware\ContextAttachmentMiddleware;
use ZeroBoiler\Analytics\Middleware\LoggingMiddleware;
use ZeroBoiler\Analytics\Middleware\PiiSanitizationMiddleware;
use ZeroBoiler\Analytics\Middleware\SchemaValidationMiddleware;
use ZeroBoiler\Analytics\Middleware\TimestampMiddleware;
use ZeroBoiler\Analytics\Pipeline\ConsentFilter;
use ZeroBoiler\Analytics\Pipeline\EventDebounceFilter;
use ZeroBoiler\Analytics\Pipeline\EventMetadataEnricher;
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Pipeline\SamplingFilter;
use ZeroBoiler\Analytics\Pipeline\SchemaEnricher;
use ZeroBoiler\Analytics\Pipeline\TimestampEnricher;
use ZeroBoiler\Analytics\Pipeline\UtmEnricher;
use ZeroBoiler\Analytics\Pipeline\UserContextEnricher;
use ZeroBoiler\Analytics\Queue\EventReplayQueue;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\Services\AnalyticsHealthService;
use ZeroBoiler\Analytics\Services\AnalyticsProfileService;
use ZeroBoiler\Analytics\Services\AnalyticsStatsService;
use ZeroBoiler\Analytics\Services\AnomalyDetectionService;
use ZeroBoiler\Analytics\Services\AttributionService;
use ZeroBoiler\Analytics\Services\CohortAnalyticsService;
use ZeroBoiler\Analytics\Services\DeviceContextService;
use ZeroBoiler\Analytics\Services\EcommerceAnalyticsService;
use ZeroBoiler\Analytics\Services\EventAggregationService;
use ZeroBoiler\Analytics\Services\EventAlertRulesService;
use ZeroBoiler\Analytics\Services\EventDeduplicationService;
use ZeroBoiler\Analytics\Services\EventStreamService;
use ZeroBoiler\Analytics\Services\EventValidationService;
use ZeroBoiler\Analytics\Services\ExportService;
use ZeroBoiler\Analytics\Services\FunnelAnalyticsService;
use ZeroBoiler\Analytics\Services\FunnelDataBuilderService;
use ZeroBoiler\Analytics\Services\GdprErasureService;
use ZeroBoiler\Analytics\Services\GoogleAnalyticsService;
use ZeroBoiler\Analytics\Services\GoogleTagManagerService;
use ZeroBoiler\Analytics\Services\InboundWebhookService;
use ZeroBoiler\Analytics\Services\IpAnonymizationService;
use ZeroBoiler\Analytics\Services\MetaPixelService;
use ZeroBoiler\Analytics\Services\RevenueAnalyticsService;
use ZeroBoiler\Analytics\Services\RevenueAttributionService;
use ZeroBoiler\Analytics\Services\SaaSAnalyticsService;
use ZeroBoiler\Analytics\Services\SaasFunnelService;
use ZeroBoiler\Analytics\Services\SessionAnalyticsService;
use ZeroBoiler\Analytics\Services\TrackingPreferenceService;
use ZeroBoiler\Analytics\Services\UserJourneyService;
use ZeroBoiler\Analytics\Services\ConsentLogService;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;
use ZeroBoiler\Analytics\Support\AnalyticsRateLimiter;
use ZeroBoiler\Analytics\Support\EventTransformer;
use ZeroBoiler\Analytics\Support\WebhookSignatureValidator;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;
use ZeroBoiler\Analytics\Trackers\PlausibleTracker;
use ZeroBoiler\Analytics\Trackers\PosthogTracker;
use ZeroBoiler\Analytics\Trackers\TrackerInterface;
use ZeroBoiler\Analytics\Trackers\WebhookTracker;
use ZeroBoiler\Analytics\Tracking\AnonymousIdTracker;
use ZeroBoiler\Analytics\Tracking\ServerSideTracker;
use ZeroBoiler\Analytics\Tracking\SessionTracker;
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;

// ─── AnalyticsEvent DTO ────────────────────────────────────────────────────

describe('AnalyticsEvent DTO', function () {
    it('is readonly', function () {
        expect((new ReflectionClass(AnalyticsEvent::class))->isReadOnly())->toBeTrue();
    });

    it('stores event data immutably', function () {
        $event = new AnalyticsEvent(name: 'test_event', params: ['key' => 'value'], clientId: 'abc', userId: '123');

        expect($event->name)->toBe('test_event');
        expect($event->params)->toBe(['key' => 'value']);
        expect($event->clientId)->toBe('abc');
        expect($event->userId)->toBe('123');
    });

    it('allows null clientId and userId', function () {
        $event = new AnalyticsEvent(name: 'page_view', params: []);

        expect($event->clientId)->toBeNull();
        expect($event->userId)->toBeNull();
    });
});

// ─── ConsentState DTO ─────────────────────────────────────────────────────

describe('ConsentState DTO', function () {
    it('creates granted state with all signals', function () {
        $state = ConsentState::granted();

        expect($state->hasAnalyticsConsent())->toBeTrue();
        expect($state->hasAdConsent())->toBeTrue();
        expect($state->isGranted('ad_user_data'))->toBeTrue();
        expect($state->isGranted('ad_personalization'))->toBeTrue();
        expect($state->isGranted('functionality_storage'))->toBeTrue();
        expect($state->isGranted('personalization_storage'))->toBeTrue();
    });

    it('creates denied state with analytics and ad denied', function () {
        $state = ConsentState::denied();

        expect($state->hasAnalyticsConsent())->toBeFalse();
        expect($state->hasAdConsent())->toBeFalse();
        // security_storage is always granted per Google spec
        expect($state->isGranted('security_storage'))->toBeTrue();
    });

    it('isGranted returns false for unset signals', function () {
        $state = new ConsentState;

        expect($state->hasAnalyticsConsent())->toBeFalse();
        expect($state->hasAdConsent())->toBeFalse();
    });

    it('with() returns new state with merged signals', function () {
        $state = ConsentState::denied();
        $updated = $state->with(['analytics_storage' => 'granted']);

        expect($updated->hasAnalyticsConsent())->toBeTrue();
        expect($updated->hasAdConsent())->toBeFalse();
        // Original state unchanged (immutability)
        expect($state->hasAnalyticsConsent())->toBeFalse();
    });

    it('toArray returns all signals', function () {
        $state = ConsentState::granted();
        $arr = $state->toArray();

        expect($arr)->toBeArray();
        expect($arr['analytics_storage'])->toBe('granted');
        expect($arr['security_storage'])->toBe('granted');
    });

    it('isDenied returns true for denied signals', function () {
        $state = ConsentState::denied();

        expect($state->isDenied('analytics_storage'))->toBeTrue();
        expect($state->isDenied('ad_storage'))->toBeTrue();
    });
});

// ─── EventInterceptorRegistry ────────────────────────────────────────────

describe('EventInterceptorRegistry', function () {
    it('registers and runs before-interceptors', function () {
        $registry = new EventInterceptorRegistry;

        $registry->before(function (AnalyticsEvent $event): ?AnalyticsEvent {
            return new AnalyticsEvent(
                name: $event->name . '_modified',
                params: $event->params,
            );
        });

        $event = new AnalyticsEvent(name: 'click', params: []);
        $result = $registry->runBefore($event);

        expect($result)->not->toBeNull();
        expect($result->name)->toBe('click_modified');
    });

    it('before-interceptor can cancel dispatch by returning null', function () {
        $registry = new EventInterceptorRegistry;

        $registry->before(fn (): ?AnalyticsEvent => null);

        $event = new AnalyticsEvent(name: 'click', params: []);
        $result = $registry->runBefore($event);

        expect($result)->toBeNull();
    });

    it('runs after-interceptors', function () {
        $registry = new EventInterceptorRegistry;

        $afterCalled = false;
        $registry->after(function (AnalyticsEvent $event, bool $success) use (&$afterCalled): void {
            $afterCalled = true;
        });

        $event = new AnalyticsEvent(name: 'click', params: []);
        $registry->runAfter($event, true);

        expect($afterCalled)->toBeTrue();
    });

    it('after-interceptor errors are caught silently', function () {
        $registry = new EventInterceptorRegistry;

        $registry->after(function (): void {
            throw new \RuntimeException('test error');
        });

        $event = new AnalyticsEvent(name: 'click', params: []);
        $registry->runAfter($event, true);

        expect($registry->afterCount())->toBe(1);
    });

    it('flush clears all interceptors', function () {
        $registry = new EventInterceptorRegistry;
        $registry->before(fn (): ?AnalyticsEvent => null);
        $registry->after(fn (): void => null);

        $registry->flush();

        expect($registry->beforeCount())->toBe(0);
        expect($registry->afterCount())->toBe(0);
    });
});

// ─── AnalyticsMetrics ────────────────────────────────────────────────────

describe('AnalyticsMetrics', function () {
    it('records dispatch and failure counts', function () {
        $metrics = new AnalyticsMetrics;
        $metrics->setEnabled(true);

        $metrics->recordDispatch('ga4');
        $metrics->recordDispatch('ga4');
        $metrics->recordDispatch('gtm');
        $metrics->recordFailure('meta', 'timeout');

        expect($metrics->totalDispatched())->toBe(3);
        expect($metrics->totalFailed())->toBe(1);
        expect($metrics->dispatchedByProvider())->toBe(['ga4' => 2, 'gtm' => 1]);
    });

    it('returns full summary', function () {
        $metrics = new AnalyticsMetrics;
        $metrics->setEnabled(true);

        $metrics->recordDispatch('ga4');
        $metrics->recordFiltered();
        $metrics->recordDeduplicated();

        $summary = $metrics->summary();

        expect($summary['total_dispatched'])->toBe(1);
        expect($summary['filtered'])->toBe(1);
        expect($summary['deduplicated'])->toBe(1);
    });

    it('flush resets counters', function () {
        $metrics = new AnalyticsMetrics;
        $metrics->setEnabled(true);

        $metrics->recordDispatch('ga4');
        $metrics->flush();

        expect($metrics->totalDispatched())->toBe(0);
    });

    it('setEnabled returns self for fluent chaining', function () {
        $metrics = new AnalyticsMetrics;

        $result = $metrics->setEnabled(true);

        expect($result)->toBe($metrics);
        expect($metrics->isEnabled())->toBeTrue();
    });
});

// ─── EventCatalog ────────────────────────────────────────────────────────

describe('EventCatalog', function () {
    it('contains ecommerce events', function () {
        expect(EventCatalog::has('purchase'))->toBeTrue();
        expect(EventCatalog::has('add_to_cart'))->toBeTrue();
        expect(EventCatalog::has('view_item'))->toBeTrue();
    });

    it('contains SaaS events', function () {
        expect(EventCatalog::has('sign_up'))->toBeTrue();
        expect(EventCatalog::has('login'))->toBeTrue();
        expect(EventCatalog::has('subscription'))->toBeTrue();
    });

    it('contains engagement events', function () {
        expect(EventCatalog::has('page_view'))->toBeTrue();
        expect(EventCatalog::has('click'))->toBeTrue();
    });

    it('returns category for known events', function () {
        $category = EventCatalog::getCategory('purchase');
        expect($category)->toBe('ecommerce');
    });

    it('returns null for unknown events', function () {
        $entry = EventCatalog::get('nonexistent_event_xyz');
        expect($entry)->toBeNull();
    });
});

// ─── Event Categories ────────────────────────────────────────────────────

describe('Event Categories', function () {
    it('EcommerceEvents returns correct count', function () {
        expect(EcommerceEvents::count())->toBeGreaterThan(0);
    });

    it('SaaSEvents returns correct count', function () {
        expect(SaaSEvents::count())->toBeGreaterThan(0);
    });

    it('EngagementEvents returns correct count', function () {
        expect(EngagementEvents::count())->toBeGreaterThan(0);
    });
});

// ─── Structural Checks ──────────────────────────────────────────────────

describe('Structural Checks', function () {
    it('all source files declare strict types', function () {
        $files = glob(__DIR__.'/../src/**/*.php');

        expect($files)->not->toBeEmpty();

        foreach ($files as $file) {
            $content = file_get_contents($file);
            expect($content)->toContain('declare(strict_types=1)');
        }
    });

    it('AnalyticsManager is final', function () {
        expect((new ReflectionClass(AnalyticsManager::class))->isFinal())->toBeTrue();
    });

    it('AnalyticsManager constructor has :void return type', function () {
        $constructor = (new ReflectionClass(AnalyticsManager::class))->getConstructor();

        expect($constructor)->not->toBeNull();
        expect($constructor->hasReturnType())->toBeTrue();
        expect((string) $constructor->getReturnType())->toBe('void');
    });

    it('AnalyticsServiceProvider is final', function () {
        expect((new ReflectionClass(AnalyticsServiceProvider::class))->isFinal())->toBeTrue();
    });

    it('AnalyticsMetrics is final', function () {
        expect((new ReflectionClass(AnalyticsMetrics::class))->isFinal())->toBeTrue();
    });

    it('EventInterceptorRegistry is final', function () {
        expect((new ReflectionClass(EventInterceptorRegistry::class))->isFinal())->toBeTrue();
    });

    it('all tracker classes are final and implement TrackerInterface', function () {
        $trackers = [
            GA4Tracker::class,
            GTMTracker::class,
            MetaPixelTracker::class,
            PlausibleTracker::class,
            PosthogTracker::class,
            WebhookTracker::class,
        ];

        foreach ($trackers as $tracker) {
            $reflection = new ReflectionClass($tracker);
            expect($reflection->isFinal(), "{$tracker} should be final")->toBeTrue();
            expect($reflection->implementsInterface(TrackerInterface::class))
                ->toBeTrue("{$tracker} should implement TrackerInterface");
        }
    });

    it('pipeline classes are final', function () {
        $pipelines = [
            EventPipeline::class,
            ConsentFilter::class,
            SamplingFilter::class,
            EventDebounceFilter::class,
            EventMetadataEnricher::class,
            SchemaEnricher::class,
            TimestampEnricher::class,
            UtmEnricher::class,
            UserContextEnricher::class,
        ];

        foreach ($pipelines as $pipeline) {
            expect((new ReflectionClass($pipeline))->isFinal(), "{$pipeline} should be final")->toBeTrue();
        }
    });

    it('middleware classes are final and implement AnalyticsMiddlewareInterface', function () {
        $middleware = [
            ConsentGateMiddleware::class,
            ContextAttachmentMiddleware::class,
            SchemaValidationMiddleware::class,
            TimestampMiddleware::class,
            LoggingMiddleware::class,
            PiiSanitizationMiddleware::class,
            AuditLogMiddleware::class,
        ];

        foreach ($middleware as $m) {
            $reflection = new ReflectionClass($m);
            expect($reflection->isFinal(), "{$m} should be final")->toBeTrue();
            expect($reflection->implementsInterface(AnalyticsMiddlewareInterface::class))
                ->toBeTrue("{$m} should implement AnalyticsMiddlewareInterface");
        }
    });

    it('AnalyticsMiddlewareStack is final and implements Countable', function () {
        $reflection = new ReflectionClass(AnalyticsMiddlewareStack::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->implementsInterface(\Countable::class))->toBeTrue();
    });

    it('EventSchemaRegistry is final and implements Countable', function () {
        $reflection = new ReflectionClass(EventSchemaRegistry::class);
        expect($reflection->isFinal())->toBeTrue();
        expect($reflection->implementsInterface(\Countable::class))->toBeTrue();
    });

    it('EventContextBuilder is final', function () {
        expect((new ReflectionClass(EventContextBuilder::class))->isFinal())->toBeTrue();
    });

    it('service classes are final', function () {
        $services = [
            EventValidationService::class,
            MetaPixelService::class,
            GoogleAnalyticsService::class,
            GoogleTagManagerService::class,
            EcommerceAnalyticsService::class,
            SaaSAnalyticsService::class,
            FunnelAnalyticsService::class,
            RevenueAttributionService::class,
            CohortAnalyticsService::class,
            RevenueAnalyticsService::class,
            AnalyticsHealthService::class,
            AnalyticsProfileService::class,
            AnalyticsStatsService::class,
            AnomalyDetectionService::class,
            AttributionService::class,
            DeviceContextService::class,
            EventAggregationService::class,
            EventAlertRulesService::class,
            EventDeduplicationService::class,
            EventStreamService::class,
            ExportService::class,
            FunnelDataBuilderService::class,
            GdprErasureService::class,
            InboundWebhookService::class,
            IpAnonymizationService::class,
            SaasFunnelService::class,
            SessionAnalyticsService::class,
            TrackingPreferenceService::class,
            UserJourneyService::class,
            ConsentLogService::class,
        ];

        foreach ($services as $service) {
            expect((new ReflectionClass($service))->isFinal(), "{$service} should be final")->toBeTrue();
        }
    });

    it('bus and queue classes are final', function () {
        $classes = [
            AnalyticsDataBus::class,
            QueuedAnalyticsDispatcher::class,
            EventReplayQueue::class,
        ];

        foreach ($classes as $class) {
            expect((new ReflectionClass($class))->isFinal(), "{$class} should be final")->toBeTrue();
        }
    });

    it('tracking classes are final', function () {
        $tracking = [
            UserIdentityTracker::class,
            AnonymousIdTracker::class,
            ServerSideTracker::class,
            SessionTracker::class,
        ];

        foreach ($tracking as $t) {
            expect((new ReflectionClass($t))->isFinal(), "{$t} should be final")->toBeTrue();
        }
    });

    it('support classes are final', function () {
        $support = [
            AnalyticsConfig::class,
            AnalyticsRateLimiter::class,
            EventTransformer::class,
            WebhookSignatureValidator::class,
            AnalyticsDirectives::class,
        ];

        foreach ($support as $s) {
            expect((new ReflectionClass($s))->isFinal(), "{$s} should be final")->toBeTrue();
        }
    });

    it('console commands are final', function () {
        $commands = [
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand::class,
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand::class,
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsExportCommand::class,
            \ZeroBoiler\Analytics\Console\Commands\RevenueReportCommand::class,
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthCommand::class,
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsDashboardCommand::class,
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsReadinessCommand::class,
            \ZeroBoiler\Analytics\Console\Commands\AnalyticsSchemaExportCommand::class,
        ];

        foreach ($commands as $command) {
            expect((new ReflectionClass($command))->isFinal(), "{$command} should be final")->toBeTrue();
        }
    });

    it('controller and middleware classes are final', function () {
        $classes = [
            AnalyticsEventController::class,
            InjectAnalyticsScripts::class,
            HandleInertiaAnalytics::class,
        ];

        foreach ($classes as $class) {
            expect((new ReflectionClass($class))->isFinal(), "{$class} should be final")->toBeTrue();
        }
    });

    it('AnalyticsEvent DTO is readonly', function () {
        expect((new ReflectionClass(AnalyticsEvent::class))->isReadOnly())->toBeTrue();
    });

    it('Facade is final with #[Override]', function () {
        $r = new ReflectionClass(\ZeroBoiler\Analytics\Facades\Analytics::class);
        expect($r->isFinal())->toBeTrue();
        $m = $r->getMethod('getFacadeAccessor');
        $has = array_any($m->getAttributes(), fn (ReflectionAttribute $a): bool => $a->getName() === 'Override');
        expect($has)->toBeTrue();
    });

    it('composer.json has minimum-stability stable', function () {
        $composer = json_decode(
            file_get_contents(__DIR__.'/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($composer['minimum-stability'])->toBe('stable');
        expect($composer['prefer-stable'])->toBeTrue();
    });

    it('composer.json requires PHP 8.5+', function () {
        $composer = json_decode(
            file_get_contents(__DIR__.'/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($composer['require']['php'])->toBe('^8.5');
    });

    it('no TODO or FIXME markers in source', function () {
        $files = glob(__DIR__.'/../src/**/*.php');

        foreach ($files as $file) {
            $content = file_get_contents($file);

            expect($content)->not->toContain('TODO');
            expect($content)->not->toContain('FIXME');
            expect($content)->not->toContain('HACK');
        }
    });

    it('all constructors on final leaf services have :void return type', function () {
        $leafServices = [
            MetaPixelService::class,
            GoogleAnalyticsService::class,
            GoogleTagManagerService::class,
            EventSchemaRegistry::class,
        ];

        foreach ($leafServices as $class) {
            $constructor = (new ReflectionClass($class))->getConstructor();

            expect($constructor->hasReturnType(), "{$class} constructor should have :void return type")
                ->toBeTrue();
        }
    });
});
