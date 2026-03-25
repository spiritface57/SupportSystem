<?php

namespace App\Console\Commands;

use App\Support\UploadEventEmitter;
use App\Support\UploadStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RescanPendingUploads extends Command
{
    protected $signature = 'upload:rescan-pending {--limit=50} {--dry-run}';
    protected $description = 'Rescan pending_scan uploads from quarantine and publish clean files';

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $finalDisk = UploadStorage::finalDisk();
        $quarantineDisk = UploadStorage::quarantineDisk();

        $prefix = UploadStorage::quarantinePrefix();
        $listPrefix = $prefix === '' ? '' : "{$prefix}/";

        $uploads = $this->collectQuarantineUploads($quarantineDisk, $listPrefix, $limit);
        if ($uploads->isEmpty()) {
            $this->info('No quarantine uploads to process.');
            return self::SUCCESS;
        }

        $processed = 0;

        foreach ($uploads as $uploadId => $item) {
            $quarantineKey = $item['key'];
            $filename = $item['filename'];

            // Per-upload lock to prevent double processing across overlaps
            $lockHandle = null;
            try {
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
            } catch (\Throwable $e) {
                Log::warning('rescan_lock_exception', [
                    'upload_id' => $uploadId,
                    'error'     => $e->getMessage(),
                ]);
                if (is_resource($lockHandle)) {
                    fclose($lockHandle);
                }
                continue;
            }

            try {
                // Expect meta
                $metaPath = storage_path("app/uploads-meta/{$uploadId}/meta.json");
                if (!File::exists($metaPath)) {
                    Log::warning('rescan_skip_missing_meta', ['upload_id' => $uploadId]);
                    continue;
                }

                $meta = json_decode(File::get($metaPath), true) ?: [];
                $filename   = (string) ($meta['filename'] ?? '');
                $totalBytes = (int) ($meta['total_bytes'] ?? 0);

                if ($filename === '' || $totalBytes < 1) {
                    Log::warning('rescan_skip_invalid_meta', ['upload_id' => $uploadId]);
                    continue;
                }

                // Marker file in quarantine directory
                $marker = UploadStorage::quarantineMarkerKey($uploadId);

                // Strong idempotency: if already published (marker OR event exists), skip
                if (Storage::disk($quarantineDisk)->exists($marker)) {
                    continue;
                }

                $alreadyPublished = DB::table('upload_events')
                    ->where('upload_id', $uploadId)
                    ->where('event_name', 'upload.published')
                    ->exists();

                if ($alreadyPublished) {
                    // reconcile filesystem marker to prevent future reprocessing
                    try {
                        File::put($marker, now()->toISOString());
                    } catch (\Throwable $e) {
                    }
                    continue;
                }

                $this->line("Rescanning {$uploadId} / {$filename}");

                if ($dryRun) {
                    $processed++;
                    continue;
                }

                // Emit scan started (best effort)
                try {
                    UploadEventEmitter::emit('upload.scan.started', $uploadId, 'api', [
                        'engine' => 'clamav',
                        'mode'   => 'rescan_pending',
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('rescan_emit_failed', [
                        'upload_id' => $uploadId,
                        'event'     => 'upload.scan.started',
                        'error'     => $e->getMessage(),
                    ]);
                }

                $verdict   = 'pending_scan';
                $signature = null;

                // Call scanner
                try {
                    $stream = Storage::disk($quarantineDisk)->readStream($quarantineKey);
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
                        $verdict   = 'infected';
                        $signature = $body['signature'] ?? null;
                    } else {
                        throw new \RuntimeException('scan_protocol_error');
                    }
                } catch (\Throwable $e) {
                    Log::warning('rescan_scanner_degraded', [
                        'upload_id' => $uploadId,
                        'error'     => $e->getMessage(),
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

                // Emit scan completed (best effort)
                try {
                    UploadEventEmitter::emit('upload.scan.completed', $uploadId, 'api', array_filter([
                        'verdict'   => $verdict,
                        'signature' => $signature,
                        'mode'      => 'rescan_pending',
                    ]));
                } catch (\Throwable $e) {
                    Log::warning('rescan_emit_failed', [
                        'upload_id' => $uploadId,
                        'event'     => 'upload.scan.completed',
                        'error'     => $e->getMessage(),
                    ]);
                }

                if ($verdict === 'infected') {
                    // keep quarantined; do NOT publish
                    $processed++;
                    continue;
                }

                if ($verdict !== 'clean') {
                    // leave quarantined
                    $processed++;
                    continue;
                }

                // Publish clean: tmp + rename (atomic-ish)
                $finalKey = UploadStorage::finalKey($uploadId, $filename);
                if (!$this->publishToFinal($finalDisk, $finalKey, $quarantineDisk, $quarantineKey)) {
                    Log::error('rescan_publish_copy_failed', ['upload_id' => $uploadId]);
                    $processed++;
                    continue;
                }

                // Mark published and cleanup quarantine file
                try {
                    Storage::disk($quarantineDisk)->put($marker, now()->toISOString());
                    // optional: delete quarantined payload after publish
                    Storage::disk($quarantineDisk)->delete($quarantineKey);
                } catch (\Throwable $e) {
                    Log::warning('rescan_cleanup_failed', [
                        'upload_id' => $uploadId,
                        'error'     => $e->getMessage(),
                    ]);
                }

                // Emit published (best effort)
                try {
                    $bytes = null;
                    try {
                        $bytes = Storage::disk($finalDisk)->size($finalKey);
                    } catch (\Throwable $e) {
                    }

                    UploadEventEmitter::emit('upload.published', $uploadId, 'api', [
                        'bytes' => $bytes,
                        'path'  => UploadStorage::formatPath($finalDisk, $finalKey),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('rescan_emit_failed', [
                        'upload_id' => $uploadId,
                        'event'     => 'upload.published',
                        'error'     => $e->getMessage(),
                    ]);
                }

                $processed++;
            } finally {
                // Always release lock
                if (is_resource($lockHandle)) {
                    flock($lockHandle, LOCK_UN);
                    fclose($lockHandle);
                }
            }
        }

        $this->info("Processed: {$processed}");
        return self::SUCCESS;
    }

    private function collectQuarantineUploads(string $disk, string $listPrefix, int $limit)
    {
        try {
            $listing = Storage::disk($disk)->listContents($listPrefix, true);
        } catch (\Throwable $e) {
            return collect();
        }
        $uploads = [];

        foreach ($listing as $item) {
            $path = null;
            $type = null;

            if (is_array($item)) {
                $path = $item['path'] ?? null;
                $type = $item['type'] ?? null;
            } elseif (is_object($item)) {
                $path = method_exists($item, 'path') ? $item->path() : null;
                $type = method_exists($item, 'type') ? $item->type() : null;
            }

            if ($type !== 'file' || !$path) {
                continue;
            }

            if ($listPrefix !== '' && !str_starts_with($path, $listPrefix)) {
                continue;
            }

            $relative = $listPrefix === '' ? $path : substr($path, strlen($listPrefix));
            $relative = ltrim($relative, '/');

            if ($relative === '') {
                continue;
            }

            $parts = explode('/', $relative, 2);
            if (count($parts) < 2) {
                continue;
            }

            $uploadId = $parts[0];
            $filename = $parts[1];

            if ($filename === '.published' || str_ends_with($filename, '.tmp')) {
                continue;
            }

            if (!isset($uploads[$uploadId])) {
                $uploads[$uploadId] = [
                    'key' => $path,
                    'filename' => $filename,
                ];

                if (count($uploads) >= $limit) {
                    break;
                }
            }
        }

        return collect($uploads);
    }

    private function publishToFinal(string $finalDisk, string $finalKey, string $quarantineDisk, string $quarantineKey): bool
    {
        $finalFs = Storage::disk($finalDisk);
        $driver = (string) config("filesystems.disks.{$finalDisk}.driver");

        $dir = trim(dirname($finalKey), '/');
        if ($dir !== '' && $dir !== '.') {
            $finalFs->makeDirectory($dir);
        }

        $stream = Storage::disk($quarantineDisk)->readStream($quarantineKey);
        if ($stream === false) {
            return false;
        }

        try {
            if ($driver === 'local') {
                $tmpKey = $finalKey . '.tmp';
                if (!$finalFs->writeStream($tmpKey, $stream)) {
                    return false;
                }
                if (!$finalFs->move($tmpKey, $finalKey)) {
                    try {
                        $finalFs->delete($tmpKey);
                    } catch (\Throwable $e) {
                    }
                    return false;
                }
                return true;
            }

            return (bool) $finalFs->writeStream($finalKey, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
