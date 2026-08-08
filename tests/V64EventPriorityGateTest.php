<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\EventPriority;
use ZeroBoiler\Analytics\Pipeline\PriorityAwareFilter;
use ZeroBoiler\Analytics\Services\EventPriorityGate;

beforeEach(function (): void {
    $this->cache = new \Illuminate\Cache\ArrayStore;
    $this->repository = new \Illuminate\Config\Repository([]);
    $this->gate = new EventPriorityGate(
        new \Illuminate\Cache\Repository($this->cache),
        $this->repository,
    );
    $this->filter = new PriorityAwareFilter($this->gate);
});

describe('EventPriority enum', function (): void {
    test('has four priority levels', function (): void {
        expect(EventPriority::cases())->toHaveCount(4);
    });

    test('values are lowercase strings', function (): void {
        expect(EventPriority::Critical->value)->toBe('critical');
        expect(EventPriority::Normal->value)->toBe('normal');
        expect(EventPriority::Low->value)->toBe('low');
        expect(EventPriority::Background->value)->toBe('background');
    });

    test('weights are correctly ordered', function (): void {
        expect(EventPriority::Critical->weight())->toBe(3);
        expect(EventPriority::Normal->weight())->toBe(2);
        expect(EventPriority::Low->weight())->toBe(1);
        expect(EventPriority::Background->weight())->toBe(0);
    });

    test('critical bypasses all filters', function (): void {
        expect(EventPriority::Critical->bypassesFilters())->toBeTrue();
        expect(EventPriority::Normal->bypassesFilters())->toBeFalse();
    });

    test('low and background are subject to sampling', function (): void {
        expect(EventPriority::Low->subjectToSampling())->toBeTrue();
        expect(EventPriority::Background->subjectToSampling())->toBeTrue();
        expect(EventPriority::Normal->subjectToSampling())->toBeFalse();
        expect(EventPriority::Critical->subjectToSampling())->toBeFalse();
    });

    test('low and background are subject to budget', function (): void {
        expect(EventPriority::Low->subjectToBudget())->toBeTrue();
        expect(EventPriority::Background->subjectToBudget())->toBeTrue();
        expect(EventPriority::Normal->subjectToBudget())->toBeFalse();
    });

    test('background is deferrable', function (): void {
        expect(EventPriority::Background->deferrable())->toBeTrue();
        expect(EventPriority::Normal->deferrable())->toBeFalse();
    });

    test('fromString resolves valid values', function (): void {
        expect(EventPriority::fromString('critical'))->toBe(EventPriority::Critical);
        expect(EventPriority::fromString('NORMAL'))->toBe(EventPriority::Normal);
        expect(EventPriority::fromString('Low'))->toBe(EventPriority::Low);
        expect(EventPriority::fromString('background'))->toBe(EventPriority::Background);
    });

    test('fromString returns null for invalid values', function (): void {
        expect(EventPriority::fromString('urgent'))->toBeNull();
        expect(EventPriority::fromString(''))->toBeNull();
    });

    test('values() returns all string values', function (): void {
        $values = EventPriority::values();
        expect($values)->toContain('critical');
        expect($values)->toContain('normal');
        expect($values)->toContain('low');
        expect($values)->toContain('background');
    });
});

