<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Support\EventBuilder;
use ZeroBoiler\Analytics\Support\SaaSEventHelpers;
use ZeroBoiler\Analytics\AnalyticsManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for v109.0.0 — Event Catalog Semantic Alias Resolution,
 * SaaS Event Helpers expansion, and EventBuilder SaaS shortcuts.
 *
 * @covers \ZeroBoiler\Analytics\Events\EventCatalog
 * @covers \ZeroBoiler\Analytics\Support\EventBuilder
 * @covers \ZeroBoiler\Analytics\Support\SaaSEventHelpers
 */
final class V109SemanticAliasAndSaaSHelpersTest extends TestCase
{
    // ─── EventCatalog::resolve() ───────────────────────────────────────

    public function test_resolve_exact_snake_case_match(): void
    {
        $this->assertSame('view_item', EventCatalog::resolve('view_item'));
        $this->assertSame('add_to_cart', EventCatalog::resolve('add_to_cart'));
        $this->assertSame('sign_up', EventCatalog::resolve('sign_up'));
        $this->assertSame('login', EventCatalog::resolve('login'));
    }

    public function test_resolve_pascal_case_to_snake_case(): void
    {
        $this->assertSame('view_item', EventCatalog::resolve('ViewItem'));
        $this->assertSame('add_to_cart', EventCatalog::resolve('AddToCart'));
        $this->assertSame('begin_checkout', EventCatalog::resolve('BeginCheckout'));
        $this->assertSame('sign_up', EventCatalog::resolve('SignUp'));
        $this->assertSame('scroll_depth', EventCatalog::resolve('ScrollDepth'));
        $this->assertSame('form_submit', EventCatalog::resolve('FormSubmit'));
        $this->assertSame('search', EventCatalog::resolve('Search'));
        $this->assertSame('share', EventCatalog::resolve('Share'));
        $this->assertSame('error', EventCatalog::resolve('Error'));
        $this->assertSame('page_view', EventCatalog::resolve('PageView'));
    }

    public function test_resolve_camel_case_to_snake_case(): void
    {
        $this->assertSame('view_item', EventCatalog::resolve('viewItem'));
        $this->assertSame('add_to_cart', EventCatalog::resolve('addToCart'));
        $this->assertSame('page_view', EventCatalog::resolve('pageView'));
        $this->assertSame('scroll_depth', EventCatalog::resolve('scrollDepth'));
    }

    public function test_resolve_kebab_case_to_snake_case(): void
    {
        $this->assertSame('view_item', EventCatalog::resolve('view-item'));
        $this->assertSame('add_to_cart', EventCatalog::resolve('add-to-cart'));
        $this->assertSame('sign_up', EventCatalog::resolve('sign-up'));
    }

    public function test_resolve_spaced_to_snake_case(): void
    {
        $this->assertSame('add_to_cart', EventCatalog::resolve('add to cart'));
        $this->assertSame('view_item', EventCatalog::resolve('view item'));
    }

    public function test_resolve_returns_null_for_unknown_event(): void
    {
        $this->assertNull(EventCatalog::resolve('nonexistent_event_xyz'));
        $this->assertNull(EventCatalog::resolve('SomeRandomEvent'));
    }

    public function test_resolve_and_get_returns_full_entry(): void
    {
        $entry = EventCatalog::resolveAndGet('ViewItem');

        $this->assertNotNull($entry);
        $this->assertSame('view_item', $entry['name']);
        $this->assertArrayHasKey('ga4', $entry);
        $this->assertArrayHasKey('category', $entry);
        $this->assertSame('ecommerce', $entry['category']);
    }

    public function test_resolve_and_get_returns_null_for_unknown(): void
    {
        $this->assertNull(EventCatalog::resolveAndGet('totally_unknown'));
    }

    public function test_resolve_saaS_events(): void
    {
        $this->assertSame('sign_up', EventCatalog::resolve('SignUp'));
        $this->assertSame('login', EventCatalog::resolve('Login'));
        $this->assertSame('plan_upgrade', EventCatalog::resolve('PlanUpgrade'));
        $this->assertSame('cancellation', EventCatalog::resolve('Cancellation'));
        $this->assertSame('trial_start', EventCatalog::resolve('TrialStart') ?? EventCatalog::resolve('StartTrial'));
    }

    public function test_resolve_engagement_events(): void
    {
        $this->assertSame('click', EventCatalog::resolve('Click'));
        $this->assertSame('scroll_depth', EventCatalog::resolve('ScrollDepth'));
        $this->assertSame('form_start', EventCatalog::resolve('FormStart'));
        $this->assertSame('form_submit', EventCatalog::resolve('FormSubmit'));
        $this->assertSame('error', EventCatalog::resolve('Error'));
    }

