<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Feature\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\SaaSJourneyService;
use Mockery;
use Mockery\MockInterface;

/**
 * @covers \ZeroBoiler\Analytics\Services\SaaSJourneyService
 */
final class SaaSJourneyServiceTest extends \PHPUnit\Framework\TestCase
{
    private AnalyticsManager&MockInterface $manager;

    private CacheRepository&MockInterface $cache;

    private ConfigRepository&MockInterface $config;

    private SaaSJourneyService $service;

    protected function setUp(): void
    {
        $this->manager = Mockery::mock(AnalyticsManager::class);
        $this->cache = Mockery::mock(CacheRepository::class);
        $this->config = Mockery::mock(ConfigRepository::class);

        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.journeys', [])
            ->andReturn(['enabled' => true, 'cache_ttl' => 2592000, 'definitions' => []]);

        $this->service = new SaaSJourneyService(
            $this->manager,
            $this->cache,
            $this->config,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testHitMilestoneRecordsFirstMilestone(): void
    {
        $clientId = 'test-client-123';
        $cacheKey = 'zb_journey_test-client-123_acquisition';

        $this->cache->shouldReceive('get')
            ->with($cacheKey, [])
            ->once()
            ->andReturn([]);

        $this->cache->shouldReceive('put')
            ->with($cacheKey, Mockery::type('array'), 2592000)
            ->once();

        $this->manager->shouldReceive('trackEvent')
            ->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'journey_acquisition_step'
                    && $event->params['milestone'] === 'landing_page'
                    && $event->params['completed_milestones'] === 1;
            })
            ->once();

        $this->service->hitMilestone('acquisition', 'landing_page', $clientId, ['source' => 'google']);
    }