describe('EventPriorityGate', function (): void {
    test('resolves priority for critical revenue events', function (): void {
        $purchase = new AnalyticsEvent(name: 'purchase');
        $subscription = new AnalyticsEvent(name: 'subscription');
        $signUp = new AnalyticsEvent(name: 'sign_up');

        expect($this->gate->resolvePriority($purchase))->toBe(EventPriority::Critical);
        expect($this->gate->resolvePriority($subscription))->toBe(EventPriority::Critical);
        expect($this->gate->resolvePriority($signUp))->toBe(EventPriority::Critical);
    });

    test('resolves priority for low-priority high-volume events', function (): void {
        $scrollDepth = new AnalyticsEvent(name: 'scroll_depth');
        $outboundClick = new AnalyticsEvent(name: 'outbound_click');
        $timing = new AnalyticsEvent(name: 'timing');

        expect($this->gate->resolvePriority($scrollDepth))->toBe(EventPriority::Low);
        expect($this->gate->resolvePriority($outboundClick))->toBe(EventPriority::Low);
        expect($this->gate->resolvePriority($timing))->toBe(EventPriority::Low);
    });

    test('resolves priority for background events', function (): void {
        $abTest = new AnalyticsEvent(name: 'ab_test_exposure');
        $notification = new AnalyticsEvent(name: 'notification');

        expect($this->gate->resolvePriority($abTest))->toBe(EventPriority::Background);
        expect($this->gate->resolvePriority($notification))->toBe(EventPriority::Background);
    });

    test('resolves default priority for unknown events', function (): void {
        $custom = new AnalyticsEvent(name: 'my_custom_event');

        expect($this->gate->resolvePriority($custom))->toBe(EventPriority::Normal);
    });

    test('explicit _priority param overrides resolved priority', function (): void {
        $custom = new AnalyticsEvent(name: 'my_custom_event', params: ['_priority' => 'critical']);
        expect($this->gate->resolvePriority($custom))->toBe(EventPriority::Critical);
    });

    test('explicit _priority param accepts case-insensitive values', function (): void {
        $custom = new AnalyticsEvent(name: 'scroll_depth', params: ['_priority' => 'CRITICAL']);
        expect($this->gate->resolvePriority($custom))->toBe(EventPriority::Critical);
    });

    test('allows all events when gate is disabled', function (): void {
        $this->repository->set('zeroboiler', ['analytics' => ['priority' => ['enabled' => false]]]);
        $gate = new EventPriorityGate(
            new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore),
            $this->repository,
        );

        $event = new AnalyticsEvent(name: 'scroll_depth');
        expect($gate->allows($event))->toBeTrue();
    });

    test('always allows critical events', function (): void {
        $this->repository->set('zeroboiler', [
            'analytics' => [
                'priority' => [
                    'enabled' => true,
                    'rate_limits' => ['critical' => 0, 'normal' => 0, 'low' => 0, 'background' => 0],
                ],
            ],
        ]);

        $gate = new EventPriorityGate(
            new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore),
            $this->repository,
        );

        $purchase = new AnalyticsEvent(name: 'purchase');
        expect($gate->allows($purchase))->toBeTrue();
    });

    test('respects custom config overrides', function (): void {
        $this->repository->set('zeroboiler', [
            'analytics' => [
                'priority' => [
                    'enabled' => true,
                    'overrides' => ['my_custom_event' => 'critical'],
                ],
            ],
        ]);

        $gate = new EventPriorityGate(
            new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore),
            $this->repository,
        );

        $custom = new AnalyticsEvent(name: 'my_custom_event');
        expect($gate->resolvePriority($custom))->toBe(EventPriority::Critical);
    });

    test('getRateLimit returns configured values', function (): void {
        expect($this->gate->getRateLimit(EventPriority::Critical))->toBe(10_000);
        expect($this->gate->getRateLimit(EventPriority::Normal))->toBe(1_000);
        expect($this->gate->getRateLimit(EventPriority::Low))->toBe(200);
        expect($this->gate->getRateLimit(EventPriority::Background))->toBe(50);
    });

    test('getBuiltinOverrides returns expected event count', function (): void {
        $overrides = EventPriorityGate::getBuiltinOverrides();
        expect($overrides)->toBeArray();
        expect(count($overrides))->toBeGreaterThan(10);
        expect($overrides)->toHaveKey('purchase');
        expect($overrides)->toHaveKey('scroll_depth');
    });

    test('summary returns structured diagnostics', function (): void {
        $summary = $this->gate->summary();
        expect($summary)->toBeArray();
        expect($summary)->toHaveKeys(['enabled', 'budget_aware', 'budget_threshold', 'rate_limits', 'current_counts', 'override_count', 'builtin_override_count']);
        expect($summary['rate_limits'])->toHaveKey('critical');
        expect($summary['rate_limits'])->toHaveKey('normal');
        expect($summary['rate_limits'])->toHaveKey('low');
        expect($summary['rate_limits'])->toHaveKey('background');
    });

    test('resets counters correctly', function (): void {
        $this->gate->resetCounters();
        expect($this->gate->getCurrentCount(EventPriority::Critical))->toBe(0);
        expect($this->gate->getCurrentCount(EventPriority::Normal))->toBe(0);
    });
});

describe('PriorityAwareFilter', function (): void {
    test('passes through events allowed by gate', function (): void {
        $purchase = new AnalyticsEvent(name: 'purchase');
        $result = ($this->filter)($purchase);

        expect($result)->not->toBeNull();
        expect($result->name)->toBe('purchase');
    });

    test('drops events rejected by gate', function (): void {
        // Set rate limit to 0 for low-priority events
        $this->repository->set('zeroboiler', [
            'analytics' => [
                'priority' => [
                    'enabled' => true,
                    'rate_limits' => ['critical' => 10000, 'normal' => 10000, 'low' => 0, 'background' => 0],
                    'budget_aware' => false,
                ],
            ],
        ]);

        $gate = new EventPriorityGate(
            new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore),
            $this->repository,
        );
        $filter = new PriorityAwareFilter($gate);

        $scrollDepth = new AnalyticsEvent(name: 'scroll_depth');
        $result = ($filter)($scrollDepth);

        expect($result)->toBeNull();
        expect($filter->getDroppedCount())->toBe(1);
    });

    test('tracks dropped count', function (): void {
        $this->repository->set('zeroboiler', [
            'analytics' => [
                'priority' => [
                    'enabled' => true,
                    'rate_limits' => ['critical' => 10000, 'normal' => 10000, 'low' => 0, 'background' => 0],
                    'budget_aware' => false,
                ],
            ],
        ]);

        $gate = new EventPriorityGate(
            new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore),
            $this->repository,
        );
        $filter = new PriorityAwareFilter($gate);

        $scrollDepth = new AnalyticsEvent(name: 'scroll_depth');
        ($filter)($scrollDepth);
        ($filter)($scrollDepth);

        expect($filter->getDroppedCount())->toBe(2);
    });

    test('resets dropped count', function (): void {
        $this->filter->resetDroppedCount();
        expect($this->filter->getDroppedCount())->toBe(0);
    });

    test('resolvePriority delegates to gate', function (): void {
        $purchase = new AnalyticsEvent(name: 'purchase');
        expect($this->filter->resolvePriority($purchase))->toBe(EventPriority::Critical);
    });

    test('isEnabled delegates to gate', function (): void {
        expect($this->filter->isEnabled())->toBeTrue();
    });
});

