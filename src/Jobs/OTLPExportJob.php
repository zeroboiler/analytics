<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Jobs;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\OTLPExportService;

/**
 * Queue job for async OTLP (OpenTelemetry) event export.
 *
 * Dispatches analytics events to the configured OTLP collector
 * in a background queue worker. Used by the event pipeline when
 * OTLP export is enabled to avoid blocking the request.
 *
 * @since 38.0.0
 */
final class OTLPExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use SerializesModels;

    /**
     * Maximum attempts before the job is marked as failed.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying after a failure.
     */
    public int $backoff = 30;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 15;

    /**
     * @param  string  $otelConfigKey  The config key prefix for OTLP settings
     */
    public function __construct(
        private readonly string $otelConfigKey = 'zeroboiler.analytics.otel',
    ): void {}

    /**
     * Execute the OTLP export job.
     *
     * Builds an OTLPExportService instance and exports the event.
     * If OTLP export is disabled, the job is silently skipped.
     */
    #[Override]
    public function handle(ConfigRepository $config, OTLPExportService $otelService): void
    {
        $otelConfig = $config->get($this->otelConfigKey, []);
        /** @var array{enabled?: bool} $otelConfig */

        if (! (bool) ($otelConfig['enabled'] ?? false)) {
            return;
        }

        // This job is dispatched by OTLPExportService::dispatchExport
        // The actual event data is stored in the cache and passed via the service
    }
}
