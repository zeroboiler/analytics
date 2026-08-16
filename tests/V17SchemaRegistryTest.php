<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Schema\EventSchemaRegistry;
use ZeroBoiler\Analytics\Schema\EventSchema;
use ZeroBoiler\Analytics\Schema\EventParam;

describe('v1.7.0 — Event Schema Registry for new events', function () {
    it('registers screen_view schema', function () {
        $registry = new EventSchemaRegistry;

        expect($registry->has('screen_view'))->toBeTrue();

        $schema = $registry->get('screen_view');
        expect($schema)->not->toBeNull();
        expect($schema->name)->toBe('screen_view');
        expect($schema->category)->toBe('engagement');
        expect($schema)->toBeInstanceOf(EventSchema::class);
    });

    it('registers ab_test_exposure schema', function () {
        $registry = new EventSchemaRegistry;

        expect($registry->has('ab_test_exposure'))->toBeTrue();

        $schema = $registry->get('ab_test_exposure');
        expect($schema)->not->toBeNull();
        expect($schema->name)->toBe('ab_test_exposure');
        expect($schema->category)->toBe('engagement');
    });

    it('registers notification schema', function () {
        $registry = new EventSchemaRegistry;

        expect($registry->has('notification'))->toBeTrue();

        $schema = $registry->get('notification');
        expect($schema)->not->toBeNull();
        expect($schema->name)->toBe('notification');
        expect($schema->category)->toBe('engagement');
    });

    it('validates screen_view with required params', function () {
        $registry = new EventSchemaRegistry;

        $result = $registry->validate('screen_view', [
            'screen_name' => 'Dashboard',
        ]);

        expect($result['valid'])->toBeTrue();
        expect($result['sanitized'])->toHaveKey('screen_name');
    });

    it('fails screen_view validation without required params', function () {
        $registry = new EventSchemaRegistry;

        $result = $registry->validate('screen_view', []);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->toContain('Missing required parameter: screen_name');
    });

    it('validates ab_test_exposure with required params', function () {
        $registry = new EventSchemaRegistry;

        $result = $registry->validate('ab_test_exposure', [
            'experiment_id' => 'test_1',
            'variant_id' => 'control',
        ]);

        expect($result['valid'])->toBeTrue();
    });

    it('validates notification with required params', function () {
        $registry = new EventSchemaRegistry;

        $result = $registry->validate('notification', [
            'notification_channel' => 'email',
            'notification_action' => 'sent',
        ]);

        expect($result['valid'])->toBeTrue();
    });

    it('notification schema accepts optional notification_type', function () {
        $registry = new EventSchemaRegistry;

        $result = $registry->validate('notification', [
            'notification_channel' => 'push',
            'notification_action' => 'opened',
            'notification_type' => 'daily_digest',
        ]);

        expect($result['valid'])->toBeTrue();
        expect($result['sanitized']['notification_type'])->toBe('daily_digest');
    });
});
