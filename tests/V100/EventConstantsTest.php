<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests\V100;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEventConstants;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEventConstants;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\EventTags;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEventConstants;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;

/**
 * v100.0.0 — Event Name Constants comprehensive test suite.
 *
 * Validates that all three constant classes (EcommerceEventConstants,
 * SaaSEventConstants, EngagementEventConstants) provide correct event
 * names, are consistent with their respective catalog classes, and
 * expose the expected utility methods.
 *
 * @since 100.0.0
 */
#[Group('v100')]
#[Group('constants')]
final class EventConstantsTest extends TestCase
{
    // ── EcommerceEventConstants ───────────────────────────────────

    public function testEcommerceConstantsContainsViewItem(): void
    {
        $this->assertSame('view_item', EcommerceEventConstants::VIEW_ITEM);
    }

    public function testEcommerceConstantsContainsAddToCart(): void
    {
        $this->assertSame('add_to_cart', EcommerceEventConstants::ADD_TO_CART);
    }

    public function testEcommerceConstantsContainsPurchase(): void
    {
        $this->assertSame('purchase', EcommerceEventConstants::PURCHASE);
    }

    public function testEcommerceConstantsContainsRefund(): void
    {
        $this->assertSame('refund', EcommerceEventConstants::REFUND);
    }

    public function testEcommerceConstantsContainsCheckoutStep(): void
    {
        $this->assertSame('checkout_step', EcommerceEventConstants::CHECKOUT_STEP);
    }

    public function testEcommerceConstantsContainsAbandonedCart(): void
    {
        $this->assertSame('abandoned_cart', EcommerceEventConstants::ABANDONED_CART);
    }

    public function testEcommerceConstantsAllReturnsNonEmptyArray(): void
    {
        $all = EcommerceEventConstants::all();

        $this->assertIsArray($all);
        $this->assertNotEmpty($all);
    }

    public function testEcommerceConstantsNamesReturnsList(): void
    {
        $names = EcommerceEventConstants::names();

        $this->assertIsArray($names);
        $this->assertContains('purchase', $names);
        $this->assertContains('view_item', $names);
        $this->assertContains('add_to_cart', $names);
    }

    public function testEcommerceConstantsAllNamesAreStrings(): void
    {
        foreach (EcommerceEventConstants::all() as $key => $value) {
            $this->assertIsString($key, "Key '{$key}' must be a string");
            $this->assertIsString($value, "Value for '{$key}' must be a string");
            $this->assertNotEmpty($value, "Value for '{$key}' must not be empty");
        }
    }

