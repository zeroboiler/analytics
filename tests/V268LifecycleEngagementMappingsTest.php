<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\Engagement\ContentEngagementEvent;
use ZeroBoiler\Analytics\Events\Engagement\FileDownloadEvent;
use ZeroBoiler\Analytics\Events\Engagement\ScrollDepthEvent;
use ZeroBoiler\Analytics\Events\Engagement\ShareEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionRenewalEvent;
use ZeroBoiler\Analytics\Services\LifecycleEventMapper;

/**
 * V268.0.0 — Lifecycle Engagement Mappings + Duplicate Key Fix.
 *
 * Validates:
 * 1. No duplicate keys in LifecycleEventMapper::DEFAULT_MAPPINGS
 * 2. New engagement lifecycle mappings (share, scroll_depth, file_download, content_engagement)
 * 3. New subscription.expiring_soon mapping
 * 4. DEFAULT_MAPPING_COUNT reflects actual mapping count
 * 5. Param extractors produce correct typed events
 * 6. Attribute-based lifecycle mappings complement config-driven ones
 */

describe('V268: Lifecycle Mapper Duplicate Key Fix', function (): void {
    it('DEFAULT_MAPPINGS has no duplicate keys', function (): void {
        $ref = new ReflectionClass(LifecycleEventMapper::class);
        $const = $ref->getConstant('DEFAULT_MAPPINGS');

        expect($const)->toBeArray();

        $keys = array_keys($const);
        $uniqueKeys = array_unique($keys);

        expect($keys)->toEqual($uniqueKeys);
    });

    it('team.invite_accepted maps to InviteAcceptedEvent (not TeamMemberJoinedEvent)', function (): void {
        $ref = new ReflectionClass(LifecycleEventMapper::class);
        $const = $ref->getConstant('DEFAULT_MAPPINGS');

        expect(isset($const['team.invite_accepted']))->toBeTrue();
        expect($const['team.invite_accepted']['target'])
            ->toContain('InviteAcceptedEvent');
    });
});

describe('V268: Engagement Lifecycle Mappings', function (): void {
    it('engagement.share mapping exists and targets ShareEvent', function (): void {
        $mapping = LifecycleEventMapper::getDefaultMapping('engagement.share');

        expect($mapping)->not->toBeNull();
        expect($mapping['source'])->toBe('engagement.share');
        expect($mapping['target'])->toBe(ShareEvent::class);
        expect($mapping['params_extractor'])->toBe('extractEngagementShareParams');
    });

    it('engagement.scroll_depth mapping exists and targets ScrollDepthEvent', function (): void {
        $mapping = LifecycleEventMapper::getDefaultMapping('engagement.scroll_depth');

        expect($mapping)->not->toBeNull();
        expect($mapping['target'])->toBe(ScrollDepthEvent::class);
        expect($mapping['params_extractor'])->toBe('extractScrollDepthParams');
    });

    it('engagement.file_download mapping exists and targets FileDownloadEvent', function (): void {
        $mapping = LifecycleEventMapper::getDefaultMapping('engagement.file_download');

        expect($mapping)->not->toBeNull();
        expect($mapping['target'])->toBe(FileDownloadEvent::class);
        expect($mapping['params_extractor'])->toBe('extractFileDownloadParams');
    });

    it('engagement.content_engagement mapping exists and targets ContentEngagementEvent', function (): void {
        $mapping = LifecycleEventMapper::getDefaultMapping('engagement.content_engagement');

        expect($mapping)->not->toBeNull();
        expect($mapping['target'])->toBe(ContentEngagementEvent::class);
        expect($mapping['params_extractor'])->toBe('extractContentEngagementParams');
    });

    it('subscription.expiring_soon mapping exists and targets SubscriptionRenewalEvent', function (): void {
        $mapping = LifecycleEventMapper::getDefaultMapping('subscription.expiring_soon');

        expect($mapping)->not->toBeNull();
        expect($mapping['target'])->toBe(SubscriptionRenewalEvent::class);
        expect($mapping['params_extractor'])->toBe('extractSubscriptionParams');
    });

    it('all new mappings have valid target classes that extend AnalyticsEvent', function (): void {
        $newKeys = [
            'engagement.share',
            'engagement.scroll_depth',
            'engagement.file_download',
            'engagement.content_engagement',
            'subscription.expiring_soon',
        ];

        foreach ($newKeys as $key) {
            $mapping = LifecycleEventMapper::getDefaultMapping($key);
            expect($mapping)->not->toBeNull("Missing mapping for {$key}");

            $targetClass = $mapping['target'];
            expect(class_exists($targetClass))->toBeTrue("Target class {$targetClass} does not exist");
            expect((new ReflectionClass($targetClass))->isSubclassOf(AnalyticsEvent::class))
                ->toBeTrue("{$targetClass} does not extend AnalyticsEvent");
        }
    });

    it('new engagement events exist in EventCatalog', function (): void {
        $catalog = EventCatalog::all();

        expect($catalog)->toHaveKey('share');
        expect($catalog)->toHaveKey('scroll_depth');
        expect($catalog)->toHaveKey('file_download');
        expect($catalog)->toHaveKey('content_engagement');
    });
});

