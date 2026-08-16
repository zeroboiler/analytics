<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests;

use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\EventTags;
use ZeroBoiler\Analytics\Events\SaaS\CustomerSuccessEvents;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CustomerSuccessEvents catalog integration into EventCatalog (v136.0.0).
 *
 * Verifies that:
 * - CustomerSuccessEvents is properly registered in EventCatalog::all(), byCategory(), etc.
 * - All provider name methods include CustomerSuccessEvents
 * - EventTags are assigned to all CustomerSuccess events
 * - EventCatalog::has(), getCategory(), classFor(), resolve() work correctly
 *
 * @covers \ZeroBoiler\Analytics\Events\SaaS\CustomerSuccessEvents
 * @covers \ZeroBoiler\Analytics\Events\EventCatalog
 * @covers \ZeroBoiler\Analytics\Events\EventTags
 */
final class V136CustomerSuccessCatalogIntegrationTest extends TestCase
{
    // ── CustomerSuccessEvents Self-Tests ────────────────────────────

    public function testCustomerSuccessEventsHasExpectedCount(): void
    {
        $this->assertSame(7, CustomerSuccessEvents::count());
    }

    public function testCustomerSuccessEventsReturnsAllNames(): void
    {
        $names = CustomerSuccessEvents::names();
        $this->assertCount(7, $names);
        $this->assertContains('support_ticket_created', $names);
        $this->assertContains('nps_submitted', $names);
        $this->assertContains('health_score_changed', $names);
        $this->assertContains('renewal_reminder_sent', $names);
        $this->assertContains('churn_interview', $names);
        $this->assertContains('customer_review', $names);
        $this->assertContains('onboarding_call_completed', $names);
    }

    public function testCustomerSuccessEventsCategory(): void
    {
        $this->assertSame('customer_success', CustomerSuccessEvents::category());
    }

    public function testCustomerSuccessEventsGa4Names(): void
    {
        $names = CustomerSuccessEvents::ga4Names();
        $this->assertCount(7, $names);
        $this->assertContains('support_ticket_created', $names);
        $this->assertContains('nps_submitted', $names);
    }

    public function testCustomerSuccessEventsMetaNames(): void
    {
        $names = CustomerSuccessEvents::metaNames();
        // All CS events map to 'CustomEvent' in Meta
        $this->assertCount(7, $names);
    }

    public function testCustomerSuccessEventsPosthogNames(): void
    {
        $names = CustomerSuccessEvents::posthogNames();
        $this->assertCount(7, $names);
    }

    public function testCustomerSuccessEventsPlausibleNamesAreEmpty(): void
    {
        $names = CustomerSuccessEvents::plausibleNames();
        $this->assertEmpty($names);
    }

    public function testCustomerSuccessEventsMixpanelNames(): void
    {
        $names = CustomerSuccessEvents::mixpanelNames();
        $this->assertCount(7, $names);
        $this->assertContains('Support Ticket Created', $names);
        $this->assertContains('NPS Submitted', $names);
    }

    public function testCustomerSuccessEventsAmplitudeNames(): void
    {
        $names = CustomerSuccessEvents::amplitudeNames();
        $this->assertCount(7, $names);
    }

    public function testCustomerSuccessEventsTikTokNamesAreEmpty(): void
    {
        $names = CustomerSuccessEvents::tiktokNames();
        $this->assertEmpty($names);
    }

    public function testCustomerSuccessEventsLinkedInNamesAreEmpty(): void
    {
        $names = CustomerSuccessEvents::linkedinNames();
        $this->assertEmpty($names);
    }

    public function testCustomerSuccessEventsClassFor(): void
    {
        $this->assertNotNull(CustomerSuccessEvents::classFor('support_ticket_created'));
        $this->assertNotNull(CustomerSuccessEvents::classFor('nps_submitted'));
        $this->assertNull(CustomerSuccessEvents::classFor('nonexistent_event'));
    }

    public function testCustomerSuccessEventsGetReturnsNullForUnknown(): void
    {
        $this->assertNull(CustomerSuccessEvents::get('nonexistent_event'));
    }

