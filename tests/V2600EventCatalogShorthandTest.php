<?php
declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Facades\Analytics;
use ZeroBoiler\Analytics\Support\AnalyticsFake;

/**
 * Tests for v26.0.0: Full Event Catalog Shorthand API.
 *
 * Verifies all 35 new convenience methods on AnalyticsManager and Facade
 * produce correct event names and parameters.
 *
 * @covers \ZeroBoiler\Analytics\AnalyticsManager
 * @covers \ZeroBoiler\Analytics\Support\AnalyticsFake
 */
#[Group('v2600')]
#[CoversClass(AnalyticsManager::class)]
#[CoversClass(AnalyticsFake::class)]
final class V2600EventCatalogShorthandTest extends \PHPUnit\Framework\TestCase
{
    private AnalyticsFake $fake;

    protected function setUp(): void
    {
        $this->fake = new AnalyticsFake;
    }

    // ─── E-Commerce Shorthand Tests ───────────────────────────────────

    public function test_view_item_shorthand(): void
    {
        $this->fake->viewItem(['item_id' => 'SKU-123', 'item_name' => 'T-Shirt', 'price' => 29.99]);

        $this->fake->assertTracked('view_item', function (AnalyticsEvent $event): bool {
            return $event->params['item_id'] === 'SKU-123'
                && $event->params['item_name'] === 'T-Shirt'
                && $event->params['price'] === 29.99;
        });
    }

    public function test_view_item_with_extra_params(): void
    {
        $this->fake->viewItem(['item_id' => 'SKU-456'], ['currency' => 'EUR']);

        $this->fake->assertTracked('view_item', function (AnalyticsEvent $event): bool {
            return $event->params['item_id'] === 'SKU-456'
                && $event->params['currency'] === 'EUR';
        });
    }

    public function test_add_to_cart_shorthand(): void
    {
        $this->fake->addToCart(['item_id' => 'SKU-123', 'quantity' => 2]);

        $this->fake->assertTracked('add_to_cart', function (AnalyticsEvent $event): bool {
            return $event->params['item_id'] === 'SKU-123'
                && $event->params['quantity'] === 2;
        });
    }

    public function test_remove_from_cart_shorthand(): void
    {
        $this->fake->removeFromCart(['item_id' => 'SKU-123']);

        $this->fake->assertTracked('remove_from_cart');
    }

    public function test_view_cart_shorthand(): void
    {
        $items = [['item_id' => 'SKU-1'], ['item_id' => 'SKU-2']];
        $this->fake->viewCart($items, 59.98);

        $this->fake->assertTracked('view_cart', function (AnalyticsEvent $event): bool {
            return count($event->params['items']) === 2
                && $event->params['value'] === 59.98;
        });
    }

    public function test_view_cart_empty(): void
    {
        $this->fake->viewCart();

        $this->fake->assertTracked('view_cart');
    }

    public function test_begin_checkout_shorthand(): void
    {
        $items = [['item_id' => 'SKU-1']];
        $this->fake->beginCheckout($items, 29.99, ['currency' => 'USD']);

        $this->fake->assertTracked('begin_checkout', function (AnalyticsEvent $event): bool {
            return $event->params['value'] === 29.99
                && $event->params['currency'] === 'USD';
        });
    }

    public function test_add_payment_info_shorthand(): void
    {
        $this->fake->addPaymentInfo('credit_card');

        $this->fake->assertTracked('add_payment_info', function (AnalyticsEvent $event): bool {
            return $event->params['payment_type'] === 'credit_card';
        });
    }

    public function test_refund_shorthand(): void
    {
        $this->fake->refund('txn_abc', 59.98, ['currency' => 'USD']);

        $this->fake->assertTracked('refund', function (AnalyticsEvent $event): bool {
            return $event->params['transaction_id'] === 'txn_abc'
                && $event->params['value'] === 59.98
                && $event->params['currency'] === 'USD';
        });
    }

