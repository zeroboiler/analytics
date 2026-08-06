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
use ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts;
use ZeroBoiler\Analytics\Inertia\HandleInertiaAnalytics;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Pipeline\EventPipeline;
use ZeroBoiler\Analytics\Services\EcommerceAnalyticsService;
use ZeroBoiler\Analytics\Context\EventContextBuilder;
use ZeroBoiler\Analytics\Middleware\AnalyticsMiddlewareStack;
use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\Services\EventValidationService;
use ZeroBoiler\Analytics\Services\GoogleAnalyticsService;
use ZeroBoiler\Analytics\Services\GoogleTagManagerService;
use ZeroBoiler\Analytics\Services\MetaPixelService;
use ZeroBoiler\Analytics\Services\RevenueAnalyticsService;
use ZeroBoiler\Analytics\Services\SaaSAnalyticsService;
use ZeroBoiler\Analytics\Tracking\ServerSideTracker;
use ZeroBoiler\Analytics\Tracking\SessionTracker;
use ZeroBoiler\Analytics\Tracking\UserIdentityTracker;

/**
 * Laravel service provider for the ZeroBoiler Analytics package.
 *
 * Registers the analytics manager, tracker services, pipeline,
 * schema registry, Blade directives, middleware, and API routes.
 */
class AnalyticsServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
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

        // Health endpoint — no auth required
        Route::prefix('api')
            ->middleware(['throttle:120,1'])
            ->group(function (): void {
                Route::get('analytics/health', [\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class, 'health']);
            });

        // Authenticated endpoints
        Route::prefix('api')
            ->middleware(['auth:sanctum', 'throttle:60,1'])
            ->group(function (): void {
                Route::post('analytics/events', [\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class, 'track']);
                Route::post('analytics/batch', [\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class, 'batch']);
                Route::post('analytics/identify', [\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class, 'identify']);
                Route::post('analytics/pageview', [\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class, 'pageview']);
                Route::post('analytics/consent', [\ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::class, 'updateConsent']);
            });
    }
}
