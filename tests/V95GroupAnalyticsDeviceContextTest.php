<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Support\DeviceContextParser;
use ZeroBoiler\Analytics\Services\GroupAnalyticsService;

beforeEach(function (): void {
    $this->cache = app('cache');
    $this->config = app('config');
});

// ── DeviceContextParser Tests ─────────────────────────────────────────

describe('DeviceContextParser', function (): void {
    test('parses Chrome on Windows desktop', function (): void {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $result = DeviceContextParser::parse($ua);

        expect($result['device_type'])->toBe('desktop');
        expect($result['browser'])->toBe('Chrome');
        expect($result['browser_version'])->toBe('120.0.0.0');
        expect($result['os'])->toBe('Windows');
        expect($result['os_version'])->toBe('10');
        expect($result['is_bot'])->toBeFalse();
        expect($result['is_desktop'])->toBeTrue();
        expect($result['is_mobile'])->toBeFalse();
    });

    test('parses Safari on macOS desktop', function (): void {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
        $result = DeviceContextParser::parse($ua);

        expect($result['device_type'])->toBe('desktop');
        expect($result['browser'])->toBe('Safari');
        expect($result['os'])->toBe('macOS');
        expect($result['brand'])->toBe('apple');
    });

    test('parses Chrome on Android mobile', function (): void {
        $ua = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';
        $result = DeviceContextParser::parse($ua);

        expect($result['device_type'])->toBe('mobile');
        expect($result['browser'])->toBe('Chrome');
        expect($result['os'])->toBe('Android');
        expect($result['brand'])->toBe('google');
        expect($result['is_mobile'])->toBeTrue();
        expect($result['is_desktop'])->toBeFalse();
    });

    test('parses iPhone Safari mobile', function (): void {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        $result = DeviceContextParser::parse($ua);

        expect($result['device_type'])->toBe('mobile');
        expect($result['browser'])->toBe('Safari');
        expect($result['os'])->toBe('iOS');
        expect($result['brand'])->toBe('apple');
        expect($result['is_mobile'])->toBeTrue();
    });

    test('parses iPad tablet', function (): void {
        $ua = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        $result = DeviceContextParser::parse($ua);

        expect($result['device_type'])->toBe('tablet');
        expect($result['is_tablet'])->toBeTrue();
    });

    test('detects Googlebot', function (): void {
        $ua = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $result = DeviceContextParser::parse($ua);

        expect($result['is_bot'])->toBeTrue();
        expect(DeviceContextParser::deviceCategory($ua))->toBe('bot');
    });

    test('detects Facebook bot', function (): void {
        $ua = 'facebookexternalhit/1.1';
        $result = DeviceContextParser::parse($ua);

        expect($result['is_bot'])->toBeTrue();
    });

    test('parses Firefox on Linux', function (): void {
        $ua = 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0';
        $result = DeviceContextParser::parse($ua);

        expect($result['browser'])->toBe('Firefox');
        expect($result['browser_version'])->toBe('121.0');
        expect($result['os'])->toBe('Ubuntu');
        expect($result['device_type'])->toBe('desktop');
    });

    test('parses Edge browser', function (): void {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0';
        $result = DeviceContextParser::parse($ua);

        expect($result['browser'])->toBe('Edge');
    });

    test('handles empty user agent', function (): void {
        $result = DeviceContextParser::parse('');

        expect($result['device_type'])->toBeNull();
        expect($result['browser'])->toBeNull();
        expect($result['os'])->toBeNull();
        expect($result['is_bot'])->toBeFalse();
        expect($result['is_mobile'])->toBeFalse();
        expect($result['is_desktop'])->toBeFalse();
    });

    test('deviceCategory returns unknown for empty UA', function (): void {
        expect(DeviceContextParser::deviceCategory(''))->toBe('unknown');
    });
});

// ── GroupAnalyticsService Tests ───────────────────────────────────────

