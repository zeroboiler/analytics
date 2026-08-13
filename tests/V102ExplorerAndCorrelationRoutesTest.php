<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

/**
 * V102 — Analytics Data Explorer & Correlation Analyzer Routes Test.
 *
 * Validates that the v60.0.0 route endpoints are registered.
 *
 * @since 60.0.0
 */
test('v60 routes: analytics data explorer routes are registered', function (): void {
    $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
    expect($routes)->not->toBeFalse();

    // Data Explorer routes
    expect($routes)->toContain("Route::get('explorer/health'");
    expect($routes)->toContain("Route::get('explorer/explore'");
    expect($routes)->toContain("Route::get('explorer/top-events'");
    expect($routes)->toContain("Route::get('explorer/drill-down/{eventName}'");
    expect($routes)->toContain("Route::get('explorer/compare'");
    expect($routes)->toContain("Route::get('explorer/funnel'");
});

test('v60 routes: correlation analyzer routes are registered', function (): void {
    $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
    expect($routes)->not->toBeFalse();

    expect($routes)->toContain("Route::get('correlation-analyzer/health'");
    expect($routes)->toContain("Route::get('correlation-analyzer/cross-correlation'");
    expect($routes)->toContain("Route::get('correlation-analyzer/transition'");
    expect($routes)->toContain("Route::get('correlation-analyzer/matrix'");
});

test('v60 routes: controller has all explorer methods', function (): void {
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');
    expect($controller)->not->toBeFalse();

    expect($controller)->toContain('public function explorerHealth()');
    expect($controller)->toContain('public function explorerExplore(');
    expect($controller)->toContain('public function explorerTopEvents(');
    expect($controller)->toContain('public function explorerDrillDown(');
    expect($controller)->toContain('public function explorerCompare(');
    expect($controller)->toContain('public function explorerFunnel(');
});

test('v60 routes: controller has all correlation analyzer methods', function (): void {
    $controller = file_get_contents(__DIR__ . '/../src/Http/Controllers/AnalyticsEventController.php');
    expect($controller)->not->toBeFalse();

    expect($controller)->toContain('public function correlationAnalyzerHealth()');
    expect($controller)->toContain('public function correlationAnalyzerCrossCorrelation(');
    expect($controller)->toContain('public function correlationAnalyzerTransition(');
    expect($controller)->toContain('public function correlationAnalyzerMatrix(');
});

test('v60 routes: route count has increased with new endpoints', function (): void {
    $routes = file_get_contents(__DIR__ . '/../routes/analytics.php');
    preg_match_all("/Route::(get|post|put|patch|delete)\\(/", $routes, $matches);
    // With the 10 new routes, count should be 140+
    expect(count($matches[0]))->toBeGreaterThanOrEqual(140);
});

test('v60 service provider: registers both new services', function (): void {
    $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
    expect($provider)->not->toBeFalse();

    expect($provider)->toContain('AnalyticsDataExplorerService::class');
    expect($provider)->toContain('EventCorrelationAnalyzerService::class');
    expect($provider)->toContain('v60.0.0');
});