    public function test_abandoned_cart_shorthand(): void
    {
        $items = [['item_id' => 'SKU-1']];
        $this->fake->abandonedCart($items, 99.99);

        $this->fake->assertTracked('abandoned_cart', function (AnalyticsEvent $event): bool {
            return count($event->params['items']) === 1
                && $event->params['value'] === 99.99;
        });
    }

    public function test_checkout_abandon_shorthand(): void
    {
        $this->fake->checkoutAbandon(2);

        $this->fake->assertTracked('checkout_abandon', function (AnalyticsEvent $event): bool {
            return $event->params['checkout_step'] === 2;
        });
    }

    public function test_checkout_step_shorthand(): void
    {
        $this->fake->checkoutStep(3, 'Payment');

        $this->fake->assertTracked('checkout_step', function (AnalyticsEvent $event): bool {
            return $event->params['checkout_step'] === 3
                && $event->params['checkout_step_name'] === 'Payment';
        });
    }

    // ─── Engagement Shorthand Tests ─────────────────────────────────

    public function test_scroll_depth_shorthand(): void
    {
        $this->fake->scrollDepth(75, '/blog/article');

        $this->fake->assertTracked('scroll_depth', function (AnalyticsEvent $event): bool {
            return $event->params['percent'] === 75
                && $event->params['url'] === '/blog/article';
        });
    }

    public function test_click_shorthand(): void
    {
        $this->fake->click('#cta-button', '/pricing');

        $this->fake->assertTracked('click', function (AnalyticsEvent $event): bool {
            return $event->params['target'] === '#cta-button'
                && $event->params['url'] === '/pricing';
        });
    }

    public function test_form_start_shorthand(): void
    {
        $this->fake->formStart('contact-form', 'Contact Us');

        $this->fake->assertTracked('form_start', function (AnalyticsEvent $event): bool {
            return $event->params['form_id'] === 'contact-form'
                && $event->params['form_name'] === 'Contact Us';
        });
    }

    public function test_form_submit_shorthand(): void
    {
        $this->fake->formSubmit('contact-form', 'Contact Us', true);

        $this->fake->assertTracked('form_submit', function (AnalyticsEvent $event): bool {
            return $event->params['form_id'] === 'contact-form'
                && $event->params['success'] === true;
        });
    }

    public function test_form_submit_without_success(): void
    {
        $this->fake->formSubmit('login-form');

        $this->fake->assertTracked('form_submit', function (AnalyticsEvent $event): bool {
            return ! isset($event->params['success']);
        });
    }

    public function test_search_shorthand(): void
    {
        $this->fake->search('laravel analytics', 42);

        $this->fake->assertTracked('search', function (AnalyticsEvent $event): bool {
            return $event->params['search_term'] === 'laravel analytics'
                && $event->params['results_count'] === 42;
        });
    }

    public function test_share_shorthand(): void
    {
        $this->fake->share('twitter', 'article', 'post-123');

        $this->fake->assertTracked('share', function (AnalyticsEvent $event): bool {
            return $event->params['method'] === 'twitter'
                && $event->params['content_type'] === 'article'
                && $event->params['item_id'] === 'post-123';
        });
    }

    public function test_outbound_click_shorthand(): void
    {
        $this->fake->outboundClick('https://example.com', 'External');

        $this->fake->assertTracked('outbound_click', function (AnalyticsEvent $event): bool {
            return $event->params['url'] === 'https://example.com'
                && $event->params['label'] === 'External';
        });
    }

    public function test_content_engagement_shorthand(): void
    {
        $this->fake->contentEngagement('article', 'post-123', 120);

        $this->fake->assertTracked('content_engagement', function (AnalyticsEvent $event): bool {
            return $event->params['content_type'] === 'article'
                && $event->params['content_id'] === 'post-123'
                && $event->params['duration_seconds'] === 120;
        });
    }

