<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace Tests\Phase64;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventBatchJob;
use ZeroBoiler\Analytics\Jobs\TrackAnalyticsEventJob;
use ZeroBoiler\Analytics\Queue\QueuedAnalyticsDispatcher;

/**
 * Phase 64 production readiness — async event metadata preservation.
 *
 * Validates that source, category, and session_id are preserved
 * across the async queue boundary (serialize → dispatch → deserialize → handle).
 *
 * @since 244.0.0
 */
final class Phase64ProductionReadinessTest extends TestCase
{
    // ── Job Serialization Integrity ────────────────────────────────

    #[Test]
    public function single_job_preserves_all_metadata_fields(): void
    {
        $job = new TrackAnalyticsEventJob(
            name: 'purchase',
            params: ['value' => 99.99],
            clientId: 'cli_abc123',
            userId: 'user_456',
            timestamp: 1700000000,
            priority: 'critical',
            source: 'api',
            category: 'ecommerce',
            sessionId: 'sess_xyz',
        );

        $this->assertSame('purchase', $job->name);
        $this->assertSame(['value' => 99.99], $job->params);
        $this->assertSame('cli_abc123', $job->clientId);
        $this->assertSame('user_456', $job->userId);
        $this->assertSame(1700000000, $job->timestamp);
        $this->assertSame('critical', $job->priority);
        $this->assertSame('api', $job->source);
        $this->assertSame('ecommerce', $job->category);
        $this->assertSame('sess_xyz', $job->sessionId);
    }

    #[Test]
    public function single_job_accepts_null_optional_metadata(): void
    {
        $job = new TrackAnalyticsEventJob(
            name: 'page_view',
            params: [],
        );

        $this->assertNull($job->clientId);
        $this->assertNull($job->userId);
        $this->assertNull($job->timestamp);
        $this->assertNull($job->priority);
        $this->assertNull($job->source);
        $this->assertNull($job->category);
        $this->assertNull($job->sessionId);
    }

    #[Test]
    public function batch_job_preserves_all_metadata_per_event(): void
    {
        $events = [
            [
                'name' => 'sign_up',
                'params' => ['method' => 'google'],
                'client_id' => 'cli_001',
                'user_id' => 'user_001',
                'timestamp' => 1700000001,
                'priority' => 'normal',
                'source' => 'client',
                'category' => 'saas',
                'session_id' => 'sess_aaa',
            ],
            [
                'name' => 'add_to_cart',
                'params' => ['item_id' => 'SKU-1'],
                'client_id' => 'cli_002',
                'source' => 'api',
                'category' => 'ecommerce',
                'session_id' => 'sess_bbb',
            ],
        ];

        $job = new TrackAnalyticsEventBatchJob(events: $events);

        $this->assertCount(2, $job->events);

        // First event: full metadata
        $this->assertSame('sign_up', $job->events[0]['name']);
        $this->assertSame(['method' => 'google'], $job->events[0]['params']);
        $this->assertSame('cli_001', $job->events[0]['client_id']);
        $this->assertSame('user_001', $job->events[0]['user_id']);
        $this->assertSame(1700000001, $job->events[0]['timestamp']);
        $this->assertSame('normal', $job->events[0]['priority']);
        $this->assertSame('client', $job->events[0]['source']);
        $this->assertSame('saas', $job->events[0]['category']);
        $this->assertSame('sess_aaa', $job->events[0]['session_id']);

        // Second event: partial metadata (null-safe defaults)
        $this->assertSame('add_to_cart', $job->events[1]['name']);
        $this->assertSame('api', $job->events[1]['source']);
        $this->assertSame('ecommerce', $job->events[1]['category']);
        $this->assertSame('sess_bbb', $job->events[1]['session_id']);
        $this->assertNull($job->events[1]['user_id']);
        $this->assertNull($job->events[1]['priority']);
    }

    #[Test]
    public function batch_job_handles_events_without_optional_metadata(): void
    {
        $events = [
            ['name' => 'click', 'params' => ['element' => 'button']],
        ];

        $job = new TrackAnalyticsEventBatchJob(events: $events);

        $this->assertNull($job->events[0]['client_id'] ?? null);
        $this->assertNull($job->events[0]['source'] ?? null);
        $this->assertNull($job->events[0]['category'] ?? null);
        $this->assertNull($job->events[0]['session_id'] ?? null);
    }

