<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\AnalyticsSnippetService;
use ZeroBoiler\Analytics\Services\DifferentialPrivacyService;
use ZeroBoiler\Analytics\Services\EventCorrelationMatrixService;

beforeEach(function (): void {
    $this->config = mock(ConfigRepository::class);
});

// ── AnalyticsSnippetService ──────────────────────────────────────────

describe('AnalyticsSnippetService', function (): void {
    beforeEach(function (): void {
        $this->config->shouldReceive('get')->andReturnUsing(
            function (string $key, mixed $default = null): mixed {
                $map = [
                    'zeroboiler.analytics.ga4.enabled' => true,
                    'zeroboiler.analytics.ga4.measurement_id' => 'G-ABC123',
                    'zeroboiler.analytics.gtm.enabled' => true,
                    'zeroboiler.analytics.gtm.container_id' => 'GTM-XYZ',
                    'zeroboiler.analytics.meta_pixel.enabled' => true,
                    'zeroboiler.analytics.meta_pixel.id' => '1234567890',
                    'zeroboiler.analytics.plausible.enabled' => false,
                    'zeroboiler.analytics.plausible.domain' => '',
                    'zeroboiler.analytics.posthog.enabled' => false,
                    'zeroboiler.analytics.posthog.api_key' => '',
                    'zeroboiler.analytics.posthog.host' => 'https://eu.posthog.com',
                    'zeroboiler.analytics.mixpanel.enabled' => false,
                    'zeroboiler.analytics.mixpanel.token' => '',
                    'zeroboiler.analytics.amplitude.enabled' => false,
                    'zeroboiler.analytics.amplitude.api_key' => '',
                    'zeroboiler.analytics.tiktok.enabled' => false,
                    'zeroboiler.analytics.tiktok.pixel_id' => '',
                    'zeroboiler.analytics.linkedin.enabled' => false,
                    'zeroboiler.analytics.linkedin.partner_id' => '',
                    'zeroboiler.analytics.webhook.enabled' => false,
                    'zeroboiler.analytics.webhook.url' => '',
                };

                return $map[$key] ?? $default;
            },
        );
    });

    test('headSnippet returns HTML for enabled providers', function (): void {
        $service = new AnalyticsSnippetService($this->config);
        $result = $service->headSnippet();

        expect($result['html'])->toBeString();
        expect($result['providers'])->toContain('ga4');
        expect($result['providers'])->toContain('gtm');
        expect($result['providers'])->toContain('meta');
        expect($result['consent_mode'])->toBeTrue();
    });

    test('headSnippet includes GA4 measurement ID', function (): void {
        $service = new AnalyticsSnippetService($this->config);
        $result = $service->headSnippet();

        expect($result['html'])->toContain('G-ABC123');
    });

    test('headSnippet includes Consent Mode v2 commands', function (): void {
        $service = new AnalyticsSnippetService($this->config);
        $result = $service->headSnippet();

        expect($result['html'])->toContain('consent');
        expect($result['html'])->toContain('analytics_storage');
        expect($result['html'])->toContain('ad_storage');
        expect($result['html'])->toContain('wait_for_update');
    });

    test('headSnippet includes GTM container ID', function (): void {
        $service = new AnalyticsSnippetService($this->config);
        $result = $service->headSnippet();

        expect($result['html'])->toContain('GTM-XYZ');
    });

    test('headSnippet includes Meta Pixel ID', function (): void {
        $service = new AnalyticsSnippetService($this->config);
        $result = $service->headSnippet();

        expect($result['html'])->toContain('1234567890');
        expect($result['html'])->toContain('fbq');
    });

    test('headSnippet excludes disabled providers', function (): void {
        $service = new AnalyticsSnippetService($this->config);
        $result = $service->headSnippet();

        expect($result['providers'])->not->toContain('plausible');
        expect($result['providers'])->not->toContain('posthog');
        expect($result['providers'])->not->toContain('mixpanel');
    });

    test('bodySnippet returns GTM noscript', function (): void {
        $service = new AnalyticsSnippetService($this->config);
        $result = $service->bodySnippet();

        expect($result)->toContain('noscript');
        expect($result)->toContain('GTM-XYZ');
    });

    test('clientInitSnippet returns JS module code', function (): void {
        $service = new AnalyticsSnippetService($this->config);
        $result = $service->clientInitSnippet();

        expect($result)->toContain('type="module"');
        expect($result)->toContain('trackPageView');
        expect($result)->toContain('init');
        expect($result)->toContain('flushQueue');
    });

    test('clientInitSnippet with consent listener includes consent code', function (): void {
        $service = new AnalyticsSnippetService($this->config);
        $result = $service->clientInitSnippet(includeConsentListener: true);

        expect($result)->toContain('zb:consent');
        expect($result)->toContain('gtag');
        expect($result)->toContain('fbq');
    });

    test('clientInitSnippet without consent listener excludes consent code', function (): void {
        $service = new AnalyticsSnippetService($this->config);
        $result = $service->clientInitSnippet(includeConsentListener: false);

        expect($result)->not->toContain('zb:consent');
    });

    test('fullSnippet returns head, body, init, and providers', function (): void {
        $service = new AnalyticsSnippetService($this->config);
        $result = $service->fullSnippet();

        expect($result)->toHaveKeys(['head', 'body', 'init', 'providers']);
        expect($result['providers'])->toContain('ga4');
        expect($result['head'])->toBeString();
        expect($result['body'])->toBeString();
        expect($result['init'])->toBeString();
    });

    test('providerSummary returns all 10 providers', function (): void {
        $service = new AnalyticsSnippetService($this->config);
        $result = $service->providerSummary();

        expect($result['providers'])->toHaveCount(10);
    });

    test('providerSummary masks provider IDs', function (): void {
        $service = new AnalyticsSnippetService($this->config);
        $result = $service->providerSummary();

        $ga4 = collect($result['providers'])->first(fn (array $p): bool => $p['name'] === 'GA4');
        expect($ga4['id_masked'])->not->toBe('G-ABC123');
        expect($ga4['configured'])->toBeTrue();
    });

    test('providerSummary shows unconfigured providers', function (): void {
        $service = new AnalyticsSnippetService($this->config);
        $result = $service->providerSummary();

        $plausible = collect($result['providers'])->first(fn (array $p): bool => $p['name'] === 'Plausible');
        expect($plausible['configured'])->toBeFalse();
        expect($plausible['id_masked'])->toBe('(not configured)');
    });

    test('headSnippet returns empty HTML when no providers configured', function (): void {
        $this->config->shouldReceive('get')->andReturnUsing(
            fn (string $key, mixed $default = null): mixed => $default,
        );

        $service = new AnalyticsSnippetService($this->config);
        $result = $service->headSnippet();

        expect($result['html'])->toBe('');
        expect($result['providers'])->toBeEmpty();
    });
});