    public function testHitMilestoneCompletesJourney(): void
    {
        $clientId = 'test-client-456';
        $cacheKey = 'zb_journey_test-client-456_acquisition';

        // Simulate 3 of 4 milestones already complete
        $existingProgress = [
            'milestones' => [
                'landing_page' => ['hit_at' => '2026-01-01T00:00:00+00:00', 'params' => []],
                'signup_view' => ['hit_at' => '2026-01-01T00:05:00+00:00', 'params' => []],
                'signup_submit' => ['hit_at' => '2026-01-01T00:10:00+00:00', 'params' => []],
            ],
            'started_at' => '2026-01-01T00:00:00+00:00',
        ];

        $this->cache->shouldReceive('get')
            ->with($cacheKey, [])
            ->once()
            ->andReturn($existingProgress);

        $this->cache->shouldReceive('put')
            ->with($cacheKey, Mockery::type('array'), 2592000)
            ->once();

        // Should dispatch step event AND journey completion event
        $this->manager->shouldReceive('trackEvent')
            ->withArgs(fn (AnalyticsEvent $e): bool => $e->name === 'journey_acquisition_step')
            ->once();

        $this->manager->shouldReceive('trackEvent')
            ->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'journey_acquisition_completed'
                    && isset($event->params['duration_seconds'])
                    && isset($event->params['step_timings'])
                    && $event->params['total_milestones'] === 4;
            })
            ->once();

        $this->service->hitMilestone('acquisition', 'signup_confirm', $clientId);
    }

    public function testHitMilestoneIsIdempotent(): void
    {
        $clientId = 'test-client-789';
        $cacheKey = 'zb_journey_test-client-789_acquisition';

        $existingProgress = [
            'milestones' => [
                'landing_page' => ['hit_at' => '2026-01-01T00:00:00+00:00', 'params' => []],
            ],
            'started_at' => '2026-01-01T00:00:00+00:00',
        ];

        $this->cache->shouldReceive('get')
            ->with($cacheKey, [])
            ->once()
            ->andReturn($existingProgress);

        // Should NOT put to cache or track any event for duplicate milestone
        $this->cache->shouldNotReceive('put');
        $this->manager->shouldNotReceive('trackEvent');

        $this->service->hitMilestone('acquisition', 'landing_page', $clientId);
    }

    public function testHitMilestoneIgnoresInvalidJourney(): void
    {
        $this->cache->shouldNotReceive('get');
        $this->manager->shouldNotReceive('trackEvent');

        $this->service->hitMilestone('nonexistent_journey', 'some_step', 'client-id');
    }

    public function testHitMilestoneIgnoresInvalidMilestone(): void
    {
        $this->cache->shouldNotReceive('get');
        $this->manager->shouldNotReceive('trackEvent');

        $this->service->hitMilestone('acquisition', 'nonexistent_milestone', 'client-id');
    }

    public function testGetProgressReturnsEmptyForUnknownJourney(): void
    {
        $progress = $this->service->getProgress('unknown', 'client-1');

        $this->assertSame('unknown', $progress['journey']);
        $this->assertFalse($progress['completed']);
        $this->assertSame(0.0, $progress['progress_percent']);
        $this->assertSame([], $progress['completed_milestones']);
    }

    public function testGetProgressReturnsCorrectProgress(): void
    {
        $clientId = 'client-progress-test';
        $cacheKey = 'zb_journey_client-progress-test_trial';

        $existingProgress = [
            'milestones' => [
                'trial_start' => ['hit_at' => '2026-01-01T00:00:00+00:00', 'params' => []],
                'trial_active' => ['hit_at' => '2026-01-02T00:00:00+00:00', 'params' => []],
            ],
            'started_at' => '2026-01-01T00:00:00+00:00',
        ];

        $this->cache->shouldReceive('get')
            ->with($cacheKey, null)
            ->once()
            ->andReturn($existingProgress);

        $progress = $this->service->getProgress('trial', $clientId);

        $this->assertSame('trial', $progress['journey']);
        $this->assertSame('Trial to Conversion', $progress['label']);
        $this->assertFalse($progress['completed']);
        $this->assertSame(2, $progress['completed_milestones'][0] ?? 0);
        $this->assertSame(4, $progress['total_milestones']);
        $this->assertSame(50.0, $progress['progress_percent']);
    }

    public function testResetProgressClearsCache(): void
    {
        $cacheKey = 'zb_journey_client-1_acquisition';

        $this->cache->shouldReceive('forget')
            ->with($cacheKey)
            ->once();

        $this->service->resetProgress('acquisition', 'client-1');
    }

    public function testRegisterJourneyOverridesDefault(): void
    {
        $this->service->registerJourney(
            'custom',
            'Custom Journey',
            ['step1', 'step2'],
            'journey_custom_completed',
        );

        $journeys = $this->service->getJourneys();

        $this->assertArrayHasKey('custom', $journeys);
        $this->assertSame('Custom Journey', $journeys['custom']['label']);
        $this->assertSame(['step1', 'step2'], $journeys['custom']['milestones']);
        $this->assertSame('journey_custom_completed', $journeys['custom']['completed_event']);
    }

    public function testDisabledServiceDoesNothing(): void
    {
        $this->config->shouldReceive('get')
            ->with('zeroboiler.analytics.journeys', [])
            ->andReturn(['enabled' => false, 'cache_ttl' => 2592000, 'definitions' => []]);

        $disabledService = new SaaSJourneyService(
            $this->manager,
            $this->cache,
            $this->config,
        );

        $this->cache->shouldNotReceive('get');
        $this->manager->shouldNotReceive('trackEvent');

        $disabledService->hitMilestone('acquisition', 'landing_page', 'client-1');
    }

    public function testGetAllProgressReturnsAllJourneys(): void
    {
        $clientId = 'all-progress-client';

        // All built-in journeys should be present
        $allProgress = $this->service->getAllProgress($clientId);

        $this->assertArrayHasKey('acquisition', $allProgress);
        $this->assertArrayHasKey('trial', $allProgress);
        $this->assertArrayHasKey('expansion', $allProgress);
        $this->assertArrayHasKey('activation', $allProgress);
    }
}
