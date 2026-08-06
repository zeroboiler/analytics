<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Blade\Directives;

use Illuminate\Support\Facades\Blade;

/**
 * Registers Blade directives for analytics script injection.
 *
 * Provides `@analyticsHead`, `@analyticsBody`, `@analyticsTrack`, and
 * `@dataLayerPush` directives for traditional Laravel Blade templates.
 */
final class AnalyticsDirectives
{
    /**
     * Register all analytics-related Blade directives.
     */
    public static function register(): void
    {
        Blade::directive('analyticsHead', function (): string {
            return "<?php echo app('zeroboiler.analytics')->headScripts(); ?>";
        });

        Blade::directive('analyticsBody', function (): string {
            return "<?php echo app('zeroboiler.analytics')->bodyScripts(); ?>";
        });

        Blade::directive('analyticsTrack', function (string $expression): string {
            return "<?php app('zeroboiler.analytics')->track({$expression}); ?>";
        });

        Blade::directive('dataLayerPush', function (string $expression): string {
            return "<?php app('zeroboiler.analytics')->push({$expression}); ?>";
        });
    }
}
