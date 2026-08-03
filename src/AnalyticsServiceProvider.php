<?php

declare(strict_types=1);

namespace ZeroBoiler\Analytics;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use ZeroBoiler\Analytics\Blade\Directives\AnalyticsDirectives;
use ZeroBoiler\Analytics\Http\Middleware\InjectAnalyticsScripts;
use ZeroBoiler\Analytics\Services\GoogleAnalyticsService;
use ZeroBoiler\Analytics\Services\GoogleTagManagerService;
use ZeroBoiler\Analytics\Services\MetaPixelService;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes(
                [__DIR__.'/../config/zeroboiler.php' => $this->app->configPath('zeroboiler.php')],
                'zeroboiler-analytics-config',
            );
        }

        $this->registerBladeDirectives();
        $this->registerMiddleware();
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
        }
    }
}
