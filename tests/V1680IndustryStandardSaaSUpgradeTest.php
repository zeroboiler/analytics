<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Observers\AnalyticsEventObserver;
use ZeroBoiler\Analytics\Services\EventWarehouseExportService;
use ZeroBoiler\Analytics\Services\SaaSRetentionCohortService;
use ZeroBoiler\Analytics\Support\AnalyticsFake;

describe('V1680 — Industry-Standard SaaS Analytics Upgrade', function (): void {
    beforeEach(function (): void {
        AnalyticsEventObserver::clearMappings();
    });

    afterEach(function (): void {
        AnalyticsEventObserver::clearMappings();
    });

    // ── AnalyticsEventObserver ────────────────────────────────────────

    describe('AnalyticsEventObserver — Eloquent Model Auto-Tracking', function (): void {
        it('registers mappings via observe()', function (): void {
            AnalyticsEventObserver::observe(
                \App\Models\User::class,
                ['created' => ['event' => 'user_created', 'category' => 'saas']],
            );

            $mappings = AnalyticsEventObserver::getMappings();

            expect($mappings)->toHaveKey(\App\Models\User::class);
            expect($mappings[\App\Models\User::class])->toHaveKey('created');
            expect($mappings[\App\Models\User::class]['created']['event'])->toBe('user_created');
            expect($mappings[\App\Models\User::class]['created']['category'])->toBe('saas');
        });

        it('registers multiple event mappings for a single model', function (): void {
            AnalyticsEventObserver::observe(
                \App\Models\User::class,
                [
                    'created' => ['event' => 'user_created', 'category' => 'saas'],
                    'deleted' => ['event' => 'user_deleted', 'category' => 'saas'],
                    'updated' => ['event' => 'user_updated', 'category' => 'saas'],
                ],
            );

            $mappings = AnalyticsEventObserver::getMappings();

            expect(count($mappings[\App\Models\User::class]))->toBe(3);
        });

        it('registers from config array format', function (): void {
            AnalyticsEventObserver::registerFromConfig([
                'App\\Models\\Workspace' => ['created', 'deleted'],
            ]);

            $mappings = AnalyticsEventObserver::getMappings();

            expect($mappings)->toHaveKey('App\\Models\\Workspace');
            expect($mappings['App\\Models\\Workspace'])->toHaveKey('created');
            expect($mappings['App\\Models\\Workspace'])->toHaveKey('deleted');
        });

        it('derives event name from model class when not specified', function (): void {
            AnalyticsEventObserver::registerFromConfig([
                'App\\Models\\Subscription' => ['created'],
            ]);

            $mappings = AnalyticsEventObserver::getMappings();
            $eventName = $mappings['App\\Models\\Subscription']['created']['event'];

            // "subscription_created" derived from "Subscription" + "created"
            expect($eventName)->toBe('subscription_created');
        });

        it('clears all mappings', function (): void {
            AnalyticsEventObserver::observe(
                'App\\Models\\Test',
                ['created' => ['event' => 'test_created']],
            );

            expect(AnalyticsEventObserver::getMappings())->not->toBeEmpty();

            AnalyticsEventObserver::clearMappings();

            expect(AnalyticsEventObserver::getMappings())->toBeEmpty();
        });

        it('supports extended config format with custom events', function (): void {
            AnalyticsEventObserver::registerFromConfig([
                'App\\Models\\Order' => [
                    'created' => ['event' => 'order_placed', 'category' => 'ecommerce'],
                    'updated' => ['event' => 'order_updated', 'category' => 'ecommerce'],
                ],
            ]);

            $mappings = AnalyticsEventObserver::getMappings();
            expect($mappings['App\\Models\\Order']['created']['event'])->toBe('order_placed');
            expect($mappings['App\\Models\\Order']['created']['category'])->toBe('ecommerce');
            expect($mappings['App\\Models\\Order']['updated']['event'])->toBe('order_updated');
        });

        it('supports param_keys option for selective attribute extraction', function (): void {
            AnalyticsEventObserver::observe(
                'App\\Models\\Product',
                [
                    'created' => [
                        'event' => 'product_added',
                        'category' => 'ecommerce',
                        'param_keys' => ['name', 'price', 'category'],
                    ],
                ],
            );

            $mappings = AnalyticsEventObserver::getMappings();
            expect($mappings['App\\Models\\Product']['created']['param_keys'])->toBe(['name', 'price', 'category']);
        });
    });

    // ── SaaSRetentionCohortService ─────────────────────────────────────

    describe('SaaSRetentionCohortService — Retention Cohort Analytics', function (): void {
        it('computes cohort table structure', function (): void {
            $service = new SaaSRetentionCohortService;

            $result = $service->compute(
                cohortEvent: 'sign_up',
                returnEvent: 'page_view',
                periodType: 'daily',
                periods: 7,
            );

            expect($result)->toHaveKey('cohort_event');
            expect($result)->toHaveKey('period_type');
            expect($result)->toHaveKey('periods');
            expect($result)->toHaveKey('cohorts');
            expect($result)->toHaveKey('summary');
            expect($result['cohort_event'])->toBe('sign_up');
            expect($result['period_type'])->toBe('daily');
            expect($result['periods'])->toBe(7);
            expect(count($result['cohorts']))->toBe(7);
        });

        it('cohort has retention array with correct period count', function (): void {
            $service = new SaaSRetentionCohortService;

            $result = $service->compute('sign_up', 'page_view', 'daily', 5);

            foreach ($result['cohorts'] as $cohort) {
                expect($cohort)->toHaveKey('cohort_period');
                expect($cohort)->toHaveKey('cohort_size');
                expect($cohort)->toHaveKey('retention');
                expect(count($cohort['retention']))->toBe(5);
            }
        });

        it('retention entries have required fields', function (): void {
            $service = new SaaSRetentionCohortService;

            $result = $service->compute('sign_up', 'page_view', 'weekly', 3);

            $firstCohort = $result['cohorts'][0];
            $firstRetention = $firstCohort['retention'][0];

            expect($firstRetention)->toHaveKey('period');
            expect($firstRetention)->toHaveKey('pct');
            expect($firstRetention)->toHaveKey('count');
            expect($firstRetention['period'])->toBe(1);
        });

        it('summary has retention metrics', function (): void {
            $service = new SaaSRetentionCohortService;

            $result = $service->compute('sign_up', 'page_view', 'daily', 7);

            expect($result['summary'])->toHaveKey('avg_retention_d1');
            expect($result['summary'])->toHaveKey('avg_retention_d7');
            expect($result['summary'])->toHaveKey('avg_retention_d30');
            expect($result['summary'])->toHaveKey('best_cohort');
        });

        it('provides user retention structure', function (): void {
            $service = new SaaSRetentionCohortService;

            $result = $service->userRetention('user-123', 'sign_up');

            expect($result)->toHaveKey('user_id');
            expect($result)->toHaveKey('cohort_date');
            expect($result)->toHaveKey('days_active');
            expect($result)->toHaveKey('retention_days');
            expect($result)->toHaveKey('streak');
            expect($result['user_id'])->toBe('user-123');
        });

        it('compares two cohorts', function (): void {
            $service = new SaaSRetentionCohortService;

            $result = $service->compareCohorts('sign_up', 'trial_start');

            expect($result)->toHaveKey('cohort_a');
            expect($result)->toHaveKey('cohort_b');
            expect($result)->toHaveKey('delta');
            expect($result)->toHaveKey('winner');
            expect($result['delta'])->toHaveKey('d1');
            expect($result['delta'])->toHaveKey('d7');
            expect($result['delta'])->toHaveKey('d30');
        });

        it('provides dashboard summary with grades', function (): void {
            $service = new SaaSRetentionCohortService;

            $result = $service->summary('sign_up');

            expect($result)->toHaveKey('d1_retention');
            expect($result)->toHaveKey('d7_retention');
            expect($result)->toHaveKey('d30_retention');
            expect($result)->toHaveKey('d60_retention');
            expect($result)->toHaveKey('trend');
            expect($result)->toHaveKey('grade');
            expect(in_array($result['grade'], ['A', 'B', 'C', 'D', 'F'], true))->toBeTrue();
            expect(in_array($result['trend'], ['healthy', 'moderate', 'concerning'], true))->toBeTrue();
        });

        it('supports different period types', function (): void {
            $service = new SaaSRetentionCohortService;

            $daily = $service->compute('sign_up', 'page_view', 'daily', 7);
            $weekly = $service->compute('sign_up', 'page_view', 'weekly', 4);
            $monthly = $service->compute('sign_up', 'page_view', 'monthly', 6);

            expect($daily['period_type'])->toBe('daily');
            expect($weekly['period_type'])->toBe('weekly');
            expect($monthly['period_type'])->toBe('monthly');
        });
    });

    // ── EventWarehouseExportService ────────────────────────────────────

    describe('EventWarehouseExportService — Data Warehouse Export', function (): void {
        it('exports events to JSONL format', function (): void {
            $service = new EventWarehouseExportService;

            $events = [
                ['name' => 'page_view', 'params' => ['url' => '/home'], 'category' => 'engagement'],
                ['name' => 'sign_up', 'params' => ['method' => 'email'], 'category' => 'saas'],
            ];

            $jsonl = $service->toJsonl($events);
            $lines = explode("\n", $jsonl);

            expect(count($lines))->toBe(2);

            $first = json_decode($lines[0], true);
            expect($first['event_name'])->toBe('page_view');
            expect($first['category'])->toBe('engagement');

            $second = json_decode($lines[1], true);
            expect($second['event_name'])->toBe('sign_up');
            expect($second['category'])->toBe('saas');
        });

        it('exports events to CSV format', function (): void {
            $service = new EventWarehouseExportService;

            $events = [
                ['name' => 'purchase', 'params' => ['value' => 99.99], 'category' => 'ecommerce'],
            ];

            $csv = $service->toCsv($events);
            $lines = explode("\n", $csv);

            expect(count($lines))->toBe(2); // header + data

            // Header should contain standard fields
            expect($lines[0])->toContain('event_name');
            expect($lines[0])->toContain('category');
            expect($lines[0])->toContain('timestamp');
            expect($lines[0])->toContain('client_id');
        });

        it('returns empty string for empty CSV export', function (): void {
            $service = new EventWarehouseExportService;

            expect($service->toCsv([]))->toBe('');
        });

        it('JSONL handles empty events array', function (): void {
            $service = new EventWarehouseExportService;

            expect($service->toJsonl([]))->toBe('');
        });

        it('provides warehouse schema', function (): void {
            $service = new EventWarehouseExportService;

            $schema = $service->schema();

            expect(count($schema))->toBeGreaterThan(10);

            // Check required fields are present
            $columnNames = array_column($schema, 'column');
            expect($columnNames)->toContain('event_id');
            expect($columnNames)->toContain('event_name');
            expect($columnNames)->toContain('category');
            expect($columnNames)->toContain('params');
            expect($columnNames)->toContain('timestamp');
            expect($columnNames)->toContain('client_id');
            expect($columnNames)->toContain('user_id');
            expect($columnNames)->toContain('utm_source');
        });

        it('generates BigQuery-compatible schema', function (): void {
            $service = new EventWarehouseExportService;

            $bqSchema = $service->bigQuerySchema();

            expect(count($bqSchema))->toBeGreaterThan(10);

            $first = $bqSchema[0];
            expect($first)->toHaveKey('name');
            expect($first)->toHaveKey('type');
            expect($first)->toHaveKey('mode');
            expect($first)->toHaveKey('description');
        });

        it('generates Snowflake DDL', function (): void {
            $service = new EventWarehouseExportService;

            $ddl = $service->snowflakeDdl('my_analytics_events');

            expect($ddl)->toContain('CREATE TABLE IF NOT EXISTS my_analytics_events');
            expect($ddl)->toContain('event_id');
            expect($ddl)->toContain('event_name');
            expect($ddl)->toContain('NOT NULL');
        });

        it('generates ClickHouse DDL', function (): void {
            $service = new EventWarehouseExportService;

            $ddl = $service->clickHouseDdl('analytics_events');

            expect($ddl)->toContain('CREATE TABLE IF NOT EXISTS analytics_events');
            expect($ddl)->toContain('ENGINE =');
            expect($ddl)->toContain('ORDER BY');
        });

        it('returns supported formats', function (): void {
            $service = new EventWarehouseExportService;

            $formats = $service->supportedFormats();

            expect($formats)->toHaveKey('jsonl');
            expect($formats)->toHaveKey('csv');
            expect($formats['jsonl'])->toBe('application/x-ndjson');
            expect($formats['csv'])->toBe('text/csv');
        });

        it('normalizes events with UTM and device context', function (): void {
            $service = new EventWarehouseExportService;

            $events = [
                [
                    'name' => 'page_view',
                    'params' => [
                        'utm_source' => 'google',
                        'utm_medium' => 'cpc',
                        'utm_campaign' => 'spring_sale',
                        'platform' => 'mobile',
                        'browser' => 'Chrome',
                    ],
                ],
            ];

            $jsonl = $service->toJsonl($events);
            $decoded = json_decode($jsonl, true);

            expect($decoded['utm_source'])->toBe('google');
            expect($decoded['utm_medium'])->toBe('cpc');
            expect($decoded['utm_campaign'])->toBe('spring_sale');
            expect($decoded['device_platform'])->toBe('mobile');
            expect($decoded['device_browser'])->toBe('Chrome');
        });
    });

    // ── Version Sweep ────────────────────────────────────────────────

    describe('Version Sweep 167 → 168', function (): void {
        it('AnalyticsEvent DTO has version 168.0.0', function (): void {
            expect(AnalyticsEvent::VERSION)->toBe('168.0.0');
        });

        it('composer.json has version 168.0.0', function (): void {
            $composer = json_decode(file_get_contents(base_path('composer.json')), true);
            expect($composer['version'])->toBe('168.0.0');
        });

        it('package.json has version 168.0.0', function (): void {
            $pkg = json_decode(file_get_contents(base_path('package.json')), true);
            expect($pkg['version'])->toBe('168.0.0');
        });

        it('analytics.js getVersion returns 168.0.0', function (): void {
            $js = file_get_contents(base_path('resources/js/analytics.js'));
            expect($js)->toContain("return '168.0.0';");
        });

        it('all JS entry points have @version 168.0.0', function (): void {
            $files = [
                'resources/js/analytics.js',
                'resources/js/analytics.constants.js',
                'resources/js/useAnalytics.svelte.js',
                'resources/js/useAnalyticsConfig.svelte.js',
                'resources/js/useSessionReplay.svelte.js',
                'resources/js/usePerformanceTracker.svelte.js',
                'resources/js/useEcommerce.svelte.js',
                'resources/js/useSaaSMetrics.svelte.js',
                'resources/js/useLifecycle.svelte.js',
            ];

            foreach ($files as $file) {
                $content = file_get_contents(base_path($file));
                expect($content, "Missing @version 168.0.0 in {$file}")
                    ->toContain('@version 168.0.0');
            }
        });

        it('analytics.d.ts has @version 168.0.0', function (): void {
            $dts = file_get_contents(base_path('resources/js/analytics.d.ts'));
            expect($dts)->toContain('@version 168.0.0');
        });

        it('AnalyticsIntegrityCommand expects 168.0.0', function (): void {
            $ref = new ReflectionClass(\ZeroBoiler\Analytics\Console\Commands\AnalyticsIntegrityCommand::class);
            $const = $ref->getConstant('EXPECTED_VERSION');
            expect($const)->toBe('168.0.0');
        });

        it('AnalyticsServiceProvider has @version 168.0.0', function (): void {
            $content = file_get_contents(base_path('src/AnalyticsServiceProvider.php'));
            expect($content)->toContain('@version 168.0.0');
        });

        it('new services exist and are properly typed', function (): void {
            expect(class_exists(\ZeroBoiler\Analytics\Observers\AnalyticsEventObserver::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Services\SaaSRetentionCohortService::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Services\EventWarehouseExportService::class))->toBeTrue();
            expect(class_exists(\ZeroBoiler\Analytics\Http\Middleware\AnalyticsRequestTrackerMiddleware::class))->toBeTrue();
        });

        it('new services use declare(strict_types=1)', function (): void {
            $files = [
                'src/Observers/AnalyticsEventObserver.php',
                'src/Services/SaaSRetentionCohortService.php',
                'src/Services/EventWarehouseExportService.php',
                'src/Http/Middleware/AnalyticsRequestTrackerMiddleware.php',
            ];

            foreach ($files as $file) {
                $content = file_get_contents(base_path($file));
                expect($content, "Missing declare(strict_types=1) in {$file}")
                    ->toContain('declare(strict_types=1)');
            }
        });

        it('config has request_tracking section', function (): void {
            $config = require base_path('config/zeroboiler.php');
            expect($config)->toHaveKey('request_tracking');
            expect($config['request_tracking'])->toHaveKey('enabled');
            expect($config['request_tracking'])->toHaveKey('track_success');
            expect($config['request_tracking'])->toHaveKey('track_client_errors');
            expect($config['request_tracking'])->toHaveKey('track_server_errors');
            expect($config['request_tracking'])->toHaveKey('slow_threshold_ms');
            expect($config['request_tracking'])->toHaveKey('exclude_paths');
            expect($config['request_tracking'])->toHaveKey('exclude_methods');
        });
    });
});
