<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Marketing\MarketingEvents;
use ZeroBoiler\Analytics\Events\Marketing\MarketingEventConstants;

/**
 * Verifies Marketing events are fully represented across PHP, JS, and TypeScript.
 *
 * Ensures the 34 marketing event constants match between PHP MarketingEventConstants,
 * JS analytics.constants.js, and analytics.d.ts type definitions.
 *
 * @since 132.0.0
 */
final class V132MarketingEventsClientParityTest extends TestCase
{
    // ── Version Consistency ──────────────────────────────────────────

    public function test_version_is_132(): void
    {
        $this->assertSame('132.0.0', AnalyticsEvent::VERSION);
    }

    // ── Marketing Events Catalog Count ───────────────────────────────

    public function test_marketing_events_catalog_has_34_events(): void
    {
        $this->assertSame(34, MarketingEvents::count());
    }

    public function test_marketing_event_constants_has_34_constants(): void
    {
        $this->assertSame(34, MarketingEventConstants::count());
    }

    public function test_catalog_and_constants_have_same_count(): void
    {
        $this->assertSame(
            MarketingEvents::count(),
            MarketingEventConstants::count(),
            'MarketingEvents catalog and MarketingEventConstants must have the same number of entries.',
        );
    }

    // ── Core Marketing Events Present ─────────────────────────────────

    public function test_email_marketing_events_exist(): void
    {
        $emailEvents = [
            'email_sent', 'email_delivered', 'email_opened', 'email_clicked',
            'email_bounced', 'email_unsubscribed', 'email_marked_spam',
        ];

        foreach ($emailEvents as $name) {
            $this->assertTrue(
                MarketingEvents::has($name),
                "Missing email marketing event: {$name}",
            );
            $this->assertTrue(
                MarketingEventConstants::isValid($name),
                "Missing email marketing constant: {$name}",
            );
        }
    }

    public function test_lead_generation_events_exist(): void
    {
        $leadEvents = ['lead_captured', 'lead_qualified', 'lead_score_changed'];

        foreach ($leadEvents as $name) {
            $this->assertTrue(MarketingEvents::has($name), "Missing lead event: {$name}");
            $this->assertTrue(MarketingEventConstants::isValid($name), "Missing lead constant: {$name}");
        }
    }

    public function test_content_marketing_events_exist(): void
    {
        $contentEvents = ['blog_view', 'content_downloaded', 'newsletter_subscribed'];

        foreach ($contentEvents as $name) {
            $this->assertTrue(MarketingEvents::has($name), "Missing content event: {$name}");
            $this->assertTrue(MarketingEventConstants::isValid($name), "Missing content constant: {$name}");
        }
    }

    public function test_social_media_events_exist(): void
    {
        $socialEvents = ['social_share', 'social_follow', 'social_comment', 'social_mention'];

        foreach ($socialEvents as $name) {
            $this->assertTrue(MarketingEvents::has($name), "Missing social event: {$name}");
            $this->assertTrue(MarketingEventConstants::isValid($name), "Missing social constant: {$name}");
        }
    }

    public function test_paid_advertising_events_exist(): void
    {
        $adEvents = ['ad_impression', 'ad_click', 'ad_conversion'];

        foreach ($adEvents as $name) {
            $this->assertTrue(MarketingEvents::has($name), "Missing ad event: {$name}");
            $this->assertTrue(MarketingEventConstants::isValid($name), "Missing ad constant: {$name}");
        }
    }

    public function test_webinar_events_exist(): void
    {
        $webinarEvents = ['webinar_registered', 'webinar_attended', 'webinar_engagement'];

        foreach ($webinarEvents as $name) {
            $this->assertTrue(MarketingEvents::has($name), "Missing webinar event: {$name}");
            $this->assertTrue(MarketingEventConstants::isValid($name), "Missing webinar constant: {$name}");
        }
    }

