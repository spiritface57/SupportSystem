<?php

namespace App\Support;

use InvalidArgumentException;

class UploadEventSchema
{
    public const VERSION = 3;

    public const EVENTS = [
        'upload.initiated',
        'upload.chunk.received',
        'upload.scan.started',
        'upload.scan.completed',
        'upload.scan.failed',
        'upload.finalized',
        'upload.failed',
    ];

    public const SOURCES = [
        'api',
        'scanner',
    ];

    public const FAILURE_REASONS = [
        // scanner
        'scanner_unavailable',

        // finalize
        'invalid_filename',
        'finalize_in_progress',
        'finalize_fs_race',
        'finalize_missing_chunks',
        'finalize_size_mismatch',
        'finalize_internal_error',
    ];

    public static function validate(string $eventName, string $source, array $payload = []): void
    {
        if (!in_array($eventName, self::EVENTS, true)) {
            throw new InvalidArgumentException("Invalid event: {$eventName}");
        }

        if (!in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Invalid source: {$source}");
        }

        if (
            isset($payload['reason']) &&
            !in_array($payload['reason'], self::FAILURE_REASONS, true)
        ) {
            throw new InvalidArgumentException("Invalid failure reason: {$payload['reason']}");
        }
    }
}
