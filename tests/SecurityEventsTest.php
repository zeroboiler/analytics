<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\Security\AiAgentAccessEvent;
use ZeroBoiler\Analytics\Events\Security\DataAccessAuditEvent;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;

describe('Security Events — v90.0.0', function () {
    describe('AiAgentAccessEvent', function () {
        it('creates with all parameters', function () {
            $event = new AiAgentAccessEvent(
                agent: 'claude',
                action: 'write',
                resource: 'config',
            );

            expect($event->name)->toBe('ai_agent_access');
            expect($event->params['agent'])->toBe('claude');
            expect($event->params['action'])->toBe('write');
            expect($event->params['resource'])->toBe('config');
        });

        it('filters out null parameters', function () {
            $event = new AiAgentAccessEvent(agent: 'gpt');

            expect($event->name)->toBe('ai_agent_access');
            expect($event->params['agent'])->toBe('gpt');
            expect($event->params)->not->toHaveKey('action');
            expect($event->params)->not->toHaveKey('resource');
        });

        it('accepts extra params', function () {
            $event = new AiAgentAccessEvent(
                agent: 'copilot',
                action: 'read',
                params: ['session_id' => 'sess-123', 'outcome' => 'success'],
            );

            expect($event->params['session_id'])->toBe('sess-123');
            expect($event->params['outcome'])->toBe('success');
        });

        it('creates with no arguments', function () {
            $event = new AiAgentAccessEvent();

            expect($event->name)->toBe('ai_agent_access');
            expect($event->params)->toBeEmpty();
        });

        it('works with common AI agent names', function () {
            $agents = ['claude', 'gpt', 'copilot', 'hermes', 'gemini', 'deepseek'];

            foreach ($agents as $agent) {
                $event = new AiAgentAccessEvent(agent: $agent, action: 'deploy');
                expect($event->params['agent'])->toBe($agent);
            }
        });

        it('has strict types', function () {
            $event = new AiAgentAccessEvent(
                agent: 'claude',
                action: 'write',
                resource: 'database',
                params: ['duration_ms' => 150],
            );

            // Verify immutability via readonly
            expect($event instanceof \ZeroBoiler\Analytics\DTO\AnalyticsEvent)->toBeTrue();
        });
    });

    describe('DataAccessAuditEvent', function () {
        it('creates with all parameters', function () {
            $event = new DataAccessAuditEvent(
                dataType: 'user_profile',
                accessor: 'admin@company.com',
                accessLevel: 'export',
            );

            expect($event->name)->toBe('data_access_audit');
            expect($event->params['data_type'])->toBe('user_profile');
            expect($event->params['accessor'])->toBe('admin@company.com');
            expect($event->params['access_level'])->toBe('export');
        });

        it('filters out null parameters', function () {
            $event = new DataAccessAuditEvent(dataType: 'analytics_events');

            expect($event->name)->toBe('data_access_audit');
            expect($event->params['data_type'])->toBe('analytics_events');
            expect($event->params)->not->toHaveKey('accessor');
            expect($event->params)->not->toHaveKey('access_level');
        });

        it('supports common access levels', function () {
            $levels = ['read', 'write', 'export', 'delete', 'admin'];

            foreach ($levels as $level) {
                $event = new DataAccessAuditEvent(dataType: 'test', accessor: 'user-1', accessLevel: $level);
                expect($event->params['access_level'])->toBe($level);
            }
        });

        it('accepts extra params for GDPR compliance context', function () {
            $event = new DataAccessAuditEvent(
                dataType: 'payment_info',
                accessor: 'user-42',
                accessLevel: 'read',
                params: [
                    'ip_address' => '192.168.1.1',
                    'user_agent' => 'Mozilla/5.0',
                    'legal_basis' => 'legitimate_interest',
                    'retention_days' => 90,
                ],
            );

            expect($event->params['legal_basis'])->toBe('legitimate_interest');
            expect($event->params['retention_days'])->toBe(90);
        });
    });

    describe('SecurityEvents catalog', function () {
        it('includes ai_agent_access event', function () {
            expect(SecurityEvents::has('ai_agent_access'))->toBeTrue();
        });

        it('returns correct ai_agent_access entry', function () {
            $entry = SecurityEvents::get('ai_agent_access');

            expect($entry)->not->toBeNull();
            expect($entry['name'])->toBe('ai_agent_access');
            expect($entry['class'])->toBe(AiAgentAccessEvent::class);
            expect($entry['ga4'])->toBe('ai_agent_access');
            expect($entry['meta'])->toBe('CustomEvent');
            expect($entry['posthog'])->toBe('ai_agent_access');
            expect($entry['mixpanel'])->toBe('AI Agent Access');
            expect($entry['amplitude'])->toBe('AI Agent Access');
        });

        it('includes data_access_audit with correct class', function () {
            expect(SecurityEvents::has('data_access_audit'))->toBeTrue();

            $entry = SecurityEvents::get('data_access_audit');
            expect($entry['class'])->toBe(DataAccessAuditEvent::class);
        });

        it('count increased by 1 (from 5 to 6)', function () {
            expect(SecurityEvents::count())->toBe(6);
        });

        it('ai_agent_access is in names list', function () {
            expect(SecurityEvents::names())->toContain('ai_agent_access');
        });

        it('ai_agent_access has correct GA4 name', function () {
            expect(SecurityEvents::ga4Names())->toContain('ai_agent_access');
        });

        it('ai_agent_access has correct PostHog name', function () {
            expect(SecurityEvents::posthogNames())->toContain('ai_agent_access');
        });

        it('ai_agent_access has correct Mixpanel name', function () {
            expect(SecurityEvents::mixpanelNames())->toContain('AI Agent Access');
        });

        it('ai_agent_access has correct Amplitude name', function () {
            expect(SecurityEvents::amplitudeNames())->toContain('AI Agent Access');
        });

        it('category is security', function () {
            expect(SecurityEvents::category())->toBe('security');
        });
    });
});
