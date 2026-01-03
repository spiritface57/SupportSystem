<?php

namespace App\Console\Commands;

use App\Support\UploadEventEmitter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RescanPendingUploads extends Command
{
    protected $signature = 'upload:rescan-pending {--limit=50} {--dry-run}';
    protected $description = 'Rescan pending_scan uploads from quarantine and publish clean files';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $root = storage_path('app/quarantine/uploads');
        if (!is_dir($root)) {
            $this->info('No quarantine directory found.');
            return self::SUCCESS;
        }

        $dirs = collect(File::directories($root))
            ->take($limit)
            ->values();

        if ($dirs->isEmpty()) {
            $this->info('No quarantine uploads to process.');
            return self::SUCCESS;
        }

        $processed = 0;

        foreach ($dirs as $dir) {
            $uploadId = basename($dir);

            $lockHandle = null;

            try {
                // Per-upload lock to prevent double publish (multiple workers / overlaps)
                $lockFile = storage_path("app/tmp/locks/rescan-{$uploadId}.lock");
                File::ensureDirectoryExists(dirname($lockFile));

                $lockHandle = fopen($lockFile, 'c');
                if ($lockHandle === false) {
                    Log::warning('rescan_lock_failed', ['upload_id' => $uploadId]);
                    continue;
                }

                if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
                    // another worker is already processing this upload
                    continue;
                }

                // Expect exactly one quarantined file (named by meta filename)
                $metaPath = storage_path("app/uploads-meta/{$uploadId}/meta.json");
                if (!File::exists($metaPath)) {
                    Log::warning('rescan_skip_missing_meta', ['upload_id' => $uploadId]);
                    continue;
                }

                $meta = json_decode(File::get($metaPath), true);
                $filename = (string) ($meta['filename'] ?? '');
                $totalBytes = (int) ($meta['total_bytes'] ?? 0);

                if ($filename === '' || $totalBytes < 1) {
                    Log::warning('rescan_skip_invalid_meta', ['upload_id' => $uploadId]);
                    continue;
                }

                $quarantinePath = "{$dir}/{$filename}";
                if (!File::exists($quarantinePath)) {
                    Log::warning('rescan_skip_missing_quarantine_file', [
                        'upload_id' => $uploadId,
                        'path' => $quarantinePath,
                    ]);
                    continue;
                }

                // Simple idempotency marker (prevents reprocessing clean publishes)
                $marker = "{$dir}/.published";
                if (File::exists($marker)) {
                    continue;
                }

                $this->line("Rescanning {$uploadId} / {$filename}");

                if ($dryRun) {
                    $processed++;
                    continue;
                }

                try {
                    UploadEventEmitter::emit('upload.scan.started', $uploadId, 'api', [
                        'engine' => 'clamav',
                        'mode'   => 'rescan_pending',
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('rescan_emit_failed', [
                        'upload_id' => $uploadId,
                        'event' => 'upload.scan.started',
                        'error' => $e->getMessage(),
                    ]);
                }

                $verdict = 'pending_scan';
                $signature = null;

                try {
                    $stream = fopen($quarantinePath, 'rb');
                    if ($stream === false) {
                        throw new \RuntimeException('open_failed');
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

                    if (is_array($body) && ($body['status'] ?? null) === 'clean') {
                        $verdict = 'clean';
                    } elseif (is_array($body) && ($body['status'] ?? null) === 'infected') {
                        $verdict = 'infected';
                        $signature = $body['signature'] ?? null;
                    } else {
                        throw new \RuntimeException('scan_protocol_error');
                    }
                } catch (\Throwable $e) {
                    Log::warning('rescan_scanner_degraded', [
                        'upload_id' => $uploadId,
                        'error' => $e->getMessage(),
                    ]);

                    try {
                        UploadEventEmitter::emit('upload.scan.failed', $uploadId, 'api', [
                            'reason' => 'scanner_unavailable',
                            'mode'   => 'rescan_pending',
                        ]);
                    } catch (\Throwable $emitErr) {
                    }

                    // leave as pending_scan
                    $processed++;
                    continue;
                }

                // Emit scan completed
                try {
                    UploadEventEmitter::emit('upload.scan.completed', $uploadId, 'api', array_filter([
                        'verdict' => $verdict,
                        'signature' => $signature,
                        'mode' => 'rescan_pending',
                    ]));
                } catch (\Throwable $e) {
                    Log::warning('rescan_emit_failed', [
                        'upload_id' => $uploadId,
                        'event' => 'upload.scan.completed',
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($verdict === 'infected') {
                    // keep quarantined; do NOT publish
                    $processed++;
                    continue;
                }

                // Publish clean: move into final storage (atomic-ish with tmp + rename)
                $finalDir = storage_path("app/final/uploads/{$uploadId}");
                File::ensureDirectoryExists($finalDir);

                $finalPath = "{$finalDir}/{$filename}";
                $tmpFinalPath = $finalPath . '.tmp';

                if (!File::copy($quarantinePath, $tmpFinalPath)) {
                    Log::error('rescan_publish_copy_failed', ['upload_id' => $uploadId]);
                    $processed++;
                    continue;
                }

                if (!@rename($tmpFinalPath, $finalPath)) {
                    try {
                        File::delete($tmpFinalPath);
                    } catch (\Throwable $e) {
                    }
                    Log::error('rescan_publish_rename_failed', ['upload_id' => $uploadId]);
                    $processed++;
                    continue;
                }

                // Mark published and cleanup quarantine file (keep dir for audit if you want)
                try {
                    File::put($marker, now()->toISOString());
                    File::delete($quarantinePath);
                } catch (\Throwable $e) {
                    Log::warning('rescan_cleanup_failed', [
                        'upload_id' => $uploadId,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Emit published
                try {
                    UploadEventEmitter::emit('upload.published', $uploadId, 'api', [
                        'bytes' => File::size($finalPath),
                        'path'  => $finalPath,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('rescan_emit_failed', [
                        'upload_id' => $uploadId,
                        'event' => 'upload.published',
                        'error' => $e->getMessage(),
                    ]);
                }

                $processed++;
            } catch (\Throwable $e) {
                // last-resort safety; do not crash the whole command
                Log::error('rescan_unhandled_exception', [
                    'upload_id' => $uploadId,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                if (is_resource($lockHandle)) {
                    flock($lockHandle, LOCK_UN);
                    fclose($lockHandle);
                }
            }
        }

        $this->info("Processed: {$processed}");
        return self::SUCCESS;
    }
}
