<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ZeroBoiler\Analytics\Blade\Directives\AnalyticsDirectives;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsOverviewCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsTestCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsExportCommand;
use ZeroBoiler\Analytics\Console\Commands\RevenueReportCommand;
use ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Services\EcommerceAnalyticsService;
use ZeroBoiler\Analytics\Bus\AnalyticsDataBus;
use ZeroBoiler\Analytics\Context\EventContextBuilder;
use ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareStack;
use ZeroBoiler\Analytics\Middleware\PiiSanitizationMiddleware;
use ZeroBoiler\Analytics\Pipeline\SamplingFilter;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\Tracking\AnonymousIdTracker;
use ZeroBoiler\Analytics\Services\EventValidationService;
use ZeroBoiler\Analytics\Services\FunnelAnalyticsService;
use ZeroBoiler\Analytics\Services\GoogleAnalyticsService;
use ZeroBoiler\Analytics\Services\GoogleTagManagerService;
use ZeroBoiler\Analytics\Services\MetaPixelService;
use ZeroBoiler\Analytics\Services\RevenueAnalyticsService;
use ZeroBoiler\Analytics\Services\RevenueAttributionService;
use ZeroBoiler\Analytics\Services\SaaSAnalyticsService;
use ZeroBoiler\Analytics\Tracking\ServerSideTracker;
use ZeroBoiler\Analytics\Tracking\SessionTracker;
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;
use ZeroBoiler\Analytics\Queue\EventReplayQueue;
use ZeroBoiler\Analytics\Services\CohortAnalyticsService;
use ZeroBoiler\Analytics\Services\EventAggregationService;
use ZeroBoiler\Analytics\Services\SessionAnalyticsService;
use ZeroBoiler\Analytics\Services\UserJourneyService;
use ZeroBoiler\Analytics\Services\AnomalyDetectionService;
use ZeroBoiler\Analytics\Services\EventStreamService;
use ZeroBoiler\Analytics\Services\ExportService;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsHealthCommand;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsDashboardCommand;
use ZeroBoiler\Analytics\Services\AnalyticsHealthService;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;
use ZeroBoiler\Analytics\Services\TrackingPreferenceService;
use ZeroBoiler\Analytics\Services\EventDeduplicationService;
use ZeroBoiler\Analytics\Services\DeviceContextService;
use ZeroBoiler\Analytics\Services\IpAnonymizationService;
use ZeroBoiler\Analytics\Services\SaasFunnelService;

/**
 * Laravel service provider for the ZeroBoiler Analytics package.
 *
 * Registers the analytics manager, tracker services, pipeline,
 * schema registry, Blade directives, middleware, and API routes.
 */
