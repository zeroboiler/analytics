<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Schema\EventParameterSchema;
use ZeroBoiler\Analytics\Schema\EventParameterSchemas;

beforeEach(function (): void {
    // Ensure EventParameterSchemas static cache is fresh
    EventParameterSchemas::all();
});

describe('EventParameterSchemas — Industry Standard SaaS Coverage', function (): void {
    test('has schemas for all ecommerce events', function (): void {
        $ecommerceNames = EcommerceEvents::names();
        $schemaNames = EventParameterSchemas::schemaEventNames();

        foreach ($ecommerceNames as $eventName) {
            expect(EventParameterSchemas::hasSchema($eventName))
                ->toBe(true, "Missing schema for ecommerce event: {$eventName}");
        }
    });

    test('has schemas for all engagement events', function (): void {
        $engagementNames = EngagementEvents::names();
        $schemaNames = EventParameterSchemas::schemaEventNames();

        foreach ($engagementNames as $eventName) {
            expect(EventParameterSchemas::hasSchema($eventName))
                ->toBe(true, "Missing schema for engagement event: {$eventName}");
        }
    });

    test('has schemas for core SaaS lifecycle events', function (): void {
        $coreSaaS = [
            'sign_up', 'login', 'logout', 'start_trial', 'trial_end',
            'subscribe', 'plan_upgrade', 'plan_downgrade', 'cancellation',
            'trial_converted', 'trial_expired', 'subscription_created',
            'subscription_cancelled', 'subscription_renewal', 'subscription_resumed',
            'subscription_paused', 'subscription_value_changed',
            'feature_used', 'feature_adopted', 'feature_limit_reached',
            'revenue_tracked', 'expansion_revenue',
            'payment_failed', 'payment_succeeded', 'billing_retry',
            'team_created', 'team_member_joined', 'team_member_removed',
            'role_changed', 'invite_sent',
            'integration_connected', 'integration_failed',
            'account_activated', 'account_deactivated', 'account_deleted',
            'password_changed', 'password_reset', 'email_verified', 'profile_updated',
            'workspace_created', 'milestone_reached', 'usage_quota_reached',
            'consent_granted', 'consent_withdrawn',
            'cohort_assigned', 'cohort_retention', 'cohort_churn',
            'cohort_conversion', 'cohort_migration', 'cohort_engagement',
        ];

        foreach ($coreSaaS as $eventName) {
            expect(EventParameterSchemas::hasSchema($eventName))
                ->toBe(true, "Missing schema for SaaS event: {$eventName}");
        }
    });

    test('schema count exceeds 60 events', function (): void {
        expect(EventParameterSchemas::count())->toBeGreaterThan(60);
    });

    test('all schemas have valid category', function (): void {
        $validCategories = ['ecommerce', 'saas', 'engagement'];

        foreach (EventParameterSchemas::all() as $name => $schema) {
            expect($schema->category)->toBeIn($validCategories);
            expect($schema->name)->toBe($name);
        }
    });

    test('ecommerce purchase schema requires transaction_id, currency, value', function (): void {
        $schema = EventParameterSchemas::forEvent('purchase');

        expect($schema)->not->toBeNull();
        expect($schema->required)->toContain('transaction_id');
        expect($schema->required)->toContain('currency');
        expect($schema->required)->toContain('value');
        expect($schema->itemParams)->toBeTrue();
        expect($schema->optional)->toHaveKey('tax');
        expect($schema->optional)->toHaveKey('shipping');
        expect($schema->optional)->toHaveKey('coupon');
    });

    test('ecommerce refund schema requires transaction_id', function (): void {
        $schema = EventParameterSchemas::forEvent('refund');

        expect($schema)->not->toBeNull();
        expect($schema->required)->toContain('transaction_id');
    });

    test('SaaS sign_up schema has method as optional', function (): void {
        $schema = EventParameterSchemas::forEvent('sign_up');

        expect($schema)->not->toBeNull();
        expect($schema->optional)->toHaveKey('method');
        expect($schema->optional)->toHaveKey('plan_name');
    });

    test('SaaS plan_upgrade schema has from_plan and to_plan', function (): void {
        $schema = EventParameterSchemas::forEvent('plan_upgrade');

        expect($schema)->not->toBeNull();
        expect($schema->optional)->toHaveKey('from_plan');
        expect($schema->optional)->toHaveKey('to_plan');
        expect($schema->optional)->toHaveKey('price_difference');
    });

    test('engagement page_view schema has page_title, page_location, page_referrer', function (): void {
        $schema = EventParameterSchemas::forEvent('page_view');

        expect($schema)->not->toBeNull();
        expect($schema->required)->toBeEmpty();
        expect($schema->optional)->toHaveKey('page_title');
        expect($schema->optional)->toHaveKey('page_location');
        expect($schema->optional)->toHaveKey('page_referrer');
    });

    test('engagement search schema requires search_term', function (): void {
        $schema = EventParameterSchemas::forEvent('search');

        expect($schema)->not->toBeNull();
        expect($schema->required)->toContain('search_term');
    });

    test('engagement share schema requires method', function (): void {
        $schema = EventParameterSchemas::forEvent('share');

        expect($schema)->not->toBeNull();
        expect($schema->required)->toContain('method');
    });

    test('unknown events return null schema', function (): void {
        expect(EventParameterSchemas::forEvent('nonexistent_event_xyz'))->toBeNull();
    });

    test('validation passes for valid purchase params', function (): void {
        $errors = EventParameterSchemas::validate('purchase', [
            'transaction_id' => 'ORD-123',
            'currency' => 'USD',
            'value' => 99.99,
            'tax' => 8.50,
            'items' => [
                ['item_id' => 'SKU-1', 'price' => 49.99, 'quantity' => 2],
            ],
        ]);

        expect($errors)->toBeEmpty();
    });

    test('validation fails for missing required purchase params', function (): void {
        $errors = EventParameterSchemas::validate('purchase', [
            'value' => 99.99,
        ]);

        expect($errors)->not->toBeEmpty();
        expect($errors)->toContain("Missing required parameter: 'transaction_id'");
        expect($errors)->toContain("Missing required parameter: 'currency'");
    });

    test('validation fails for wrong type', function (): void {
        $errors = EventParameterSchemas::validate('purchase', [
            'transaction_id' => 'ORD-123',
            'currency' => 'USD',
            'value' => 'not_a_number',
        ]);

        expect($errors)->not->toBeEmpty();
    });

    test('validation passes for unknown events (no schema = no validation)', function (): void {
        $errors = EventParameterSchemas::validate('custom_event', [
            'anything' => 'goes',
        ]);

        expect($errors)->toBeEmpty();
    });

    test('validation allows null for optional params', function (): void {
        $errors = EventParameterSchemas::validate('sign_up', [
            'method' => null,
        ]);

        expect($errors)->toBeEmpty();
    });

    test('byCategory groups schemas correctly', function (): void {
        $grouped = EventParameterSchemas::byCategory();

        expect($grouped)->toHaveKeys(['ecommerce', 'saas', 'engagement']);
        expect($grouped['ecommerce'])->toHaveKey('purchase');
        expect($grouped['saas'])->toHaveKey('sign_up');
        expect($grouped['engagement'])->toHaveKey('page_view');
        expect($grouped['ecommerce'])->not->toHaveKey('sign_up');
    });

    test('schema toArray serialization', function (): void {
        $schema = EventParameterSchemas::forEvent('purchase');

        expect($schema)->not->toBeNull();
        $arr = $schema->toArray();

        expect($arr)->toHaveKey('name');
        expect($arr)->toHaveKey('category');
        expect($arr)->toHaveKey('required');
        expect($arr)->toHaveKey('optional');
        expect($arr)->toHaveKey('item_params');
        expect($arr['name'])->toBe('purchase');
        expect($arr['category'])->toBe('ecommerce');
        expect($arr['item_params'])->toBeTrue();
    });

    test('all ecommerce schemas have itemParams = true for item-based events', function (): void {
        $itemEvents = [
            'view_item', 'add_to_cart', 'remove_from_cart', 'view_cart',
            'begin_checkout', 'add_payment_info', 'purchase', 'refund',
            'add_to_wishlist', 'select_item', 'select_promotion', 'view_promotion',
        ];

        foreach ($itemEvents as $eventName) {
            $schema = EventParameterSchemas::forEvent($eventName);
            expect($schema)->not->toBeNull();
            expect($schema->itemParams)
                ->toBe(true, "{$eventName} should have itemParams = true");
        }
    });
});