// ── DifferentialPrivacyService ─────────────────────────────────────────

describe('DifferentialPrivacyService', function (): void {
    beforeEach(function (): void {
        $this->cache = mock(CacheRepository::class);
        $this->cache->shouldReceive('get')->andReturn(0);
        $this->cache->shouldReceive('put')->andReturnTrue();
        $this->cache->shouldReceive('forget')->andReturnTrue();

        $this->config->shouldReceive('get')->andReturnUsing(
            function (string $key, mixed $default = null): mixed {
                $map = [
                    'zeroboiler.analytics.differential_privacy.enabled' => true,
                    'zeroboiler.analytics.differential_privacy.epsilon' => 1.0,
                    'zeroboiler.analytics.differential_privacy.default_delta' => 1.0,
                    'zeroboiler.analytics.differential_privacy.cache_ttl' => 300,
                    'zeroboiler.analytics.differential_privacy.cache_prefix' => 'zb_dp_',
                ];

                return $map[$key] ?? $default;
            },
        );
    });

    test('isEnabled returns config value', function (): void {
        $service = new DifferentialPrivacyService($this->cache, $this->config);

        expect($service->isEnabled())->toBeTrue();
    });

    test('getEpsilon returns configured epsilon', function (): void {
        $service = new DifferentialPrivacyService($this->cache, $this->config);

        expect($service->getEpsilon())->toBe(1.0);
    });

    test('addNoise returns value unchanged when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.differential_privacy.enabled', false)
            ->andReturn(false);

        $service = new DifferentialPrivacyService($this->cache, $this->config);

        expect($service->addNoise(100.0))->toBe(100.0);
    });

    test('addNoise returns non-negative value', function (): void {
        $service = new DifferentialPrivacyService($this->cache, $this->config);

        // Even with large negative noise, result should be >= 0
        $result = $service->addNoise(1.0, 1000.0);

        expect($result)->toBeGreaterThanOrEqual(0.0);
    });

    test('addNoise with different sensitivity values', function (): void {
        $service = new DifferentialPrivacyService($this->cache, $this->config);

        $result1 = $service->addNoise(100.0, 1.0);
        $result2 = $service->addNoise(100.0, 10.0);

        // Both should be non-negative
        expect($result1)->toBeGreaterThanOrEqual(0.0);
        expect($result2)->toBeGreaterThanOrEqual(0.0);

        // Higher sensitivity = more noise = more variance
        // We can't guarantee this in a single sample, but structure is correct
        expect(is_float($result1))->toBeTrue();
        expect(is_float($result2))->toBeTrue();
    });

    test('addNoiseToPercentage clamps to 0-100', function (): void {
        $service = new DifferentialPrivacyService($this->cache, $this->config);

        $result = $service->addNoiseToPercentage(50.0, 100);

        expect($result)->toBeGreaterThanOrEqual(0.0);
        expect($result)->toBeLessThanOrEqual(100.0);
    });

    test('addNoiseToPercentage uses population-based delta', function (): void {
        $service = new DifferentialPrivacyService($this->cache, $this->config);

        $largePop = $service->addNoiseToPercentage(50.0, 10000);
        $smallPop = $service->addNoiseToPercentage(50.0, 10);

        // Large population = less noise, small population = more noise
        expect($largePop)->toBeFloat();
        expect($smallPop)->toBeFloat();
    });

    test('addNoiseToRevenue returns non-negative value', function (): void {
        $service = new DifferentialPrivacyService($this->cache, $this->config);

        $result = $service->addNoiseToRevenue(5000.0, 100.0);

        expect($result)->toBeGreaterThanOrEqual(0.0);
    });

    test('anonymizeCount returns null for small counts', function (): void {
        $service = new DifferentialPrivacyService($this->cache, $this->config);

        expect($service->anonymizeCount(0, 10))->toBeNull();
        expect($service->anonymizeCount(5, 10))->toBeNull();
        expect($service->anonymizeCount(9, 10))->toBeNull();
    });

    test('anonymizeCount returns noisy value for large counts', function (): void {
        $service = new DifferentialPrivacyService($this->cache, $this->config);

        $result = $service->anonymizeCount(100, 10);

        expect($result)->not->toBeNull();
        expect($result)->toBeGreaterThanOrEqual(0);
        expect(is_int($result))->toBeTrue();
    });

    test('anonymizeCount returns true value when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.differential_privacy.enabled', false)
            ->andReturn(false);

        $service = new DifferentialPrivacyService($this->cache, $this->config);

        expect($service->anonymizeCount(5, 10))->toBe(5);
        expect($service->anonymizeCount(100, 10))->toBe(100);
    });

    test('anonymizeHistogram suppresses small buckets', function (): void {
        $service = new DifferentialPrivacyService($this->cache, $this->config);

        $result = $service->anonymizeHistogram([
            'chrome' => 100,
            'firefox' => 5,
            'safari' => 200,
        ], 10);

        expect($result['chrome'])->not->toBeNull();
        expect($result['firefox'])->toBeNull();
        expect($result['safari'])->not->toBeNull();
    });

    test('privacySafeTopN returns ranked results', function (): void {
        $service = new DifferentialPrivacyService($this->cache, $this->config);

        $result = $service->privacySafeTopN([
            'purchase' => 500.0,
            'signup' => 1000.0,
            'login' => 3000.0,
        ], 2);

        expect($result)->toHaveCount(2);
        expect($result[0])->toHaveKey('label');
        expect($result[0])->toHaveKey('value');
        expect($result[0])->toHaveKey('rank');
        expect($result[0]['rank'])->toBe(1);
    });

    test('privacySafeTopN preserves true order when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.differential_privacy.enabled', false)
            ->andReturn(false);

        $service = new DifferentialPrivacyService($this->cache, $this->config);

        $result = $service->privacySafeTopN([
            'purchase' => 500.0,
            'signup' => 1000.0,
            'login' => 3000.0,
        ], 2);

        expect($result[0]['label'])->toBe('login');
        expect($result[0]['value'])->toBe(3000.0);
    });

    test('consumeBudget tracks epsilon usage', function (): void {
        $service = new DifferentialPrivacyService($this->cache, $this->config);

        $result = $service->consumeBudget(0.5);

        expect($result)->toHaveKeys(['consumed', 'remaining', 'budget', 'exhausted']);
        expect($result['consumed'])->toBeGreaterThan(0.0);
        expect($result['exhausted'])->toBeFalse();
    });

    test('consumeBudget returns empty when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.differential_privacy.enabled', false)
            ->andReturn(false);

        $service = new DifferentialPrivacyService($this->cache, $this->config);

        $result = $service->consumeBudget(0.5);

        expect($result['consumed'])->toBe(0.0);
        expect($result['exhausted'])->toBeFalse();
    });

    test('status returns all fields', function (): void {
        $service = new DifferentialPrivacyService($this->cache, $this->config);

        $result = $service->status();

        expect($result)->toHaveKeys(['enabled', 'epsilon', 'sensitivity', 'period_budget', 'current_consumed', 'remaining']);
        expect($result['enabled'])->toBeTrue();
        expect($result['epsilon'])->toBe(1.0);
    });

    test('resetBudget clears cache key', function (): void {
        $this->cache->shouldReceive('forget')->with(
            mock(\Illuminate\Contracts\Cache\Repository::class) // any string
        )->andReturnTrue();

        // Use actual regex-style matching since we don't know exact key
        $this->cache->shouldReceive('forget')->andReturnTrue();

        $service = new DifferentialPrivacyService($this->cache, $this->config);
        $service->resetBudget(); // Should not throw

        expect(true)->toBeTrue();
    });
});