final class AnalyticsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/zeroboiler.php',
            'zeroboiler',
        );

        $this->app->singleton('zeroboiler.analytics', function (Application $app): AnalyticsManager {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsManager($config);
        });

        $this->app->alias('zeroboiler.analytics', AnalyticsManager::class);

        // Bind convenience service wrappers
        $this->app->singleton(GoogleAnalyticsService::class, function (Application $app): GoogleAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new GoogleAnalyticsService($manager->ga4());
        });

        $this->app->singleton(GoogleTagManagerService::class, function (Application $app): GoogleTagManagerService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new GoogleTagManagerService($manager->gtm());
        });

        $this->app->singleton(MetaPixelService::class, function (Application $app): MetaPixelService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new MetaPixelService($manager->meta());
        });

        $this->app->singleton(ServerSideTracker::class, function (Application $app): ServerSideTracker {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new ServerSideTracker($manager, $config);
        });

        $this->app->singleton(QueuedAnalyticsDispatcher::class, function (Application $app): QueuedAnalyticsDispatcher {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new QueuedAnalyticsDispatcher($manager, $config);
        });

        $this->app->singleton(UserIdentityTracker::class, function (Application $app): UserIdentityTracker {
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $cookieName = $config->get('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id');

            return new UserIdentityTracker($queue, $cookieName);
        });

        $this->app->singleton(EcommerceAnalyticsService::class, function (Application $app): EcommerceAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EcommerceAnalyticsService($manager, $config);
        });

        $this->app->singleton(SaaSAnalyticsService::class, function (Application $app): SaaSAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new SaaSAnalyticsService($manager);
        });

        // PII sanitization middleware (configurable strategy)
        $this->app->bind(PiiSanitizationMiddleware::class, function (Application $app): PiiSanitizationMiddleware {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $piiConfig = $config->get('zeroboiler.analytics.pii_sanitization', []);
            /** @var array{enabled?: bool, strategy?: string, custom_fields?: list<string>} $piiConfig */

            $strategy = $piiConfig['strategy'] ?? PiiSanitizationMiddleware::STRATEGY_HASH;
            $customFields = $piiConfig['custom_fields'] ?? null;

            return new PiiSanitizationMiddleware(
                piiFields: $customFields,
                strategy: $strategy,
            );
        });

        // Event sampling filter for high-traffic applications
        $this->app->bind(SamplingFilter::class, function (Application $app): SamplingFilter {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $samplingConfig = $config->get('zeroboiler.analytics.sampling', []);
            /** @var array{enabled?: bool, rate?: float, deterministic?: bool} $samplingConfig */

            $rate = (float) ($samplingConfig['rate'] ?? 1.0);
            $deterministic = (bool) ($samplingConfig['deterministic'] ?? true);

            return new SamplingFilter(
                sampleRate: $rate,
                deterministic: $deterministic,
            );
        });

        // Anonymous ID tracker for persistent client-side identifiers
        $this->app->singleton(AnonymousIdTracker::class, function (Application $app): AnonymousIdTracker {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnonymousIdTracker($config);
        });

        $this->app->singleton(EventValidationService::class, function (Application $app): EventValidationService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventValidationService($config);
        });

        $this->app->singleton(SessionTracker::class, function (Application $app): SessionTracker {
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');

            return new SessionTracker($queue, $manager);
        });

        $this->app->singleton(RevenueAnalyticsService::class, function (Application $app): RevenueAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $ecommerce = $config->get('zeroboiler.analytics.ecommerce', []);
            /** @var array{currency?: string} $ecommerce */

            return new RevenueAnalyticsService($manager, $ecommerce['currency'] ?? 'USD');
        });

        $this->app->bind(EventPipeline::class);

        $this->app->singleton(EventSchemaRegistry::class);
        $this->app->bind(AnalyticsMiddlewareStack::class);
        $this->app->bind(EventContextBuilder::class);

        // Funnel analytics service
        $this->app->singleton(FunnelAnalyticsService::class, function (Application $app): FunnelAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new FunnelAnalyticsService($manager, $queue, $config);
        });

        // Revenue attribution service
        $this->app->singleton(RevenueAttributionService::class, function (Application $app): RevenueAttributionService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $ecommerce = $config->get('zeroboiler.analytics.ecommerce', []);
            /** @var array{currency?: string} $ecommerce */
            $queueConfig = $config->get('zeroboiler.analytics.queue', []);
            /** @var array{enabled?: bool} $queueConfig */

            return new RevenueAttributionService(
                $manager,
                $queue,
                $ecommerce['currency'] ?? 'USD',
                (bool) ($queueConfig['enabled'] ?? true),
            );
        });

        // Analytics data bus for conditional routing
        $this->app->singleton(AnalyticsDataBus::class, function (Application $app): AnalyticsDataBus {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $queueConfig = $config->get('zeroboiler.analytics.queue', []);
            /** @var array{enabled?: bool} $queueConfig */

            return new AnalyticsDataBus(
                $manager,
                $queue,
                (bool) ($queueConfig['enabled'] ?? true),
            );
        });

        // Event replay queue for failed event retry with backoff
        $this->app->singleton(EventReplayQueue::class, function (Application $app): EventReplayQueue {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventReplayQueue($manager, $manager->metrics(), $config);
        });

        // Session analytics service
        $this->app->singleton(SessionAnalyticsService::class, function (Application $app): SessionAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $queueConfig = $config->get('zeroboiler.analytics.queue', []);
            /** @var array{enabled?: bool} $queueConfig */

            return new SessionAnalyticsService(
                $manager,
                $queue,
                (bool) ($queueConfig['enabled'] ?? true),
            );
        });

        // Event aggregation service
        $this->app->singleton(EventAggregationService::class, function (Application $app): EventAggregationService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();
            /** @var EventReplayQueue $replayQueue */
            $replayQueue = $app->make(EventReplayQueue::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new EventAggregationService($manager, $metrics, $replayQueue, $config);
        });

        // Cohort analytics service
        $this->app->singleton(CohortAnalyticsService::class, function (Application $app): CohortAnalyticsService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $queueConfig = $config->get('zeroboiler.analytics.queue', []);
            /** @var array{enabled?: bool} $queueConfig */

            return new CohortAnalyticsService(
                $manager,
                $queue,
                (bool) ($queueConfig['enabled'] ?? true),
            );
        });

        // User journey mapping service
        $this->app->singleton(UserJourneyService::class, function (Application $app): UserJourneyService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            $queueConfig = $config->get('zeroboiler.analytics.queue', []);
            /** @var array{enabled?: bool} $queueConfig */

            return new UserJourneyService(
                $manager,
                $queue,
                (bool) ($queueConfig['enabled'] ?? true),
            );
        });

        // Anomaly detection service
        $this->app->singleton(AnomalyDetectionService::class, function (Application $app): AnomalyDetectionService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);

            return new AnomalyDetectionService($manager, $metrics, $queue);
        });

        // Event stream service for real-time dashboards
        $this->app->singleton(EventStreamService::class, function (Application $app): EventStreamService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();

            $config = $app->make(ConfigRepository::class);
            $streamConfig = $config->get('zeroboiler.analytics.stream', []);
            /** @var array{buffer_size?: int} $streamConfig */
            $bufferSize = (int) ($streamConfig['buffer_size'] ?? 1000);

            return new EventStreamService($manager, $metrics, $bufferSize);
        });

        // Export service
        $this->app->singleton(ExportService::class, function (Application $app): ExportService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();
            /** @var EventStreamService $stream */
            $stream = $app->make(EventStreamService::class);

            return new ExportService($manager, $metrics, $stream);
        });

        // Analytics health service for programmatic health checks
        $this->app->singleton(AnalyticsHealthService::class, function (Application $app): AnalyticsHealthService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var AnalyticsMetrics $metrics */
            $metrics = $manager->metrics();
            /** @var EventReplayQueue $replayQueue */
            $replayQueue = $app->make(EventReplayQueue::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsHealthService($manager, $metrics, $replayQueue, $config);
        });

        // Type-safe analytics config accessor
        $this->app->singleton(AnalyticsConfig::class, function (Application $app): AnalyticsConfig {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new AnalyticsConfig($config);
        });

        // Tracking preference service for per-user GDPR opt-out
        $this->app->singleton(TrackingPreferenceService::class, function (Application $app): TrackingPreferenceService {
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');
            /** @var ConfigRepository $config */
            $trackingConfig = $app->make(ConfigRepository::class)->get('zeroboiler.analytics.tracking_preference', []);
            /** @var array{ttl?: int} $trackingConfig */

            return new TrackingPreferenceService(
                $cache,
                isset($trackingConfig['ttl']) ? (int) $trackingConfig['ttl'] : null,
            );
        });

        // Event deduplication service (cache-based fingerprint tracking)
        $this->app->singleton(EventDeduplicationService::class, function (Application $app): EventDeduplicationService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var \Illuminate\Contracts\Cache\Repository $cache */
            $cache = $app->make('cache');

            return new EventDeduplicationService($config, $cache);
        });

        // Device context service (User-Agent parsing)
        $this->app->singleton(DeviceContextService::class);

        // IP anonymization service (GDPR compliance)
        $this->app->singleton(IpAnonymizationService::class, function (Application $app): IpAnonymizationService {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new IpAnonymizationService($config);
        });

        // SaaS lifecycle funnel service
        $this->app->singleton(SaasFunnelService::class, function (Application $app): SaasFunnelService {
            /** @var AnalyticsManager $manager */
            $manager = $app->make('zeroboiler.analytics');
            /** @var QueuedAnalyticsDispatcher $queue */
            $queue = $app->make(QueuedAnalyticsDispatcher::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new SaasFunnelService($manager, $queue, $config);
        });
    }

    /**
     * Bootstrap any application services.
     */
    #[\Override]
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes(
                [__DIR__.'/../config/zeroboiler.php' => $this->app->configPath('zeroboiler.php')],
                'zeroboiler-analytics-config',
            );

            $this->commands([
                AnalyticsTestCommand::class,
                AnalyticsOverviewCommand::class,
                AnalyticsExportCommand::class,
                RevenueReportCommand::class,
                AnalyticsHealthCommand::class,
                AnalyticsDashboardCommand::class,
            ]);
        }

        $this->registerBladeDirectives();
        $this->registerMiddleware();
        $this->registerAutoTracking();
        $this->registerRoutes();
    }

    /**
     * Register Blade directives for analytics.
     */
    private function registerBladeDirectives(): void
    {
        $this->app->afterResolving('blade.compiler', function (): void {
            AnalyticsDirectives::register();
        });
    }

    /**
     * Register the analytics middleware as a scoped alias.
     */
    private function registerMiddleware(): void
    {
        $router = $this->app->make('router');

        if (is_object($router) && method_exists($router, 'aliasMiddleware')) {
            $router->aliasMiddleware(
                'analytics.scripts',
                InjectAnalyticsScripts::class,
            );
            $router->aliasMiddleware(
                'analytics.inertia',
                HandleInertiaAnalytics::class,
            );
        }
    }

    /**
     * Register server-side auto-tracking of Laravel events.
     */
    private function registerAutoTracking(): void
    {
        $tracker = $this->app->make(ServerSideTracker::class);
        /** @var \Illuminate\Contracts\Events\Dispatcher $dispatcher */
        $dispatcher = $this->app->make('events');

        $tracker->register($dispatcher);

        // Register custom application events from config
        $config = $this->app->make(ConfigRepository::class);
        $autoTrack = $config->get('zeroboiler.analytics.auto_track', []);
        /** @var array{events?: array<string, bool>, models?: array<class-string, array<int, string>>} $autoTrack */
        $customEvents = $autoTrack['events'] ?? [];

        foreach (array_keys($customEvents) as $eventName) {
            if (str_starts_with($eventName, 'auth.')) {
                continue; // Laravel framework events, already registered
            }

            $tracker->listen($eventName, $dispatcher);
        }

        // Register Eloquent model listeners
        /** @var array<class-string, array<int, string>> $modelEvents */
        $modelEvents = $autoTrack['models'] ?? [];
        if (! empty($modelEvents)) {
            $tracker->registerModelListeners($modelEvents);
        }
    }

    /**
     * Register analytics API routes.
     */
    private function registerRoutes(): void
    {
        if ($this->app instanceof \Illuminate\Foundation\Application && $this->app->routesAreCached()) {
            return;
        }

        $controller = \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class;

        // Health + Catalog + Stream + Export endpoints — no auth required
        Route::prefix('api')
            ->middleware(['throttle:120,1'])
            ->group(function () use ($controller): void {
                Route::get('analytics/health', [$controller, 'health']);
                Route::get('analytics/catalog', [$controller, 'catalog']);
                Route::get('analytics/stream', [$controller, 'stream']);
                Route::get('analytics/stream/stats', [$controller, 'streamStats']);
                Route::get('analytics/export', [$controller, 'export']);
            });

        // Authenticated endpoints
        Route::prefix('api')
            ->middleware(['auth:sanctum', 'throttle:60,1'])
            ->group(function () use ($controller): void {
                Route::post('analytics/events', [$controller, 'track']);
                Route::post('analytics/batch', [$controller, 'batch']);
                Route::post('analytics/identify', [$controller, 'identify']);
                Route::post('analytics/pageview', [$controller, 'pageview']);
                Route::post('analytics/consent', [$controller, 'updateConsent']);
                Route::post('analytics/opt-out', [$controller, 'optOut']);
                Route::post('analytics/opt-in', [$controller, 'optIn']);
                Route::get('analytics/preference', [$controller, 'preference']);
            });
    }
}