    public function testEcommerceConstantsAllValuesAreSnakeCase(): void
    {
        foreach (EcommerceEventConstants::all() as $value) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $value,
                "E-commerce constant value '{$value}' must be snake_case",
            );
        }
    }

    public function testEcommerceConstantsValidReturnsTrueForKnownEvent(): void
    {
        $this->assertTrue(EcommerceEventConstants::isValid('purchase'));
        $this->assertTrue(EcommerceEventConstants::isValid('add_to_cart'));
        $this->assertTrue(EcommerceEventConstants::isValid('refund'));
    }

    public function testEcommerceConstantsValidReturnsFalseForUnknownEvent(): void
    {
        $this->assertFalse(EcommerceEventConstants::isValid('nonexistent_event'));
        $this->assertFalse(EcommerceEventConstants::isValid(''));
    }

    public function testEcommerceConstantsCountIsPositive(): void
    {
        $this->assertGreaterThan(0, EcommerceEventConstants::count());
        $this->assertSame(
            count(EcommerceEventConstants::all()),
            EcommerceEventConstants::count(),
        );
    }

    public function testEcommerceConstantsAreSubsetOfCatalog(): void
    {
        foreach (EcommerceEventConstants::names() as $name) {
            $this->assertTrue(
                EcommerceEvents::has($name),
                "Ecommerce constant '{$name}' not found in EcommerceEvents catalog",
            );
        }
    }

    // ── SaaSEventConstants ─────────────────────────────────────────

    public function testSaaSConstantsContainsSignUp(): void
    {
        $this->assertSame('sign_up', SaaSEventConstants::SIGN_UP);
    }

    public function testSaaSConstantsContainsLogin(): void
    {
        $this->assertSame('login', SaaSEventConstants::LOGIN);
    }

    public function testSaaSConstantsContainsTrialStart(): void
    {
        $this->assertSame('start_trial', SaaSEventConstants::TRIAL_START);
    }

    public function testSaaSConstantsContainsCancellation(): void
    {
        $this->assertSame('cancellation', SaaSEventConstants::CANCELLATION);
    }

    public function testSaaSConstantsContainsPlanUpgrade(): void
    {
        $this->assertSame('plan_upgrade', SaaSEventConstants::PLAN_UPGRADE);
    }

    public function testSaaSConstantsContainsPlanDowngrade(): void
    {
        $this->assertSame('plan_downgrade', SaaSEventConstants::PLAN_DOWNGRADE);
    }

    public function testSaaSConstantsContainsPaymentSucceeded(): void
    {
        $this->assertSame('payment_succeeded', SaaSEventConstants::PAYMENT_SUCCEEDED);
    }

    public function testSaaSConstantsContainsTeamCreated(): void
    {
        $this->assertSame('team_created', SaaSEventConstants::TEAM_CREATED);
    }

    public function testSaaSConstantsContainsFeatureUsed(): void
    {
        $this->assertSame('feature_used', SaaSEventConstants::FEATURE_USED);
    }

    public function testSaaSConstantsContainsAccountDeleted(): void
    {
        $this->assertSame('account_deleted', SaaSEventConstants::ACCOUNT_DELETED);
    }

    public function testSaaSConstantsContainsDataErasureCompleted(): void
    {
        $this->assertSame('data_erasure_completed', SaaSEventConstants::DATA_ERASURE_COMPLETED);
    }

    public function testSaaSConstantsContainsIntegrationConnected(): void
    {
        $this->assertSame('integration_connected', SaaSEventConstants::INTEGRATION_CONNECTED);
    }

    public function testSaaSConstantsContainsMilestoneReached(): void
    {
        $this->assertSame('milestone_reached', SaaSEventConstants::MILESTONE_REACHED);
    }

    public function testSaaSConstantsAllReturnsNonEmptyArray(): void
    {
        $all = SaaSEventConstants::all();

        $this->assertIsArray($all);
        $this->assertNotEmpty($all);
    }

    public function testSaaSConstantsNamesReturnsList(): void
    {
        $names = SaaSEventConstants::names();

        $this->assertIsArray($names);
        $this->assertContains('sign_up', $names);
        $this->assertContains('login', $names);
        $this->assertContains('plan_upgrade', $names);
        $this->assertContains('cancellation', $names);
        $this->assertContains('start_trial', $names);
    }

    public function testSaaSConstantsAllNamesAreStrings(): void
    {
        foreach (SaaSEventConstants::all() as $key => $value) {
            $this->assertIsString($key);
            $this->assertIsString($value);
            $this->assertNotEmpty($value);
        }
    }

    public function testSaaSConstantsAllValuesAreSnakeCase(): void
    {
        foreach (SaaSEventConstants::all() as $value) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $value,
                "SaaS constant value '{$value}' must be snake_case",
            );
        }
    }

    public function testSaaSConstantsValidReturnsTrueForKnownEvent(): void
    {
        $this->assertTrue(SaaSEventConstants::isValid('sign_up'));
        $this->assertTrue(SaaSEventConstants::isValid('plan_upgrade'));
        $this->assertTrue(SaaSEventConstants::isValid('cancellation'));
    }

    public function testSaaSConstantsValidReturnsFalseForUnknownEvent(): void
    {
        $this->assertFalse(SaaSEventConstants::isValid('nonexistent_event'));
        $this->assertFalse(SaaSEventConstants::isValid(''));
    }

    public function testSaaSConstantsCountIsPositive(): void
    {
        $this->assertGreaterThan(0, SaaSEventConstants::count());
        $this->assertSame(
            count(SaaSEventConstants::all()),
            SaaSEventConstants::count(),
        );
    }

    public function testSaaSConstantsAreSubsetOfCatalog(): void
    {
        foreach (SaaSEventConstants::names() as $name) {
            $this->assertTrue(
                SaaSEvents::has($name),
                "SaaS constant '{$name}' not found in SaaSEvents catalog",
            );
        }
    }

    public function testSaaSConstantsCoversAllCoreLifecycleEvents(): void
    {
        $coreEvents = [
            'sign_up',
            'login',
            'logout',
            'start_trial',
            'plan_upgrade',
            'plan_downgrade',
            'cancellation',
            'payment_succeeded',
            'payment_failed',
            'team_created',
            'team_member_joined',
            'team_member_removed',
            'feature_used',
            'account_deleted',
            'password_changed',
        ];

        foreach ($coreEvents as $event) {
            $this->assertTrue(
                SaaSEventConstants::isValid($event),
                "Core lifecycle event '{$event}' missing from SaaSEventConstants",
            );
        }
    }

    // ── EngagementEventConstants ───────────────────────────────────

    public function testEngagementConstantsContainsPageView(): void
    {
        $this->assertSame('page_view', EngagementEventConstants::PAGE_VIEW);
    }

    public function testEngagementConstantsContainsScrollDepth(): void
    {
        $this->assertSame('scroll_depth', EngagementEventConstants::SCROLL_DEPTH);
    }

    public function testEngagementConstantsContainsClick(): void
    {
        $this->assertSame('click', EngagementEventConstants::CLICK);
    }

    public function testEngagementConstantsContainsFormStart(): void
    {
        $this->assertSame('form_start', EngagementEventConstants::FORM_START);
    }

    public function testEngagementConstantsContainsFormSubmit(): void
    {
        $this->assertSame('form_submit', EngagementEventConstants::FORM_SUBMIT);
    }

    public function testEngagementConstantsContainsSearch(): void
    {
        $this->assertSame('search', EngagementEventConstants::SEARCH);
    }

    public function testEngagementConstantsContainsShare(): void
    {
        $this->assertSame('share', EngagementEventConstants::SHARE);
    }

    public function testEngagementConstantsContainsError(): void
    {
        $this->assertSame('error', EngagementEventConstants::ERROR);
    }

    public function testEngagementConstantsContainsSessionStart(): void
    {
        $this->assertSame('session_start', EngagementEventConstants::SESSION_START);
    }

    public function testEngagementConstantsContainsScreenView(): void
    {
        $this->assertSame('screen_view', EngagementEventConstants::SCREEN_VIEW);
    }

    public function testEngagementConstantsAllReturnsNonEmptyArray(): void
    {
        $all = EngagementEventConstants::all();

        $this->assertIsArray($all);
        $this->assertNotEmpty($all);
    }

    public function testEngagementConstantsNamesReturnsList(): void
    {
        $names = EngagementEventConstants::names();

        $this->assertIsArray($names);
        $this->assertContains('page_view', $names);
        $this->assertContains('scroll_depth', $names);
        $this->assertContains('form_submit', $names);
        $this->assertContains('search', $names);
    }

    public function testEngagementConstantsAllNamesAreStrings(): void
    {
        foreach (EngagementEventConstants::all() as $key => $value) {
            $this->assertIsString($key);
            $this->assertIsString($value);
            $this->assertNotEmpty($value);
        }
    }

    public function testEngagementConstantsAllValuesAreSnakeCase(): void
    {
        foreach (EngagementEventConstants::all() as $value) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $value,
                "Engagement constant value '{$value}' must be snake_case",
            );
        }
    }

    public function testEngagementConstantsValidReturnsTrueForKnownEvent(): void
    {
        $this->assertTrue(EngagementEventConstants::isValid('page_view'));
        $this->assertTrue(EngagementEventConstants::isValid('form_submit'));
        $this->assertTrue(EngagementEventConstants::isValid('scroll_depth'));
    }

    public function testEngagementConstantsValidReturnsFalseForUnknownEvent(): void
    {
        $this->assertFalse(EngagementEventConstants::isValid('nonexistent_event'));
        $this->assertFalse(EngagementEventConstants::isValid(''));
    }

    public function testEngagementConstantsCountIsPositive(): void
    {
        $this->assertGreaterThan(0, EngagementEventConstants::count());
        $this->assertSame(
            count(EngagementEventConstants::all()),
            EngagementEventConstants::count(),
        );
    }

    public function testEngagementConstantsAreSubsetOfCatalog(): void
    {
        foreach (EngagementEventConstants::names() as $name) {
            $this->assertTrue(
                EngagementEvents::has($name),
                "Engagement constant '{$name}' not found in EngagementEvents catalog",
            );
        }
    }

    public function testEngagementConstantsCoversAllCoreEngagementEvents(): void
    {
        $coreEvents = [
            'page_view',
            'scroll_depth',
            'click',
            'form_start',
            'form_submit',
            'search',
            'share',
            'error',
            'session_start',
            'session_end',
            'screen_view',
            'outbound_click',
            'file_download',
            'video_play',
        ];

        foreach ($coreEvents as $event) {
            $this->assertTrue(
                EngagementEventConstants::isValid($event),
                "Core engagement event '{$event}' missing from EngagementEventConstants",
            );
        }
    }

    // ── Cross-Constant Consistency ────────────────────────────────

    public function testNoDuplicateEventNamesAcrossConstantClasses(): void
    {
        $ecommerce = EcommerceEventConstants::names();
        $saas = SaaSEventConstants::names();
        $engagement = EngagementEventConstants::names();

        $overlap1 = array_intersect($ecommerce, $saas);
        $overlap2 = array_intersect($ecommerce, $engagement);
        $overlap3 = array_intersect($saas, $engagement);

        $allOverlaps = array_merge($overlap1, $overlap2, $overlap3);

        $this->assertEmpty(
            $allOverlaps,
            'Event names must be unique across constant classes. Overlaps: ' . implode(', ', $allOverlaps),
        );
    }

    public function testTotalConstantsCountMatchesSumOfCategories(): void
    {
        $total = EcommerceEventConstants::count()
            + SaaSEventConstants::count()
            + EngagementEventConstants::count();

        $this->assertGreaterThan(80, $total, 'Total constants should cover 80+ core events');
    }

    // ── Version Consistency ──────────────────────────────────────

    public function testPackageVersionIs100(): void
    {
        $this->assertSame('100.0.0', AnalyticsEvent::VERSION);
    }

    public function testVersionIsString(): void
    {
        $this->assertIsString(AnalyticsEvent::VERSION);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', AnalyticsEvent::VERSION);
    }

    // ── Catalog Integration ──────────────────────────────────────

    public function testEventCatalogContainsAllConstantEvents(): void
    {
        $catalogNames = EventCatalog::names();

        foreach (EcommerceEventConstants::names() as $name) {
            $this->assertContains(
                $name,
                $catalogNames,
                "E-commerce constant '{$name}' must be in the unified EventCatalog",
            );
        }

        foreach (SaaSEventConstants::names() as $name) {
            $this->assertContains(
                $name,
                $catalogNames,
                "SaaS constant '{$name}' must be in the unified EventCatalog",
            );
        }

        foreach (EngagementEventConstants::names() as $name) {
            $this->assertContains(
                $name,
                $catalogNames,
                "Engagement constant '{$name}' must be in the unified EventCatalog",
            );
        }
    }

    public function testEventTagsAreAvailableForAllConstantEvents(): void
    {
        foreach (SaaSEventConstants::names() as $name) {
            $tags = EventTags::for($name);

            $this->assertIsArray($tags);
            $this->assertNotEmpty($tags, "SaaS event '{$name}' should have at least one tag");
        }
    }
}