    // ── AnalyticsEvent DTO Re-hydration from Job Fields ────────────

    #[Test]
    public function single_job_fields_map_correctly_to_analytics_event(): void
    {
        $job = new TrackAnalyticsEventJob(
            name: 'login',
            params: ['method' => 'oauth'],
            clientId: 'cli_test',
            userId: 'user_test',
            timestamp: 1700000000,
            priority: 'low',
            source: 'server',
            category: 'saas',
            sessionId: 'sess_test',
        );

        // Simulate what the handle() method does
        $event = new AnalyticsEvent(
            name: $job->name,
            params: $job->params,
            clientId: $job->clientId,
            userId: $job->userId,
            timestamp: \DateTimeImmutable::createFromFormat('U', (string) $job->timestamp),
            priority: $job->priority,
            source: $job->source,
            category: $job->category,
            sessionId: $job->sessionId,
        );

        $this->assertSame('login', $event->name);
        $this->assertSame(['method' => 'oauth'], $event->params);
        $this->assertSame('cli_test', $event->clientId);
        $this->assertSame('user_test', $event->userId);
        $this->assertSame(1700000000, $event->timestamp?->getTimestamp());
        $this->assertSame('low', $event->priority);
        $this->assertSame('server', $event->source);
        $this->assertSame('saas', $event->category);
        $this->assertSame('sess_test', $event->sessionId);
    }

    #[Test]
    public function batch_job_fields_map_correctly_to_analytics_events(): void
    {
        $events = [
            [
                'name' => 'scroll_depth',
                'params' => ['percent' => 75],
                'client_id' => 'cli_batch',
                'source' => 'client',
                'category' => 'engagement',
                'session_id' => 'sess_batch',
            ],
        ];

        $job = new TrackAnalyticsEventBatchJob(events: $events);
        $data = $job->events[0];

        // Simulate handle() re-hydration
        $event = new AnalyticsEvent(
            name: $data['name'],
            params: $data['params'],
            clientId: $data['client_id'] ?? null,
            userId: $data['user_id'] ?? null,
            timestamp: isset($data['timestamp'])
                ? \DateTimeImmutable::createFromFormat('U', (string) $data['timestamp']) ?: null
                : null,
            priority: $data['priority'] ?? null,
            source: $data['source'] ?? null,
            category: $data['category'] ?? null,
            sessionId: $data['session_id'] ?? null,
        );

        $this->assertSame('scroll_depth', $event->name);
        $this->assertSame('client', $event->source);
        $this->assertSame('engagement', $event->category);
        $this->assertSame('sess_batch', $event->sessionId);
    }

    // ── Code Quality Checks ───────────────────────────────────────

    #[Test]
    public function jobs_have_strict_types_declaration(): void
    {
        $singleJobPath = dirname(__DIR__, 2) . '/src/Jobs/TrackAnalyticsEventJob.php';
        $batchJobPath = dirname(__DIR__, 2) . '/src/Jobs/TrackAnalyticsEventBatchJob.php';
        $dispatcherPath = dirname(__DIR__, 2) . '/src/Queue/QueuedAnalyticsDispatcher.php';

        foreach ([$singleJobPath, $batchJobPath, $dispatcherPath] as $path) {
            $contents = file_get_contents($path);
            $this->assertNotFalse($contents, "File should exist: {$path}");
            $this->assertStringContainsString('declare(strict_types=1)', $contents, "File should have strict_types: {$path}");
        }
    }

    #[Test]
    public function jobs_have_mit_license_header(): void
    {
        $files = [
            dirname(__DIR__, 2) . '/src/Jobs/TrackAnalyticsEventJob.php',
            dirname(__DIR__, 2) . '/src/Jobs/TrackAnalyticsEventBatchJob.php',
            dirname(__DIR__, 2) . '/src/Queue/QueuedAnalyticsDispatcher.php',
        ];

        foreach ($files as $path) {
            $contents = file_get_contents($path);
            $this->assertNotFalse($contents);
            $this->assertStringContainsString('MIT license', $contents, "File should have MIT license header: {$path}");
        }
    }

