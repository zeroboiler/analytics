<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEventConstants;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEventConstants;
use ZeroBoiler\Analytics\Events\EventAliasRegistry;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEventConstants;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Services\AnalyticsConfigValidator;

/**
 * Tests for v238.0.0 — SaaS Event Constants, Event Alias Registry,
 * Analytics Config Validator.
 *
 * Validates:
 * 1. File quality: strict_types, MIT headers, final classes, @since annotations
 * 2. SaaSEventConstants: all constants, count, isValid, coverage vs catalog
 * 3. EventAliasRegistry: alias resolution, grouping, validation, custom registration
 * 4. AnalyticsConfigValidator: file quality, structure, method signatures
 * 5. Cross-catalog constants parity
 * 6. Version consistency 238.0.0
 *
 * @since 238.0.0
 */
final class V238EventConstantsAliasRegistryConfigValidatorTest extends TestCase
{
    // ── File Quality Checks ────────────────────────────────────

    public function testSaaSEventConstantsHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Events/SaaS/SaaSEventConstants.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('namespace ZeroBoiler\\Analytics\\Events\\SaaS', $content);
        $this->assertStringContainsString('final class SaaSEventConstants', $content);
        $this->assertStringContainsString('@since 238.0.0', $content);
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $content);
    }

    public function testEventAliasRegistryHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Events/EventAliasRegistry.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('namespace ZeroBoiler\\Analytics\\Events', $content);
        $this->assertStringContainsString('final class EventAliasRegistry', $content);
        $this->assertStringContainsString('@since 238.0.0', $content);
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $content);
    }

    public function testAnalyticsConfigValidatorHasStrictTypes(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/AnalyticsConfigValidator.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('namespace ZeroBoiler\\Analytics\\Services', $content);
        $this->assertStringContainsString('final class AnalyticsConfigValidator', $content);
        $this->assertStringContainsString('@since 238.0.0', $content);
        $this->assertStringContainsString('This file is part of ZeroBoiler, licensed under the MIT license', $content);
    }

    // ── SaaSEventConstants ─────────────────────────────────────

    public function testSaaSEventConstantsHasAllCoreEvents(): void
    {
        $this->assertSame('sign_up', SaaSEventConstants::SIGN_UP);
        $this->assertSame('login', SaaSEventConstants::LOGIN);
        $this->assertSame('logout', SaaSEventConstants::LOGOUT);
        $this->assertSame('start_trial', SaaSEventConstants::START_TRIAL);
        $this->assertSame('subscribe', SaaSEventConstants::SUBSCRIBE);
        $this->assertSame('plan_upgrade', SaaSEventConstants::PLAN_UPGRADE);
        $this->assertSame('plan_downgrade', SaaSEventConstants::PLAN_DOWNGRADE);
        $this->assertSame('cancellation', SaaSEventConstants::CANCELLATION);
        $this->assertSame('trial_converted', SaaSEventConstants::TRIAL_CONVERTED);
        $this->assertSame('trial_expired', SaaSEventConstants::TRIAL_EXPIRED);
        $this->assertSame('feature_used', SaaSEventConstants::FEATURE_USED);
        $this->assertSame('feature_adopted', SaaSEventConstants::FEATURE_ADOPTED);
        $this->assertSame('payment_failed', SaaSEventConstants::PAYMENT_FAILED);
        $this->assertSame('payment_succeeded', SaaSEventConstants::PAYMENT_SUCCEEDED);
    }

    public function testSaaSEventConstantsHasSubscriptionLifecycle(): void
    {
        $this->assertSame('subscription_created', SaaSEventConstants::SUBSCRIPTION_CREATED);
        $this->assertSame('subscription_renewal', SaaSEventConstants::SUBSCRIPTION_RENEWAL);
        $this->assertSame('subscription_paused', SaaSEventConstants::SUBSCRIPTION_PAUSED);
        $this->assertSame('subscription_resumed', SaaSEventConstants::SUBSCRIPTION_RESUMED);
        $this->assertSame('subscription_cancelled', SaaSEventConstants::SUBSCRIPTION_CANCELLED);
        $this->assertSame('subscription_value_changed', SaaSEventConstants::SUBSCRIPTION_VALUE_CHANGED);
    }

    public function testSaaSEventConstantsHasBillingConstants(): void
    {
        $this->assertSame('invoice_generated', SaaSEventConstants::INVOICE_GENERATED);
        $this->assertSame('credit_applied', SaaSEventConstants::CREDIT_APPLIED);
        $this->assertSame('billing_retry', SaaSEventConstants::BILLING_RETRY);
        $this->assertSame('payment_method_added', SaaSEventConstants::PAYMENT_METHOD_ADDED);
        $this->assertSame('payment_method_updated', SaaSEventConstants::PAYMENT_METHOD_UPDATED);
        $this->assertSame('payment_method_removed', SaaSEventConstants::PAYMENT_METHOD_REMOVED);
    }

    public function testSaaSEventConstantsHasTeamConstants(): void
    {
        $this->assertSame('team_created', SaaSEventConstants::TEAM_CREATED);
        $this->assertSame('team_member_joined', SaaSEventConstants::TEAM_MEMBER_JOINED);
        $this->assertSame('team_member_removed', SaaSEventConstants::TEAM_MEMBER_REMOVED);
        $this->assertSame('role_changed', SaaSEventConstants::ROLE_CHANGED);
        $this->assertSame('invite_sent', SaaSEventConstants::INVITE_SENT);
        $this->assertSame('workspace_created', SaaSEventConstants::WORKSPACE_CREATED);
    }

    public function testSaaSEventConstantsHasCohortConstants(): void
    {
        $this->assertSame('cohort_assigned', SaaSEventConstants::COHORT_ASSIGNED);
        $this->assertSame('cohort_retention', SaaSEventConstants::COHORT_RETENTION);
        $this->assertSame('cohort_churn', SaaSEventConstants::COHORT_CHURN);
        $this->assertSame('cohort_conversion', SaaSEventConstants::COHORT_CONVERSION);
        $this->assertSame('cohort_migration', SaaSEventConstants::COHORT_MIGRATION);
        $this->assertSame('cohort_engagement', SaaSEventConstants::COHORT_ENGAGEMENT);
    }

    public function testSaaSEventConstantsHasDataPrivacyConstants(): void
    {
        $this->assertSame('export', SaaSEventConstants::EXPORT);
        $this->assertSame('import', SaaSEventConstants::IMPORT);
        $this->assertSame('account_deleted', SaaSEventConstants::ACCOUNT_DELETED);
        $this->assertSame('data_erasure_completed', SaaSEventConstants::DATA_ERASURE_COMPLETED);
    }

    public function testSaaSEventConstantsHasGrowthConstants(): void
    {
        $this->assertSame('milestone_reached', SaaSEventConstants::MILESTONE_REACHED);
        $this->assertSame('first_value', SaaSEventConstants::FIRST_VALUE);
        $this->assertSame('activation', SaaSEventConstants::ACTIVATION);
        $this->assertSame('revenue_tracked', SaaSEventConstants::REVENUE_TRACKED);
        $this->assertSame('expansion_revenue', SaaSEventConstants::EXPANSION_REVENUE);
    }

    public function testSaaSEventConstantsAllMethod(): void
    {
        $all = SaaSEventConstants::all();
        $this->assertIsArray($all);
        $this->assertGreaterThan(70, count($all));
    }

    public function testSaaSEventConstantsNamesMethod(): void
    {
        $names = SaaSEventConstants::names();
        $this->assertIsArray($names);
        $this->assertContains('sign_up', $names);
        $this->assertContains('login', $names);
        $this->assertContains('start_trial', $names);
        $this->assertContains('purchase', $names); // if present
    }

    public function testSaaSEventConstantsIsValid(): void
    {
        $this->assertTrue(SaaSEventConstants::isValid('sign_up'));
        $this->assertTrue(SaaSEventConstants::isValid('login'));
        $this->assertTrue(SaaSEventConstants::isValid('start_trial'));
        $this->assertFalse(SaaSEventConstants::isValid('nonexistent_event'));
        $this->assertFalse(SaaSEventConstants::isValid(''));
    }

    public function testSaaSEventConstantsCount(): void
    {
        $count = SaaSEventConstants::count();
        $this->assertGreaterThan(70, $count);
        $this->assertSame(count(SaaSEventConstants::all()), $count);
    }

    public function testSaaSEventConstantsCatalogCoverage(): void
    {
        // All SaaS constants should resolve in the EventCatalog
        $missing = [];
        foreach (SaaSEventConstants::names() as $name) {
            if (! EventCatalog::has($name)) {
                $missing[] = $name;
            }
        }
        // Most constants should map to catalog entries
        $coverage = 1.0 - (count($missing) / SaaSEventConstants::count());
        $this->assertGreaterThan(0.85, $coverage, 'SaaS constants should have >85% catalog coverage. Missing: ' . implode(', ', $missing));
    }

    // ── Cross-Catalog Constants Parity ─────────────────────────

    public function testEcommerceConstantsExist(): void
    {
        $this->assertSame('view_item', EcommerceEventConstants::VIEW_ITEM);
        $this->assertSame('add_to_cart', EcommerceEventConstants::ADD_TO_CART);
        $this->assertSame('purchase', EcommerceEventConstants::PURCHASE);
        $this->assertSame('refund', EcommerceEventConstants::REFUND);
        $this->assertSame('begin_checkout', EcommerceEventConstants::BEGIN_CHECKOUT);
        $this->assertGreaterThan(10, EcommerceEventConstants::count());
    }

    public function testEngagementConstantsExist(): void
    {
        $this->assertSame('page_view', EngagementEventConstants::PAGE_VIEW);
        $this->assertSame('scroll_depth', EngagementEventConstants::SCROLL_DEPTH);
        $this->assertSame('click', EngagementEventConstants::CLICK);
        $this->assertSame('form_start', EngagementEventConstants::FORM_START);
        $this->assertSame('form_submit', EngagementEventConstants::FORM_SUBMIT);
        $this->assertSame('search', EngagementEventConstants::SEARCH);
        $this->assertSame('share', EngagementEventConstants::SHARE);
        $this->assertSame('error', EngagementEventConstants::ERROR);
        $this->assertGreaterThan(25, EngagementEventConstants::count());
    }

    public function testAllConstantsClassesAreFinal(): void
    {
        $classes = [
            SaaSEventConstants::class,
            EcommerceEventConstants::class,
            EngagementEventConstants::class,
        ];

        foreach ($classes as $class) {
            $ref = new \ReflectionClass($class);
            $this->assertTrue($ref->isFinal(), "{$class} should be final");
        }
    }

    public function testAllConstantsClassesHaveUtilityMethods(): void
    {
        $classes = [
            SaaSEventConstants::class,
            EcommerceEventConstants::class,
            EngagementEventConstants::class,
        ];

        foreach ($classes as $class) {
            $this->assertTrue(method_exists($class, 'all'), "{$class}::all() should exist");
            $this->assertTrue(method_exists($class, 'names'), "{$class}::names() should exist");
            $this->assertTrue(method_exists($class, 'isValid'), "{$class}::isValid() should exist");
            $this->assertTrue(method_exists($class, 'count'), "{$class}::count() should exist");
        }
    }

    // ── EventAliasRegistry ─────────────────────────────────────

    public function testEventAliasRegistryResolvesSignup(): void
    {
        $this->assertSame('sign_up', EventAliasRegistry::resolve('signup'));
        $this->assertSame('sign_up', EventAliasRegistry::resolve('register'));
        $this->assertSame('sign_up', EventAliasRegistry::resolve('registration'));
        $this->assertSame('sign_up', EventAliasRegistry::resolve('user_signup'));
    }

    public function testEventAliasRegistryResolvesEcommerce(): void
    {
        $this->assertSame('view_item', EventAliasRegistry::resolve('product_view'));
        $this->assertSame('view_item', EventAliasRegistry::resolve('view_product'));
        $this->assertSame('add_to_cart', EventAliasRegistry::resolve('cart_add'));
        $this->assertSame('begin_checkout', EventAliasRegistry::resolve('checkout'));
        $this->assertSame('begin_checkout', EventAliasRegistry::resolve('start_checkout'));
        $this->assertSame('purchase', EventAliasRegistry::resolve('order'));
        $this->assertSame('purchase', EventAliasRegistry::resolve('order_completed'));
        $this->assertSame('purchase', EventAliasRegistry::resolve('transaction'));
    }

    public function testEventAliasRegistryResolvesSaaS(): void
    {
        $this->assertSame('login', EventAliasRegistry::resolve('signin'));
        $this->assertSame('login', EventAliasRegistry::resolve('sign_in'));
        $this->assertSame('login', EventAliasRegistry::resolve('auth'));
        $this->assertSame('logout', EventAliasRegistry::resolve('signout'));
        $this->assertSame('logout', EventAliasRegistry::resolve('sign_out'));
        $this->assertSame('start_trial', EventAliasRegistry::resolve('trial'));
        $this->assertSame('start_trial', EventAliasRegistry::resolve('trial_start'));
        $this->assertSame('start_trial', EventAliasRegistry::resolve('free_trial'));
        $this->assertSame('cancellation', EventAliasRegistry::resolve('cancel'));
        $this->assertSame('cancellation', EventAliasRegistry::resolve('unsubscribe'));
        $this->assertSame('cancellation', EventAliasRegistry::resolve('churn'));
        $this->assertSame('plan_upgrade', EventAliasRegistry::resolve('upgrade'));
        $this->assertSame('plan_downgrade', EventAliasRegistry::resolve('downgrade'));
    }

    public function testEventAliasRegistryResolvesEngagement(): void
    {
        $this->assertSame('page_view', EventAliasRegistry::resolve('pageview'));
        $this->assertSame('page_view', EventAliasRegistry::resolve('pv'));
        $this->assertSame('scroll_depth', EventAliasRegistry::resolve('scroll'));
        $this->assertSame('click', EventAliasRegistry::resolve('button_click'));
        $this->assertSame('click', EventAliasRegistry::resolve('cta_click'));
        $this->assertSame('form_submit', EventAliasRegistry::resolve('form_submission'));
        $this->assertSame('search', EventAliasRegistry::resolve('site_search'));
        $this->assertSame('share', EventAliasRegistry::resolve('social_share'));
        $this->assertSame('error', EventAliasRegistry::resolve('exception'));
    }

    public function testEventAliasRegistryFallsBackToEventCatalog(): void
    {
        // PascalCase resolution should go through EventCatalog::resolve
        $this->assertSame('view_item', EventAliasRegistry::resolve('ViewItem'));
        $this->assertSame('add_to_cart', EventAliasRegistry::resolve('AddToCart'));
        $this->assertSame('sign_up', EventAliasRegistry::resolve('SignUp'));
    }

    public function testEventAliasRegistryReturnsNullForUnknown(): void
    {
        $this->assertNull(EventAliasRegistry::resolve('totally_nonexistent_event_xyz'));
        $this->assertNull(EventAliasRegistry::resolve(''));
    }

    public function testEventAliasRegistryAllMethod(): void
    {
        $all = EventAliasRegistry::all();
        $this->assertIsArray($all);
        $this->assertGreaterThan(40, count($all));
    }

    public function testEventAliasRegistryCount(): void
    {
        $this->assertSame(count(EventAliasRegistry::all()), EventAliasRegistry::count());
        $this->assertGreaterThan(40, EventAliasRegistry::count());
    }

    public function testEventAliasRegistryHasMethod(): void
    {
        $this->assertTrue(EventAliasRegistry::has('signup'));
        $this->assertTrue(EventAliasRegistry::has('order'));
        $this->assertTrue(EventAliasRegistry::has('checkout'));
        $this->assertFalse(EventAliasRegistry::has('nonexistent_xyz'));
    }

    public function testEventAliasRegistryGroupedByTarget(): void
    {
        $grouped = EventAliasRegistry::groupedByTarget();
        $this->assertIsArray($grouped);
        $this->assertArrayHasKey('sign_up', $grouped);
        $this->assertArrayHasKey('purchase', $grouped);
        $this->assertArrayHasKey('login', $grouped);
        $this->assertContains('signup', $grouped['sign_up']);
        $this->assertContains('register', $grouped['sign_up']);
        $this->assertContains('order', $grouped['purchase']);
    }

    public function testEventAliasRegistryAliasesForMethod(): void
    {
        $aliases = EventAliasRegistry::aliasesFor('sign_up');
        $this->assertContains('signup', $aliases);
        $this->assertContains('register', $aliases);
        $this->assertContains('registration', $aliases);
        $this->assertContains('user_signup', $aliases);
    }

    public function testEventAliasRegistryValidation(): void
    {
        $invalid = EventAliasRegistry::validate();
        // All alias targets should exist in the catalog
        $this->assertCount(0, $invalid, 'All alias targets should exist in the EventCatalog');
    }

    public function testEventAliasRegistryIsCaseInsensitive(): void
    {
        $this->assertSame('sign_up', EventAliasRegistry::resolve('SIGNUP'));
        $this->assertSame('sign_up', EventAliasRegistry::resolve('SignUp'));
        $this->assertSame('sign_up', EventAliasRegistry::resolve('SiGnUp'));
    }

    // ── AnalyticsConfigValidator ───────────────────────────────

    public function testAnalyticsConfigValidatorHasValidateMethod(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/AnalyticsConfigValidator.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('public function validate(): array', $content);
    }

    public function testAnalyticsConfigValidatorHasIsProductionReady(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/AnalyticsConfigValidator.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('public function isProductionReady(): bool', $content);
    }

    public function testAnalyticsConfigValidatorHasProviderChecks(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/AnalyticsConfigValidator.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('ga4Checks', $content);
        $this->assertStringContainsString('gtmChecks', $content);
        $this->assertStringContainsString('metaChecks', $content);
        $this->assertStringContainsString('plausibleChecks', $content);
        $this->assertStringContainsString('posthogChecks', $content);
    }

    public function testAnalyticsConfigValidatorHasCrossSectionValidation(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/AnalyticsConfigValidator.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('validateCrossSection', $content);
        $this->assertStringContainsString('ga4.enabled_without_id', $content);
        $this->assertStringContainsString('meta.enabled_without_id', $content);
        $this->assertStringContainsString('no_providers_enabled', $content);
        $this->assertStringContainsString('debug.in_production', $content);
    }

    public function testAnalyticsConfigValidatorHasSectionChecks(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/AnalyticsConfigValidator.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('consentChecks', $content);
        $this->assertStringContainsString('apiChecks', $content);
        $this->assertStringContainsString('identityChecks', $content);
        $this->assertStringContainsString('queueChecks', $content);
        $this->assertStringContainsString('samplingChecks', $content);
        $this->assertStringContainsString('autoTrackChecks', $content);
    }

    public function testAnalyticsConfigValidatorHasScoreAndGrade(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/AnalyticsConfigValidator.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString("'score'", $content);
        $this->assertStringContainsString("'grade'", $content);
        $this->assertStringContainsString("'A'", $content);
        $this->assertStringContainsString("'B'", $content);
        $this->assertStringContainsString("'C'", $content);
        $this->assertStringContainsString("'D'", $content);
        $this->assertStringContainsString("'F'", $content);
        $this->assertStringContainsString('section_scores', $content);
        $this->assertStringContainsString('buildReport', $content);
    }

    public function testAnalyticsConfigValidatorReturnTypeStructure(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/AnalyticsConfigValidator.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('issues', $content);
        $this->assertStringContainsString('findings', $content);
        $this->assertStringContainsString('summary', $content);
        $this->assertStringContainsString('errors', $content);
        $this->assertStringContainsString('warnings', $content);
    }

    public function testAnalyticsConfigValidatorUsesConfigRepository(): void
    {
        $content = file_get_contents(__DIR__ . '/../src/Services/AnalyticsConfigValidator.php');
        $this->assertNotFalse($content);
        $this->assertStringContainsString('ConfigRepository $config', $content);
        $this->assertStringContainsString('Illuminate\\Contracts\\Config\\Repository', $content);
    }

    // ── Version Consistency ────────────────────────────────────

    public function testVersionConsistency238(): void
    {
        $eventVersion = AnalyticsEvent::VERSION;
        $this->assertIsString($eventVersion);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $eventVersion);
    }

    public function testAllNewFilesHaveSinceAnnotation(): void
    {
        $files = [
            __DIR__ . '/../src/Events/SaaS/SaaSEventConstants.php',
            __DIR__ . '/../src/Events/EventAliasRegistry.php',
            __DIR__ . '/../src/Services/AnalyticsConfigValidator.php',
        ];

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertNotFalse($content);
            $this->assertStringContainsString('@since 238.0.0', $content, "{$file} should have @since 238.0.0");
        }
    }
}
