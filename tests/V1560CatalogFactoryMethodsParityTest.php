<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Infrastructure\InfrastructureEvents;
use ZeroBoiler\Analytics\Events\Marketing\MarketingEvents;
use ZeroBoiler\Analytics\Events\SaaS\CustomerSuccessEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Security\SecurityEvents;
use ZeroBoiler\Analytics\Events\Uptime\UptimeEvents;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

beforeEach(function (): void {
    //
});

describe('v156.0.0 — All 8 Catalogs Have Typed Factory Method Parity', function (): void {

    // ── Infrastructure Catalog ────────────────────────────────────

    describe('InfrastructureEvents typed factory methods', function (): void {
        test('has category() method returning infrastructure', function (): void {
            expect(InfrastructureEvents::category())->toBe('infrastructure');
        });

        test('has typed factory for all 10 events', function (): void {
            $reflection = new ReflectionClass(InfrastructureEvents::class);
            $expectedMethods = [
                'featureFlagEvaluated',
                'experimentExposed',
                'errorBudgetBurned',
                'sloBreach',
                'deploymentRolledBack',
                'incidentStarted',
                'incidentResolved',
                'maintenanceStarted',
                'maintenanceEnded',
                'pipelineFailure',
            ];

            foreach ($expectedMethods as $method) {
                expect($reflection->hasMethod($method))->toBeTrue("Missing factory method: {$method}");
                $m = $reflection->getMethod($method);
                expect($m->isPublic())->toBeTrue("{$method} should be public");
                expect($m->getReturnType()?->getName())->toBe(AnalyticsEvent::class);
            }
        });

        test('factory methods return correct event name and category', function (): void {
            $event = InfrastructureEvents::incidentStarted(['severity' => 'critical']);
            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('incident_started');
            expect($event->category)->toBe('infrastructure');
            expect($event->params)->toHaveKey('severity');
        });

        test('build() generic factory validates event name', function (): void {
            $event = InfrastructureEvents::build('deployment', ['version' => 'v2.0']);
            expect($event->name)->toBe('deployment');
            expect($event->category)->toBe('infrastructure');
        });

        test('build() throws for unknown event', function (): void {
            InfrastructureEvents::build('nonexistent_event');
        })->throws(InvalidArgumentException::class);

        test('has strict types declaration', function (): void {
            $contents = file_get_contents((new ReflectionClass(InfrastructureEvents::class))->getFileName());
            expect($contents)->toContain('declare(strict_types=1)');
        });

        test('has AnalyticsEvent import', function (): void {
            $contents = file_get_contents((new ReflectionClass(InfrastructureEvents::class))->getFileName());
            expect($contents)->toContain('use ZeroBoiler\Analytics\DTO\AnalyticsEvent');
        });
    });

    // ── Security Catalog ───────────────────────────────────────────

    describe('SecurityEvents typed factory methods', function (): void {
        test('has typed factory for all 6 events', function (): void {
            $reflection = new ReflectionClass(SecurityEvents::class);
            $expectedMethods = [
                'loginAttempt',
                'suspiciousActivity',
                'dataAccessAudit',
                'rateLimitExceeded',
                'mfaChallenge',
                'aiAgentAccess',
            ];

            foreach ($expectedMethods as $method) {
                expect($reflection->hasMethod($method))->toBeTrue("Missing factory method: {$method}");
                $m = $reflection->getMethod($method);
                expect($m->isPublic())->toBeTrue();
                expect($m->getReturnType()?->getName())->toBe(AnalyticsEvent::class);
            }
        });

        test('factory methods return correct event name and category', function (): void {
            $event = SecurityEvents::mfaChallenge(['method' => 'totp', 'success' => true]);
            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('mfa_challenge');
            expect($event->category)->toBe('security');
            expect($event->params['method'])->toBe('totp');
        });

        test('build() generic factory validates event name', function (): void {
            $event = SecurityEvents::build('suspicious_activity', ['severity' => 'high']);
            expect($event->name)->toBe('suspicious_activity');
            expect($event->category)->toBe('security');
        });

        test('build() throws for unknown event', function (): void {
            SecurityEvents::build('nonexistent_security_event');
        })->throws(InvalidArgumentException::class);

        test('has strict types declaration', function (): void {
            $contents = file_get_contents((new ReflectionClass(SecurityEvents::class))->getFileName());
            expect($contents)->toContain('declare(strict_types=1)');
        });
    });

    // ── Uptime Catalog ────────────────────────────────────────────

    describe('UptimeEvents typed factory methods', function (): void {
        test('has typed factory for all 5 events', function (): void {
            $reflection = new ReflectionClass(UptimeEvents::class);
            $expectedMethods = [
                'serviceUp',
                'serviceDown',
                'apiLatency',
                'errorSpike',
                'deployment',
            ];

            foreach ($expectedMethods as $method) {
                expect($reflection->hasMethod($method))->toBeTrue("Missing factory method: {$method}");
                $m = $reflection->getMethod($method);
                expect($m->isPublic())->toBeTrue();
                expect($m->getReturnType()?->getName())->toBe(AnalyticsEvent::class);
            }
        });

        test('factory methods return correct event name and category', function (): void {
            $event = UptimeEvents::errorSpike(['error_count' => 500, 'window' => '5m']);
            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('error_spike');
            expect($event->category)->toBe('uptime');
        });

        test('build() generic factory validates event name', function (): void {
            $event = UptimeEvents::build('service_down', ['service' => 'api-gateway']);
            expect($event->name)->toBe('service_down');
            expect($event->category)->toBe('uptime');
        });

        test('build() throws for unknown event', function (): void {
            UptimeEvents::build('nonexistent_uptime_event');
        })->throws(InvalidArgumentException::class);

        test('has strict types declaration', function (): void {
            $contents = file_get_contents((new ReflectionClass(UptimeEvents::class))->getFileName());
            expect($contents)->toContain('declare(strict_types=1)');
        });
    });

    // ── CustomerSuccess Catalog ────────────────────────────────────

    describe('CustomerSuccessEvents typed factory methods', function (): void {
        test('has typed factory for all 7 events', function (): void {
            $reflection = new ReflectionClass(CustomerSuccessEvents::class);
            $expectedMethods = [
                'supportTicketCreated',
                'npsSubmitted',
                'healthScoreChanged',
                'renewalReminderSent',
                'churnInterview',
                'customerReview',
                'onboardingCallCompleted',
            ];

            foreach ($expectedMethods as $method) {
                expect($reflection->hasMethod($method))->toBeTrue("Missing factory method: {$method}");
                $m = $reflection->getMethod($method);
                expect($m->isPublic())->toBeTrue();
                expect($m->getReturnType()?->getName())->toBe(AnalyticsEvent::class);
            }
        });

        test('factory methods return correct event name and category', function (): void {
            $event = CustomerSuccessEvents::npsSubmitted(['score' => 9, 'promoter' => true]);
            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('nps_submitted');
            expect($event->category)->toBe('customer_success');
            expect($event->params['score'])->toBe(9);
        });

        test('build() generic factory validates event name', function (): void {
            $event = CustomerSuccessEvents::build('churn_interview', ['reason' => 'pricing']);
            expect($event->name)->toBe('churn_interview');
            expect($event->category)->toBe('customer_success');
        });

        test('build() throws for unknown event', function (): void {
            CustomerSuccessEvents::build('nonexistent_cs_event');
        })->throws(InvalidArgumentException::class);

        test('has strict types declaration', function (): void {
            $contents = file_get_contents((new ReflectionClass(CustomerSuccessEvents::class))->getFileName());
            expect($contents)->toContain('declare(strict_types=1)');
        });
    });

    // ── Marketing Catalog ──────────────────────────────────────────

    describe('MarketingEvents typed factory methods', function (): void {
        test('has typed factory for all 34 events', function (): void {
            $reflection = new ReflectionClass(MarketingEvents::class);
            $expectedMethods = [
                'emailSent',
                'emailDelivered',
                'emailOpened',
                'emailClicked',
                'emailBounced',
                'emailUnsubscribed',
                'emailMarkedSpam',
                'leadCaptured',
                'leadQualified',
                'leadScoreChanged',
                'blogView',
                'contentDownloaded',
                'newsletterSubscribed',
                'socialShare',
                'socialFollow',
                'socialComment',
                'socialMention',
                'adImpression',
                'adClick',
                'adConversion',
                'webinarRegistered',
                'webinarAttended',
                'webinarEngagement',
                'smsSent',
                'smsDelivered',
                'smsClicked',
                'pushNotificationSent',
                'pushNotificationOpened',
                'referralLinkShared',
                'referralConversion',
                'affiliateSignup',
                'affiliateCommission',
                'attributionTouchpoint',
                'campaignResponse',
            ];

            foreach ($expectedMethods as $method) {
                expect($reflection->hasMethod($method))->toBeTrue("Missing factory method: {$method}");
                $m = $reflection->getMethod($method);
                expect($m->isPublic())->toBeTrue();
                expect($m->getReturnType()?->getName())->toBe(AnalyticsEvent::class);
            }
        });

        test('factory methods return correct event name and category', function (): void {
            $event = MarketingEvents::adConversion(['campaign' => 'summer2026', 'value' => 49.99]);
            expect($event)->toBeInstanceOf(AnalyticsEvent::class);
            expect($event->name)->toBe('ad_conversion');
            expect($event->category)->toBe('marketing');
            expect($event->params['value'])->toBe(49.99);
        });

        test('email funnel factory chain produces correct names', function (): void {
            $sent = MarketingEvents::emailSent(['campaign' => 'welcome']);
            $opened = MarketingEvents::emailOpened(['campaign' => 'welcome']);
            $clicked = MarketingEvents::emailClicked(['campaign' => 'welcome']);

            expect($sent->name)->toBe('email_sent');
            expect($opened->name)->toBe('email_opened');
            expect($clicked->name)->toBe('email_clicked');
            expect($sent->category)->toBe($opened->category);
            expect($opened->category)->toBe($clicked->category);
        });

        test('build() generic factory validates event name', function (): void {
            $event = MarketingEvents::build('social_share', ['platform' => 'twitter']);
            expect($event->name)->toBe('social_share');
            expect($event->category)->toBe('marketing');
        });

        test('build() throws for unknown event', function (): void {
            MarketingEvents::build('nonexistent_marketing_event');
        })->throws(InvalidArgumentException::class);

        test('has strict types declaration', function (): void {
            $contents = file_get_contents((new ReflectionClass(MarketingEvents::class))->getFileName());
            expect($contents)->toContain('declare(strict_types=1)');
        });

        test('has AnalyticsEvent import', function (): void {
            $contents = file_get_contents((new ReflectionClass(MarketingEvents::class))->getFileName());
            expect($contents)->toContain('use ZeroBoiler\Analytics\DTO\AnalyticsEvent');
        });
    });

    // ── Cross-Catalog Consistency ─────────────────────────────────

    describe('Cross-catalog factory method consistency', function (): void {
        test('all 8 catalogs have build() generic factory', function (): void {
            $catalogs = [
                EcommerceEvents::class,
                SaaSEvents::class,
                EngagementEvents::class,
                MarketingEvents::class,
                CustomerSuccessEvents::class,
                SecurityEvents::class,
                UptimeEvents::class,
                InfrastructureEvents::class,
            ];

            foreach ($catalogs as $catalog) {
                $reflection = new ReflectionClass($catalog);
                expect($reflection->hasMethod('build'))->toBeTrue("{$catalog} missing build() factory");
            }
        });

        test('all 8 catalogs have category() method', function (): void {
            $catalogs = [
                EcommerceEvents::class,
                SaaSEvents::class,
                EngagementEvents::class,
                MarketingEvents::class,
                CustomerSuccessEvents::class,
                SecurityEvents::class,
                UptimeEvents::class,
                InfrastructureEvents::class,
            ];

            $categories = ['ecommerce', 'saas', 'engagement', 'marketing', 'customer_success', 'security', 'uptime', 'infrastructure'];

            foreach ($catalogs as $i => $catalog) {
                $reflection = new ReflectionClass($catalog);
                expect($reflection->hasMethod('category'))->toBeTrue("{$catalog} missing category() method");

                $method = $reflection->getMethod('category');
                $method->setAccessible(true);
                expect($method->invoke(null))->toBe($categories[$i]);
            }
        });

        test('all factory methods are static and public', function (): void {
            $catalogs = [
                MarketingEvents::class,
                CustomerSuccessEvents::class,
                SecurityEvents::class,
                UptimeEvents::class,
                InfrastructureEvents::class,
            ];

            foreach ($catalogs as $catalog) {
                $reflection = new ReflectionClass($catalog);
                $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

                $factoryMethods = array_filter($methods, function (ReflectionMethod $m): bool {
                    $returnType = $m->getReturnType()?->getName();
                    return $m->isStatic()
                        && $returnType === AnalyticsEvent::class
                        && $m->getName() !== 'build';
                });

                foreach ($factoryMethods as $factoryMethod) {
                    expect($factoryMethod->isStatic())->toBeTrue("{$catalog}::{$factoryMethod->getName()} must be static");
                    expect($factoryMethod->isPublic())->toBeTrue("{$catalog}::{$factoryMethod->getName()} must be public");
                }
            }
        });

        test('all factory events have correct category matching their catalog', function (): void {
            // Test a sample from each catalog
            $tests = [
                [InfrastructureEvents::class, 'incidentStarted', 'incident_started', 'infrastructure'],
                [SecurityEvents::class, 'loginAttempt', 'login_attempt', 'security'],
                [UptimeEvents::class, 'apiLatency', 'api_latency', 'uptime'],
                [CustomerSuccessEvents::class, 'healthScoreChanged', 'health_score_changed', 'customer_success'],
                [MarketingEvents::class, 'adClick', 'ad_click', 'marketing'],
            ];

            foreach ($tests as [$class, $method, $expectedName, $expectedCategory]) {
                $reflection = new ReflectionClass($class);
                $methodReflection = $reflection->getMethod($method);
                $event = $methodReflection->invoke(null, []);

                expect($event->name)->toBe($expectedName, "{$class}::{$method} name mismatch");
                expect($event->category)->toBe($expectedCategory, "{$class}::{$method} category mismatch");
            }
        });
    });

    // ── Version Consistency ────────────────────────────────────────

    describe('Version consistency across all entry points', function (): void {
        test('AnalyticsEvent::VERSION is 156.0.0', function (): void {
            expect(AnalyticsEvent::VERSION)->toBe('156.0.0');
        });

        test('EventCatalog count is consistent', function (): void {
            $total = EventCatalog::count();
            $byCategory = EventCatalog::byCategory();
            $sum = 0;
            foreach ($byCategory as $events) {
                $sum += count($events);
            }
            expect($sum)->toBe($total);
            expect($total)->toBeGreaterThan(210);
        });
    });

    // ── Factory Method Count Validation ────────────────────────────

    describe('Factory method count per catalog', function (): void {
        test('MarketingEvents has 34 catalog events + build() = 35 factory methods', function (): void {
            $reflection = new ReflectionClass(MarketingEvents::class);
            $methods = array_filter(
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC),
                fn (ReflectionMethod $m): bool => $m->getReturnType()?->getName() === AnalyticsEvent::class,
            );
            // 34 typed + 1 build() + names() etc
            expect(count($methods))->toBeGreaterThanOrEqual(35);
        });

        test('CustomerSuccessEvents has 7 catalog events + build() = 8 factory methods', function (): void {
            $reflection = new ReflectionClass(CustomerSuccessEvents::class);
            $methods = array_filter(
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC),
                fn (ReflectionMethod $m): bool => $m->getReturnType()?->getName() === AnalyticsEvent::class,
            );
            expect(count($methods))->toBeGreaterThanOrEqual(8);
        });

        test('SecurityEvents has 6 catalog events + build() = 7 factory methods', function (): void {
            $reflection = new ReflectionClass(SecurityEvents::class);
            $methods = array_filter(
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC),
                fn (ReflectionMethod $m): bool => $m->getReturnType()?->getName() === AnalyticsEvent::class,
            );
            expect(count($methods))->toBeGreaterThanOrEqual(7);
        });

        test('UptimeEvents has 5 catalog events + build() = 6 factory methods', function (): void {
            $reflection = new ReflectionClass(UptimeEvents::class);
            $methods = array_filter(
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC),
                fn (ReflectionMethod $m): bool => $m->getReturnType()?->getName() === AnalyticsEvent::class,
            );
            expect(count($methods))->toBeGreaterThanOrEqual(6);
        });

        test('InfrastructureEvents has 10 catalog events + build() = 11 factory methods', function (): void {
            $reflection = new ReflectionClass(InfrastructureEvents::class);
            $methods = array_filter(
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC),
                fn (ReflectionMethod $m): bool => $m->getReturnType()?->getName() === AnalyticsEvent::class,
            );
            expect(count($methods))->toBeGreaterThanOrEqual(11);
        });
    });
});
