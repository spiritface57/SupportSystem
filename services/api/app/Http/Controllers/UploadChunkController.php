<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Support\UploadEventEmitter;

class UploadChunkController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'upload_id' => 'required|uuid',
            'index'     => 'required|integer|min:0',
            'chunk'     => 'required|file',
        ]);

        $uploadId = $data['upload_id'];
        $index    = (int) $data['index'];
        $chunk    = $data['chunk'];

        // load meta to enforce chunk_bytes and total_bytes contracts
        $metaPath = storage_path("app/uploads-meta/{$uploadId}/meta.json");
        if (!File::exists($metaPath)) {
            return response()->json([
                'error'  => 'upload_not_initialized',
                'reason' => 'missing_meta',
            ], 404);
        }

        $meta = json_decode(File::get($metaPath), true);
        $maxChunkBytes = (int) ($meta['chunk_bytes'] ?? 0);

        if ($maxChunkBytes < 1024) {
            return response()->json([
                'error'  => 'upload_invalid_meta',
                'reason' => 'chunk_bytes_missing',
            ], 400);
        }

        // enforce max chunk size
        $size = (int) $chunk->getSize();
        if ($size <= 0) {
            return response()->json([
                'error'  => 'invalid_chunk',
                'reason' => 'empty',
            ], 422);
        }

        // allow last chunk to be <= chunk_bytes, but never > chunk_bytes
        if ($size > $maxChunkBytes) {
            return response()->json([
                'error'  => 'chunk_too_large',
                'reason' => 'exceeds_declared_chunk_bytes',
                'max'    => $maxChunkBytes,
                'got'    => $size,
            ], 413);
        }

        $dir = storage_path("app/uploads/{$uploadId}");
        File::ensureDirectoryExists($dir);

        $target = "{$dir}/{$index}.part";

        // collision handling: idempotent if same size, reject if different
        if (File::exists($target)) {
            $existingSize = File::size($target);

            if ($existingSize === $size) {
                $this->safeEmit('upload.chunk.received', $uploadId, 'api', [
                    'index' => $index,
                    'bytes' => $size,
                    'duplicate' => true,
                ]);

                return response()->json([
                    'received' => true,
                    'index'    => $index,
                    'status'   => 'duplicate_accepted',
                ]);
            }

            return response()->json([
                'error'  => 'chunk_collision',
                'reason' => 'different_size_for_same_index',
                'index'  => $index,
                'existing_bytes' => $existingSize,
                'got_bytes'      => $size,
            ], 409);
        }

        // move to target (atomic enough for local disk)
        $chunk->move($dir, "{$index}.part");

        $this->safeEmit('upload.chunk.received', $uploadId, 'api', [
            'index' => $index,
            'bytes' => $size,
            'duplicate' => false,
        ]);

        return response()->json([
            'received' => true,
            'index'    => $index,
            'bytes'    => $size,
        ]);
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
}
