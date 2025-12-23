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

        try {
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

                    fwrite($out, $buf);
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

            // 🔵 v0.4: scan lifecycle start
            UploadEventEmitter::emit(
                'upload.scan.started',
                $uploadId,
                'api',
                [
                    'engine' => 'clamav',
                ]
            );

            $hash   = hash_file('sha256', $tempAssembledPath);
            $stream = fopen($tempAssembledPath, 'rb');

            $scannerResponse = Http::timeout(config('scanner.timeout'))
                ->withHeaders(['Content-Type' => 'application/octet-stream'])
                ->send('POST', config('scanner.base_url') . '/scan', [
                    'body' => $stream,
                ]);

            fclose($stream);

            if ($scannerResponse->failed()) {
                // 🔵 v0.4: scan failed
                UploadEventEmitter::emit(
                    'upload.scan.failed',
                    $uploadId,
                    'api',
                    [
                        'reason' => 'scanner_unavailable',
                    ]
                );

                throw new \RuntimeException('scanner_unavailable');
            }

            $body = $scannerResponse->json();

            if (!is_array($body) || !isset($body['status'])) {
                // 🔵 v0.4
                UploadEventEmitter::emit(
                    'upload.scan.failed',
                    $uploadId,
                    'api',
                    [
                        'reason' => 'scanner_invalid_response',
                    ]
                );

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

                // 🔵 v0.4
                UploadEventEmitter::emit(
                    'upload.scan.failed',
                    $uploadId,
                    'api',
                    [
                        'reason' => 'infected_file',
                    ]
                );

                throw new \RuntimeException('infected_file');
            }

            if ($body['status'] !== 'clean') {
                // 🔵 v0.4
                UploadEventEmitter::emit(
                    'upload.scan.failed',
                    $uploadId,
                    'api',
                    [
                        'reason' => 'scanner_unknown_state',
                    ]
                );

                throw new \RuntimeException('scanner_unknown_state');
            }

            // 🔵 v0.4: scan completed successfully
            UploadEventEmitter::emit(
                'upload.scan.completed',
                $uploadId,
                'api',
                [
                    'result' => 'clean',
                ]
            );

            /* -------------------------------
             * Commit FINAL
             * ------------------------------- */
            $finalDir = storage_path("app/final/uploads/{$uploadId}");
            File::ensureDirectoryExists($finalDir);

            $finalPath = "{$finalDir}/{$filename}";
            File::copy($tempAssembledPath, $finalPath);

            // cleanup intentionally deferred
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
            $reason = $this->mapFailureReason($e);
            Log::error('FINALIZE_EXCEPTION_DEBUG', [
                'message' => $e->getMessage(),
                'class'   => get_class($e),
            ]);

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
        }
    }

    private function mapFailureReason(\Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        if (
            str_contains($message, 'curl error') ||
            str_contains($message, 'could not resolve host') ||
            str_contains($message, 'connection refused') ||
            str_contains($message, 'timeout')
        ) {
            return 'scanner_unavailable';
        }

        return match ($e->getMessage()) {
            'infected_file'       => 'infected_file',
            'integrity_mismatch'  => 'integrity_mismatch',
            'orphan_upload'       => 'orphan_upload',
            default               => 'internal_error',
        };
    }
}
