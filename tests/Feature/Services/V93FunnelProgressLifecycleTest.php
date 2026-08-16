<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Feature\Services;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Services\FunnelProgressTracker;

/**
 * @covers \ZeroBoiler\Analytics\Services\FunnelProgressTracker
 * @covers \ZeroBoiler\Analytics\Events\EventCatalog
 */
class V93FunnelProgressLifecycleTest extends TestCase
{
    private AnalyticsManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->app->make('zeroboiler.analytics');
        Cache::clear();
    }

    // ── EventCatalog funnelTemplates ─────────────────────────────

    public function test_funnel_templates_returns_six_prebuilt_funnels(): void
    {
        $templates = EventCatalog::funnelTemplates();

        $this->assertArrayHasKey('signup', $templates);
        $this->assertArrayHasKey('trial', $templates);
        $this->assertArrayHasKey('checkout', $templates);
        $this->assertArrayHasKey('onboarding', $templates);
        $this->assertArrayHasKey('activation', $templates);
        $this->assertArrayHasKey('billing', $templates);
        $this->assertCount(6, $templates);
    }

    public function test_funnel_template_has_required_structure(): void
    {
        $template = EventCatalog::funnelTemplate('signup');

        $this->assertNotNull($template);
        $this->assertArrayHasKey('name', $template);
        $this->assertArrayHasKey('description', $template);
        $this->assertArrayHasKey('steps', $template);
        $this->assertArrayHasKey('total_steps', $template);
        $this->assertSame('signup', $template['name']);
        $this->assertSame(5, $template['total_steps']);
        $this->assertCount(5, $template['steps']);
    }

    public function test_funnel_template_returns_null_for_unknown(): void
    {
        $this->assertNull(EventCatalog::funnelTemplate('nonexistent'));
    }

    public function test_funnel_template_names_returns_all_names(): void
    {
        $names = EventCatalog::funnelTemplateNames();

        $this->assertContains('signup', $names);
        $this->assertContains('checkout', $names);
        $this->assertContains('onboarding', $names);
        $this->assertCount(6, $names);
    }

    public function test_funnel_template_events_returns_event_names(): void
    {
        $events = EventCatalog::funnelTemplateEvents('checkout');

        $this->assertSame([
            'view_item',
            'add_to_cart',
            'begin_checkout',
            'add_payment_info',
            'purchase',
        ], $events);
    }

    public function test_funnel_template_events_returns_empty_for_unknown(): void
    {
        $this->assertSame([], EventCatalog::funnelTemplateEvents('nonexistent'));
    }

    public function test_checkout_funnel_has_correct_step_labels(): void
    {
        $template = EventCatalog::funnelTemplate('checkout');

        $this->assertSame('Product Viewed', $template['steps'][0]['label']);
        $this->assertSame('Added to Cart', $template['steps'][1]['label']);
        $this->assertSame('Checkout Started', $template['steps'][2]['label']);
        $this->assertSame('Payment Info Entered', $template['steps'][3]['label']);
        $this->assertSame('Purchase Completed', $template['steps'][4]['label']);
    }

    // ── FunnelProgressTracker ────────────────────────────────────

    public function test_funnel_tracker_tracks_first_step(): void
    {
        $tracker = $this->app->make(FunnelProgressTracker::class);

        $result = $tracker->track(
            funnelName: 'signup',
            stepName: 'form_start',
            identity: 'user-123',
            stepNumber: 1,
            totalSteps: 5,
        );

        $this->assertSame('signup', $result['funnel_name']);
        $this->assertSame('form_start', $result['step_name']);
        $this->assertSame(1, $result['step_number']);
        $this->assertSame(5, $result['total_steps']);
        $this->assertSame(20.0, $result['completion_pct']);
        $this->assertFalse($result['is_complete']);
        $this->assertFalse($result['is_advancement']);
        $this->assertFalse($result['is_regression']);
        $this->assertNull($result['previous_step']);
    }

    public function test_funnel_tracker_detects_advancement(): void
    {
        $tracker = $this->app->make(FunnelProgressTracker::class);

        // Step 1
        $tracker->track('signup', 'form_start', 'user-456', 1, 5);

        // Step 2 (advancement)
        $result = $tracker->track('signup', 'form_submit', 'user-456', 2, 5);

        $this->assertTrue($result['is_advancement']);
        $this->assertFalse($result['is_regression']);
        $this->assertSame('form_start', $result['previous_step']);
        $this->assertSame(1, $result['previous_step_number']);
        $this->assertSame(40.0, $result['completion_pct']);
    }

    public function test_funnel_tracker_detects_regression(): void
    {
        $tracker = $this->app->make(FunnelProgressTracker::class);

        $tracker->track('signup', 'form_submit', 'user-789', 2, 5);
        $result = $tracker->track('signup', 'form_start', 'user-789', 1, 5);

        $this->assertTrue($result['is_regression']);
        $this->assertFalse($result['is_advancement']);
    }

    public function test_funnel_tracker_detects_completion(): void
    {
        $tracker = $this->app->make(FunnelProgressTracker::class);

        // Track to completion
        $tracker->track('checkout', 'view_item', 'user-c1', 1, 5);
        $tracker->track('checkout', 'add_to_cart', 'user-c1', 2, 5);
        $tracker->track('checkout', 'begin_checkout', 'user-c1', 3, 5);
        $tracker->track('checkout', 'add_payment_info', 'user-c1', 4, 5);
        $result = $tracker->track('checkout', 'purchase', 'user-c1', 5, 5);

        $this->assertTrue($result['is_complete']);
        $this->assertSame(100.0, $result['completion_pct']);
        $this->assertTrue($tracker->isCompleted('checkout', 'user-c1'));
    }

    public function test_funnel_tracker_get_progress(): void
    {
        $tracker = $this->app->make(FunnelProgressTracker::class);

        $this->assertNull($tracker->getProgress('signup', 'new-user'));

        $tracker->track('signup', 'form_start', 'new-user', 1, 5);
        $progress = $tracker->getProgress('signup', 'new-user');

        $this->assertNotNull($progress);
        $this->assertSame('form_start', $progress['step_name']);
        $this->assertSame(1, $progress['step_number']);
    }

    public function test_funnel_tracker_reset(): void
    {
        $tracker = $this->app->make(FunnelProgressTracker::class);

        $tracker->track('signup', 'form_start', 'user-reset', 1, 5);
        $this->assertNotNull($tracker->getProgress('signup', 'user-reset'));

        $tracker->reset('signup', 'user-reset');
        $this->assertNull($tracker->getProgress('signup', 'user-reset'));
    }

    public function test_funnel_tracker_tracks_elapsed_seconds(): void
    {
        $tracker = $this->app->make(FunnelProgressTracker::class);

        $tracker->track('signup', 'form_start', 'user-timing', 1, 5);
        $result = $tracker->track('signup', 'form_submit', 'user-timing', 2, 5);

        $this->assertNotNull($result['elapsed_seconds']);
        $this->assertGreaterThanOrEqual(0.0, $result['elapsed_seconds']);
    }

    public function test_funnel_tracker_preserves_first_seen(): void
    {
        $tracker = $this->app->make(FunnelProgressTracker::class);

        $first = $tracker->track('signup', 'form_start', 'user-first', 1, 5);
        $second = $tracker->track('signup', 'form_submit', 'user-first', 2, 5);

        $this->assertSame($first['first_seen'], $second['first_seen']);
    }

    public function test_funnel_tracker_different_funnels_independent(): void
    {
        $tracker = $this->app->make(FunnelProgressTracker::class);

        $tracker->track('signup', 'form_start', 'user-multi', 1, 5);
        $tracker->track('checkout', 'view_item', 'user-multi', 3, 5);

        $signupProgress = $tracker->getProgress('signup', 'user-multi');
        $checkoutProgress = $tracker->getProgress('checkout', 'user-multi');

        $this->assertSame(1, $signupProgress['step_number']);
        $this->assertSame(3, $checkoutProgress['step_number']);
    }

    // ── AnalyticsManager integration ─────────────────────────────

    public function test_analytics_manager_version_is_293(): void
    {
        $this->assertSame('76.0.0', $this->manager->version());
    }

    public function test_analytics_manager_track_funnel_progress(): void
    {
        $result = $this->manager->trackFunnelProgress(
            funnelName: 'signup',
            stepName: 'form_start',
            identity: 'test-user',
            stepNumber: 1,
            totalSteps: 5,
        );

        $this->assertSame('signup', $result['funnel_name']);
        $this->assertSame(20.0, $result['completion_pct']);
    }

    public function test_analytics_manager_funnel_templates(): void
    {
        $templates = $this->manager->funnelTemplates();

        $this->assertCount(6, $templates);
        $this->assertArrayHasKey('signup', $templates);
        $this->assertArrayHasKey('activation', $templates);
    }

    public function test_analytics_manager_funnel_template_single(): void
    {
        $template = $this->manager->funnelTemplate('trial');

        $this->assertNotNull($template);
        $this->assertSame(4, $template['total_steps']);
        $this->assertNull($this->manager->funnelTemplate('nonexistent'));
    }

    public function test_activation_funnel_has_six_steps(): void
    {
        $template = EventCatalog::funnelTemplate('activation');

        $this->assertSame(6, $template['total_steps']);
        $this->assertCount(6, $template['steps']);
        $this->assertSame('Signed Up', $template['steps'][0]['label']);
        $this->assertSame('Team Expanded', $template['steps'][5]['label']);
    }

    public function test_billing_funnel_tracks_payment_flow(): void
    {
        $template = EventCatalog::funnelTemplate('billing');

        $this->assertSame(5, $template['total_steps']);
        $events = EventCatalog::funnelTemplateEvents('billing');

        $this->assertSame([
            'plan_upgrade',
            'begin_checkout',
            'add_payment_info',
            'subscribe',
            'payment_succeeded',
        ], $events);
    }
}
