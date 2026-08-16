<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Services\EventPayloadEncryptionService;

/**
 * Analytics encryption management command.
 *
 * Provides subcommands for managing event payload encryption:
 * - status: Show encryption configuration and health
 * - encrypt: Encrypt a sample event payload
 * - decrypt: Decrypt an encrypted event payload
 * - fields: List fields that would be encrypted for a given event
 * - rotate: Re-encrypt sample payload (simulates key rotation)
 *
 * @since 54.0.0
 */
final class AnalyticsEncryptionCommand extends Command
{
    /** @var string */
    protected $signature = 'zb:analytics:encryption
                                {action : Subcommand: status|encrypt|decrypt|fields|rotate}
                                {--event= : Event name (for encrypt/decrypt/fields actions)}
                                {--params={} : JSON params to encrypt/decrypt}
                                {--field= : Single field name to decrypt}
    ';

    /** @var string */
    protected $description = 'Manage event payload encryption (v54.0.0)';

    private EventPayloadEncryptionService $encryptionService;

    public function __construct(EventPayloadEncryptionService $encryptionService): void
    {
        parent::__construct();
        $this->encryptionService = $encryptionService;
    }

    /**
     * Execute the console command.
     */
    #[Override]
    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'status' => $this->showStatus(),
            'encrypt' => $this->encryptSample(),
            'decrypt' => $this->decryptSample(),
            'fields' => $this->showFields(),
            'rotate' => $this->rotateSample(),
            default => $this->unknownAction($action),
        };
    }

    /**
     * Show encryption status and configuration.
     */
    private function showStatus(): int
    {
        $report = $this->encryptionService->healthReport();

        $this->info('┌─────────────────────────────────────────────────────┐');
        $this->info('│  ZeroBoiler Analytics — Payload Encryption Status     │');
        $this->info('└─────────────────────────────────────────────────────┘');
        $this->newLine();

        $this->table(
            ['Setting', 'Value'],
            [
                ['Enabled', $report['enabled'] ? '<fg=green>YES</>' : '<fg=red>NO</>'],
                ['Prefix', $report['prefix']],
                ['Cipher', $report['cipher']],
                ['Global Fields', (string) $report['global_fields_count']],
                ['Event Rules', (string) $report['event_rules_count']],
                ['Version', '54.0.0'],
            ],
        );

        if (! empty($report['global_fields'])) {
            $this->newLine();
            $this->info('Global encrypted fields:');
            foreach ($report['global_fields'] as $field) {
                $this->line("  • {$field}");
            }
        }

        if (! empty($report['event_rules'])) {
            $this->newLine();
            $this->info('Per-event rules:');
            foreach ($report['event_rules'] as $event => $fields) {
                $this->line("  {$event}: [" . implode(', ', $fields) . ']');
            }
        }

        if (! $report['enabled']) {
            $this->newLine();
            $this->warn('Encryption is currently DISABLED.');
            $this->line('Set ANALYTICS_ENCRYPTION_ENABLED=true to enable.');
        }

        return self::SUCCESS;
    }

    /**
     * Encrypt a sample event payload.
     */
    private function encryptSample(): int
    {
        $eventName = $this->option('event');

        if ($eventName === null) {
            $this->error('--event is required for encrypt action.');

            return self::FAILURE;
        }

        $params = $this->parseParams();

        $this->info("Encrypting params for event: <comment>{$eventName}</comment>");
        $this->newLine();

        $this->table(
            ['Field', 'Original', 'Encrypted', 'Status'],
            $this->buildEncryptionRows($params, $eventName),
        );

        $encrypted = $this->encryptionService->encryptParams($params, $eventName);

        $this->newLine();
        $this->info('Encrypted payload:');
        $this->line(json_encode($encrypted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * Decrypt an encrypted event payload.
     */
    private function decryptSample(): int
    {
        $params = $this->parseParams();

        $fieldName = $this->option('field');

        if ($fieldName !== null) {
            $decrypted = $this->encryptionService->decryptField($params, $fieldName);

            $this->info("Decrypted field '{$fieldName}':");
            $this->line(is_string($decrypted) || is_array($decrypted)
                ? json_encode($decrypted, JSON_PRETTY_PRINT)
                : var_export($decrypted, true));

            return self::SUCCESS;
        }

        $decrypted = $this->encryptionService->decryptParams($params);

        $this->info('Decrypted payload:');
        $this->line(json_encode($decrypted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * Show fields that would be encrypted for a given event.
     */
    private function showFields(): int
    {
        $eventName = $this->option('event');

        if ($eventName === null) {
            $this->error('--event is required for fields action.');

            return self::FAILURE;
        }

        $fields = $this->encryptionService->getFieldsForEvent($eventName);

        $this->info("Fields encrypted for event: <comment>{$eventName}</comment>");
        $this->newLine();

        if (empty($fields)) {
            $this->warn('No fields configured for encryption on this event.');
            $this->line('Add fields to zeroboiler.analytics.encryption.global_fields');
            $this->line('or zeroboiler.analytics.encryption.event_rules.' . $eventName);

            return self::SUCCESS;
        }

        foreach ($fields as $field) {
            $this->line("  • {$field}");
        }

        return self::SUCCESS;
    }

    /**
     * Simulate key rotation on a sample payload.
     */
    private function rotateSample(): int
    {
        $params = $this->parseParams();

        $this->info('Rotating encryption on payload...');
        $this->newLine();

        $rotated = $this->encryptionService->rotateEncryption($params);

        $encryptedCount = $this->encryptionService->countEncryptedFields($rotated);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Fields processed', (string) count($params)],
                ['Fields encrypted', (string) $encryptedCount],
                ['Status', '<fg=green>OK</>'],
            ],
        );

        $this->newLine();
        $this->info('Rotated payload:');
        $this->line(json_encode($rotated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * Parse JSON params from the --params option.
     *
     * @return array<string, mixed>
     */
    private function parseParams(): array
    {
        $raw = $this->option('params');

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Build table rows showing encryption status per field.
     *
     * @param  array<string, mixed>  $params
     * @return list<array{string, string, string, string}>
     */
    private function buildEncryptionRows(array $params, string $eventName): array
    {
        $rows = [];

        foreach ($params as $key => $value) {
            $original = is_string($value) ? $value : json_encode($value);
            $shouldEncrypt = $this->encryptionService->shouldEncryptFieldForEvent($key, $eventName);

            $rows[] = [
                $key,
                $this->truncate((string) $original, 40),
                $shouldEncrypt ? '<fg=green>ENCRYPTED</>' : '<fg=gray>passthrough</>',
                $shouldEncrypt ? '<fg=yellow>●</>' : '<fg=gray>○</>',
            ];
        }

        return $rows;
    }

    /**
     * Truncate a string for display.
     */
    private function truncate(string $value, int $length): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }

        return substr($value, 0, $length - 3) . '...';
    }

    /**
     * Handle unknown subcommand.
     */
    private function unknownAction(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line('Available actions: status, encrypt, decrypt, fields, rotate');

        return self::FAILURE;
    }
}