    // ─── SaaSEventHelpers expansion ────────────────────────────────────

    public function test_saas_helpers_has_all_new_methods(): void
    {
        $reflection = new \ReflectionClass(SaaSEventHelpers::class);
        $methods = array_map(
            fn (\ReflectionMethod $m): string => $m->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        // Original methods (v97)
        $this->assertContains('signUp', $methods);
        $this->assertContains('login', $methods);
        $this->assertContains('trialStart', $methods);
        $this->assertContains('subscription', $methods);
        $this->assertContains('planUpgrade', $methods);
        $this->assertContains('planDowngrade', $methods);
        $this->assertContains('cancellation', $methods);
        $this->assertContains('featureUsed', $methods);
        $this->assertContains('teamCreated', $methods);
        $this->assertContains('inviteSent', $methods);
        $this->assertContains('paymentFailed', $methods);

        // New v109 methods
        $this->assertContains('logout', $methods);
        $this->assertContains('trialConverted', $methods);
        $this->assertContains('trialExpired', $methods);
        $this->assertContains('subscriptionPaused', $methods);
        $this->assertContains('subscriptionResumed', $methods);
        $this->assertContains('invoiceGenerated', $methods);
        $this->assertContains('profileUpdated', $methods);
        $this->assertContains('passwordChanged', $methods);
        $this->assertContains('roleChanged', $methods);
        $this->assertContains('integrationConnected', $methods);
        $this->assertContains('integrationFailed', $methods);
        $this->assertContains('dataErasureCompleted', $methods);
        $this->assertContains('emailVerified', $methods);
        $this->assertContains('teamMemberJoined', $methods);
        $this->assertContains('teamMemberRemoved', $methods);
        $this->assertContains('subscriptionRenewal', $methods);
    }

    public function test_saas_helpers_new_methods_have_return_type_void(): void
    {
        $reflection = new \ReflectionClass(SaaSEventHelpers::class);

        $newMethods = [
            'logout', 'trialConverted', 'trialExpired', 'subscriptionPaused',
            'subscriptionResumed', 'invoiceGenerated', 'profileUpdated',
            'passwordChanged', 'roleChanged', 'integrationConnected',
            'integrationFailed', 'dataErasureCompleted', 'emailVerified',
            'teamMemberJoined', 'teamMemberRemoved', 'subscriptionRenewal',
        ];

        foreach ($newMethods as $method) {
            $m = $reflection->getMethod($method);
            $this->assertSame('void', (string) $m->getReturnType(), "SaaSEventHelpers::{$method}() must return void");
        }
    }

    public function test_saas_helpers_class_is_final(): void
    {
        $reflection = new \ReflectionClass(SaaSEventHelpers::class);
        $this->assertTrue($reflection->isFinal());
    }

    // ─── EventBuilder SaaS shortcuts ──────────────────────────────────

    public function test_event_builder_new_saas_shortcuts_exist(): void
    {
        $reflection = new \ReflectionClass(EventBuilder::class);

        $newShortcuts = [
            'planDowngrade', 'logout', 'subscriptionPaused', 'subscriptionResumed',
            'invoiceGenerated', 'teamCreated', 'inviteSent', 'paymentFailed',
            'subscriptionRenewal', 'trialExpired',
        ];

        foreach ($newShortcuts as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "EventBuilder::{$method}() must exist",
            );
        }

        // Verify return type is self
        foreach ($newShortcuts as $method) {
            $m = $reflection->getMethod($method);
            $this->assertSame(
                'self',
                (string) $m->getReturnType(),
                "EventBuilder::{$method}() must return self",
            );
        }
    }

    public function test_event_builder_plan_downgrade_builds_correctly(): void
    {
        $event = EventBuilder::planDowngrade('pro', 'free')->build();

        $this->assertSame('plan_downgrade', $event->name());
        $this->assertSame('pro', $event->params()['from_plan']);
        $this->assertSame('free', $event->params()['to_plan']);
    }

    public function test_event_builder_logout_builds_correctly(): void
    {
        $event = EventBuilder::logout('manual')->build();

        $this->assertSame('logout', $event->name());
        $this->assertSame('manual', $event->params()['method']);
    }

    public function test_event_builder_subscription_paused_builds_correctly(): void
    {
        $event = EventBuilder::subscriptionPaused('business', 'financial')->build();

        $this->assertSame('subscription_paused', $event->name());
        $this->assertSame('business', $event->params()['plan']);
        $this->assertSame('financial', $event->params()['reason']);
    }