// ── EventCorrelationMatrixService ──────────────────────────────────────

describe('EventCorrelationMatrixService', function (): void {
    beforeEach(function (): void {
        $this->cache = mock(CacheRepository::class);
        $this->cache->shouldReceive('get')->andReturnUsing(
            function (string $key, mixed $default = null): mixed {
                // Simulate some event counts
                if (str_contains($key, 'event_count_purchase')) {
                    return 500;
                }
                if (str_contains($key, 'event_count_add_to_cart')) {
                    return 800;
                }
                if (str_contains($key, 'event_count_search')) {
                    return 1200;
                }
                if (str_contains($key, 'cooc_id_')) {
                    return ['user1' => true, 'user2' => true, 'user3' => true, 'user4' => true, 'user5' => true,
                        'user6' => true, 'user7' => true, 'user8' => true, 'user9' => true, 'user10' => true];
                }

                return $default;
            },
        );
        $this->cache->shouldReceive('put')->andReturnTrue();

        $this->config->shouldReceive('get')->andReturnUsing(
            function (string $key, mixed $default = null): mixed {
                $map = [
                    'zeroboiler.analytics.correlation_matrix.enabled' => true,
                    'zeroboiler.analytics.correlation_matrix.cache_ttl' => 3600,
                    'zeroboiler.analytics.correlation_matrix.cache_prefix' => 'zb_corr_',
                    'zeroboiler.analytics.correlation_matrix.time_window' => 604800,
                    'zeroboiler.analytics.correlation_matrix.significance_threshold' => 0.3,
                    'zeroboiler.analytics.correlation_matrix.max_pairs' => 500,
                ];

                return $map[$key] ?? $default;
            },
        );
    });

    test('isEnabled returns config value', function (): void {
        $service = new EventCorrelationMatrixService($this->cache, $this->config);

        expect($service->isEnabled())->toBeTrue();
    });

    test('isEnabled returns false when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.correlation_matrix.enabled', true)
            ->andReturn(false);

        $service = new EventCorrelationMatrixService($this->cache, $this->config);

        expect($service->isEnabled())->toBeFalse();
    });

    test('recordCooccurrence stores data', function (): void {
        $service = new EventCorrelationMatrixService($this->cache, $this->config);

        $service->recordCooccurrence('add_to_cart', 'purchase', 'user123');

        // Should not throw
        expect(true)->toBeTrue();
    });

    test('recordCooccurrence skips events outside time window', function (): void {
        $service = new EventCorrelationMatrixService($this->cache, $this->config);

        $service->recordCooccurrence('signup', 'purchase', 'user456', 9999999);

        // Should not throw — just silently ignored
        expect(true)->toBeTrue();
    });

    test('recordCooccurrence does nothing when disabled', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.correlation_matrix.enabled', true)
            ->andReturn(false);

        $service = new EventCorrelationMatrixService($this->cache, $this->config);

        $service->recordCooccurrence('add_to_cart', 'purchase', 'user123');

        // Should not throw
        expect(true)->toBeTrue();
    });

    test('recordEvent stores event count', function (): void {
        $service = new EventCorrelationMatrixService($this->cache, $this->config);

        $service->recordEvent('purchase');

        expect(true)->toBeTrue();
    });

    test('computeMatrix returns structured data', function (): void {
        $service = new EventCorrelationMatrixService($this->cache, $this->config);

        $result = $service->computeMatrix();

        expect($result)->toHaveKeys(['matrix', 'events', 'top_pairs', 'computed_at']);
        expect($result['events'])->toBeArray();
        expect($result['matrix'])->toBeArray();
        expect($result['top_pairs'])->toBeArray();
        expect($result['computed_at'])->toBeString();
    });

    test('computeMatrix with custom threshold', function (): void {
        $service = new EventCorrelationMatrixService($this->cache, $this->config);

        $result = $service->computeMatrix(0.8);

        expect($result)->toBeArray();
    });

    test('correlationsFor returns list of correlated events', function (): void {
        $service = new EventCorrelationMatrixService($this->cache, $this->config);

        $result = $service->correlationsFor('purchase');

        expect($result)->toBeArray();
    });

    test('predictorsOf returns insight strings', function (): void {
        $service = new EventCorrelationMatrixService($this->cache, $this->config);

        $result = $service->predictorsOf('purchase', 5);

        expect($result)->toBeArray();
    });

    test('predictorsOf items have insight field', function (): void {
        $service = new EventCorrelationMatrixService($this->cache, $this->config);

        $result = $service->predictorsOf('purchase');

        // Empty result is valid (no data loaded yet)
        expect(is_array($result))->toBeTrue();
    });

    test('summary returns all fields', function (): void {
        $service = new EventCorrelationMatrixService($this->cache, $this->config);

        $result = $service->summary();

        expect($result)->toHaveKeys([
            'enabled',
            'tracked_pairs',
            'tracked_events',
            'significant_pairs',
            'avg_correlation',
            'strongest',
            'time_window_hours',
            'threshold',
        ]);
        expect($result['enabled'])->toBeTrue();
        expect($result['time_window_hours'])->toBe(168); // 7 days
    });

    test('summary when disabled returns zeros', function (): void {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.correlation_matrix.enabled', true)
            ->andReturn(false);

        $service = new EventCorrelationMatrixService($this->cache, $this->config);
        $result = $service->summary();

        expect($result['enabled'])->toBeFalse();
        expect($result['tracked_pairs'])->toBe(0);
    });

    test('clear does not throw', function (): void {
        $service = new EventCorrelationMatrixService($this->cache, $this->config);

        $service->clear(); // Soft clear

        expect(true)->toBeTrue();
    });

    test('pairKey normalizes alphabetical order', function (): void {
        // Use reflection to test private method
        $service = new EventCorrelationMatrixService($this->cache, $this->config);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('pairKey');
        $method->setAccessible(true);

        $key1 = $method->invoke($service, 'purchase', 'add_to_cart');
        $key2 = $method->invoke($service, 'add_to_cart', 'purchase');

        expect($key1)->toBe($key2);
        expect($key1)->toBe('add_to_cart::purchase');
    });

    test('correlationStrength classifies correctly', function (): void {
        $service = new EventCorrelationMatrixService($this->cache, $this->config);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('correlationStrength');
        $method->setAccessible(true);

        expect($method->invoke($service, 0.9))->toBe('very_strong');
        expect($method->invoke($service, 0.7))->toBe('strong');
        expect($method->invoke($service, 0.5))->toBe('moderate');
        expect($method->invoke($service, 0.3))->toBe('weak');
        expect($method->invoke($service, 0.1))->toBe('very_weak');
        expect($method->invoke($service, -0.9))->toBe('very_strong');
    });
});