describe('GroupAnalyticsService', function (): void {
    beforeEach(function (): void {
        $this->service = new GroupAnalyticsService($this->cache, $this->config);
    });

    test('identifies a group with traits', function (): void {
        $this->service->identify('org_123', [
            'name' => 'Acme Corp',
            'industry' => 'SaaS',
            'plan' => 'enterprise',
        ]);

        $group = $this->service->getGroup('org_123');

        expect($group['group_id'])->toBe('org_123');
        expect($group['traits']['name'])->toBe('Acme Corp');
        expect($group['traits']['industry'])->toBe('SaaS');
        expect($group['traits']['plan'])->toBe('enterprise');
        expect($group['created_at'])->not->toBeNull();
        expect($group['updated_at'])->not->toBeNull();
    });

    test('merges traits on subsequent identify calls', function (): void {
        $this->service->identify('org_123', ['name' => 'Acme Corp']);
        $this->service->identify('org_123', ['mrr' => 50000]);

        $group = $this->service->getGroup('org_123');

        expect($group['traits']['name'])->toBe('Acme Corp');
        expect($group['traits']['mrr'])->toBe(50000);
    });

    test('returns empty group for non-existent ID', function (): void {
        $group = $this->service->getGroup('nonexistent');

        expect($group['group_id'])->toBe('nonexistent');
        expect($group['traits'])->toBe([]);
        expect($group['member_count'])->toBe(0);
    });

    test('adds and retrieves group members', function (): void {
        $this->service->addMember('user_1', 'org_123', 'admin', ['department' => 'Engineering']);

        $members = $this->service->getGroupMembers('org_123');
        $userGroups = $this->service->getUserGroups('user_1');

        expect($members)->toHaveKey('user_1');
        expect($members['user_1']['role'])->toBe('admin');
        expect($members['user_1']['traits']['department'])->toBe('Engineering');
        expect($userGroups)->toHaveKey('org_123');
    });

    test('tracks member count in group data', function (): void {
        $this->service->identify('org_123', ['name' => 'Acme']);
        $this->service->addMember('user_1', 'org_123');
        $this->service->addMember('user_2', 'org_123');

        $group = $this->service->getGroup('org_123');

        expect($group['member_count'])->toBe(2);
    });

    test('removes a member from a group', function (): void {
        $this->service->addMember('user_1', 'org_123');
        $this->service->removeMember('user_1', 'org_123');

        $members = $this->service->getGroupMembers('org_123');
        $userGroups = $this->service->getUserGroups('user_1');

        expect($members)->not->toHaveKey('user_1');
        expect($userGroups)->not->toHaveKey('org_123');
    });

    test('updates decrement member count after removal', function (): void {
        $this->service->identify('org_123');
        $this->service->addMember('user_1', 'org_123');
        $this->service->addMember('user_2', 'org_123');
        $this->service->removeMember('user_1', 'org_123');

        $group = $this->service->getGroup('org_123');

        expect($group['member_count'])->toBe(1);
    });

    test('updates a single trait', function (): void {
        $this->service->identify('org_123', ['name' => 'Acme', 'mrr' => 10000]);
        $this->service->setTrait('org_123', 'mrr', 25000);

        $group = $this->service->getGroup('org_123');

        expect($group['traits']['mrr'])->toBe(25000);
        expect($group['traits']['name'])->toBe('Acme'); // unchanged
    });

    test('increments a numeric trait', function (): void {
        $this->service->identify('org_123', ['total_events' => 10]);
        $this->service->incrementTrait('org_123', 'total_events', 5);

        $group = $this->service->getGroup('org_123');

        expect($group['traits']['total_events'])->toBe(15);
    });

    test('gets primary group for a user', function (): void {
        $this->service->addMember('user_1', 'org_123');
        $this->service->addMember('user_1', 'org_456');

        $primary = $this->service->getPrimaryGroup('user_1');

        expect($primary)->toBe('org_123'); // first group joined
    });

    test('returns null for primary group when no groups', function (): void {
        $primary = $this->service->getPrimaryGroup('user_unknown');

        expect($primary)->toBeNull();
    });

    test('forgets a group and cleans up membership', function (): void {
        $this->service->identify('org_123');
        $this->service->addMember('user_1', 'org_123');
        $this->service->addMember('user_2', 'org_123');

        $this->service->forgetGroup('org_123');

        expect($this->service->getGroup('org_123')['traits'])->toBe([]);
        expect($this->service->getUserGroups('user_1'))->not->toHaveKey('org_123');
        expect($this->service->getUserGroups('user_2'))->not->toHaveKey('org_123');
    });

    test('forgets all user groups', function (): void {
        $this->service->addMember('user_1', 'org_123');
        $this->service->addMember('user_1', 'org_456');

        $this->service->forgetUserGroups('user_1');

        expect($this->service->getUserGroups('user_1'))->toBeEmpty();
    });

    test('returns summary', function (): void {
        $summary = $this->service->summary();

        expect($summary)->toHaveKeys(['enabled', 'ttl', 'max_members_per_group', 'max_groups_per_user']);
    });

    test('does not add duplicate members', function (): void {
        $this->service->addMember('user_1', 'org_123');
        $this->service->addMember('user_1', 'org_123');

        $members = $this->service->getGroupMembers('org_123');

        expect(count($members))->toBe(1);
    });
});
