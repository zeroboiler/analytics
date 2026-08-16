<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\SaaSLifecycleFlowService;

test('SaaSLifecycleFlowService returns correct stages', function (): void {
    $stages = SaaSLifecycleFlowService::stages();

    expect($stages)->toBe([
        'anonymous',
        'signed_up',
        'trialing',
        'subscribed',
        'activated',
        'expanding',
        'retained',
        'champion',
    ]);
});

test('stageIndex returns correct index for each stage', function (): void {
    expect(SaaSLifecycleFlowService::stageIndex('anonymous'))->toBe(0)
        ->and(SaaSLifecycleFlowService::stageIndex('signed_up'))->toBe(1)
        ->and(SaaSLifecycleFlowService::stageIndex('trialing'))->toBe(2)
        ->and(SaaSLifecycleFlowService::stageIndex('subscribed'))->toBe(3)
        ->and(SaaSLifecycleFlowService::stageIndex('activated'))->toBe(4)
        ->and(SaaSLifecycleFlowService::stageIndex('expanding'))->toBe(5)
        ->and(SaaSLifecycleFlowService::stageIndex('retained'))->toBe(6)
        ->and(SaaSLifecycleFlowService::stageIndex('champion'))->toBe(7)
        ->and(SaaSLifecycleFlowService::stageIndex('unknown'))->toBe(0);
});

test('progressForStage calculates correct percentages', function (): void {
    expect(SaaSLifecycleFlowService::progressForStage('anonymous'))->toBe(0.0)
        ->and(SaaSLifecycleFlowService::progressForStage('signed_up'))->toBe(0.14)
        ->and(SaaSLifecycleFlowService::progressForStage('trialing'))->toBe(0.29)
        ->and(SaaSLifecycleFlowService::progressForStage('subscribed'))->toBe(0.43)
        ->and(SaaSLifecycleFlowService::progressForStage('activated'))->toBe(0.57)
        ->and(SaaSLifecycleFlowService::progressForStage('expanding'))->toBe(0.71)
        ->and(SaaSLifecycleFlowService::progressForStage('retained'))->toBe(0.86)
        ->and(SaaSLifecycleFlowService::progressForStage('champion'))->toBe(1.0);
});

test('nextStageAfter returns correct next stage', function (): void {
    expect(SaaSLifecycleFlowService::nextStageAfter('anonymous'))->toBe('signed_up')
        ->and(SaaSLifecycleFlowService::nextStageAfter('signed_up'))->toBe('trialing')
        ->and(SaaSLifecycleFlowService::nextStageAfter('trialing'))->toBe('subscribed')
        ->and(SaaSLifecycleFlowService::nextStageAfter('subscribed'))->toBe('activated')
        ->and(SaaSLifecycleFlowService::nextStageAfter('activated'))->toBe('expanding')
        ->and(SaaSLifecycleFlowService::nextStageAfter('expanding'))->toBe('retained')
        ->and(SaaSLifecycleFlowService::nextStageAfter('retained'))->toBe('champion')
        ->and(SaaSLifecycleFlowService::nextStageAfter('champion'))->toBeNull();
});

test('isForwardProgression works correctly', function (): void {
    expect(SaaSLifecycleFlowService::isForwardProgression('anonymous', 'signed_up'))->toBeTrue()
        ->and(SaaSLifecycleFlowService::isForwardProgression('trialing', 'subscribed'))->toBeTrue()
        ->and(SaaSLifecycleFlowService::isForwardProgression('subscribed', 'subscribed'))->toBeFalse()
        ->and(SaaSLifecycleFlowService::isForwardProgression('subscribed', 'trialing'))->toBeFalse()
        ->and(SaaSLifecycleFlowService::isForwardProgression('champion', 'anonymous'))->toBeFalse();
});

test('resolveStageForEvent maps events to stages', function (): void {
    expect(SaaSLifecycleFlowService::resolveStageForEvent('sign_up'))->toBe('signed_up')
        ->and(SaaSLifecycleFlowService::resolveStageForEvent('start_trial'))->toBe('trialing')
        ->and(SaaSLifecycleFlowService::resolveStageForEvent('subscribe'))->toBe('subscribed')
        ->and(SaaSLifecycleFlowService::resolveStageForEvent('plan_upgrade'))->toBe('expanding')
        ->and(SaaSLifecycleFlowService::resolveStageForEvent('feature_used'))->toBeNull()
        ->and(SaaSLifecycleFlowService::resolveStageForEvent('subscription_renewal'))->toBe('retained')
        ->and(SaaSLifecycleFlowService::resolveStageForEvent('unknown_event'))->toBeNull();
});

test('trackSignUp returns signed_up stage', function (): void {
    $service = new SaaSLifecycleFlowService(null);
    $stage = $service->trackSignUp('user-123', ['method' => 'email']);

    expect($stage)->toBe('signed_up');
});

test('trackTrialStart returns trialing stage', function (): void {
    $service = new SaaSLifecycleFlowService(null);
    $stage = $service->trackTrialStart('user-123', ['plan' => 'pro', 'days' => 14]);

    expect($stage)->toBe('trialing');
});

