<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Analytics command scheduler for automated recurring analytics tasks.
 *
 * Provides config-driven scheduling of analytics admin commands without
 * requiring manual crontab entries. Supports hourly, daily, weekly, and
 * monthly schedules with cooldown tracking and execution logging.
 *
 * Built-in scheduled tasks:
 * - Health check (hourly)
 * - Readiness score (daily)
 * - Cost report (daily)
 * - Archive cleanup (weekly)
 * - Schema validation (daily)
 * - Snapshot generation (daily)
 *
 * Custom tasks can be registered via config or at runtime.
 *
 * Configuration is read from `zeroboiler.analytics.scheduler`.
 *
 * @since 36.0.0
 */
final class AnalyticsCommandScheduler
{
    /** @var array<string, string> Schedule frequency constants */
    private const FREQUENCIES = [
        'hourly' => 'hourly',
        'daily' => 'daily',
        'weekly' => 'weekly',
        'monthly' => 'monthly',
    ];

    /** @var array<string, int> Cooldown periods in seconds per frequency */
    private const COOLDOWNS = [
        'hourly' => 3600,
        'daily' => 86400,
        'weekly' => 604800,
        'monthly' => 2592000,
    ];

    /**
     * Built-in scheduled tasks.
     *
     * @var array<string, array{command: string, frequency: string, description: string, enabled: bool, params: array<string, mixed>}>
     */
    private const BUILTIN_TASKS = [
        'health_check' => [
            'command' => 'zb:analytics:health',
            'frequency' => 'hourly',
            'description' => 'Run analytics health check across all providers',
            'enabled' => true,
            'params' => [],
        ],
        'readiness_score' => [
            'command' => 'zb:analytics:readiness',
            'frequency' => 'daily',
            'description' => 'Compute SaaS analytics readiness score',
            'enabled' => true,
            'params' => [],
        ],
        'cost_report' => [
            'command' => 'zb:analytics:cost',
            'frequency' => 'daily',
            'description' => 'Generate daily event cost report by provider',
            'enabled' => true,
            'params' => [],
        ],
        'archive_cleanup' => [
            'command' => 'zb:analytics:replay',
            'frequency' => 'weekly',
            'description' => 'Clean up expired archived events',
            'enabled' => false,
            'params' => ['action' => 'cleanup'],
        ],
        'schema_validation' => [
            'command' => 'zb:analytics:diagnostic',
            'frequency' => 'daily',
            'description' => 'Validate all event schemas for drift detection',
            'enabled' => true,
            'params' => [],
        ],
        'daily_snapshot' => [
            'command' => 'zb:analytics:snapshot',
            'frequency' => 'daily',
            'description' => 'Generate daily analytics snapshot',
            'enabled' => true,
            'params' => [],
        ],
        'overview' => [
            'command' => 'zb:analytics:overview',
            'frequency' => 'daily',
            'description' => 'Generate analytics overview report',
            'enabled' => false,
            'params' => [],
        ],
    ];

    private bool $enabled;

    private string $cachePrefix;

    private int $cacheTtl;

    /** @var array<string, array{command: string, frequency: string, description: string, enabled: bool, params: array<string, mixed>}> Registered tasks */
    private array $tasks = [];

    /** @var array<string, array{last_run: string|null, last_status: string|null, run_count: int, error_count: int}> Execution log */
    private array $executionLog = [];

