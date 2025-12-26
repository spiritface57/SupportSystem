<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Support\UploadEventEmitter;

class UploadInitController extends Controller
{
    public function __invoke(Request $request)
    {
        try {
            $data = $request->validate([
                'filename'     => 'required|string|max:255',
                'total_bytes'  => 'required|integer|min:1',
                'chunk_bytes'  => 'required|integer|min:1024|max:10485760',
            ]);

            $uploadId    = (string) Str::uuid();
            $filename    = $this->sanitizeFilename($data['filename']);
            $totalBytes  = (int) $data['total_bytes'];
            $chunkBytes  = (int) $data['chunk_bytes'];

            // store minimal upload metadata (file-based, no DB dependency)
            $metaDir = storage_path("app/uploads-meta/{$uploadId}");
            File::ensureDirectoryExists($metaDir);

            File::put("{$metaDir}/meta.json", json_encode([
                'upload_id'    => $uploadId,
                'filename'     => $filename,
                'total_bytes'  => $totalBytes,
                'chunk_bytes'  => $chunkBytes,
                'created_at'   => now()->toISOString(),
            ], JSON_PRETTY_PRINT));

            $this->safeEmit(
                'upload.initiated',
                $uploadId,
                'api',
                [
                    'filename'    => $filename,
                    'total_bytes' => $totalBytes,
                    'chunk_bytes' => $chunkBytes,
                ]
            );

            return response()->json([
                'upload_id'   => $uploadId,
                'filename'    => $filename,
                'total_bytes' => $totalBytes,
                'chunk_bytes' => $chunkBytes,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('UPLOAD_INIT_EXCEPTION', [
                'class'   => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Internal Server Error',
            ], 500);
        }
    }

    private function safeEmit(string $event, string $uploadId, string $source, array $payload = []): void
    {
        try {
            UploadEventEmitter::emit($event, $uploadId, $source, $payload);
        } catch (\Throwable $e) {
            Log::warning('event_emit_failed', [
                'event'     => $event,
                'upload_id' => $uploadId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function sanitizeFilename(string $filename): string
    {
        $clean = basename($filename);

        if ($clean === '' || $clean === '.' || $clean === '..') {
            throw new \RuntimeException('invalid_filename');
        }

        if (str_contains($clean, "\0") || str_contains($clean, '/') || str_contains($clean, '\\')) {
            throw new \RuntimeException('invalid_filename');
        }

        return $clean;
    }
}