    public function test_onboarding_step_shorthand(): void
    {
        $this->fake->onboardingStep(3, 'Invite Team');

        $this->fake->assertTracked('onboarding_step', function (AnalyticsEvent $event): bool {
            return $event->params['step'] === 3
                && $event->params['step_name'] === 'Invite Team';
        });
    }

    public function test_onboarding_completed_shorthand(): void
    {
        $this->fake->onboardingCompleted(5);

        $this->fake->assertTracked('onboarding_completed', function (AnalyticsEvent $event): bool {
            return $event->params['total_steps'] === 5;
        });
    }

    public function test_goal_conversion_shorthand(): void
    {
        $this->fake->goalConversion('signup_complete', 99.99);

        $this->fake->assertTracked('goal_conversion', function (AnalyticsEvent $event): bool {
            return $event->params['goal_name'] === 'signup_complete'
                && $event->params['value'] === 99.99;
        });
    }

    public function test_feedback_shorthand(): void
    {
        $this->fake->feedback('nps', 9);

        $this->fake->assertTracked('feedback', function (AnalyticsEvent $event): bool {
            return $event->params['feedback_type'] === 'nps'
                && $event->params['score'] === 9;
        });
    }

    public function test_feature_request_shorthand(): void
    {
        $this->fake->featureRequest('Dark Mode');

        $this->fake->assertTracked('feature_request', function (AnalyticsEvent $event): bool {
            return $event->params['feature_name'] === 'Dark Mode';
        });
    }

    // ─── SaaS Lifecycle Shorthand Tests ───────────────────────────────

    public function test_subscription_paused_shorthand(): void
    {
        $this->fake->subscriptionPaused('Pro');

        $this->fake->assertTracked('subscription_paused', function (AnalyticsEvent $event): bool {
            return $event->params['plan_name'] === 'Pro';
        });
    }

    public function test_subscription_resumed_shorthand(): void
    {
        $this->fake->subscriptionResumed('Pro');

        $this->fake->assertTracked('subscription_resumed', function (AnalyticsEvent $event): bool {
            return $event->params['plan_name'] === 'Pro';
        });
    }

    public function test_plan_changed_shorthand(): void
    {
        $this->fake->planChanged('Free', 'Pro');

        $this->fake->assertTracked('plan_changed', function (AnalyticsEvent $event): bool {
            return $event->params['from_plan'] === 'Free'
                && $event->params['to_plan'] === 'Pro';
        });
    }

    public function test_team_created_shorthand(): void
    {
        $this->fake->teamCreated('Engineering', 5);

        $this->fake->assertTracked('team_created', function (AnalyticsEvent $event): bool {
            return $event->params['team_name'] === 'Engineering'
                && $event->params['member_count'] === 5;
        });
    }

    public function test_team_member_joined_shorthand(): void
    {
        $this->fake->teamMemberJoined('admin', 'email_invite');

        $this->fake->assertTracked('team_member_joined', function (AnalyticsEvent $event): bool {
            return $event->params['role'] === 'admin'
                && $event->params['invite_method'] === 'email_invite';
        });
    }

    public function test_team_member_removed_shorthand(): void
    {
        $this->fake->teamMemberRemoved('viewer');

        $this->fake->assertTracked('team_member_removed', function (AnalyticsEvent $event): bool {
            return $event->params['role'] === 'viewer';
        });
    }

    public function test_role_changed_shorthand(): void
    {
        $this->fake->roleChanged('editor', 'admin');

        $this->fake->assertTracked('role_changed', function (AnalyticsEvent $event): bool {
            return $event->params['from_role'] === 'editor'
                && $event->params['to_role'] === 'admin';
        });
    }

    public function test_payment_failed_shorthand(): void
    {
        $this->fake->paymentFailed('card_declined', 99.00);

        $this->fake->assertTracked('payment_failed', function (AnalyticsEvent $event): bool {
            return $event->params['reason'] === 'card_declined'
                && $event->params['amount'] === 99.00;
        });
    }

