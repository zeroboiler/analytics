<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\Services\EventSchemaExportService;

/**
 * Export analytics event schemas in various industry-standard formats.
 *
 * Generates JSON Schema, TypeScript definitions, or OpenAPI operation
 * definitions from the event catalog for downstream consumers.
 *
 * @since 9.8.0
 */
final class AnalyticsSchemaExportCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:export-schema
        {--format=json : Output format (json, typescript, openapi)}
        {--output=- : Output file path (- for stdout)}
        {--pretty : Pretty-print JSON output}';

    /** @var string */
    protected $description = 'Export analytics event schemas (JSON Schema, TypeScript, OpenAPI)';

    private EventSchemaExportService $exportService;

    public function __construct(EventSchemaExportService $exportService): void
    {
        parent::__construct();
        $this->exportService = $exportService;
    }

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(): int
    {
        $format = $this->option('format');
        $output = $this->option('output');
        $pretty = $this->option('pretty');

        $this->info("Exporting event catalog as {$format}...");

        $content = match ($format) {
            'json' => $this->exportJson($pretty),
            'typescript' => $this->exportTypeScript(),
            'openapi' => $this->exportOpenApi($pretty),
            default => $this->failAndReturn("Unsupported format: {$format}. Use: json, typescript, openapi"),
        };

        if ($output === '-') {
            $this->line($content);

            return self::SUCCESS;
        }

        $path = $output !== '' ? $output : $this->defaultOutputPath($format);
        $written = file_put_contents($path, $content);

        if ($written === false) {
            $this->error("Failed to write to: {$path}");

            return self::FAILURE;
        }

        $size = number_format($written / 1024, 1);
        $this->info("Exported {$format} schema to: {$path} ({$size} KB)");

        return self::SUCCESS;
    }

    /**
     * Export JSON Schema.
     */
    private function exportJson(bool $pretty): string
    {
        $schema = $this->exportService->exportJsonSchema();

        return $pretty
            ? json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : json_encode($schema);
    }

    /**
     * Export TypeScript definitions.
     */
    private function exportTypeScript(): string
    {
        return $this->exportService->exportTypeScript();
    }

    /**
     * Export OpenAPI operations.
     */
    private function exportOpenApi(bool $pretty): string
    {
        $operations = $this->exportService->exportOpenApi();

        return $pretty
            ? json_encode($operations, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : json_encode($operations);
    }

    /**
     * Get the default output file path for a given format.
     */
    private function defaultOutputPath(string $format): string
    {
        $ext = match ($format) {
            'json' => 'analytics-events.schema.json',
            'typescript' => 'analytics-events.d.ts',
            'openapi' => 'analytics-events.openapi.json',
            default => 'analytics-events.' . $format,
        };

        return resource_path("docs/analytics/{$ext}");
    }

    /**
     * Fail with a message and return failure code.
     */
    private function failAndReturn(string $message): string
    {
        $this->error($message);

        return '';
    }
}
