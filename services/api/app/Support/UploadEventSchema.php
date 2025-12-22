<?php

namespace App\Support;

use InvalidArgumentException;

class UploadEventSchema
{
    public const VERSION = 1;

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
        'scanner_unavailable',
        'scanner_timeout',
        'infected_file',
        'integrity_mismatch',
        'orphan_upload',
        'internal_error',
    ];

    public static function validate(string $eventName, string $source, array $payload): void
    {
        if (!in_array($eventName, self::EVENTS, true)) {
            throw new InvalidArgumentException("Invalid event_name: {$eventName}");
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
