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
            // Per-upload finalize lock
            $lockFile = storage_path("app/tmp/locks/finalize-{$uploadId}.lock");
            File::ensureDirectoryExists(dirname($lockFile));

            $lockHandle = fopen($lockFile, 'c');
            if ($lockHandle === false) {
                throw new \RuntimeException('finalize_lock_failed');
            }

            if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('finalize_in_progress');
            }

            // Locate chunks
            $chunksDir = storage_path("app/uploads/{$uploadId}");
            if (!is_dir($chunksDir)) {
                throw new \RuntimeException('orphan_upload');
            }

            // Pre-check chunk completeness (fail fast)
            $metaPath = storage_path("app/uploads-meta/{$uploadId}/meta.json");
            if (!File::exists($metaPath)) {
                throw new \RuntimeException('finalize_missing_chunks');
            }

            $meta = json_decode(File::get($metaPath), true);
            $chunkBytes    = (int) ($meta['chunk_bytes'] ?? 0);
            $declaredTotal = (int) ($meta['total_bytes'] ?? 0);

            if ($chunkBytes < 1024 || $declaredTotal < 1) {
                throw new \RuntimeException('finalize_missing_chunks');
            }

            $expectedChunks = (int) ceil($declaredTotal / $chunkBytes);
            for ($i = 0; $i < $expectedChunks; $i++) {
                if (!File::exists("{$chunksDir}/{$i}.part")) {
                    throw new \RuntimeException('finalize_missing_chunks');
                }
            }

            // Assemble into TEMP
            $tempDir = storage_path("app/tmp/{$uploadId}");
            File::ensureDirectoryExists($tempDir);

            $tempAssembledPath = "{$tempDir}/assembled.bin";
            $out = fopen($tempAssembledPath, 'wb');
            if ($out === false) {
                throw new \RuntimeException('finalize_internal_error');
            }

            $written = 0;

            $chunks = collect(File::files($chunksDir))
                ->sortBy(fn($f) => (int) pathinfo($f->getFilename(), PATHINFO_FILENAME));

            foreach ($chunks as $chunk) {
                $in = fopen($chunk->getPathname(), 'rb');
                if ($in === false) {
                    throw new \RuntimeException('finalize_internal_error');
                }

                while (!feof($in)) {
                    $buf = fread($in, 8192);
                    if ($buf === false) {
                        fclose($in);
                        throw new \RuntimeException('finalize_internal_error');
                    }

                    $w = fwrite($out, $buf);
                    if ($w === false) {
                        fclose($in);
                        throw new \RuntimeException('finalize_internal_error');
                    }

                    $written += strlen($buf);
                }

                fclose($in);
            }

            fclose($out);

            if ($written !== $totalBytes) {
                throw new \RuntimeException('integrity_mismatch');
            }

            // Scan (degraded allowed; must not block finalize)
            $scanStatus = 'pending_scan';
            $signature  = null;

            $this->safeEmit('upload.scan.started', $uploadId, 'api', ['engine' => 'clamav']);

            try {
                $hash = hash_file('sha256', $tempAssembledPath);

                $stream = fopen($tempAssembledPath, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('finalize_internal_error');
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
                        $signature = $body['signature'] ?? null;

                        Log::channel('infected')->warning('infected_upload', [
                            'upload_id' => $uploadId,
                            'filename'  => $filename,
                            'bytes'     => $written,
                            'sha256'    => $hash,
                            'signature' => $signature,
                            'ip'        => $request->ip(),
                        ]);

                        $this->safeEmit('upload.scan.completed', $uploadId, 'api', [
                            'verdict'   => 'infected',
                            'signature' => $signature,
                        ]);

                        $scanStatus = 'infected';
                    } elseif (($body['status'] ?? null) === 'clean') {
                        $this->safeEmit('upload.scan.completed', $uploadId, 'api', [
                            'verdict' => 'clean',
                        ]);

                        $scanStatus = 'clean';
                    } else {
                        // unknown scanner payload -> treat as unavailable (do not block finalize)
                        throw new \RuntimeException('scan_protocol_error');
                    }
                } else {
                    throw new \RuntimeException('scan_protocol_error');
                }
            } catch (\Throwable $e) {
                Log::warning('scanner_degraded', [
                    'upload_id' => $uploadId,
                    'error'     => $e->getMessage(),
                ]);

                $this->safeEmit('upload.scan.failed', $uploadId, 'api', [
                    'reason' => 'scanner_unavailable',
                ]);

                $scanStatus = 'pending_scan';
            }

            // Commit result: only CLEAN is published; everything else quarantined
            $finalPath = null;

            if ($scanStatus === 'clean') {
                $finalDir = storage_path("app/final/uploads/{$uploadId}");
                File::ensureDirectoryExists($finalDir);

                $finalPath = "{$finalDir}/{$filename}";
                $tmpFinalPath = $finalPath . '.tmp';

                if (!File::copy($tempAssembledPath, $tmpFinalPath)) {
                    throw new \RuntimeException('final_write_failed');
                }

                if (!@rename($tmpFinalPath, $finalPath)) {
                    try {
                        File::delete($tmpFinalPath);
                    } catch (\Throwable $e) {
                    }
                    throw new \RuntimeException('final_write_failed');
                }
            } else {
                $quarantineDir = storage_path("app/quarantine/uploads/{$uploadId}");
                File::ensureDirectoryExists($quarantineDir);

                $quarantinePath = "{$quarantineDir}/{$filename}";
                $tmpQuarantinePath = $quarantinePath . '.tmp';

                if (!File::copy($tempAssembledPath, $tmpQuarantinePath)) {
                    throw new \RuntimeException('final_write_failed');
                }

                if (!@rename($tmpQuarantinePath, $quarantinePath)) {
                    try {
                        File::delete($tmpQuarantinePath);
                    } catch (\Throwable $e) {
                    }
                    throw new \RuntimeException('final_write_failed');
                }
            }

            // Cleanup temp
            try {
                File::deleteDirectory($tempDir);
            } catch (\Throwable $e) {
                Log::warning('finalize_cleanup_failed', [
                    'upload_id' => $uploadId,
                    'error'     => $e->getMessage(),
                ]);
            }

            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            $this->safeEmit('upload.finalized', $uploadId, 'api', [
                'bytes'       => $written,
                'duration_ms' => $durationMs,
                'status'      => $scanStatus,
            ]);

            return response()->json([
                'finalized' => true,
                'bytes'     => $written,
                'status'    => $scanStatus,
                'path'      => $finalPath, // null for pending_scan / infected
            ]);
        } catch (\Throwable $e) {
            Log::error('FINALIZE_EXCEPTION', [
                'upload_id' => $uploadId ?? null,
                'class'     => get_class($e),
                'message'   => $e->getMessage(),
            ]);

            $reason = $this->mapFailureReason($e);

            $this->safeEmit('upload.failed', $uploadId ?? 'unknown', 'api', [
                'stage'  => 'finalize',
                'reason' => $reason,
            ]);

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

        // Scanner interaction failures normalized
        if (
            $msg === 'scan_timeout' ||
            $msg === 'scan_protocol_error' ||
            str_contains($msg, 'timeout') ||
            str_contains($msg, 'curl error') ||
            str_contains($msg, 'could not resolve host') ||
            str_contains($msg, 'connection refused') ||
            str_contains($msg, 'timed out')
        ) {
            return 'scanner_unavailable';
        }

        return match ($msg) {
            'invalid_filename'        => 'invalid_filename',
            'finalize_in_progress'    => 'finalize_in_progress',

            'finalize_lock_failed',
            'final_write_failed'      => 'finalize_fs_race',

            'finalize_missing_chunks',
            'orphan_upload'           => 'finalize_missing_chunks',

            'finalize_size_mismatch',
            'integrity_mismatch'      => 'finalize_size_mismatch',

            default                   => 'finalize_internal_error',
        };
    }
}
