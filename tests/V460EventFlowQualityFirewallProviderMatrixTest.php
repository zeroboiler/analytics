<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Cache;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\EventFlowAnalysisService;
use ZeroBoiler\Analytics\Services\AnalyticsDataQualityFirewall;
use ZeroBoiler\Analytics\Services\ProviderEventCompatibilityMatrix;
use ZeroBoiler\Analytics\Console\Commands\AnalyticsFlowCommand;

// ── V460 Event Flow, Data Quality Firewall & Provider Matrix Test ──────

describe('V460 Event Flow & Quality Firewall & Provider Matrix', function () {

    beforeEach(function (): void {
        $this->config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'event_flow' => [
                        'enabled' => true,
                        'max_path_length' => 50,
                        'path_ttl' => 86400,
                        'top_paths_limit' => 25,
                        'cache_prefix' => 'zb_flow_test_',
                        'metrics_ttl' => 3600,
                    ],
                    'quality_firewall' => [
                        'enabled' => true,
                        'quarantine_threshold' => 0.5,
                        'drop_threshold' => 0.2,
                        'enforce_quarantine' => true,
                        'enforce_drop' => true,
                        'cache_prefix' => 'zb_qf_test_',
                        'metrics_ttl' => 3600,
                        'velocity_window' => 60,
                        'max_events_per_window' => 100,
                        'required_global_params' => [],
                        'event_required_params' => [],
                        'reserved_prefixes' => ['_ga_', '_fb_', '_meta_'],
                    ],
                    'provider_matrix' => [
                        'enabled' => true,
                        'cache_prefix' => 'zb_pem_test_',
                        'cache_ttl' => 3600,
                    ],
                    'sampling' => [
                        'enabled' => false,
                        'global_rate' => 1.0,
                    ],
                ],
            ],
        ]);

        $this->cache = app('cache');
        Cache::clear();
    });

    // ── 1. Version Consistency ─────────────────────────────────────────

    test('v46.0.0: AnalyticsEvent VERSION is 46.0.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('46.0.0');
    });

    test('v46.0.0: composer.json version is 46.0.0', function (): void {
        $composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
        expect($composer['version'])->toBe('46.0.0');
    });

    test('v46.0.0: JS client version is 46.0.0', function (): void {
        $js = file_get_contents(__DIR__ . '/../resources/js/analytics.js');
        expect($js)->toContain("'46.0.0'");
        expect($js)->toContain('@version 46.0.0');
    });

    test('v46.0.0: TypeScript definitions version is 46.0.0', function (): void {
        $ts = file_get_contents(__DIR__ . '/../resources/js/analytics.d.ts');
        expect($ts)->toContain('@version 46.0.0');
    });

    // ── 2. EventFlowAnalysisService ─────────────────────────────────────

    describe('EventFlowAnalysisService', function () {
        test('class exists and is instantiable', function (): void {
            $service = new EventFlowAnalysisService($this->cache, $this->config);
            expect($service)->toBeInstanceOf(EventFlowAnalysisService::class);
        });

        test('isEnabled returns true when config enabled', function (): void {
            $service = new EventFlowAnalysisService($this->cache, $this->config);
            expect($service->isEnabled())->toBeTrue();
        });

        test('isEnabled returns false when config disabled', function (): void {
            $this->config->set('zeroboiler.analytics.event_flow.enabled', false);
            $service = new EventFlowAnalysisService($this->cache, $this->config);
            expect($service->isEnabled())->toBeFalse();
        });

        test('recordStep and getPath work together', function (): void {
            $service = new EventFlowAnalysisService($this->cache, $this->config);

            $event1 = new AnalyticsEvent('page_view', ['url' => '/home']);
            $event2 = new AnalyticsEvent('click', ['target' => 'cta']);
            $event3 = new AnalyticsEvent('form_start', ['form_id' => 'signup']);

            $service->recordStep($event1, 'user_123');
            $service->recordStep($event2, 'user_123');
            $service->recordStep($event3, 'user_123');

            $path = $service->getPath('user_123');
            expect($path)->toBe(['page_view', 'click', 'form_start']);
        });

        test('clearPath removes the user path', function (): void {
            $service = new EventFlowAnalysisService($this->cache, $this->config);

            $service->recordStep(new AnalyticsEvent('page_view'), 'user_456');
            expect($service->getPath('user_456'))->not->toBeEmpty();

            $service->clearPath('user_456');
            expect($service->getPath('user_456'))->toBeEmpty();
        });

        test('getPath returns empty for unknown user', function (): void {
            $service = new EventFlowAnalysisService($this->cache, $this->config);
            expect($service->getPath('unknown_user'))->toBeEmpty();
        });

        test('recordStep does nothing when disabled', function (): void {
            $this->config->set('zeroboiler.analytics.event_flow.enabled', false);
            $service = new EventFlowAnalysisService($this->cache, $this->config);

            $service->recordStep(new AnalyticsEvent('page_view'), 'user_789');
            expect($service->getPath('user_789'))->toBeEmpty();
        });

        test('getMetrics returns expected structure', function (): void {
            $service = new EventFlowAnalysisService($this->cache, $this->config);

            $service->recordStep(new AnalyticsEvent('page_view'), 'user_m1');
            $metrics = $service->getMetrics();

            expect($metrics)->toHaveKeys(['enabled', 'steps_recorded', 'paths_tracked', 'max_path_length', 'path_ttl']);
            expect($metrics['enabled'])->toBeTrue();
            expect($metrics['steps_recorded'])->toBeGreaterThanOrEqual(1);
            expect($metrics['max_path_length'])->toBe(50);
        });

        test('summary returns expected structure', function (): void {
            $service = new EventFlowAnalysisService($this->cache, $this->config);
            $summary = $service->summary();

            expect($summary)->toHaveKeys(['enabled', 'max_path_length', 'path_ttl', 'top_paths_limit', 'metrics_ttl']);
        });

        test('funnelDropOff returns valid structure', function (): void {
            $service = new EventFlowAnalysisService($this->cache, $this->config);

            $result = $service->funnelDropOff(['page_view', 'sign_up', 'purchase']);
            expect($result)->toHaveKeys(['steps', 'total_conversion']);
            expect($result['steps'])->toHaveCount(3);
        });

        test('funnelDropOff returns empty for empty steps', function (): void {
            $service = new EventFlowAnalysisService($this->cache, $this->config);
            $result = $service->funnelDropOff([]);
            expect($result['steps'])->toBeEmpty();
        });

        test('stepTiming returns valid structure', function (): void {
            $service = new EventFlowAnalysisService($this->cache, $this->config);

            $result = $service->stepTiming('page_view', 'sign_up');
            expect($result)->toHaveKeys(['avg_seconds', 'min_seconds', 'max_seconds', 'sample_count']);
        });

        test('conversionPathComparison returns valid structure', function (): void {
            $service = new EventFlowAnalysisService($this->cache, $this->config);

            $result = $service->conversionPathComparison('purchase', ['page_view', 'click']);
            expect($result)->toHaveKeys(['converters', 'non_converters', 'key_difference']);
        });

        test('topPaths returns empty when disabled', function (): void {
            $this->config->set('zeroboiler.analytics.event_flow.enabled', false);
            $service = new EventFlowAnalysisService($this->cache, $this->config);
            expect($service->topPaths(3))->toBeEmpty();
        });

        test('recordStep falls back to clientId then userId then anonymous', function (): void {
            $service = new EventFlowAnalysisService($this->cache, $this->config);

            $service->recordStep(new AnalyticsEvent('page_view', [], 'client_abc'), null);
            expect($service->getPath('client_abc'))->toHaveCount(1);
        });
    });

    // ── 3. AnalyticsDataQualityFirewall ────────────────────────────────

    describe('AnalyticsDataQualityFirewall', function () {
        test('class exists and is instantiable', function (): void {
            $service = new AnalyticsDataQualityFirewall($this->cache, $this->config);
            expect($service)->toBeInstanceOf(AnalyticsDataQualityFirewall::class);
        });

        test('isEnabled returns true when config enabled', function (): void {
            $service = new AnalyticsDataQualityFirewall($this->cache, $this->config);
            expect($service->isEnabled())->toBeTrue();
        });

        test('evaluate returns pass for well-formed events', function (): void {
            $service = new AnalyticsDataQualityFirewall($this->cache, $this->config);
            $event = new AnalyticsEvent('page_view', ['url' => '/home', 'title' => 'Home']);

            $result = $service->evaluate($event);
            expect($result['disposition'])->toBe('pass');
            expect($result['score'])->toBeGreaterThanOrEqual(0.5);
            expect($result['violations'])->toBeArray();
        });

        test('evaluate returns pass when firewall is disabled', function (): void {
            $this->config->set('zeroboiler.analytics.quality_firewall.enabled', false);
            $service = new AnalyticsDataQualityFirewall($this->cache, $this->config);

            $result = $service->evaluate(new AnalyticsEvent('bad_event', ['_ga_secret' => 'x']));
            expect($result['score'])->toBe(1.0);
            expect($result['disposition'])->toBe('pass');
        });

        test('evaluate detects bad event name format', function (): void {
            $service = new AnalyticsDataQualityFirewall($this->cache, $this->config);
            $event = new AnalyticsEvent('BadEventName!', ['param' => 'value']);

            $result = $service->evaluate($event);
            expect($result['violations'])->not->toBeEmpty();
            $formatViolations = array_filter($result['violations'], fn (array $v): bool => $v['rule'] === 'format');
            expect($formatViolations)->not->toBeEmpty();
        });

        test('evaluate detects null bytes in params', function (): void {
            $service = new AnalyticsDataQualityFirewall($this->cache, $this->config);
            $event = new AnalyticsEvent('page_view', ['title' => "hello\0world"]);

            $result = $service->evaluate($event);
            $nullViolations = array_filter($result['violations'], fn (array $v): bool => str_contains($v['message'], 'null bytes'));
            expect($nullViolations)->not->toBeEmpty();
        });

        test('evaluate detects reserved prefixes', function (): void {
            $service = new AnalyticsDataQualityFirewall($this->cache, $this->config);
            $event = new AnalyticsEvent('page_view', ['_ga_cid' => '123', '_fb_pid' => '456']);

            $result = $service->evaluate($event);
            $prefixViolations = array_filter($result['violations'], fn (array $v): bool => str_contains($v['message'], 'reserved prefix'));
            expect($prefixViolations)->not->toBeEmpty();
        });

        test('evaluate detects empty string params', function (): void {
            $service = new AnalyticsDataQualityFirewall($this->cache, $this->config);
            $event = new AnalyticsEvent('page_view', ['title' => '   ']);

            $result = $service->evaluate($event);
            $emptyViolations = array_filter($result['violations'], fn (array $v): bool => str_contains($v['message'], 'empty string'));
            expect($emptyViolations)->not->toBeEmpty();
        });

        test('shouldBlock returns false for passing events', function (): void {
            $service = new AnalyticsDataQualityFirewall($this->cache, $this->config);
            expect($service->shouldBlock(new AnalyticsEvent('page_view', ['url' => '/'])))->toBeFalse();
        });

        test('shouldBlock returns false when disabled', function (): void {
            $this->config->set('zeroboiler.analytics.quality_firewall.enabled', false);
            $service = new AnalyticsDataQualityFirewall($this->cache, $this->config);
            expect($service->shouldBlock(new AnalyticsEvent('bad_event')))->toBeFalse();
        });

        test('getMetrics returns expected structure', function (): void {
            $service = new AnalyticsDataQualityFirewall($this->cache, $this->config);
            $service->evaluate(new AnalyticsEvent('page_view', ['url' => '/']));

            $metrics = $service->getMetrics();
            expect($metrics)->toHaveKeys(['enabled', 'evaluated', 'passed', 'quarantined', 'dropped', 'quarantine_threshold', 'drop_threshold']);
            expect($metrics['enabled'])->toBeTrue();
            expect($metrics['evaluated'])->toBeGreaterThanOrEqual(1);
        });

        test('summary returns expected structure', function (): void {
            $service = new AnalyticsDataQualityFirewall($this->cache, $this->config);
            $summary = $service->summary();

            expect($summary)->toHaveKeys(['enabled', 'quarantine_threshold', 'drop_threshold', 'enforce_quarantine', 'enforce_drop', 'velocity_window', 'max_events_per_window', 'required_global_params_count', 'event_required_params_count']);
        });

        test('velocity check triggers on excessive events', function (): void {
            $this->config->set('zeroboiler.analytics.quality_firewall.max_events_per_window', 3);
            $service = new AnalyticsDataQualityFirewall($this->cache, $this->config);

            $service->evaluate(new AnalyticsEvent('page_view'));
            $service->evaluate(new AnalyticsEvent('page_view'));
            $service->evaluate(new AnalyticsEvent('page_view'));

            $result = $service->evaluate(new AnalyticsEvent('page_view'));
            $velocityViolations = array_filter($result['violations'], fn (array $v): bool => $v['rule'] === 'velocity');
            expect($velocityViolations)->not->toBeEmpty();
        });
    });

    // ── 4. ProviderEventCompatibilityMatrix ─────────────────────────────

    describe('ProviderEventCompatibilityMatrix', function () {
        test('class exists and is instantiable', function (): void {
            $service = new ProviderEventCompatibilityMatrix($this->cache, $this->config);
            expect($service)->toBeInstanceOf(ProviderEventCompatibilityMatrix::class);
        });

        test('isEnabled returns true when config enabled', function (): void {
            $service = new ProviderEventCompatibilityMatrix($this->cache, $this->config);
            expect($service->isEnabled())->toBeTrue();
        });

        test('getMatrix returns valid structure', function (): void {
            $service = new ProviderEventCompatibilityMatrix($this->cache, $this->config);
            $matrix = $service->getMatrix();

            expect($matrix)->toHaveKeys(['events', 'providers', 'matrix']);
            expect($matrix['providers'])->toContain('ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude');
            expect($matrix['events'])->toBeGreaterThan(0);
        });

        test('getMatrix contains all catalog events', function (): void {
            $service = new ProviderEventCompatibilityMatrix($this->cache, $this->config);
            $matrix = $service->getMatrix();

            expect($matrix['events'])->toBe(EventCatalog::count());
        });

        test('getProviderCoverage returns all providers', function (): void {
            $service = new ProviderEventCompatibilityMatrix($this->cache, $this->config);
            $coverage = $service->getProviderCoverage();

            expect($coverage)->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude']);
            foreach ($coverage as $provider => $data) {
                expect($data)->toHaveKeys(['provider', 'total_events', 'mapped_count', 'coverage_pct', 'unmapped']);
                expect($data['coverage_pct'])->toBeGreaterThanOrEqual(0.0);
                expect($data['coverage_pct'])->toBeLessThanOrEqual(100.0);
            }
        });

        test('getReadinessScores returns valid structure', function (): void {
            $service = new ProviderEventCompatibilityMatrix($this->cache, $this->config);
            $readiness = $service->getReadinessScores();

            expect($readiness)->toHaveKeys(['scores', 'recommendation']);
            expect($readiness['scores'])->toHaveKeys(['ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude']);

            foreach ($readiness['scores'] as $provider => $data) {
                expect($data)->toHaveKeys(['provider', 'score', 'coverage_weight', 'specificity_weight', 'category_weight']);
                expect($data['score'])->toBeGreaterThanOrEqual(0.0);
                expect($data['score'])->toBeLessThanOrEqual(100.0);
            }
        });

        test('eventPopularityRanking returns events with provider counts', function (): void {
            $service = new ProviderEventCompatibilityMatrix($this->cache, $this->config);
            $ranking = $service->eventPopularityRanking(5);

            expect($ranking)->not->toBeEmpty();
            expect(count($ranking))->toBeLessThanOrEqual(5);

            foreach ($ranking as $event) {
                expect($event)->toHaveKeys(['event', 'provider_count', 'providers', 'category']);
                expect($event['provider_count'])->toBeGreaterThanOrEqual(0);
                expect($event['provider_count'])->toBeLessThanOrEqual(6);
            }
        });

        test('eventPopularityRanking is sorted descending', function (): void {
            $service = new ProviderEventCompatibilityMatrix($this->cache, $this->config);
            $ranking = $service->eventPopularityRanking(10);

            for ($i = 1; $i < count($ranking); $i++) {
                expect($ranking[$i - 1]['provider_count'])->toBeGreaterThanOrEqual($ranking[$i]['provider_count']);
            }
        });

        test('analyzeEventGaps returns valid structure for known event', function (): void {
            $service = new ProviderEventCompatibilityMatrix($this->cache, $this->config);
            $gaps = $service->analyzeEventGaps('page_view');

            expect($gaps)->toHaveKeys(['event', 'providers', 'gap_count', 'has_ga4', 'has_meta', 'has_posthog', 'has_plausible', 'has_mixpanel', 'has_amplitude']);
            expect($gaps['event'])->toBe('page_view');
            expect($gaps['has_ga4'])->toBeTrue();
        });

        test('analyzeEventGaps returns all gaps for unknown event', function (): void {
            $service = new ProviderEventCompatibilityMatrix($this->cache, $this->config);
            $gaps = $service->analyzeEventGaps('nonexistent_event_xyz');

            expect($gaps['gap_count'])->toBe(6);
            expect($gaps['has_ga4'])->toBeFalse();
        });

        test('getGapRecommendations returns prioritized list', function (): void {
            $service = new ProviderEventCompatibilityMatrix($this->cache, $this->config);
            $recommendations = $service->getGapRecommendations('plausible', 10);

            expect($recommendations)->toBeArray();
            foreach ($recommendations as $rec) {
                expect($rec)->toHaveKeys(['event', 'category', 'missing_providers', 'priority']);
                expect($rec['priority'])->toBeIn(['high', 'medium', 'low']);
            }
        });

        test('summary returns expected structure', function (): void {
            $service = new ProviderEventCompatibilityMatrix($this->cache, $this->config);
            $summary = $service->summary();

            expect($summary)->toHaveKeys(['enabled', 'providers', 'catalog_size', 'cache_ttl']);
            expect($summary['catalog_size'])->toBe(EventCatalog::count());
        });

        test('clearCache does not throw', function (): void {
            $service = new ProviderEventCompatibilityMatrix($this->cache, $this->config);
            expect(fn () => $service->clearCache())->not->toThrow();
        });
    });

    // ── 5. AnalyticsFlowCommand ──────────────────────────────────────────

    describe('AnalyticsFlowCommand', function () {
        test('class exists', function (): void {
            expect(class_exists(AnalyticsFlowCommand::class))->toBeTrue();
        });

        test('command signature is correct', function (): void {
            $command = new AnalyticsFlowCommand($this->config);
            expect($command->getName())->toBe('zb:analytics:flow');
        });
    });

    // ── 6. EventCatalog Integrity ────────────────────────────────────────

    test('v46.0.0: EventCatalog integrity', function (): void {
        $catalog = EventCatalog::all();
        expect($catalog)->not->toBeEmpty();
        expect(EventCatalog::count())->toBeGreaterThan(100);

        $validation = EventCatalog::validate();
        expect($validation['valid'])->toBeTrue();
        expect($validation['errors'])->toBeEmpty();
    });

    test('v46.0.0: EventCatalog has all 6 categories', function (): void {
        $byCategory = EventCatalog::byCategory();
        expect(array_keys($byCategory))->toHaveCount(6);
        expect($byCategory)->toHaveKeys(['ecommerce', 'saas', 'engagement', 'security', 'uptime', 'infrastructure']);
    });

    // ── 7. Config Coverage ─────────────────────────────────────────────

    test('v46.0.0: config has event_flow section', function (): void {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        expect($config)->toHaveKey('analytics');
        expect($config['analytics'])->toHaveKey('event_flow');
        expect($config['analytics']['event_flow'])->toHaveKeys(['enabled', 'max_path_length', 'path_ttl', 'cache_prefix', 'metrics_ttl']);
    });

    test('v46.0.0: config has quality_firewall section', function (): void {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        expect($config['analytics'])->toHaveKey('quality_firewall');
        expect($config['analytics']['quality_firewall'])->toHaveKeys(['enabled', 'quarantine_threshold', 'drop_threshold', 'enforce_quarantine', 'enforce_drop']);
    });

    test('v46.0.0: config has provider_matrix section', function (): void {
        $config = include __DIR__ . '/../config/zeroboiler.php';
        expect($config['analytics'])->toHaveKey('provider_matrix');
        expect($config['analytics']['provider_matrix'])->toHaveKeys(['enabled', 'cache_prefix', 'cache_ttl']);
    });

    // ── 8. ServiceProvider Registration ───────────────────────────────

    test('v46.0.0: ServiceProvider has v46 version', function (): void {
        $reflection = new ReflectionClass(\ZeroBoiler\Analytics\AnalyticsServiceProvider::class);
        $doc = $reflection->getDocComment() ?: '';
        expect($doc)->toContain('46.0.0');
    });

    test('v46.0.0: ServiceProvider imports new services', function (): void {
        $file = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        expect($file)->toContain('use ZeroBoiler\\Analytics\\Services\\EventFlowAnalysisService');
        expect($file)->toContain('use ZeroBoiler\\Analytics\\Services\\AnalyticsDataQualityFirewall');
        expect($file)->toContain('use ZeroBoiler\\Analytics\\Console\\Commands\\AnalyticsFlowCommand');
    });

    test('v46.0.0: ServiceProvider registers new commands', function (): void {
        $file = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        expect($file)->toContain('AnalyticsFlowCommand::class');
    });
});