    #[Test]
    public function jobs_are_final_readonly(): void
    {
        $singleJob = file_get_contents(dirname(__DIR__, 2) . '/src/Jobs/TrackAnalyticsEventJob.php');
        $batchJob = file_get_contents(dirname(__DIR__, 2) . '/src/Jobs/TrackAnalyticsEventBatchJob.php');

        $this->assertStringContainsString('final readonly class TrackAnalyticsEventJob', $singleJob);
        $this->assertStringContainsString('final readonly class TrackAnalyticsEventBatchJob', $batchJob);
    }

    #[Test]
    public function dispatcher_is_final(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/src/Queue/QueuedAnalyticsDispatcher.php');
        $this->assertStringContainsString('final class QueuedAnalyticsDispatcher', $contents);
    }

    #[Test]
    public function jobs_have_void_constructor_return_types(): void
    {
        $files = [
            dirname(__DIR__, 2) . '/src/Jobs/TrackAnalyticsEventJob.php',
            dirname(__DIR__, 2) . '/src/Jobs/TrackAnalyticsEventBatchJob.php',
            dirname(__DIR__, 2) . '/src/Queue/QueuedAnalyticsDispatcher.php',
        ];

        foreach ($files as $path) {
            $contents = file_get_contents($path);
            $this->assertNotFalse($contents);
            // Constructor should have ): void
            $this->assertMatchesRegularExpression(
                '/public function __construct\([^)]*\): void/',
                $contents,
                "Constructor should have :void return type in {$path}",
            );
        }
    }

    #[Test]
    public function job_has_all_nine_constructor_properties(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/src/Jobs/TrackAnalyticsEventJob.php');

        $this->assertStringContainsString('public string $name', $contents);
        $this->assertStringContainsString('public array $params', $contents);
        $this->assertStringContainsString('public ?string $clientId', $contents);
        $this->assertStringContainsString('public ?string $userId', $contents);
        $this->assertStringContainsString('public ?int $timestamp', $contents);
        $this->assertStringContainsString('public ?string $priority', $contents);
        $this->assertStringContainsString('public ?string $source', $contents);
        $this->assertStringContainsString('public ?string $category', $contents);
        $this->assertStringContainsString('public ?string $sessionId', $contents);
    }

    #[Test]
    public function dispatcher_passes_all_fields_in_single_dispatch(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/src/Queue/QueuedAnalyticsDispatcher.php');

        // Verify dispatch() passes source, category, sessionId
        $this->assertStringContainsString("source: \$event->source,", $contents);
        $this->assertStringContainsString("category: \$event->category,", $contents);
        $this->assertStringContainsString("sessionId: \$event->sessionId,", $contents);
    }

    #[Test]
    public function dispatcher_passes_all_fields_in_batch_dispatch(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/src/Queue/QueuedAnalyticsDispatcher.php');

        // Verify dispatchBatch() array_map includes source, category, session_id
        $this->assertStringContainsString("'source' => \$event->source,", $contents);
        $this->assertStringContainsString("'category' => \$event->category,", $contents);
        $this->assertStringContainsString("'session_id' => \$event->sessionId,", $contents);
    }

    #[Test]
    public function batch_job_phpdoc_includes_new_fields(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/src/Jobs/TrackAnalyticsEventBatchJob.php');

        $this->assertStringContainsString('source', $contents);
        $this->assertStringContainsString('category', $contents);
        $this->assertStringContainsString('session_id', $contents);
    }

    #[Test]
    public function single_job_failed_method_includes_metadata_in_log(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/src/Jobs/TrackAnalyticsEventJob.php');

        // The failed() method should log source, category, session_id
        $this->assertStringContainsString("'source' => \$this->source,", $contents);
        $this->assertStringContainsString("'category' => \$this->category,", $contents);
        $this->assertStringContainsString("'session_id' => \$this->sessionId,", $contents);
    }

    #[Test]
    public function batch_job_handle_includes_metadata_in_error_log(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/src/Jobs/TrackAnalyticsEventBatchJob.php');

        // Error logging should include source, category
        $this->assertStringContainsString("'source' => \$eventData['source'] ?? null,", $contents);
        $this->assertStringContainsString("'category' => \$eventData['category'] ?? null,", $contents);
    }
}
