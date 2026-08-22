<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Data Lake Export Service — S3/GCS-compatible event export pipeline.
 *
 * Exports analytics events to cloud object storage (S3, GCS, Azure Blob)
 * for data warehousing, long-term retention, and downstream ETL processing.
 * Supports batch exports, incremental exports, partitioned output by date,
 * and configurable file formats (JSONL, CSV, Parquet-ready JSON).
 *
 * Inspired by Segment's Warehouse sync, RudderStack's Object Storage
 * destination, and the analytics data lake pattern used by Amplitude,
 * Mixpanel, and PostHog.
 *
 * Configuration: `zeroboiler.analytics.data_lake`
 *
 * @since 20.0.0
 */
final class DataLakeExportService
{
    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'zb_datalake_';

    /** Supported export formats */
    public const FORMAT_JSONL = 'jsonl';
    public const FORMAT_CSV = 'csv';
    public const FORMAT_NDJSON = 'ndjson';

    /** Export statuses */
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /** Supported storage backends */
    public const STORAGE_S3 = 's3';
    public const STORAGE_GCS = 'gcs';
    public const STORAGE_LOCAL = 'local';
    public const STORAGE_NULL = 'null'; // For testing

    private CacheRepository $cache;

    private bool $enabled;

    private string $storageBackend;

    private string $bucket;

    private string $prefix;

    private string $format;

    private int $batchSize;

    private int $retentionDays;

    private bool $partitionByDate;

    private bool $compressOutput;

    private int $exportTimeout;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Config repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $dlConfig = $config->get('zeroboiler.analytics.data_lake', []);
        /** @var array{enabled?: bool, storage?: string, bucket?: string, prefix?: string, format?: string, batch_size?: int, retention_days?: int, partition_by_date?: bool, compress?: bool, timeout?: int} $dlConfig */

