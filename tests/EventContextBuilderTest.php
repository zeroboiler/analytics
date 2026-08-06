<?php

declare(strict_types=1);

use ZeroBoiler\Analytics\Context\EventContextBuilder;

describe('EventContextBuilder', function () {
    describe('with no request (CLI context)', function () {
        it('builds empty context when no request is given', function () {
            $builder = new EventContextBuilder;
            $context = $builder->build();

            expect($context)->toBe([]);
        });

        it('allows manual property setting without request', function () {
            $builder = new EventContextBuilder;
            $context = $builder
                ->set('session_id', 'cli-session-123')
                ->set('environment', 'testing')
                ->getContext();

            expect($context['session_id'])->toBe('cli-session-123');
            expect($context['environment'])->toBe('testing');
        });
    });

    describe('withUserIdentity', function () {
        it('can accept override user context', function () {
            $builder = new EventContextBuilder;
            $context = $builder
                ->withUserIdentity([
                    'user_id' => '42',
                    'user_plan' => 'pro',
                    'user_email_hash' => hash('sha256', 'user@example.com'),
                ])
                ->getContext();

            expect($context['user_id'])->toBe('42');
            expect($context['user_plan'])->toBe('pro');
        });

        it('clears user context between builds', function () {
            $builder = new EventContextBuilder;
            $builder->withUserIdentity(['user_id' => '42']);
            $builder->clear();

            $context = $builder->getContext();
            expect($context)->not()->toHaveKey('user_id');
        });
    });

    describe('withClientId', function () {
        it('can accept override client ID', function () {
            $builder = new EventContextBuilder;
            $context = $builder
                ->withClientId('override-client-id-123')
                ->getContext();

            expect($context['client_id'])->toBe('override-client-id-123');
        });
    });

    describe('withUTM', function () {
        it('can accept override UTM params', function () {
            $builder = new EventContextBuilder;
            $context = $builder
                ->withUTM([
                    'utm_source' => 'google',
                    'utm_medium' => 'cpc',
                    'utm_campaign' => 'spring_sale',
                ])
                ->getContext();

            expect($context['utm_source'])->toBe('google');
            expect($context['utm_medium'])->toBe('cpc');
            expect($context['utm_campaign'])->toBe('spring_sale');
        });
    });

    describe('withPage', function () {
        it('can accept override page context', function () {
            $builder = new EventContextBuilder;
            $context = $builder
                ->withPage([
                    'page_url' => 'https://example.com/pricing',
                    'page_path' => '/pricing',
                ])
                ->getContext();

            expect($context['page_url'])->toBe('https://example.com/pricing');
            expect($context['page_path'])->toBe('/pricing');
        });
    });

    describe('withDevice', function () {
        it('can accept override device context', function () {
            $builder = new EventContextBuilder;
            $context = $builder
                ->withDevice([
                    'ip' => '192.168.1.1',
                    'user_agent' => 'TestBot/1.0',
                    'locale' => 'en',
                ])
                ->getContext();

            expect($context['ip'])->toBe('192.168.1.1');
            expect($context['user_agent'])->toBe('TestBot/1.0');
        });
    });

    describe('withSession', function () {
        it('can accept override session ID', function () {
            $builder = new EventContextBuilder;
            $context = $builder
                ->withSession('session-abc-123')
                ->getContext();

            expect($context['session_id'])->toBe('session-abc-123');
        });
    });

    describe('withCustom', function () {
        it('adds custom properties', function () {
            $builder = new EventContextBuilder;
            $context = $builder
                ->withCustom([
                    'app_version' => '1.4.0',
                    'feature_flags' => ['new_dashboard' => true],
                ])
                ->getContext();

            expect($context['app_version'])->toBe('1.4.0');
            expect($context['feature_flags'])->toBe(['new_dashboard' => true]);
        });
    });

    describe('without', function () {
        it('removes a key from context', function () {
            $builder = new EventContextBuilder;
            $context = $builder
                ->set('user_id', '42')
                ->without('user_id')
                ->getContext();

            expect($context)->not()->toHaveKey('user_id');
        });
    });

    describe('has / get', function () {
        it('checks for key existence', function () {
            $builder = new EventContextBuilder;
            $builder->set('user_id', '42');

            expect($builder->has('user_id'))->toBeTrue();
            expect($builder->has('nonexistent'))->toBeFalse();
        });

        it('gets a value with default', function () {
            $builder = new EventContextBuilder;

            expect($builder->get('missing_key', 'default_value'))->toBe('default_value');
        });
    });

    describe('composable builder pattern', function () {
        it('chains multiple collectors', function () {
            $builder = new EventContextBuilder;
            $context = $builder
                ->withUserIdentity(['user_id' => '42'])
                ->withClientId('client-123')
                ->withSession('session-456')
                ->withUTM(['utm_source' => 'twitter'])
                ->withCustom(['app_version' => '1.4.0'])
                ->getContext();

            expect($context['user_id'])->toBe('42');
            expect($context['client_id'])->toBe('client-123');
            expect($context['session_id'])->toBe('session-456');
            expect($context['utm_source'])->toBe('twitter');
            expect($context['app_version'])->toBe('1.4.0');
        });
    });
});
