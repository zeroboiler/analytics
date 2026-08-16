<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Engagement\PerformanceScoreEvent;
use ZeroBoiler\Analytics\Services\ConsentBannerService;
use ZeroBoiler\Analytics\Services\PerformanceScoreService;

beforeEach(function () {
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->config = Mockery::mock(ConfigRepository::class);
});

afterEach(function () {
    Mockery::close();
});

describe('PerformanceScoreService', function () {
    it('rates LCP as good when value is below 2500ms', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        expect($service->rateMetric('LCP', 1200))->toBe('good');
        expect($service->rateMetric('LCP', 2500))->toBe('good');
    });

    it('rates LCP as needs-improvement when value is between 2500 and 4000', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        expect($service->rateMetric('LCP', 3000))->toBe('needs-improvement');
        expect($service->rateMetric('LCP', 4000))->toBe('needs-improvement');
    });

    it('rates LCP as poor when value exceeds 4000ms', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        expect($service->rateMetric('LCP', 5000))->toBe('poor');
    });

    it('rates INP correctly', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        expect($service->rateMetric('INP', 100))->toBe('good');
        expect($service->rateMetric('INP', 300))->toBe('needs-improvement');
        expect($service->rateMetric('INP', 600))->toBe('poor');
    });

    it('rates CLS correctly', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        expect($service->rateMetric('CLS', 0.05))->toBe('good');
        expect($service->rateMetric('CLS', 0.15))->toBe('needs-improvement');
        expect($service->rateMetric('CLS', 0.3))->toBe('poor');
    });

    it('rates TTFB correctly', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        expect($service->rateMetric('TTFB', 400))->toBe('good');
        expect($service->rateMetric('TTFB', 1200))->toBe('needs-improvement');
        expect($service->rateMetric('TTFB', 2000))->toBe('poor');
    });

    it('rates unknown metrics as unknown', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        expect($service->rateMetric('UNKNOWN_METRIC', 100))->toBe('unknown');
    });

    it('converts ratings to numeric scores', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        expect($service->ratingToScore('good'))->toBe(3);
        expect($service->ratingToScore('needs-improvement'))->toBe(2);
        expect($service->ratingToScore('poor'))->toBe(1);
        expect($service->ratingToScore('unknown'))->toBe(0);
    });

    it('calculates overall score 100 for all-good metrics', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        $result = $service->calculateScore([
            'LCP' => 1200,
            'INP' => 100,
            'CLS' => 0.05,
            'TTFB' => 400,
        ]);

        expect($result['score'])->toBe(100);
        expect($result['rating'])->toBe('good');
        expect($result['breakdown'])->toHaveKey('LCP');
        expect($result['breakdown'])->toHaveKey('INP');
        expect($result['breakdown'])->toHaveKey('CLS');
        expect($result['breakdown'])->toHaveKey('TTFB');
    });

    it('calculates score 0 for empty metrics', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        $result = $service->calculateScore([]);

        expect($result['score'])->toBe(0);
        expect($result['rating'])->toBe('unknown');
        expect($result['breakdown'])->toBeEmpty();
    });

    it('handles partial metrics by redistributing weights', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        $result = $service->calculateScore([
            'LCP' => 1200,
            'CLS' => 0.05,
        ]);

        expect($result['score'])->toBeGreaterThan(0);
        expect($result['rating'])->toBe('good');
    });

    it('converts numeric scores to ratings correctly', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        expect($service->scoreToRating(95))->toBe('good');
        expect($service->scoreToRating(90))->toBe('good');
        expect($service->scoreToRating(75))->toBe('needs-improvement');
        expect($service->scoreToRating(50))->toBe('needs-improvement');
        expect($service->scoreToRating(30))->toBe('poor');
        expect($service->scoreToRating(0))->toBe('poor');
    });

    it('returns all thresholds', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        $thresholds = $service->getThresholds();

        expect($thresholds)->toHaveKey('LCP');
        expect($thresholds)->toHaveKey('INP');
        expect($thresholds)->toHaveKey('CLS');
        expect($thresholds)->toHaveKey('TTFB');
        expect($thresholds)->toHaveKey('FCP');
        expect($thresholds)->toHaveKey('FID');
        expect($thresholds['LCP'])->toBe(['good' => 2500, 'poor' => 4000]);
    });

    it('returns metric weights', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        $weights = $service->getWeights();

        expect($weights)->toBe([
            'LCP' => 0.25,
            'INP' => 0.30,
            'CLS' => 0.25,
            'TTFB' => 0.20,
        ]);
    });

    it('aggregates multiple metric sets and computes p75', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        $result = $service->aggregateMetrics([
            ['LCP' => 1000, 'INP' => 100, 'CLS' => 0.05, 'TTFB' => 200],
            ['LCP' => 2000, 'INP' => 200, 'CLS' => 0.10, 'TTFB' => 500],
            ['LCP' => 3000, 'INP' => 300, 'CLS' => 0.20, 'TTFB' => 800],
            ['LCP' => 4000, 'INP' => 400, 'CLS' => 0.30, 'TTFB' => 1200],
        ]);

        expect($result['summary'])->toHaveKey('LCP');
        expect($result['summary']['LCP']['count'])->toBe(4);
        expect($result['summary']['LCP']['min'])->toBe(1000);
        expect($result['summary']['LCP']['max'])->toBe(4000);
        expect($result['overall_p75_score'])->toBeGreaterThan(0);
    });

    it('returns empty aggregation for empty input', function () {
        $service = new PerformanceScoreService($this->cache, $this->config);

        $result = $service->aggregateMetrics([]);

        expect($result['summary'])->toBeEmpty();
        expect($result['overall_p75_score'])->toBe(0);
    });

    it('caches and retrieves scores', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance.cache_prefix', 'zb_perf_')
            ->andReturn('zb_perf_');

        $this->cache->shouldReceive('put')
            ->once()
            ->with('zb_perf_test_key', Mockery::type('array'), 3600);

        $this->cache->shouldReceive('get')
            ->once()
            ->with('zb_perf_test_key')
            ->andReturn(['score' => 85, 'rating' => 'good']);

        $service = new PerformanceScoreService($this->cache, $this->config);

        $service->cacheScore('test_key', ['score' => 85, 'rating' => 'good']);
        $cached = $service->getCachedScore('test_key');

        expect($cached)->toBe(['score' => 85, 'rating' => 'good']);
    });

    it('returns null for non-existent cached score', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.performance.cache_prefix', 'zb_perf_')
            ->andReturn('zb_perf_');

        $this->cache->shouldReceive('get')
            ->once()
            ->with('zb_perf_nonexistent')
            ->andReturn(null);

        $service = new PerformanceScoreService($this->cache, $this->config);

        expect($service->getCachedScore('nonexistent'))->toBeNull();
    });
});

