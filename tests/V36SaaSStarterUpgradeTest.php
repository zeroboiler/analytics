<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\InviteSentEvent;
use ZeroBoiler\Analytics\Events\SaaS\IntegrationConnectedEvent;
use ZeroBoiler\Analytics\Events\Engagement\FileDownloadEvent;
use ZeroBoiler\Analytics\Events\Engagement\VideoPlayEvent;
use ZeroBoiler\Analytics\Support\AnalyticsConfig;

beforeEach(function (): void {
    $this->config = Mockery::mock(Illuminate\Contracts\Config\Repository::class);
    $this->manager = new AnalyticsManager($this->config);
});

describe('V36 SaaS Starter Upgrade', function (): void {

    // ── New Event Classes ──────────────────────────────────────────

    describe('InviteSentEvent', function (): void {
        test('constructs with required params', function (): void {
            $event = new InviteSentEvent('team_member', 'editor', 'user-42');
            expect($event->name)->toBe('invite_sent');
            expect($event->params['invite_type'])->toBe('team_member');
            expect($event->params['role'])->toBe('editor');
            expect($event->params['user_id'])->toBe('user-42');
        });

        test('constructs with minimal params', function (): void {
            $event = new InviteSentEvent('referral');
            expect($event->name)->toBe('invite_sent');
            expect($event->params['invite_type'])->toBe('referral');
            expect($event->params)->toHaveKey('role');
        });

        test('accepts extra params', function (): void {
            $event = new InviteSentEvent('collaborator', 'viewer', 'user-1', ['workspace_id' => 'ws-123']);
            expect($event->params['workspace_id'])->toBe('ws-123');
        });

        test('is readonly final', function (): void {
            $reflection = new ReflectionClass(InviteSentEvent::class);
            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->isReadOnly())->toBeTrue();
        });

        test('extends AnalyticsEvent', function (): void {
            $event = new InviteSentEvent('team_member');
            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
        });
    });

    describe('IntegrationConnectedEvent', function (): void {
        test('constructs with required params', function (): void {
            $event = new IntegrationConnectedEvent('slack', 'user-1');
            expect($event->name)->toBe('integration_connected');
            expect($event->params['integration_name'])->toBe('slack');
            expect($event->params['user_id'])->toBe('user-1');
        });

        test('constructs with minimal params', function (): void {
            $event = new IntegrationConnectedEvent('github');
            expect($event->params['integration_name'])->toBe('github');
            expect($event->params)->not->toHaveKey('user_id');
        });

        test('accepts extra params', function (): void {
            $event = new IntegrationConnectedEvent('stripe', null, ['connected_account_id' => 'acct_123']);
            expect($event->params['connected_account_id'])->toBe('acct_123');
        });

        test('is readonly final', function (): void {
            $reflection = new ReflectionClass(IntegrationConnectedEvent::class);
            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->isReadOnly())->toBeTrue();
        });
    });

    describe('FileDownloadEvent', function (): void {
        test('constructs with all params', function (): void {
            $event = new FileDownloadEvent('report.pdf', 'pdf', 1024000);
            expect($event->name)->toBe('file_download');
            expect($event->params['file_name'])->toBe('report.pdf');
            expect($event->params['file_type'])->toBe('pdf');
            expect($event->params['file_size'])->toBe(1024000);
        });

        test('constructs with minimal params', function (): void {
            $event = new FileDownloadEvent('data.csv');
            expect($event->name)->toBe('file_download');
            expect($event->params['file_name'])->toBe('data.csv');
            expect($event->params)->not->toHaveKey('file_type');
        });

        test('accepts extra params', function (): void {
            $event = new FileDownloadEvent('export.xlsx', 'xlsx', null, ['page_url' => '/reports']);
            expect($event->params['page_url'])->toBe('/reports');
        });

        test('is readonly final', function (): void {
            $reflection = new ReflectionClass(FileDownloadEvent::class);
            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->isReadOnly())->toBeTrue();
        });
    });

    describe('VideoPlayEvent', function (): void {
        test('constructs with all params', function (): void {
            $event = new VideoPlayEvent('Onboarding Tutorial', 'wistia', 180.5);
            expect($event->name)->toBe('video_play');
            expect($event->params['video_title'])->toBe('Onboarding Tutorial');
            expect($event->params['video_provider'])->toBe('wistia');
            expect($event->params['video_duration'])->toBe(180.5);
        });

        test('constructs with minimal params', function (): void {
            $event = new VideoPlayEvent('Product Demo');
            expect($event->name)->toBe('video_play');
            expect($event->params['video_title'])->toBe('Product Demo');
            expect($event->params)->not->toHaveKey('video_provider');
        });

        test('accepts extra params', function (): void {
            $event = new VideoPlayEvent('Tutorial', 'youtube', 120, ['autoplay' => true]);
            expect($event->params['autoplay'])->toBeTrue();
        });

        test('is readonly final', function (): void {
            $reflection = new ReflectionClass(VideoPlayEvent::class);
            expect($reflection->isFinal())->toBeTrue();
            expect($reflection->isReadOnly())->toBeTrue();
        });
    });

    // ── Catalog Integrity ──────────────────────────────────────────

    describe('SaaSEvents catalog expansion', function (): void {
        test('now has 19 events (was 17)', function (): void {
            expect(SaaSEvents::count())->toBe(19);
        });

        test('invite_sent exists in catalog', function (): void {
            expect(SaaSEvents::has('invite_sent'))->toBeTrue();
            $entry = SaaSEvents::get('invite_sent');
            expect($entry['class'])->toBe(InviteSentEvent::class);
            expect($entry['ga4'])->toBe('invite_sent');
            expect($entry['meta'])->toBe('InviteSent');
        });

        test('integration_connected exists in catalog', function (): void {
            expect(SaaSEvents::has('integration_connected'))->toBeTrue();
            $entry = SaaSEvents::get('integration_connected');
            expect($entry['class'])->toBe(IntegrationConnectedEvent::class);
            expect($entry['ga4'])->toBe('integration_connected');
            expect($entry['meta'])->toBe('IntegrationConnected');
        });

        test('all SaaS event classes exist', function (): void {
            foreach (SaaSEvents::all() as $name => $entry) {
                expect(class_exists($entry['class']))->toBeTrue(
                    "SaaS event '{$name}' class {$entry['class']} does not exist"
                );
            }
        });
    });

    describe('EngagementEvents catalog expansion', function (): void {
        test('now has 21 events (was 19)', function (): void {
            expect(EngagementEvents::count())->toBe(21);
        });

        test('file_download exists in catalog', function (): void {
            expect(EngagementEvents::has('file_download'))->toBeTrue();
            $entry = EngagementEvents::get('file_download');
            expect($entry['class'])->toBe(FileDownloadEvent::class);
            expect($entry['ga4'])->toBe('file_download');
            expect($entry['meta'])->toBe('FileDownload');
        });

        test('video_play exists in catalog', function (): void {
            expect(EngagementEvents::has('video_play'))->toBeTrue();
            $entry = EngagementEvents::get('video_play');
            expect($entry['class'])->toBe(VideoPlayEvent::class);
            expect($entry['ga4'])->toBe('video_play');
            expect($entry['meta'])->toBe('VideoPlay');
        });

        test('all engagement event classes exist', function (): void {
            foreach (EngagementEvents::all() as $name => $entry) {
                expect(class_exists($entry['class']))->toBeTrue(
                    "Engagement event '{$name}' class {$entry['class']} does not exist"
                );
            }
        });
    });

    describe('EventCatalog unified total', function (): void {
        test('total event count is now 52', function (): void {
            expect(EventCatalog::count())->toBe(52);
        });

        test('breakdown: 12 ecommerce + 19 saas + 21 engagement', function (): void {
            $summary = EventCatalog::byCategory();
            expect(count($summary['ecommerce']))->toBe(12);
            expect(count($summary['saas']))->toBe(19);
            expect(count($summary['engagement']))->toBe(21);
        });
    });

    // ── EventCatalog Validation ────────────────────────────────────

    describe('EventCatalog::requiredKeys()', function (): void {
        test('returns name, class, ga4, category', function (): void {
            $keys = EventCatalog::requiredKeys();
            expect($keys)->toContain('name');
            expect($keys)->toContain('class');
            expect($keys)->toContain('ga4');
            expect($keys)->toContain('category');
            expect($keys)->toHaveCount(4);
        });
    });

    describe('EventCatalog::validate()', function (): void {
        test('returns valid result with no errors', function (): void {
            $result = EventCatalog::validate();
            expect($result['valid'])->toBeTrue();
            expect($result['errors'])->toBeEmpty();
        });

        test('result has valid, errors, and warnings keys', function (): void {
            $result = EventCatalog::validate();
            expect($result)->toHaveKeys(['valid', 'errors', 'warnings']);
        });

        test('errors is a list of strings', function (): void {
            $result = EventCatalog::validate();
            expect($result['errors'])->toBeArray();
        });

        test('warnings is a list of strings', function (): void {
            $result = EventCatalog::validate();
            expect($result['warnings'])->toBeArray();
        });
    });

    // ── AnalyticsManager Convenience Methods ────────────────────────

    describe('inviteSent convenience', function (): void {
        test('calls track with correct event name and params', function (): void {
            $manager = Mockery::mock(AnalyticsManager::class, [$this->config])->makePartial();
            $manager->shouldReceive('track')
                ->once()
                ->with('invite_sent', Mockery::on(function (array $params): bool {
                    return $params['invite_type'] === 'team_member'
                        && $params['role'] === 'editor';
                }))
                ->andReturnNull();

            $manager->inviteSent('team_member', 'editor');
        });
    });

    describe('integrationConnected convenience', function (): void {
        test('calls track with correct event name and params', function (): void {
            $manager = Mockery::mock(AnalyticsManager::class, [$this->config])->makePartial();
            $manager->shouldReceive('track')
                ->once()
                ->with('integration_connected', Mockery::on(function (array $params): bool {
                    return $params['integration_name'] === 'slack';
                }))
                ->andReturnNull();

            $manager->integrationConnected('slack');
        });
    });

    describe('fileDownload convenience', function (): void {
        test('calls track with correct event name and params', function (): void {
            $manager = Mockery::mock(AnalyticsManager::class, [$this->config])->makePartial();
            $manager->shouldReceive('track')
                ->once()
                ->with('file_download', Mockery::on(function (array $params): bool {
                    return $params['file_name'] === 'report.pdf'
                        && $params['file_type'] === 'pdf';
                }))
                ->andReturnNull();

            $manager->fileDownload('report.pdf', 'pdf');
        });
    });

    describe('videoPlay convenience', function (): void {
        test('calls track with correct event name and params', function (): void {
            $manager = Mockery::mock(AnalyticsManager::class, [$this->config])->makePartial();
            $manager->shouldReceive('track')
                ->once()
                ->with('video_play', Mockery::on(function (array $params): bool {
                    return $params['video_title'] === 'Onboarding'
                        && $params['video_provider'] === 'wistia';
                }))
                ->andReturnNull();

            $manager->videoPlay('Onboarding', 'wistia');
        });
    });

    describe('validateCatalog convenience', function (): void {
        test('delegates to EventCatalog::validate()', function (): void {
            $manager = Mockery::mock(AnalyticsManager::class, [$this->config])->makePartial();
            $result = $manager->validateCatalog();
            expect($result)->toBeArray();
            expect($result)->toHaveKey('valid');
        });
    });

    // ── Version Consistency ────────────────────────────────────────

    describe('version consistency', function (): void {
        test('manager version is 2.35.0', function (): void {
            $manager = new AnalyticsManager($this->config);
            expect($manager->version())->toBe('2.93.0');
        });

        test('event catalog summary shows new counts', function (): void {
            $manager = new AnalyticsManager($this->config);
            $summary = $manager->eventCatalogSummary();
            expect($summary['ecommerce'])->toBe(12);
            expect($summary['saas'])->toBe(19);
            expect($summary['engagement'])->toBe(21);
            expect($summary['total'])->toBe(52);
        });

        test('totalEventCount matches catalog count', function (): void {
            $manager = new AnalyticsManager($this->config);
            expect($manager->totalEventCount())->toBe(EventCatalog::count());
        });
    });

    // ── AnalyticsConfig New Accessors ───────────────────────────────

    describe('AnalyticsConfig new accessors', function (): void {
        beforeEach(function (): void {
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.referral.enabled', false)
                ->andReturn(false);
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.referral.param_name', 'ref')
                ->andReturn('ref');
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.referral.ttl', 2592000)
                ->andReturn(2592000);
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.referral.track_conversions', true)
                ->andReturn(true);
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.broadcast.alert_channel', 'analytics.alerts')
                ->andReturn('analytics.alerts');
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.broadcast.metrics_channel', 'analytics.metrics')
                ->andReturn('analytics.metrics');
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.retention_policy.engagement_days', 30)
                ->andReturn(30);
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.retention_policy.saas_days', 90)
                ->andReturn(90);
            $this->config->shouldReceive('get')
                ->with('zeroboiler.analytics.retention_policy.ecommerce_days', 365)
                ->andReturn(365);
        });

        test('referral accessors return correct defaults', function (): void {
            $ac = new AnalyticsConfig($this->config);
            expect($ac->referralEnabled())->toBeFalse();
            expect($ac->referralParamName())->toBe('ref');
            expect($ac->referralTtl())->toBe(2592000);
            expect($ac->referralTrackConversions())->toBeTrue();
        });

        test('broadcast channel accessors return correct defaults', function (): void {
            $ac = new AnalyticsConfig($this->config);
            expect($ac->broadcastAlertChannel())->toBe('analytics.alerts');
            expect($ac->broadcastMetricsChannel())->toBe('analytics.metrics');
        });

        test('retention policy day accessors return correct defaults', function (): void {
            $ac = new AnalyticsConfig($this->config);
            expect($ac->retentionPolicyEngagementDays())->toBe(30);
            expect($ac->retentionPolicySaasDays())->toBe(90);
            expect($ac->retentionPolicyEcommerceDays())->toBe(365);
        });
    });

    // ── Cross-Provider Mapping ──────────────────────────────────────

    describe('new events in provider mappings', function (): void {
        test('invite_sent has GA4 and Meta mappings', function (): void {
            $entry = EventCatalog::get('invite_sent');
            expect($entry['ga4'])->toBe('invite_sent');
            expect($entry['meta'])->toBe('InviteSent');
            expect($entry['category'])->toBe('saas');
        });

        test('integration_connected has GA4 and Meta mappings', function (): void {
            $entry = EventCatalog::get('integration_connected');
            expect($entry['ga4'])->toBe('integration_connected');
            expect($entry['meta'])->toBe('IntegrationConnected');
            expect($entry['category'])->toBe('saas');
        });

        test('file_download has GA4 and Meta mappings', function (): void {
            $entry = EventCatalog::get('file_download');
            expect($entry['ga4'])->toBe('file_download');
            expect($entry['meta'])->toBe('FileDownload');
            expect($entry['category'])->toBe('engagement');
        });

        test('video_play has GA4 and Meta mappings', function (): void {
            $entry = EventCatalog::get('video_play');
            expect($entry['ga4'])->toBe('video_play');
            expect($entry['meta'])->toBe('VideoPlay');
            expect($entry['category'])->toBe('engagement');
        });

        test('new events appear in allGa4Names()', function (): void {
            $ga4Names = EventCatalog::allGa4Names();
            expect($ga4Names)->toContain('invite_sent');
            expect($ga4Names)->toContain('integration_connected');
            expect($ga4Names)->toContain('file_download');
            expect($ga4Names)->toContain('video_play');
        });

        test('new events appear in allMetaNames()', function (): void {
            $metaNames = EventCatalog::allMetaNames();
            expect($metaNames)->toContain('InviteSent');
            expect($metaNames)->toContain('IntegrationConnected');
            expect($metaNames)->toContain('FileDownload');
            expect($metaNames)->toContain('VideoPlay');
        });
    });

    // ── File Existence ─────────────────────────────────────────────

    describe('file structure', function (): void {
        test('all new event class files exist', function (): void {
            $base = dirname(__DIR__, 2) . '/src';
            expect(file_exists("{$base}/Events/SaaS/InviteSentEvent.php"))->toBeTrue();
            expect(file_exists("{$base}/Events/SaaS/IntegrationConnectedEvent.php"))->toBeTrue();
            expect(file_exists("{$base}/Events/Engagement/FileDownloadEvent.php"))->toBeTrue();
            expect(file_exists("{$base}/Events/Engagement/VideoPlayEvent.php"))->toBeTrue();
        });
    });
});
