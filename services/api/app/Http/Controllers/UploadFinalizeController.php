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
        $filename   = $this->sanitizeFilename($data['filename']);
        $totalBytes = (int) $data['total_bytes'];
        $startedAt  = microtime(true);

        $lockHandle = null;

        try {
            /* Global finalize lock */
            $lockFile = storage_path('app/tmp/finalize.lock');
            File::ensureDirectoryExists(dirname($lockFile));

            $lockHandle = fopen($lockFile, 'c');
            if ($lockHandle === false) {
                throw new \RuntimeException('finalize_lock_failed');
            }

            if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('finalize_in_progress');
            }

            /* Locate chunks */
            $chunksDir = storage_path("app/uploads/{$uploadId}");
            if (!is_dir($chunksDir)) {
                throw new \RuntimeException('orphan_upload');
            }

            /* Assemble into TEMP */
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
                $in = fopen($chunk->getPathname(), 'rb');
                if ($in === false) {
                    throw new \RuntimeException('chunk_open_failed');
                }

                while (!feof($in)) {
                    $buf = fread($in, 8192);
                    if ($buf === false) {
                        fclose($in);
                        throw new \RuntimeException('chunk_read_failed');
                    }

                    $w = fwrite($out, $buf);
                    if ($w === false) {
                        fclose($in);
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

            /* Scan decoupled */
            $scanStatus = 'pending_scan';

            $this->safeEmit(
                'upload.scan.started',
                $uploadId,
                'api',
                ['engine' => 'clamav']
            );

            try {
                $hash = hash_file('sha256', $tempAssembledPath);

                $stream = fopen($tempAssembledPath, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('temp_open_failed');
                }

                try {
                    $scannerResponse = Http::timeout(config('scanner.timeout'))
                        ->withHeaders(['Content-Type' => 'application/octet-stream'])
                        ->send('POST', rtrim(config('scanner.base_url'), '/') . '/scan', [
                            'body' => $stream,
                        ]);
                } finally {
                    fclose($stream);
                }

                if ($scannerResponse->ok()) {
                    $body = $scannerResponse->json();

                    if (is_array($body) && ($body['status'] ?? null) === 'infected') {
                        Log::channel('infected')->warning('infected_upload', [
                            'upload_id' => $uploadId,
                            'filename'  => $filename,
                            'bytes'     => $written,
                            'sha256'    => $hash,
                            'signature' => $body['signature'] ?? null,
                            'ip'        => $request->ip(),
                        ]);

                        $this->safeEmit(
                            'upload.scan.failed',
                            $uploadId,
                            'api',
                            ['reason' => 'infected_file']
                        );

                        throw new \RuntimeException('infected_file');
                    }

                    if (($body['status'] ?? null) === 'clean') {
                        $scanStatus = 'clean';

                        $this->safeEmit(
                            'upload.scan.completed',
                            $uploadId,
                            'api',
                            ['result' => 'clean']
                        );
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('scanner_degraded', [
                    'upload_id' => $uploadId,
                    'error'     => $e->getMessage(),
                ]);

                $this->safeEmit(
                    'upload.scan.failed',
                    $uploadId,
                    'api',
                    ['reason' => 'scanner_unavailable']
                );

                $scanStatus = 'pending_scan';
            }

            /* Commit FINAL independent */
            $finalDir = storage_path("app/final/uploads/{$uploadId}");
            File::ensureDirectoryExists($finalDir);

            $finalPath = "{$finalDir}/{$filename}";

            if (!File::copy($tempAssembledPath, $finalPath)) {
                throw new \RuntimeException('final_write_failed');
            }

            /* Cleanup temp */
            try {
                File::deleteDirectory($tempDir);
            } catch (\Throwable $e) {
                Log::warning('finalize_cleanup_failed', [
                    'upload_id' => $uploadId,
                    'error'     => $e->getMessage(),
                ]);
            }

            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            $this->safeEmit(
                'upload.finalized',
                $uploadId,
                'api',
                [
                    'bytes'       => $written,
                    'duration_ms' => $durationMs,
                    'status'      => $scanStatus,
                ]
            );

            return response()->json([
                'finalized' => true,
                'bytes'     => $written,
                'status'    => $scanStatus,
                'path'      => $finalPath,
            ]);
        } catch (\Throwable $e) {
            Log::error('FINALIZE_EXCEPTION', [
                'upload_id' => $uploadId ?? null,
                'class'     => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            $reason = $this->mapFailureReason($e);

            $this->safeEmit(
                'upload.failed',
                $uploadId ?? 'unknown',
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
            if (is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
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

    private function mapFailureReason(\Throwable $e): string
    {
        $msg = strtolower((string) $e->getMessage());

        if ($e instanceof \InvalidArgumentException) {
            return 'invalid_event';
        }

        if (
            str_contains($msg, 'curl error') ||
            str_contains($msg, 'could not resolve host') ||
            str_contains($msg, 'connection refused') ||
            str_contains($msg, 'timed out') ||
            str_contains($msg, 'timeout')
        ) {
            return 'scanner_unavailable';
        }

        if (
            str_contains($msg, 'permission denied') ||
            str_contains($msg, 'no such file') ||
            str_contains($msg, 'mkdir(') ||
            str_contains($msg, 'copy(') ||
            str_contains($msg, 'fopen(')
        ) {
            return 'finalize_fs_race';
        }

        return match ($e->getMessage()) {
            'infected_file'        => 'infected_file',
            'integrity_mismatch'   => 'integrity_mismatch',
            'orphan_upload'        => 'orphan_upload',
            'finalize_lock_failed' => 'finalize_fs_race',
            'final_write_failed'   => 'finalize_fs_race',
            'finalize_in_progress' => 'finalize_in_progress',
            'invalid_filename'     => 'invalid_filename',
            default                => 'internal_error',
        };
    }
}
