<?php

namespace App\Support;

use App\Models\UploadEvent;
use Illuminate\Support\Str;
use InvalidArgumentException;

class UploadEventEmitter
{
    public static function emit(
        string $eventName,
        string $uploadId,
        string $source,
        array $payload = []
    ): void {
        UploadEventSchema::validate($eventName, $source, $payload);

        if (!Str::isUuid($uploadId)) {
            throw new InvalidArgumentException('Invalid upload_id');
        }

        UploadEvent::create([
            'event_name'    => $eventName,
            'event_version' => UploadEventSchema::VERSION,
            'upload_id'     => $uploadId,
            'source'        => $source,
            'payload'       => $payload,
            'created_at'    => now(),
        ]);
    }
}