    /**
     * @param  ConfigRepository  $config  Analytics configuration
     * @param  CacheRepository  $cache  Application cache
     */
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
    ){
        $schedulerConfig = $config->get('zeroboiler.analytics.scheduler', []);
        /** @var array{enabled?: bool, cache_prefix?: string, cache_ttl?: int, tasks?: array<string, array{command: string, frequency: string, description: string, enabled?: bool, params?: array<string, mixed>}>, override_defaults?: bool} $schedulerConfig */

        $this->enabled = (bool) ($schedulerConfig['enabled'] ?? false);
        $this->cachePrefix = (string) ($schedulerConfig['cache_prefix'] ?? 'zb_scheduler_');
        $this->cacheTtl = (int) ($schedulerConfig['cache_ttl'] ?? 2592000);

        foreach (self::BUILTIN_TASKS as $name => $task) {
            $this->tasks[$name] = $task;
        }

        $customTasks = $schedulerConfig['tasks'] ?? [];
        foreach ($customTasks as $name => $task) {
            $this->tasks[$name] = [
                'command' => (string) ($task['command'] ?? ''),
                'frequency' => (string) ($task['frequency'] ?? 'daily'),
                'description' => (string) ($task['description'] ?? ''),
                'enabled' => (bool) ($task['enabled'] ?? true),
                'params' => (array) ($task['params'] ?? []),
            ];
        }

        // Override built-in tasks from config
        if (($schedulerConfig['override_defaults'] ?? false) === true) {
            foreach ($schedulerConfig['tasks'] ?? [] as $name => $task) {
                if (isset($this->tasks[$name])) {
                    $this->tasks[$name] = array_merge($this->tasks[$name], [
                        'command' => (string) ($task['command'] ?? $this->tasks[$name]['command']),
                        'frequency' => (string) ($task['frequency'] ?? $this->tasks[$name]['frequency']),
                        'description' => (string) ($task['description'] ?? $this->tasks[$name]['description']),
                        'enabled' => (bool) ($task['enabled'] ?? $this->tasks[$name]['enabled']),
                        'params' => (array) ($task['params'] ?? $this->tasks[$name]['params']),
                    ]);
                }
            }
        }

        $this->loadExecutionLog();
    }

    /**
     * Check if the scheduler is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get all registered tasks.
     *
     * @return array<string, array{command: string, frequency: string, description: string, enabled: bool, params: array<string, mixed>}>
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    /**
     * Get tasks that are due for execution.
     *
     * A task is due if it is enabled and its cooldown period has elapsed
     * since the last run.
     *
     * @return list<string> Task names that are due
     */
    public function getDueTasks(): array
    {
        if (! $this->enabled) {
            return [];
        }

        $due = [];
        $now = time();

        foreach ($this->tasks as $name => $task) {
            if (! $task['enabled']) {
                continue;
            }

            $lastRun = $this->executionLog[$name]['last_run'] ?? null;

            if ($lastRun === null) {
                $due[] = $name;
                continue;
            }

            $lastRunTs = strtotime($lastRun);
            if ($lastRunTs === false) {
                $due[] = $name;
                continue;
            }

            $cooldown = self::COOLDOWNS[$task['frequency']] ?? 86400;

            if (($now - $lastRunTs) >= $cooldown) {
                $due[] = $name;
            }
        }

        return $due;
    }

    /**
     * Execute all due tasks.
     *
     * @return array{executed: list<string>, skipped: list<string>, failed: list<string>, total: int}
     */
    public function executeDueTasks(): array
    {
        $dueTasks = $this->getDueTasks();
        $executed = [];
        $skipped = [];
        $failed = [];

        foreach ($dueTasks as $name) {
            try {
                $result = $this->executeTask($name);

                if ($result) {
                    $executed[] = $name;
                } else {
                    $skipped[] = $name;
                }
            } catch (\Throwable $e) {
                $failed[] = $name;
                $this->recordExecution($name, 'error');
                Log::warning("ZeroBoiler: Scheduled task '{$name}' failed: {$e->getMessage()}");
            }
        }

        return [
            'executed' => $executed,
            'skipped' => $skipped,
            'failed' => $failed,
            'total' => count($dueTasks),
        ];
    }

    /**
     * Execute a specific task by name.
     *
     * Runs the configured Artisan command with the task's parameters.
     *
     * @param  string  $name  Task name
     * @return bool True if executed successfully
     */
    public function executeTask(string $name): bool
    {
        if (! isset($this->tasks[$name])) {
            return false;
        }

        $task = $this->tasks[$name];

        if (! $task['enabled']) {
            return false;
        }

        try {
            $command = $task['command'];
            $params = $task['params'];

            $parts = [$command];
            foreach ($params as $key => $value) {
                if (is_int($key)) {
                    $parts[] = (string) $value;
                } else {
                    $parts[] = "--{$key}=" . escapeshellarg((string) $value);
                }
            }

            $commandStr = implode(' ', $parts);

            // Execute via Artisan
            $exitCode = $this->runArtisan($commandStr);

            if ($exitCode === 0) {
                $this->recordExecution($name, 'success');
            } else {
                $this->recordExecution($name, 'error');
            }

            return $exitCode === 0;
        } catch (\Throwable $e) {
            $this->recordExecution($name, 'error');
            Log::warning("ZeroBoiler: Failed to execute scheduled task '{$name}': {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Register a custom task at runtime.
     *
     * @param  string  $name  Unique task name
     * @param  string  $command  Artisan command to execute
     * @param  string  $frequency  Schedule frequency (hourly|daily|weekly|monthly)
     * @param  string  $description  Human-readable description
     * @param  array<string, mixed>  $params  Command parameters
     */
    public function registerTask(string $name, string $command, string $frequency, string $description = '', array $params = []): void
    {
        $this->tasks[$name] = [
            'command' => $command,
            'frequency' => $frequency,
            'description' => $description,
            'enabled' => true,
            'params' => $params,
        ];
    }

    /**
     * Enable or disable a task.
     */
    public function toggleTask(string $name, bool $enabled): void
    {
        if (isset($this->tasks[$name])) {
            $this->tasks[$name]['enabled'] = $enabled;
        }
    }

    /**
     * Remove a task from the schedule.
     */
    public function removeTask(string $name): void
    {
        unset($this->tasks[$name]);
        unset($this->executionLog[$name]);
    }

    /**
     * Get the execution log for all tasks.
     *
     * @return array<string, array{last_run: string|null, last_status: string|null, run_count: int, error_count: int}>
     */
    public function getExecutionLog(): array
    {
        return $this->executionLog;
    }

    /**
     * Get a summary of the scheduler state.
     *
     * @return array{enabled: bool, total_tasks: int, enabled_tasks: int, due_tasks: int, last_executed: string|null}
     */
    public function getSummary(): array
    {
        $enabledTasks = array_filter($this->tasks, fn (array $t): bool => $t['enabled']);
        $dueTasks = $this->getDueTasks();

        $lastExecuted = null;
        foreach ($this->executionLog as $log) {
            if ($log['last_run'] !== null) {
                if ($lastExecuted === null || $log['last_run'] > $lastExecuted) {
                    $lastExecuted = $log['last_run'];
                }
            }
        }

        return [
            'enabled' => $this->enabled,
            'total_tasks' => count($this->tasks),
            'enabled_tasks' => count($enabledTasks),
            'due_tasks' => count($dueTasks),
            'last_executed' => $lastExecuted,
        ];
    }

    /**
     * Record a task execution in the log.
     */
    private function recordExecution(string $name, string $status): void
    {
        if (! isset($this->executionLog[$name])) {
            $this->executionLog[$name] = [
                'last_run' => null,
                'last_status' => null,
                'run_count' => 0,
                'error_count' => 0,
            ];
        }

        $this->executionLog[$name]['last_run'] = date('Y-m-d H:i:s');
        $this->executionLog[$name]['last_status'] = $status;
        $this->executionLog[$name]['run_count']++;

        if ($status === 'error') {
            $this->executionLog[$name]['error_count']++;
        }

        $this->persistExecutionLog();
    }

    /**
     * Load execution log from cache.
     */
    private function loadExecutionLog(): void
    {
        $logKey = $this->cachePrefix . 'execution_log';

        /** @var array<string, array{last_run: string|null, last_status: string|null, run_count: int, error_count: int}>|null $cached */
        $cached = $this->cache->get($logKey);

        if (is_array($cached)) {
            $this->executionLog = $cached;
        }
    }

    /**
     * Persist execution log to cache.
     */
    private function persistExecutionLog(): void
    {
        $logKey = $this->cachePrefix . 'execution_log';
        $this->cache->put($logKey, $this->executionLog, $this->cacheTtl);
    }

    /**
     * Run an Artisan command and return the exit code.
     */
    private function runArtisan(string $command): int
    {
        try {
            $exitCode = \Illuminate\Support\Facades\Artisan::call($command);

            return is_int($exitCode) ? $exitCode : 1;
        } catch (\Throwable $e) {
            return 1;
        }
    }
}
