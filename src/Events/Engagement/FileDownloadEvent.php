<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user downloads a file or document.
 *
 * GA4: file_download (custom)
 * Meta: FileDownload (custom)
 *
 * Use this to track document engagement, whitepaper downloads,
 * invoice PDF access, and export actions in SaaS dashboards.
 */
final readonly class FileDownloadEvent extends AnalyticsEvent
{
    /**
     * @param  string  $fileName  Name of the downloaded file
     * @param  string|null  $fileType  File extension or MIME type (e.g. 'pdf', 'csv', 'xlsx')
     * @param  string|null  $fileSize  File size in bytes (optional)
     * @param  array<string, mixed>  $extra  Additional parameters
     */
    public function __construct(
        string $fileName,
        ?string $fileType = null,
        ?int $fileSize = null,
        array $extra = [],
    ): void {
        $baseParams = array_filter([
            'file_name' => $fileName,
            'file_type' => $fileType,
            'file_size' => $fileSize,
        ]);

        parent::__construct('file_download', array_merge($baseParams, $extra));
    }
}