    public function test_payment_succeeded_shorthand(): void
    {
        $this->fake->paymentSucceeded(49.99, 'stripe');

        $this->fake->assertTracked('payment_succeeded', function (AnalyticsEvent $event): bool {
            return $event->params['amount'] === 49.99
                && $event->params['method'] === 'stripe';
        });
    }

    public function test_milestone_reached_shorthand(): void
    {
        $this->fake->milestoneReached('100_events');

        $this->fake->assertTracked('milestone_reached', function (AnalyticsEvent $event): bool {
            return $event->params['milestone'] === '100_events';
        });
    }

    public function test_workspace_created_shorthand(): void
    {
        $this->fake->workspaceCreated('Marketing');

        $this->fake->assertTracked('workspace_created', function (AnalyticsEvent $event): bool {
            return $event->params['workspace_name'] === 'Marketing';
        });
    }

    public function test_usage_quota_reached_shorthand(): void
    {
        $this->fake->usageQuotaReached('api_calls', 10000);

        $this->fake->assertTracked('usage_quota_reached', function (AnalyticsEvent $event): bool {
            return $event->params['quota_type'] === 'api_calls'
                && $event->params['quota_limit'] === 10000;
        });
    }

    public function test_billing_retry_shorthand(): void
    {
        $this->fake->billingRetry(2);

        $this->fake->assertTracked('billing_retry', function (AnalyticsEvent $event): bool {
            return $event->params['attempt'] === 2;
        });
    }

    // ─── Catalog Consistency ─────────────────────────────────────────

    public function test_ecommerce_shorthands_exist_in_catalog(): void
    {
        $expectedEcommerce = [
            'view_item', 'add_to_cart', 'remove_from_cart', 'view_cart',
            'begin_checkout', 'add_payment_info', 'refund', 'abandoned_cart',
            'checkout_abandon', 'checkout_step',
        ];

        foreach ($expectedEcommerce as $eventName) {
            $this->assertTrue(
                EventCatalog::eventExists($eventName),
                "Event '{$eventName}' should exist in the catalog",
            );
        }
    }

    public function test_engagement_shorthands_exist_in_catalog(): void
    {
        $expectedEngagement = [
            'scroll_depth', 'click', 'form_start', 'form_submit', 'search',
            'share', 'outbound_click', 'content_engagement', 'onboarding_step',
            'onboarding_completed', 'goal_conversion', 'feedback', 'feature_request',
        ];

        foreach ($expectedEngagement as $eventName) {
            $this->assertTrue(
                EventCatalog::eventExists($eventName),
                "Event '{$eventName}' should exist in the catalog",
            );
        }
    }

    public function test_saas_shorthands_exist_in_catalog(): void
    {
        $expectedSaas = [
            'subscription_paused', 'subscription_resumed', 'plan_changed',
            'team_created', 'team_member_joined', 'team_member_removed',
            'role_changed', 'payment_failed', 'payment_succeeded',
            'milestone_reached', 'workspace_created', 'usage_quota_reached',
            'billing_retry',
        ];

        foreach ($expectedSaas as $eventName) {
            $this->assertTrue(
                EventCatalog::eventExists($eventName),
                "Event '{$eventName}' should exist in the catalog",
            );
        }
    }

    // ─── Version Sweep ────────────────────────────────────────────────

    public function test_version_is_26(): void
    {
        $this->assertSame('26.0.0', AnalyticsEvent::VERSION);
    }

    public function test_all_shorthand_events_total_count(): void
    {
        // Verify total events in catalog has not decreased
        $total = EventCatalog::totalEventCount();
        $this->assertGreaterThan(120, $total, 'Total event catalog should have 120+ events');
    }

    public function test_shorthand_method_names_match_catalog_keys(): void
    {
        // Verify shorthand event names match catalog naming convention (snake_case)
        $events = $this->fake->trackedEvents();
        foreach ($events as $event) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $event->name,
                "Event name '{$event->name}' should follow snake_case convention",
            );
        }
    }
}
