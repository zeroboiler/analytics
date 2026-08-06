<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Engagement\WebVitalsEvent;
use ZeroBoiler\Analytics\Events\Engagement\JSErrorEvent;
use ZeroBoiler\Analytics\Events\Engagement\TimingEvent;
use ZeroBoiler\Analytics\Events\Engagement\SessionStartEvent;
use ZeroBoiler\Analytics\Events\Engagement\SessionEndEvent;
use ZeroBoiler\Analytics\Events\Engagement\OutboundClickEvent;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;

beforeEach(function (): void {
    // Reset the static catalog cache between tests
    $ref = new ReflectionProperty(EngagementEvents::class, 'catalog');
    $ref->setAccessible(true);
    $ref->setValue(null, []);
});

describe('v2.11 — Client Auto-Tracking & Performance Events', function (): void {
    describe('WebVitalsEvent', function (): void {
        it('creates event with required params', function (): void {
            $event = new WebVitalsEvent('LCP', 2500.0);

            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('web_vitals');
            expect($event->params)->toHaveKey('metric_name');
            expect($event->params['metric_name'])->toBe('LCP');
            expect($event->params['metric_value'])->toBe(2500.0);
        });

        it('creates event with all params including rating', function (): void {
            $event = new WebVitalsEvent(
                metricName: 'CLS',
                value: 0.15,
                rating: 'needs-improvement',
                pagePath: '/dashboard',
                navigationType: 'navigate',
            );

            expect($event->name)->toBe('web_vitals');
            expect($event->params['metric_name'])->toBe('CLS');
            expect($event->params['metric_value'])->toBe(0.15);
            expect($event->params['rating'])->toBe('needs-improvement');
            expect($event->params['page_path'])->toBe('/dashboard');
            expect($event->params['navigation_type'])->toBe('navigate');
        });

        it('filters out null values', function (): void {
            $event = new WebVitalsEvent('TTFB', 800.0, null, null, null);

            expect($event->params)->not->toHaveKey('rating');
            expect($event->params)->not->toHaveKey('page_path');
            expect($event->params)->not->toHaveKey('navigation_type');
        });

        it('is in the engagement catalog', function (): void {
            expect(EngagementEvents::has('web_vitals'))->toBeTrue();
            expect(EngagementEvents::classFor('web_vitals'))->toBe(WebVitalsEvent::class);
        });

        it('is in the unified event catalog', function (): void {
            expect(EventCatalog::has('web_vitals'))->toBeTrue();
            $entry = EventCatalog::get('web_vitals');
            expect($entry)->not->toBeNull();
            expect($entry['category'])->toBe('engagement');
            expect($entry['ga4'])->toBe('web_vitals');
        });
    });

    describe('JSErrorEvent', function (): void {
        it('creates event with error message', function (): void {
            $event = new JSErrorEvent('TypeError: Cannot read property of undefined');

            expect($event->name)->toBe('js_error');
            expect($event->params['error_message'])->toBe('TypeError: Cannot read property of undefined');
        });

        it('creates event with full context', function (): void {
            $event = new JSErrorEvent(
                message: 'Uncaught ReferenceError: foo is not defined',
                source: 'https://app.example.com/assets/app.js',
                line: 42,
                col: 15,
                errorType: 'unhandled',
                pagePath: '/pricing',
                fatal: true,
            );

            expect($event->params['error_message'])->toBe('Uncaught ReferenceError: foo is not defined');
            expect($event->params['error_source'])->toBe('https://app.example.com/assets/app.js');
            expect($event->params['error_line'])->toBe(42);
            expect($event->params['error_col'])->toBe(15);
            expect($event->params['error_type'])->toBe('unhandled');
            expect($event->params['page_path'])->toBe('/pricing');
            expect($event->params['fatal'])->toBeTrue();
        });

        it('is in the engagement catalog', function (): void {
            expect(EngagementEvents::has('js_error'))->toBeTrue();
            expect(EngagementEvents::classFor('js_error'))->toBe(JSErrorEvent::class);
        });

        it('is in the unified event catalog', function (): void {
            expect(EventCatalog::has('js_error'))->toBeTrue();
        });
    });

    describe('TimingEvent', function (): void {
        it('creates event with name and duration', function (): void {
            $event = new TimingEvent('api_request', 350);

            expect($event->name)->toBe('timing');
            expect($event->params['timing_name'])->toBe('api_request');
            expect($event->params['timing_duration_ms'])->toBe(350);
        });

        it('creates event with category and page', function (): void {
            $event = new TimingEvent('render_component', 120, 'render', '/dashboard');

            expect($event->params['timing_category'])->toBe('render');
            expect($event->params['page_path'])->toBe('/dashboard');
        });

        it('filters out null params', function (): void {
            $event = new TimingEvent('simple', 50);

            expect($event->params)->not->toHaveKey('timing_category');
            expect($event->params)->not->toHaveKey('page_path');
        });

        it('is in the engagement catalog', function (): void {
            expect(EngagementEvents::has('timing'))->toBeTrue();
            expect(EngagementEvents::classFor('timing'))->toBe(TimingEvent::class);
        });

        it('is in the unified event catalog', function (): void {
            expect(EventCatalog::has('timing'))->toBeTrue();
        });
    });

    describe('SessionStartEvent', function (): void {
        it('creates event with session ID and page', function (): void {
            $event = new SessionStartEvent(
                sessionId: 'abc12345',
                pagePath: '/pricing',
                referrer: 'https://google.com',
                source: 'organic',
            );

            expect($event->name)->toBe('session_start');
            expect($event->params['session_id'])->toBe('abc12345');
            expect($event->params['page_path'])->toBe('/pricing');
            expect($event->params['referrer'])->toBe('https://google.com');
            expect($event->params['source'])->toBe('organic');
        });

        it('creates minimal event', function (): void {
            $event = new SessionStartEvent();

            expect($event->name)->toBe('session_start');
            expect($event->params)->toBe([]);
        });

        it('is in the engagement catalog', function (): void {
            expect(EngagementEvents::has('session_start'))->toBeTrue();
            expect(EngagementEvents::classFor('session_start'))->toBe(SessionStartEvent::class);
        });

        it('is in the unified event catalog', function (): void {
            expect(EventCatalog::has('session_start'))->toBeTrue();
        });
    });

    describe('SessionEndEvent', function (): void {
        it('creates event with full session summary', function (): void {
            $event = new SessionEndEvent(
                sessionId: 'abc12345',
                durationSeconds: 300,
                eventCount: 15,
                pageViewCount: 4,
                exitPage: '/checkout',
                endReason: 'visibility',
            );

            expect($event->name)->toBe('session_end');
            expect($event->params['session_id'])->toBe('abc12345');
            expect($event->params['duration_seconds'])->toBe(300);
            expect($event->params['event_count'])->toBe(15);
            expect($event->params['page_view_count'])->toBe(4);
            expect($event->params['exit_page'])->toBe('/checkout');
            expect($event->params['end_reason'])->toBe('visibility');
        });

        it('creates minimal event', function (): void {
            $event = new SessionEndEvent();

            expect($event->name)->toBe('session_end');
            expect($event->params)->toBe([]);
        });

        it('is in the engagement catalog', function (): void {
            expect(EngagementEvents::has('session_end'))->toBeTrue();
            expect(EngagementEvents::classFor('session_end'))->toBe(SessionEndEvent::class);
        });

        it('is in the unified event catalog', function (): void {
            expect(EventCatalog::has('session_end'))->toBeTrue();
        });
    });

    describe('OutboundClickEvent', function (): void {
        it('creates event with link URL and text', function (): void {
            $event = new OutboundClickEvent(
                linkUrl: 'https://docs.example.com/guide',
                linkText: 'Documentation',
            );

            expect($event->name)->toBe('outbound_click');
            expect($event->params['link_url'])->toBe('https://docs.example.com/guide');
            expect($event->params['link_text'])->toBe('Documentation');
        });

        it('creates event with custom link name', function (): void {
            $event = new OutboundClickEvent(
                linkUrl: 'https://twitter.com/share',
                linkText: 'Share on Twitter',
                linkName: 'social_share',
                pagePath: '/blog/post-1',
            );

            expect($event->params['link_name'])->toBe('social_share');
            expect($event->params['page_path'])->toBe('/blog/post-1');
        });

        it('filters out null values', function (): void {
            $event = new OutboundClickEvent('https://example.com');

            expect($event->params)->not->toHaveKey('link_name');
            expect($event->params)->not->toHaveKey('page_path');
        });

        it('is in the engagement catalog', function (): void {
            expect(EngagementEvents::has('outbound_click'))->toBeTrue();
            expect(EngagementEvents::classFor('outbound_click'))->toBe(OutboundClickEvent::class);
        });

        it('is in the unified event catalog', function (): void {
            expect(EventCatalog::has('outbound_click'))->toBeTrue();
        });
    });

    describe('Engagement catalog expansion', function (): void {
        it('has the correct total count after v2.11 additions', function (): void {
            // Original 13 events + 6 new = 19
            expect(EngagementEvents::count())->toBe(19);
        });

        it('includes all new event names', function (): void {
            $names = EngagementEvents::names();

            expect($names)->toContain('web_vitals');
            expect($names)->toContain('js_error');
            expect($names)->toContain('timing');
            expect($names)->toContain('session_start');
            expect($names)->toContain('session_end');
            expect($names)->toContain('outbound_click');
        });

        it('all new events have GA4 mappings', function (): void {
            $newEvents = ['web_vitals', 'js_error', 'timing', 'session_start', 'session_end', 'outbound_click'];

            foreach ($newEvents as $name) {
                $entry = EngagementEvents::get($name);
                expect($entry)->not->toBeNull();
                expect($entry['ga4'])->toBe($name);
            }
        });

        it('all new events are readonly', function (): void {
            $classes = [
                WebVitalsEvent::class,
                JSErrorEvent::class,
                TimingEvent::class,
                SessionStartEvent::class,
                SessionEndEvent::class,
                OutboundClickEvent::class,
            ];

            foreach ($classes as $class) {
                $ref = new ReflectionClass($class);
                expect($ref->isReadOnly())->toBeTrue();
            }
        });
    });

    describe('Event catalog integration', function (): void {
        it('total event count increased correctly', function (): void {
            // Ecommerce (9) + SaaS (17) + Engagement (19) = 45
            expect(EventCatalog::count())->toBe(45);
        });

        it('search finds new events', function (): void {
            $results = EventCatalog::search('session');
            $names = array_map(fn (array $e): string => $e['name'], $results);

            expect($names)->toContain('session_start');
            expect($names)->toContain('session_end');
        });

        it('search finds performance events', function (): void {
            $results = EventCatalog::search('vitals');
            expect(count($results))->toBeGreaterThanOrEqual(1);
            expect($results[0]['name'])->toBe('web_vitals');
        });

        it('byCategory returns engagement with new events', function (): void {
            $categories = EventCatalog::byCategory();
            $engagement = $categories['engagement'];

            expect($engagement)->toHaveKey('web_vitals');
            expect($engagement)->toHaveKey('session_start');
            expect($engagement)->toHaveKey('session_end');
            expect($engagement)->toHaveKey('outbound_click');
        });

        it('all new event classes extend AnalyticsEvent', function (): void {
            $classes = [
                WebVitalsEvent::class,
                JSErrorEvent::class,
                TimingEvent::class,
                SessionStartEvent::class,
                SessionEndEvent::class,
                OutboundClickEvent::class,
            ];

            foreach ($classes as $class) {
                $ref = new ReflectionClass($class);
                expect($ref->isSubclassOf(AnalyticsEvent::class))->toBeTrue();
            }
        });
    });
});