    public function test_sms_push_events_exist(): void
    {
        $smsEvents = [
            'sms_sent', 'sms_delivered', 'sms_clicked',
            'push_notification_sent', 'push_notification_opened',
        ];

        foreach ($smsEvents as $name) {
            $this->assertTrue(MarketingEvents::has($name), "Missing SMS/push event: {$name}");
            $this->assertTrue(MarketingEventConstants::isValid($name), "Missing SMS/push constant: {$name}");
        }
    }

    public function test_affiliate_referral_events_exist(): void
    {
        $affiliateEvents = [
            'referral_link_shared', 'referral_conversion',
            'affiliate_signup', 'affiliate_commission',
        ];

        foreach ($affiliateEvents as $name) {
            $this->assertTrue(MarketingEvents::has($name), "Missing affiliate event: {$name}");
            $this->assertTrue(MarketingEventConstants::isValid($name), "Missing affiliate constant: {$name}");
        }
    }

    public function test_attribution_events_exist(): void
    {
        $attributionEvents = ['attribution_touchpoint', 'campaign_response'];

        foreach ($attributionEvents as $name) {
            $this->assertTrue(MarketingEvents::has($name), "Missing attribution event: {$name}");
            $this->assertTrue(MarketingEventConstants::isValid($name), "Missing attribution constant: {$name}");
        }
    }

    // ── Cross-Provider Mapping Coverage ───────────────────────────────

    public function test_marketing_events_have_ga4_mapping(): void
    {
        $all = MarketingEvents::all();
        foreach ($all as $name => $entry) {
            $this->assertNotEmpty($entry['ga4'], "{$name} missing GA4 mapping");
        }
    }

    public function test_marketing_events_have_posthog_mapping(): void
    {
        $all = MarketingEvents::all();
        foreach ($all as $name => $entry) {
            $this->assertNotEmpty($entry['posthog'], "{$name} missing PostHog mapping");
        }
    }

    public function test_marketing_events_have_mixpanel_mapping(): void
    {
        $all = MarketingEvents::all();
        foreach ($all as $name => $entry) {
            $this->assertNotEmpty($entry['mixpanel'], "{$name} missing Mixpanel mapping");
        }
    }

    public function test_marketing_events_have_amplitude_mapping(): void
    {
        $all = MarketingEvents::all();
        foreach ($all as $name => $entry) {
            $this->assertNotEmpty($entry['amplitude'], "{$name} missing Amplitude mapping");
        }
    }

    // ── JS/TS Parity Verification ────────────────────────────────────

    /**
     * Verify that the MarketingEvents JS export has the exact same event names
     * as the PHP MarketingEventConstants. This test ensures JS/TS parity by
     * checking that the JS constants file contains all 28 marketing event names.
     *
     * The JS file (resources/js/analytics.constants.js) must export a
     * MarketingEvents frozen object with identical keys to PHP constants.
     */
    public function test_js_marketing_events_constants_file_contains_all_28_events(): void
    {
        $jsConstantsPath = __DIR__ . '/../../resources/js/analytics.constants.js';
        $this->assertFileExists($jsConstantsPath);

        $jsContent = file_get_contents($jsConstantsPath);
        $phpConstants = MarketingEventConstants::names();

        foreach ($phpConstants as $name) {
            $this->assertStringContainsString(
                $name,
                $jsContent,
                "JS analytics.constants.js missing marketing event: {$name}",
            );
        }
    }

    /**
     * Verify the JS MarketingEvents constant is exported and frozen.
     */
    public function test_js_marketing_events_export_exists(): void
    {
        $jsConstantsPath = __DIR__ . '/../../resources/js/analytics.constants.js';
        $jsContent = file_get_contents($jsConstantsPath);

        $this->assertStringContainsString('export const MarketingEvents', $jsContent);
        $this->assertStringContainsString('Object.freeze(MarketingEvents)', $jsContent);
    }

