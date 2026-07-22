<?php

declare(strict_types=1);

use Illuminate\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\Trackers\GA4Tracker;
use ZeroBoiler\Analytics\Trackers\GTMTracker;
use ZeroBoiler\Analytics\Trackers\MetaPixelTracker;

function makeManager(array $overrides = []): AnalyticsManager
{
    $default = [
        'ga4' => [
            'enabled' => true,
            'measurement_id' => 'G-TEST1234',
            'api_secret' => 'test-api-secret-with-20-chars',
        ],
        'gtm' => [
            'enabled' => true,
            'container_id' => 'GTM-TEST1',
        ],
        'meta_pixel' => [
            'enabled' => true,
            'id' => '123456789012345',
            'access_token' => 'test-access-token',
        ],
    ];

    $config = array_replace_recursive($default, $overrides);

    $repo = new ConfigRepository(['zeroboiler' => ['analytics' => $config]]);

    return new AnalyticsManager($repo);
}

describe('AnalyticsManager', function () {
    it('can be instantiated', function () {
        $manager = makeManager();

        expect($manager)->toBeInstanceOf(AnalyticsManager::class);
    });

    it('has GA4 tracker', function () {
        $manager = makeManager();

        expect($manager->ga4())->toBeInstanceOf(GA4Tracker::class);
    });

    it('has GTM tracker', function () {
        $manager = makeManager();

        expect($manager->gtm())->toBeInstanceOf(GTMTracker::class);
    });

    it('has Meta Pixel tracker', function () {
        $manager = makeManager();

        expect($manager->meta())->toBeInstanceOf(MetaPixelTracker::class);
    });

    it('generates head scripts from all enabled providers', function () {
        $manager = makeManager();

        $scripts = $manager->headScripts();

        expect($scripts)->toContain('Google Analytics 4');
        expect($scripts)->toContain('Google Tag Manager');
        expect($scripts)->toContain('Meta Pixel Code');
    });

    it('generates body scripts from enabled providers', function () {
        $manager = makeManager();

        $scripts = $manager->bodyScripts();

        expect($scripts)->toContain('Google Tag Manager (noscript)');
        expect($scripts)->toContain('Meta Pixel Code (noscript)');
    });

    it('returns empty head scripts when all providers disabled', function () {
        $manager = makeManager([
            'ga4' => ['enabled' => false],
            'gtm' => ['enabled' => false],
            'meta_pixel' => ['enabled' => false],
        ]);

        expect($manager->headScripts())->toBe('');
    });

    it('returns empty body scripts when all providers disabled', function () {
        $manager = makeManager([
            'ga4' => ['enabled' => false],
            'gtm' => ['enabled' => false],
            'meta_pixel' => ['enabled' => false],
        ]);

        expect($manager->bodyScripts())->toBe('');
    });

    it('pushes data to GTM dataLayer', function () {
        $manager = makeManager();

        $manager->push(['event' => 'test_event', 'value' => 42]);

        expect($manager->gtm()->getDataLayer())->toHaveCount(1);
        expect($manager->gtm()->getDataLayer()[0])->toEqual(['event' => 'test_event', 'value' => 42]);
    });

    it('does not push to dataLayer when GTM disabled', function () {
        $manager = makeManager(['gtm' => ['enabled' => false]]);

        $manager->push(['event' => 'test_event']);

        expect($manager->gtm()->getDataLayer())->toBeEmpty();
    });
});