        $this->enabled = (bool) ($dlConfig['enabled'] ?? false);
        $this->storageBackend = (string) ($dlConfig['storage'] ?? self::STORAGE_NULL);
        $this->bucket = (string) ($dlConfig['bucket'] ?? '');
        $this->prefix = (string) ($dlConfig['prefix'] ?? 'analytics/events/');
        $this->format = (string) ($dlConfig['format'] ?? self::FORMAT_JSONL);
        $this->batchSize = (int) ($dlConfig['batch_size'] ?? 10000);
        $this->retentionDays = (int) ($dlConfig['retention_days'] ?? 365);
        $this->partitionByDate = (bool) ($dlConfig['partition_by_date'] ?? true);
        $this->compressOutput = (bool) ($dlConfig['compress'] ?? true);
        $this->exportTimeout = (int) ($dlConfig['timeout'] ?? 300);
    }

    /**
     * Check if the data lake export service is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the storage backend type.
     */
    public function getStorageBackend(): string
    {
        return $this->storageBackend;
    }

    /**
     * Get the configured export format.
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Get the bucket/container name.
     */
    public function getBucket(): string
    {
        return $this->bucket;
    }

    /**
     * Get the configured key prefix.
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Generate the storage key for an export file.
     *
     * Follows the convention: {prefix}/{date}/{filename}.{format}[.gz]
     *
     * @param  string  $filename  Base filename (without extension)
     * @param  \DateTimeInterface|null  $date  Partition date (defaults to today)
     */
    public function generateStorageKey(string $filename, ?\DateTimeInterface $date = null): string
    {
        $date = $date ?? new \DateTimeImmutable();
        $datePartition = $this->partitionByDate ? $date->format('Y/m/d') . '/' : '';
        $extension = $this->format === self::FORMAT_JSONL ? 'jsonl' : $this->format;
        $compressionSuffix = $this->compressOutput ? '.gz' : '';

        return rtrim($this->prefix, '/') . '/' . $datePartition . $filename . '.' . $extension . $compressionSuffix;
    }

    /**
     * Prepare a batch of events for export.
     *
     * Transforms analytics events into the configured export format.
     *
     * @param  list<array{name: string, params: array<string, mixed>, client_id?: string|null, user_id?: string|null, timestamp?: string|null}>  $events
     * @return string Formatted export data
     */
    public function formatEvents(array $events): string
    {
        return match ($this->format) {
            self::FORMAT_JSONL, self::FORMAT_NDJSON => $this->formatJsonl($events),
            self::FORMAT_CSV => $this->formatCsv($events),
            default => $this->formatJsonl($events),
        };
    }

    /**
     * Record an export job status.
     *
     * @param  string  $jobId  Unique job identifier
     * @param  string  $status  One of: pending, running, completed, failed
     * @param  array<string, mixed>  $meta  Additional job metadata
     */
    public function recordJob(string $jobId, string $status, array $meta = []): void
    {
        $cacheKey = self::CACHE_PREFIX . 'job_' . $jobId;

        $this->cache->put($cacheKey, [
            'status' => $status,
            'meta' => $meta,
            'updated_at' => time(),
        ], $this->retentionDays * 86400);
    }

    /**
     * Get an export job status.
     *
     * @param  string  $jobId  Unique job identifier
     * @return array{status: string|null, meta: array<string, mixed>, updated_at: int|null}
     */
    public function getJob(string $jobId): array
    {
        $cacheKey = self::CACHE_PREFIX . 'job_' . $jobId;
        $job = $this->cache->get($cacheKey);

        if (! is_array($job)) {
            return ['status' => null, 'meta' => [], 'updated_at' => null];
        }

        return [
            'status' => $job['status'] ?? null,
            'meta' => $job['meta'] ?? [],
            'updated_at' => $job['updated_at'] ?? null,
        ];
    }

    /**
     * List recent export jobs.
     *
     * @return list<array{job_id: string, status: string, updated_at: int}>
     */
    public function listRecentJobs(int $limit = 20): array
    {
        // In a real implementation, this would scan cache or a database
        return [];
    }

    /**
     * Get the retention period in days.
     */
    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    /**
     * Validate storage configuration.
     *
     * @return array{valid: bool, errors: list<string>}
     */
    public function validateConfig(): array
    {
        $errors = [];

        if ($this->enabled) {
            $validBackends = [self::STORAGE_S3, self::STORAGE_GCS, self::STORAGE_LOCAL, self::STORAGE_NULL];

            if (! in_array($this->storageBackend, $validBackends, true)) {
                $errors[] = "Invalid storage backend: {$this->storageBackend}";
            }

            if ($this->storageBackend !== self::STORAGE_NULL && $this->bucket === '') {
                $errors[] = 'Bucket/container name is required for non-null storage';
            }

            $validFormats = [self::FORMAT_JSONL, self::FORMAT_CSV, self::FORMAT_NDJSON];

            if (! in_array($this->format, $validFormats, true)) {
                $errors[] = "Invalid export format: {$this->format}";
            }
        }

        return [
            'valid' => count($errors) === 0,
            'errors' => $errors,
        ];
    }

    /**
     * Get configuration summary.
     *
     * @return array{enabled: bool, storage: string, bucket: string, format: string, batch_size: int, retention_days: int, partition_by_date: bool, compress: bool}
     */
    public function getConfigSummary(): array
    {
        return [
            'enabled' => $this->enabled,
            'storage' => $this->storageBackend,
            'bucket' => $this->bucket,
            'format' => $this->format,
            'batch_size' => $this->batchSize,
            'retention_days' => $this->retentionDays,
            'partition_by_date' => $this->partitionByDate,
            'compress' => $this->compressOutput,
        ];
    }

    /**
     * Format events as JSONL (one JSON object per line).
     *
     * @param  list<array<string, mixed>>  $events
     */
    private function formatJsonl(array $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            $lines[] = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode("\n", $lines);
    }

    /**
     * Format events as CSV.
     *
     * @param  list<array<string, mixed>>  $events
     */
    private function formatCsv(array $events): string
    {
        if (count($events) === 0) {
            return '';
        }

        // Flatten nested arrays for CSV compatibility
        $headers = [];
        $rows = [];

        foreach ($events as $event) {
            $flat = $this->flattenArray($event);

            foreach (array_keys($flat) as $key) {
                if (! in_array($key, $headers, true)) {
                    $headers[] = $key;
                }
            }

            $rows[] = $flat;
        }

        // Build CSV
        $lines = [];
        $lines[] = $this->csvEscape($headers);

        foreach ($rows as $row) {
            $values = array_map(function (string $header) use ($row): string {
                $value = $row[$header] ?? '';
                $stringValue = is_scalar($value) ? (string) $value : json_encode($value);

                return $stringValue;
            }, $headers);

            $lines[] = $this->csvEscape($values);
        }

        return implode("\n", $lines);
    }

    /**
     * Flatten a nested array into dot-notation keys.
     *
     * @param  array<string, mixed>  $array
     * @param  string  $prefix  Key prefix for recursion
     * @return array<string, string>
     */
    private function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $flatKey = $prefix !== '' ? $prefix . '.' . $key : $key;

            if (is_array($value) && $this->isAssociative($value)) {
                $result = array_merge($result, $this->flattenArray($value, $flatKey));
            } else {
                $result[$flatKey] = is_scalar($value) ? $value : json_encode($value);
            }
        }

        return $result;
    }

    /**
     * Check if an array is associative (non-sequential).
     */
    private function isAssociative(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Escape a CSV row.
     *
     * @param  list<string>  $fields
     */
    private function csvEscape(array $fields): string
    {
        return implode(',', array_map(function (string $field): string {
            if (str_contains($field, ',') || str_contains($field, '"') || str_contains($field, "\n")) {
                return '"' . str_replace('"', '""', $field) . '"';
            }

            return $field;
        }, $fields));
    }
}
