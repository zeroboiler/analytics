<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\UtmAttribution;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;
use ZeroBoiler\Analytics\Services\RevenueAttributionService;

describe('RevenueAttributionService', function () {
    beforeEach(function () {
        $this->manager = Mockery::mock(AnalyticsManager::class);
        $this->queue = Mockery::mock(QueuedAnalyticsDispatcher::class);
        $this->service = new RevenueAttributionService(
            $this->manager,
            $this->queue,
            'USD',
            useAsync: false,
        );
    });

    afterEach(function () {
        Mockery::close();
    });

    describe('trackRevenue', function () {
        it('tracks revenue event with attribution', function () {
            $attribution = new UtmAttribution(
                source: 'google',
                medium: 'cpc',
                campaign: 'spring_sale',
            );

            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'revenue_tracked'
                    && $event->params['revenue_event_id'] === 'rev-001'
                    && $event->params['revenue_amount'] === 99.99
                    && $event->params['currency'] === 'USD'
                    && $event->params['utm_source'] === 'google'
                    && $event->userId === '42';
            });

            $this->service->trackRevenue('rev-001', 99.99, ['plan' => 'pro'], $attribution, '42');
        });

        it('tracks revenue without attribution', function () {
            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'revenue_tracked'
                    && ! isset($event->params['utm_source']);
            });

            $this->service->trackRevenue('rev-002', 49.99);
        });

        it('uses custom currency when provided in params', function () {
            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->params['currency'] === 'EUR';
            });

            $this->service->trackRevenue('rev-003', 79.99, ['currency' => 'EUR']);
        });

        it('uses default currency when not overridden', function () {
            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->params['currency'] === 'USD';
            });

            $this->service->trackRevenue('rev-004', 29.99);
        });
    });

    describe('trackMrrChange', function () {
        it('tracks MRR change with correct delta calculation', function () {
            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'mrr_change'
                    && $event->params['mrr_amount'] === 99.99
                    && $event->params['mrr_previous'] === 49.99
                    && $event->params['mrr_delta'] === 50.0
                    && $event->params['change_type'] === 'upgrade'
                    && $event->params['user_id'] === 'user-1';
            });

            $this->service->trackMrrChange('user-1', 99.99, 49.99, 'pro', 'upgrade');
        });

        it('calculates negative delta for downgrade', function () {
            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->params['mrr_delta'] === -20.0;
            });

            $this->service->trackMrrChange('user-2', 29.99, 49.99, 'starter', 'downgrade');
        });
    });

    describe('trackLtv', function () {
        it('tracks LTV with calculated daily average', function () {
            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'ltv_update'
                    && $event->params['ltv'] === 599.99
                    && $event->params['total_revenue'] === 299.99
                    && $event->params['days_active'] === 180
                    && $event->params['avg_daily_revenue'] === 1.67
                    && $event->params['user_id'] === 'user-3';
            });

            $this->service->trackLtv('user-3', 599.99, 299.99, 180);
        });

        it('handles zero days active gracefully', function () {
            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->params['avg_daily_revenue'] === 0;
            });

            $this->service->trackLtv('user-4', 0.0, 0.0, 0);
        });
    });

    describe('trackCohortRevenue', function () {
        it('tracks cohort revenue with ARPU calculation', function () {
            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'cohort_revenue'
                    && $event->params['cohort_name'] === '2026-01'
                    && $event->params['cohort_revenue'] === 15000.0
                    && $event->params['cohort_customers'] === 250
                    && $event->params['cohort_arpu'] === 60.0;
            });

            $this->service->trackCohortRevenue('2026-01', 15000.0, 250);
        });

        it('handles zero customers gracefully', function () {
            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->params['cohort_arpu'] === 0;
            });

            $this->service->trackCohortRevenue('2026-02', 0.0, 0);
        });
    });

    describe('trackRevenueBreakdown', function () {
        it('tracks revenue breakdown with average transaction value', function () {
            $this->manager->shouldReceive('trackEvent')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'revenue_breakdown'
                    && $event->params['revenue_source'] === 'stripe'
                    && $event->params['revenue_channel'] === 'organic'
                    && $event->params['revenue_amount'] === 5000.0
                    && $event->params['transaction_count'] === 50
                    && $event->params['avg_transaction_value'] === 100.0;
            });

            $this->service->trackRevenueBreakdown('stripe', 'organic', 5000.0, 50);
        });
    });

    describe('currency management', function () {
        it('returns default currency', function () {
            expect($this->service->getDefaultCurrency())->toBe('USD');
        });

        it('allows setting a new default currency', function () {
            $result = $this->service->setDefaultCurrency('EUR');

            expect($result)->toBe($this->service);
            expect($this->service->getDefaultCurrency())->toBe('EUR');
        });
    });

    describe('async dispatch', function () {
        it('dispatches via queue when async is enabled', function () {
            $asyncService = new RevenueAttributionService(
                $this->manager,
                $this->queue,
                'USD',
                useAsync: true,
            );

            $this->queue->shouldReceive('dispatch')->once()->withArgs(function (AnalyticsEvent $event): bool {
                return $event->name === 'revenue_tracked';
            });
            $this->manager->shouldNotReceive('trackEvent');

            $asyncService->trackRevenue('rev-async', 100.0);
        });
    });

    describe('getManager', function () {
        it('returns the underlying analytics manager', function () {
            expect($this->service->getManager())->toBe($this->manager);
        });
    });
});