describe('Version consistency', function (): void {
    test('version is 2.66.0 across key files', function (): void {
        // Check that files exist and contain the version
        $composerJson = file_get_contents(__DIR__ . '/../composer.json');
        $managerPhp = file_get_contents(__DIR__ . '/../src/AnalyticsManager.php');
        $analyticsJs = file_get_contents(__DIR__ . '/../resources/js/analytics.js');

        expect($composerJson)->toContain('2.90.0');
        expect($managerPhp)->toContain('2.90.0');
        expect($analyticsJs)->toContain('2.90.0');
    });

    test('EventPriority file exists with correct namespace', function (): void {
        $file = __DIR__ . '/../src/DTO/EventPriority.php';
        expect(file_exists($file))->toBeTrue();
        $contents = file_get_contents($file);
        expect($contents)->toContain('namespace ZeroBoiler\\Analytics\\DTO');
        expect($contents)->toContain('declare(strict_types=1)');
    });

    test('EventPriorityGate file exists with correct namespace', function (): void {
        $file = __DIR__ . '/../src/Services/EventPriorityGate.php';
        expect(file_exists($file))->toBeTrue();
        $contents = file_get_contents($file);
        expect($contents)->toContain('namespace ZeroBoiler\\Analytics\\Services');
        expect($contents)->toContain('declare(strict_types=1)');
    });

    test('PriorityAwareFilter file exists with correct namespace', function (): void {
        $file = __DIR__ . '/../src/Pipeline/PriorityAwareFilter.php';
        expect(file_exists($file))->toBeTrue();
        $contents = file_get_contents($file);
        expect($contents)->toContain('namespace ZeroBoiler\\Analytics\\Pipeline');
        expect($contents)->toContain('declare(strict_types=1)');
    });

    test('priority config section exists', function (): void {
        $config = file_get_contents(__DIR__ . '/../config/zeroboiler.php');
        expect($config)->toContain("'priority' => [");
        expect($config)->toContain('ANALYTICS_PRIORITY_ENABLED');
    });

    test('ServiceProvider registers EventPriorityGate', function (): void {
        $provider = file_get_contents(__DIR__ . '/../src/AnalyticsServiceProvider.php');
        expect($provider)->toContain('use ZeroBoiler\\Analytics\\Services\\EventPriorityGate');
        expect($provider)->toContain('EventPriorityGate::class');
    });

    test('AnalyticsConfig has priority accessors', function (): void {
        $config = file_get_contents(__DIR__ . '/../src/Support/AnalyticsConfig.php');
        expect($config)->toContain('priorityEnabled');
        expect($config)->toContain('priorityRateLimit');
        expect($config)->toContain('priorityCacheTtl');
        expect($config)->toContain('priorityBudgetAware');
        expect($config)->toContain('priorityOverrides');
    });
});

describe('PHP 8.5 compliance', function (): void {
    test('EventPriority uses enum syntax', function (): void {
        $contents = file_get_contents(__DIR__ . '/../src/DTO/EventPriority.php');
        expect($contents)->toContain('enum EventPriority: string');
        expect($contents)->not->toContain('public function __construct');
    });

    test('all new files declare strict types', function (): void {
        $files = [
            __DIR__ . '/../src/DTO/EventPriority.php',
            __DIR__ . '/../src/Services/EventPriorityGate.php',
            __DIR__ . '/../src/Pipeline/PriorityAwareFilter.php',
        ];

        foreach ($files as $file) {
            expect(file_get_contents($file))->toContain('declare(strict_types=1)');
        }
    });

    test('EventPriorityGate constructor has void return type', function (): void {
        $contents = file_get_contents(__DIR__ . '/../src/Services/EventPriorityGate.php');
        expect($contents)->toContain('public function __construct(');
        // Check for :void on or near the constructor
        expect($contents)->toMatch('/public function __construct\([^)]+\):\s*void/');
    });

    test('PriorityAwareFilter constructor has void return type', function (): void {
        $contents = file_get_contents(__DIR__ . '/../src/Pipeline/PriorityAwareFilter.php');
        expect($contents)->toMatch('/public function __construct\([^)]+\):\s*void/');
    });
});
