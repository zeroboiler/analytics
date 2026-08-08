<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\SaaS\ExportEvent;
use ZeroBoiler\Analytics\Events\SaaS\ImportEvent;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Services\EventPriorityCalculator;

/**
 * V2.86.0 — Quick-Start, Privacy & Data Portability Upgrade Tests.
 *
 * Comprehensive test suite verifying:
 * - New ExportEvent and ImportEvent typed classes
 * - Quick-start event set (12 essential events)
 * - Privacy-safe events (no PII)
 * - GDPR-sensitive events (PII gating)
 * - SaaS acquisition and monetization event sets
 * - Funnel tracking convenience method
 * - Event catalog integrity with 92 total events
 * - AARRR classification for new events
 * - Version alignment (2.86.0)
 * - Provider mapping coverage
 */
describe('V2.86.0 — Quick-Start, Privacy & Data Portability', function () {
    it('has version 2.86.0 in DTO', function () {
        expect(AnalyticsEvent::VERSION)->toBe('2.86.0');
    });

    it('has 92+ events in the full catalog', function () {
        $total = EventCatalog::count();
        expect($total)->toBeGreaterThanOrEqual(92);
    });

    it('has 50+ SaaS events (added export + import)', function () {
        expect(SaaSEvents::count())->toBeGreaterThanOrEqual(50);
    });

    it('category counts sum to total', function () {
        $total = EventCatalog::count();
        $sum = EcommerceEvents::count() + SaaSEvents::count() + EngagementEvents::count();
        expect($sum)->toBe($total);
    });

    // ── ExportEvent ────────────────────────────────────────────────────────

    describe('ExportEvent', function () {
        it('creates with format only', function () {
            $event = new ExportEvent('csv');
            expect($event->name)->toBe('export');
            expect($event->params)->toHaveKey('format');
            expect($event->params['format'])->toBe('csv');
        });

        it('creates with all parameters', function () {
            $event = new ExportEvent('json', 'reports', 150);
            expect($event->params['format'])->toBe('json');
            expect($event->params['resource'])->toBe('reports');
            expect($event->params['record_count'])->toBe(150);
        });

        it('creates with extra params', function () {
            $event = new ExportEvent('pdf', 'invoices', 50, ['page_count' => 12]);
            expect($event->params['page_count'])->toBe(12);
        });

        it('filters null values', function () {
            $event = new ExportEvent('csv', null, null);
            expect($event->params)->not->toHaveKey('resource');
            expect($event->params)->not->toHaveKey('record_count');
        });

        it('is registered in SaaSEvents catalog', function () {
            expect(SaaSEvents::has('export'))->toBeTrue();
            $entry = SaaSEvents::get('export');
            expect($entry['class'])->toBe(ExportEvent::class);
            expect($entry['ga4'])->toBe('file_download');
            expect($entry['meta'])->toBe('ExportData');
            expect($entry['posthog'])->toBe('export');
        });
    });

    // ── ImportEvent ────────────────────────────────────────────────────────

    describe('ImportEvent', function () {
        it('creates with format only', function () {
            $event = new ImportEvent('csv');
            expect($event->name)->toBe('import');
            expect($event->params['format'])->toBe('csv');
        });

        it('creates with all parameters', function () {
            $event = new ImportEvent('xlsx', 'contacts', 500, true);
            expect($event->params['format'])->toBe('xlsx');
            expect($event->params['resource'])->toBe('contacts');
            expect($event->params['record_count'])->toBe(500);
            expect($event->params['success'])->toBe(true);
        });

        it('creates with failed import', function () {
            $event = new ImportEvent('csv', 'products', 0, false, ['error' => 'invalid_format']);
            expect($event->params['success'])->toBe(false);
            expect($event->params['error'])->toBe('invalid_format');
        });

        it('filters null values', function () {
            $event = new ImportEvent('csv', null, null, null);
            expect($event->params)->not->toHaveKey('resource');
            expect($event->params)->not->toHaveKey('record_count');
            expect($event->params)->not->toHaveKey('success');
        });

        it('is registered in SaaSEvents catalog', function () {
            expect(SaaSEvents::has('import'))->toBeTrue();
            $entry = SaaSEvents::get('import');
            expect($entry['class'])->toBe(ImportEvent::class);
            expect($entry['ga4'])->toBe('file_upload');
            expect($entry['meta'])->toBe('ImportData');
            expect($entry['posthog'])->toBe('import');
        });
    });

    // ── Quick-Start Event Set ─────────────────────────────────────────────

    describe('quickStart()', function () {
        it('returns the quick-start event set', function () {
            $quickStart = EventCatalog::quickStart();
            expect($quickStart)->toHaveKey('events');
            expect($quickStart)->toHaveKey('count');
            expect($quickStart)->toHaveKey('categories');
            expect($quickStart)->toHaveKey('funnel_coverage');
        });

        it('has 12 events', function () {
            expect(EventCatalog::quickStart()['count'])->toBe(12);
        });

        it('covers all four funnel stages', function () {
            $coverage = EventCatalog::quickStart()['funnel_coverage'];
            expect($coverage['signup'])->toBeTrue();
            expect($coverage['trial'])->toBeTrue();
            expect($coverage['revenue'])->toBeTrue();
            expect($coverage['engagement'])->toBeTrue();
        });

        it('includes essential SaaS events', function () {
            $names = array_column(EventCatalog::quickStart()['events'], 'name');
            expect($names)->toContain('sign_up');
            expect($names)->toContain('login');
            expect($names)->toContain('start_trial');
            expect($names)->toContain('subscribe');
            expect($names)->toContain('purchase');
            expect($names)->toContain('cancellation');
            expect($names)->toContain('page_view');
            expect($names)->toContain('feature_used');
        });

        it('has category breakdown', function () {
            $categories = EventCatalog::quickStart()['categories'];
            expect($categories)->toHaveKey('ecommerce');
            expect($categories)->toHaveKey('saas');
            expect($categories)->toHaveKey('engagement');
        });
    });

    // ── Privacy-Safe Events ───────────────────────────────────────────────

    describe('privacySafeEvents()', function () {
        it('returns a non-empty list', function () {
            $safe = EventCatalog::privacySafeEvents();
            expect($safe)->not->toBeEmpty();
        });

        it('contains only behavioral events', function () {
            $names = array_column(EventCatalog::privacySafeEvents(), 'name');
            expect($names)->toContain('page_view');
            expect($names)->toContain('scroll_depth');
            expect($names)->toContain('search');
            expect($names)->toContain('web_vitals');
        });

        it('excludes PII-heavy events', function () {
            $names = array_column(EventCatalog::privacySafeEvents(), 'name');
            expect($names)->not->toContain('sign_up');
            expect($names)->not->toContain('login');
            expect($names)->not->toContain('payment_succeeded');
        });
    });

    // ── GDPR-Sensitive Events ───────────────────────────────────────────

    describe('gdprSensitiveEvents()', function () {
        it('returns a non-empty list', function () {
            $sensitive = EventCatalog::gdprSensitiveEvents();
            expect($sensitive)->not->toBeEmpty();
        });

        it('contains authentication events', function () {
            $names = array_column(EventCatalog::gdprSensitiveEvents(), 'name');
            expect($names)->toContain('sign_up');
            expect($names)->toContain('login');
            expect($names)->toContain('password_changed');
        });

        it('contains billing events', function () {
            $names = array_column(EventCatalog::gdprSensitiveEvents(), 'name');
            expect($names)->toContain('payment_succeeded');
            expect($names)->toContain('payment_failed');
            expect($names)->toContain('invoice_generated');
        });
    });

    // ── SaaS Acquisition Events ───────────────────────────────────────────

    describe('saasAcquisitionEvents()', function () {
        it('returns acquisition-focused events', function () {
            $events = EventCatalog::saasAcquisitionEvents();
            $names = array_column($events, 'name');
            expect($names)->toContain('sign_up');
            expect($names)->toContain('start_trial');
            expect($names)->toContain('share');
            expect($names)->not->toBeEmpty();
        });
    });

    // ── SaaS Monetization Events ─────────────────────────────────────────

    describe('saasMonetizationEvents()', function () {
        it('returns revenue-focused events', function () {
            $events = EventCatalog::saasMonetizationEvents();
            $names = array_column($events, 'name');
            expect($names)->toContain('purchase');
            expect($names)->toContain('subscribe');
            expect($names)->toContain('plan_upgrade');
            expect($names)->toContain('expansion_revenue');
            expect($names)->not->toBeEmpty();
        });

        it('has 20+ monetization events', function () {
            expect(count(EventCatalog::saasMonetizationEvents()))->toBeGreaterThanOrEqual(20);
        });
    });

    // ── AARRR Classification ──────────────────────────────────────────────

    describe('AARRR classification', function () {
        it('classifies export as operational', function () {
            $calculator = new EventPriorityCalculator;
            expect($calculator->classify('export'))->toBe('operational');
        });

        it('classifies import as activation', function () {
            $calculator = new EventPriorityCalculator;
            expect($calculator->classify('import'))->toBe('activation');
        });
    });

    // ── Catalog Integrity ────────────────────────────────────────────────

    describe('catalog integrity', function () {
        it('all events have required keys', function () {
            $validation = EventCatalog::validate();
            expect($validation['valid'])->toBeTrue();
            expect($validation['errors'])->toBeEmpty();
        });

        it('every event has GA4 mapping', function () {
            $all = EventCatalog::all();
            foreach ($all as $name => $entry) {
                expect(isset($entry['ga4']))->toBeTrue("Event {$name} missing GA4 mapping");
            }
        });

        it('every event has PostHog mapping', function () {
            $all = EventCatalog::all();
            foreach ($all as $name => $entry) {
                expect(isset($entry['posthog']))->toBeTrue("Event {$name} missing PostHog mapping");
            }
        });

        it('export and import have proper provider mappings', function () {
            $export = EventCatalog::get('export');
            expect($export['ga4'])->toBe('file_download');
            expect($export['meta'])->toBe('ExportData');
            expect($export['posthog'])->toBe('export');

            $import = EventCatalog::get('import');
            expect($import['ga4'])->toBe('file_upload');
            expect($import['meta'])->toBe('ImportData');
            expect($import['posthog'])->toBe('import');
        });

        it('new events are included in product growth events', function () {
            $names = array_column(EventCatalog::productGrowthEvents(), 'name');
            expect($names)->toContain('export');
            expect($names)->toContain('import');
        });

        it('new events are included in all lifecycle events', function () {
            $names = array_column(EventCatalog::allLifecycleEvents(), 'name');
            expect($names)->toContain('export');
            expect($names)->toContain('import');
        });

        it('new events are included in saas essential', function () {
            $names = array_column(EventCatalog::saasEssential()['events'], 'name');
            expect($names)->toContain('export');
            expect($names)->toContain('import');
        });

        it('new events are in industry standard medium tier', function () {
            $standard = EventCatalog::industryStandard();
            $mediumNames = array_column($standard['medium'], 'name');
            expect($mediumNames)->toContain('export');
            expect($mediumNames)->toContain('import');
        });

        it('new events are in enterprise recommended instrumentation', function () {
            $rec = EventCatalog::recommendedInstrumentation('enterprise');
            $names = array_column($rec['events'], 'name');
            expect($names)->toContain('export');
            expect($names)->toContain('import');
        });
    });

    // ── Industry Standard Readiness ───────────────────────────────────────

    describe('industry readiness', function () {
        it('readiness score is 100', function () {
            $score = EventCatalog::industryReadinessScore();
            expect($score['score'])->toBe(100);
            expect($score['gaps'])->toBeEmpty();
        });
    });
});
