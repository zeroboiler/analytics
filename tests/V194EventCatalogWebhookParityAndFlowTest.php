<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Tests;

test('EventCatalog count includes WebhookEvents', function (): void {
    $count = \ZeroBoiler\Analytics\Events\EventCatalog::count();

    $expected = \ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents::count()
        + \ZeroBoiler\Analytics\Events\SaaS\SaaSEvents::count()
        + \ZeroBoiler\Analytics\Events\Engagement\EngagementEvents::count()
        + \ZeroBoiler\Analytics\Events\Security\SecurityEvents::count()
        + \ZeroBoiler\Analytics\Events\Uptime\UptimeEvents::count()
        + \ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents::count()
        + \ZeroBoiler\Analytics\Events\Marketing\MarketingEvents::count()
        + \ZeroBoiler\Analytics\Events\SaaS\CustomerSuccessEvents::count()
        + \ZeroBoiler\Analytics\Events\Webhook\WebhookEvents::count();

    expect($count)->toBe($expected);
});

test('EventCatalog categorySummary includes webhook', function (): void {
    $summary = \ZeroBoiler\Analytics\Events\EventCatalog::categorySummary();

    expect($summary)->toHaveKey('webhook');
    expect($summary['webhook'])->toBe(\ZeroBoiler\Analytics\Events\Webhook\WebhookEvents::count());
    expect($summary['total'])->toBe(array_sum($summary));
});

test('EventCatalog allGa4Names includes webhook events', function (): void {
    $names = \ZeroBoiler\Analytics\Events\EventCatalog::allGa4Names();

    $webhookGa4 = \ZeroBoiler\Analytics\Events\Webhook\WebhookEvents::ga4Names();

    foreach ($webhookGa4 as $name) {
        expect($names)->toContain($name);
    }
});

test('EventCatalog allPosthogNames includes webhook events', function (): void {
    $names = \ZeroBoiler\Analytics\Events\EventCatalog::allPosthogNames();

    $webhookPosthog = \ZeroBoiler\Analytics\Events\Webhook\WebhookEvents::posthogNames();

    foreach ($webhookPosthog as $name) {
        expect($names)->toContain($name);
    }
});

test('EventCatalog allMetaNames includes webhook events', function (): void {
    $names = \ZeroBoiler\Analytics\Events\EventCatalog::allMetaNames();

    $webhookMeta = \ZeroBoiler\Analytics\Events\Webhook\WebhookEvents::metaNames();

    // Only non-null meta names should be included
    foreach ($webhookMeta as $name) {
        if ($name !== null) {
            expect($names)->toContain($name);
        }
    }
});

test('EventCatalog allPlausibleNames includes webhook events', function (): void {
    $names = \ZeroBoiler\Analytics\Events\EventCatalog::allPlausibleNames();

    $webhookPlausible = \ZeroBoiler\Analytics\Events\Webhook\WebhookEvents::plausibleNames();

    foreach ($webhookPlausible as $name) {
        if ($name !== null) {
            expect($names)->toContain($name);
        }
    }
});

test('EventCatalog allMixpanelNames includes webhook events', function (): void {
    $names = \ZeroBoiler\Analytics\Events\EventCatalog::allMixpanelNames();

    $webhookMixpanel = \ZeroBoiler\Analytics\Events\Webhook\WebhookEvents::mixpanelNames();

    foreach ($webhookMixpanel as $name) {
        expect($names)->toContain($name);
    }
});

test('EventCatalog allAmplitudeNames includes webhook events', function (): void {
    $names = \ZeroBoiler\Analytics\Events\EventCatalog::allAmplitudeNames();

    $webhookAmplitude = \ZeroBoiler\Analytics\Events\Webhook\WebhookEvents::amplitudeNames();

    foreach ($webhookAmplitude as $name) {
        expect($names)->toContain($name);
    }
});

test('EventCatalog allTikTokNames includes webhook events', function (): void {
    $names = \ZeroBoiler\Analytics\Events\EventCatalog::allTikTokNames();

    $webhookTiktok = \ZeroBoiler\Analytics\Events\Webhook\WebhookEvents::tiktokNames();

    foreach ($webhookTiktok as $name) {
        if ($name !== null) {
            expect($names)->toContain($name);
        }
    }
});

test('EventCatalog allLinkedInNames includes webhook events', function (): void {
    $names = \ZeroBoiler\Analytics\Events\EventCatalog::allLinkedInNames();

    $webhookLinkedin = \ZeroBoiler\Analytics\Events\Webhook\WebhookEvents::linkedinNames();

    foreach ($webhookLinkedin as $name) {
        if ($name !== null) {
            expect($names)->toContain($name);
        }
    }
});

test('EventCatalog has correctly accounts for webhook events', function (): void {
    // Verify webhook_delivered is in the catalog
    expect(\ZeroBoiler\Analytics\Events\EventCatalog::has('webhook_delivered'))->toBeTrue();
    expect(\ZeroBoiler\Analytics\Events\EventCatalog::getCategory('webhook_delivered'))->toBe('webhook');
});

test('SaaS lifecycle flow service stage constants are consistent', function (): void {
    $stages = \ZeroBoiler\Analytics\Services\SaaSLifecycleFlowService::stages();

    expect($stages)->toHaveCount(8);
    expect($stages[0])->toBe('anonymous');
    expect($stages[7])->toBe('champion');

    // Verify stageIndex is consistent with stages array
    foreach ($stages as $idx => $stage) {
        expect(\ZeroBoiler\Analytics\Services\SaaSLifecycleFlowService::stageIndex($stage))->toBe($idx);
    }
});

test('SaaS lifecycle flow funnelBreakdown has all stages', function (): void {
    $breakdown = \ZeroBoiler\Analytics\Services\SaaSLifecycleFlowService::funnelBreakdown();

    expect($breakdown)->toHaveCount(8);
    expect($breakdown[0]['stage'])->toBe('anonymous');
    expect($breakdown[0]['index'])->toBe(0);
    expect($breakdown[0]['progress'])->toBe(0.0);
    expect($breakdown[7]['stage'])->toBe('champion');
    expect($breakdown[7]['index'])->toBe(7);
    expect($breakdown[7]['progress'])->toBe(1.0);
});
