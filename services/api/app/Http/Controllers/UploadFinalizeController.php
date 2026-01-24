<?php

namespace App\Http\Controllers;

use App\Support\UploadEventEmitter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class UploadFinalizeController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'upload_id'   => 'required|uuid',
            'filename'    => 'sometimes|string|max:255',
            'total_bytes' => 'sometimes|integer|min:1',
        ]);

        $uploadId   = (string) $data['upload_id'];
        $startedAt  = microtime(true);
        $lockHandle = null;

        try {
            /* -------------------- LOCK (per upload) -------------------- */
            $lockFile = storage_path("app/tmp/locks/finalize-{$uploadId}.lock");
            File::ensureDirectoryExists(dirname($lockFile));

            $lockHandle = fopen($lockFile, 'c');
            if ($lockHandle === false) {
                throw new \RuntimeException('finalize_lock_failed');
            }

            // If another finalize is running for this upload_id, return 409 deterministically.
            if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
                return response()->json([
                    'error'  => 'upload_failed',
                    'reason' => 'finalize_in_progress',
                ], 409);
            }

            /**
             * TEST / CHAOS ONLY (Barrier)
             * Deterministically force overlap:
             * - finalize#1 acquires lock, then waits here
             * - finalize#2 hits while lock is held -> must return finalize_in_progress
             *
             * Enable by env:
             *   FINALIZE_BARRIER=1
             * Optional:
             *   FINALIZE_BARRIER_TIMEOUT_MS=15000
             *
             * Barrier file path:
             *   storage/app/tmp/barriers/finalize-<upload_id>.release
             *
             * Test script should create that file from inside the container or via docker exec.
             */
            Log::info('finalize_barrier_probe', [
                'env_FINALIZE_BARRIER' => env('FINALIZE_BARRIER'),
                'cfg_finalize_barrier' => config('upload.finalize_barrier'),
            ]);


            $cfgEnabled = (bool) config('upload.finalize_barrier.enabled', false);
            $headerEnabled = request()->header('X-Test-Barrier') === '1';
            $envOk = app()->environment(['local', 'testing']);

            $barrierEnabled = $cfgEnabled && $envOk && $headerEnabled;

            Log::info('finalize_barrier_probe', [
                'env_FINALIZE_BARRIER' => env('FINALIZE_BARRIER'),
                'cfg_finalize_barrier' => $cfgEnabled,
                'hdr_X_Test_Barrier' => request()->header('X-Test-Barrier'),
                'barrierEnabled' => $barrierEnabled,
            ]);
            if ($barrierEnabled) {
                $timeoutMs = (int) config('upload.finalize_barrier_timeout_ms', 15000);

                $barrierDir = storage_path("app/tmp/barriers");
                File::ensureDirectoryExists($barrierDir);

                $barrierFile = "{$barrierDir}/finalize-{$uploadId}.release";

                Log::info('finalize_barrier_wait', [
                    'upload_id' => $uploadId,
                    'file'      => $barrierFile,
                    'timeoutMs' => $timeoutMs,
                ]);

                $start = microtime(true);
                while (!File::exists($barrierFile)) {
                    usleep(50 * 1000); // 50ms

                    $elapsedMs = (int) ((microtime(true) - $start) * 1000);
                    if ($elapsedMs >= $timeoutMs) {
                        Log::warning('finalize_barrier_timeout', [
                            'upload_id' => $uploadId,
                            'elapsedMs' => $elapsedMs,
                        ]);

                        return response()->json([
                            'error'  => 'upload_failed',
                            'reason' => 'finalize_internal_error',
                            'detail' => 'barrier_timeout',
                        ], 500);
                    }
                }

                // consume barrier (so next runs don't auto-pass)
                try {
                    File::delete($barrierFile);
                } catch (\Throwable $e) {
                }

                Log::info('finalize_barrier_released', [
                    'upload_id' => $uploadId,
                ]);
            }

            /**
             * TEST / CHAOS ONLY (simple delay)
             * Optional: artificial delay AFTER lock (safe for concurrency tests).
             * Enable by env:
             *   FINALIZE_TEST_DELAY_MS=2000
             */
            $delayMs = (int) env('FINALIZE_TEST_DELAY_MS', 0);
            if ($delayMs > 0) {
                Log::info('finalize_delay_enter', ['ms' => $delayMs, 'upload_id' => $uploadId]);
                usleep($delayMs * 1000);
                Log::info('finalize_delay_exit', ['ms' => $delayMs, 'upload_id' => $uploadId]);
            }

            /* -------------------- META & PATHS -------------------- */
            $chunksDir = storage_path("app/uploads/{$uploadId}");
            $metaPath  = storage_path("app/uploads-meta/{$uploadId}/meta.json");

            if (!is_dir($chunksDir) || !File::exists($metaPath)) {
                throw new \RuntimeException('finalize_missing_chunks');
            }

            $meta = json_decode(File::get($metaPath), true) ?: [];
            $filename   = (string) ($meta['filename'] ?? '');
            $totalBytes = (int)    ($meta['total_bytes'] ?? 0);
            $chunkBytes = (int)    ($meta['chunk_bytes'] ?? 0);

            if ($filename === '' || $totalBytes < 1 || $chunkBytes < 1024) {
                throw new \RuntimeException('finalize_missing_chunks');
            }

            /* -------------------- CLIENT SANITY -------------------- */
            if (isset($data['filename'])) {
                $clientName = $this->sanitizeFilename((string) $data['filename']);
                if ($clientName !== $filename) {
                    return response()->json([
                        'error'    => 'upload_failed',
                        'reason'   => 'contract_mismatch',
                        'field'    => 'filename',
                        'expected' => $filename,
                        'got'      => $clientName,
                    ], 409);
                }
            }

            if (isset($data['total_bytes'])) {
                $clientTotal = (int) $data['total_bytes'];
                if ($clientTotal !== $totalBytes) {
                    return response()->json([
                        'error'    => 'upload_failed',
                        'reason'   => 'contract_mismatch',
                        'field'    => 'total_bytes',
                        'expected' => $totalBytes,
                        'got'      => $clientTotal,
                    ], 409);
                }
            }

            /* -------------------- DUPLICATE FINALIZE GUARD -------------------- */
            // Policy: finalize is one-shot. If any committed artifact exists, reject duplicates.
            $finalDir      = storage_path("app/final/uploads/{$uploadId}");
            $quarantineDir = storage_path("app/quarantine/uploads/{$uploadId}");

            $finalPath      = "{$finalDir}/{$filename}";
            $finalTmp       = "{$finalPath}.tmp";
            $quarantinePath = "{$quarantineDir}/{$filename}";
            $quarantineTmp  = "{$quarantinePath}.tmp";

            if (
                File::exists($finalPath) || File::exists($finalTmp) ||
                File::exists($quarantinePath) || File::exists($quarantineTmp)
            ) {
                // Best-effort event
                $this->safeEmit('upload.failed', $uploadId, 'api', [
                    'stage'  => 'finalize',
                    'reason' => 'finalize_locked',
                    'detail' => 'duplicate_finalize',
                ]);

                return response()->json([
                    'error'  => 'upload_failed',
                    'reason' => 'finalize_locked',
                    'detail' => 'duplicate_finalize',
                ], 409);
            }

            /* -------------------- CHUNK COMPLETENESS -------------------- */
            $expectedChunks = (int) ceil($totalBytes / $chunkBytes);

            for ($i = 0; $i < $expectedChunks; $i++) {
                if (!File::exists("{$chunksDir}/{$i}.part")) {
                    throw new \RuntimeException('finalize_missing_chunks');
                }
            }

            /* -------------------- ASSEMBLE -------------------- */
            $tempDir   = storage_path("app/tmp/{$uploadId}");
            $assembled = "{$tempDir}/assembled.bin";
            File::ensureDirectoryExists($tempDir);

            $out = fopen($assembled, 'wb');
            if ($out === false) {
                throw new \RuntimeException('finalize_internal_error');
            }

            $written = 0;

            try {
                for ($i = 0; $i < $expectedChunks; $i++) {
                    $in = fopen("{$chunksDir}/{$i}.part", 'rb');
                    if ($in === false) {
                        throw new \RuntimeException('finalize_internal_error');
                    }

                    try {
                        while (!feof($in)) {
                            $buf = fread($in, 8192);
                            if ($buf === false) {
                                throw new \RuntimeException('finalize_internal_error');
                            }
                            if ($buf === '') {
                                continue;
                            }

                            $w = fwrite($out, $buf);
                            if ($w === false) {
                                throw new \RuntimeException('finalize_internal_error');
                            }

                            $written += strlen($buf);
                        }
                    } finally {
                        fclose($in);
                    }
                }
            } finally {
                fclose($out);
            }

            if ($written !== $totalBytes) {
                throw new \RuntimeException('finalize_size_mismatch');
            }

            /* -------------------- SCAN (degraded allowed) -------------------- */
            $scanStatus = 'pending_scan';

            try {
                $this->safeEmit('upload.scan.started', $uploadId, 'api', ['engine' => 'clamav']);

                $stream = fopen($assembled, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('scan_protocol_error');
                }

                try {
                    $resp = Http::timeout(config('scanner.timeout'))
                        ->withHeaders(['Content-Type' => 'application/octet-stream'])
                        ->send('POST', rtrim(config('scanner.base_url'), '/') . '/scan', [
                            'body' => $stream,
                        ]);
                } finally {
                    fclose($stream);
                }

                if (!$resp->ok()) {
                    throw new \RuntimeException('scan_protocol_error');
                }

                $body = $resp->json();
                if (!is_array($body) || !isset($body['status'])) {
                    throw new \RuntimeException('scan_protocol_error');
                }

                if (($body['status'] ?? null) === 'clean') {
                    $scanStatus = 'clean';
                    $this->safeEmit('upload.scan.completed', $uploadId, 'api', ['verdict' => 'clean']);
                } elseif (($body['status'] ?? null) === 'infected') {
                    $scanStatus = 'infected';
                    $this->safeEmit('upload.scan.completed', $uploadId, 'api', [
                        'verdict'   => 'infected',
                        'signature' => $body['signature'] ?? null,
                    ]);
                } else {
                    throw new \RuntimeException('scan_protocol_error');
                }
            } catch (\Throwable $e) {
                // scanner degraded: finalize must still commit to quarantine deterministically
                $this->safeEmit('upload.scan.failed', $uploadId, 'api', ['reason' => 'scanner_unavailable']);
                $scanStatus = 'pending_scan';
            }

            /* -------------------- COMMIT -------------------- */
            if ($scanStatus === 'clean') {
                File::ensureDirectoryExists($finalDir);

                if (!File::copy($assembled, $finalTmp)) {
                    throw new \RuntimeException('finalize_internal_error');
                }
                if (!@rename($finalTmp, $finalPath)) {
                    try {
                        File::delete($finalTmp);
                    } catch (\Throwable $e) {
                    }
                    throw new \RuntimeException('finalize_internal_error');
                }
            } else {
                File::ensureDirectoryExists($quarantineDir);

                if (!File::copy($assembled, $quarantineTmp)) {
                    throw new \RuntimeException('finalize_internal_error');
                }
                if (!@rename($quarantineTmp, $quarantinePath)) {
                    try {
                        File::delete($quarantineTmp);
                    } catch (\Throwable $e) {
                    }
                    throw new \RuntimeException('finalize_internal_error');
                }
            }

            /* -------------------- CLEANUP -------------------- */
            try {
                File::deleteDirectory($tempDir);
            } catch (\Throwable $e) {
                Log::warning('finalize_cleanup_failed', [
                    'upload_id' => $uploadId,
                    'error'     => $e->getMessage(),
                ]);
            }

            $this->safeEmit('upload.finalized', $uploadId, 'api', [
                'bytes'       => $written,
                'status'      => $scanStatus,
                'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            return response()->json([
                'finalized' => true,
                'status'    => $scanStatus,
                'bytes'     => $written,
                'path'      => ($scanStatus === 'clean') ? $finalPath : null,
            ]);
        } catch (\Throwable $e) {
            $reason = $this->mapFailureReason($e);

            $http = in_array($reason, ['finalize_in_progress', 'finalize_locked'], true)
                ? 409
                : Response::HTTP_BAD_REQUEST;

            // Emit failure best-effort (do not throw)
            $this->safeEmit('upload.failed', $uploadId ?? 'unknown', 'api', [
                'stage'  => 'finalize',
                'reason' => $reason,
            ]);

            return response()->json([
                'error'  => 'upload_failed',
                'reason' => $reason,
            ], $http);
        } finally {
            if (is_resource($lockHandle)) {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
            }
        }
    }

    private function sanitizeFilename(string $name): string
    {
        $clean = basename($name);

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

        return match ($msg) {
            'finalize_in_progress'    => 'finalize_in_progress',
            'finalize_locked'         => 'finalize_locked',
            'finalize_missing_chunks' => 'finalize_missing_chunks',
            'finalize_size_mismatch'  => 'finalize_size_mismatch',
            default                   => 'finalize_internal_error',
        };
    }

    private function safeEmit(string $event, string $uploadId, string $source, array $payload): void
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