describe('EventParameterSchema — Value Object', function (): void {
    test('is readonly', function (): void {
        $schema = new EventParameterSchema(
            name: 'test_event',
            category: 'engagement',
            required: ['foo'],
            optional: ['bar' => 'string'],
            itemParams: false,
        );

        expect($schema->name)->toBe('test_event');
        expect($schema->category)->toBe('engagement');
        expect($schema->required)->toBe(['foo']);
        expect($schema->optional)->toBe(['bar' => 'string']);
        expect($schema->itemParams)->toBeFalse();
    });
});

describe('End-to-End SaaS Funnel Parameter Validation', function (): void {
    test('signup → trial → subscribe → upgrade → cancellation flow validates', function (): void {
        // Step 1: Sign Up
        $errors = EventParameterSchemas::validate('sign_up', [
            'method' => 'email',
            'plan_name' => 'free',
        ]);
        expect($errors)->toBeEmpty();

        // Step 2: Trial Start
        $errors = EventParameterSchemas::validate('start_trial', [
            'plan_name' => 'pro',
            'trial_days' => 14,
        ]);
        expect($errors)->toBeEmpty();

        // Step 3: Subscribe (trial converted)
        $errors = EventParameterSchemas::validate('subscribe', [
            'plan_name' => 'pro',
            'value' => 29.99,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
        ]);
        expect($errors)->toBeEmpty();

        // Step 4: Plan Upgrade
        $errors = EventParameterSchemas::validate('plan_upgrade', [
            'from_plan' => 'pro',
            'to_plan' => 'enterprise',
            'price_difference' => 170.01,
        ]);
        expect($errors)->toBeEmpty();

        // Step 5: Cancellation
        $errors = EventParameterSchemas::validate('cancellation', [
            'plan_name' => 'enterprise',
            'reason' => 'budget',
        ]);
        expect($errors)->toBeEmpty();
    });

    test('e-commerce purchase funnel validates', function (): void {
        // View Item
        $errors = EventParameterSchemas::validate('view_item', [
            'currency' => 'USD',
            'value' => 49.99,
            'items' => [['item_id' => 'SKU-1', 'price' => 49.99, 'quantity' => 1]],
        ]);
        expect($errors)->toBeEmpty();

        // Add to Cart
        $errors = EventParameterSchemas::validate('add_to_cart', [
            'currency' => 'USD',
            'value' => 99.98,
            'items' => [['item_id' => 'SKU-1', 'price' => 49.99, 'quantity' => 2]],
        ]);
        expect($errors)->toBeEmpty();

        // Begin Checkout
        $errors = EventParameterSchemas::validate('begin_checkout', [
            'currency' => 'USD',
            'value' => 99.98,
            'items' => [['item_id' => 'SKU-1', 'price' => 49.99, 'quantity' => 2]],
            'coupon' => 'SAVE10',
        ]);
        expect($errors)->toBeEmpty();

        // Add Payment Info
        $errors = EventParameterSchemas::validate('add_payment_info', [
            'currency' => 'USD',
            'value' => 89.98,
            'items' => [['item_id' => 'SKU-1', 'price' => 44.99, 'quantity' => 2]],
            'payment_type' => 'credit_card',
        ]);
        expect($errors)->toBeEmpty();

        // Purchase
        $errors = EventParameterSchemas::validate('purchase', [
            'transaction_id' => 'TXN-789',
            'currency' => 'USD',
            'value' => 89.98,
            'tax' => 7.50,
            'shipping' => 5.00,
            'coupon' => 'SAVE10',
            'items' => [['item_id' => 'SKU-1', 'price' => 44.99, 'quantity' => 2]],
        ]);
        expect($errors)->toBeEmpty();
    });

    test('engagement flow validates', function (): void {
        // Page View
        expect(EventParameterSchemas::validate('page_view', [
            'page_title' => 'Pricing',
            'page_location' => 'https://example.com/pricing',
        ]))->toBeEmpty();

        // Scroll Depth
        expect(EventParameterSchemas::validate('scroll_depth', [
            'percent' => 75,
            'time_on_page' => 12.5,
        ]))->toBeEmpty();

        // Click
        expect(EventParameterSchemas::validate('click', [
            'element' => 'button',
            'element_text' => 'Get Started',
        ]))->toBeEmpty();

        // Form Start
        expect(EventParameterSchemas::validate('form_start', [
            'form_id' => 'signup-form',
            'form_name' => 'Registration',
        ]))->toBeEmpty();

        // Form Submit
        expect(EventParameterSchemas::validate('form_submit', [
            'form_id' => 'signup-form',
            'success' => true,
        ]))->toBeEmpty();

        // Search
        expect(EventParameterSchemas::validate('search', [
            'search_term' => 'analytics dashboard',
            'results_count' => 42,
        ]))->toBeEmpty();

        // Error
        expect(EventParameterSchemas::validate('error', [
            'error_type' => 'TypeError',
            'error_message' => 'Cannot read property of null',
            'fatal' => false,
        ]))->toBeEmpty();
    });

    test('cohort analytics events validate', function (): void {
        expect(EventParameterSchemas::validate('cohort_assigned', [
            'cohort_name' => 'power_users',
            'cohort_type' => 'behavioral',
        ]))->toBeEmpty();

        expect(EventParameterSchemas::validate('cohort_retention', [
            'cohort_name' => 'jan_2026',
            'period_days' => 30,
            'retention_rate' => 0.65,
        ]))->toBeEmpty();

        expect(EventParameterSchemas::validate('cohort_churn', [
            'cohort_name' => 'jan_2026',
            'churn_rate' => 0.15,
        ]))->toBeEmpty();

        expect(EventParameterSchemas::validate('cohort_migration', [
            'from_cohort' => 'free_users',
            'to_cohort' => 'paid_users',
        ]))->toBeEmpty();
    });
});