test('trackSubscription returns subscribed stage', function (): void {
    $service = new SaaSLifecycleFlowService(null);
    $stage = $service->trackSubscription('user-123', ['plan' => 'pro', 'mrr' => 49]);

    expect($stage)->toBe('subscribed');
});

test('trackPlanUpgrade returns expanding stage', function (): void {
    $service = new SaaSLifecycleFlowService(null);
    $stage = $service->trackPlanUpgrade('user-123', ['previous_plan' => 'starter', 'new_plan' => 'pro']);

    expect($stage)->toBe('expanding');
});

test('trackActivation returns activated stage', function (): void {
    $service = new SaaSLifecycleFlowService(null);
    $stage = $service->trackActivation('user-123', ['milestone' => 'first_project_created']);

    expect($stage)->toBe('activated');
});

test('trackRenewal returns retained stage', function (): void {
    $service = new SaaSLifecycleFlowService(null);
    $stage = $service->trackRenewal('user-123', ['renewal_count' => 1, 'tenure_months' => 3]);

    expect($stage)->toBe('retained');
});

test('trackCancellation returns void', function (): void {
    $service = new SaaSLifecycleFlowService(null);
    $service->trackCancellation('user-123', ['reason' => 'too_expensive']);

    // No return value — no exception means success
    expect(true)->toBeTrue();
});

test('dispatches events through manager when provided', function (): void {
    $dispatched = [];
    $manager = new class($dispatched) extends \ZeroBoiler\Analytics\AnalyticsManager
    {
        /**
         * @param  array<string, mixed>  $captured
         */
        public function __construct(
            private array &$captured,
        ) {
            parent::__construct(
                app()->make(\Illuminate\Contracts\Config\Repository::class),
                app()->make(\Illuminate\Contracts\Events\Dispatcher::class),
            );
        }

        public function trackEvent(AnalyticsEvent $event): void
        {
            $this->captured[] = $event;
        }
    };

    $capturedRef = &$dispatched;
    $service = new SaaSLifecycleFlowService($manager);
    $service->trackSignUp('user-456', ['method' => 'google']);

    expect($dispatched)->toHaveCount(1);
    expect($dispatched[0]->name)->toBe('sign_up');
    expect($dispatched[0]->userId)->toBe('user-456');
    expect($dispatched[0]->params['method'])->toBe('google');
    expect($dispatched[0]->category)->toBe('saas');
});

test('funnelSummary returns complete summary', function (): void {
    $summary = SaaSLifecycleFlowService::funnelSummary('subscribed');

    expect($summary)->toBe([
        'stage' => 'subscribed',
        'progress' => 0.43,
        'next_stage' => 'activated',
        'stages' => SaaSLifecycleFlowService::STAGES,
    ]);
});

test('funnelBreakdown returns all stages with trigger events', function (): void {
    $breakdown = SaaSLifecycleFlowService::funnelBreakdown();

    expect($breakdown)->toHaveCount(8);

    // Check anonymous stage
    expect($breakdown[0]['stage'])->toBe('anonymous');
    expect($breakdown[0]['index'])->toBe(0);
    expect($breakdown[0]['progress'])->toBe(0.0);

    // Check signed_up stage has sign_up trigger
    expect($breakdown[1]['stage'])->toBe('signed_up');
    expect($breakdown[1]['trigger_events'])->toContain('sign_up');

    // Check trialing stage has start_trial trigger
    expect($breakdown[2]['stage'])->toBe('trialing');
    expect($breakdown[2]['trigger_events'])->toContain('start_trial');

    // Check subscribed stage has subscribe trigger
    expect($breakdown[3]['stage'])->toBe('subscribed');
    expect($breakdown[3]['trigger_events'])->toContain('subscribe');

    // Check champion stage (last)
    expect($breakdown[7]['stage'])->toBe('champion');
    expect($breakdown[7]['progress'])->toBe(1.0);
    expect($breakdown[7]['trigger_events'])->toBe([]);
});

test('trackActivation merges milestone into feature_name', function (): void {
    $dispatched = [];
    $manager = new class($dispatched) extends \ZeroBoiler\Analytics\AnalyticsManager
    {
        /**
         * @param  array<string, mixed>  $captured
         */
        public function __construct(
            private array &$captured,
        ) {
            parent::__construct(
                app()->make(\Illuminate\Contracts\Config\Repository::class),
                app()->make(\Illuminate\Contracts\Events\Dispatcher::class),
            );
        }

        public function trackEvent(AnalyticsEvent $event): void
        {
            $this->captured[] = $event;
        }
    };

    $capturedRef = &$dispatched;
    $service = new SaaSLifecycleFlowService($manager);
    $service->trackActivation('user-789', ['milestone' => 'first_api_call', 'time_to_activate' => 3600]);

    expect($dispatched)->toHaveCount(1);
    expect($dispatched[0]->name)->toBe('feature_used');
    expect($dispatched[0]->params['feature_name'])->toBe('first_api_call');
    expect($dispatched[0]->params['time_to_activate'])->toBe(3600);
});