    public function testCustomerSuccessEventsGetReturnsEntryForKnown(): void
    {
        $entry = CustomerSuccessEvents::get('support_ticket_created');
        $this->assertNotNull($entry);
        $this->assertSame('support_ticket_created', $entry['name']);
        $this->assertSame('support_ticket_created', $entry['ga4']);
        $this->assertSame('CustomEvent', $entry['meta']);
    }

    // ── EventCatalog Integration Tests ───────────────────────────────

    public function testEventCatalogAllIncludesCustomerSuccess(): void
    {
        $all = EventCatalog::all();
        $this->assertArrayHasKey('support_ticket_created', $all);
        $this->assertArrayHasKey('nps_submitted', $all);
        $this->assertArrayHasKey('health_score_changed', $all);
        $this->assertArrayHasKey('renewal_reminder_sent', $all);
        $this->assertArrayHasKey('churn_interview', $all);
        $this->assertArrayHasKey('customer_review', $all);
        $this->assertArrayHasKey('onboarding_call_completed', $all);
    }

    public function testEventCatalogCategoryIsCustomerSuccess(): void
    {
        $this->assertSame('customer_success', EventCatalog::getCategory('support_ticket_created'));
        $this->assertSame('customer_success', EventCatalog::getCategory('nps_submitted'));
        $this->assertSame('customer_success', EventCatalog::getCategory('health_score_changed'));
    }

    public function testEventCatalogHasCustomerSuccessEvents(): void
    {
        $this->assertTrue(EventCatalog::has('support_ticket_created'));
        $this->assertTrue(EventCatalog::has('nps_submitted'));
        $this->assertTrue(EventCatalog::has('churn_interview'));
        $this->assertFalse(EventCatalog::has('nonexistent_cs_event'));
    }

    public function testEventCatalogByCategoryIncludesCustomerSuccess(): void
    {
        $byCategory = EventCatalog::byCategory();
        $this->assertArrayHasKey('customer_success', $byCategory);
        $this->assertCount(7, $byCategory['customer_success']);
    }

    public function testEventCatalogCategoryMethodReturnsCustomerSuccess(): void
    {
        $events = EventCatalog::category('customer_success');
        $this->assertCount(7, $events);
        $this->assertArrayHasKey('support_ticket_created', $events);
    }

    public function testEventCatalogClassForResolvesCustomerSuccess(): void
    {
        $class = EventCatalog::classFor('support_ticket_created');
        $this->assertNotNull($class);
        $this->assertStringContainsString('SupportTicketCreatedEvent', $class);
    }

    public function testEventCatalogCountIncludesCustomerSuccess(): void
    {
        $count = EventCatalog::count();
        // 15 ecommerce + 65 saas + 43 engagement + 7 security + 5 uptime + 9 infra + 34 marketing + 7 cs = 185+
        $this->assertGreaterThan(180, $count);
    }

    public function testEventCatalogResolveFindsCustomerSuccessEvents(): void
    {
        $this->assertSame('support_ticket_created', EventCatalog::resolve('support_ticket_created'));
        $this->assertSame('nps_submitted', EventCatalog::resolve('nps_submitted'));
        $this->assertNull(EventCatalog::resolve('nonexistent'));
    }

    public function testEventCatalogAllGa4NamesIncludesCustomerSuccess(): void
    {
        $names = EventCatalog::allGa4Names();
        $this->assertContains('support_ticket_created', $names);
        $this->assertContains('nps_submitted', $names);
    }

    public function testEventCatalogAllMetaNamesIncludesCustomerSuccess(): void
    {
        $names = EventCatalog::allMetaNames();
        // All 7 CS events map to 'CustomEvent' in Meta
        $metaCount = count(array_filter($names, fn (string $n): bool => $n === 'CustomEvent'));
        $this->assertGreaterThanOrEqual(7, $metaCount);
    }