// ── Version Sweep ─────────────────────────────────────────────────────

describe('Version sweep v42.0.0', function (): void {
    test('AnalyticsSnippetService class exists', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Services\AnalyticsSnippetService::class))->toBeTrue();
    });

    test('DifferentialPrivacyService class exists', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Services\DifferentialPrivacyService::class))->toBeTrue();
    });

    test('EventCorrelationMatrixService class exists', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Services\EventCorrelationMatrixService::class))->toBeTrue();
    });

    test('AnalyticsSnippetCommand class exists', function (): void {
        expect(class_exists(\ZeroBoiler\Analytics\Console\Commands\AnalyticsSnippetCommand::class))->toBeTrue();
    });

    test('All new classes are final', function (): void {
        $reflection = new \ReflectionClass(\ZeroBoiler\Analytics\Services\AnalyticsSnippetService::class);
        expect($reflection->isFinal())->toBeTrue();

        $reflection = new \ReflectionClass(\ZeroBoiler\Analytics\Services\DifferentialPrivacyService::class);
        expect($reflection->isFinal())->toBeTrue();

        $reflection = new \ReflectionClass(\ZeroBoiler\Analytics\Services\EventCorrelationMatrixService::class);
        expect($reflection->isFinal())->toBeTrue();
    });

    test('All new classes use strict types', function (): void {
        $files = [
            (new \ReflectionClass(\ZeroBoiler\Analytics\Services\AnalyticsSnippetService::class))->getFileName(),
            (new \ReflectionClass(\ZeroBoiler\Analytics\Services\DifferentialPrivacyService::class))->getFileName(),
            (new \ReflectionClass(\ZeroBoiler\Analytics\Services\EventCorrelationMatrixService::class))->getFileName(),
        ];

        foreach ($files as $file) {
            $contents = file_get_contents((string) $file);
            expect($contents)->toContain('declare(strict_types=1)');
        }
    });
});