    /**
     * Verify AllEventNames includes MarketingEvents in JS.
     */
    public function test_js_all_event_names_includes_marketing(): void
    {
        $jsConstantsPath = __DIR__ . '/../../resources/js/analytics.constants.js';
        $jsContent = file_get_contents($jsConstantsPath);

        // Check AllEventNames spread includes MarketingEvents
        $this->assertStringContainsString('...MarketingEvents,', $jsContent);
    }

    /**
     * Verify getCategoryNames includes 'marketing' in JS.
     */
    public function test_js_get_category_names_includes_marketing(): void
    {
        $jsConstantsPath = __DIR__ . '/../../resources/js/analytics.constants.js';
        $jsContent = file_get_contents($jsConstantsPath);

        $this->assertStringContainsString("'marketing'", $jsContent);
    }

    /**
     * Verify getEventNamesByCategory includes marketing case in JS.
     */
    public function test_js_get_event_names_by_category_includes_marketing_case(): void
    {
        $jsConstantsPath = __DIR__ . '/../../resources/js/analytics.constants.js';
        $jsContent = file_get_contents($jsConstantsPath);

        $this->assertStringContainsString("case 'marketing'", $jsContent);
    }

    /**
     * Verify TypeScript type definitions include MarketingEvents.
     */
    public function test_ts_type_definitions_include_marketing_events(): void
    {
        $tsPath = __DIR__ . '/../../resources/js/analytics.d.ts';
        $this->assertFileExists($tsPath);

        $tsContent = file_get_contents($tsPath);

        $this->assertStringContainsString('export const MarketingEvents: Readonly<{', $tsContent);
        $this->assertStringContainsString('typeof MarketingEvents &', $tsContent);
        $this->assertStringContainsString('MarketingEventName', $tsContent);
        $this->assertStringContainsString("'marketing'", $tsContent);
    }

    /**
     * Verify TypeScript MarketingEvents has all 34 event type members.
     */
    public function test_ts_marketing_events_has_all_event_types(): void
    {
        $tsPath = __DIR__ . '/../../resources/js/analytics.d.ts';
        $tsContent = file_get_contents($tsPath);

        $phpConstants = MarketingEventConstants::names();
        foreach ($phpConstants as $name) {
            // Convert snake_case event name to UPPER_SNAKE_CASE constant name
            $constantName = strtoupper($name);
            $this->assertStringContainsString(
                $constantName,
                $tsContent,
                "TypeScript analytics.d.ts missing marketing event type: {$constantName}",
            );
        }
    }

    // ── Catalog Structure ─────────────────────────────────────────────

    public function test_marketing_catalog_entries_have_required_keys(): void
    {
        $requiredKeys = ['name', 'class', 'ga4', 'meta', 'posthog', 'plausible', 'mixpanel', 'amplitude', 'tiktok', 'linkedin'];

        foreach (MarketingEvents::all() as $eventName => $entry) {
            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $entry,
                    "Marketing event '{$eventName}' missing required key: {$key}",
                );
            }
        }
    }

    public function test_marketing_catalog_class_references_exist(): void
    {
        foreach (MarketingEvents::all() as $eventName => $entry) {
            $this->assertTrue(
                class_exists($entry['class']),
                "Marketing event '{$eventName}' references non-existent class: {$entry['class']}",
            );
        }
    }

    // ── JS Version Consistency ─────────────────────────────────────────

    public function test_js_constants_version_is_132(): void
    {
        $jsConstantsPath = __DIR__ . '/../../resources/js/analytics.constants.js';
        $jsContent = file_get_contents($jsConstantsPath);
        $this->assertStringContainsString('@version 132.0.0', $jsContent);
    }

    public function test_ts_definitions_version_is_132(): void
    {
        $tsPath = __DIR__ . '/../../resources/js/analytics.d.ts';
        $tsContent = file_get_contents($tsPath);
        $this->assertStringContainsString('@version 132.0.0', $tsContent);
    }
}