    public function testEventCatalogAllPosthogNamesIncludesCustomerSuccess(): void
    {
        $names = EventCatalog::allPosthogNames();
        $this->assertContains('support_ticket_created', $names);
        $this->assertContains('nps_submitted', $names);
    }

    public function testEventCatalogAllMixpanelNamesIncludesCustomerSuccess(): void
    {
        $names = EventCatalog::allMixpanelNames();
        $this->assertContains('Support Ticket Created', $names);
        $this->assertContains('NPS Submitted', $names);
    }

    public function testEventCatalogAllAmplitudeNamesIncludesCustomerSuccess(): void
    {
        $names = EventCatalog::allAmplitudeNames();
        $this->assertContains('Support Ticket Created', $names);
    }

    public function testEventCatalogSearchByCategoryCustomerSuccess(): void
    {
        $results = EventCatalog::searchByCategory('nps', 'customer_success');
        $this->assertNotEmpty($results);
        $found = array_filter($results, fn (array $e): bool => $e['name'] === 'nps_submitted');
        $this->assertNotEmpty($found);
    }

    public function testEventCatalogGetReturnsCustomerSuccessEntry(): void
    {
        $entry = EventCatalog::get('nps_submitted');
        $this->assertNotNull($entry);
        $this->assertSame('customer_success', $entry['category']);
        $this->assertSame('nps_submitted', $entry['ga4']);
        $this->assertSame('CustomEvent', $entry['meta']);
    }

    // ── EventTags Integration Tests ─────────────────────────────────

    public function testEventTagsForSupportTicketCreated(): void
    {
        $tags = EventTags::for('support_ticket_created');
        $this->assertContains('retention', $tags);
        $this->assertContains('b2b', $tags);
        $this->assertContains('enterprise', $tags);
        $this->assertContains('engagement', $tags);
    }

    public function testEventTagsForNpsSubmitted(): void
    {
        $tags = EventTags::for('nps_submitted');
        $this->assertContains('retention', $tags);
        $this->assertContains('pii', $tags);
    }

    public function testEventTagsForHealthScoreChanged(): void
    {
        $tags = EventTags::for('health_score_changed');
        $this->assertContains('critical', $tags);
        $this->assertContains('retention', $tags);
    }

    public function testEventTagsForRenewalReminderSent(): void
    {
        $tags = EventTags::for('renewal_reminder_sent');
        $this->assertContains('billing', $tags);
        $this->assertContains('revenue', $tags);
    }

    public function testEventTagsForChurnInterview(): void
    {
        $tags = EventTags::for('churn_interview');
        $this->assertContains('retention', $tags);
        $this->assertContains('pii', $tags);
    }

    public function testEventTagsForCustomerReview(): void
    {
        $tags = EventTags::for('customer_review');
        $this->assertContains('acquisition', $tags);
        $this->assertContains('b2b', $tags);
    }

    public function testEventTagsForOnboardingCallCompleted(): void
    {
        $tags = EventTags::for('onboarding_call_completed');
        $this->assertContains('onboarding', $tags);
        $this->assertContains('engagement', $tags);
    }

    public function testEventTagsTaggedReturnsCustomerSuccessEvents(): void
    {
        $retentionEvents = EventTags::tagged('retention');
        $this->assertContains('support_ticket_created', $retentionEvents);
        $this->assertContains('nps_submitted', $retentionEvents);
        $this->assertContains('health_score_changed', $retentionEvents);
        $this->assertContains('renewal_reminder_sent', $retentionEvents);
        $this->assertContains('churn_interview', $retentionEvents);
    }

    public function testEventTagsB2bReturnsCustomerSuccessEvents(): void
    {
        $b2bEvents = EventTags::tagged('b2b');
        $this->assertContains('support_ticket_created', $b2bEvents);
        $this->assertContains('nps_submitted', $b2bEvents);
        $this->assertContains('health_score_changed', $b2bEvents);
        $this->assertContains('churn_interview', $b2bEvents);
        $this->assertContains('customer_review', $b2bEvents);
        $this->assertContains('onboarding_call_completed', $b2bEvents);
    }
}