describe('PerformanceScoreEvent', function () {
    it('creates event with score and rating', function () {
        $event = new PerformanceScoreEvent(95, 'good');

        expect($event)->toBeInstanceOf(AnalyticsEvent::class);
        expect($event->name)->toBe('performance_score');
        expect($event->params['score'])->toBe(95);
        expect($event->params['rating'])->toBe('good');
    });

    it('includes individual metric breakdown', function () {
        $event = new PerformanceScoreEvent(75, 'needs-improvement', [
            'LCP' => ['value' => 3000, 'rating' => 'needs-improvement'],
            'INP' => ['value' => 150, 'rating' => 'good'],
        ], 'https://example.com/page');

        expect($event->params['metric_LCP_value'])->toBe(3000);
        expect($event->params['metric_LCP_rating'])->toBe('needs-improvement');
        expect($event->params['metric_INP_value'])->toBe(150);
        expect($event->params['metric_INP_rating'])->toBe('good');
        expect($event->params['page_url'])->toBe('https://example.com/page');
    });

    it('accepts optional session ID', function () {
        $event = new PerformanceScoreEvent(50, 'needs-improvement', [], null, 'session-123');

        expect($event->params['session_id'])->toBe('session-123');
    });

    it('merges extra params', function () {
        $event = new PerformanceScoreEvent(90, 'good', [], null, null, [
            'user_agent' => 'Chrome/120',
            'viewport_width' => 1920,
        ]);

        expect($event->params['user_agent'])->toBe('Chrome/120');
        expect($event->params['viewport_width'])->toBe(1920);
    });
});

describe('ConsentBannerService', function () {
    it('returns configured purposes', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent', [])
            ->andReturn([
                'purposes' => [
                    'necessary' => ['label' => 'Necessary', 'required' => true, 'default' => true],
                    'analytics' => ['label' => 'Analytics', 'required' => false, 'default' => true],
                ],
            ]);

        $service = new ConsentBannerService($this->config);
        $purposes = $service->getPurposes();

        expect($purposes)->toHaveKey('necessary');
        expect($purposes['necessary']['required'])->toBeTrue();
        expect($purposes['necessary']['label'])->toBe('Necessary');
        expect($purposes['analytics']['required'])->toBeFalse();
    });

    it('uses default labels when not configured', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent', [])
            ->andReturn([]);

        $service = new ConsentBannerService($this->config);
        $purposes = $service->getPurposes();

        expect($purposes)->toBeEmpty();
    });

    it('renders HTML banner', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent', [])
            ->andReturn([
                'default' => 'granted',
                'purposes' => [
                    'necessary' => ['label' => 'Necessary', 'required' => true, 'default' => true],
                    'analytics' => ['label' => 'Analytics', 'required' => false, 'default' => true],
                ],
            ]);

        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id')
            ->andReturn('zb_analytics_id');

        $service = new ConsentBannerService($this->config);
        $html = $service->render();

        expect($html->__toString())->toContain('zb-consent-banner');
        expect($html->__toString())->toContain('Cookie Preferences');
        expect($html->__toString())->toContain('Accept All');
        expect($html->__toString())->toContain('Reject All');
        expect($html->__toString())->toContain('Necessary');
        expect($html->__toString())->toContain('Analytics');
    });

    it('supports dark theme option', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent', [])
            ->andReturn(['default' => 'denied', 'purposes' => []]);

        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id')
            ->andReturn('zb_analytics_id');

        $service = new ConsentBannerService($this->config);
        $html = $service->render(['theme' => 'dark']);

        expect($html->__toString())->toContain('zb-consent--dark');
    });

    it('supports top position option', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent', [])
            ->andReturn(['default' => 'granted', 'purposes' => []]);

        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.identity.cookie_name', 'zb_analytics_id')
            ->andReturn('zb_analytics_id');

        $service = new ConsentBannerService($this->config);
        $html = $service->render(['position' => 'top']);

        expect($html->__toString())->toContain('zb-consent--top');
    });

    it('renders consent script for head', function () {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.consent', [])
            ->andReturn(['default' => 'denied']);

        $service = new ConsentBannerService($this->config);
        $script = $service->renderConsentScript();

        expect($script->__toString())->toContain('gtag');
        expect($script->__toString())->toContain('consent');
        expect($script->__toString())->toContain('denied');
        expect($script->__toString())->toContain('security_storage');
    });
});
