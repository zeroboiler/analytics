<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use Illuminate\Config\Repository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\FunnelAnalyticsService;

describe('FunnelAnalyticsService', function () {
    beforeEach(function () {
        $this->manager = Mockery::mock(AnalyticsManager::class);
        $this->queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $config = new Repository([
            'zeroboiler' => [
                'analytics' => [
                    'queue' => ['enabled' => false],
                ],
            ],
        ]);
        $this->service = new FunnelAnalyticsService($this->manager, $this->queue, $config);
    });

    afterEach(function () {
        Mockery::close();
    });

    describe('startFunnel', function () {
        it('tracks funnel_started event', function () {
            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'funnel_started'
                    && $event->params['funnel_name'] === 'signup'
                    && ($event->params['source'] ?? null) === 'landing_page';
            });

            $this->service->startFunnel('signup', ['source' => 'landing_page']);
        });

        it('marks funnel as active', function () {
            $this->manager->shouldReceive('trackEvent');
            $this->service->startFunnel('checkout');

            expect($this->service->isActive('checkout'))->toBeTrue();
        });
    });

    describe('trackStep', function () {
        it('auto-starts funnel if not active', function () {
            $this->manager->shouldReceive('trackEvent')->twice();
            $this->service->trackStep('signup', 'form_start', 1);

            expect($this->service->isActive('signup'))->toBeTrue();
        });

        it('tracks funnel_step with correct metadata', function () {
            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'funnel_step'
                    && $event->params['funnel_name'] === 'checkout'
                    && $event->params['funnel_step_name'] === 'shipping_info'
                    && $event->params['funnel_step_number'] === 1
                    && isset($event->params['funnel_elapsed_ms']);
            });

            $this->service->startFunnel('checkout');
            $this->service->trackStep('checkout', 'shipping_info', 1);
        });

        it('updates current step', function () {
            $this->manager->shouldReceive('trackEvent');
            $this->service->startFunnel('checkout');
            $this->service->trackStep('checkout', 'shipping', 1);
            $this->service->trackStep('checkout', 'payment', 2);

            expect($this->service->getCurrentStep('checkout'))->toBe('payment');
        });
    });

    describe('complete', function () {
        it('tracks funnel_completed and removes from active', function () {
            $this->manager->shouldReceive('trackEvent')->twice(); // startFunnel + complete
            $this->service->startFunnel('signup');
            $this->service->trackStep('signup', 'form', 1);
            $this->service->trackStep('signup', 'confirm', 2);

            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'funnel_completed'
                    && $event->params['funnel_name'] === 'signup'
                    && $event->params['funnel_total_steps'] === 3
                    && isset($event->params['funnel_elapsed_ms']);
            });

            $this->service->complete('signup', 3, ['plan' => 'pro']);

            expect($this->service->isActive('signup'))->toBeFalse();
        });

        it('calculates skipped steps', function () {
            $this->manager->shouldReceive('trackEvent');
            $this->service->startFunnel('signup');
            $this->service->trackStep('signup', 'form', 1);

            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->params['funnel_skipped_steps'] === 1;
            });

            $this->service->complete('signup', 2);
        });
    });

    describe('abandon', function () {
        it('tracks funnel_abandoned and removes from active', function () {
            $this->manager->shouldReceive('trackEvent');
            $this->service->startFunnel('checkout');
            $this->service->trackStep('checkout', 'shipping', 1);

            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'funnel_abandoned'
                    && $event->params['funnel_abandoned_at'] === 'payment'
                    && $event->params['funnel_total_steps'] === 4
                    && ($event->params['funnel_completion_rate'] ?? 0) === 25.0;
            });

            $this->service->abandon('checkout', 'payment', 4, ['reason' => 'form_complexity']);

            expect($this->service->isActive('checkout'))->toBeFalse();
        });

        it('calculates completion rate correctly', function () {
            $this->manager->shouldReceive('trackEvent');
            $this->service->startFunnel('trial');
            $this->service->trackStep('trial', 'start', 1);
            $this->service->trackStep('trial', 'confirm_email', 2);

            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return ($event->params['funnel_completion_rate'] ?? 0) === 66.67;
            });

            $this->service->abandon('trial', 'card_entry', 3);
        });
    });

    describe('retry', function () {
        it('tracks funnel_retry event', function () {
            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'funnel_retry'
                    && $event->params['funnel_name'] === 'signup'
                    && $event->params['funnel_attempt_number'] === 2;
            });

            $this->service->retry('signup', 2);
        });
    });

    describe('state inspection', function () {
        it('returns null step for inactive funnel', function () {
            expect($this->service->getCurrentStep('nonexistent'))->toBeNull();
        });

        it('returns empty list when no funnels active', function () {
            expect($this->service->getActiveFunnels())->toBe([]);
        });

        it('returns all active funnel names', function () {
            $this->manager->shouldReceive('trackEvent');
            $this->service->startFunnel('signup');
            $this->service->startFunnel('checkout');

            $active = $this->service->getActiveFunnels();

            expect($active)->toContain('signup');
            expect($active)->toContain('checkout');
            expect($active)->toHaveCount(2);
        });
    });

    describe('async dispatch', function () {
        it('dispatches via queue when async is enabled', function () {
            $asyncConfig = new Repository([
                'zeroboiler' => [
                    'analytics' => [
                        'queue' => ['enabled' => true],
                    ],
                ],
            ]);
            $asyncService = new FunnelAnalyticsService($this->manager, $this->queue, $asyncConfig);

            $this->queue->shouldReceive('dispatch')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'funnel_started';
            });
            $this->manager->shouldNotReceive('trackEvent');

            $asyncService->startFunnel('async_funnel');
        });
    });

    describe('getManager', function () {
        it('returns the underlying analytics manager', function () {
            expect($this->service->getManager())->toBe($this->manager);
        });
    });
});