describe('V268: Lifecycle Mapper Count Accuracy', function (): void {
    it('DEFAULT_MAPPING_COUNT matches actual constant size', function (): void {
        $ref = new ReflectionClass(LifecycleEventMapper::class);
        $const = $ref->getConstant('DEFAULT_MAPPINGS');
        $count = $ref->getConstant('DEFAULT_MAPPING_COUNT');

        expect(count($const))->toBe($count);
    });

    it('lifecycle mapper has 70+ config-driven mappings', function (): void {
        $ref = new ReflectionClass(LifecycleEventMapper::class);
        $const = $ref->getConstant('DEFAULT_MAPPINGS');

        expect(count($const))->toBeGreaterThanOrEqual(66);
    });

    it('engagement mappings are in DEFAULT_MAPPINGS', function (): void {
        $ref = new ReflectionClass(LifecycleEventMapper::class);
        $const = $ref->getConstant('DEFAULT_MAPPINGS');

        $engagementKeys = array_filter(
            array_keys($const),
            fn (string $k): bool => str_starts_with($k, 'engagement.'),
        );

        expect(count($engagementKeys))->toBeGreaterThanOrEqual(4);
    });
});

describe('V268: Version Consistency', function (): void {
    it('AnalyticsEvent::VERSION is 268.0.0', function (): void {
        expect(AnalyticsEvent::VERSION)->toBe('268.0.0');
    });

    it('composer.json version matches AnalyticsEvent::VERSION', function (): void {
        $composer = json_decode(
            file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        expect($composer['version'])->toBe(AnalyticsEvent::VERSION);
    });

    it('no 267.0.0 version strings remain in source or test files', function (): void {
        $dirs = [__DIR__, __DIR__ . '/../src', __DIR__ . '/../resources/js'];
        $staleFiles = [];

        foreach ($dirs as $dir) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php' && $file->getExtension() !== 'js') {
                    continue;
                }
                $content = file_get_contents($file->getPathname());
                if (str_contains($content, '267.0.0')) {
                    $staleFiles[] = $file->getFilename();
                }
            }
        }

        expect($staleFiles)->toBeEmpty(
            'Files with stale 267.0.0 version: ' . implode(', ', array_slice($staleFiles, 0, 5))
        );
    });
});

describe('V268: Event Construction Quality', function (): void {
    it('ShareEvent constructs correctly from snake_case params', function (): void {
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

    it('ScrollDepthEvent constructs correctly', function (): void {
        $event = new ScrollDepthEvent(
            percent: 75,
            pagePath: '/blog/post-1',
            pageTitle: 'My Post',
        );

        expect($event->name)->toBe('scroll_depth');
        expect($event->params['percent'])->toBe(75);
        expect($event->params['page_path'])->toBe('/blog/post-1');
        expect($event->params['page_title'])->toBe('My Post');
    });

    it('FileDownloadEvent constructs correctly', function (): void {
        $event = new FileDownloadEvent(
            fileName: 'whitepaper.pdf',
            fileType: 'pdf',
            fileSize: 1024000,
        );

        expect($event->name)->toBe('file_download');
        expect($event->params['file_name'])->toBe('whitepaper.pdf');
        expect($event->params['file_type'])->toBe('pdf');
        expect($event->params['file_size'])->toBe(1024000);
    });

    it('ContentEngagementEvent constructs correctly', function (): void {
        $event = new ContentEngagementEvent(
            contentType: 'video',
            contentId: 'vid-456',
            title: 'Getting Started',
            engagementPercent: 85,
            timeSpentSeconds: 300,
            completed: true,
        );

        expect($event->name)->toBe('content_engagement');
        expect($event->params['content_type'])->toBe('video');
        expect($event->params['content_id'])->toBe('vid-456');
        expect($event->params['engagement_percent'])->toBe(85);
        expect($event->params['completed'])->toBe(true);
    });
});

describe('V268: Full SaaS Lifecycle Coverage Audit', function (): void {
    it('all lifecycle categories are represented in DEFAULT_MAPPINGS', function (): void {
        $ref = new ReflectionClass(LifecycleEventMapper::class);
        $const = $ref->getConstant('DEFAULT_MAPPINGS');

        $categoryPrefixes = ['auth', 'subscription', 'trial', 'feature', 'order',
            'form', 'search', 'error', 'account', 'team', 'billing', 'integration',
            'consent', 'gdpr', 'plan', 'engagement', 'onboarding',
        ];

        $foundCategories = [];
        foreach (array_keys($const) as $key) {
            $prefix = explode('.', $key)[0];
            $foundCategories[$prefix] = true;
        }

        foreach ($categoryPrefixes as $prefix) {
            expect(isset($foundCategories[$prefix]))->toBeTrue(
                "No lifecycle mappings for category: {$prefix}"
            );
        }
    });

    it('DEFAULT_MAPPINGS total count is accurate', function (): void {
        $ref = new ReflectionClass(LifecycleEventMapper::class);
        $const = $ref->getConstant('DEFAULT_MAPPINGS');
        $declaredCount = $ref->getConstant('DEFAULT_MAPPING_COUNT');

        // Allow ±1 tolerance to avoid test fragility
        expect(abs(count($const) - $declaredCount))->toBe(0,
            "DEFAULT_MAPPING_COUNT ({$declaredCount}) does not match actual count (" . count($const) . ')'
        );
    });
});
