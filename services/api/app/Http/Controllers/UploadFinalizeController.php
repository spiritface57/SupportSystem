<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Support\UploadEventEmitter;
use Symfony\Component\HttpFoundation\Response;

class UploadFinalizeController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'upload_id'   => 'required|uuid',
            'filename'    => 'required|string|max:255',
            'total_bytes' => 'required|integer|min:1',
        ]);

        $uploadId   = $data['upload_id'];
        $filename   = $data['filename'];
        $totalBytes = (int) $data['total_bytes'];
        $startedAt  = microtime(true);

        // ✅ v0.4: global finalize lock (filesystem critical section)
        $lockHandle = null;
        try {
            $lockFile = storage_path('app/tmp/finalize.lock');
            File::ensureDirectoryExists(dirname($lockFile));

            $lockHandle = fopen($lockFile, 'c');
            if ($lockHandle === false) {
                throw new \RuntimeException('finalize_lock_failed');
            }

            // BLOCKING lock: correctness > throughput for v0.4
            if (!flock($lockHandle, LOCK_EX)) {
                throw new \RuntimeException('finalize_lock_failed');
            }

            /* -------------------------------
             * Locate chunks
             * ------------------------------- */
            $chunksDir = storage_path("app/uploads/{$uploadId}");
            if (!is_dir($chunksDir)) {
                throw new \RuntimeException('orphan_upload');
            }

            /* -------------------------------
             * Assemble into TEMP
             * ------------------------------- */
            $tempDir = storage_path("app/tmp/{$uploadId}");
            File::ensureDirectoryExists($tempDir);

            $tempAssembledPath = "{$tempDir}/assembled.bin";
            $out = fopen($tempAssembledPath, 'wb');
            if ($out === false) {
                throw new \RuntimeException('temp_open_failed');
            }

            $written = 0;

            $chunks = collect(File::files($chunksDir))
                ->sortBy(fn($f) => (int) pathinfo($f->getFilename(), PATHINFO_FILENAME));

            foreach ($chunks as $chunk) {
                $path = $chunk->getPathname();

                $in = @fopen($path, 'rb');
                if ($in === false) {
                    throw new \RuntimeException('chunk_open_failed');
                }

                while (!feof($in)) {
                    $buf = fread($in, 8192);
                    if ($buf === false) {
                        throw new \RuntimeException('chunk_read_failed');
                    }

                    $w = fwrite($out, $buf);
                    if ($w === false) {
                        throw new \RuntimeException('temp_write_failed');
                    }

                    $written += strlen($buf);
                }

                fclose($in);
            }

            fclose($out);

            if ($written !== $totalBytes) {
                throw new \RuntimeException('integrity_mismatch');
            }

            /* -------------------------------
             * Scan gate
             * ------------------------------- */
            UploadEventEmitter::emit(
                'upload.scan.started',
                $uploadId,
                'api',
                ['engine' => 'clamav']
            );

            $hash   = hash_file('sha256', $tempAssembledPath);
            $stream = fopen($tempAssembledPath, 'rb');
            if ($stream === false) {
                UploadEventEmitter::emit('upload.scan.failed', $uploadId, 'api', ['reason' => 'internal_error']);
                throw new \RuntimeException('temp_open_failed');
            }

            try {
                $scannerResponse = Http::timeout(config('scanner.timeout'))
                    ->withHeaders(['Content-Type' => 'application/octet-stream'])
                    ->send('POST', config('scanner.base_url') . '/scan', [
                        'body' => $stream,
                    ]);
            } catch (\Throwable $e) {
                fclose($stream);
                UploadEventEmitter::emit('upload.scan.failed', $uploadId, 'api', ['reason' => 'scanner_unavailable']);
                throw $e;
            }

            fclose($stream);

            if ($scannerResponse->failed()) {
                UploadEventEmitter::emit('upload.scan.failed', $uploadId, 'api', ['reason' => 'scanner_unavailable']);
                throw new \RuntimeException('scanner_unavailable');
            }

            $body = $scannerResponse->json();

            if (!is_array($body) || !array_key_exists('status', $body)) {
                UploadEventEmitter::emit('upload.scan.failed', $uploadId, 'api', ['reason' => 'scanner_invalid_response']);
                throw new \RuntimeException('scanner_invalid_response');
            }

            if ($body['status'] === 'infected') {
                Log::channel('infected')->warning('infected_upload', [
                    'upload_id' => $uploadId,
                    'filename'  => $filename,
                    'bytes'     => $written,
                    'sha256'    => $hash,
                    'signature' => $body['signature'] ?? null,
                    'ip'        => $request->ip(),
                ]);

                UploadEventEmitter::emit('upload.scan.failed', $uploadId, 'api', ['reason' => 'infected_file']);
                throw new \RuntimeException('infected_file');
            }

            if ($body['status'] !== 'clean') {
                UploadEventEmitter::emit('upload.scan.failed', $uploadId, 'api', ['reason' => 'scanner_unknown_state']);
                throw new \RuntimeException('scanner_unknown_state');
            }

            UploadEventEmitter::emit(
                'upload.scan.completed',
                $uploadId,
                'api',
                ['result' => 'clean']
            );

            /* -------------------------------
             * Commit FINAL
             * ------------------------------- */
            $finalDir = storage_path("app/final/uploads/{$uploadId}");
            File::ensureDirectoryExists($finalDir);

            $finalPath = "{$finalDir}/{$filename}";

            // Use move if you want atomic-ish, copy if you want keep temp
            // For v0.4 correctness, copy is OK but may be slower.
            if (!File::copy($tempAssembledPath, $finalPath)) {
                throw new \RuntimeException('final_write_failed');
            }

            // Optional cleanup (enable when ready)
            // File::deleteDirectory($chunksDir);
            // File::deleteDirectory($tempDir);

            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            UploadEventEmitter::emit(
                'upload.finalized',
                $uploadId,
                'api',
                [
                    'duration_ms' => $durationMs,
                    'bytes'       => $written,
                ]
            );

            return response()->json([
                'finalized' => true,
                'bytes'     => $written,
                'path'      => $finalPath,
            ]);
        } catch (\Throwable $e) {
            // DEBUG log (keep until stable)
            Log::error('FINALIZE_EXCEPTION', [
                'upload_id' => $uploadId ?? null,
                'class'     => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            $reason = $this->mapFailureReason($e);

            UploadEventEmitter::emit(
                'upload.failed',
                $uploadId,
                'api',
                [
                    'stage'  => 'finalize',
                    'reason' => $reason,
                ]
            );

            return response()->json([
                'error'  => 'upload_failed',
                'reason' => $reason,
            ], Response::HTTP_BAD_REQUEST);
        } finally {
            // ✅ always release lock
            if (is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }
    }

    private function mapFailureReason(\Throwable $e): string
    {
        $msg = strtolower((string) $e->getMessage());

        // Network-ish / DNS / timeout errors thrown by HTTP client
        if (
            str_contains($msg, 'curl error') ||
            str_contains($msg, 'could not resolve host') ||
            str_contains($msg, 'connection refused') ||
            str_contains($msg, 'timed out') ||
            str_contains($msg, 'timeout')
        ) {
            return 'scanner_unavailable';
        }

        // Filesystem race / IO hints
        if (
            str_contains($msg, 'fopen(') ||
            str_contains($msg, 'copy(') ||
            str_contains($msg, 'mkdir(') ||
            str_contains($msg, 'no such file') ||
            str_contains($msg, 'permission denied')
        ) {
            return 'finalize_fs_race';
        }

        return match ($e->getMessage()) {
            'scanner_unavailable'      => 'scanner_unavailable',
            'scanner_invalid_response' => 'scanner_unavailable',
            'scanner_unknown_state'    => 'scanner_unavailable',
            'infected_file'            => 'infected_file',
            'integrity_mismatch'       => 'integrity_mismatch',
            'orphan_upload'            => 'orphan_upload',
            'finalize_lock_failed'     => 'finalize_fs_race',
            'final_write_failed'       => 'finalize_fs_race',
            default                    => 'internal_error',
        };
    }
}