    public function test_event_builder_invoice_generated_builds_correctly(): void
    {
        $event = EventBuilder::invoiceGenerated('INV-001', 49.99, 'EUR')->build();

        $this->assertSame('invoice_generated', $event->name());
        $this->assertSame('INV-001', $event->params()['invoice_id']);
        $this->assertSame(49.99, $event->params()['amount']);
        $this->assertSame('EUR', $event->params()['currency']);
    }

    public function test_event_builder_trial_expired_builds_correctly(): void
    {
        $event = EventBuilder::trialExpired('pro', 14)->build();

        $this->assertSame('trial_expired', $event->name());
        $this->assertSame('pro', $event->params()['plan']);
        $this->assertSame(14, $event->params()['trial_days']);
    }

    public function test_event_builder_subscription_renewal_builds_correctly(): void
    {
        $event = EventBuilder::subscriptionRenewal('pro', 49.99, 3)->build();

        $this->assertSame('subscription_renewal', $event->name());
        $this->assertSame('pro', $event->params()['plan']);
        $this->assertSame(49.99, $event->params()['value']);
        $this->assertSame(3, $event->params()['cycle_count']);
    }

    public function test_event_builder_team_created_builds_correctly(): void
    {
        $event = EventBuilder::teamCreated('Engineering', 5)->build();

        $this->assertSame('team_created', $event->name());
        $this->assertSame('Engineering', $event->params()['team_name']);
        $this->assertSame(5, $event->params()['member_count']);
    }

    public function test_event_builder_payment_failed_builds_correctly(): void
    {
        $event = EventBuilder::paymentFailed('card_declined', 99.99, 'USD')->build();

        $this->assertSame('payment_failed', $event->name());
        $this->assertSame('card_declined', $event->params()['reason']);
        $this->assertSame(99.99, $event->params()['amount']);
    }

    // ─── Cross-file integrity ─────────────────────────────────────────

    public function test_resolve_consistent_with_existing_search(): void
    {
        // If resolve finds it, search should also find it
        $resolved = EventCatalog::resolve('AddToCart');
        $this->assertNotNull($resolved);

        $searched = EventCatalog::search('add_to_cart');
        $this->assertNotEmpty($searched);
        $this->assertSame('add_to_cart', $searched[0]['name']);
    }

    public function test_event_catalog_resolve_get_category(): void
    {
        $entry = EventCatalog::resolveAndGet('PageView');
        $this->assertNotNull($entry);
        $this->assertSame('engagement', $entry['category']);
    }

    public function test_version_consistency(): void
    {
        $composerJson = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
        );

        $this->assertArrayHasKey('version', $composerJson);
        $this->assertSame('109.0.0', $composerJson['version']);
    }

    public function test_event_catalog_file_has_strict_types(): void
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . '/src/Events/EventCatalog.php',
        );
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function test_event_builder_file_has_strict_types(): void
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . '/src/Support/EventBuilder.php',
        );
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function test_saas_helpers_file_has_strict_types(): void
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . '/src/Support/SaaSEventHelpers.php',
        );
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function test_total_saas_helper_method_count(): void
    {
        $reflection = new \ReflectionClass(SaaSEventHelpers::class);
        $publicMethods = array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m): bool => ! $m->isStatic() && $m->getDeclaringClass()->getName() === SaaSEventHelpers::class,
        );

        // 26 tracked helper methods + 1 manager() accessor = 27 public non-static methods
        $this->assertCount(
            27,
            $publicMethods,
            'SaaSEventHelpers must have 27 public non-static methods (26 event helpers + 1 manager accessor)',
        );
    }

    public function test_total_event_builder_static_factory_count(): void
    {
        $reflection = new \ReflectionClass(EventBuilder::class);
        $staticFactories = array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC),
            fn (\ReflectionMethod $m): bool =>
                $m->getDeclaringClass()->getName() === EventBuilder::class
                && $m->getReturnType() !== null
                && (string) $m->getReturnType() === 'self',
        );

        // make() + fromCatalog() + 33 event factories (original 23 + 10 new) = 35
        // Actually: make, fromCatalog, purchase, signUp, pageView, login, startTrial,
        // trialConverted, subscribe, planUpgrade, cancellation, viewItem, addToCart,
        // refund, search, share, formStart, formSubmit, scrollDepth, error, identify,
        // beginCheckout, featureUsed, onboardingStep, fromBlueprint = 26 original
        // + 10 new: planDowngrade, logout, subscriptionPaused, subscriptionResumed,
        //   invoiceGenerated, teamCreated, inviteSent, paymentFailed,
        //   subscriptionRenewal, trialExpired = 36 total
        $this->assertGreaterThanOrEqual(
            35,
            count($staticFactories),
            'EventBuilder must have 35+ static factory methods returning self',
        );
    }
}
