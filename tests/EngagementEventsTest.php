<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\CustomEvent;
use ZeroBoiler\Analytics\Events\Engagement\ClickEvent;
use ZeroBoiler\Analytics\Events\Engagement\ErrorEvent;
use ZeroBoiler\Analytics\Events\Engagement\FormStartEvent;
use ZeroBoiler\Analytics\Events\Engagement\FormSubmitEvent;
use ZeroBoiler\Analytics\Events\Engagement\PageViewEvent;
use ZeroBoiler\Analytics\Events\Engagement\ScrollDepthEvent;
use ZeroBoiler\Analytics\Events\Engagement\SearchEvent;
use ZeroBoiler\Analytics\Events\Engagement\ShareEvent;
use ZeroBoiler\Analytics\Events\Engagement\TimeOnPageEvent;

describe('Engagement Events', function () {
    describe('PageViewEvent', function () {
        it('creates with all parameters', function () {
            $event = new PageViewEvent(
                pageTitle: 'Home',
                pageLocation: 'https://example.com/',
                pageReferrer: 'https://google.com',
            );

            expect($event->name)->toBe('page_view');
            expect($event->params)->toHaveKey('page_title');
            expect($event->params['page_title'])->toBe('Home');
            expect($event->params['page_location'])->toBe('https://example.com/');
            expect($event->params['page_referrer'])->toBe('https://google.com');
        });

        it('creates with empty parameters', function () {
            $event = new PageViewEvent;

            expect($event->name)->toBe('page_view');
            expect($event->params)->toBeEmpty();
        });
    });

    describe('ScrollDepthEvent', function () {
        it('tracks scroll depth percentage', function () {
            $event = new ScrollDepthEvent(
                percent: 75,
                pagePath: '/blog/article-1',
                pageTitle: 'My Article',
            );

            expect($event->name)->toBe('scroll_depth');
            expect($event->params['percent'])->toBe(75);
            expect($event->params['page_path'])->toBe('/blog/article-1');
            expect($event->params['page_title'])->toBe('My Article');
        });

        it('filters null page title', function () {
            $event = new ScrollDepthEvent(percent: 50, pagePath: '/test');

            expect($event->params)->not->toHaveKey('page_title');
            expect($event->params['percent'])->toBe(50);
        });
    });

    describe('ClickEvent', function () {
        it('tracks button clicks', function () {
            $event = new ClickEvent(
                elementText: 'Buy Now',
                elementType: 'button',
                elementId: 'buy-btn',
                elementClass: 'btn-primary',
                targetUrl: '/checkout',
            );

            expect($event->name)->toBe('click');
            expect($event->params['element_text'])->toBe('Buy Now');
            expect($event->params['element_type'])->toBe('button');
            expect($event->params['element_id'])->toBe('buy-btn');
            expect($event->params['element_class'])->toBe('btn-primary');
            expect($event->params['target_url'])->toBe('/checkout');
        });

        it('defaults to button type', function () {
            $event = new ClickEvent(elementText: 'Click me');

            expect($event->params['element_type'])->toBe('button');
        });
    });

    describe('FormStartEvent', function () {
        it('tracks form start', function () {
            $event = new FormStartEvent(
                formName: 'Contact Form',
                formId: 'contact-form',
                formDestination: '/api/contact',
            );

            expect($event->name)->toBe('form_start');
            expect($event->params['form_name'])->toBe('Contact Form');
            expect($event->params['form_id'])->toBe('contact-form');
            expect($event->params['form_destination'])->toBe('/api/contact');
        });
    });

    describe('FormSubmitEvent', function () {
        it('tracks form submission with value', function () {
            $event = new FormSubmitEvent(
                formName: 'Lead Form',
                formId: 'lead-form',
                value: 49.99,
                currency: 'USD',
            );

            expect($event->name)->toBe('form_submit');
            expect($event->params['form_name'])->toBe('Lead Form');
            expect($event->params['value'])->toBe(49.99);
            expect($event->params['currency'])->toBe('USD');
        });

        it('filters null optional params', function () {
            $event = new FormSubmitEvent(formName: 'Simple');

            expect($event->params)->not->toHaveKey('value');
            expect($event->params)->not->toHaveKey('currency');
        });
    });

    describe('SearchEvent', function () {
        it('tracks search queries', function () {
            $event = new SearchEvent(
                searchTerm: 'laravel analytics',
                resultsCount: 42,
                category: 'packages',
            );

            expect($event->name)->toBe('search');
            expect($event->params['search_term'])->toBe('laravel analytics');
            expect($event->params['results_count'])->toBe(42);
            expect($event->params['category'])->toBe('packages');
        });
    });

    describe('ShareEvent', function () {
        it('tracks content sharing', function () {
            $event = new ShareEvent(
                method: 'twitter',
                contentType: 'article',
                itemId: 'post-123',
            );

            expect($event->name)->toBe('share');
            expect($event->params['method'])->toBe('twitter');
            expect($event->params['content_type'])->toBe('article');
            expect($event->params['item_id'])->toBe('post-123');
        });
    });

    describe('TimeOnPageEvent', function () {
        it('tracks time on page in milliseconds for GA4', function () {
            $event = new TimeOnPageEvent(
                seconds: 30,
                pagePath: '/pricing',
                pageTitle: 'Pricing',
            );

            expect($event->name)->toBe('time_on_page');
            expect($event->params['engagement_time_msec'])->toBe(30000);
            expect($event->params['seconds'])->toBe(30);
            expect($event->params['page_path'])->toBe('/pricing');
        });
    });

    describe('ErrorEvent', function () {
        it('tracks 404 errors', function () {
            $event = new ErrorEvent(
                errorType: '404',
                message: 'Page not found',
                pagePath: '/missing-page',
                fatal: false,
            );

            expect($event->name)->toBe('error');
            expect($event->params['error_type'])->toBe('404');
            expect($event->params['message'])->toBe('Page not found');
            expect($event->params['page_path'])->toBe('/missing-page');
            expect($event->params['fatal'])->toBeFalse();
        });

        it('tracks validation errors', function () {
            $event = new ErrorEvent(
                errorType: 'validation',
                message: 'Email is required',
                pagePath: '/register',
            );

            expect($event->name)->toBe('error');
            expect($event->params['error_type'])->toBe('validation');
            expect($event->params)->not->toHaveKey('fatal');
        });
    });
});

describe('CustomEvent', function () {
    it('creates with arbitrary name and params', function () {
        $event = new CustomEvent('tutorial_completed', [
            'tutorial_name' => 'Getting Started',
            'duration_seconds' => 120,
            'completed' => true,
        ]);

        expect($event->name)->toBe('tutorial_completed');
        expect($event->params['tutorial_name'])->toBe('Getting Started');
        expect($event->params['duration_seconds'])->toBe(120);
        expect($event->params['completed'])->toBeTrue();
    });

    it('creates with empty params', function () {
        $event = new CustomEvent('heartbeat');

        expect($event->name)->toBe('heartbeat');
        expect($event->params)->toBeEmpty();
    });

    it('creates with nested params', function () {
        $event = new CustomEvent('feature_used', [
            'feature_name' => 'export_csv',
            'context' => [
                'page' => '/dashboard',
                'items_count' => 150,
            ],
        ]);

        expect($event->name)->toBe('feature_used');
        expect($event->params['feature_name'])->toBe('export_csv');
        expect($event->params['context']['page'])->toBe('/dashboard');
    });
});
